#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\Infrastructure\Process\BoundedProcessRunner;

$app = require __DIR__ . '/../bootstrap.php';

// OCR-Pipeline für historische Wetteranzeigen: Quelle und Zeitraum eingrenzen,
// Bildausschnitt mit harten Tool-Timeouts erzeugen, Text per Tesseract lesen,
// Werte plausibilisieren und Ergebnis/Fehlermarker neben dem Archivbild ablegen.
// Ohne --overwrite werden bereits entschiedene Slots nicht erneut bearbeitet.

// ---------------------------------------------------------------------------
// 1. Konfiguration einlesen.
// Kommandozeilenwerte überschreiben die sicheren Defaults; Umgebungsvariablen
// erlauben betriebliche Standardwerte, ohne das Script zu ändern.
// ---------------------------------------------------------------------------
$args = $_SERVER['argv'] ?? [];
array_shift($args);

$slug = null;
$year = null;
$month = null;
$day = null;
$dir = null;
$variant = 'la';
$fallbackVariant = null;
$root = 'archive';
$overwrite = false;
$dryRun = false;
$verbose = false;
$limit = null;
$timeFilter = null;
$precision = true;
$rescue = false;
$maxOcrFailures = 2;
$ocrTimeoutSeconds = 20;
$convertTimeoutSeconds = 20;
$imageTimeoutSeconds = 45;

$envMaxOcrFailures = getenv('WX_OCR_MAX_FAILURES');
if ($envMaxOcrFailures !== false && preg_match('/^\d+$/', $envMaxOcrFailures)) {
  $maxOcrFailures = (int) $envMaxOcrFailures;
}
$envOcrTimeout = getenv('WX_OCR_TIMEOUT_SECONDS');
if ($envOcrTimeout !== false && preg_match('/^\d+$/', $envOcrTimeout)) {
  $ocrTimeoutSeconds = (int) $envOcrTimeout;
}
$envConvertTimeout = getenv('WX_OCR_CONVERT_TIMEOUT_SECONDS');
if ($envConvertTimeout !== false && preg_match('/^\d+$/', $envConvertTimeout)) {
  $convertTimeoutSeconds = (int) $envConvertTimeout;
}
$envImageTimeout = getenv('WX_OCR_IMAGE_TIMEOUT_SECONDS');
if ($envImageTimeout !== false && preg_match('/^\d+$/', $envImageTimeout)) {
  $imageTimeoutSeconds = (int) $envImageTimeout;
}

// Die Hilfe endet vor jedem Dateizugriff und kann deshalb gefahrlos zur
// Kontrolle der verfügbaren Optionen aufgerufen werden.
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
  echo "Usage: php bin/extract_wx_ocr.php --slug=<slug> --year=YYYY --month=MM --day=DD [--variant=la] [--fallback-variant=hu] [--root=archive|cache|storage] [--dir=/path] [--time=HHMM] [--overwrite] [--dry-run] [--limit=N] [--max-ocr-failures=N] [--ocr-timeout=N] [--convert-timeout=N] [--image-timeout=N] [--verbose] [--no-precision] [--rescue]\n";
  echo "  --dir       Optional absolute/relative directory to scan instead of slug+date.\n";
  echo "  --time      Optional HHMM to process a single image.\n";
  echo "  --variant   Image variant to OCR (default: la).\n";
  echo "  --fallback-variant Optional rescue variant tried only after a failed precise OCR pass (default for la: hu).\n";
  echo "  --root      Base root when using slug+date (default: archive).\n";
  echo "  --overwrite Overwrite existing HHMM_wx.txt files.\n";
  echo "  --dry-run   Do not write files; just log what would be done.\n";
  echo "  --limit     Stop after N images (for quick testing).\n";
  echo "  --max-ocr-failures=N After N generic OCR parse failures, write HHMM_ocr_failed.no and skip on later runs (default: 2; env WX_OCR_MAX_FAILURES; 0 disables).\n";
  echo "  --ocr-timeout=N Hard timeout per tesseract pass in seconds (default: 20; env WX_OCR_TIMEOUT_SECONDS; range 1-300).\n";
  echo "  --convert-timeout=N Hard timeout per ImageMagick convert pass in seconds (default: 20; env WX_OCR_CONVERT_TIMEOUT_SECONDS; range 1-300).\n";
  echo "  --image-timeout=N Hard timeout for all OCR passes of one source image in seconds (default: 45; env WX_OCR_IMAGE_TIMEOUT_SECONDS; range 1-600).\n";
  echo "  --verbose   Print per-file output.\n";
  echo "  --no-precision Disable extra OCR passes (faster).\n";
  echo "  --rescue    Enable extra heavy OCR passes for stubborn images.\n";
  echo "  Missing weather is recorded as HHMM_weather.no and skipped on later runs.\n";
  echo "  Pressure jumps are recorded as HHMM_pressure_jump.no and skipped on later runs.\n";
  echo "  Repeated generic OCR parse failures are recorded as HHMM_ocr_failed.no after the configured failure limit.\n";
  exit(0);
}

// Jede Option wird einzeln gelesen. Unbekannte Optionen verändern keinen
// Zustand; fehlende Pflichtangaben werden weiter unten sichtbar abgelehnt.
foreach ($args as $arg) {
  if (str_starts_with($arg, '--slug=')) {
    $slug = trim(substr($arg, 7));
  } elseif (str_starts_with($arg, '--year=')) {
    $year = (int) substr($arg, 7);
  } elseif (str_starts_with($arg, '--month=')) {
    $month = (int) substr($arg, 8);
  } elseif (str_starts_with($arg, '--day=')) {
    $day = (int) substr($arg, 6);
  } elseif (str_starts_with($arg, '--dir=')) {
    $dir = trim(substr($arg, 6));
  } elseif (str_starts_with($arg, '--variant=')) {
    $variant = trim(substr($arg, 10));
  } elseif (str_starts_with($arg, '--fallback-variant=')) {
    $fallbackVariant = trim(substr($arg, 19));
  } elseif (str_starts_with($arg, '--time=')) {
    $timeFilter = trim(substr($arg, 7));
  } elseif (str_starts_with($arg, '--root=')) {
    $root = trim(substr($arg, 7));
  } elseif (str_starts_with($arg, '--limit=')) {
    $limit = (int) substr($arg, 8);
  } elseif (str_starts_with($arg, '--max-ocr-failures=')) {
    $maxOcrFailures = (int) substr($arg, 19);
  } elseif (str_starts_with($arg, '--ocr-timeout=')) {
    $ocrTimeoutSeconds = (int) substr($arg, 14);
  } elseif (str_starts_with($arg, '--convert-timeout=')) {
    $convertTimeoutSeconds = (int) substr($arg, 18);
  } elseif (str_starts_with($arg, '--image-timeout=')) {
    $imageTimeoutSeconds = (int) substr($arg, 16);
  } elseif ($arg === '--overwrite') {
    $overwrite = true;
  } elseif ($arg === '--dry-run') {
    $dryRun = true;
  } elseif ($arg === '--verbose') {
    $verbose = true;
  } elseif ($arg === '--no-precision') {
    $precision = false;
  } elseif ($arg === '--rescue') {
    $rescue = true;
  }
}

// Zahlen und Varianten nach dem Parsen in feste Betriebsgrenzen zwingen.
if ($maxOcrFailures < 0) {
  $maxOcrFailures = 0;
}
$ocrTimeoutSeconds = max(1, min(300, $ocrTimeoutSeconds));
$convertTimeoutSeconds = max(1, min(300, $convertTimeoutSeconds));
$imageTimeoutSeconds = max(1, min(600, $imageTimeoutSeconds));

if ($fallbackVariant === null && $variant === 'la') {
  $fallbackVariant = 'hu';
}
if ($fallbackVariant !== null) {
  $fallbackVariant = trim($fallbackVariant);
  if ($fallbackVariant === '' || $fallbackVariant === $variant) {
    $fallbackVariant = null;
  }
}

// ---------------------------------------------------------------------------
// 2. Quellverzeichnis und Werkzeuge prüfen.
// Entweder wurde ein Verzeichnis ausdrücklich angegeben oder es wird über
// Kamera, Datum und den zentralen PathResolver zusammengesetzt.
// ---------------------------------------------------------------------------
if ($dir === null) {
  if ($slug === null || !$year || !$month || !$day) {
    fwrite(STDERR, "Missing required args. Use --help for usage.\n");
    exit(1);
  }
  $monthStr = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
  $dayStr = str_pad((string) $day, 2, '0', STR_PAD_LEFT);
  if ($root === 'cache') {
    $dir = $app->paths()->cachePath($slug . '/' . $year . '/' . $monthStr . '/' . $dayStr);
  } elseif ($root === 'storage') {
    $dir = $app->paths()->storagePath($slug . '/' . $year . '/' . $monthStr . '/' . $dayStr);
  } else {
    $root = 'archive';
    $dir = $app->paths()->archivePath($slug . '/' . $year . '/' . $monthStr . '/' . $dayStr);
  }
}

