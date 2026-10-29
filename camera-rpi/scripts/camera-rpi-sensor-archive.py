#!/usr/bin/env python3
"""Bounded daily sensor snapshots; never restores a stale current.status."""
import argparse
import datetime as dt
import grp
import math
import os
from pathlib import Path
import re
import tempfile
import time
from zoneinfo import ZoneInfo

MAX_BYTES = 16 * 1024 * 1024
ARCHIVE_NAME = re.compile(r"sensor-\d{8}\.tsv\Z")


def read_samples(path, now):
    """Read bounded plausible TSV rows, ignoring incomplete concurrent appends.

    Missing history is empty; reject symlinks/oversize and deduplicate by epoch.
    """
    if path.is_symlink():
        raise ValueError(f"Symlink rejected: {path}")
    try:
        with path.open("rb") as stream:
            raw = stream.read(MAX_BYTES + 1)
    except FileNotFoundError:
        return {}
    if len(raw) > MAX_BYTES:
        raise ValueError(f"History too large: {path}")
    samples = {}
    for line in raw.splitlines(keepends=True):
        # Ignore a concurrent, not yet complete append.
        if not line.endswith(b"\n"):
            continue
        try:
            stamp, temperature, humidity = line.decode("ascii").split()
            if not stamp.isdigit():
                continue
            epoch, temp, hum = int(stamp), float(temperature), float(humidity)
            if (now - 7 * 86400 <= epoch <= now + 300
                    and math.isfinite(temp) and -40 <= temp <= 80
                    and math.isfinite(hum) and 0 <= hum <= 100):
                samples[epoch] = (temp, hum)
        except (ValueError, UnicodeError):
            continue
    return samples


def atomic_write(path, samples, durable=False, group=None):
    """Replace a sorted snapshot atomically; fsync file and directory only for SD backup."""
    fd, temporary = tempfile.mkstemp(prefix=".sensor-", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="ascii") as stream:
            os.fchmod(stream.fileno(), 0o640 if group is not None else 0o600)
            if group is not None:
                os.fchown(stream.fileno(), -1, group)
            for stamp, (temp, humidity) in sorted(samples.items()):
                stream.write(f"{stamp}\t{temp:.1f}\t{humidity:.1f}\n")
            stream.flush()
            if durable:
                os.fsync(stream.fileno())
        os.replace(temporary, path)
        if durable:
            sync_directory(path.parent)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def sync_directory(path):
    """Persist directory metadata after publishing or pruning a durable snapshot."""
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def archive_files(root):
    """List only the daily snapshot naming scheme; leave unrelated archive files alone."""
    return sorted(p for p in root.iterdir() if ARCHIVE_NAME.fullmatch(p.name))


def backup(runtime, archive, now):
    """Write at most one valid snapshot per Berlin calendar day, retaining 30 copies."""
    samples = read_samples(runtime / "history.tsv", now)
    if not samples:
        print("Keine gueltigen Messwerte; bestehende Sicherungen bleiben erhalten.")
        return
    archive.mkdir(mode=0o700, parents=True, exist_ok=True)
    day = dt.datetime.fromtimestamp(now, ZoneInfo("Europe/Berlin")).strftime("%Y%m%d")
    destination = archive / f"sensor-{day}.tsv"
    if destination.exists():
        if not read_samples(destination, now):
            raise ValueError("Heutige Sicherung ist leer/ungueltig; manuell pruefen")
        print("Heute bereits gesichert; keine erneuten SD-Schreibzugriffe.")
        return
    atomic_write(destination, samples, durable=True)
    # Retain 30 successful daily snapshots, not just 30 calendar days.
    for old in archive_files(archive)[:-30]:
        old.unlink()
    sync_directory(archive)
    print(f"{len(samples)} Messwerte dauerhaft gesichert: {destination}")


def restore(runtime, archive, now, group):
    """Merge recent snapshots into 48h RAM history; never restore current sensor state."""
    samples = {}
    if archive.exists():
        for path in archive_files(archive)[-30:]:
            try:
                samples.update(read_samples(path, now))
            except ValueError as error:
                print(f"WARNUNG: {error}")
    # Live values win over older snapshots; only the last 48 hours are restored.
    samples.update(read_samples(runtime / "history.tsv", now))
    samples = {t: value for t, value in samples.items() if t >= now - 48 * 3600}
    if samples:
        runtime.mkdir(mode=0o750, parents=True, exist_ok=True)
        atomic_write(runtime / "history.tsv", samples, group=group)
    print(f"{len(samples)} historische Messwerte im RAM; kein aktueller Sensorstatus restauriert.")


def main():
    """Dispatch backup/restore; the shell wrapper owns locking and the root-rw window."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("backup", "restore"))
    parser.add_argument("--runtime", type=Path, default=Path("/run/camera-rpi-status"))
    parser.add_argument("--archive", type=Path, default=Path("/var/lib/camera-rpi/sensor-backup"))
    args = parser.parse_args()
    for path in (args.runtime, args.archive):
        if path.is_symlink():
            raise ValueError(f"Symlink rejected: {path}")
    if args.action == "backup":
        backup(args.runtime, args.archive, int(time.time()))
    else:
        restore(args.runtime, args.archive, int(time.time()), grp.getgrnam("www-data").gr_gid)


if __name__ == "__main__":
    main()
