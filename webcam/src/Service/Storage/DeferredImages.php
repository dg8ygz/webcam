<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use GdImage;
use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Private Originale und freigegebene Archivbilder sind strikt getrennt.
 * Ein URL enthaelt nur Kamera, unveraenderlichen Rezeptschluessel und Groesse.
 * Niemals einen vom Browser gelieferten Dateipfad oeffnen oder Rohdaten liefern.
 */
final class DeferredImages
{
  public const RENDERER = 'gd-regions-v1-jpeg92';
  private array $records = [];
  private array $readStores = [];
  private readonly DeferredStorageGuard $storageGuard;

  public function __construct(private readonly App $app)
  {
    $this->storageGuard = new DeferredStorageGuard($app);
  }

  public function enabled(Camera $camera): bool
  {
    $enabled = in_array($camera->slug(), (array) $this->app->config('deferred_image_cameras', []), true);
    if ($enabled && $camera->slug() !== 'bielefeld') throw new RuntimeException('Deferred-image pilot only supports Bielefeld');
    return $enabled;
  }

  private function readStore(Camera $camera): CameraDataStore
  {
    return $this->readStores[$camera->slug()] ??= new CameraDataStore($this->app, $camera, null, true);
  }

  public function handles(Camera $camera, int $slot): bool
  {
    if (!$this->enabled($camera) && !$this->app->config('deferred_image_root')) return false;
    if ($this->forSlot($camera, $slot) !== null) return true;
    if (!$this->enabled($camera)) return false;
    $store = $this->readStore($camera);
    $q = $store->pdo()->prepare('SELECT 1 FROM captures WHERE slot_ts=?');
    $q->execute([$slot]);
    // Historische Slots bleiben klassisch, auch bei einer Uploadwiederholung.
    return $q->fetchColumn() === false;
  }

  public static function isPath(string $path): bool
  {
    return str_starts_with($path, 'derived/');
  }

  /** @return array{0:string,1:string,2:string}|null */
  public static function parse(string $path): ?array
  {
    if (!preg_match('#\Aderived/([a-z0-9-]{1,80})/([a-f0-9]{64})/(original|full|hu|sm|la|lm|[1-9][0-9]{1,3})\.jpg\z#D', $path, $m)) return null;
    return [$m[1], $m[2], $m[3]];
  }

  public static function relative(Camera $camera, string $key, string $variant): string
  {
    return 'derived/' . $camera->slug() . '/' . $key . '/' . $variant . '.jpg';
  }

  public static function url(string $relative): string
  {
    return 'image.php?image=' . rawurlencode($relative);
  }

  /**
   * Fehlende datierte Legacy-URLs nur auf registrierte Pilotaufnahmen abbilden.
   * Vorhandene Dateien liefert Apache weiterhin direkt; keine Rohquelle als Ersatz.
   */
  public function fromLegacyPath(string $path): ?string
  {
    if (preg_match('#\Acache/bielefeld/(\d{4})/(\d{2})/(\d{2})/(\d{8})_(\d{6})(?:_utc([1-9]\d{0,10}))?(?:_(full|hu|sm|la|lm|[1-9][0-9]{1,3}))?\.jpg\z#D', $path, $m)) {
      if ($m[4] !== $m[1] . $m[2] . $m[3]) return null;
      $stamp = $m[4] . $m[5]; $epoch = $m[6] ?? ''; $variant = $m[7] ?? 'original';
    } elseif (preg_match('#\A(?:storage/archive/)?bielefeld/(\d{4})/(\d{2})/(\d{2})/(\d{4})(?:_utc([1-9]\d{0,10}))?(?:_(full|hu|sm|la|lm|[1-9][0-9]{1,3}))?\.jpg\z#D', $path, $m)) {
      $stamp = $m[1] . $m[2] . $m[3] . $m[4] . '00'; $epoch = $m[5] ?? ''; $variant = $m[6] ?? 'original';
    } else return null;
    $time = CaptureFilename::date($stamp, new \DateTimeZone($this->app->config('timezone', 'UTC')), $epoch);
    if (!$time || $time->format('YmdHis') !== $stamp) return null;
    $camera = $this->app->cameras()->find('bielefeld');
    if (!$camera) return null;
    $row = $this->forSlot($camera, $time->getTimestamp());
    if (!$row) return null;
    $relative = self::relative($camera, $row['image_key'], $variant);
    return $this->available($camera, $relative) ? $relative : null;
  }

