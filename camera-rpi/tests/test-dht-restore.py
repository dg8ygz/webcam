#!/usr/bin/env python3
"""Test restore function with shell mocks: never touches modules or services."""
from pathlib import Path
import subprocess
import tempfile
import unittest

RUNNER = Path(__file__).resolve().parents[1] / 'ops/dht-driver-debug/run-diagnostic.sh'


class RestoreTests(unittest.TestCase):
    def run_restore(self, observed):
        # Isolate the actual function, with a test-only directory prefix.
        source = RUNNER.read_text().split('restore_driver() {', 1)[1].split('\nif [[ ${1:-}', 1)[0]
        source = 'restore_driver() {' + source
        with tempfile.TemporaryDirectory(prefix='camera-rpi-dht-driver.') as directory:
            run = Path(directory)
            source = source.replace('/run/camera-rpi-dht-driver.*', '/tmp/camera-rpi-dht-driver.*')
            script = '''
set -u
flock() { return 0; }
timeout() { shift; "$@"; }
systemctl() { echo "$*" >> "$1_LOG"; return 0; }
modprobe() { return 0; }
cat() { case "$1" in */original.srcversion) echo ORIGINAL;; *) echo "$OBSERVED";; esac; }
'''.replace('"$1_LOG"', '"$LOG"')
            # On the test host no DHT module is used; all module operations
            # and sysfs-content reads are mocked regardless.
            script += source + '\nrestore_driver "$RUN"\n'
            import os
            env = dict(os.environ, RUN=directory, LOG=str(run/'calls'), OBSERVED=observed)
            result = subprocess.run(['bash', '-c', script], env=env, capture_output=True, text=True)
            calls = (run/'calls').read_text()
            return result.returncode, (run/'RESTORED').exists(), calls

    def test_matching_contents_restart_and_complete(self):
        code, marker, calls = self.run_restore('ORIGINAL')
        self.assertEqual(code, 0)
        self.assertTrue(marker)
        self.assertIn('start camera-rpi-sensor.service', calls)

    def test_failed_identity_still_restarts_sampling(self):
        code, marker, calls = self.run_restore('OTHER')
        self.assertNotEqual(code, 0)
        self.assertFalse(marker)
        self.assertIn('start camera-rpi-sensor.service', calls)

    def test_no_size_based_sysfs_comparison(self):
        self.assertNotIn('cmp -s "', RUNNER.read_text())


if __name__ == '__main__':
    unittest.main()
