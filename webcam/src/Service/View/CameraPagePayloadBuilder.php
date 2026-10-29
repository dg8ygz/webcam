<?php
declare(strict_types=1);

namespace Webcam\Service\View;

use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Baut die gemeinsame Template-Payload aller öffentlichen Kameraansichten.
 *
 * Leseführung
 * -----------
 * 1. `base()` sammelt ausschließlich Werte, die schon nach dem Request-Parsing
 *    für Live-, Archiv- und Best-Of-Ansichten identisch vorliegen.
 * 2. `build()` ergänzt sichere Leerwerte und überschreibt sie mit den Daten
 *    der tatsächlich gewählten Ansicht.
 * 3. `scripts()` hält die geordnete JavaScript-Liste an genau einer Stelle und
 *    ergänzt Karte bzw. globales Best-Of nur bei Bedarf.
 *
 * Der Builder rendert kein HTML und liest weder GET- noch Server-Globals. So
 * bleibt die Route für die Auswahl der Daten zuständig, während Früh- und
 * Normalrender nicht mehr unbemerkt auseinanderlaufen können.
 */
final class CameraPagePayloadBuilder
{
  /** @var list<string> */
  private const CORE_SCRIPTS = [
    'js/camera-weather-model.js',
    'js/camera-weather.js',
    'js/camera-surroundings.js',
    'js/camera-image-compare.js',
    'js/camera-snapshot-prefetch.js',
    'js/camera-labels.js',
    'js/camera-meta-modal.js',
    'js/camera-timeline.js',
    'js/camera-timeline-model.js',
    'js/camera-timeline-data.js',
    'js/camera-timeline-view.js',
    'js/camera-captcha.js',
    'js/camera-bestof-like.js',
    'js/camera-status.js',
    'js/camera-partial-navigation.js',
    'js/camera-history.js',
    'js/camera-dayvideo.js',
    'js/camera-playback.js',
    'js/camera-lightbox-entry.js',
    'js/camera-lightbox-controls.js',
    'js/camera-lightbox.js',
    'js/camera-stream-preference.js',
    'js/camera-stream-hls.js',
    'js/camera-stream.js',
    'js/camera-live-image.js',
    'js/camera.js',
  ];

  /**
   * @param array<string,mixed> $cameraData
   * @param array<string,mixed> $state
   * @param array{grouped:array<string,mixed>,years:list<string>} $archive
   * @param array{year:mixed,month:mixed,day:mixed,time:mixed} $selection
   * @param array{html:string,available:bool} $info
   * @param array{log:callable(string):void,enabled:bool} $profiling
   * @return array<string,mixed>
   */
  public function base(
    App $app,
    Camera $camera,
    array $cameraData,
    array $state,
    array $archive,
    array $selection,
    array $info,
    array $profiling,
    string $requestedStreamMode
  ): array {
    return [
      'app' => $app,
      'slug' => $camera->slug(),
      'pageTitle' => $camera->title(),
      'camera' => $cameraData,
      'status' => $state,
      'archiveYears' => $archive['grouped'],
      'archiveYearsList' => $archive['years'],
      'yearParam' => $selection['year'],
      'monthParam' => $selection['month'],
      'dayParam' => $selection['day'],
      'timeParam' => $selection['time'],
      'infoHtml' => $info['html'],
      'hasInfoHtml' => $info['available'],
      'wcLog' => $profiling['log'],
      'wcProfile' => $profiling['enabled'],
      'requestedStreamMode' => $requestedStreamMode,
    ];
  }

  /**
   * Vervollständigt eine Ansichts-Payload. `array_replace` ist hier bewusst
   * flach: Timeline, Sidebar und Navigation sind jeweils fertige View-Models
   * und sollen nicht feldweise mit den Leerwerten vermischt werden.
   *
   * @param array<string,mixed> $base
   * @param array<string,mixed> $view
   * @return array<string,mixed>
   */
  public function build(array $base, array $view): array
  {
    $payload = array_replace($this->defaults(), $base, $view);
    $payload['scripts'] = $this->scripts(
      (bool) $payload['enableLeaflet'],
      (string) $payload['viewMode'] === 'global-bestof'
    );
    return $payload;
  }

  /** @return list<string> */
  public function scripts(bool $enableLeaflet, bool $globalBestOf): array
  {
    $scripts = self::CORE_SCRIPTS;
    if ($enableLeaflet) {
      $scripts[] = 'js/camera-map.js';
    }
    if ($globalBestOf) {
      $scripts[] = 'js/global-bestof.js';
    }
    return $scripts;
  }

  /**
   * Definiert die vollständige, harmlose Leerform des Kamera-Templates. Der
   * frühe Best-Of-Pfad kann dadurch teure Timeline-Daten weglassen, ohne eine
   * zweite Kopie der Template-Schnittstelle pflegen zu müssen.
   *
   * @return array<string,mixed>
   */
  private function defaults(): array
  {
    return [
      'bestOf' => [],
      'bestOfPage' => 1,
      'bestOfPerPage' => 50,
      'bestOfYears' => [],
      'bestOfSelectedYear' => null,
      'topLiked' => [],
      'globalBestOfPage' => 1,
      'globalBestOfPerPage' => 50,
      'globalSelectedYear' => null,
      'globalSelectedCamera' => null,
      'globalCameraOptions' => [],
      'timeline' => [
        'entries' => [],
        'years' => [],
        'months' => [],
        'days' => [],
        'times' => [],
        'latest' => null,
        'year_filter' => null,
      ],
      'snapshot' => null,
      'timelineNav' => ['has_data' => false],
      'monthPickerOptions' => [],
      'dayPickerOptions' => [],
      'hourPickerOptions' => [],
      'yearPickerOptions' => [],
      'timelineTimeOptions' => [],
      'timelineLatestUrl' => null,
      'exifData' => null,
      'overlay' => null,
      'logoTone' => null,
      'frontendOverlaySuppressed' => false,
      'cameraLabels' => [],
      'bestOfState' => ['relative_path' => null, 'is_best_of' => false],
      'sidebarDownloads' => [
        'exif' => null,
        'weather' => null,
        'weatherTimestamp' => null,
        'has_info' => false,
        'bestof_link' => null,
      ],
      'modalWeatherText' => null,
      'modalExifText' => '',
      'mainClass' => 'main-content main-content--full',
      'viewMode' => 'live',
      'viewLinks' => [],
      'bestOfLink' => null,
      'enableLeaflet' => false,
      'prefetchEnabled' => false,
      'timelineFetchEnabled' => false,
      'isLiveViewRequest' => false,
      'isArchiveSelection' => false,
      'mapPayload' => null,
      'styles' => ['vendor/fontawesome/css/all.min.css'],
      'requestedStreamMode' => '',
    ];
  }
}