$dir = rtrim($dir, DIRECTORY_SEPARATOR);
if (!is_dir($dir)) {
  fwrite(STDERR, "Directory not found: {$dir}\n");
  exit(1);
}

ensureBinary('convert');
ensureBinary('tesseract');

$pattern = $dir . DIRECTORY_SEPARATOR . '*_' . $variant . '.jpg';
$files = glob($pattern) ?: [];
sort($files, SORT_STRING);
if (!$files) {
  fwrite(STDERR, "No files found for pattern: {$pattern}\n");
  exit(1);
}

// ---------------------------------------------------------------------------
// 3. Tageslauf vorbereiten.
// Die sortierte Dateiliste sorgt dafür, dass die Drucksprungprüfung immer den
// zeitlich vorherigen akzeptierten Messwert als Vergleich benutzt.
// ---------------------------------------------------------------------------
$logPath = $app->paths()->logPath('wx_ocr.log');
$processed = 0;
$errors = 0;
$noWeather = 0;
$guarded = 0;
$ocrFailed = 0;
$lastAccepted = null;

echo stampLine("Start dir={$dir} files=" . count($files)) . PHP_EOL;

// ---------------------------------------------------------------------------
// 4. Jedes Bild genau einer fachlichen Entscheidung zuführen:
//    gültige Wetterdatei, kein Wetter, Drucksprung oder endgültiger OCR-Fehler.
// Marker machen diese Entscheidung für spätere Läufe nachvollziehbar.
// ---------------------------------------------------------------------------
foreach ($files as $file) {
  $base = basename($file);
  if (!preg_match('/^(\d{4})_([a-z0-9]+)\.jpg$/i', $base, $m)) {
    continue;
  }
  $hhmm = $m[1];
  if ($timeFilter !== null && $hhmm !== $timeFilter) {
    continue;
  }
  $outFile = $dir . DIRECTORY_SEPARATOR . $hhmm . '_wx.txt';
  $noWeatherFile = $dir . DIRECTORY_SEPARATOR . $hhmm . '_weather.no';
  $pressureJumpFile = $dir . DIRECTORY_SEPARATOR . $hhmm . '_pressure_jump.no';
  $ocrFailureStateFile = $dir . DIRECTORY_SEPARATOR . $hhmm . '_ocr_failed.json';
  $ocrFailureFile = $dir . DIRECTORY_SEPARATOR . $hhmm . '_ocr_failed.no';

  // Bereits entschiedene Slots werden standardmäßig nicht erneut bearbeitet.
  // Eine vorhandene gültige Wetterdatei speist trotzdem den Druckvergleich des
  // folgenden Slots, damit ein Teillauf dieselbe Semantik wie ein Volllauf hat.
  if (!$overwrite && is_file($outFile)) {
    $existing = parseWxSidecar($outFile);
    if ($existing !== null) {
      $guard = checkPressureGuard($lastAccepted, $hhmm, $existing);
      if ($guard['ok'] && isset($existing['press'])) {
        $lastAccepted = [
          'hhmm' => $hhmm,
          'press' => (float) $existing['press'],
        ];
      }
    }
    if ($verbose) {
      echo stampLine("[SKIP] {$outFile} exists.") . PHP_EOL;
    }
    continue;
  }
  if (!$overwrite && is_file($noWeatherFile)) {
    if ($verbose) {
      echo stampLine("[SKIP] {$noWeatherFile} exists.") . PHP_EOL;
    }
    continue;
  }
  if (!$overwrite && is_file($pressureJumpFile)) {
    if ($verbose) {
      echo stampLine("[SKIP] {$pressureJumpFile} exists.") . PHP_EOL;
    }
    continue;
  }
  if (!$overwrite && is_file($ocrFailureFile)) {
    if ($verbose) {
      echo stampLine("[SKIP] {$ocrFailureFile} exists.") . PHP_EOL;
    }
    continue;
  }

  // Zuerst die konfigurierte Bildvariante lesen. Nur wenn kein eindeutiges
  // Ergebnis und kein Timeout/„kein Wetter“-Befund vorliegt, wird eine größere
  // Fallback-Variante versucht.
  $attempt = extractWxFromImage($file, $verbose, $precision, $rescue, $ocrTimeoutSeconds, $convertTimeoutSeconds, $imageTimeoutSeconds);
  $result = $attempt['result'];
  $analysis = $attempt['analysis'];
  if (
    $result === null
    && !$analysis['definitive_no_weather']
    && empty($analysis['ocr_timeout_detected'])
    && ($precision || $rescue)
  ) {
    $fallbackVariants = [];
    if ($fallbackVariant !== null) {
      $fallbackVariants[] = $fallbackVariant;
    }
    if ($variant !== 'la' && !in_array('la', $fallbackVariants, true)) {
      $fallbackVariants[] = 'la';
    }
    foreach ($fallbackVariants as $fallback) {
      $fallbackFile = preg_replace('/_' . preg_quote($variant, '/') . '\\.jpg$/', '_' . $fallback . '.jpg', $file);
      if (!$fallbackFile || $fallbackFile === $file || !is_file($fallbackFile)) {
        continue;
      }
      $fallbackAttempt = extractWxFromImage($fallbackFile, $verbose, $precision, $rescue, $ocrTimeoutSeconds, $convertTimeoutSeconds, $imageTimeoutSeconds);
      $analysis = mergeOcrAnalysis($analysis, $fallbackAttempt['analysis']);
      $result = $fallbackAttempt['result'];
      if ($result !== null) {
        $result['line'] = $result['line'] . ' (fallback:' . $fallback . ')';
        break;
      }
    }
  }
  // Kein Messwert ist nicht immer derselbe Fehler:
  // - erkannter Zeitstempel ohne Wetter => dauerhafter weather.no-Marker
  // - unlesbarer Text/Timeout => gezählter Retry, später ocr_failed.no
  if ($result === null) {
    if (shouldTreatAsNoWeather($analysis)) {
      $noWeather++;
      if ($dryRun) {
        echo stampLine("[DRY] {$noWeatherFile} <= NO_WEATHER") . PHP_EOL;
      } else {
        $markerWritten = webcam_atomic_write_file($noWeatherFile, "NO_WEATHER\n");
        if (!$markerWritten) {
          $errors++;
          logLine($logPath, "ERROR file={$file} message=no_weather_marker_write_failed out={$noWeatherFile}");
          fwrite(STDERR, stampLine("[ERR] Failed to write {$noWeatherFile}") . PHP_EOL);
        } else {
          removeMarkerIfExists($pressureJumpFile);
          removeOcrFailureMarkers($ocrFailureStateFile, $ocrFailureFile);
        }
      }
      logLine(
        $logPath,
        'MISS file=' . $file
          . ' message=no_weather_data'
          . ' out=' . $noWeatherFile
          . ' sample="' . sanitizeLogSnippet($analysis['sample']) . '"'
      );
      if ($verbose) {
        echo stampLine("[MISS] {$file}: timestamp found, no weather data.") . PHP_EOL;
      }
    } else {
      $errors++;
      $failure = recordOcrParseFailure(
        $ocrFailureStateFile,
        $ocrFailureFile,
        $file,
        $analysis['sample'],
        $maxOcrFailures,
        $dryRun
      );
      if (!$failure['ok']) {
        logLine($logPath, "ERROR file={$file} message=ocr_failure_marker_write_failed out={$failure['failed_path']}");
        fwrite(STDERR, stampLine("[ERR] Failed to update OCR failure marker for {$file}") . PHP_EOL);
      }
      if ($failure['final'] && $failure['ok']) {
        $ocrFailed++;
      }
      logLine(
        $logPath,
        'ERROR file=' . $file
          . ' message=' . (!empty($analysis['ocr_timeout_detected']) ? 'ocr_timeout' : 'ocr_parse_failed')
          . ' attempts=' . $failure['attempts']
          . ' max_attempts=' . $maxOcrFailures
          . ($failure['final'] ? ' out=' . $ocrFailureFile : ' retry_state=' . $ocrFailureStateFile)
          . ' sample="' . sanitizeLogSnippet($analysis['sample']) . '"'
      );
      if ($verbose) {
        if ($failure['final']) {
          echo stampLine("[ERR] {$file}: OCR parse failed; final marker written after {$failure['attempts']} attempts.") . PHP_EOL;
        } elseif ($maxOcrFailures === 0) {
          echo stampLine("[ERR] {$file}: OCR parse failed; final marker disabled.") . PHP_EOL;
        } else {
          echo stampLine("[ERR] {$file}: OCR parse failed; attempt {$failure['attempts']}/{$maxOcrFailures}.") . PHP_EOL;
        }
      }
    }
  } else {
    // Erkannte Zahlen müssen zuerst innerhalb absolut realistischer Grenzen
    // liegen. Ein offensichtlich falscher OCR-Wert wird niemals als Wetterwert
    // veröffentlicht.
    $plausibility = checkAbsolutePlausibility($result);
    if (!$plausibility['ok']) {
      $guarded++;
      if ($dryRun) {
        echo stampLine("[DRY] {$ocrFailureFile} <= OUT_OF_RANGE") . PHP_EOL;
      } else {
        $markerText = implode("\n", [
          'OCR_FAILED',
          'reason=out_of_range',
          'file=' . $file,
          'temp=' . $result['temp'],
          'press=' . ($result['press'] ?? 'n/a'),
          'temp_allowed=' . $plausibility['temp_allowed'],
          'press_allowed=' . $plausibility['press_allowed'],
          '',
        ]);
        $markerWritten = webcam_atomic_write_file($ocrFailureFile, $markerText);
        if (!$markerWritten) {
          $errors++;
          logLine($logPath, "ERROR file={$file} message=ocr_failed_marker_write_failed out={$ocrFailureFile}");
          fwrite(STDERR, stampLine("[ERR] Failed to write {$ocrFailureFile}") . PHP_EOL);
        } else {
          $ocrFailed++;
          removeMarkerIfExists($ocrFailureStateFile);
        }
      }
      logLine(
        $logPath,
        'MISS file=' . $file
          . ' message=out_of_range'
          . ' out=' . $ocrFailureFile
          . ' temp=' . $result['temp']
          . ' press=' . ($result['press'] ?? 'n/a')
          . ' temp_allowed=' . $plausibility['temp_allowed']
          . ' press_allowed=' . $plausibility['press_allowed']
          . ' sample="' . sanitizeLogSnippet($analysis['sample']) . '"'
      );
      if ($verbose) {
        echo stampLine(
          "[MISS] {$file}: out of range temp={$result['temp']} press=" . ($result['press'] ?? 'n/a') . "."
        ) . PHP_EOL;
      }
      $processed++;
      if ($limit !== null && $processed >= $limit) {
        break;
      }
      continue;
    }

    // Danach folgt die zeitabhängige Plausibilität: Ein großer Drucksprung in
    // wenigen Minuten ist typischerweise ein Lesefehler und erhält einen
    // eigenen, später überprüfbaren Marker.
    $guard = checkPressureGuard($lastAccepted, $hhmm, $result);
    if (!$guard['ok']) {
      $guarded++;
      if ($dryRun) {
        echo stampLine("[DRY] {$pressureJumpFile} <= PRESSURE_JUMP") . PHP_EOL;
      } else {
        $markerText = implode("\n", [
          'PRESSURE_JUMP',
          'file=' . $file,
          'prev_hhmm=' . $guard['prev_hhmm'],
          'prev_press=' . $guard['prev_press'],
          'press=' . ($result['press'] ?? 'n/a'),
          'delta=' . $guard['delta'],
          'allowed=' . $guard['allowed'],
          '',
        ]);
        $markerWritten = webcam_atomic_write_file($pressureJumpFile, $markerText);
        if (!$markerWritten) {
          $errors++;
          logLine($logPath, "ERROR file={$file} message=pressure_jump_marker_write_failed out={$pressureJumpFile}");
          fwrite(STDERR, stampLine("[ERR] Failed to write {$pressureJumpFile}") . PHP_EOL);
        } else {
          removeOcrFailureMarkers($ocrFailureStateFile, $ocrFailureFile);
        }
      }
      logLine(
        $logPath,
        'MISS file=' . $file
          . ' message=pressure_jump'
          . ' out=' . $pressureJumpFile
          . ' prev_hhmm=' . $guard['prev_hhmm']
          . ' prev_press=' . $guard['prev_press']
          . ' press=' . ($result['press'] ?? 'n/a')
          . ' delta=' . $guard['delta']
          . ' allowed=' . $guard['allowed']
          . ' sample="' . sanitizeLogSnippet($analysis['sample']) . '"'
      );
      if ($verbose) {
        echo stampLine(
          "[MISS] {$file}: pressure jump {$guard['delta']} mBar exceeds {$guard['allowed']} mBar."
        ) . PHP_EOL;
      }
      $processed++;
      if ($limit !== null && $processed >= $limit) {
        break;
      }
      continue;
    }

    // Erst nach beiden Prüfungen wird die kompatible Text-Sidecar atomar
    // geschrieben und der Messwert als Referenz für den nächsten Slot gesetzt.
    $line = isset($result['press'], $result['wind'])
      ? sprintf(
        'Temp: %s°C  Press: %s mBar  Winddir: %s',
        $result['temp'],
        $result['press'],
        $result['wind']
      )
      : (string) ($result['line'] ?? ('Temp: ' . $result['temp'] . ' °C'));
    if ($dryRun) {
      echo stampLine("[DRY] {$outFile} <= {$line}") . PHP_EOL;
      if (isset($result['press'])) {
        $lastAccepted = [
          'hhmm' => $hhmm,
          'press' => (float) $result['press'],
        ];
      }
    } else {
      $writeOk = webcam_atomic_write_file($outFile, $line . PHP_EOL);
      if (!$writeOk) {
        $errors++;
        logLine($logPath, "ERROR file={$file} message=write_failed out={$outFile}");
        fwrite(STDERR, stampLine("[ERR] Failed to write {$outFile}") . PHP_EOL);
      } else {
        removeMarkerIfExists($pressureJumpFile);
        removeOcrFailureMarkers($ocrFailureStateFile, $ocrFailureFile);
        if (isset($result['press'])) {
          $lastAccepted = [
            'hhmm' => $hhmm,
            'press' => (float) $result['press'],
          ];
        }
        logLine($logPath, "OK file={$file} out={$outFile} line=\"" . $line . "\"");
        if ($verbose) {
          echo stampLine("[OK] {$outFile}") . PHP_EOL;
        }
      }
    }
  }

  $processed++;
  if ($limit !== null && $processed >= $limit) {
    break;
  }
}

