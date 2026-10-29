#!/usr/bin/env python3
"""Allowlisted update bundle, no config/keys/runtime or Git working-tree sweep."""
import hashlib
from pathlib import Path
import tarfile
import tempfile
import os

root = Path(__file__).resolve().parents[1]
files = [
    'apache/tunnel-landing.html',
    'MAINTENANCE-TUNNEL.md', 'LOCAL-WEATHER.md', 'scripts/camera-rpi-worker',
    'scripts/lib/camera-rpi-common.sh', 'scripts/lib/camera-rpi-jpeg.py',
    'scripts/lib/camera-rpi-weather.py', 'scripts/camera-rpi-tunnel.py',
    'systemd/camera-rpi-tunnel@.service', 'systemd/camera-rpi-tunnel-server.service',
    'systemd/camera-rpi-maintenance-tmpfiles.conf',
    'ops/fetch-chisel.sh', 'ops/install-chisel.sh', 'ops/provision-tunnel.py',
    'ops/prepare-tunnel-registration.py', 'ops/build-tunnel-apache.py',
    'ops/install-tunnel-edge.sh', 'ops/install-tunnel-server.sh',
    'ops/update-maintenance-weather.sh', 'ops/enroll-tunnel-pi.sh',
    'tests/test-tunnel.py', 'tests/test-tunnel-apache.py', 'tests/test-local-weather.py',
    'tests/test-tunnel-transport.py',
]
with tempfile.TemporaryDirectory(prefix='camera-maintenance-bundle-') as temporary:
    stage = Path(temporary)
    lines = []
    for name in files:
        source = root / name
        assert source.is_file() and not source.is_symlink()
        data = source.read_bytes()
        dest = stage / name
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(data)
        dest.chmod(0o644)
        lines.append(hashlib.sha256(data).hexdigest() + '  ' + name)
    (stage/'SHA256SUMS').write_text('\n'.join(lines)+'\n')
    archive = stage/'update.tar.gz'
    with tarfile.open(archive, 'w:gz') as tar:
        for name in [*files, 'SHA256SUMS']:
            tar.add(stage/name, arcname=name, recursive=False)
    data = archive.read_bytes()
    digest = hashlib.sha256(data).hexdigest()
    output = Path('/tmp') / ('camera-rpi-maintenance-weather-' + digest[:12] + '.tar.gz')
    with output.open('xb') as handle:
        handle.write(data)
    output.chmod(0o644)
    print(str(output))
    print('SHA256 ' + digest)
