<?php
declare(strict_types=1);

namespace Webcam\Service\View;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Bereitet alle Zusatzinformationen des ausgewählten Kamerabildes auf.
 *
 * Die Route `camera.php` entscheidet weiterhin, welches Bild und welche
 * Ansicht angefordert wurden. Diese Klasse übernimmt anschließend den
 * zusammenhängenden Präsentationsschritt:
 *
 * 1. EXIF- und Wettertexte aus DB-Daten oder Legacy-Sidecars lesen,
 * 2. Likes und Logo-Erkennung mit der Kamera-DB abgleichen,
 * 3. das sichtbare Bild-Overlay und seine Farbwahl zusammensetzen,
 * 4. Modaltexte, zeitabhängige Labels und den Best-Of-Zustand ableiten.
 *
 * Der Builder rendert kein HTML und liest keine Request-Globals. Seine
 * Rückgabe kann deshalb unabhängig vom großen Kamera-Template getestet und
 * später auch von einer API oder einem anderen Renderer verwendet werden.
 */
final class CameraSnapshotPresentationBuilder
{
  private readonly CameraOverlayMetadata $overlayMetadata;
  private readonly CameraWeatherMetadata $weatherMetadata;

  public function __construct(
    private readonly App $app,
    ?CameraOverlayMetadata $overlayMetadata = null,
    ?CameraWeatherMetadata $weatherMetadata = null
  ) {
    $this->overlayMetadata = $overlayMetadata ?? new CameraOverlayMetadata();
    $this->weatherMetadata = $weatherMetadata ?? new CameraWeatherMetadata($app);
  }

  /**
   * Baut das vollständige, templatefertige Präsentationsmodell eines Bildes.
   *
   * `$selectedSnapshot` stammt aus Timeline/Archiv und darf bei einer Kamera
   * ohne Bild `null` sein. `$state` enthält nur Live-Fallbackwerte; sie werden
   * für Archivansichten bewusst nicht verwendet. `$bestOf` ist die bereits
   * geladene Galerie und dient ausschließlich als Konsistenzgegenprobe.
   *
   * @param array<string,mixed>|null $selectedSnapshot
   * @param array<string,mixed> $state
   * @param array<string,mixed> $cameraData
   * @param list<array<string,mixed>> $bestOf
   * @return array{
   *   exif_data:?string,
   *   weather_data:?string,
   *   overlay:array{parts:list<string>,tone:string,luminance:?float}|null,
   *   logo_tone:string,
   *   frontend_overlay_suppressed:bool,
   *   camera_labels:list<array<string,mixed>>,
   *   best_of_state:array{relative_path:?string,is_best_of:bool},
   *   weather_day_available:bool,
   *   modal_weather_text:?string,
   *   modal_exif_text:string
   * }
   */
  public function build(
    Camera $camera,
    ?array $selectedSnapshot,
    array $state,
    array $cameraData,
    array $bestOf,
    bool $isLiveViewRequest,
    string $viewMode
  ): array {
    // EXIF und Wetter werden zuerst aufbereitet, weil beide später sowohl in
    // Modals als auch im direkt über dem Bild liegenden Overlay erscheinen.
    [$exifData, $exifValues] = $this->exifPresentation($selectedSnapshot);
    $weatherData = $this->weatherData(
      $camera,
      $selectedSnapshot,
      $state,
      $isLiveViewRequest,
      $viewMode
    );

    // Likes und Logo-Status gehören zum selben Capture. Der read-only
    // DB-Abgleich korrigiert ältere Timeline-Einträge, ohne etwas zu schreiben.
    [$likesCount, $logoDetected] = $this->capturePresentationState(
      $camera,
      $selectedSnapshot
    );

    $timezone = (string) $this->app->config('timezone', 'UTC');
    $overlayPresentation = $this->overlayPresentation(
      $camera,
      $selectedSnapshot,
      $state,
      $exifValues,
      $weatherData,
      $likesCount,
      $timezone
    );
    $overlayParts = $overlayPresentation['parts'];
    $frontendOverlaySuppressed = $logoDetected === true;

    // Ein bereits in die Bilddatei gerendertes Logo/Overlay darf nicht ein
    // zweites Mal im Browser erscheinen. Dann werden auch freie Labels
    // ausgeblendet, weil sie Teil derselben Präsentationsebene sind.
    $cameraLabels = $frontendOverlaySuppressed
      ? []
      : $this->cameraLabels(
        $camera,
        $selectedSnapshot,
        $cameraData,
        $overlayPresentation['timestamp'],
        $timezone
      );

    return [
      'exif_data' => $exifData,
      'weather_data' => $weatherData,
      'overlay' => $overlayPresentation['overlay'],
      'logo_tone' => $overlayPresentation['logo_tone'],
      'frontend_overlay_suppressed' => $frontendOverlaySuppressed,
      'camera_labels' => $cameraLabels,
      'best_of_state' => $this->bestOfState(
        $camera,
        $selectedSnapshot,
        $bestOf,
        $likesCount
      ),
      'weather_day_available' => $selectedSnapshot !== null
        && !in_array($viewMode, ['bestof', 'global-bestof'], true)
        && isset($selectedSnapshot['year'], $selectedSnapshot['month'], $selectedSnapshot['day']),
      'modal_weather_text' => $this->modalWeatherText($weatherData, $overlayParts),
      'modal_exif_text' => $this->modalExifText($exifData, $overlayParts),
    ];
  }