// Der Abschlusszähler unterscheidet technische Fehler, echte Bilder ohne
// Wetter, Plausibilitätsblockaden und endgültige OCR-Fehler.
echo stampLine(
  "Done. processed={$processed} errors={$errors} no_weather={$noWeather} guarded={$guarded} ocr_failed={$ocrFailed} dir={$dir}"
) . PHP_EOL;

// ---------------------------------------------------------------------------
// OCR- und Prozess-Helfer.
// ---------------------------------------------------------------------------

function ensureBinary(string $name): void
{
  $cmd = 'command -v ' . escapeshellarg($name) . ' 2>/dev/null';
  $path = trim((string) shell_exec($cmd));
  if ($path === '') {
    fwrite(STDERR, "Missing required binary: {$name}\n");
    exit(1);
  }
}

/**
 * Versucht, Wetterdaten aus genau einem Bild zu lesen.
 *
 * Schnelle, eng zugeschnittene OCR-Pässe laufen zuerst. Präzisions- und
 * Rescue-Pässe vergrößern den Bildausschnitt und kosten mehr CPU. Alle Pässe
 * teilen ein Zeitbudget und sammeln Kandidaten; zurückgegeben wird der am
 * besten bewertete plausible Text oder eine Analyse des Fehlschlags.
 *
 * @return array{result:?array<string,mixed>,analysis:array<string,mixed>}
 */
