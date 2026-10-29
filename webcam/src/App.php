<?php
declare(strict_types=1);

namespace Webcam;

use RuntimeException;
use Webcam\Domain\Camera;
use Webcam\Domain\CameraRepository;
use Webcam\Infrastructure\Filesystem\CameraStoragePathResolver;
use Webcam\Infrastructure\Filesystem\PathResolver;
use Webcam\Infrastructure\Logging\Logger;
use Webcam\Infrastructure\View\Renderer;
use Webcam\Service\Capture\CaptureManager;
use Webcam\Service\Status\ResetService;
use Webcam\Service\Status\StatusService;
use Webcam\Service\Storage\ArchiveBrowser;
use Webcam\Service\Storage\BestOfGallery;
use Webcam\Service\Weather\WeatherCommandRunner;
use Webcam\Service\View\CameraPresenter;
use Webcam\Service\View\CameraTimelineResolver;

/**
 * Zentrale Verbindung zwischen Konfiguration und Anwendungsdiensten.
 *
 * Einstiegspunkte laden genau ein App-Objekt über bootstrap.php. Häufig
 * benötigte Grundobjekte (Pfade, Kamera-Repository und Logger) entstehen sofort;
 * größere Services erst beim ersten Methodenaufruf. Dadurch müssen einzelne
 * Seiten weder Pfade noch Abhängigkeiten selbst zusammensetzen.
 */
final class App
{
  private readonly array $config;
  private readonly CameraRepository $cameraRepository;
  private readonly PathResolver $paths;
  private readonly CameraStoragePathResolver $cameraStoragePaths;
  private readonly Logger $logger;
  private ?CaptureManager $captureManager = null;
  private ?StatusService $statusService = null;
  private ?ResetService $resetService = null;
  private ?Renderer $renderer = null;
  private ?CameraPresenter $cameraPresenter = null;
  private ?CameraTimelineResolver $cameraTimelineResolver = null;
  private ?ArchiveBrowser $archiveBrowser = null;
  private ?BestOfGallery $bestOfGallery = null;
  private ?WeatherCommandRunner $weatherRunner = null;
  private ?\Webcam\Service\Storage\DeferredImages $deferredImages = null;

  public function __construct(array $config)
  {
    $this->config = $config;
    if (isset($config['timezone'])) {
      date_default_timezone_set($config['timezone']);
    }
    $cameraConfigPath = $config['camera_config_path'] ?? null;
    if (!is_string($cameraConfigPath)) {
      throw new RuntimeException('camera_config_path missing in app config');
    }

    $this->paths = new PathResolver(
      $config['storage_path'] ?? null,
      $config['archive_path'] ?? null,
      $config['state_path'] ?? null,
      $config['log_path'] ?? null,
      $config['info_path'] ?? null,
      $config['cache_path'] ?? null,
      $config['camera_db_path'] ?? null,
    );
    $this->paths->ensureBaseDirectories();
    $this->cameraStoragePaths = new CameraStoragePathResolver($this->paths);

    $this->cameraRepository = new CameraRepository($cameraConfigPath);
    $this->logger = new Logger($this->paths->logPath('app.log'));
  }

  public function config(string $key, mixed $default = null): mixed
  {
    return $this->config[$key] ?? $default;
  }

  public function cameras(): CameraRepository
  {
    return $this->cameraRepository;
  }

  /**
   * @return array<string,mixed>
   */
  public function camerasMeta(): array
  {
    return $this->cameraRepository->meta();
  }

  public function paths(): PathResolver
  {
    return $this->paths;
  }

  public function cameraStoragePaths(): CameraStoragePathResolver
  {
    return $this->cameraStoragePaths;
  }

  public function logger(): Logger
  {
    return $this->logger;
  }

  public function captureManager(): CaptureManager
  {
    if ($this->captureManager === null) {
      $this->captureManager = new CaptureManager($this);
    }
    return $this->captureManager;
  }

  public function statusService(): StatusService
  {
    if ($this->statusService === null) {
      $this->statusService = new StatusService($this);
    }
    return $this->statusService;
  }

  public function resetService(): ResetService
  {
    if ($this->resetService === null) {
      $this->resetService = new ResetService($this);
    }
    return $this->resetService;
  }

  public function renderer(): Renderer
  {
    if ($this->renderer === null) {
      $this->renderer = new Renderer(__DIR__ . '/../templates');
    }
    return $this->renderer;
  }

  public function cameraPresenter(): CameraPresenter
  {
    if ($this->cameraPresenter === null) {
      $this->cameraPresenter = new CameraPresenter($this);
    }
    return $this->cameraPresenter;
  }

