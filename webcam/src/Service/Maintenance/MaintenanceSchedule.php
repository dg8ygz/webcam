<?php
declare(strict_types=1);

namespace Webcam\Service\Maintenance;

use InvalidArgumentException;

/**
 * Zentrale, kleine Soll-Konfiguration fuer taegliche Wartungsscripte.
 *
 * Der interne Tick liest dieselben Werte wie die Admin-Oberflaeche. Dadurch
 * gibt es keine zweite, abweichende Zeitangabe in einer System-Crontab.
 */
final class MaintenanceSchedule
{
  public const ARCHIVE_ROLLOVER = 'archive_rollover';
  public const BACKUP_DAILY = 'backup_daily';
  public const WEATHER_QUALITY = 'weather_quality';

  private const MAX_TASKS = 32;
  private const MAX_LABEL_LENGTH = 80;
  private const MAX_DESCRIPTION_LENGTH = 300;
  private const MAX_PATH_LENGTH = 255;
  private const MAX_ARGUMENTS = 32;
  private const MAX_ARGUMENT_LENGTH = 255;

  /**
   * @return array<string,array{
   *   enabled:bool,
   *   time:string,
   *   label:string,
   *   description:string,
   *   path:string,
   *   arguments:list<string>,
   *   timeout_seconds:int
   * }>
   */
  public static function defaults(): array
  {
    return [
      self::ARCHIVE_ROLLOVER => [
        'enabled' => false,
        'time' => '01:00',
        'label' => 'Archiv-Rollover',
        'description' => 'Prueft und archiviert den abgeschlossenen Vortag auf dem autorisierten Archivschreiber.',
        'path' => 'bin/archive_rollover.php',
        'arguments' => [],
        'timeout_seconds' => 7200,
      ],
      self::BACKUP_DAILY => [
        'enabled' => true,
        'time' => '03:20',
        'label' => 'Datenbank-Backup',
        'description' => 'Sichert Config- und Kamera-Datenbanken konsistent.',
        'path' => 'bin/backup_daily.php',
        'arguments' => ['--force', '--verbose'],
        'timeout_seconds' => 1800,
      ],
      self::WEATHER_QUALITY => [
        'enabled' => true,
        'time' => '03:30',
        'label' => 'Wetterdaten Vortag',
        'description' => 'Markiert nach erfolgreichem Tagesbackup ungueltige Wetterwerte des Vortags.',
        'path' => 'bin/weather_quality_previous_day.sh',
        'arguments' => [],
        'timeout_seconds' => 600,
      ],
    ];
  }

  /**
   * @param mixed $value
   * @return array<string,array{
   *   enabled:bool,
   *   time:string,
   *   label:string,
   *   description:string,
   *   path:string,
   *   arguments:list<string>,
   *   timeout_seconds:int
   * }>
   */
  public static function normalize(mixed $value): array
  {
    $input = is_array($value) ? $value : [];
    $defaults = self::defaults();
    $result = [];

    foreach ($defaults as $task => $taskDefaults) {
      $settings = is_array($input[$task] ?? null) ? $input[$task] : [];
      $result[$task] = self::normalizeTask($settings, $taskDefaults);
    }

    foreach ($input as $task => $settings) {
      if (isset($defaults[$task]) || !is_string($task) || !self::isValidTaskId($task)) {
        continue;
      }
      if (!is_array($settings) || count($result) >= self::MAX_TASKS) {
        continue;
      }
      $customDefaults = self::customDefaults($task);
      $normalized = self::normalizeTask($settings, $customDefaults);
      if ($normalized['path'] === '') {
        $normalized['enabled'] = false;
      }
      $result[$task] = $normalized;
    }
    return $result;
  }

