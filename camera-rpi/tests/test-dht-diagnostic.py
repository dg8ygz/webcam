#!/usr/bin/env python3
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('diagnose_dht', Path(__file__).resolve().parents[1] / 'ops/diagnose-dht.py')
diag = importlib.util.module_from_spec(spec)
spec.loader.exec_module(diag)

SOURCE = ('drivers/iio/humidity/dht11.c:90 [dht11]dht11_edges_print =_ "edges"\n'
          'drivers/iio/humidity/dht11.c:92 [dht11]dht11_edges_print =pmf "timings"\n'
          'drivers/other.c:12 [other]other_fn =p "unrelated"\n')


class DiagnosticTests(unittest.TestCase):
    def test_sites_only_dht(self):
        sites = diag.debug_sites(SOURCE)
        self.assertEqual(len(sites), 2)
        self.assertFalse(sites[0]['enabled'])
        self.assertTrue(sites[1]['enabled'])

    def test_malformed_and_empty(self):
        self.assertEqual(diag.debug_sites('unrelated malformed data'), [])

    def test_restore_only_added_print_flag(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory) / 'state.json'
            state.write_text(json.dumps({'control': str(diag.CONTROL_PATHS[0]),
                                         'sites': diag.debug_sites(SOURCE)}))
            real_read = Path.read_text
            def reader(path, *args, **kwargs):
                return SOURCE if path in diag.CONTROL_PATHS else real_read(path, *args, **kwargs)
            with patch.object(Path, 'read_text', reader), patch.object(diag, 'switch') as switch:
                diag.restore(state)
            self.assertEqual(switch.call_count, 1)
            self.assertEqual(switch.call_args.args[1]['line'], 90)
            self.assertFalse(switch.call_args.args[2])
            self.assertTrue((Path(directory) / 'RESTORED').exists())

    def test_restore_checks_result(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory) / 'state.json'
            state.write_text(json.dumps({'control': str(diag.CONTROL_PATHS[0]),
                                         'sites': diag.debug_sites(SOURCE)}))
            real_read = Path.read_text
            def reader(path, *args, **kwargs):
                return SOURCE.replace('=_', '=p') if path in diag.CONTROL_PATHS else real_read(path, *args, **kwargs)
            with patch.object(Path, 'read_text', reader), patch.object(diag, 'switch'):
                with self.assertRaises(RuntimeError):
                    diag.restore(state)
            self.assertFalse((Path(directory) / 'RESTORED').exists())

    def test_control_path_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory) / 'state.json'
            state.write_text(json.dumps({'control': '/etc/not-a-control', 'sites': []}))
            with self.assertRaises(ValueError):
                diag.restore(state)


if __name__ == '__main__':
    unittest.main()
