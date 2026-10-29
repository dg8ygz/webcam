#!/usr/bin/env python3
"""Validate MJPEG framing/dimensions and discard bytes after EOI, no decoding.

Some UVC cameras include trailing buffer bytes in bytesused. These must not
reach the upload. Segment lengths matter: an APP thumbnail may contain EOI.
The worker owns the private partial file and retains its size/signature check.
"""
import sys


def jpeg_end(data: bytes, width: int, height: int) -> int:
    """Return the end of the outer image, skipping APP payloads and stuffed scan bytes.

    This checks framing and configured dimensions, not pixel-level decodability.
    """
    if data[:2] != b"\xff\xd8":
        raise ValueError("JPEG SOI fehlt")
    pos, in_scan, saw_frame, saw_scan = 2, False, False, False
    while pos < len(data):
        if in_scan:
            pos = data.find(b"\xff", pos)
            if pos < 0:
                break
        if data[pos] != 0xFF:
            raise ValueError("Ungueltiger JPEG-Marker")
        while pos < len(data) and data[pos] == 0xFF:
            pos += 1
        if pos >= len(data):
            break
        marker = data[pos]
        pos += 1
        if in_scan and (marker == 0 or 0xD0 <= marker <= 0xD7):
            continue
        in_scan = False
        if marker == 0xD9:
            if not (saw_frame and saw_scan):
                raise ValueError("JPEG ohne Bild-/Scandaten")
            return pos
        if marker in (0, 0xD8) or 0xD0 <= marker <= 0xD7:
            raise ValueError("Unerwarteter JPEG-Marker")
        if marker == 1:  # TEM, stand-alone marker
            continue
        if pos + 2 > len(data):
            break
        length = int.from_bytes(data[pos:pos + 2], "big")
        if length < 2 or pos + length > len(data):
            raise ValueError("Abgeschnittenes JPEG-Segment")
        if marker in (0xC0, 0xC1, 0xC2):
            if length < 8:
                raise ValueError("Ungueltiger JPEG-Bildheader")
            actual_h = int.from_bytes(data[pos + 3:pos + 5], "big")
            actual_w = int.from_bytes(data[pos + 5:pos + 7], "big")
            if (actual_w, actual_h) != (width, height):
                raise ValueError("JPEG-Aufloesung weicht von Konfiguration ab")
            saw_frame = True
        if marker == 0xDA:
            if not saw_frame or length < 6:
                raise ValueError("Ungueltiger JPEG-Scanheader")
            saw_scan, in_scan = True, True
        pos += length
    raise ValueError("JPEG EOI fehlt")


def main() -> int:
    """Bound the read and trim trailing UVC buffer bytes only after validation."""
    try:
        path, width, height, limit = sys.argv[1:]
        width, height, limit = int(width), int(height), int(limit)
        if not (16 <= width <= 4096 and 16 <= height <= 2160 and 1024 <= limit <= 104857600):
            raise ValueError("Ungueltige Bildgrenzen")
        with open(path, "r+b") as stream:
            data = stream.read(limit + 1)
            if len(data) > limit:
                raise ValueError("JPEG zu gross")
            end = jpeg_end(data, width, height)
            if end != len(data):
                stream.truncate(end)
        return 0
    except (OSError, ValueError) as error:
        print(f"MJPEG-Pruefung fehlgeschlagen: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