function extractWxFromImage(
  string $file,
  bool $verbose,
  bool $precision,
  bool $rescue,
  int $ocrTimeoutSeconds,
  int $convertTimeoutSeconds,
  int $imageTimeoutSeconds
): array
{
  // Bildabmessungen zuerst billig prüfen. Extreme oder ungültige Bilder gehen
  // nicht an ImageMagick/Tesseract weiter.
  $info = @getimagesize($file);
  if (!$info) {
    return [
      'result' => null,
      'analysis' => newOcrAnalysis(),
    ];
  }
  $width = (int) $info[0];
  $height = (int) $info[1];
  if ($width > 32000 || $height > 32000) {
    return [
      'result' => null,
      'analysis' => newOcrAnalysis(),
    ];
  }
  // Mehrere Ausschnitte decken die historisch unterschiedlichen Positionen
  // der Kameraeinblendung ab. Mindest-/Maximalwerte verhindern winzige oder
  // unverhältnismäßig große OCR-Arbeitsbilder.
  $cropHeight = (int) round($height * 0.2);
  if ($cropHeight < 60) {
    $cropHeight = 60;
  } elseif ($cropHeight > 140) {
    $cropHeight = 140;
  }
  $cropHeightTall = (int) round($height * 0.32);
  if ($cropHeightTall < 80) {
    $cropHeightTall = 80;
  } elseif ($cropHeightTall > 220) {
    $cropHeightTall = 220;
  }
  $cropHeightWide = (int) round($height * 0.35);
  if ($cropHeightWide < 90) {
    $cropHeightWide = 90;
  } elseif ($cropHeightWide > 240) {
    $cropHeightWide = 240;
  }
  $rightCropWidth = (int) round($width * 0.6);
  if ($rightCropWidth < 200) {
    $rightCropWidth = $width;
  }
  $rightCropX = max(0, $width - $rightCropWidth);
  $leftCropWidth = (int) round($width * 0.4);
  if ($leftCropWidth < 200) {
    $leftCropWidth = $width;
  }
  $leftCropWidthWide = (int) round($width * 0.7);
  if ($leftCropWidthWide < 320) {
    $leftCropWidthWide = min($width, 320);
  }
  if ($leftCropWidthWide > $width) {
    $leftCropWidthWide = $width;
  }
  $lineCropWidth = $width;
  $lineCropHeight = (int) round($height * 0.074);
  if ($lineCropHeight < 24) {
    $lineCropHeight = min($height, 24);
  } elseif ($lineCropHeight > 52) {
    $lineCropHeight = 52;
  }
  $lineCropY = (int) round($height * 0.039);
  if ($lineCropY < 8) {
    $lineCropY = min(max(0, $height - $lineCropHeight), 8);
  }
  if ($lineCropY + $lineCropHeight > $height) {
    $lineCropY = max(0, $height - $lineCropHeight);
  }

  $tmpBase = tempnam(sys_get_temp_dir(), 'wxocr_');
  if ($tmpBase === false) {
    return [
      'result' => null,
      'analysis' => newOcrAnalysis(),
    ];
  }
  $tmpPng = $tmpBase . '.png';
  @unlink($tmpBase);

  // Ein gemeinsames Zeitbudget gilt für das gesamte Quellbild, nicht erneut
  // für jeden Pass. `tryPass` führt Vorbereitung, OCR, Analyse und Bewertung
  // immer in derselben Reihenfolge aus.
  $candidates = [];
  $analysis = newOcrAnalysis();
  $allowLoose = $precision;
  $imageDeadline = microtime(true) + $imageTimeoutSeconds;
  $tryPass = static function (
    string $file,
    string $tmpPng,
    int $cropWidth,
    int $cropHeight,
    int $cropX,
    int $cropY,
    string $ops,
    bool $verbose,
    bool $useWhitelist,
    int $psm,
    bool $loose,
    bool $precision,
    bool $rescue,
    array &$candidates,
    array &$analysis
  ) use ($ocrTimeoutSeconds, $convertTimeoutSeconds, $imageTimeoutSeconds, $imageDeadline): ?array {
    if (!empty($analysis['ocr_timeout_detected'])) {
      return null;
    }
    if (microtime(true) >= $imageDeadline) {
      markOcrImageTimeout($analysis, $imageTimeoutSeconds);
      return null;
    }
    $attempt = runOcrPassDetailed(
      $file,
      $tmpPng,
      $cropWidth,
      $cropHeight,
      $cropX,
      $cropY,
      $ops,
      $verbose,
      $useWhitelist,
      $psm,
      $loose,
      $ocrTimeoutSeconds,
      $convertTimeoutSeconds
    );
    $analysis = mergeOcrAnalysis($analysis, $attempt['analysis']);
    $parsed = $attempt['parsed'];
    if (microtime(true) >= $imageDeadline && $parsed === null) {
      markOcrImageTimeout($analysis, $imageTimeoutSeconds);
    }
    if ($parsed === null) {
      return null;
    }
    $candidates[] = $parsed;
    if ((!$precision && !$rescue) || $parsed['score'] >= 5) {
      return $parsed;
    }
    return null;
  };

  // Schneller Normalfall: nur die typische einzelne Einblendungszeile lesen.
  // Ein ausreichend guter Treffer beendet die teurere Pipeline sofort.
  $early = $tryPass(
    $file,
    $tmpPng,
    $lineCropWidth,
    $lineCropHeight,
    0,
    $lineCropY,
    'Gray -auto-level -resize 250%',
    $verbose,
    true,
    7,
    true,
    $precision,
    $rescue,
    $candidates,
    $analysis
  );
  if ($early !== null) {
    return [
      'result' => $early,
      'analysis' => $analysis,
    ];
  }

  if ($analysis['expected_timestamp_detected'] && !$analysis['weather_hint_detected']) {
    $analysis['definitive_no_weather'] = true;
    return [
      'result' => null,
      'analysis' => $analysis,
    ];
  }

  if (shouldTreatAsNoWeather($analysis)) {
    $analysis['definitive_no_weather'] = true;
    return [
      'result' => null,
      'analysis' => $analysis,
    ];
  }

  $early = $tryPass(
    $file,
    $tmpPng,
    $lineCropWidth,
    $lineCropHeight,
    0,
    $lineCropY,
    'Gray -contrast-stretch 0x15% -resize 300% -unsharp 0x1',
    $verbose,
    false,
    7,
    true,
    $precision,
    $rescue,
    $candidates,
    $analysis
  );
  if ($early !== null) {
    return [
      'result' => $early,
      'analysis' => $analysis,
    ];
  }

  if ($analysis['timestamp_detected'] && !$analysis['weather_hint_detected']) {
    $analysis['definitive_no_weather'] = true;
    return [
      'result' => null,
      'analysis' => $analysis,
    ];
  }

  if (!$precision && !$rescue) {
    return [
      'result' => $candidates[0] ?? null,
      'analysis' => $analysis,
    ];
  }

  $early = $tryPass(
    $file,
    $tmpPng,
    $width,
    $cropHeight,
    0,
    0,
    'Gray -auto-level -resize 300%',
    $verbose,
    true,
    6,
    $allowLoose,
    $precision,
    $rescue,
    $candidates,
    $analysis
  );
  if ($early !== null) {
    return [
      'result' => $early,
      'analysis' => $analysis,
    ];
  }

  $early = $tryPass(
    $file,
    $tmpPng,
    $width,
    $cropHeight,
    0,
    0,
    'Gray -auto-level -resize 400% -unsharp 0x1',
    $verbose,
    true,
    6,
    $allowLoose,
    $precision,
    $rescue,
    $candidates,
    $analysis
  );
  if ($early !== null) {
    return [
      'result' => $early,
      'analysis' => $analysis,
    ];
  }

  $early = $tryPass(
    $file,
    $tmpPng,
    $width,
    $cropHeight,
    0,
    0,
    'Gray -auto-level -resize 300%',
    $verbose,
    false,
    6,
    $allowLoose,
    $precision,
    $rescue,
    $candidates,
    $analysis
  );
  if ($early !== null) {
    return [
      'result' => $early,
      'analysis' => $analysis,
    ];
  }

  $early = $tryPass(
    $file,
    $tmpPng,
    $width,
    $cropHeight,
    0,
    0,
    'Gray -negate -threshold 60% -resize 300%',
    $verbose,
    true,
    6,
    $allowLoose,
    $precision,
    $rescue,
    $candidates,
    $analysis
  );
  if ($early !== null) {
    return [
      'result' => $early,
      'analysis' => $analysis,
    ];
  }

  // Präzisionsmodus probiert größere linke/rechte Ausschnitte und verschiedene
  // Kontrastbehandlungen. Das ist langsamer, rettet aber schwache Altbilder.
  if ($precision) {
    $early = $tryPass(
      $file,
      $tmpPng,
      $leftCropWidthWide,
      $cropHeightWide,
      0,
      0,
      'Gray -auto-level -resize 800% -unsharp 0x1',
      $verbose,
      true,
      6,
      true,
      $precision,
      $rescue,
      $candidates,
      $analysis
    );
    if ($early !== null) {
      return [
        'result' => $early,
        'analysis' => $analysis,
      ];
    }

    $early = $tryPass(
      $file,
      $tmpPng,
      $leftCropWidthWide,
      $cropHeightWide,
      0,
      0,
      'Gray -contrast-stretch 0x20% -resize 800% -unsharp 1x1',
      $verbose,
      true,
      6,
      true,
      $precision,
      $rescue,
      $candidates,
      $analysis
    );
    if ($early !== null) {
      return [
        'result' => $early,
        'analysis' => $analysis,
      ];
    }

    $early = $tryPass(
      $file,
      $tmpPng,
      $leftCropWidth,
      $cropHeightTall,
      0,
      0,
      'Gray -auto-level -resize 800% -unsharp 0x1',
      $verbose,
      true,
      7,
      true,
      $precision,
      $rescue,
      $candidates,
      $analysis
    );
    if ($early !== null) {
      return [
        'result' => $early,
        'analysis' => $analysis,
      ];
    }

    $early = $tryPass(
      $file,
      $tmpPng,
      $leftCropWidth,
      $cropHeightTall,
      0,
      0,
      'Gray -contrast-stretch 0x20% -resize 800% -unsharp 1x1',
      $verbose,
      true,
      7,
      true,
      $precision,
      $rescue,
      $candidates,
      $analysis
    );
    if ($early !== null) {
      return [
        'result' => $early,
        'analysis' => $analysis,
      ];
    }

    $early = $tryPass(
      $file,
      $tmpPng,
      $rightCropWidth,
      $cropHeightTall,
      $rightCropX,
      0,
      'Gray -auto-level -resize 600% -unsharp 0x1',
      $verbose,
      true,
      7,
      true,
      $precision,
      $rescue,
      $candidates,
      $analysis
    );
    if ($early !== null) {
      return [
        'result' => $early,
        'analysis' => $analysis,
      ];
    }

    $early = $tryPass(
      $file,
      $tmpPng,
      $rightCropWidth,
      $cropHeightTall,
      $rightCropX,
      0,
      'Gray -contrast-stretch 0x20% -resize 600% -unsharp 1x1',
      $verbose,
      true,
      7,
      true,
      $precision,
      $rescue,
      $candidates,
      $analysis
    );
    if ($early !== null) {
      return [
        'result' => $early,
        'analysis' => $analysis,
      ];
    }

    $early = $tryPass(
      $file,
      $tmpPng,
      $width,
      $cropHeightTall,
      0,
      0,
      'Gray -threshold 55% -resize 400%',
      $verbose,
      true,
      6,
      true,
      $precision,
      $rescue,
      $candidates,
      $analysis
    );
    if ($early !== null) {
      return [
        'result' => $early,
        'analysis' => $analysis,
      ];
    }

    $early = $tryPass(
      $file,
      $tmpPng,
      $rightCropWidth,
      $cropHeightTall,
      $rightCropX,
      0,
      'Gray -negate -threshold 55% -resize 500% -unsharp 0x1',
      $verbose,
      false,
      7,
      true,
      $precision,
      $rescue,
      $candidates,
      $analysis
    );
    if ($early !== null) {
      return [
        'result' => $early,
        'analysis' => $analysis,
      ];
    }
  }

  // Rescue ist bewusst optional: diese sehr großen Skalierungen sind nur für
  // gezielte historische Problemfälle gedacht und nicht für jeden Tageslauf.
  if ($rescue) {
    $early = $tryPass(
      $file,
      $tmpPng,
      $rightCropWidth,
      $cropHeightTall,
      $rightCropX,
      0,
      'Gray -normalize -resize 800% -unsharp 1x1',
      $verbose,
      false,
      7,
      true,
      $precision,
      $rescue,
      $candidates,
      $analysis
    );
    if ($early !== null) {
      return [
        'result' => $early,
        'analysis' => $analysis,
      ];
    }

    $early = $tryPass(
      $file,
      $tmpPng,
      $rightCropWidth,
      $cropHeightTall,
      $rightCropX,
      0,
      'Gray -level 20%,80% -resize 900% -sharpen 0x1',
      $verbose,
      true,
      7,
      true,
      $precision,
      $rescue,
      $candidates,
      $analysis
    );
    if ($early !== null) {
      return [
        'result' => $early,
        'analysis' => $analysis,
      ];
    }

    $early = $tryPass(
      $file,
      $tmpPng,
      $width,
      $cropHeightTall,
      0,
      0,
      'Gray -negate -threshold 50% -resize 800% -unsharp 1x1',
      $verbose,
      false,
      11,
      true,
      $precision,
      $rescue,
      $candidates,
      $analysis
    );
    if ($early !== null) {
      return [
        'result' => $early,
        'analysis' => $analysis,
      ];
    }
  }

  // Wenn kein Pass sofort sicher genug war, gewinnt der Kandidat mit dem
  // höchsten Inhalts-Score; bei Gleichstand der mit der vollständigeren Zeile.
  if (!$candidates) {
    return [
      'result' => null,
      'analysis' => $analysis,
    ];
  }
  usort($candidates, function (array $a, array $b): int {
    if ($a['score'] === $b['score']) {
      return $b['line_len'] <=> $a['line_len'];
    }
    return $b['score'] <=> $a['score'];
  });
  return [
    'result' => $candidates[0],
    'analysis' => $analysis,
  ];
}

