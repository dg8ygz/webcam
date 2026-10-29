<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Ein einziges privates Eingangsbild je bearbeiteter Kamera ermoeglicht neue
 * Vorschauen ohne doppelte Filterung des bereits bearbeiteten Public-JPEGs.
 * Kein historisches Roharchiv. Zugriff ausschliesslich ueber die Admin-API.
 */
final class ImageProcessingPreview
{
  public function __construct(private readonly App $app) {}

  private function directory(Camera $camera): string
  {
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $camera->slug())) { throw new RuntimeException('Ungueltige Kamera'); }
    return $this->app->paths()->statePath('image-processing/' . $camera->slug());
  }

  public function remember(Camera $camera, ?string $binary, ?string $file, string $publishedHash): void
  {
    $dir = $this->directory($camera);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('Privater Vorschauspeicher fehlt'); }
    $ok = $file !== null ? AtomicFileWriter::copy($file, $dir . '/source.jpg', 0600)
      : AtomicFileWriter::write($dir . '/source.jpg', (string) $binary, null, 0600);
    if (!$ok || !AtomicFileWriter::write($dir . '/source.json', json_encode([
      'published_sha256' => $publishedHash, 'input_sha256' => hash_file('sha256', $dir . '/source.jpg'),
    ], JSON_THROW_ON_ERROR), null, 0600)) { throw new RuntimeException('Privates Vorschaubild konnte nicht gesichert werden'); }
  }

  public function render(Camera $camera, array $config): array
  {
    $config = ImageProcessingConfig::normalize($config);
    // Ein paralleler Vorschaulauf je Installation; Browser sendet entprellt.
    $lock = fopen($this->app->paths()->statePath('image-preview.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); throw new RuntimeException('Vorschau beschaeftigt, bitte erneut versuchen'); }
    $source = $result = null;
    try {
      $db = (new CameraDataStore($this->app, $camera))->pdo();
      $row = $db->query('SELECT relative_path, hash_sha256, captured_ts, slot_ts FROM captures WHERE is_duplicate=0 ORDER BY slot_ts DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
      if (!$row) { throw new RuntimeException('Noch kein Bild fuer diese Kamera vorhanden'); }
      $dir = $this->directory($camera);
      $meta = is_file($dir . '/source.json') ? json_decode((string) file_get_contents($dir . '/source.json'), true) : null;
      if (is_array($meta) && ($meta['published_sha256'] ?? null) === $row['hash_sha256']) {
        $file = $dir . '/source.jpg'; $expected = $meta['input_sha256'] ?? '';
      } else {
        if (($camera->capture()['image_processing']['enabled'] ?? false) === true) { throw new RuntimeException('Unbearbeitetes Vorschaubild fehlt; naechste Aufnahme abwarten'); }
        $file = $this->app->cameraStoragePaths()->resolveStorageRelative($camera, $row['relative_path']);
        $expected = $row['hash_sha256'];
      }
      if (!$file || !is_file($file)) { throw new RuntimeException('Vorschaubild nicht verfuegbar'); }
      SnapshotResourceLimits::checkBytes((int) filesize($file), $this->app);
      $binary = file_get_contents($file, false, null, 0, SnapshotResourceLimits::maxBytes($this->app) + 1);
      if (is_string($binary)) SnapshotResourceLimits::checkBytes(strlen($binary), $this->app);
      if (!is_string($binary) || !hash_equals((string) $expected, hash('sha256', $binary))) { throw new RuntimeException('Vorschaubild hat sich geaendert; erneut laden'); }
      SnapshotResourceLimits::checkDimensions(getimagesizefromstring($binary));
      // Beim ersten Editieren den unveraenderten Eingang privat merken. So
      // bleibt weiteres Vorschauen auch direkt nach dem Speichern moeglich.
      if ((!is_array($meta) || ($meta['published_sha256'] ?? null) !== $row['hash_sha256']) && !($camera->capture()['image_processing']['enabled'] ?? false)) {
        $this->remember($camera, $binary, null, (string) $row['hash_sha256']);
      }
      $source = imagecreatefromstring($binary);
      unset($binary);
      if (!$source) { throw new RuntimeException('Vorschaubild ist kein lesbares JPEG'); }
      $processor = new ImageProcessor();
      if (StrombergPrivacyCrop::required($camera->slug(), (int) $row['slot_ts'])) {
        $safe = StrombergPrivacyCrop::apply($source);
        imagedestroy($source); $source = $safe;
        $config = StrombergPrivacyCrop::remainingProcessing($config, imagesx($source));
      }
      $result = $processor->apply($source, $config);
      return [
        'width' => imagesx($source), 'height' => imagesy($source),
        'result_width' => imagesx($result), 'result_height' => imagesy($result),
        'captured_at' => (int) $row['captured_ts'], 'source_id' => $expected,
        'original' => $this->thumbnail($source, $processor),
        'preview' => $this->thumbnail($result, $processor),
      ];
    } finally {
      if ($result) imagedestroy($result);
      if ($source) imagedestroy($source);
      flock($lock, LOCK_UN); fclose($lock);
    }
  }

  private function thumbnail(\GdImage $image, ImageProcessor $processor): string
  {
    $ratio = min(1, 1000 / imagesx($image), 800 / imagesy($image));
    $small = imagescale($image, max(1, (int) round(imagesx($image)*$ratio)), max(1, (int) round(imagesy($image)*$ratio)));
    if (!$small) { throw new RuntimeException('Vorschauverkleinerung fehlgeschlagen'); }
    try { return 'data:image/jpeg;base64,' . base64_encode($processor->jpeg($small, 80)); }
    finally { imagedestroy($small); }
  }
}
