#!/usr/bin/python3
import importlib.util
import os
from pathlib import Path
import time
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('clock_sync', Path(__file__).resolve().parents[1] / 'scripts/camera-rpi-camera-clock.py')
clock = importlib.util.module_from_spec(spec)
spec.loader.exec_module(clock)


class ClockTests(unittest.TestCase):
    def test_local_encoding_summer_and_winter(self):
        with patch.dict(os.environ, TZ='Europe/Berlin'):
            time.tzset()
            for epoch, offset in [(1791536180, 7200), (1768042800, 3600)]:
                self.assertEqual(clock.local_stamp(epoch), epoch + offset)
        time.tzset()

    def test_unsynchronized_pi_never_touches_camera(self):
        with patch.object(clock, 'run', return_value='no\n') as run:
            with self.assertRaises(RuntimeError):
                clock.synchronize()
            self.assertEqual(run.call_count, 1)

    def test_readonly_field_rejected(self):
        with self.assertRaises(RuntimeError):
            clock.camera_time('Type: DATE\nReadonly: 1\nCurrent: 123\n')

    def test_check_does_not_write(self):
        with patch.object(clock, 'run', side_effect=['yes', 'Type: DATE\nReadonly: 0\nCurrent: 1791536180\n']) as run:
            clock.synchronize(check=True)
            self.assertEqual(run.call_count, 2)

    def test_write_uses_utc_and_checks_readback(self):
        output = 'Type: DATE\nReadonly: 0\nCurrent: 1791536180\n'
        with patch.object(clock.time, 'time', return_value=1791536180), patch.object(clock, 'run', side_effect=['yes', output, 'yes', '', output]) as run:
            clock.synchronize()
            self.assertTrue(run.call_args_list[3].kwargs['utc'])
            self.assertEqual(run.call_args_list[4].args[0], ['gphoto2', '--get-config', 'datetime'])

    def test_bad_readback_is_failure(self):
        output = 'Type: DATE\nReadonly: 0\nCurrent: 1791532580\n'
        with patch.object(clock.time, 'time', return_value=1791536180), patch.object(clock, 'run', side_effect=['yes', output, 'yes', '', output]):
            with self.assertRaises(RuntimeError):
                clock.synchronize()


if __name__ == '__main__':
    unittest.main()
