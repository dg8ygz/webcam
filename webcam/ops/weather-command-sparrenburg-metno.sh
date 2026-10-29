#!/bin/bash
set -euo pipefail

# Weather command for the Admin UI field "Wetter -> Befehle / Skript".
# Data source: MET Norway Locationforecast API, no API key.
# The values are model values for the camera coordinate, not a local station.

LAT="${LAT:-52.014884}"
LON="${LON:-8.526911}"
SOURCE_URL="www.met.no"
USER_AGENT="${WEBCAM_WEATHER_USER_AGENT:-webcam-sparrenburg/1.0 https://webcam.example.invalid}"

# Request only A records: the local dual-stack resolver intermittently stalls
# on AAAA. Keep the existing deadlines and TLS checks; never pin an API IP.
# Append curl-owned numeric diagnostics after the body, then remove them before
# parsing. On failure publish only these bounded fields, never response bodies,
# raw URLs or headers. A nonzero exit makes WeatherCommandRunner log the error
# while the already stored camera image remains valid.
if response="$(
  curl --ipv4 -fsS \
    --connect-timeout 3 \
    --max-time 8 \
    -H "User-Agent: ${USER_AGENT}" \
    -H "Accept: application/json" \
    --write-out '\nhttp=%{http_code} dns=%{time_namelookup} connect=%{time_connect} total=%{time_total}' \
    "https://api.met.no/weatherapi/locationforecast/2.0/compact?lat=${LAT}&lon=${LON}" \
    2>/dev/null
)"; then
  curl_rc=0
else
  curl_rc=$?
fi

metrics="${response##*$'\n'}"
if [[ ! "$metrics" =~ ^http=[0-9]{3}\ dns=[0-9]+([.][0-9]+)?\ connect=[0-9]+([.][0-9]+)?\ total=[0-9]+([.][0-9]+)?$ ]] || (( ${#metrics} > 160 )); then
  metrics='metrics=unavailable'
fi
if (( curl_rc != 0 )); then
  printf 'weather model: fetch failed curl_exit=%s %s\n' "$curl_rc" "$metrics" >&2
  exit "$curl_rc"
fi
json="${response%$'\n'*}"

WEATHER_JSON="$json" php <<'PHP'
<?php
declare(strict_types=1);

$raw = getenv('WEATHER_JSON');
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
  fwrite(STDERR, "weather model: invalid JSON\n");
  exit(2);
}

$series = $data['properties']['timeseries'][0] ?? null;
$details = is_array($series) ? ($series['data']['instant']['details'] ?? null) : null;
if (!is_array($series) || !is_array($details)) {
  fwrite(STDERR, "weather model: missing details\n");
  exit(2);
}

$temp = requireNumber($details, 'air_temperature');
$press = requireNumber($details, 'air_pressure_at_sea_level');
$windKmh = requireNumber($details, 'wind_speed') * 3.6;
$windDeg = requireNumber($details, 'wind_from_direction');
$time = requireString($series, 'time');

printf(
  "T:%sC P:%smBar W:%skm/h %s\nQuelle:www.met.no %s\n",
  fmt($temp),
  fmt($press),
  fmt($windKmh),
  windLabel($windDeg),
  localTimeLabel($time)
);

function requireNumber(array $data, string $key): float
{
  if (!array_key_exists($key, $data) || !is_numeric($data[$key])) {
    fwrite(STDERR, "weather model: missing numeric field {$key}\n");
    exit(2);
  }
  return (float) $data[$key];
}

function requireString(array $data, string $key): string
{
  if (!array_key_exists($key, $data) || !is_string($data[$key]) || trim($data[$key]) === '') {
    fwrite(STDERR, "weather model: missing string field {$key}\n");
    exit(2);
  }
  return trim($data[$key]);
}

function fmt(float $value): string
{
  $rounded = round($value, 1);
  return number_format($rounded, abs($rounded - round($rounded)) < 0.05 ? 0 : 1, '.', '');
}

function localTimeLabel(string $time): string
{
  try {
    $date = new DateTimeImmutable($time);
  } catch (Throwable) {
    return $time;
  }
  $local = $date->setTimezone(new DateTimeZone('Europe/Berlin'));
  return $local->format('G:i') . ' Uhr';
}

function windLabel(float $degrees): string
{
  $labels = ['N', 'NNO', 'NO', 'ONO', 'O', 'OSO', 'SO', 'SSO', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
  $index = ((int) floor(fmod($degrees + 11.25, 360.0) / 22.5)) % 16;
  return $labels[$index];
}
PHP
