<?php
declare(strict_types=1);

// Rendert die von weather.php aggregierten Tagesreihen. Die Closures formatieren
// Werte und wandeln zusammenhängende Messpunkte in SVG-Sparkline-Segmente um;
// Lücken bleiben sichtbar und werden nicht mit erfundenen Werten verbunden.

/** @var array<string,array<string,mixed>> $metrics */
/** @var list<string> $dayKeys */
/** @var int $days */
/** @var DateTimeImmutable $startDate */
/** @var DateTimeImmutable $endDate */
/** @var string $cameraBase */
/** @var string $heroText */
/** @var string $relativePrefix */

$metrics ??= [];
$selectedCamera ??= null;
$dayKeys ??= [];
$days ??= count($dayKeys);
$cameraBase ??= './camera/';
$dayOptions = [30, 60, 90, 180, 240, 360];
$formatValue = static function (?float $value, array $definition): string {
  if ($value === null) {
    return '-';
  }
  $precision = isset($definition['precision']) && is_numeric($definition['precision'])
    ? (int) $definition['precision']
    : 1;
  $unit = trim((string) ($definition['unit'] ?? ''));
  $formatted = number_format($value, $precision, ',', '');
  return $unit !== '' ? $formatted . ' ' . $unit : $formatted;
};

$sparklineSegments = static function (array $values, float $min, float $max): array {
  $count = count($values);
  if ($count === 0) {
    return [];
  }
  $range = $max - $min;
  if (abs($range) < 0.000001) {
    $range = 1.0;
  }

  $segments = [];
  $points = [];
  foreach ($values as $index => $value) {
    if ($value === null) {
      if ($points !== []) {
        $segments[] = $points;
        $points = [];
      }
      continue;
    }
    $x = $count > 1 ? ($index / ($count - 1)) * 100 : 0;
    $y = 58 - ((float) $value - $min) / $range * 52;
    $points[] = sprintf('%.3f,%.3f', $x, max(6.0, min(58.0, $y)));
  }
  if ($points !== []) {
    $segments[] = $points;
  }
  return $segments;
};

$formatDate = static fn(DateTimeImmutable $date): string => $date->format('d.m.Y');
$formatTimestamp = static function (mixed $timestamp): string {
  if (!is_numeric($timestamp)) {
    return '-';
  }
  return date('d.m.Y H:i', (int) $timestamp);
};
?>

<?php require __DIR__ . '/overview_header.php'; ?>

<?php if ($selectedCamera === null): ?>
  <?php require __DIR__ . '/weather_forecast.php'; ?>
<?php endif; ?>