  /**
   * Validiert einen vollstaendigen Admin-Payload ohne stille Korrektur.
   *
   * @param array<string,mixed> $value
   * @return array<string,array{
   *   enabled:bool,
   *   time:string,
   *   label:string,
   *   description:string,
   *   path:string,
   *   arguments:list<string>,
   *   timeout_seconds:int
   * }>
   */
  public static function validateUpdate(array $value): array
  {
    if (count($value) > self::MAX_TASKS) {
      throw new InvalidArgumentException('maintenance script limit exceeded');
    }

    $defaults = self::defaults();
    foreach (array_keys($defaults) as $task) {
      if (!array_key_exists($task, $value)) {
        throw new InvalidArgumentException("{$task} settings missing");
      }
    }

    $result = [];
    foreach ($value as $task => $settings) {
      if (!is_string($task) || !self::isValidTaskId($task)) {
        throw new InvalidArgumentException('invalid maintenance script id');
      }
      if (!is_array($settings)) {
        throw new InvalidArgumentException("{$task} settings missing");
      }
      if (!array_key_exists('enabled', $settings) || !is_bool($settings['enabled'])) {
        throw new InvalidArgumentException("{$task}.enabled must be boolean");
      }
      if (!self::isValidTime($settings['time'] ?? null)) {
        throw new InvalidArgumentException("{$task}.time must use HH:MM");
      }
      $taskDefaults = $defaults[$task] ?? self::customDefaults($task);
      $label = self::validateTextField(
        $settings['label'] ?? $taskDefaults['label'],
        "{$task}.label",
        self::MAX_LABEL_LENGTH,
        false
      );
      $description = self::validateTextField(
        $settings['description'] ?? $taskDefaults['description'],
        "{$task}.description",
        self::MAX_DESCRIPTION_LENGTH,
        true
      );
      $path = self::validatePath($settings['path'] ?? $taskDefaults['path'], "{$task}.path");
      $arguments = self::validateArguments(
        $settings['arguments'] ?? $taskDefaults['arguments'],
        "{$task}.arguments"
      );
      $timeout = $settings['timeout_seconds'] ?? $taskDefaults['timeout_seconds'];
      if (!is_int($timeout) || $timeout < 10 || $timeout > 7200) {
        throw new InvalidArgumentException("{$task}.timeout_seconds must be between 10 and 7200");
      }

      $result[$task] = [
        'enabled' => $settings['enabled'],
        'time' => (string) $settings['time'],
        'label' => $label,
        'description' => $description,
        'path' => $path,
        'arguments' => $arguments,
        'timeout_seconds' => $timeout,
      ];
    }
    if ($result[self::WEATHER_QUALITY]['enabled']) {
      if (!$result[self::BACKUP_DAILY]['enabled']) {
        throw new InvalidArgumentException('backup_daily must be enabled before weather_quality');
      }
      if ($result[self::WEATHER_QUALITY]['time'] <= $result[self::BACKUP_DAILY]['time']) {
        throw new InvalidArgumentException('weather_quality.time must be later than backup_daily.time');
      }
    }
    return $result;
  }

  /** @return array<string,array{label:string,description:string,builtin:bool}> */
  public static function definitions(): array
  {
    $defaults = self::defaults();
    return [
      self::ARCHIVE_ROLLOVER => [
        'label' => $defaults[self::ARCHIVE_ROLLOVER]['label'],
        'description' => $defaults[self::ARCHIVE_ROLLOVER]['description'],
        'builtin' => true,
      ],
      self::BACKUP_DAILY => [
        'label' => $defaults[self::BACKUP_DAILY]['label'],
        'description' => $defaults[self::BACKUP_DAILY]['description'],
        'builtin' => true,
      ],
      self::WEATHER_QUALITY => [
        'label' => $defaults[self::WEATHER_QUALITY]['label'],
        'description' => $defaults[self::WEATHER_QUALITY]['description'],
        'builtin' => true,
      ],
    ];
  }

  public static function isBuiltin(string $task): bool
  {
    return isset(self::defaults()[$task]);
  }

  /**
   * @param array<string,mixed> $settings
   * @param array{
   *   enabled:bool,
   *   time:string,
   *   label:string,
   *   description:string,
   *   path:string,
   *   arguments:list<string>,
   *   timeout_seconds:int
   * } $defaults
   * @return array{
   *   enabled:bool,
   *   time:string,
   *   label:string,
   *   description:string,
   *   path:string,
   *   arguments:list<string>,
   *   timeout_seconds:int
   * }
   */
  private static function normalizeTask(array $settings, array $defaults): array
  {
    $label = self::normalizeText($settings['label'] ?? null, $defaults['label'], self::MAX_LABEL_LENGTH);
    $description = self::normalizeText(
      $settings['description'] ?? null,
      $defaults['description'],
      self::MAX_DESCRIPTION_LENGTH,
      true
    );
    $path = self::normalizePath($settings['path'] ?? null, $defaults['path']);
    $arguments = self::normalizeArguments($settings['arguments'] ?? null, $defaults['arguments']);
    $timeout = $settings['timeout_seconds'] ?? null;

    return [
      'enabled' => array_key_exists('enabled', $settings)
        ? (is_bool($settings['enabled']) ? $settings['enabled'] : false)
        : $defaults['enabled'],
      'time' => self::isValidTime($settings['time'] ?? null)
        ? (string) $settings['time']
        : $defaults['time'],
      'label' => $label,
      'description' => $description,
      'path' => $path,
      'arguments' => $arguments,
      'timeout_seconds' => is_int($timeout) && $timeout >= 10 && $timeout <= 7200
        ? $timeout
        : $defaults['timeout_seconds'],
    ];
  }

