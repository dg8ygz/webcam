<?php
declare(strict_types=1);

// Wiederverwendbare Renderblöcke der Kameraansicht.
//
// Diese Datei wird von templates/camera.php nach der Datenvorbereitung
// eingebunden. Sie definiert ausschließlich die Timeline-/Menü- und
// Tagesvideo-Renderer. Alle verwendeten Werte stehen deshalb ausdrücklich
// in den jeweiligen Closure-use-Listen; versteckte globale Abhängigkeiten
// würden sonst erst beim Öffnen eines Menüs als HTTP-500 sichtbar.

// Die folgenden Closures erzeugen größere, mehrfach verwendete HTML-Blöcke.
// `ob_start()` sammelt deren Ausgabe als String; erst der eigentliche View am
// Dateiende entscheidet, welcher Block sichtbar wird.
$renderTimelineControls = static function (bool $interactive = true) use (
  $usbLiveLink,
  $usbLiveActive,
  $isPlaybackView,
  $camera,
  $timelineNav,
  $timelineTimeOptions,
  $yearPickerOptions,
  $monthPickerOptions,
  $dayPickerOptions,
  $hourPickerOptions,
  $timelineLatestUrl,
  $relativePrefix,
  $viewLinks,
  $navSlugList,
  $sidebarDownloads,
  $bestofLink,
  $bestofNavLink,
  $infoLink,
  $historyLink,
  $historyRefTs,
  $app,
  $cameraSlug,
  $timelineCameraTitle,
  $streamEnabled,
  $streamHlsEndpoint,
  $streamMode,
  $requestedStreamMode,
  $liveDisplayDefault,
  $buildCameraNavLink,
  $appendNavParam,
  $labelToggleInitiallyHidden,
  $bestOfInfoToolsDisabled
): string {
  ob_start();
  ?>
    <div class="timeline-controls" id="timeline-controls" data-timeline-container aria-live="polite">
        <div class="timeline-controls__groups">
          <div class="timeline-controls__group timeline-controls__group--menus">
            <!-- nav-version a7761fa -->
            <div class="tl-menu">
              <button type="button" class="tl-menu__toggle tl-menu__toggle--plain">
                <span class="tl-menu__icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" role="presentation" focusable="false">
                    <circle cx="12" cy="12" r="9" />
                    <polygon points="12,7 9,15 15,13 12,7" />
                  </svg>
                </span>
                Übersicht
              </button>
              <div class="tl-menu__dropdown">
              <a href="<?= htmlspecialchars($relativePrefix) ?>" data-no-ajax="1">Start</a>
              <a href="<?= htmlspecialchars($viewLinks['global_bestof'] ?? ($relativePrefix . 'bestof')) ?>" data-no-ajax="1">Globales Best Of</a>
              <?php foreach ($navSlugList as $camItem): ?>
                <?php $slug = $camItem['slug'] ?? ''; $title = $camItem['title'] ?? $slug; if ($slug === '') continue; ?>
                <a
                  href="<?= htmlspecialchars($buildCameraNavLink((string) $slug)) ?>"
                  data-camera-slug="<?= htmlspecialchars((string) $slug) ?>"
                  data-no-ajax="1"
                ><?= htmlspecialchars($title) ?></a>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="tl-menu">
            <button type="button" class="tl-menu__toggle tl-menu__toggle--plain" aria-label="Infos">
              <span class="tl-menu__icon" aria-hidden="true">ⓘ</span> Infos
            </button>
            <div class="tl-menu__dropdown">
              <?php if ($infoLink): ?><a href="<?= htmlspecialchars($infoLink) ?>">Webcam-Infos</a><?php endif; ?>
              <?php if (!empty($sidebarDownloads['exif'])): ?><a href="#" data-open-exif>EXIF-Daten</a><?php endif; ?>
              <?php if (!empty($sidebarDownloads['weather_available']) || !empty($sidebarDownloads['weather'])): ?>
                <a href="#" data-open-weather>Wetterdaten</a>
                <a href="<?= htmlspecialchars($relativePrefix . 'weather?camera=' . rawurlencode((string) ($camera['slug'] ?? ''))) ?>" data-no-ajax="1">Wetterverlauf dieser Kamera</a>
              <?php endif; ?>
              <?php if ($bestOfInfoToolsDisabled): ?>
                <!--
                  Best-of zeigt eine Galerie und besitzt weder die Karten- und
                  Telemetrie-Dialoge noch die Beschriftung des großen Kamerabilds.
                  Ohne data-Attribute kann JavaScript diese Hinweise nicht
                  versehentlich als funktionierende Bedienelemente behandeln.
                -->
                <span class="tl-menu__disabled" aria-disabled="true" title="In der Best-of-Ansicht nicht verfügbar">Umgebungskarte</span>
                <span class="tl-menu__disabled" aria-disabled="true" title="In der Best-of-Ansicht nicht verfügbar">Beschriftung ausblenden</span>
                <span class="tl-menu__disabled" aria-disabled="true" title="In der Best-of-Ansicht nicht verfügbar">Status &amp; Telemetrie</span>
              <?php else: ?>
                <a href="#" data-open-surroundings-map>Umgebungskarte</a>
                <a href="#" data-label-toggle aria-pressed="true"<?= $labelToggleInitiallyHidden ? ' hidden' : '' ?>>Beschriftung ausblenden</a>
                <a href="#" data-open-status>Status &amp; Telemetrie</a>
              <?php endif; ?>
            </div>
          </div>
          <div class="tl-menu tl-menu--spaced">
            <button type="button" class="tl-menu__toggle tl-menu__toggle--plain" aria-label="Optionen">
              <span class="tl-menu__icon" aria-hidden="true">🎞️</span> Optionen
            </button>
            <div class="tl-menu__dropdown">
              <?php if ($usbLiveLink !== null): ?>
                <a href="<?= htmlspecialchars($usbLiveLink) ?>" data-no-ajax="1"<?= $usbLiveActive ? ' class="is-active" aria-current="page"' : '' ?>>Livestream</a>
                <a href="<?= htmlspecialchars($viewLinks['live'] ?? '#') ?>" data-no-ajax="1" data-usb-snapshot-link>Livebild</a>
              <?php elseif ($streamEnabled): ?>
                <?php if ($requestedStreamMode === 'hls' && in_array($streamMode, ['rtsp', 'mjpeg'], true)): ?>
                <!-- Die HLS-Seite rendert keinen MJPEG-Player. Wie beim Video-Link
                     beendet ein voller Seitenwechsel den bisherigen Transport. -->
                <a href="<?= htmlspecialchars($buildCameraNavLink($cameraSlug), ENT_QUOTES) ?>" data-no-ajax="1">Livestream</a>
                <?php else: ?>
                <a href="#" data-live-display="stream" class="<?= $liveDisplayDefault === 'stream' ? 'is-active' : '' ?>" aria-pressed="<?= $liveDisplayDefault === 'stream' ? 'true' : 'false' ?>">Livestream</a>
                <?php endif; ?>
                <?php if ($streamHlsEndpoint !== ''): ?>
                <a href="<?= htmlspecialchars($buildCameraNavLink($cameraSlug) . '?stream=hls') ?>" data-no-ajax="1" data-stream-video-link>Livestream als Video</a>
                <?php endif; ?>
                <a href="#" data-live-display="snapshot" class="<?= $liveDisplayDefault === 'snapshot' ? 'is-active' : '' ?>" aria-pressed="<?= $liveDisplayDefault === 'snapshot' ? 'true' : 'false' ?>">Snapshot</a>
              <?php else: ?>
                <a href="<?= htmlspecialchars($viewLinks['live'] ?? '#') ?>">Livebild</a>
              <?php endif; ?>
              <a href="<?= htmlspecialchars($viewLinks['playback'] ?? '#') ?>">Timelapse</a>
              <div class="tl-menu__submenu" data-compare-menu>
                <button type="button" class="tl-menu__submenu-toggle" data-compare-menu-toggle aria-expanded="false">
                  Bildvergleich
                </button>
                <div class="tl-menu__submenu-items" data-compare-menu-items hidden>
                  <a href="#" data-remember-compare-image>Vergleichsbild merken</a>
                  <a href="#" data-open-image-compare>Bildvergleich öffnen</a>
                  <a href="#" data-clear-compare-image hidden>Vergleichsbild löschen</a>
                </div>
              </div>
              <a href="<?= htmlspecialchars($bestofNavLink) ?>">Best Of</a>
              <?php $dayVideoLink = ($viewLinks['playback'] ?? '?view=playback') . '#dayvideo'; ?>
              <a href="<?= htmlspecialchars($dayVideoLink) ?>" data-no-ajax="1" data-dayvideo-open role="button" aria-label="Tagesvideo abspielen">Tagesvideo (MP4)</a>
              <?php if ($historyLink): ?><a href="<?= htmlspecialchars($historyLink) ?>#days" data-no-ajax="1">Tagesverlauf</a><?php endif; ?>
              <?php if ($historyLink): ?><a href<?= '="' . htmlspecialchars($historyLink) . '#months"' ?> data-no-ajax="1">Monatsvergleich</a><?php endif; ?>
              <?php if ($historyLink): ?><a href="<?= htmlspecialchars($historyLink) ?>#years" data-no-ajax="1">Jahresvergleich</a><?php endif; ?>
            </div>
          </div>
          </div>
          <div class="timeline-controls__group timeline-controls__group--camera-name" aria-label="Aktuelle Kamera">
            <span class="timeline-controls__camera-icon" aria-hidden="true">Kamera</span>
            <div class="timeline-controls__camera-name" title="<?= htmlspecialchars($timelineCameraTitle) ?>">
              <?= htmlspecialchars($timelineCameraTitle) ?>
            </div>
          </div>
        <?php if (!empty($timelineNav) && !empty($timelineNav['has_data'])): ?>
          <?php
            $headings = [
              'year' => 'Jahr',
              'month' => 'Monat',
              'day' => 'Tag',
              'hour' => 'Stunde',
              'time' => 'Zeit',
            ];
          ?>
          <?php foreach ($headings as $key => $heading): ?>
            <?php $group = $timelineNav[$key] ?? null; ?>
            <?php if ($group): ?>
              <?php
                $groupClass = 'timeline-controls__group';
                $isTimeGroup = $key === 'time';
                $isYearGroup = $key === 'year';
                $isMonthGroup = $key === 'month';
                $isDayGroup = $key === 'day';
                $isHourGroup = $key === 'hour';
                $navModeKey = $key;
                if ($isTimeGroup) {
                  $groupClass .= ' timeline-controls__group--time';
                }
                if ($isYearGroup) {
                  $groupClass .= ' timeline-controls__group--year timeline-controls__group--time';
                }
                if ($isMonthGroup) {
                  $groupClass .= ' timeline-controls__group--month timeline-controls__group--time';
                }
                if ($isDayGroup) {
                  $groupClass .= ' timeline-controls__group--day timeline-controls__group--time';
                }
                if ($isHourGroup) {
                  $groupClass .= ' timeline-controls__group--hour timeline-controls__group--time';
                }
                $hasTimePicker = $isTimeGroup && !empty($timelineTimeOptions);
                $hasYearPicker = $isYearGroup && !empty($yearPickerOptions ?? []);
                $hasMonthPicker = $isMonthGroup && !empty($monthPickerOptions ?? []);
                $hasDayPicker = $isDayGroup && !empty($dayPickerOptions ?? []);
                $hasHourPicker = $isHourGroup && !empty($hourPickerOptions ?? []);
              ?>
              <?php $disabledGroup = !$interactive; ?>
              <?php
                $prevUrl = !empty($group['prev_url']) ? $appendNavParam((string) $group['prev_url'], (string) $navModeKey) : null;
                $nextUrl = !empty($group['next_url']) ? $appendNavParam((string) $group['next_url'], (string) $navModeKey) : null;
              ?>
              <div class="<?= htmlspecialchars($groupClass . ($disabledGroup ? ' is-disabled' : '')) ?>">
                <?php if (!empty($prevUrl)): ?>
                  <a
                    class="timeline-controls__button<?= $disabledGroup ? ' is-disabled' : '' ?>"
                    href="<?= htmlspecialchars($disabledGroup ? '#' : $prevUrl) ?>"
                    aria-label="Vorherige <?= htmlspecialchars($heading) ?>"
                    <?php if ($key === 'time'): ?>
                      data-prefetch-link="snapshot"
                      <?php if (isset($group['prev_timestamp']) && $group['prev_timestamp'] !== null): ?>
                        data-target-key="<?= (int) $group['prev_timestamp'] ?>"
                      <?php endif; ?>
                    <?php endif; ?>
                  >&lsaquo;</a>
                <?php else: ?>
                  <span class="timeline-controls__button is-disabled" aria-hidden="true">&lsaquo;</span>
                <?php endif; ?>
                <?php
                  $displayLabel = $group['selected_label'] ?? $group['label'] ?? $heading;
                ?>
                <?php if ($disabledGroup): ?>
                  <div class="timeline-controls__label"><?= htmlspecialchars((string) $displayLabel) ?></div>
                <?php elseif ($hasTimePicker): ?>
                  <button
                    type="button"
                    class="timeline-time-button"
                    data-time-picker-trigger
                    aria-haspopup="true"
                    aria-expanded="false"
                  >
                    <?= htmlspecialchars((string) $displayLabel) ?>
                  </button>
                <?php elseif ($hasYearPicker || $hasMonthPicker || $hasDayPicker || $hasHourPicker): ?>
                  <?php
                    $pickerAttr = 'data-time-picker-trigger';
                    if ($hasYearPicker) {
                      $pickerAttr = 'data-year-picker-trigger';
                    } elseif ($hasMonthPicker) {
                      $pickerAttr = 'data-month-picker-trigger';
                    } elseif ($hasDayPicker) {
                      $pickerAttr = 'data-day-picker-trigger';
                    } elseif ($hasHourPicker) {
                      $pickerAttr = 'data-hour-picker-trigger';
                    }
                  ?>
                  <div class="timeline-controls__dropdown" <?= $pickerAttr ?> tabindex="0" role="button" aria-haspopup="true" aria-expanded="false">
                    <?= htmlspecialchars((string) $displayLabel) ?>
                  </div>
                <?php else: ?>
                  <div class="timeline-controls__label"><?= htmlspecialchars((string) $displayLabel) ?></div>
                <?php endif; ?>
                <?php if (!empty($nextUrl)): ?>
                  <a
                    class="timeline-controls__button<?= $disabledGroup ? ' is-disabled' : '' ?>"
                    href="<?= htmlspecialchars($disabledGroup ? '#' : $nextUrl) ?>"
                    aria-label="Nächste <?= htmlspecialchars($heading) ?>"
                    <?php if ($key === 'time'): ?>
                      data-prefetch-link="snapshot"
                      <?php if (isset($group['next_timestamp']) && $group['next_timestamp'] !== null): ?>
                        data-target-key="<?= (int) $group['next_timestamp'] ?>"
                      <?php endif; ?>
                    <?php endif; ?>
                  >&rsaquo;</a>
                <?php else: ?>
                  <span class="timeline-controls__button is-disabled" aria-hidden="true">&rsaquo;</span>
                <?php endif; ?>

                <?php
                  $hasPickerPanel = $hasTimePicker || $hasYearPicker || $hasMonthPicker || $hasDayPicker || $hasHourPicker;
                  $panelAria = '';
                  if ($hasTimePicker) {
                    $panelAria = 'aria-label="Zeitauswahl"';
                  } elseif ($hasYearPicker) {
                    $panelAria = 'aria-label="Jahresauswahl"';
                  } elseif ($hasMonthPicker) {
                    $panelAria = 'aria-label="Monatsauswahl"';
                  } elseif ($hasDayPicker) {
                    $panelAria = 'aria-label="Tagesauswahl"';
                  } elseif ($hasHourPicker) {
                    $panelAria = 'aria-label="Stundenauswahl"';
                  }
                ?>

                <?php if ($hasPickerPanel && !$disabledGroup): ?>
                  <?php
                    $panelAttr = 'data-time-picker-panel';
                    if ($hasYearPicker) {
                      $panelAttr = 'data-year-picker-panel';
                    } elseif ($hasMonthPicker) {
                      $panelAttr = 'data-month-picker-panel';
                    } elseif ($hasDayPicker) {
                      $panelAttr = 'data-day-picker-panel';
                    } elseif ($hasHourPicker) {
                      $panelAttr = 'data-hour-picker-panel';
                    }
                  ?>
                  <div class="timeline-time-picker" <?= $panelAttr ?> <?= $panelAria ?>>
                    <?php if ($hasTimePicker): ?>
                      <ul>
                        <?php foreach ($timelineTimeOptions as $option): ?>
                          <?php
                            $optionUrl = $option['url'] ?? '#';
                            $optionClasses = 'timeline-time-picker__option' . (!empty($option['selected']) ? ' is-selected' : '');
                          ?>
                          <li>
                            <a
                              class="<?= $optionClasses ?>"
                              href="<?= htmlspecialchars((string) $optionUrl) ?>"
                              data-time-picker-option="1"
                            >
                              <?= htmlspecialchars((string) $option['label']) ?>
                            </a>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php elseif ($hasYearPicker): ?>
                      <ul class="timeline-time-picker__years">
                        <?php foreach ($yearPickerOptions as $option): ?>
                          <?php
                            $optionUrl = $appendNavParam($option['url'] ?? '#', 'year');
                            $optionClasses = 'timeline-time-picker__option' . (!empty($option['selected']) ? ' is-selected' : '');
                          ?>
                          <li>
                            <a
                              class="<?= $optionClasses ?>"
                              href="<?= htmlspecialchars((string) $optionUrl) ?>"
                              data-year-picker-option="1"
                            >
                              <?= htmlspecialchars((string) $option['label']) ?>
                            </a>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php elseif ($hasMonthPicker): ?>
                      <ul>
                        <?php foreach ($monthPickerOptions as $option): ?>
                          <?php
                            $optionUrl = $appendNavParam($option['url'] ?? '#', 'month');
                            $optionClasses = 'timeline-time-picker__option' . (!empty($option['selected']) ? ' is-selected' : '');
                          ?>
                          <li>
                            <a
                              class="<?= $optionClasses ?>"
                              href="<?= htmlspecialchars((string) $optionUrl) ?>"
                              data-month-picker-option="1"
                            >
                              <?= htmlspecialchars((string) $option['label']) ?>
                            </a>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php elseif ($hasDayPicker): ?>
                      <ul>
                        <?php foreach ($dayPickerOptions as $option): ?>
                          <?php
                            $optionUrl = $appendNavParam($option['url'] ?? '#', 'day');
                            $optionClasses = 'timeline-time-picker__option' . (!empty($option['selected']) ? ' is-selected' : '');
                          ?>
                          <li>
                            <a
                              class="<?= $optionClasses ?>"
                              href="<?= htmlspecialchars((string) $optionUrl) ?>"
                              data-day-picker-option="1"
                            >
                              <?= htmlspecialchars((string) $option['label']) ?>
                            </a>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php elseif ($hasHourPicker): ?>
                      <ul>
                        <?php foreach ($hourPickerOptions as $option): ?>
                          <?php
                            $optionUrl = $appendNavParam($option['url'] ?? '#', 'hour');
                            $optionClasses = 'timeline-time-picker__option' . (!empty($option['selected']) ? ' is-selected' : '');
                          ?>
                          <li>
                            <a
                              class="<?= $optionClasses ?>"
                              href="<?= htmlspecialchars((string) $optionUrl) ?>"
                              data-hour-picker-option="1"
                            >
                              <?= htmlspecialchars((string) $option['label']) ?>
                            </a>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="timeline-controls__group"><div class="timeline-controls__label">Lade Timeline …</div></div>
        <?php endif; ?>

        <div class="timeline-controls__group timeline-controls__group--latest<?= !$interactive && !$isPlaybackView ? ' is-disabled' : '' ?>">
          <?php if ($interactive || $isPlaybackView): ?>
            <a class="timeline-controls__latest" href="<?= htmlspecialchars($timelineLatestUrl ?: '#') ?>"<?= $isPlaybackView ? ' data-no-ajax="1"' : '' ?>>Aktuell</a>
          <?php else: ?>
            <span class="timeline-controls__latest is-disabled" aria-disabled="true">Aktuell</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php
  return (string) ob_get_clean();
};
$renderDayvideoInline = static function () use ($isPlaybackView): string {
  $renderPicker = static function (string $name, string $label, array $options, string $value, bool $hidden = false): void {
    $esc = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="dayvideo-inline__label dayvideo-picker" data-dayvideo-picker<?= $name === 'clock' ? ' data-dayvideo-clock-label' : '' ?><?= $hidden ? ' hidden' : '' ?>>
      <span><?= $esc($label) ?></span>
      <input type="hidden" data-dayvideo-<?= $esc($name) ?> value="<?= $esc($value) ?>">
      <button type="button" class="dayvideo-picker__trigger" data-dayvideo-picker-trigger aria-label="<?= $esc($label) ?>" aria-haspopup="listbox" aria-expanded="false"><?= $esc($options[$value]) ?> ▾</button>
      <div class="timeline-time-picker dayvideo-picker__panel" data-dayvideo-picker-panel role="listbox" aria-label="<?= $esc($label) ?>" hidden>
        <?php foreach ($options as $key => $text): ?>
          <button type="button" class="timeline-time-picker__option<?= (string) $key === $value ? ' is-selected' : '' ?>" data-dayvideo-picker-option="<?= $esc((string) $key) ?>" role="option" aria-selected="<?= (string) $key === $value ? 'true' : 'false' ?>"><?= $esc($text) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
  };
  $clockOptions = [];
  for ($minute = 0; $minute < 1440; $minute += 10) {
    $clock = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    $clockOptions[$clock] = $clock;
  }
  ob_start();
  ?>
      <div class="dayvideo-inline" data-dayvideo-inline hidden>
        <div class="dayvideo-inline__controls">
          <?php $renderPicker('fps', 'FPS', ['3' => '3 fps', '5' => '5 fps', '7' => '7 fps', '9' => '9 fps'], '5'); ?>
          <?php $renderPicker('width', 'Auflösung', ['1200' => '1200 px', '1600' => '1600 px'], '1200'); ?>
          <?php $renderPicker('period', 'Zeitraum', [
            'day' => 'Ganzer Tag', 'sunrise' => 'Sonnenaufgang', 'sunset' => 'Abend',
            'night' => 'Nacht', 'month' => 'Monatsfilm', 'year' => 'Jahresfilm',
          ], 'day'); ?>
          <?php $renderPicker('clock', 'Tägliche Uhrzeit', $clockOptions, '12:00', true); ?>
          <button type="button" class="button" data-dayvideo-reload>Video laden</button>
          <button type="button" class="button" data-dayvideo-refresh hidden>Neu erstellen</button>
          <a class="button" data-dayvideo-download hidden download>Download</a>
          <button type="button" class="button" data-dayvideo-close><?= $isPlaybackView ? 'Timelapse anzeigen' : 'Livebild anzeigen' ?></button>
        </div>
        <p class="dayvideo-inline__status" data-dayvideo-selection></p>
        <div class="dayvideo-inline__player" data-dayvideo-player>
          <video controls preload="metadata" width="100%" data-dayvideo-video></video>
        </div>
        <div class="dayvideo-inline__status" data-dayvideo-status>Video wird erstellt…</div>
      </div>
  <?php
  return (string) ob_get_clean();
};