  public function cameraTimelineResolver(): CameraTimelineResolver
  {
    if ($this->cameraTimelineResolver === null) {
      $this->cameraTimelineResolver = new CameraTimelineResolver($this);
    }
    return $this->cameraTimelineResolver;
  }

  public function archiveBrowser(): ArchiveBrowser
  {
    if ($this->archiveBrowser === null) {
      $this->archiveBrowser = new ArchiveBrowser($this);
    }
    return $this->archiveBrowser;
  }

  public function bestOfGallery(): BestOfGallery
  {
    if ($this->bestOfGallery === null) {
      $this->bestOfGallery = new BestOfGallery($this);
    }
    return $this->bestOfGallery;
  }

  public function bestOfDirectory(Camera $camera): string
  {
    return $this->bestOfLocation($camera)['directory'];
  }

  public function bestOfLocation(Camera $camera): array
  {
    $storageRoot = rtrim($this->paths()->storagePath(), DIRECTORY_SEPARATOR);
    [$directory, $relative] = $this->resolveConfiguredDirectory(
      $storageRoot,
      (string) $camera->storageBestOfDir(),
      $camera->slug(),
      'BestOf'
    );
    return [
      'directory' => $directory,
      'relative' => $relative,
    ];
  }

  /**
   * Convert a storage-relative path for the given camera into an absolute filesystem path.
   *
   * @return non-empty-string|null
   */
  public function resolveCameraStoragePath(Camera $camera, string $relativePath): ?string
  {
    if (\Webcam\Service\Storage\DeferredImages::isPath($relativePath)) {
      return $this->deferredImages()->resolve($camera, $relativePath);
    }
    return $this->cameraStoragePaths->resolveStorageRelative($camera, $relativePath);
  }

  public function deferredImages(): \Webcam\Service\Storage\DeferredImages
  {
    return $this->deferredImages ??= new \Webcam\Service\Storage\DeferredImages($this);
  }

  public function weatherRunner(): WeatherCommandRunner
  {
    if ($this->weatherRunner === null) {
      $this->weatherRunner = new WeatherCommandRunner($this);
    }
    return $this->weatherRunner;
  }

  public function cameraOrFail(string $slug): Camera
  {
    $camera = $this->cameraRepository->find($slug);
    if (!$camera) {
      throw new RuntimeException("Camera not found: {$slug}");
    }
    return $camera;
  }

  /**
   * @return array{0:string,1:?string}
   */
  private function resolveConfiguredDirectory(string $storageRoot, string $configured, string $slug, string $default): array
  {
    $trimmed = trim($configured);
    if ($trimmed === '') {
      $trimmed = $default;
    }

    if ($this->isAbsolutePath($trimmed)) {
      $normalized = str_replace('\\', '/', $trimmed);
      $normalized = rtrim($normalized, '/');
      if ($normalized === '') {
        $normalized = $default;
      }
      $segments = array_values(array_filter(explode('/', $normalized), static fn(string $part): bool => $part !== ''));
      if (!$segments) {
        $segments = [$default];
      }
      if (strcasecmp(end($segments), $slug) !== 0) {
        $segments[] = $slug;
        $normalized .= '/' . $slug;
      }
      $directory = str_replace('/', DIRECTORY_SEPARATOR, $normalized);
      $relative = null;
      if ($this->pathStartsWith($directory, $storageRoot)) {
        $relative = ltrim(substr($directory, strlen($storageRoot)), DIRECTORY_SEPARATOR);
        $relative = $relative !== '' ? str_replace(DIRECTORY_SEPARATOR, '/', $relative) : null;
      }
      return [$directory, $relative];
    }

    $normalized = str_replace('\\', '/', $trimmed);
    $segments = array_values(array_filter(explode('/', $normalized), static fn(string $part): bool => $part !== ''));
    if (!$segments) {
      $segments = [$default];
    }
    if (strcasecmp(end($segments), $slug) !== 0) {
      $segments[] = $slug;
    }
    $directory = $storageRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    $relative = implode('/', $segments);
    return [$directory, $relative];
  }

  private function isAbsolutePath(string $path): bool
  {
    if ($path === '') {
      return false;
    }
    if ($path[0] === '/' || $path[0] === DIRECTORY_SEPARATOR) {
      return true;
    }
    return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
  }

  private function pathStartsWith(string $path, string $prefix): bool
  {
    if ($prefix === '') {
      return false;
    }
    $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
    $normalizedPrefix = rtrim(str_replace('\\', '/', $prefix), '/');
    if ($normalizedPrefix === '') {
      return false;
    }
    if (strncasecmp($normalizedPath, $normalizedPrefix, strlen($normalizedPrefix)) !== 0) {
      return false;
    }
    return strlen($normalizedPath) === strlen($normalizedPrefix)
      || $normalizedPath[strlen($normalizedPrefix)] === '/';
  }

}
