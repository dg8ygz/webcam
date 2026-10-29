<?php
declare(strict_types=1);

namespace Webcam\Service\Maintenance;

use Webcam\Infrastructure\Security\SecretRedactor;

/**
 * Bereitet die begrenzte Ausgabe genau eines Wartungsscripts fuer das Admin-UI auf.
 *
 * Neue Scheduler-Laeufe speichern diese Eintraege direkt in der taskbezogenen
 * Statusdatei. Fuer Statusdateien aus der Zeit davor kann der eindeutig durch
 * START/DONE abgegrenzte Abschnitt des gemeinsamen Scheduler-Logs gelesen werden.
 */
final class MaintenanceLog
{
  private const MAX_ENTRY_BYTES = 2048;
  private const LEGACY_TAIL_BYTES = 1048576;

  /** @return list<string> */
  public static function lastEntries(string $output, int $limit = 5): array
  {
    $limit = max(1, min(20, $limit));
    $lines = preg_split('/\r\n|\r|\n/', $output);
    if (!is_array($lines)) {
      return [];
    }

    $entries = [];
    foreach ($lines as $line) {
      $entry = self::normalizeEntry($line);
      if ($entry !== '') {
        $entries[] = $entry;
      }
    }
    return array_values(array_slice($entries, -$limit));
  }

  /** @param mixed $stored @return list<string> */
  public static function storedEntries(mixed $stored, int $limit = 5): array
  {
    if (!is_array($stored)) {
      return [];
    }
    $lines = [];
    foreach ($stored as $entry) {
      if (is_string($entry)) {
        $lines[] = $entry;
      }
    }
    return self::lastEntries(implode("\n", $lines), $limit);
  }

  /**
   * Liest ausschliesslich den Lauf, dessen START-Zeile zu Task-Label und
   * Startzeit passt. Fehlt diese eindeutige Grenze, wird nichts angezeigt.
   *
   * @return list<string>
   */
  public static function legacyTaskEntries(
    string $logPath,
    string $task,
    string $label,
    ?string $startedAt,
    int $limit = 5
  ): array {
    if (
      !preg_match('/^[a-z][a-z0-9_]{1,63}$/', $task)
      || $label === ''
      || $startedAt === null
      || $startedAt === ''
      || !is_file($logPath)
    ) {
      return [];
    }

    $contents = self::readTail($logPath, self::LEGACY_TAIL_BYTES);
    if ($contents === '') {
      return [];
    }
    $lines = preg_split('/\r\n|\r|\n/', $contents);
    if (!is_array($lines)) {
      return [];
    }

    $expectedStart = '[START] ' . $label . ' at ' . $startedAt;
    $startIndex = null;
    foreach ($lines as $index => $line) {
      if (trim($line) === $expectedStart) {
        $startIndex = $index;
      }
    }
    if ($startIndex === null) {
      return [];
    }

    $taskOutput = [];
    $donePrefix = '[DONE] ' . $label . ' exit=';
    for ($index = $startIndex + 1, $count = count($lines); $index < $count; $index += 1) {
      $line = trim($lines[$index]);
      if (str_starts_with($line, $donePrefix) || str_starts_with($line, '[START] ')) {
        break;
      }
      $taskOutput[] = $line;
    }

    return self::lastEntries(implode("\n", $taskOutput), $limit);
  }

  private static function normalizeEntry(string $entry): string
  {
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $entry) ?? '';
    $clean = trim(SecretRedactor::redact($clean));
    if (strlen($clean) > self::MAX_ENTRY_BYTES) {
      $clean = substr($clean, 0, self::MAX_ENTRY_BYTES) . '...';
    }
    return $clean;
  }

  private static function readTail(string $path, int $maxBytes): string
  {
    $size = filesize($path);
    if (!is_int($size) || $size <= 0) {
      return '';
    }
    $offset = max(0, $size - $maxBytes);
    $contents = file_get_contents($path, false, null, $offset);
    if (!is_string($contents)) {
      return '';
    }
    if ($offset > 0) {
      $firstNewline = strpos($contents, "\n");
      $contents = $firstNewline === false ? '' : substr($contents, $firstNewline + 1);
    }
    return $contents;
  }
}
