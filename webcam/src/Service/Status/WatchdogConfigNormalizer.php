<?php
declare(strict_types=1);

namespace Webcam\Service\Status;

use InvalidArgumentException;
use Webcam\Infrastructure\Http\HttpClient;

/**
 * Gemeinsamer Vertrag für Admin-Eingaben und bereits gespeicherte Resetfolgen.
 * Prüft die gesamte Sequenz ohne Nebenwirkungen, bevor ein Schritt laufen darf.
 * Fehler werden abgelehnt, nicht still gekürzt oder auf Defaultwerte gesetzt.
 */
final class WatchdogConfigNormalizer
{
  public const MAX_STEPS = 20;
  public const MAX_DELAY_SECONDS = 300;
  public const MAX_SHELL_TIMEOUT_SECONDS = 300;

  /** Gesamte Konfiguration inklusive deaktivierter Folgen ohne Effekte validieren. */
  public function normalize(mixed $value): array
  {
    $watchdog = $this->object($value, 'watchdog');
    $this->allowedKeys($watchdog, ['enabled', 'max_consecutive_failures', 'cooldown', 'reset_sequence'], 'watchdog');
    if (array_key_exists('enabled', $watchdog) && !is_bool($watchdog['enabled'])) {
      throw new InvalidArgumentException('watchdog.enabled must be a boolean');
    }
    if (array_key_exists('max_consecutive_failures', $watchdog)) {
      $watchdog['max_consecutive_failures'] = $this->integer($watchdog['max_consecutive_failures'], 1, PHP_INT_MAX, 'watchdog.max_consecutive_failures');
    }
    if (array_key_exists('cooldown', $watchdog)) {
      $cooldown = $this->object($watchdog['cooldown'], 'watchdog.cooldown');
      $keys = ['min_successes_after_reset', 'min_seconds_after_reset'];
      $this->allowedKeys($cooldown, $keys, 'watchdog.cooldown');
      foreach ($cooldown as $key => $number) {
        $cooldown[$key] = $this->integer($number, 0, PHP_INT_MAX, "watchdog.cooldown.$key");
      }
      $watchdog['cooldown'] = $cooldown;
    }
    if (array_key_exists('reset_sequence', $watchdog)) {
      $sequence = $watchdog['reset_sequence'];
      if (!is_array($sequence) || !array_is_list($sequence)) {
        throw new InvalidArgumentException('watchdog.reset_sequence must be a list');
      }
      if (count($sequence) > self::MAX_STEPS) {
        throw new InvalidArgumentException('watchdog.reset_sequence must contain at most 20 steps');
      }
      foreach ($sequence as $index => $step) {
        $sequence[$index] = $this->step($step, "watchdog.reset_sequence[$index]");
      }
      $watchdog['reset_sequence'] = $sequence;
    }
    return $watchdog;
  }

  /** Je Schritttyp eigene Feld-Allowlist und Laufzeitgrenzen; keine stille Kuerzung. */
  private function step(mixed $value, string $path): array
  {
    $step = $this->object($value, $path);
    switch ($step['type'] ?? null) {
      case 'http-get':
        $this->allowedKeys($step, ['type', 'url', 'timeout'], $path);
        if (!is_string($step['url'] ?? null) || !HttpClient::isHttpUrl($step['url'])) {
          throw new InvalidArgumentException("$path.url must be an absolute http or https URL");
        }
        $step['timeout'] = $this->integer(array_key_exists('timeout', $step) ? $step['timeout'] : 5, 1, HttpClient::MAX_TIMEOUT_SECONDS, "$path.timeout");
        break;
      case 'delay':
        $this->allowedKeys($step, ['type', 'seconds'], $path);
        $step['seconds'] = $this->integer(array_key_exists('seconds', $step) ? $step['seconds'] : 1, 1, self::MAX_DELAY_SECONDS, "$path.seconds");
        break;
      case 'shell':
        // Legacy alias timeout remains accepted, but ambiguous dual values do not.
        $this->allowedKeys($step, ['type', 'command', 'timeout_seconds', 'timeout'], $path);
        if (!is_string($step['command'] ?? null) || trim($step['command']) === '' || str_contains($step['command'], "\0")) {
          throw new InvalidArgumentException("$path.command must be a non-empty string without NUL");
        }
        if (array_key_exists('timeout', $step) && array_key_exists('timeout_seconds', $step)) {
          throw new InvalidArgumentException("$path must not contain both timeout and timeout_seconds");
        }
        foreach (['timeout', 'timeout_seconds'] as $key) {
          if (array_key_exists($key, $step)) {
            $step[$key] = $this->integer($step[$key], 1, self::MAX_SHELL_TIMEOUT_SECONDS, "$path.$key");
          }
        }
        break;
      default:
        throw new InvalidArgumentException("$path.type must be http-get, delay or shell");
    }
    return $step;
  }

  /** Assoziative JSON-Struktur verlangen; leere Objekte/Arrays sind in PHP identisch. */
  private function object(mixed $value, string $path): array
  {
    // json_decode(..., true) represents an empty object and empty array alike.
    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
      throw new InvalidArgumentException("$path must be an object");
    }
    return $value;
  }

  /** Tippfehler/unbekannte Optionen ablehnen statt scheinbar erfolgreich speichern. */
  private function allowedKeys(array $value, array $allowed, string $path): void
  {
    if (array_diff(array_keys($value), $allowed) !== []) {
      throw new InvalidArgumentException("$path contains unknown fields; allowed: " . implode(', ', $allowed));
    }
  }

  /** Kanonische Ganzzahlen aus Formularen akzeptieren, Bool/Bruchteile/Overflow nicht. */
  private function integer(mixed $value, int $min, int $max, string $path): int
  {
    // Accept canonical integer form values, not booleans, fractions or overflow.
    $validType = is_int($value) || (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1);
    $number = $validType ? filter_var($value, FILTER_VALIDATE_INT) : false;
    if ($number === false || $number < $min || $number > $max) {
      throw new InvalidArgumentException("$path must be an integer between $min and $max");
    }
    return $number;
  }
}
