<?php
declare(strict_types=1);

namespace Webcam\Service\Admin;

use Closure;
use Webcam\Infrastructure\Filesystem\CurrentImageDirectory;

/**
 * Checks the selected current directory in the web process namespace.
 * Existing legacy mounts are retained; new cameras use writable cache paths.
 */
final class CameraRuntimeAccessInspector
{
  private readonly Closure $directoryChecker;
  private readonly Closure $writableChecker;

  /**
   * Die optionalen Prüffunktionen sind Testnähte. Im Betrieb werden immer die
   * echten Dateisystemprüfungen im aktuellen Apache-Namespace verwendet.
   */
  public function __construct(
    private readonly string $projectRoot,
    ?callable $directoryChecker = null,
    ?callable $writableChecker = null,
    private readonly string $provisionCommand = '/usr/local/sbin/webcam-sync-apache-runtime-paths --apply'
  ) {
    $this->directoryChecker = $directoryChecker !== null
      ? Closure::fromCallable($directoryChecker)
      : static fn(string $path): bool => is_dir($path);
    $this->writableChecker = $writableChecker !== null
      ? Closure::fromCallable($writableChecker)
      : static fn(string $path): bool => is_writable($path);
  }

  /**
   * @return array{
   *   current_path:string,
   *   exists:bool,
   *   writable:bool,
   *   provision_required:bool,
   *   provision_command:string
   * }
   */
  public function inspect(string $slug): array
  {
    // Slugs sind bereits durch den Kamera-Normalizer begrenzt. Die erneute
    // Prüfung schützt trotzdem davor, aus ungeprüften Daten einen Pfad oder
    // einen irreführenden Admin-Befehl zusammenzusetzen.
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
      return [
        'current_path' => '',
        'exists' => false,
        'writable' => false,
        'provision_required' => true,
        'provision_command' => $this->provisionCommand,
      ];
    }

    $path = (new CurrentImageDirectory($this->projectRoot))->path($slug);
    $exists = ($this->directoryChecker)($path);
    $writable = $exists && ($this->writableChecker)($path);

    return [
      'current_path' => $path,
      'exists' => $exists,
      'writable' => $writable,
      'provision_required' => !$writable,
      'provision_command' => $this->provisionCommand,
    ];
  }

  /** Provision before saving configuration; failures must not claim success. */
  public function prepare(string $slug): array
  {
    (new CurrentImageDirectory($this->projectRoot))->prepare($slug);
    return $this->inspect($slug);
  }

  /**
   * @param array<string,array<string,mixed>> $cameras
   * @return array<string,array<string,mixed>>
   */
  public function inspectAll(array $cameras): array
  {
    $result = [];
    foreach ($cameras as $key => $camera) {
      $slug = trim((string) ($camera['slug'] ?? $key));
      if ($slug === '') {
        continue;
      }
      $result[$slug] = $this->inspect($slug);
    }
    ksort($result);
    return $result;
  }
}