  /**
   * @return array{
   *   enabled:bool,
   *   time:string,
   *   label:string,
   *   description:string,
   *   path:string,
   *   arguments:list<string>,
   *   timeout_seconds:int
   * }
   */
  private static function customDefaults(string $task): array
  {
    return [
      'enabled' => false,
      'time' => '04:00',
      'label' => str_replace(['_', '-'], ' ', $task),
      'description' => '',
      'path' => '',
      'arguments' => [],
      'timeout_seconds' => 300,
    ];
  }

  private static function isValidTaskId(string $task): bool
  {
    return preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $task) === 1;
  }

  private static function normalizeText(
    mixed $value,
    string $fallback,
    int $maxLength,
    bool $allowEmpty = false
  ): string {
    if (!is_string($value)) {
      return $fallback;
    }
    $value = trim($value);
    if ((!$allowEmpty && $value === '') || strlen($value) > $maxLength || str_contains($value, "\0")) {
      return $fallback;
    }
    return $value;
  }

  private static function validateTextField(
    mixed $value,
    string $field,
    int $maxLength,
    bool $allowEmpty
  ): string {
    if (!is_string($value)) {
      throw new InvalidArgumentException("{$field} must be a string");
    }
    $value = trim($value);
    if ((!$allowEmpty && $value === '') || strlen($value) > $maxLength || str_contains($value, "\0")) {
      throw new InvalidArgumentException("{$field} is invalid");
    }
    return $value;
  }

  private static function normalizePath(mixed $value, string $fallback): string
  {
    if (!is_string($value)) {
      return $fallback;
    }
    $value = trim($value);
    return self::isValidRelativePath($value) ? $value : $fallback;
  }

  private static function validatePath(mixed $value, string $field): string
  {
    if (!is_string($value) || !self::isValidRelativePath(trim($value))) {
      throw new InvalidArgumentException("{$field} must be a relative path below bin/");
    }
    return trim($value);
  }

  private static function isValidRelativePath(string $path): bool
  {
    if ($path === '' || strlen($path) > self::MAX_PATH_LENGTH || str_contains($path, "\0")) {
      return false;
    }
    if (!str_starts_with($path, 'bin/') || str_starts_with($path, '/') || str_contains($path, '\\')) {
      return false;
    }
    foreach (explode('/', $path) as $segment) {
      if ($segment === '' || $segment === '.' || $segment === '..') {
        return false;
      }
    }
    return preg_match('#^bin/[A-Za-z0-9._/-]+$#', $path) === 1;
  }

  /** @param list<string> $fallback @return list<string> */
  private static function normalizeArguments(mixed $value, array $fallback): array
  {
    if (!is_array($value) || count($value) > self::MAX_ARGUMENTS) {
      return $fallback;
    }
    $result = [];
    foreach ($value as $argument) {
      if (!is_string($argument)
        || strlen($argument) > self::MAX_ARGUMENT_LENGTH
        || str_contains($argument, "\0")
        || str_contains($argument, "\n")
        || str_contains($argument, "\r")
      ) {
        return $fallback;
      }
      $result[] = $argument;
    }
    return $result;
  }

  /** @return list<string> */
  private static function validateArguments(mixed $value, string $field): array
  {
    if (!is_array($value) || count($value) > self::MAX_ARGUMENTS) {
      throw new InvalidArgumentException("{$field} must be an array with at most " . self::MAX_ARGUMENTS . ' entries');
    }
    $result = [];
    foreach ($value as $argument) {
      if (!is_string($argument)
        || strlen($argument) > self::MAX_ARGUMENT_LENGTH
        || str_contains($argument, "\0")
        || str_contains($argument, "\n")
        || str_contains($argument, "\r")
      ) {
        throw new InvalidArgumentException("{$field} contains an invalid argument");
      }
      $result[] = $argument;
    }
    return $result;
  }

  private static function isValidTime(mixed $value): bool
  {
    if (!is_string($value) || !preg_match('/^(\d{2}):(\d{2})$/', $value, $matches)) {
      return false;
    }
    return (int) $matches[1] <= 23 && (int) $matches[2] <= 59;
  }
}