<section class="weather-page" id="weather-measurements">
  <header class="weather-page__header">
    <div>
      <h1>Wetterdaten</h1>
      <?php if ($selectedCamera): ?><p><?= htmlspecialchars($selectedCamera->title()) ?></p><?php endif; ?>
      <p>
        <?= htmlspecialchars($formatDate($startDate)) ?> bis <?= htmlspecialchars($formatDate($endDate)) ?>,
        Tagesmittel aus den Kamera-Datenbanken.
      </p>
    </div>
    <div class="weather-page__meta">
      <form class="weather-page__range" method="get">
        <?php if ($selectedCamera): ?><input type="hidden" name="camera" value="<?= htmlspecialchars($selectedCamera->slug()) ?>"><?php endif; ?>
        <label class="sr-only" for="weather-days">Zeitraum</label>
        <select id="weather-days" name="days" onchange="this.form.submit()">
          <?php foreach ($dayOptions as $option): ?>
            <option value="<?= (int) $option ?>" <?= (int) $days === (int) $option ? 'selected' : '' ?>>
              <?= (int) $option ?> Tage
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <span><?= count($metrics) ?> Messarten</span>
    </div>
  </header>

  <?php if ($metrics === []): ?>
    <div class="weather-empty">
      Keine Wetterdaten im gewählten Zeitraum gefunden.
    </div>
  <?php endif; ?>

  <?php foreach ($metrics as $metricKey => $group): ?>
    <?php
      $definition = is_array($group['definition'] ?? null) ? $group['definition'] : [];
      $rows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
      $groupMin = isset($group['min']) && is_numeric($group['min']) ? (float) $group['min'] : 0.0;
      $groupMax = isset($group['max']) && is_numeric($group['max']) ? (float) $group['max'] : 1.0;
      $groupMid = ($groupMin + $groupMax) / 2;
      $scaleLabels = [
        $formatValue($groupMax, $definition),
        $formatValue($groupMid, $definition),
        $formatValue($groupMin, $definition),
      ];
    ?>
    <section class="weather-metric" data-weather-metric="<?= htmlspecialchars((string) $metricKey) ?>">
      <div class="weather-metric__title">
        <h2><?= htmlspecialchars((string) ($definition['label'] ?? $metricKey)) ?></h2>
        <span>
          <?= htmlspecialchars($formatValue($groupMin, $definition)) ?> bis
          <?= htmlspecialchars($formatValue($groupMax, $definition)) ?>
        </span>
      </div>

      <div class="weather-table-wrap">
        <table class="weather-table">
          <thead>
            <tr>
              <th>Kamera</th>
              <th>Verlauf</th>
              <th>Min</th>
              <th>Ø</th>
              <th>Max</th>
              <th>Zuletzt</th>
              <th>Tage</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php
                $values = is_array($row['values'] ?? null) ? $row['values'] : [];
                $segments = $sparklineSegments($values, $groupMin, $groupMax);
                $title = sprintf(
                  '%s: min %s, avg %s, max %s, zuletzt %s',
                  (string) ($row['camera_title'] ?? ''),
                  $formatValue(isset($row['min']) ? (float) $row['min'] : null, $definition),
                  $formatValue(isset($row['avg']) ? (float) $row['avg'] : null, $definition),
                  $formatValue(isset($row['max']) ? (float) $row['max'] : null, $definition),
                  $formatTimestamp($row['latest_ts'] ?? null)
                );
              ?>
              <tr>
                <th scope="row">
                  <a href="<?= htmlspecialchars($cameraBase . rawurlencode((string) ($row['camera_slug'] ?? ''))) ?>">
                    <?= htmlspecialchars((string) ($row['camera_title'] ?? '')) ?>
                  </a>
                </th>
                <td class="weather-chart-cell">
                  <div class="weather-chart">
                    <div class="weather-chart__scale" aria-hidden="true">
                      <?php foreach ($scaleLabels as $label): ?>
                        <span><?= htmlspecialchars($label) ?></span>
                      <?php endforeach; ?>
                    </div>
                    <svg class="weather-sparkline" viewBox="0 0 100 64" preserveAspectRatio="none" role="img" aria-label="<?= htmlspecialchars($title) ?>">
                      <line x1="0" y1="6" x2="100" y2="6" class="weather-sparkline__grid" />
                      <line x1="0" y1="32" x2="100" y2="32" class="weather-sparkline__grid" />
                      <line x1="0" y1="58" x2="100" y2="58" class="weather-sparkline__axis" />
                      <?php foreach ($segments as $points): ?>
                        <?php if (count($points) === 1): ?>
                          <?php [$x, $y] = explode(',', $points[0], 2); ?>
                          <circle cx="<?= htmlspecialchars($x) ?>" cy="<?= htmlspecialchars($y) ?>" r="0.9" class="weather-sparkline__line" />
                        <?php else: ?>
                          <polyline points="<?= htmlspecialchars(implode(' ', $points)) ?>" class="weather-sparkline__line" />
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </svg>
                  </div>
                </td>
                <td><?= htmlspecialchars($formatValue(isset($row['min']) ? (float) $row['min'] : null, $definition)) ?></td>
                <td><?= htmlspecialchars($formatValue(isset($row['avg']) ? (float) $row['avg'] : null, $definition)) ?></td>
                <td><?= htmlspecialchars($formatValue(isset($row['max']) ? (float) $row['max'] : null, $definition)) ?></td>
                <td>
                  <span><?= htmlspecialchars($formatValue(isset($row['latest']) ? (float) $row['latest'] : null, $definition)) ?></span>
                  <small><?= htmlspecialchars($formatTimestamp($row['latest_ts'] ?? null)) ?></small>
                </td>
                <td>
                  <?= (int) ($row['day_count'] ?? 0) ?>
                  <small><?= (int) ($row['sample_count'] ?? 0) ?> Werte</small>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endforeach; ?>
</section>
