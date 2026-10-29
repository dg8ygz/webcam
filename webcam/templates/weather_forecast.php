<?php
declare(strict_types=1);
$esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<details id="weather-forecast" class="explore-view" data-prefix="<?= $esc($relativePrefix) ?>" data-timezone="<?= $esc($forecastTimezone) ?>">
  <summary>Vorhersage</summary>
  <section aria-label="DWD Wettervorhersage">
    <div class="explore-controls"><label>Standort<select id="weather-camera"><?php foreach ($forecastLocations as $forecastLocation): ?><option value="<?= $esc($forecastLocation['slug']) ?>"><?= $esc($forecastLocation['title']) ?></option><?php endforeach; ?></select></label><button type="button" id="weather-load">Wetter laden</button></div>
    <h2>Vorhersage · nächste 48 Stunden</h2>
    <p id="forecast-status" role="status" aria-live="polite">Standort wählen und Wetter laden.</p>
    <div id="forecast-charts" class="forecast-charts"></div>
    <p id="forecast-time" aria-live="polite"></p>
    <p class="explore-note">Stundenwerte direkt im Diagramm mit Maus, Berührung oder Pfeiltasten auswählen. Uhrzeiten: <?= $esc($forecastTimezone) ?>. Fehlende Messgrößen bleiben als Lücken sichtbar.</p>
    <p class="explore-note">Vorhersage: DWD MOSMIX, nächster Vorhersageort; keine Kameramessung. Datenquelle: <a href="https://www.dwd.de/">Deutscher Wetterdienst</a>, <a href="https://www.dwd.de/copyright">Nutzungsbedingungen / CC BY 4.0</a>. Messwerte der Kameras: <a href="#weather-measurements">Messwerte unten</a>.</p>
  </section>
</details>