  /**
   * Lädt EXIF bevorzugt aus dem bereits hydrierten Snapshot. Nur alte
   * Timeline-Einträge ohne DB-Text benötigen noch ihre physische Sidecar-Datei.
   *
   * @param array<string,mixed>|null $snapshot
   * @return array{0:?string,1:array<string,string>}
   */
  private function exifPresentation(?array $snapshot): array
  {
    $exifData = null;
    if (!empty($snapshot['exif_text']) && is_string($snapshot['exif_text'])) {
      $exifData = trim($snapshot['exif_text']);
    } elseif (!empty($snapshot['exif_path']) && is_string($snapshot['exif_path'])) {
      $content = $this->readTextFile($snapshot['exif_path']);
      if ($content !== null) {
        $exifData = trim($content);
      }
    }

    if ($exifData === null || $exifData === '') {
      return [$exifData, []];
    }

    return $this->parseExifText($exifData);
  }

  /**
   * Versteht beide historisch verwendeten EXIF-Formate (`Key|Value` und
   * `Key: Value`). Zusätzlich wird die schon bisher sichtbare Kurzfassung an
   * den vollständigen Text angehängt, damit das Modal seine kompakte Zeile
   * behält.
   *
   * @return array{0:string,1:array<string,string>}
   */
  private function parseExifText(string $exifData): array
  {
    $lines = preg_split('/\r?\n/', $exifData) ?: [];
    $values = [];
    $likesValue = null;

    foreach ($lines as $line) {
      $trimmed = trim($line);
      if ($trimmed === '') {
        continue;
      }

      // Likes-Sidecars verwenden traditionell "Likes: 5". Der Wert gehört in
      // die Kurzfassung, soll aber nicht als beliebiges EXIF-Feld erscheinen.
      if ($likesValue === null && preg_match('/^likes\s*:\s*(\d+)/i', $trimmed, $matches)) {
        $likesValue = $matches[1];
        continue;
      }

      $separator = str_contains($trimmed, '|') ? '|' : (str_contains($trimmed, ':') ? ':' : null);
      if ($separator === null) {
        continue;
      }
      [$key, $value] = array_map('trim', explode($separator, $trimmed, 2));
      if ($key !== '') {
        $values[$key] = $value;
      }
    }

    $summary = [];
    $exposure = $values['ExposureTime'] ?? $values['Exposure Time'] ?? null;
    if (is_string($exposure) && $exposure !== '') {
      $summary[] = $exposure;
    }
    $fNumber = $values['FNumber'] ?? $values['F-Number'] ?? null;
    if (is_string($fNumber) && $fNumber !== '') {
      $summary[] = preg_match('/^f\s*\//i', $fNumber)
        ? $fNumber
        : 'f/' . ltrim($fNumber, 'f/ ');
    }
    $iso = $values['ISO'] ?? $values['ISO Speed Ratings'] ?? null;
    if (is_string($iso) && $iso !== '') {
      $summary[] = preg_match('/^ISO/i', $iso) ? $iso : 'ISO ' . $iso;
    }
    $likes = $values['Likes'] ?? $likesValue;
    if (is_string($likes) && $likes !== '') {
      $summary[] = trim($likes) . ' Likes';
    }

    if ($summary !== []) {
      $lines[] = implode(' · ', $summary);
      $exifData = trim(implode("\n", $lines));
    }

    return [$exifData, $values];
  }

