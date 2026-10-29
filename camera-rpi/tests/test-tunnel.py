#!/usr/bin/env python3
import importlib.util
import pathlib
import tempfile
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('tunnel', ROOT / 'scripts/camera-rpi-tunnel.py')
t = importlib.util.module_from_spec(spec)
spec.loader.exec_module(t)


class TunnelTests(unittest.TestCase):
    def test_headers_and_limits(self):
        value = 'a' * 32 + ':1800001800'
        self.assertEqual(t.lease(value, 1800000000), ('a' * 32, 1800001800))
        self.assertIsNone(t.lease(value, 1800001800))
        self.assertEqual(t.header_lease('HTTP/1.1 100 Continue\r\nX-Camera-Tunnel-Lease: bad\r\n\r\nHTTP/1.1 200 OK\r\nX-Camera-Tunnel-Lease: ' + value), value)
        for raw in ['HTTP/1.1 302 Found\nX-Camera-Tunnel-Lease: closed', 'HTTP/1.1 200 OK\nX-Camera-Tunnel-Lease: closed\nX-Camera-Tunnel-Lease: closed', 'x' * 16385]:
            with self.assertRaises(ValueError): t.header_lease(raw)
        for value in ['a'*32+':1800001801', 'open;id', 'http://evil', 'a'*32+':9999999999']:
            with self.assertRaises(ValueError): t.lease(value, 1800000000)
        for name in ['../x', 'x/y', 'x;id', 'x\n', '']:
            with self.assertRaises(ValueError): t.identifier(name)

    def test_process_has_only_fixed_target_and_no_argv_secret(self):
        cfg = {'url': 'https://example.invalid/camera-maintenance/', 'auth': 'camera:'+'s'*40, 'fingerprint': 'A'*43+'=', 'port': 22101}
        with patch.object(t.subprocess, 'Popen') as popen:
            t.Supervisor(cfg).start()
            argv = popen.call_args.args[0]
            self.assertNotIn(cfg['auth'], ' '.join(argv))
            self.assertEqual(argv[-1], 'R:127.0.0.1:22101:127.0.0.1:22')
            self.assertNotIn('--tls-skip-verify', argv)
            self.assertEqual(popen.call_args.kwargs['env']['AUTH'], cfg['auth'])

    def test_absolute_and_monotonic_expiry_replay_close_and_crash(self):
        class Child:
            def poll(self): return None
        calls = []
        def launch(): calls.append(1); return Child()
        s = t.Supervisor({}, launch)
        value = 'a'*32+':1800001800'
        with patch.object(t, 'stop_child') as stop:
            self.assertEqual(s.tick(value, 1800000000, 0), 'running')
            self.assertEqual(s.tick(value, 1800001000, 1000), 'running')
            self.assertEqual(len(calls), 1)
            # Backwards wall-clock shift cannot extend the 30 minute lifetime.
            self.assertEqual(s.tick(value, 1800001000, 1800), 'closed')
            self.assertEqual(s.tick(value, 1800001001, 1801), 'closed')
            self.assertEqual(len(calls), 1)
            self.assertEqual(s.tick('closed', 1800001002, 1802), 'closed')
            self.assertGreater(stop.call_count, 0)
            self.assertEqual(s.tick('b'*32+':1800002800', 1800001003, 1803), 'running')
            self.assertEqual(s.tick('closed', 1800001004, 1804), 'closed')

    def test_config_rejects_routes_and_credentials(self):
        import json
        cfg = {'url': 'https://example.invalid/camera-maintenance/', 'auth': 'camera:'+'s'*40, 'fingerprint': 'A'*43+'=', 'port': 22101}
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'config'
            path.write_text(json.dumps(cfg))
            self.assertEqual(t.config(path), cfg)
            for key, value in [('url', 'http://example.invalid/'), ('url', 'https://user:pass@example.invalid/'), ('port', 22), ('port', True), ('auth', 'x:short'), ('fingerprint', ''), ('target', '192.168.1.1:80')]:
                invalid = {**cfg, key: value}
                path.write_text(json.dumps(invalid))
                with self.assertRaises(ValueError): t.config(path)


if __name__ == '__main__': unittest.main()
