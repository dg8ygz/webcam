#!/usr/bin/env python3
import importlib.util
import pathlib
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('weather', pathlib.Path(__file__).resolve().parents[1] / 'scripts/lib/camera-rpi-weather.py')
w = importlib.util.module_from_spec(spec)
spec.loader.exec_module(w)


class WeatherTests(unittest.TestCase):
    def test_sample_freshness_and_ranges(self):
        data = dict(source='dht22', measured_at=1800000000, captured_at=1800000030, temperature_c=23.3, humidity_percent=61.5)
        # A backlog retains the original sample, not the current sensor value.
        self.assertEqual(w.validate(data, 1800000030, 1800080000), data)
        for key, value in [('measured_at', 1799999800), ('measured_at', 1800000040), ('temperature_c', float('nan')), ('humidity_percent', 101), ('temperature_c', True), ('source', 'shell'), ('command', 'id')]:
            with self.assertRaises(ValueError): w.validate({**data, key: value}, 1800000030, 1800000030)
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'status'
            path.write_text('sensor_state=ok\nupdated_epoch=1800000000\ntemperature_c=23.3\nhumidity_percent=61.5\n')
            self.assertEqual(w.snapshot(path, 1800000030, 1800000030), data)
            with self.assertRaises(ValueError): w.snapshot(path, 1800000300, 1800000300)
            path.write_text('sensor_state=unavailable\n')
            self.assertIsNone(w.snapshot(path, 1800000030, 1800000030))


if __name__ == '__main__': unittest.main()