  /**
   * Wählt Wetterdaten in fester Priorität: Snapshot-DB, Legacy-Sidecar und nur
   * in der echten Liveansicht zuletzt bekannter Statuswert. Qualitätsmarkierte
   * Slots liefern absichtlich gar keinen Wettertext.
   *
   * @param array<string,mixed>|null $snapshot
   * @param array<string,mixed> $state
   */
  private function weatherData(
    Camera $camera,
    ?array $snapshot,
    array $state,
    bool $isLiveViewRequest,
    string $viewMode
  ): ?string {
    $slotTimestamp = isset($snapshot['timestamp']) && is_numeric($snapshot['timestamp'])
      ? (int) $snapshot['timestamp']
      : null;
    if ($this->weatherMetadata->slotHasQualityFlag($camera, $slotTimestamp)) {
      return null;
    }

    $weatherData = null;
    if (!empty($snapshot['weather_text']) && is_string($snapshot['weather_text'])) {
      $weatherData = $this->weatherMetadata->normalizeText($snapshot['weather_text']);
    }
    if ($weatherData === null || $weatherData === '') {
      $weatherPath = $snapshot['weather_path'] ?? null;
      if (is_string($weatherPath) && $weatherPath !== '') {
        $weatherData = $this->weatherMetadata->normalizeText($this->readTextFile($weatherPath));
      }
    }

    if (
      ($weatherData === null || $weatherData === '')
      && $isLiveViewRequest
      && $viewMode === 'live'
    ) {
      if (!empty($state['last_weather_data'])) {
        $weatherData = $this->weatherMetadata->normalizeText((string) $state['last_weather_data']);
      } elseif (!empty($state['last_weather_path']) && is_string($state['last_weather_path'])) {
        $weatherData = $this->weatherMetadata->normalizeText(
          $this->readTextFile($state['last_weather_path'])
        );
      }
    }

    return $weatherData;
  }

  /**
   * Liefert Like-Zähler und Logo-Erkennung für genau einen Capture.
   *
   * Der zusätzliche DB-Lookup bleibt wie im bisherigen Seitenablauf auf ältere
   * Snapshotdaten ohne Like-Zähler beschränkt. Ein nicht erreichbares oder kurz
   * gesperrtes SQLite darf die öffentliche Kameraseite nicht unbenutzbar machen.
   *
   * @param array<string,mixed>|null $snapshot
   * @return array{0:int,1:?bool}
   */
  private function capturePresentationState(Camera $camera, ?array $snapshot): array
  {
    $likesCount = isset($snapshot['likes_count']) ? (int) $snapshot['likes_count'] : 0;
    $logoDetected = null;

    if ($likesCount === 0 && $snapshot !== null) {
      $relativePath = $snapshot['relative_path'] ?? null;
      $slotTimestamp = isset($snapshot['timestamp']) && is_numeric($snapshot['timestamp'])
        ? (int) $snapshot['timestamp']
        : (isset($snapshot['slot_ts']) && is_numeric($snapshot['slot_ts'])
          ? (int) $snapshot['slot_ts']
          : null);

      try {
        $store = new CameraDataStore($this->app, $camera, null, true);
        $captureId = $slotTimestamp !== null
          ? $store->findCaptureIdBySlot($slotTimestamp)
          : null;

        if (is_string($relativePath) && $relativePath !== '') {
          $relativePath = $store->normalizeRelativePath($relativePath);
          if ($captureId === null) {
            $captureId = $store->findCaptureIdByAnyPath($relativePath)
              ?? $store->findCaptureIdByRelativePath($relativePath);
          }
        }

        if ($captureId !== null) {
          $logoStatus = $store->logoStatusById($captureId);
          if ($logoStatus !== null) {
            $logoDetected = $logoStatus['detected'];
          }
          $databaseLikes = $store->likesCountById($captureId);
          if ($databaseLikes !== null) {
            $likesCount = $databaseLikes;
          }
        }
      } catch (Throwable) {
        // Fail-open nur für die Darstellung: Bild und Seite bleiben sichtbar,
        // aber ohne unbestätigten Like-/Logo-Zustand. Es erfolgt kein Write.
      }
    }

    // Historische Archivbilder enthalten ihr Logo bereits physisch. Der
    // Browser darf darüber kein zweites Overlay legen, auch wenn eine alte DB
    // noch keinen capture_logo-Eintrag besitzt.
    if ($logoDetected === null && isset($snapshot['relative_path']) && is_string($snapshot['relative_path'])) {
      if (str_contains($snapshot['relative_path'], 'archive/' . $camera->slug() . '/')) {
        $logoDetected = true;
      }
    }

    return [$likesCount, $logoDetected];
  }