function runTesseract(string $file, bool $useWhitelist, int $psm, int $timeoutSeconds): array
{
  $cmd = ['tesseract', $file, 'stdout', '-l', 'eng', '--psm', (string) $psm];
  if ($useWhitelist) {
    $whitelist = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz.:°/\\- ';
    $cmd[] = '-c';
    $cmd[] = 'tessedit_char_whitelist=' . $whitelist;
  }

  return runProcessWithTimeout($cmd, $timeoutSeconds);
}

function runProcessWithTimeout(array $cmd, int $timeoutSeconds): array
{
  $result = (new BoundedProcessRunner())->run($cmd, $timeoutSeconds, [
    // OCR-Text und Tooldiagnose bleiben getrennt und hart begrenzt. Das Ende
    // der Ausgabe enthält bei ImageMagick/Tesseract meist die Fehlerursache.
    'output_max_bytes' => 1048576,
    'output_retention' => 'tail',
    'require_process_group' => true,
  ]);
  if (!$result['started']) {
    return [
      'text' => '',
      'timed_out' => false,
      'exit_code' => 127,
      'stderr' => $result['stderr'],
    ];
  }

  return [
    'text' => $result['stdout'],
    'stderr' => $result['stderr'],
    'timed_out' => $result['timed_out'],
    'exit_code' => $result['exit_code'],
  ];
}

// ---------------------------------------------------------------------------
// Textnormalisierung und Parser.
// Diese Funktionen übersetzen fehleranfälligen OCR-Text in ein kleines Modell
// aus Temperatur, Luftdruck und Windrichtung. Sie schreiben keine Dateien.
// ---------------------------------------------------------------------------
function splitCommandArgs(string $args): array
{
  $parts = preg_split('/\s+/', trim($args));
  if ($parts === false) {
    return [];
  }
  return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
}

function normalizeWindToken(string $token): ?string
{
  $token = strtoupper($token);
  $token = str_replace('VV', 'W', $token);
  $token = str_replace(['V', 'M'], 'W', $token);
  $token = str_replace('0', 'O', $token);
  $token = preg_replace('/[^A-Z]/', '', $token) ?? $token;
  if ($token === '') {
    return null;
  }
  if ($token === 'O' || $token === 'OST') {
    return 'E';
  }
  if (preg_match('/^[NSWO]{1,3}$/', $token)) {
    $token = str_replace('O', 'E', $token);
  }
  $valid = [
    'NNW', 'NNE', 'ENE', 'ESE', 'SSE', 'SSW', 'WSW', 'WNW',
    'NW', 'NE', 'SE', 'SW',
    'N', 'E', 'S', 'W',
  ];
  if (in_array($token, $valid, true)) {
    return $token;
  }
  foreach ($valid as $dir) {
    if (strpos($token, $dir) !== false) {
      return $dir;
    }
  }
  $token = preg_replace('/[^NSEW]/', '', $token) ?? $token;
  if ($token === '') {
    return null;
  }
  if (strlen($token) > 3) {
    $token = substr($token, -3);
  }
  return preg_match('/^[NSEW]{1,3}$/', $token) ? $token : null;
}

