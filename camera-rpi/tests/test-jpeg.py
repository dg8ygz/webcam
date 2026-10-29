#!/usr/bin/env python3
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

HELPER = Path(__file__).resolve().parents[1] / "scripts/lib/camera-rpi-jpeg.py"
spec = importlib.util.spec_from_file_location("jpeg", HELPER)
jpeg = importlib.util.module_from_spec(spec)
spec.loader.exec_module(jpeg)


def segment(marker, payload):
    return bytes((255, marker)) + (len(payload) + 2).to_bytes(2, "big") + payload


FRAME = segment(0xC0, b"\x08\x04\x38\x07\x80\x03\x01\x11\x00\x02\x11\x00\x03\x11\x00")
SCAN = segment(0xDA, b"\x03\x01\x00\x02\x11\x03\x11\x00\x3f\x00")
IMAGE = b"\xff\xd8" + FRAME + SCAN + b"\x00" * 1024 + b"\xff\xd9"


class JpegTest(unittest.TestCase):
    def test_padding_removed_at_real_eoi(self):
        self.assertEqual(jpeg.jpeg_end(IMAGE + b"padding\xff\xd9", 1920, 1080), len(IMAGE))

    def test_embedded_thumbnail_not_mistaken_for_eoi(self):
        image = IMAGE[:2] + segment(0xE1, b"thumbnail\xff\xd9") + IMAGE[2:]
        self.assertEqual(jpeg.jpeg_end(image + b"padding", 1920, 1080), len(image))

    def test_stuffing_restart_fill_and_multiple_scans(self):
        image = IMAGE[:-2] + b"\xff\x00\xff\xd0\xff\xff\xd1" + SCAN + b"\x01\xff\xd9"
        self.assertEqual(jpeg.jpeg_end(image, 1920, 1080), len(image))

    def test_reject_malformed_and_truncated(self):
        for image in (b"", b"raw", IMAGE[:-2], IMAGE[:-1], b"\xff\xd8\xff\xd9",
                      b"\xff\xd8\xff\xe0\xff\xff", b"\xff\xd8\xff\xe0\x00\x01",
                      b"\xff\xd8\x01", b"\xff\xd8" + SCAN + b"\xff\xd9"):
            with self.subTest(image=image[:16]), self.assertRaises(ValueError):
                jpeg.jpeg_end(image, 1920, 1080)

    def test_reject_wrong_dimensions(self):
        with self.assertRaises(ValueError):
            jpeg.jpeg_end(IMAGE, 640, 480)

    def test_cli_size_limit_and_truncate(self):
        with tempfile.TemporaryDirectory(prefix="camera-rpi-jpeg-") as stage:
            path = Path(stage) / "image.part"
            original = IMAGE + b"padding"
            path.write_bytes(original)
            def run(limit):
                return subprocess.run(["/usr/bin/python3", "-B", str(HELPER), str(path),
                                       "1920", "1080", str(limit)], capture_output=True).returncode
            self.assertEqual(run(1024), 1)
            self.assertEqual(path.read_bytes(), original)
            self.assertEqual(run(2048), 0)
            self.assertEqual(path.read_bytes(), IMAGE)
            self.assertEqual(run(2048), 0)


if __name__ == "__main__":
    unittest.main()
