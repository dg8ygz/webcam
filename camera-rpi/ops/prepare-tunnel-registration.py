#!/usr/bin/env python3
"""First relay enrolment, offline apart from a temporary loopback listener."""
import argparse
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time

p = argparse.ArgumentParser()
p.add_argument('--binary', required=True)
p.add_argument('--target', required=True)
p.add_argument('--url', required=True)
p.add_argument('--port', type=int, required=True)
p.add_argument('--output', required=True)
a = p.parse_args()
os.umask(0o077)
out = Path(a.output)
if out.exists(): raise SystemExit('Ziel existiert bereits; keine Credentials ueberschrieben.')
# Generate a fresh relay key and briefly start a loopback-only server to read
# its public fingerprint. Empty ACL denies clients; always reap this probe child.
with tempfile.TemporaryDirectory(prefix='camera-tunnel-key-') as directory:
    tmp = Path(directory)
    subprocess.run([a.binary, 'server', '--keygen', str(tmp/'key')], check=True, stdout=subprocess.DEVNULL)
    (tmp/'users.json').write_text('{}')
    with (tmp/'log').open('w') as handle:
        child = subprocess.Popen([a.binary, 'server', '--host', '127.0.0.1', '--port', '0', '--keyfile', str(tmp/'key'),
            '--authfile', str(tmp/'users.json')], stdout=handle, stderr=handle)
    try:
        fingerprint = None
        for _ in range(50):
            match = re.search(r'Fingerprint ([A-Za-z0-9+/]{43}=)', (tmp/'log').read_text())
            if match:
                fingerprint = match[1]
                break
            time.sleep(.1)
        if not fingerprint: raise SystemExit('Kein Server-Fingerprint; Abbruch.')
    finally:
        child.terminate()
        try: child.wait(timeout=3)
        except subprocess.TimeoutExpired: child.kill(); child.wait()
    subprocess.run(['/usr/bin/python3', str(Path(__file__).with_name('provision-tunnel.py')),
        '--target', a.target, '--url', a.url, '--port', str(a.port), '--fingerprint', fingerprint,
        '--output', str(out)], check=True)
    (out/'key').write_bytes((tmp/'key').read_bytes())
    print('Registrierung bereit; nur die zielbezogene JSON-Datei geschuetzt auf den Pi uebertragen.')
    print('Oeffentlicher Tunnel-Fingerprint: ' + fingerprint)
