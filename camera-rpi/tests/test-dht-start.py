#!/usr/bin/env python3
"""Compile the actual C predicate, replay synthetic complete DHT frames."""
import ctypes
from pathlib import Path
import subprocess
import tempfile
import unittest


def frame(values):
    data = values + [sum(values) & 255]
    edges = [(0, 0), (80000, 1), (160000, 0)]
    t = 160000
    for byte in data:
        for shift in range(7, -1, -1):
            t += 51000
            edges.append((t, 1))
            t += 71000 if byte & (1 << shift) else 26000
            edges.append((t, 0))
    return edges


def decode(edges):
    if len(edges) < 82:
        return None
    for offset in range(2 + len(edges) - 83, -1, -1):
        bits = []
        for i in range(40):
            a, b = edges[offset + 2*i + 1:offset + 2*i + 3]
            if a[1] != 1:
                break
            bits.append(int(b[0] - a[0] > 49000))
        if len(bits) != 40:
            continue
        data = [sum(bits[8*j+k] << (7-k) for k in range(8)) for j in range(5)]
        if sum(data[:4]) & 255 != data[4]:
            continue
        if data[0] < 4 or (data[1] < 10 and data[3] < 10):
            return data[:4]
    return None


class StartTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        lib = str(Path(cls.temp.name) / 'predicate.so')
        subprocess.run(['cc', '-Wall', '-Wextra', '-Werror', '-shared', '-fPIC',
                        str(Path(__file__).with_name('dht-start-wrapper.c')), '-o', lib], check=True)
        cls.lib = ctypes.CDLL(lib)
        cls.drop = cls.lib.drop_start
        cls.drop.argtypes = [ctypes.c_int, ctypes.c_int, ctypes.c_int, ctypes.c_longlong]
        cls.drop.restype = ctypes.c_int

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def capture(self, edges, fixed=True):
        out = []
        for t, v in edges:
            if len(out) == 83:
                break
            if fixed and len(out) == 1 and self.drop(len(out), out[0][1], v, t-out[0][0]):
                out.clear()
            out.append((t, v))
        return out

    def test_valid_frames_unchanged(self):
        for values in ([2,70,0,234], [3,232,0,250], [1,244,128,50], [55,0,23,0]):
            with self.subTest(values=values):
                edges = frame(list(values))
                self.assertEqual(self.capture(edges), edges)
                self.assertEqual(decode(edges), list(values))

    def test_observed_release_lengths_preserve_complete_checksum(self):
        # Durations measured in the failed diagnostic frames; complete frames
        # below are synthetic, not reconstructed missing bits from those logs.
        for duration in (4537, 6778, 7889, 8482, 11519, 19999):
            with self.subTest(duration=duration):
                edges = [(-duration, 1)] + frame([2,70,0,234])
                self.assertIsNone(decode(self.capture(edges, fixed=False)))
                self.assertEqual(decode(self.capture(edges)), [2,70,0,234])

    def test_missing_first_edge_still_supported(self):
        edges = frame([2,70,0,234])[1:]
        self.assertEqual(self.capture(edges), edges)
        self.assertEqual(decode(edges), [2,70,0,234])

    def test_boundary_and_wrong_polarity_not_dropped(self):
        for args in ((1,1,0,20000), (1,1,0,80000), (1,1,0,0),
                     (1,1,0,-1), (2,1,0,8000), (1,0,1,8000), (1,1,1,8000)):
            self.assertFalse(self.drop(*args), args)

    def test_bad_checksum_and_truncated_frames_still_rejected(self):
        edges = frame([2,70,0,234])
        # Change only the final checksum pulse from zero to one.
        edges[-1] = (edges[-1][0] + 45000, 0)
        self.assertIsNone(decode(self.capture([(-8000,1)] + edges)))
        self.assertIsNone(decode(self.capture(frame([2,70,0,234])[:-2])))


if __name__ == '__main__':
    unittest.main()
