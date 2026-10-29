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
 * Schreibt eine neue Pilotaufnahme: Rohquelle privat, Vorschauen dauerhaft,
 * Current vollstaendig. Grosse Arbeitsbilder sind nur temporaere Ausgaben
 * fuer die Current-Publikation, keine zusaetzlichen Archivkopien.
 */
final class DeferredCaptureStorage
{
  public function __construct(private readonly App $app, private readonly DeferredImages $images) {}

  /** $publish erhaelt nur fertig bearbeitete Dateien, niemals die Rohquelle. */
  public function store(Camera $camera, GdImage $input, ?string $binary, ?string $source,
    int $slot, int $capturedAt, string $sourceHash, ?float $luminance, callable $publish): array
  {
    $this->images->requireSchema($camera);
    return $this->images->withRenderLock(function () use ($camera, $input, $binary, $source, $slot, $capturedAt, $sourceHash, $luminance, $publish): array {
      $store = new CameraDataStore($this->app, $camera);
      $q = $store->pdo()->prepare('SELECT * FROM captures WHERE slot_ts=?');
      $q->execute([$slot]);
      $existing = $q->fetch(\PDO::FETCH_ASSOC);
      $row = $this->images->forSlot($camera, $slot);
      if ($existing && !$row) throw new RuntimeException('Existing legacy slot must not be converted by an upload');
      if ($row && !hash_equals($row['source_hash'], $sourceHash)) throw new ImmutableImageSlotConflict('Different source for immutable image slot');
      $replay = $row !== null;
      $recipe = $row ? $this->images->decodeRecipe($row) : $this->images->recipe($camera, imagesx($input),
        (new ImageVariantGenerator($this->app))->denoiseEnabled($camera, $luminance));
      $json = $row['recipe_json'] ?? json_encode($recipe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
      $key = $row['image_key'] ?? DeferredImages::key($sourceHash, $slot, $json);
      $this->images->claimDatedSlot($camera, $sourceHash, $slot, $key);
      $sourcePath = $this->images->saveSource($camera, $sourceHash, $slot, $binary, $source);
      $exifService = new CaptureExifMetadata($this->app);
      $exif = $exifService->read($camera, $sourcePath, $luminance, $exposure);
      $image = (new ImageProcessor())->apply($input, $recipe['processing']);
      // Eindeutiges Arbeitsverzeichnis: das finally entfernt nur eigene, in
      // diesem Aufruf erzeugte Zwischenbilder. Bestandsbilder bleiben unangetastet.
      $work = $this->images->directory($camera->slug() . '/work/' . bin2hex(random_bytes(12)));
      $scratch = [];
      try {
        $row ??= ['image_key' => $key, 'slot_ts' => $slot, 'source_hash' => $sourceHash,
          'source_bytes' => filesize($sourcePath), 'recipe_json' => $json,
          'width' => imagesx($image), 'height' => imagesy($image), 'created_at' => time()];
        $numeric = []; $named = []; $variantRows = []; $original = '';
        foreach ($recipe['variants'] as $variant => $width) {
          $variant = (string) $variant;
          $permanent = DeferredImages::preview($variant, $recipe);
          $path = $permanent ? $this->images->outputPath($camera, $key, $variant, $recipe, $slot) : $work . '/' . $variant . '.jpg';
          if (!$permanent) $scratch[] = $path;
          $this->images->writeVariant($image, $recipe, $variant, $path);
          if ($variant === 'original') $original = $path;
          elseif (ctype_digit($variant)) $numeric[(int) $variant] = $path;
          else $named[$variant] = $path;
          if ($variant !== 'original') {
            $size = getimagesize($path);
            $variantRows[] = ['variant' => $variant, 'relative_path' => DeferredImages::relative($camera, $key, $variant),
              'width' => $size[0], 'height' => $size[1], 'size' => null, 'mtime' => null];
          }
        }
        $metadataBase = substr($sourcePath, 0, -4);
        $exifPath = $metadataBase . '_ex.txt';
        if (!AtomicFileWriter::write($exifPath, $exifService->sidecar($exif), null, 0600)) throw new RuntimeException('EXIF publication failed');
        $hash = hash_file('sha256', $original);
        $duplicate = !$replay && $store->latestUsableHashBeforeSlot($slot) === $hash;
        // Rezept und Capture werden in EINER SQLite-Transaktion verknuepft.
        // Ohne diese Transaktion koennte ein Bild ohne Datenschutzprofil erscheinen.
        if (!$replay) $store->recordCapture([
          'slot_timestamp' => $slot, 'captured_timestamp' => $capturedAt,
          'relative_path' => DeferredImages::relative($camera, $key, 'original'),
          'luminance' => $luminance, 'hash' => $hash, 'is_duplicate' => $duplicate,
          'variants' => $duplicate ? [] : $variantRows, 'exif' => $exif,
          'deferred_recipe' => $row,
        ]);
        $duplicate = $duplicate || ($existing && !empty($existing['is_duplicate']));
        $published = false;
        if (!$duplicate) {
          $published = (new CurrentImagePublisher($this->app))->publish($camera, $slot,
            function () use ($publish, $original, $numeric, $named, $exifPath, $camera, $recipe, $sourcePath, $hash): void {
              $publish($original, $numeric, $named, $exifPath);
              (new ImageProcessingPreview($this->app))->remember($camera, null, $sourcePath, $hash);
            });
        }
        (new CaptureBackupScheduler($this->app))->schedule($camera);
        // Bestehende Status-/Wetterverbraucher benoetigen einen echten sicheren
        // Bildpfad. lm ist permanent; Wetter bekommt einen eigenen Metadatenpfad.
        return ['replay' => $replay, 'current_published' => $published,
          'original' => $named['lm'], 'status_image_path' => DeferredImages::relative($camera, $key, 'original'), 'metadata_base_path' => $metadataBase . '.jpg',
          'relative_path' => DeferredImages::relative($camera, $key, 'original'),
          'variants' => array_filter($numeric, static fn($width): bool => $width <= 1200, ARRAY_FILTER_USE_KEY),
          'named_variants' => array_intersect_key($named, VariantProfiles::CANONICAL_WIDTHS),
          'timestamp' => $slot, 'slot_timestamp' => $slot,
          'captured_timestamp' => $replay ? (int) ($existing['captured_ts'] ?? $capturedAt) : $capturedAt,
          'exif' => $exif, 'exposure_values' => $exposure, 'exif_path' => $exifPath,
          'exif_relative' => null, 'luminance' => $luminance, 'duplicate' => (bool) $duplicate];
      } finally {
        imagedestroy($image);
        foreach ($scratch as $file) if (is_file($file)) unlink($file);
        rmdir($work);
      }
    });
  }
}
