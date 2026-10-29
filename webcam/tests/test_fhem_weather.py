"""Regression cases for stale station readings, separate from fresh statistics."""
import importlib.util
from pathlib import Path
import subprocess
import sys
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'bin/weather_fhem.py'
spec = importlib.util.spec_from_file_location('weather_fhem', SCRIPT)
fhem = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fhem)
STAMP = '2026-10-17 12:00:00'
NOW = fhem.measurement_epoch(STAMP)


def page(stamps=None, values=None):
    stamps = stamps or {}
    readings = dict(temperature='8.9', humidity='80', dewpoint='5.64',
                    windSpeed='15.7', windDirection='145')
    readings.update(values or {})
    parts = []
    for name, value in readings.items():
        parts.append(f"<div informId='Wetterstation-{name}'>{value}</div>")
        parts.append(f'<div informId="Wetterstation-{name}-ts">{stamps.get(name, STAMP)}</div>')
    return ''.join(parts).encode()


class FhemWeatherTest(unittest.TestCase):
    def test_fresh_and_exact_age_limit(self):
        for age in (0, 600, 1800):
            self.assertEqual(fhem.weather(page(), NOW + age),
                             'Temp: 8.9 °C, Hum: 80 %, DewPt: 5.64 °C, Wind: 15.7 km/h, Windrichtung: SO\n')

    def test_live_fhem_unclosed_layout_div_does_not_hide_readings(self):
        self.assertEqual(fhem.weather(b'<div class="layout">' + page(), NOW),
                         fhem.weather(page(), NOW))

    def test_each_reading_must_be_fresh(self):
        for field in fhem.FIELDS:
            with self.subTest(field=field), self.assertRaises(ValueError):
                fhem.weather(page({field: '2026-10-13 04:38:34'}), NOW)
        with self.assertRaises(ValueError):
            fhem.weather(page(), NOW + 1801)

    def test_statistics_state_and_fetch_time_do_not_refresh_measurements(self):
        raw = page({field: '2026-10-13 04:38:34' for field in fhem.FIELDS})
        raw += (f'<div informId="Wetterstation-statTemperatureDay-ts">{STAMP}</div>'
                f'<div informId="Wetterstation-state-ts">{STAMP}</div>'
                '<div informId="Wetterstation-STATE">Temp: 20 C</div>').encode()
        with self.assertRaises(ValueError):
            fhem.weather(raw, NOW)

    def test_missing_duplicate_truncated_and_wrong_device(self):
        for raw in (b'', b'<html>login required</html>',
                    page().replace(b'Wetterstation-temperature-ts', b'Other-temperature-ts'),
                    page() + b'<div informId="Wetterstation-humidity">1</div>',
                    page()[:-6], b'x' * (fhem.MAX_BYTES + 1)):
            with self.subTest(raw_length=len(raw)), self.assertRaises(ValueError):
                fhem.weather(raw, NOW)

    def test_invalid_future_and_dst_times(self):
        for stamp in ('', 'today', '2026-02-30 12:00:00', '2026-10-17 12:00:01',
                      '2026-03-29 02:30:00', '2026-10-25 02:30:00'):
            with self.subTest(stamp=stamp), self.assertRaises(ValueError):
                fhem.weather(page({'humidity': stamp}), NOW)

    def test_local_timezone_in_winter_and_summer(self):
        from datetime import datetime, timezone
        for local, utc in [('2026-01-17 12:00:00', '2026-01-17T11:00:00'),
                           ('2026-07-17 12:00:00', '2026-07-17T10:00:00')]:
            self.assertEqual(fhem.measurement_epoch(local),
                             datetime.fromisoformat(utc).replace(tzinfo=timezone.utc).timestamp())

    def test_nonfinite_and_invalid_values(self):
        for name, value in [('temperature', 'nan'), ('windSpeed', 'inf'),
                            ('humidity', '101'), ('windDirection', '-1'),
                            ('temperature', 'secret-response')]:
            with self.subTest(name=name, value=value), self.assertRaises(ValueError):
                fhem.weather(page(values={name: value}), NOW)

    def test_cli_rejects_old_source_without_stdout_or_raw_response(self):
        result = subprocess.run([sys.executable, str(SCRIPT)],
                                input=page({field: '2026-10-13 04:38:34' for field in fhem.FIELDS}),
                                capture_output=True, timeout=3)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(result.stdout, b'')
        self.assertIn(b'reading stale or future: temperature', result.stderr)
        result = subprocess.run([sys.executable, str(SCRIPT)], input=b'secret-response',
                                capture_output=True, timeout=3)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(result.stdout, b'')
        self.assertNotIn(b'secret-response', result.stderr)


if __name__ == '__main__':
    unittest.main()
