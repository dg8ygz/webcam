<?php
declare(strict_types=1);

namespace Webcam\Service\Weather;

use DateTimeImmutable;
use DateTimeZone;
use DirectoryIterator;
use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Findet und interpretiert physische Wetter-Sidecars.
 *
 * Unterstützt werden die beiden historisch verwendeten Pfadformen im Rolling
 * Cache und im Archiv. Dateiname/Pfad bestimmen ausschließlich den Slot;
 * Inhalte werden zusätzlich gegen realistische Wettergrenzen geprüft.
 */
final class WeatherSidecarParser
{
  /** @param array<int,bool> $years @param array<int,bool> $months @return Generator<string> */
  public function files(string $base, array $years, array $months): Generator
  {
    $yearDirectories = [];
    if ($years !== []) {
      foreach (array_keys($years) as $year) {
        $directory = $base . DIRECTORY_SEPARATOR . sprintf('%04d', $year);
        if (is_dir($directory)) {
          $yearDirectories[] = $directory;
        }
      }
    } else {
      foreach (new DirectoryIterator($base) as $entry) {
        if (!$entry->isDot() && $entry->isDir() && preg_match('/^\d{4}$/', $entry->getFilename())) {
          $yearDirectories[] = $entry->getPathname();
        }
      }
      sort($yearDirectories);
    }

    foreach ($yearDirectories as $yearDirectory) {
      $scanDirectories = [];
      if ($months !== []) {
        foreach (array_keys($months) as $month) {
          $directory = $yearDirectory . DIRECTORY_SEPARATOR . sprintf('%02d', $month);
          if (is_dir($directory)) {
            $scanDirectories[] = $directory;
          }
        }
      } else {
        $scanDirectories[] = $yearDirectory;
      }
      sort($scanDirectories);
      foreach ($scanDirectories as $scanDirectory) {
        $iterator = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($scanDirectory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
          if ($file instanceof SplFileInfo && $file->isFile()
            && preg_match('/_(?:wx|w)\.txt$/i', $file->getFilename())) {
            yield $file->getPathname();
          }
        }
      }
    }
  }

  /** @return array{year:int,slot_ts:int}|null */
  public function pathMetadata(string $file, DateTimeZone $timezone): ?array
  {
    $path = str_replace('\\', '/', $file);
    if (preg_match('#/(\d{4})/(\d{2})/(\d{2})(?:_[^/]+)?/(\d{8})_(\d{6})(?:_utc([1-9]\d{0,10}))?_(?:wx|w)\.txt$#i', $path, $match)) {
      $date = \Webcam\Service\Storage\CaptureFilename::date($match[4] . $match[5], $timezone, $match[6] ?? '');
      return $date instanceof DateTimeImmutable
        ? ['year' => (int) $date->format('Y'), 'slot_ts' => $date->getTimestamp()]
        : null;
    }
    if (preg_match('#/(\d{4})/(\d{2})/(\d{2})/(\d{4})(?:_utc([1-9]\d{0,10}))?_(?:wx|w)\.txt$#i', $path, $match)) {
      $date = \Webcam\Service\Storage\CaptureFilename::date($match[1] . $match[2] . $match[3] . $match[4] . '00',
        $timezone, $match[5] ?? '');
      return $date instanceof DateTimeImmutable
        ? ['year' => (int) $match[1], 'slot_ts' => $date->getTimestamp()]
        : null;
    }
    return null;
  }

  /** @return list<string>|null */
  public function lines(string $file): ?array
  {
    $raw = file_get_contents($file);
    if ($raw === false) {
      return null;
    }
    $lines = preg_split('/\R/', trim($raw));
    return is_array($lines)
      ? array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''))
      : [];
  }

  /** @param list<string> $lines @return array{ok:bool,reason:string} */
  public function validate(array $lines): array
  {
    $text = str_replace(["\u{2212}", "\u{2013}", "\u{2014}"], '-', implode(' ', $lines));
    $checks = [
      ['label' => 'temperature', 'pattern' => '/\b(?:Temp(?:\.|eratur)?|T)\s*:\s*(-?\d+(?:[.,]\d+)?)\s*(?:°)?\s*C\b/iu', 'min' => -35.0, 'max' => 45.0, 'unit' => 'C'],
      ['label' => 'pressure', 'pattern' => '/\b(?:Press(?:\.|ure)?|Luftdruck|P)\s*:\s*(-?\d+(?:[.,]\d+)?)\s*(?:mbar|hpa)\b/i', 'min' => 930.0, 'max' => 1060.0, 'unit' => 'mBar'],
    ];
    foreach ($checks as $check) {
      preg_match_all($check['pattern'], $text, $matches);
      foreach ($matches[1] ?? [] as $rawValue) {
        $value = $this->number((string) $rawValue);
        if ($value < $check['min'] || $value > $check['max']) {
          return ['ok' => false, 'reason' => sprintf('%s %.1f %s outside %.1f..%.1f', $check['label'], $value, $check['unit'], $check['min'], $check['max'])];
        }
      }
    }
    preg_match_all('/\b(?:Wind|W)\s*:\s*(-?\d+(?:[.,]\d+)?)\s*(km\/h|kmh|m\/s|kn)?\b/i', $text, $wind, PREG_SET_ORDER);
    foreach ($wind as $match) {
      $value = $this->windKmh($this->number((string) $match[1]), strtolower((string) ($match[2] ?? 'km/h')));
      if ($value < 0.0 || $value > 180.0) {
        return ['ok' => false, 'reason' => sprintf('wind %.1f km/h outside 0.0..180.0', $value)];
      }
    }
    return ['ok' => true, 'reason' => ''];
  }

  private function number(string $value): float
  {
    return (float) str_replace(',', '.', trim($value));
  }

  private function windKmh(float $value, string $unit): float
  {
    return match ($unit) { 'm/s' => $value * 3.6, 'kn' => $value * 1.852, default => $value };
  }
}