function parseWxText(string $text): ?array
{
  $text = normalizeMinusChars($text);
  $lines = preg_split('/\r?\n/', $text) ?: [];
  $stromberg = parseStrombergWxText($lines);
  if ($stromberg !== null) {
    return $stromberg;
  }

  $best = null;
  foreach ($lines as $line) {
    $line = trim(preg_replace('/\s+/', ' ', $line));
    if ($line === '') {
      continue;
    }
    if (stripos($line, 'mbar') !== false && preg_match('/\d/', $line)) {
      $best = $line;
      break;
    }
  }
  if ($best === null) {
    return null;
  }

  $temp = null;
  $press = null;
  $wind = null;

  if (preg_match('/(-?\d+(?:[.,]\d+)?)\s*°?\s*C/i', $best, $m)) {
    $temp = normalizeNumber($m[1]);
  }
  if (preg_match('/(\d{3,4}(?:[.,]\d+)?)\s*mbar/i', $best, $m)) {
    $press = normalizeNumber($m[1]);
  }
  if (preg_match('/mbar\s*([A-Z0-9]{1,4})/i', $best, $m)) {
    $wind = normalizeWindToken($m[1]);
  }
  if ($wind === null && preg_match_all('/[A-Z]{1,4}/', strtoupper($best), $m)) {
    for ($i = count($m[0]) - 1; $i >= 0; $i--) {
      $candidate = normalizeWindToken($m[0][$i]);
      if ($candidate !== null) {
        $wind = $candidate;
        break;
      }
    }
  }

  if ($temp === null || $press === null || $wind === null) {
    return null;
  }

  $score = 0;
  $score += 1; // temp
  $score += 1; // press
  $score += 1; // wind
  if (stripos($best, 'mbar') !== false) {
    $score += 1;
  }
  if (strpos($best, '°') !== false || stripos($best, 'C') !== false) {
    $score += 1;
  }
  if (preg_match('/\d{2}\.\d{2}\.\d{2}/', $best)) {
    $score += 1;
  }
  if (preg_match('/\d{2}:\d{2}/', $best)) {
    $score += 1;
  }

  return [
    'temp' => $temp,
    'press' => $press,
    'wind' => $wind,
    'line' => $best,
    'score' => $score,
    'line_len' => strlen($best),
  ];
}

/**
 * Parse Stromberg-style overlay lines, e.g.
 * "Temperatur: 15.5 °C, Feuchtigkeit: 55 %, Helligkeit: 0, Regen: 0.59 mm/qm, Wind: 32.5 km/h, Richtung: S".
 * Also accepts the later compact form:
 * "25.10.25 05:30 Temp: 10.4 °C, Hum: 75%, DewPt: 6.17 °C, Wind: 29 km/h O".
 */
function parseStrombergWxText(array $lines): ?array
{
  $best = null;
  foreach ($lines as $line) {
    $line = trim(preg_replace('/\s+/', ' ', normalizeMinusChars((string) $line)) ?? '');
    if ($line === '') {
      continue;
    }
    $upper = strtoupper($line);
    if (
      str_contains($upper, 'TEMPERATUR')
      || str_contains($upper, 'FEUCHTIGKEIT')
      || str_contains($upper, 'HELLIGKEIT')
      || str_contains($upper, 'REGEN')
      || str_contains($upper, 'TEMP')
      || str_contains($upper, 'HUM')
      || str_contains($upper, 'DEWPT')
      || str_contains($upper, 'WIND')
    ) {
      $best = $line;
      break;
    }
  }
  if ($best === null) {
    return null;
  }

  $temp = extractFirstLabeledNumber($best, ['Temperatur', 'Temp']);
  if ($temp === null) {
    $temp = extractStrombergUnlabeledTemperature($best);
  }
  if ($temp === null) {
    return null;
  }

  $humidity = extractFirstLabeledNumber($best, ['Feuchtigkeit', 'Hum']);
  $dewPoint = extractFirstLabeledNumber($best, ['DewPt', 'Dew Point', 'Taupunkt']);
  $brightness = extractLabeledNumber($best, 'Helligkeit');
  $rain = extractLabeledNumber($best, 'Regen');
  $windSpeed = extractLabeledNumber($best, 'Wind');
  $windDir = null;
  if (preg_match('/Richtung\s*:?\s*([A-Z]{1,4})/i', $best, $m)) {
    $windDir = normalizeWindToken($m[1]);
  }
  if (
    $windDir === null
    && preg_match('/\bWind\s*:?\s*-?\d+(?:[.,]\d+)?\s*km\s*\/?\s*h\s*([A-Z0-9]{1,4})?/i', $best, $m)
  ) {
    $windDir = normalizeWindToken($m[1] ?? '');
  }

  $parts = ['Temp: ' . $temp . ' °C'];
  $score = 1;
  if ($humidity !== null) {
    $parts[] = 'Hum: ' . $humidity . ' %';
    $score++;
  }
  if ($dewPoint !== null) {
    $parts[] = 'DewPt: ' . $dewPoint . ' °C';
    $score++;
  }
  if ($brightness !== null) {
    $parts[] = 'Brightness: ' . $brightness;
    $score++;
  }
  if ($rain !== null) {
    $parts[] = 'Rain: ' . $rain . ' mm/qm';
    $score++;
  }
  if ($windSpeed !== null) {
    $windPart = 'Wind: ' . $windSpeed . ' km/h';
    if ($windDir !== null) {
      $windPart .= ' ' . $windDir;
      $parts[] = 'Windrichtung: ' . $windDir;
      $score++;
    }
    if ($windDir === null) {
      $parts[] = $windPart;
    } else {
      array_splice($parts, count($parts) - 1, 0, [$windPart]);
    }
    $score++;
  }

  if (count($parts) < 2) {
    return null;
  }

  return [
    'temp' => $temp,
    'line' => implode(', ', $parts),
    'score' => $score,
    'line_len' => strlen($best),
  ];
}

function extractStrombergUnlabeledTemperature(string $text): ?string
{
  if (!preg_match_all('/(-?\d+(?:[.,]\d+)?)\s*°?\s*C\b/i', $text, $matches, PREG_OFFSET_CAPTURE)) {
    return null;
  }

  foreach ($matches[1] as $index => $match) {
    $wholeOffset = (int) ($matches[0][$index][1] ?? $match[1]);
    $prefix = substr($text, max(0, $wholeOffset - 32), 32);
    if (preg_match('/(?:dew\s*pt|dewpt|dew\s*point|taupunkt)\s*:?\s*$/i', $prefix)) {
      continue;
    }

    $value = normalizeNumber((string) $match[0]);
    if ($value === '' || !is_numeric($value)) {
      continue;
    }
    $floatValue = (float) $value;
    if ($floatValue < -35.0 || $floatValue > 45.0) {
      continue;
    }
    return $value;
  }

  return null;
}

function extractLabeledNumber(string $text, string $label): ?string
{
  if (!preg_match('/' . preg_quote($label, '/') . '\s*:?\s*(-?\d+(?:[.,]\d+)?)/i', $text, $m)) {
    return null;
  }
  return normalizeNumber($m[1]);
}

function extractFirstLabeledNumber(string $text, array $labels): ?string
{
  foreach ($labels as $label) {
    $value = extractLabeledNumber($text, (string) $label);
    if ($value !== null) {
      return $value;
    }
  }
  return null;
}