  /** Ein Schalter stoppt nur neue Pilotaufnahmen. Vorhandene bleiben lesbar. */
  public function forSlot(Camera $camera, int $slot): ?array
  {
    if (!$this->app->config('deferred_image_root')) return null;
    $store = $this->readStore($camera);
    $db = $store->pdo();
    if (!DeferredImageSchema::exists($db)) return null;
    $q = $db->prepare('SELECT * FROM deferred_images WHERE slot_ts=?');
    $q->execute([$slot]);
    $row = $q->fetch(\PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
  }

  public function requireSchema(Camera $camera): void
  {
    $store = $this->readStore($camera);
    if (!DeferredImageSchema::exists($store->pdo())) {
      throw new RuntimeException('Deferred-image migration required before activation');
    }
    $this->root();
    $this->backupRoot();
  }

  /** Kein mkdir unter einem geratenen Pfad: Root muss vom Betreiber vorbereitet sein. */
  public function root(): string
  {
    return $this->privateRoot((string) $this->app->config('deferred_image_root', ''), true);
  }

  public function backupRoot(): string
  {
    $backup = $this->privateRoot((string) $this->app->config('deferred_image_backup_root', ''));
    $source = $this->root();
    if ($backup === $source || str_starts_with($backup, $source . '/') || str_starts_with($source, $backup . '/')) {
      throw new RuntimeException('Backup and original roots must be separate');
    }
    return $backup;
  }

  private function privateRoot(string $configured, bool $original = false): string
  {
    $root = $configured !== '' ? realpath($configured) : false;
    if ($root === false || !is_dir($root) || is_link($configured) || !str_starts_with($configured, '/')) {
      throw new RuntimeException('Private deferred-image root is not provisioned');
    }
    $approvedWebroot = $original && $this->storageGuard->allowsWebroot($root);
    // Nur der vom Betreiber vorbereitete orgin-Pfad ist eine Ausnahme.
    $forbidden = [dirname(__DIR__, 4), (string) $this->app->config('current_project_root', dirname(__DIR__, 3)),
      $this->app->paths()->storagePath(), $this->app->paths()->cachePath(), $this->app->paths()->archivePath()];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) $forbidden[] = $_SERVER['DOCUMENT_ROOT'];
    foreach ($forbidden as $public) {
      if ($public === '') continue; // realpath('') waere das zufaellige CLI-Arbeitsverzeichnis.
      $public = realpath($public);
      if (!$approvedWebroot && $public !== false && ($root === $public || str_starts_with($root, rtrim($public, '/') . '/'))) {
        throw new RuntimeException('Private originals must be outside public roots');
      }
    }
    if ((fileperms($root) & 0077) !== 0) throw new RuntimeException('Private root requires mode 0700');
    // Der Marker wird erst auf dem geprueften Datentraeger provisioniert.
    // Ein leerer Mountpunkt nach NAS-Ausfall ist keine Ersatzablage.
    $sentinel = $root . '/.webcam-private-storage';
    if (!is_file($sentinel) || is_link($sentinel) || !is_readable($sentinel)) {
      throw new RuntimeException('Private storage mount marker missing');
    }
    $this->storageGuard->checkMount($root, $original);
    return $root;
  }

