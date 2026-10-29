<?php
declare(strict_types=1);

namespace Webcam\Service\Maintenance;

use RuntimeException;

/**
 * Baut einen Wartungsprozess ohne Shell-Auswertung aus der Admin-Konfiguration.
 *
 * Konfigurierbare Pfade bleiben absichtlich auf bin/ innerhalb der jeweiligen
 * Installation begrenzt. Dadurch kann ein Admin weder Shell-Operatoren noch
 * einen Pfad aus einer parallelen Installation einschleusen. Argumente werden
 * einzeln an den begrenzten Prozessrunner uebergeben und niemals von /bin/sh
 * interpretiert.
 */
final class MaintenanceCommand
{
  /**
   * @param array{path:string,arguments:list<string>,timeout_seconds:int} $settings
   * @return array{command:list<string>,timeout_seconds:int}
   */
  public static function build(string $projectRoot, string $task, array $settings): array
  {
    $root = realpath($projectRoot);
    $binRoot = realpath(rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bin');
    if ($root === false || $binRoot === false || !is_dir($binRoot)) {
      throw new RuntimeException('maintenance project bin directory missing');
    }

    $relativePath = $settings['path'];
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $resolved = realpath($candidate);
    $binPrefix = rtrim($binRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($resolved === false || !is_file($resolved) || !str_starts_with($resolved, $binPrefix)) {
      throw new RuntimeException("maintenance script missing or outside bin/: {$relativePath}");
    }

    $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
    if ($extension === 'php') {
      $command = [PHP_BINARY, $resolved];
    } elseif ($extension === 'sh') {
      $command = ['/bin/bash', $resolved];
    } elseif (is_executable($resolved)) {
      $command = [$resolved];
    } else {
      throw new RuntimeException("maintenance script is not executable: {$relativePath}");
    }

    if ($task === MaintenanceSchedule::WEATHER_QUALITY) {
      $command = [
        '/usr/bin/env',
        'WEBCAM_WEATHER_QUALITY_SKIP_TARGET_BACKUP=1',
        ...$command,
      ];
    }
    foreach ($settings['arguments'] as $argument) {
      $command[] = $argument;
    }

    return [
      'command' => $command,
      'timeout_seconds' => max(10, min(7200, $settings['timeout_seconds'])),
    ];
  }
}
