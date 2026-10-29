#!/usr/bin/env python3
import importlib.util
import contextlib
import io
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location(
    "archive", Path(__file__).resolve().parents[1] / "scripts/camera-rpi-sensor-archive.py")
archive = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(archive)


class SensorArchiveTests(unittest.TestCase):
    def setUp(self):
        self.stage = tempfile.TemporaryDirectory()
        self.addCleanup(self.stage.cleanup)
        self.root = Path(self.stage.name)
        self.runtime = self.root / "run"
        self.runtime.mkdir()
        self.disk = self.root / "sd"
        self.now = 1789130000
        self.history = self.runtime / "history.tsv"
        self.stdout = contextlib.redirect_stdout(io.StringIO())
        self.stdout.__enter__()
        self.addCleanup(self.stdout.__exit__, None, None, None)

    def seed(self):
        self.history.write_text(f"{self.now - 30}\t21.4\t53.6\n")

    def test_roundtrip_and_no_current_status(self):
        self.seed()
        archive.backup(self.runtime, self.disk, self.now)
        self.history.unlink()
        archive.restore(self.runtime, self.disk, self.now, os.getgid())
        self.assertIn("21.4\t53.6", self.history.read_text())
        self.assertFalse((self.runtime / "current.status").exists())
        self.assertEqual(self.history.stat().st_mode & 0o777, 0o640)
        self.assertEqual(next(self.disk.iterdir()).stat().st_mode & 0o777, 0o600)

    def test_one_write_per_day(self):
        self.seed()
        archive.backup(self.runtime, self.disk, self.now)
        snapshot = next(self.disk.iterdir())
        before = snapshot.stat()
        archive.backup(self.runtime, self.disk, self.now + 30)
        self.assertEqual(before.st_ino, snapshot.stat().st_ino)
        self.assertEqual(before.st_mtime_ns, snapshot.stat().st_mtime_ns)

    def test_missing_or_invalid_history_preserves_backups(self):
        self.seed()
        archive.backup(self.runtime, self.disk, self.now)
        self.history.unlink()
        archive.backup(self.runtime, self.disk, self.now + 86400)
        self.assertEqual(len(list(self.disk.iterdir())), 1)

    def test_berlin_midnight_creates_next_daily_snapshot(self):
        now = int(archive.dt.datetime(2026, 9, 11, 21, 59, tzinfo=archive.dt.timezone.utc).timestamp())
        self.now = now
        self.seed()
        archive.backup(self.runtime, self.disk, now)
        archive.backup(self.runtime, self.disk, now + 6 * 60)
        self.assertEqual([p.name for p in archive.archive_files(self.disk)],
                         ["sensor-20260911.tsv", "sensor-20260912.tsv"])

    def test_bounded_restore_and_live_values_win(self):
        self.disk.mkdir()
        (self.disk / "sensor-20260910.tsv").write_text(
            f"{self.now - 3 * 86400}\t20\t50\n{self.now - 30}\t20\t50\n")
        self.seed()
        archive.restore(self.runtime, self.disk, self.now, os.getgid())
        self.assertEqual(self.history.read_text(), f"{self.now - 30}\t21.4\t53.6\n")

    def test_invalid_and_partial_lines(self):
        self.seed()
        with self.history.open("a") as stream:
            stream.write(f"{self.now}\tnan\t50\n{self.now}\t23\t101\n")
            stream.write(f"{self.now + 10000}\t22\t50\n")
            stream.write(f"{self.now}\t20\t50")
        self.assertEqual(len(archive.read_samples(self.history, self.now)), 1)

    def test_size_bound(self):
        self.seed()
        with patch.object(archive, "MAX_BYTES", 10):
            with self.assertRaises(ValueError):
                archive.backup(self.runtime, self.disk, self.now)
        self.assertFalse(self.disk.exists())

    def test_symlink_rejected(self):
        self.history.symlink_to(self.root / "missing")
        with self.assertRaises(ValueError):
            archive.backup(self.runtime, self.disk, self.now)

    def test_retention_only_owned_names(self):
        for day in range(32):
            self.now += 86400
            self.seed()
            archive.backup(self.runtime, self.disk, self.now)
        unrelated = self.disk / "operator-notes.txt"
        unrelated.write_text("keep")
        self.now += 86400
        self.seed()
        archive.backup(self.runtime, self.disk, self.now)
        self.assertEqual(len(archive.archive_files(self.disk)), 30)
        self.assertTrue(unrelated.exists())

    def test_failed_replace_leaves_old_file(self):
        self.seed()
        before = self.history.read_bytes()
        with patch.object(archive.os, "replace", side_effect=OSError("disk full")):
            with self.assertRaises(OSError):
                archive.atomic_write(self.history, {self.now: (10, 30)}, durable=True)
        self.assertEqual(self.history.read_bytes(), before)
        self.assertEqual(list(self.runtime.iterdir()), [self.history])

    def test_missing_archives_no_fake_data(self):
        archive.restore(self.runtime, self.disk, self.now, os.getgid())
        self.assertFalse(self.history.exists())


if __name__ == "__main__":
    unittest.main()