function parseWxTextLoose(string $text): ?array
{
  $text = normalizeMinusChars($text);
  $text = str_replace(',', '.', $text);
  $clean = strtoupper($text);
  $clean = preg_replace('/\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}/', ' ', $clean) ?? $clean;
  $clean = preg_replace('/\d{1,2}:\d{2}/', ' ', $clean) ?? $clean;
  $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

  $wind = null;
  if (preg_match_all('/[A-Z]{1,4}/', $clean, $winds) && !empty($winds[0])) {
    for ($i = count($winds[0]) - 1; $i >= 0; $i--) {
      $candidate = normalizeWindToken($winds[0][$i]);
      if ($candidate !== null) {
        $wind = $candidate;
        break;
      }
    }
  }

  $temp = null;
  $press = null;
  if (preg_match_all('/-?\d{1,4}(?:\.\d+)?/', $clean, $matches)) {
    $nums = $matches[0];
    foreach ($nums as $num) {
      if (strpos($num, '.') === false) {
        continue;
      }
      $val = (float) $num;
      if ($val >= 850 && $val <= 1100) {
        $press = normalizeNumber($num);
        break;
      }
    }
    foreach ($nums as $num) {
      if (strpos($num, '.') === false) {
        continue;
      }
      $val = (float) $num;
      if ($val >= -50 && $val <= 60) {
        $temp = normalizeNumber($num);
        break;
      }
    }
    if ($press === null) {
      foreach ($nums as $num) {
        if (strpos($num, '.') !== false) {
          continue;
        }
        $val = (float) $num;
        if ($val >= 850 && $val <= 1100) {
          $press = normalizeNumber($num);
          break;
        }
      }
    }
    if ($temp === null) {
      foreach ($nums as $num) {
        if (strpos($num, '.') !== false) {
          continue;
        }
        $val = (float) $num;
        if ($val >= -50 && $val <= 60) {
          $temp = normalizeNumber($num);
          break;
        }
      }
    }
  }

  if ($temp === null || $press === null || $wind === null) {
    return null;
  }

  $score = 1;
  if (strpos($clean, 'MBAR') !== false) {
    $score += 1;
  }
  if (strpos($clean, 'C') !== false || strpos($clean, '°') !== false) {
    $score += 1;
  }
  return [
    'temp' => $temp,
    'press' => $press,
    'wind' => $wind,
    'line' => $clean,
    'score' => $score,
    'line_len' => strlen($clean),
  ];
}

function normalizeNumber(string $value): string
{
  $value = normalizeMinusChars($value);
  $value = str_replace(',', '.', $value);
  $value = preg_replace('/[^0-9.\-]/', '', $value) ?? $value;
  return trim($value);
}

// ---------------------------------------------------------------------------
// Log- und Markerzustand.
// Marker unterscheiden „kein Wetter“, Plausibilitätsproblem und wiederholt
// unlesbaren Text, damit spätere Läufe nur sinnvolle Kandidaten wiederholen.
// ---------------------------------------------------------------------------
function logLine(string $path, string $message): void
{
  $line = '[' . gmdate('c') . '] ' . $message . PHP_EOL;
  @file_put_contents($path, $line, FILE_APPEND);
}

function removeMarkerIfExists(string $path): void
{
  if (is_file($path)) {
    @unlink($path);
  }
}

function removeOcrFailureMarkers(string $statePath, string $finalPath): void
{
  removeMarkerIfExists($statePath);
  removeMarkerIfExists($finalPath);
}

