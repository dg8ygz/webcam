<?php
declare(strict_types=1);

namespace Webcam\Service\Weather;

/** Shared, versioned parsing contract for ingestion and legacy backfill. */
final class WeatherMetrics
{
  public const VERSION = 1;

  /** Gemeinsame Spalten, Anzeigeeinheiten, Wertebereiche und Legacy-Textmuster. */
  public static function definitions(): array
  {
    return [
      'temperature' => [
        'label' => 'Temperatur',
        'unit' => 'C',
        'precision' => 1,
        'range' => [-40.0, 60.0],
        'patterns' => [
          '/\bTemp\s*:\s*(-?\d+(?:[.,]\d+)?)\s*(?:°\s*)?C\b/i',
          '/\bT\s*:\s*(-?\d+(?:[.,]\d+)?)\s*C\b/i',
        ],
      ],
      'pressure' => [
        'label' => 'Luftdruck',
        'unit' => 'mBar',
        'precision' => 1,
        'range' => [850.0, 1100.0],
        'patterns' => [
          '/\bPress\s*:\s*(-?\d+(?:[.,]\d+)?)\s*mBar\b/i',
          '/\bP\s*:\s*(-?\d+(?:[.,]\d+)?)\s*mBar\b/i',
        ],
      ],
      'wind' => [
        'label' => 'Wind',
        'unit' => 'km/h',
        'precision' => 1,
        'range' => [0.0, 250.0],
        'patterns' => [
          '/\bWind\s*:\s*(-?\d+(?:[.,]\d+)?)\s*km\/h\b/i',
          '/\bW\s*:\s*(-?\d+(?:[.,]\d+)?)\s*km\/h\b/i',
        ],
      ],
      'humidity' => [
        'label' => 'Luftfeuchte',
        'unit' => '%',
        'precision' => 0,
        'range' => [0.0, 100.0],
        'patterns' => [
          '/\bHum\s*:\s*(-?\d+(?:[.,]\d+)?)\s*%/i',
          '/\bHumidity\s*:\s*(-?\d+(?:[.,]\d+)?)\s*%/i',
        ],
      ],
      'dewpoint' => [
        'label' => 'Taupunkt',
        'unit' => 'C',
        'precision' => 1,
        'range' => [-60.0, 60.0],
        'patterns' => [
          '/\bDewPt\s*:\s*(-?\d+(?:[.,]\d+)?)\s*(?:°\s*)?C\b/i',
          '/\bDew\s*Point\s*:\s*(-?\d+(?:[.,]\d+)?)\s*(?:°\s*)?C\b/i',
        ],
      ],
    ];
  }

  /** Liefert nur gefundene plausible Werte; Fehlertexte werden nicht als Wetter gedeutet. */
  public static function extract(string $text, ?array $metricDefinitions = null): array
  {
    $metricDefinitions ??= self::definitions();
    if (self::looksLikeError($text)) {
      return [];
    }

    $values = [];
    foreach ($metricDefinitions as $metricKey => $definition) {
      $value = self::extractNumber($text, $definition['patterns'] ?? []);
      if ($value === null) {
        continue;
      }
      $range = $definition['range'] ?? null;
      if (is_array($range) && count($range) === 2) {
        $min = (float) $range[0];
        $max = (float) $range[1];
        if ($value < $min || $value > $max) {
          continue;
        }
      }
      $values[$metricKey] = $value;
    }
    return $values;
  }

  /**
   * Erstes passendes Zahlenmuster auswerten; Dezimalkomma wie Dezimalpunkt lesen.
   * @param list<string> $patterns
   */
  private static function extractNumber(string $text, array $patterns): ?float
  {
    foreach ($patterns as $pattern) {
      if (!is_string($pattern) || $pattern === '') {
        continue;
      }
      if (preg_match($pattern, $text, $matches)) {
        $raw = str_replace(',', '.', (string) ($matches[1] ?? ''));
        if (is_numeric($raw)) {
          return (float) $raw;
        }
      }
    }
    return null;
  }

  /** Erkennt bekannte Abruf-/Modellfehler vor der numerischen Extraktion. */
  public static function looksLikeError(string $text): bool
  {
    $normalized = strtolower(trim($text));
    if ($normalized === '') {
      return false;
    }
    return str_starts_with($normalized, 'curl:')
      || str_contains($normalized, 'could not resolve host')
      || str_contains($normalized, 'resolving timed out')
      || str_contains($normalized, 'operation timed out')
      || str_contains($normalized, 'weather model:');
  }

}