  /** Nur aus intern erzeugten Komponenten; Symlinks in allen Unterpfaden ablehnen. */
  public function directory(string $relative): string
  {
    if (!preg_match('#\A[a-zA-Z0-9/_-]+\z#D', $relative)) throw new RuntimeException('Invalid internal directory');
    $dir = $this->root();
    foreach (explode('/', $relative) as $part) {
      $dir .= '/' . $part;
      if (is_link($dir)) throw new RuntimeException('Symlink in private image storage');
      if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) throw new RuntimeException('Cannot create private image directory');
    }
    return $dir;
  }

  public function dayDirectory(Camera $camera, int $slot): string
  {
    if ($slot <= 0) throw new RuntimeException('Invalid source slot');
    return $camera->slug() . '/' . $this->slotDate($slot)->format('Y/m/d');
  }

  private function slotDate(int $slot): \DateTimeImmutable
  {
    return (new \DateTimeImmutable('@' . $slot))->setTimezone(new \DateTimeZone($this->app->config('timezone', 'UTC')));
  }

  private function slotStamp(int $slot): string
  {
    return CaptureFilename::cache($this->slotDate($slot));
  }

  public function sourcePath(Camera $camera, string $hash, int $slot): string
  {
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $hash)) throw new RuntimeException('Invalid source hash');
    $relative = $this->dayDirectory($camera, $slot);
    $base = $this->root();
    foreach (explode('/', $relative) as $part) {
      $base .= '/' . $part;
      if (is_link($base)) throw new RuntimeException('Symlink in original day path');
    }
    return $base . '/' . $this->slotStamp($slot) . '.jpg';
  }

  public function saveSource(Camera $camera, string $hash, int $slot, ?string $binary, ?string $source): string
  {
    $this->directory($this->dayDirectory($camera, $slot));
    $path = $this->sourcePath($camera, $hash, $slot);
    if (is_link($path)) throw new RuntimeException('Symlink source rejected');
    if (!is_file($path)) {
      $ok = $source !== null ? AtomicFileWriter::copy($source, $path, 0600) : AtomicFileWriter::write($path, (string) $binary, null, 0600);
      if (!$ok) throw new RuntimeException('Private original publication failed');
    }
    if (!hash_equals($hash, (string) hash_file('sha256', $path))) throw new RuntimeException('Private source hash mismatch');
    return $path;
  }

  /**
   * Unter dem Renderlock den UTC-Slot/Rezeptschluessel festhalten, bevor ein
   * Wiederholungsversuch ein gleichnamiges Vorschaubild ueberschreiben koennte.
   * Ein Konflikt braucht eine bewusste Korrektur statt stillen Datenverlust.
   */
  public function claimDatedSlot(Camera $camera, string $hash, int $slot, string $key): void
  {
    $this->directory($this->dayDirectory($camera, $slot));
    $path = $this->sourcePath($camera, $hash, $slot) . '.slot.json';
    $claim = json_encode(['slot' => $slot, 'image_key' => $key], JSON_THROW_ON_ERROR);
    if (is_link($path)) throw new RuntimeException('Symlink slot claim rejected');
    if (file_exists($path)) {
      if (!is_file($path) || file_get_contents($path) !== $claim) {
        throw new RuntimeException('Dated image name already belongs to another slot or recipe');
      }
    } elseif (!AtomicFileWriter::write($path, $claim, null, 0600)) {
      throw new RuntimeException('Cannot claim dated image slot');
    }
  }

  /** Das Profil gehoert zur Aufnahme, nicht zur heute eingestellten Kamera. */
  public function recipe(Camera $camera, int $width, bool $denoise): array
  {
    $variants = ['original' => 0, 'full' => 0, 'hu' => 0] + VariantProfiles::CANONICAL_WIDTHS;
    foreach ($camera->resolutions() ?: $this->app->config('default_resolutions', []) as $size) {
      $size = (int) $size;
      if ($size >= 10 && $size <= 8192 && $size < $width) $variants[(string) $size] = $size;
    }
    return ['renderer' => self::RENDERER, 'quality' => 92,
      'processing' => ImageProcessingConfig::normalize($camera->capture()['image_processing'] ?? []),
      'denoise' => $denoise, 'variants' => $variants];
  }

  public static function key(string $hash, int $slot, string $recipe): string
  {
    return hash('sha256', $hash . ':' . $slot . ':' . $recipe);
  }

  public function decodeRecipe(array $row): array
  {
    $recipe = json_decode($row['recipe_json'], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($recipe) || ($recipe['renderer'] ?? '') !== self::RENDERER || ($recipe['quality'] ?? null) !== 92
      || !is_bool($recipe['denoise'] ?? null) || !is_array($recipe['variants'] ?? null)
      || !is_array($recipe['processing'] ?? null)
      || ImageProcessingConfig::normalize($recipe['processing']) !== $recipe['processing']
      || !hash_equals($row['image_key'], self::key($row['source_hash'], (int) $row['slot_ts'], $row['recipe_json']))) {
      throw new RuntimeException('Unsupported or damaged image recipe');
    }
    foreach ($recipe['variants'] as $name => $width) {
      if (self::parse('derived/x/' . $row['image_key'] . '/' . $name . '.jpg') === null || !is_int($width) || $width < 0 || $width > 8192) {
        throw new RuntimeException('Invalid recipe variant');
      }
    }
    return $recipe;
  }

  /** Nur DB/Quelle pruefen, keine grossen Bilder fuer eine Timeline berechnen. */
  public function available(Camera $camera, string $relative): bool
  {
    try { return $this->record($camera, $relative) !== null; }
    catch (\Throwable) { return false; }
  }

  public function record(Camera $camera, string $relative): ?array
  {
    $parts = self::parse($relative);
    if ($parts === null || $parts[0] !== $camera->slug()) return null;
    [, $key, $variant] = $parts;
    if (!isset($this->records[$camera->slug() . '/' . $key])) {
      $store = $this->readStore($camera);
      if (!DeferredImageSchema::exists($store->pdo())) return null;
      $q = $store->pdo()->prepare('SELECT d.* FROM deferred_images d JOIN captures c ON c.slot_ts=d.slot_ts WHERE d.image_key=? AND c.is_duplicate=0 AND c.relative_path=?');
      $q->execute([$key, self::relative($camera, $key, 'original')]);
      $row = $q->fetch(\PDO::FETCH_ASSOC);
      if (!$row) return null;
      $recipe = $this->decodeRecipe($row);
      $source = $this->sourcePath($camera, $row['source_hash'], (int) $row['slot_ts']);
      if (!is_file($source) || is_link($source) || !is_readable($source)) return null;
      // Request-lokaler, begrenzter Metadaten-Cache, kein dauerhaftes Timeline-Duplikat.
      if (count($this->records) >= 64) $this->records = [];
      $this->records[$camera->slug() . '/' . $key] = [$row, $recipe];
    }
    [$row, $recipe] = $this->records[$camera->slug() . '/' . $key];
    return array_key_exists($variant, $recipe['variants']) ? [$row, $recipe, $variant] : null;
  }

  public static function preview(string $variant, array $recipe): bool
  {
    $width = $recipe['variants'][$variant];
    // Kalender/Mehrkamera bis 400, Vergleiche la/lm bis 1200 bleiben sofort verfuegbar.
    return $width > 0 && $width <= 1200;
  }

  public function outputPath(Camera $camera, string $key, string $variant, array $recipe, int $slot): string
  {
    $stamp = $this->slotStamp($slot);
    $directory = self::preview($variant, $recipe)
      ? $this->dayDirectory($camera, $slot)
      : $camera->slug() . '/cache/' . $key;
    return $this->directory($directory) . '/' . $stamp . '_' . $variant . '.jpg';
  }

  /** Eine Groesse aus demselben bereits bearbeiteten Bild erzeugen. */
  public function writeVariant(GdImage $image, array $recipe, string $variant, string $path): void
  {
    (new ImageVariantGenerator($this->app))->writeResized($image, imagesx($image), imagesy($image),
      $recipe['variants'][$variant], $path, $variant !== 'original' && $recipe['denoise']);
    if (!chmod($path, 0600)) throw new RuntimeException('Cannot protect derived image');
  }

  /** Hoechstens ein Renderer pro Installation; konkurrierende Requests warten begrenzt. */
  public function withRenderLock(callable $work): mixed
  {
    $file = $this->root() . '/render.lock';
    if (is_link($file)) throw new RuntimeException('Invalid renderer lock');
    $lock = fopen($file, 'c');
    if (!$lock) throw new RuntimeException('Renderer lock unavailable');
    $deadline = microtime(true) + 5;
    try {
      while (!flock($lock, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $deadline) throw new RuntimeException('Image renderer busy; retry later');
        usleep(50000);
      }
      return $work();
    } finally { flock($lock, LOCK_UN); fclose($lock); }
  }

  /** Liefert nur das freigegebene Resultat. Fehlende Rezepte/Quellen liefern niemals raw. */
  public function resolve(Camera $camera, string $relative, bool $publicRequest = false): string
  {
    $record = $this->record($camera, $relative);
    if ($record === null) throw new RuntimeException('Derived image not registered');
    [$row, $recipe, $variant] = $record;
    $path = $this->outputPath($camera, $row['image_key'], $variant, $recipe, (int) $row['slot_ts']);
    if ($this->validOutput($path, $row, $recipe, $variant)) return $path;
    return $this->withRenderLock(function () use ($camera, $row, $recipe, $variant, $path, $publicRequest): string {
      clearstatcache(true, $path);
      if ($this->validOutput($path, $row, $recipe, $variant)) return $path;
      if ($publicRequest) $this->consumePublicRenderBudget();
      $source = $this->sourcePath($camera, $row['source_hash'], (int) $row['slot_ts']);
      SnapshotResourceLimits::checkBytes((int) filesize($source), $this->app);
      SnapshotResourceLimits::checkDimensions(getimagesize($source));
      if (!hash_equals($row['source_hash'], (string) hash_file('sha256', $source))) throw new RuntimeException('Private original changed');
      $image = @imagecreatefromjpeg($source);
      if (!$image) throw new RuntimeException('Private original cannot be decoded');
      try { $processed = (new ImageProcessor())->apply($image, $recipe['processing']); }
      finally { imagedestroy($image); }
      try { $this->writeVariant($processed, $recipe, $variant, $path); }
      finally { imagedestroy($processed); }
      if (!$this->validOutput($path, $row, $recipe, $variant)) throw new RuntimeException('Rendered image dimensions invalid');
      return $path;
    });
  }

  /** Unter render.lock: globales Miss-Budget ohne IP-Daten oder neue SQLite-DB. */
  private function consumePublicRenderBudget(): void
  {
    $file = $this->root() . '/render-budget.json';
    if (is_link($file)) throw new RuntimeException('Invalid render budget');
    $state = is_file($file) ? json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR) : [];
    $window = intdiv(time(), 60);
    $count = ($state['window'] ?? -1) === $window ? (int) ($state['count'] ?? 0) : 0;
    if ($count >= 20) throw new RuntimeException('Public image rendering budget exhausted; retry later');
    if (!AtomicFileWriter::write($file, json_encode(['window' => $window, 'count' => $count + 1], JSON_THROW_ON_ERROR), null, 0600)) {
      throw new RuntimeException('Cannot reserve public image rendering budget');
    }
  }

  private function validOutput(string $path, array $row, array $recipe, string $variant): bool
  {
    if (is_link($path)) throw new RuntimeException('Symlink derived image rejected');
    if (!is_file($path)) return false;
    $info = @getimagesize($path);
    $width = $recipe['variants'][$variant] ?: (int) $row['width'];
    $width = min($width, (int) $row['width']);
    $height = max(1, (int) round((int) $row['height'] * $width / (int) $row['width']));
    if (!is_array($info) || $info[0] !== $width || $info[1] !== $height || $info[2] !== IMAGETYPE_JPEG) return false;
    // Eigene GD-Ausgaben enden mit EOI. Abgebrochene Cachedateien trotz
    // gueltigem JPEG-Header nicht als fertige Bilder akzeptieren.
    $handle = @fopen($path, 'rb');
    if (!$handle) return false;
    try { return fseek($handle, -2, SEEK_END) === 0 && fread($handle, 2) === "\xff\xd9"; }
    finally { fclose($handle); }
  }

  /**
   * Eigener Archivvertrag fuer Pilotbilder. Kein Beleg fuer eine NAS-Sicherung:
   * Quelle, festes Rezept und dauerhafte Vorschauen bilden den aktiven Bestand.
   * Ein defektes Pflichtteil stoppt den Lauf statt fuenf Legacy-Dateien zu erfinden.
   */
  public function verifyDay(Camera $camera, \DateTimeImmutable $day): int
  {
    if (!$this->app->config('deferred_image_root')) return 0;
    $store = $this->readStore($camera);
    if (!DeferredImageSchema::exists($store->pdo())) return 0;
    $q = $store->pdo()->prepare('SELECT d.* FROM deferred_images d JOIN captures c ON c.slot_ts=d.slot_ts
      WHERE d.slot_ts>=? AND d.slot_ts<? AND c.is_duplicate=0');
    $q->execute([$day->setTime(0, 0)->getTimestamp(), $day->setTime(0, 0)->modify('+1 day')->getTimestamp()]);
    $rows = $q->fetchAll(\PDO::FETCH_ASSOC); $q->closeCursor();
    foreach ($rows as $row) {
      $recipe = $this->decodeRecipe($row);
      $source = $this->sourcePath($camera, $row['source_hash'], (int) $row['slot_ts']);
      if (is_link($source) || !is_file($source) || !hash_equals($row['source_hash'], (string) hash_file('sha256', $source))) {
        throw new RuntimeException('Deferred archive original missing or changed');
      }
      foreach ($recipe['variants'] as $variant => $width) {
        if (!self::preview((string) $variant, $recipe)) continue;
        $path = $this->outputPath($camera, $row['image_key'], (string) $variant, $recipe, (int) $row['slot_ts']);
        if (!$this->validOutput($path, $row, $recipe, (string) $variant)) throw new RuntimeException('Deferred archive preview missing or invalid');
      }
    }
    return count($rows);
  }

  /** Ausschliesslich melden. Keine Loeschoption und kein Zugriff auf Originale/Vorschauen. */
  public function retentionReport(Camera $camera, int $now): array
  {
    if (!$this->app->config('deferred_image_root')) return ['files' => 0, 'bytes' => 0, 'cutoff' => $now - 30 * 86400];
    $root = $this->root() . '/' . $camera->slug() . '/cache';
    $report = ['files' => 0, 'bytes' => 0, 'cutoff' => $now - 30 * 86400];
    if (!is_dir($root) || is_link($root)) return $report;
    foreach (new \DirectoryIterator($root) as $bucket) {
      if ($bucket->isDot() || $bucket->isLink() || !$bucket->isDir() || !preg_match('/\A[a-f0-9]{64}\z/D', $bucket->getFilename())) continue;
      foreach (new \DirectoryIterator($bucket->getPathname()) as $file) {
        if ($file->isLink() || !$file->isFile() || $file->getExtension() !== 'jpg' || $file->getMTime() >= $report['cutoff']) continue;
        $report['files']++; $report['bytes'] += $file->getSize();
      }
    }
    return $report;
  }
}