function recordOcrParseFailure(
  string $statePath,
  string $finalPath,
  string $sourceFile,
  string $sample,
  int $maxFailures,
  bool $dryRun
): array {
  $previousAttempts = readOcrFailureAttempts($statePath);
  $attempts = $previousAttempts + 1;
  $final = $maxFailures > 0 && $attempts >= $maxFailures;

  if ($dryRun || $maxFailures === 0) {
    return [
      'attempts' => $attempts,
      'final' => false,
      'ok' => true,
      'failed_path' => '',
    ];
  }

  if ($final) {
    $marker = implode("\n", [
      'OCR_FAILED',
      'attempts=' . $attempts,
      'file=' . $sourceFile,
      'last_failed_at=' . gmdate('c'),
      'sample=' . sanitizeLogSnippet($sample),
      '',
    ]);
    $written = webcam_atomic_write_file($finalPath, $marker);
    if (!$written) {
      return [
        'attempts' => $attempts,
        'final' => true,
        'ok' => false,
        'failed_path' => $finalPath,
      ];
    }
    removeMarkerIfExists($statePath);
    return [
      'attempts' => $attempts,
      'final' => true,
      'ok' => true,
      'failed_path' => '',
    ];
  }

  $payload = [
    'attempts' => $attempts,
    'file' => $sourceFile,
    'last_failed_at' => gmdate('c'),
    'sample' => sanitizeLogSnippet($sample),
  ];
  if (!webcam_atomic_write_json($statePath, $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) {
    return [
      'attempts' => $attempts,
      'final' => false,
      'ok' => false,
      'failed_path' => $statePath,
    ];
  }

  return [
    'attempts' => $attempts,
    'final' => false,
    'ok' => true,
    'failed_path' => '',
  ];
}

function readOcrFailureAttempts(string $statePath): int
{
  if (!is_file($statePath)) {
    return 0;
  }
  $raw = @file_get_contents($statePath);
  if ($raw === false || trim($raw) === '') {
    return 0;
  }
  $data = json_decode($raw, true);
  if (is_array($data) && isset($data['attempts']) && is_numeric($data['attempts'])) {
    return max(0, (int) $data['attempts']);
  }
  if (preg_match('/attempts=(\d+)/', $raw, $m)) {
    return (int) $m[1];
  }
  return 0;
}

function stampLine(string $message): string
{
  return '[' . gmdate('c') . '] ' . $message;
}

// ---------------------------------------------------------------------------
// Einzelner OCR-Pass und zusammengeführte Analyse.
// Ein Pass erzeugt einen temporären Ausschnitt, ruft Tesseract begrenzt auf und
// liefert sowohl einen möglichen Messwert als auch Diagnosemerkmale zurück.
// ---------------------------------------------------------------------------
function runOcrPassDetailed(
  string $file,
  string $tmpPng,
  int $cropWidth,
  int $cropHeight,
  int $cropX,
  int $cropY,
  string $ops,
  bool $verbose,
  bool $useWhitelist,
  int $psm,
  bool $loose,
  int $ocrTimeoutSeconds,
  int $convertTimeoutSeconds
): ?array {
  $ops = adjustResizeOps($ops, $cropWidth, $cropHeight);
  $convertCmd = [
    'convert',
    $file,
    '-crop',
    $cropWidth . 'x' . $cropHeight . '+' . $cropX . '+' . $cropY,
    '+repage',
    '-colorspace',
  ];
  foreach (splitCommandArgs($ops) as $arg) {
    $convertCmd[] = $arg;
  }
  $convertCmd[] = '-resize';
  $convertCmd[] = '20000x6000>';
  $convertCmd[] = $tmpPng;

  $convert = runProcessWithTimeout($convertCmd, $convertTimeoutSeconds);
  if ($convert['exit_code'] !== 0 || $convert['timed_out'] || !is_file($tmpPng)) {
    @unlink($tmpPng);
    $analysis = newOcrAnalysis();
    if ($convert['timed_out']) {
      $analysis['ocr_timeout_detected'] = true;
      $analysis['sample'] = 'CONVERT_TIMEOUT after ' . $convertTimeoutSeconds . 's';
    }
    return [
      'parsed' => null,
      'analysis' => $analysis,
    ];
  }
  $ocr = runTesseract($tmpPng, $useWhitelist, $psm, $ocrTimeoutSeconds);
  $text = (string) $ocr['text'];
  @unlink($tmpPng);
  $analysis = analyzeOcrText($text, $file);
  if (!empty($ocr['timed_out'])) {
    $analysis['ocr_timeout_detected'] = true;
    if ($analysis['sample'] === '') {
      $analysis['sample'] = 'OCR_TIMEOUT after ' . $ocrTimeoutSeconds . 's';
    }
  }
  $parsed = parseWxText($text);
  if ($parsed === null && $loose) {
    $parsed = parseWxTextLoose($text);
  }
  if ($parsed === null && $verbose) {
    fwrite(STDERR, "[DEBUG] OCR output:\n" . trim($text) . "\n");
  }
  return [
    'parsed' => $parsed,
    'analysis' => $analysis,
  ];
}

function newOcrAnalysis(): array
{
  return [
    'timestamp_detected' => false,
    'expected_timestamp_detected' => false,
    'weather_hint_detected' => false,
    'incomplete_weather_detected' => false,
    'definitive_no_weather' => false,
    'ocr_timeout_detected' => false,
    'sample' => '',
  ];
}

function markOcrImageTimeout(array &$analysis, int $timeoutSeconds): void
{
  $analysis['ocr_timeout_detected'] = true;
  if (($analysis['sample'] ?? '') === '') {
    $analysis['sample'] = 'OCR_IMAGE_TIMEOUT after ' . $timeoutSeconds . 's';
  }
}

function analyzeOcrText(string $text, string $sourceFile = ''): array
{
  $sample = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
  $normalized = normalizeMinusChars(strtoupper($text));
  $hasTimestamp = (bool) preg_match('/\b\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}\b/', $normalized);
  $hasTimestamp = $hasTimestamp || (bool) preg_match('/\b\d{1,2}:\d{2}\b/', $normalized);
  $hasWeatherHint = (bool) preg_match('/\bMBAR\b|°\s*C|\b-?\d+(?:[.,]\d+)?\s*C\b/', $normalized);
  $hasPressureToken = strpos($normalized, 'MBAR') !== false;
  $hasTempToken = (bool) preg_match('/°\s*C|\bC\b/', $normalized);
  $hasPressureValue = (bool) preg_match('/\b\d{3,4}(?:[.,]\d+)?\s*MBAR\b/', $normalized);
  $hasTempValue = (bool) preg_match('/\b-?\d+(?:[.,]\d+)?\s*°?\s*C\b/', $normalized);
  $hasIncompleteWeather = $hasPressureToken && $hasTempToken && (!$hasPressureValue || !$hasTempValue);
  $hasExpectedTimestamp = matchesExpectedTimestamp($normalized, $sourceFile);
  return [
    'timestamp_detected' => $hasTimestamp,
    'expected_timestamp_detected' => $hasExpectedTimestamp,
    'weather_hint_detected' => $hasWeatherHint,
    'incomplete_weather_detected' => $hasIncompleteWeather,
    'definitive_no_weather' => false,
    'sample' => $sample,
  ];
}

function mergeOcrAnalysis(array $base, array $extra): array
{
  return [
    'timestamp_detected' => (bool) ($base['timestamp_detected'] || $extra['timestamp_detected']),
    'expected_timestamp_detected' => (bool) ($base['expected_timestamp_detected'] || $extra['expected_timestamp_detected']),
    'weather_hint_detected' => (bool) ($base['weather_hint_detected'] || $extra['weather_hint_detected']),
    'incomplete_weather_detected' => (bool) ($base['incomplete_weather_detected'] || $extra['incomplete_weather_detected']),
    'definitive_no_weather' => (bool) ($base['definitive_no_weather'] || $extra['definitive_no_weather']),
    'ocr_timeout_detected' => (bool) (($base['ocr_timeout_detected'] ?? false) || ($extra['ocr_timeout_detected'] ?? false)),
    'sample' => $base['sample'] !== '' ? $base['sample'] : $extra['sample'],
  ];
}

function shouldTreatAsNoWeather(array $analysis): bool
{
  if (empty($analysis['timestamp_detected'])) {
    return false;
  }

  if (empty($analysis['weather_hint_detected'])) {
    return true;
  }

  return !empty($analysis['incomplete_weather_detected']);
}

function sanitizeLogSnippet(string $text): string
{
  $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
  $text = str_replace('"', "'", $text);
  if (strlen($text) > 180) {
    $text = substr($text, 0, 177) . '...';
  }
  return $text;
}

function matchesExpectedTimestamp(string $normalizedText, string $sourceFile): bool
{
  $hints = expectedTimestampHints($sourceFile);
  if ($hints === null) {
    return false;
  }
  foreach ($hints['date_tokens'] as $dateToken) {
    if (strpos($normalizedText, $dateToken) === false) {
      continue;
    }
    if (strpos($normalizedText, $hints['time_token']) !== false) {
      return true;
    }
  }
  return false;
}

function expectedTimestampHints(string $sourceFile): ?array
{
  if (!preg_match('#/(\d{4})/(\d{2})/(\d{2})/(\d{4})_[a-z0-9]+\.jpg$#i', $sourceFile, $m)) {
    return null;
  }
  $year = $m[1];
  $month = $m[2];
  $day = $m[3];
  $hhmm = $m[4];
  $dateShort = $day . '.' . $month . '.' . substr($year, -2);
  $dateLong = $day . '.' . $month . '.' . $year;
  $time = substr($hhmm, 0, 2) . ':' . substr($hhmm, 2, 2);
  return [
    'date_tokens' => [strtoupper($dateShort), strtoupper($dateLong)],
    'time_token' => strtoupper($time),
  ];
}

// ---------------------------------------------------------------------------
// Plausibilitätsprüfungen für die zeitliche Messreihe.
// Vorhandene Sidecars werden in dasselbe Modell eingelesen wie neue OCR-Werte,
// damit Fortsetzungen und vollständige Läufe identisch geprüft werden.
// ---------------------------------------------------------------------------
function parseWxSidecar(string $file): ?array
{
  $text = @file_get_contents($file);
  if (!is_string($text) || $text === '') {
    return null;
  }
  if (!preg_match('/Temp:\s*([0-9.\-]+)°C\s+Press:\s*([0-9.\-]+)\s+mBar\s+Winddir:\s*([A-Z]+)/', $text, $m)) {
    return null;
  }
  return [
    'temp' => $m[1],
    'press' => $m[2],
    'wind' => $m[3],
  ];
}

function checkPressureGuard(?array $lastAccepted, string $hhmm, array $result): array
{
  if ($lastAccepted === null || !isset($lastAccepted['press'], $lastAccepted['hhmm'], $result['press'])) {
    return [
      'ok' => true,
      'prev_hhmm' => null,
      'prev_press' => null,
      'delta' => '0.0',
      'allowed' => '0.0',
    ];
  }

  $prevPress = (float) $lastAccepted['press'];
  $press = (float) $result['press'];
  $minutes = diffMinutesHhmm((string) $lastAccepted['hhmm'], $hhmm);
  $allowed = pressureJumpAllowance($minutes);
  $delta = abs($press - $prevPress);

  return [
    'ok' => $delta <= $allowed,
    'prev_hhmm' => (string) $lastAccepted['hhmm'],
    'prev_press' => formatFloat($prevPress),
    'delta' => formatFloat($delta),
    'allowed' => formatFloat($allowed),
  ];
}

function diffMinutesHhmm(string $from, string $to): int
{
  if (!preg_match('/^\d{4}$/', $from) || !preg_match('/^\d{4}$/', $to)) {
    return 10;
  }
  $fromMinutes = ((int) substr($from, 0, 2)) * 60 + (int) substr($from, 2, 2);
  $toMinutes = ((int) substr($to, 0, 2)) * 60 + (int) substr($to, 2, 2);
  $diff = $toMinutes - $fromMinutes;
  if ($diff <= 0) {
    return 10;
  }
  return $diff;
}

function pressureJumpAllowance(int $minutes): float
{
  if ($minutes < 1) {
    $minutes = 1;
  }
  $allowance = 2.0 + ($minutes * 0.05);
  if ($allowance > 10.0) {
    $allowance = 10.0;
  }
  return $allowance;
}

function formatFloat(float $value): string
{
  return number_format($value, 1, '.', '');
}

function checkAbsolutePlausibility(array $result): array
{
  $temp = isset($result['temp']) ? (float) $result['temp'] : 0.0;
  $hasPress = isset($result['press']);
  $press = $hasPress ? (float) $result['press'] : null;
  $tempMin = -35.0;
  $tempMax = 45.0;
  $pressMin = 930.0;
  $pressMax = 1060.0;
  $pressOk = !$hasPress || ($press >= $pressMin && $press <= $pressMax);

  return [
    'ok' => $temp >= $tempMin && $temp <= $tempMax && $pressOk,
    'temp_allowed' => formatFloat($tempMin) . '..' . formatFloat($tempMax),
    'press_allowed' => $hasPress ? (formatFloat($pressMin) . '..' . formatFloat($pressMax)) : 'n/a',
  ];
}

function normalizeMinusChars(string $text): string
{
  return str_replace(["\u{2212}", "\u{2013}", "\u{2014}"], '-', $text);
}

function adjustResizeOps(string $ops, int $cropWidth, int $cropHeight): string
{
  if ($cropWidth <= 0 || $cropHeight <= 0) {
    return $ops;
  }
  if (!preg_match('/-resize\s+(\d+)%/i', $ops, $m)) {
    return $ops;
  }
  $percent = (int) $m[1];
  if ($percent <= 0) {
    return $ops;
  }
  $maxWidth = 20000;
  $maxHeight = 6000;
  $limitPercent = $percent;
  $outW = (int) round($cropWidth * $percent / 100);
  $outH = (int) round($cropHeight * $percent / 100);
  if ($outW > $maxWidth) {
    $limitPercent = min($limitPercent, (int) floor($maxWidth * 100 / $cropWidth));
  }
  if ($outH > $maxHeight) {
    $limitPercent = min($limitPercent, (int) floor($maxHeight * 100 / $cropHeight));
  }
  if ($limitPercent < 100) {
    $limitPercent = 100;
  }
  if ($limitPercent === $percent) {
    return $ops;
  }
  return preg_replace('/-resize\s+\d+%/i', '-resize ' . $limitPercent . '%', $ops, 1) ?? $ops;
}