  /**
   * Verbindet Zeit, Wetter, Belichtung und Likes zu den maximal drei Zeilen des
   * Browser-Overlays und bestimmt anhand von Helligkeit/Nacht die Kontrastfarbe.
   *
   * @param array<string,mixed>|null $snapshot
   * @param array<string,mixed> $state
   * @param array<string,string> $exifValues
   * @return array{
   *   overlay:array{parts:list<string>,tone:string,luminance:?float}|null,
   *   parts:list<string>,timestamp:?int,logo_tone:string
   * }
   */
  private function overlayPresentation(
    Camera $camera,
    ?array $snapshot,
    array $state,
    array $exifValues,
    ?string $weatherData,
    int $likesCount,
    string $timezone
  ): array {
    $timestamp = isset($snapshot['timestamp']) && is_numeric($snapshot['timestamp'])
      ? (int) $snapshot['timestamp']
      : null;
    $timeLabel = $timestamp === null
      ? null
      : (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(new DateTimeZone($timezone))
        ->format('d.m.Y H:i') . ' Uhr';

    $exposureParts = [];
    if (!empty($exifValues['Exposure Time'])) {
      $exposureParts[] = $exifValues['Exposure Time'];
    }
    if (!empty($exifValues['FNumber'])) {
      $exposureParts[] = $exifValues['FNumber'];
    }
    if (!empty($exifValues['ISO'])) {
      $exposureParts[] = 'ISO ' . $exifValues['ISO'];
    }
    $exposureLabel = $exposureParts !== [] ? implode(' · ', $exposureParts) : null;

    $luminance = $this->luminance($exifValues, $state);
    $isNight = $timestamp !== null
      ? $this->overlayMetadata->isNight($camera, $timestamp, $timezone)
      : false;
    $threshold = $isNight ? 55.0 : 45.0;
    if ($luminance === null) {
      $tone = $isNight ? 'light' : 'dark';
    } else {
      $tone = $luminance > $threshold ? 'dark' : 'light';
    }

    // Datum und Wetter teilen sich bewusst die erste Zeile; Belichtung und
    // Likes bleiben getrennte Teile, damit das Template sie lesbar umbrechen kann.
    $primaryParts = array_values(array_filter(
      [$timeLabel, $weatherData],
      static fn(mixed $value): bool => $value !== null && $value !== ''
    ));
    $primaryLabel = $primaryParts !== [] ? implode(' ', $primaryParts) : null;
    $parts = array_values(array_filter(
      [
        $primaryLabel,
        $exposureLabel,
        $likesCount > 0 ? ('❤ ' . $likesCount . ' Likes') : null,
      ],
      static fn(mixed $value): bool => $value !== null && $value !== ''
    ));

    return [
      'overlay' => $parts !== [] ? [
        'parts' => $parts,
        'tone' => $tone,
        'luminance' => $luminance,
      ] : null,
      'parts' => $parts,
      'timestamp' => $timestamp,
      'logo_tone' => $luminance !== null
        ? ($luminance > $threshold ? 'dark' : 'light')
        : ($isNight ? 'light' : 'dark'),
    ];
  }

  /** @param array<string,string> $exifValues @param array<string,mixed> $state */
  private function luminance(array $exifValues, array $state): ?float
  {
    if (!empty($exifValues['Luminance'])) {
      $digits = preg_replace('/[^0-9.,-]/', '', $exifValues['Luminance']);
      $numeric = (float) str_replace(',', '.', (string) $digits);
      if ($numeric > 0) {
        return $numeric;
      }
    }

    return isset($state['last_luminance']) && is_numeric($state['last_luminance'])
      ? (float) $state['last_luminance']
      : null;
  }

  /**
   * Freie Bildbeschriftungen gelten für den Aufnahmezeitpunkt. Fehlt er bei
   * einem Legacy-Bild, dienen Slot, letzte Aufnahme und zuletzt die aktuelle
   * Zeit als geordnete Ersatzquellen.
   *
   * @param array<string,mixed>|null $snapshot
   * @param array<string,mixed> $cameraData
   * @return list<array<string,mixed>>
   */
  private function cameraLabels(
    Camera $camera,
    ?array $snapshot,
    array $cameraData,
    ?int $overlayTimestamp,
    string $timezone
  ): array {
    $referenceTimestamp = $overlayTimestamp
      ?? (isset($snapshot['slot_ts']) && is_numeric($snapshot['slot_ts'])
        ? (int) $snapshot['slot_ts']
        : null)
      ?? (($cameraData['last_capture'] ?? null) instanceof DateTimeImmutable
        ? $cameraData['last_capture']->getTimestamp()
        : time());

    return $this->overlayMetadata->labelsForTimestamp(
      $camera->labels(),
      $referenceTimestamp,
      new DateTimeZone($timezone)
    );
  }

  /**
   * Best-Of gilt als aktiv, wenn die veröffentlichte Datei existiert. Für alte
   * Galerien ohne direkt auflösbaren Pfad dient die bereits geladene Liste als
   * Gegenprobe; ein positiver Like-Zähler ist der Ausgangswert.
   *
   * @param array<string,mixed>|null $snapshot
   * @param list<array<string,mixed>> $bestOf
   * @return array{relative_path:?string,is_best_of:bool}
   */
  private function bestOfState(
    Camera $camera,
    ?array $snapshot,
    array $bestOf,
    int $likesCount
  ): array {
    $emptyState = ['relative_path' => null, 'is_best_of' => false];
    if (!isset($snapshot['relative_path']) || !is_string($snapshot['relative_path'])) {
      return $emptyState;
    }

    $relativePath = trim($snapshot['relative_path']);
    if ($relativePath === '') {
      return $emptyState;
    }

    $targetBase = basename($relativePath);
    $hasDatePrefix = (bool) preg_match('/^\d{8}_\d{6}_/', $targetBase);
    $isBestOf = $likesCount > 0;
    $bestOfLocation = $this->app->bestOfLocation($camera);
    $bestOfDirectory = rtrim($bestOfLocation['directory'], DIRECTORY_SEPARATOR);

    if ($bestOfDirectory !== '' && $hasDatePrefix) {
      $isBestOf = is_file($bestOfDirectory . DIRECTORY_SEPARATOR . $targetBase);
    }
    if (!$isBestOf && $hasDatePrefix) {
      foreach ($bestOf as $entry) {
        if (!empty($entry['filename']) && basename((string) $entry['filename']) === $targetBase) {
          $isBestOf = true;
          break;
        }
      }
    }

    return [
      'relative_path' => $relativePath,
      'is_best_of' => $isBestOf,
    ];
  }

  /** @param list<string> $overlayParts */
  private function modalWeatherText(?string $weatherData, array $overlayParts): ?string
  {
    if ($weatherData !== null && $weatherData !== '') {
      return $weatherData;
    }
    foreach ($overlayParts as $part) {
      if (preg_match('/(°C|\bT\s*:|\bP\s*:|\bW\s*:|Wind|Press|Temp)/i', $part)) {
        return $part;
      }
    }
    return $weatherData;
  }

  /** @param list<string> $overlayParts */
  private function modalExifText(?string $exifData, array $overlayParts): string
  {
    if ($exifData !== null && $exifData !== '') {
      return $exifData;
    }
    foreach ($overlayParts as $part) {
      if (preg_match('/(ISO|f\/|sec)/i', $part)) {
        return $part;
      }
    }
    return '';
  }

  /** Liest optionale Legacy-Dateien ausschließlich, wenn sie regulär lesbar sind. */
  private function readTextFile(string $path): ?string
  {
    if (!is_file($path) || !is_readable($path)) {
      return null;
    }
    return (string) file_get_contents($path);
  }
}
