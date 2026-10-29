#!/usr/bin/env python3
import pathlib
import subprocess
import unittest

SCRIPT = pathlib.Path(__file__).resolve().parents[1] / 'ops/build-tunnel-apache.py'


class ApacheTests(unittest.TestCase):
    def test_scope(self):
        for role, peer in [('origin', '192.0.2.2'), ('edge', '192.0.2.34')]:
            result = subprocess.run(['python3', '-B', str(SCRIPT), role, peer], capture_output=True, text=True, check=True).stdout
            self.assertEqual(result.count('<Location '), 1)
            self.assertIn('upgrade=websocket', result)
            self.assertNotIn('ProxyTimeout', result)
            self.assertNotIn('RewriteRule', result)
            self.assertNotIn('/webcam/', result)
            if role == 'edge':
                self.assertIn('%{HTTPS}', result)
                self.assertNotIn('ProxyErrorOverride', result)
                self.assertNotIn('AliasMatch', result)
            else:
                self.assertIn('CONN_REMOTE_ADDR', result)
                self.assertIn('http://127.0.0.1:9086/', result)
                self.assertIn("req('Upgrade') !~ m#(?i)^websocket$#", result)
                self.assertIn('no-proxy=1', result)
                self.assertIn('AliasMatch "^/camera-maintenance/.*$"', result)
                self.assertNotIn('ProxyErrorOverride', result)
                self.assertNotIn('ErrorDocument', result)
        for value in ['192.0.2.2\nRequire all granted', 'example.invalid', '192.0.2.2/24']:
            result = subprocess.run(['python3', '-B', str(SCRIPT), 'origin', value], capture_output=True)
            self.assertNotEqual(result.returncode, 0)


if __name__ == '__main__': unittest.main()
