"""Isolated boot guard tests: never modify the real network or service manager."""
import importlib.util
import pathlib
import subprocess
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('guard', pathlib.Path(__file__).parents[1] / 'ops/webcam-boot-guard.py')
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)


class BootGuardTest(unittest.TestCase):
    def test_address_and_route(self):
        with patch.object(guard, 'run', side_effect=[
            '[{"addr_info":[{"local":"192.0.2.34","scope":"global"}]}]',
            '[{"dev":"ens18"}]',
        ]):
            self.assertTrue(guard.network_ready())

    def test_no_address_never_queries_route(self):
        with patch.object(guard, 'run', return_value='[]') as run:
            self.assertFalse(guard.network_ready())
            self.assertEqual(run.call_count, 1)

    def test_wrong_route(self):
        with patch.object(guard, 'run', side_effect=[
            '[{"addr_info":[{"local":"192.0.2.34","scope":"global"}]}]',
            '[{"dev":"ens19"}]',
        ]):
            self.assertFalse(guard.network_ready())

    def test_probe_timeout(self):
        with patch.object(guard, 'run', side_effect=subprocess.TimeoutExpired('ip', 3)):
            self.assertFalse(guard.network_ready())

    def test_network_delayed(self):
        with patch.object(guard, 'network_ready', side_effect=[False, True]), patch.object(guard.time, 'sleep') as sleep:
            guard.wait_network()
            sleep.assert_called_once_with(1)

    def test_network_timeout(self):
        with self.assertRaises(RuntimeError):
            guard.wait_network(seconds=0)

    def test_respects_active_stopped_disabled(self):
        states = [
            {'ActiveState': 'active'},
            {'ActiveState': 'inactive', 'ActiveEnterTimestampMonotonic': '123', 'UnitFileState': 'enabled'},
            {'ActiveState': 'inactive', 'UnitFileState': 'disabled'},
            {'ActiveState': 'inactive', 'UnitFileState': 'masked'},
        ]
        for state in states:
            with self.subTest(state=state), patch.object(guard, 'apache_state', return_value=state), patch.object(guard, 'run') as run:
                guard.recover_apache()
                run.assert_not_called()

    def test_failed_mount_retry_then_success(self):
        with patch.object(guard, 'apache_state', side_effect=[
            {'ActiveState': 'inactive', 'UnitFileState': 'enabled'},
            {'ActiveState': 'failed', 'UnitFileState': 'enabled'},
            {'ActiveState': 'active'},
        ]), patch.object(guard, 'network_ready', side_effect=[False, True]), patch.object(guard, 'run') as run, patch.object(guard.time, 'sleep'):
            guard.recover_apache()
            run.assert_called_once_with('/usr/bin/systemctl', '--no-block', 'start', 'apache2.service')

    def test_recovery_timeout(self):
        with self.assertRaises(RuntimeError):
            guard.recover_apache(seconds=0)


if __name__ == '__main__':
    unittest.main()
