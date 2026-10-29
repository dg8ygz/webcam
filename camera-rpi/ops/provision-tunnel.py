#!/usr/bin/env python3
"""Create NEW private enrolment files. Never replace existing credentials."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets

parser = argparse.ArgumentParser()
parser.add_argument('--target', required=True)
parser.add_argument('--url', required=True)
parser.add_argument('--fingerprint', required=True)
parser.add_argument('--port', required=True, type=int)
parser.add_argument('--output', required=True)
args = parser.parse_args()
spec = importlib.util.spec_from_file_location('tunnel', Path(__file__).resolve().parents[1] / 'scripts/camera-rpi-tunnel.py')
t = importlib.util.module_from_spec(spec)
spec.loader.exec_module(t)
t.identifier(args.target)
os.umask(0o077)
out = Path(args.output)
out.mkdir(mode=0o700)  # Must not exist; use private path outside a webroot.
# Separate maintenance secret, unrelated to the camera upload key. Validate with
# the same contract the Pi uses before producing the narrowly scoped relay ACL.
auth = args.target + ':' + secrets.token_urlsafe(32)
cfg = dict(url=args.url, fingerprint=args.fingerprint, auth=auth, port=args.port)
path = out / (args.target + '.json')
path.write_text(json.dumps(cfg, indent=2) + '\n')
try:
    t.config(path)
except ValueError:
    path.unlink()
    raise SystemExit('Ungueltige Konfiguration; keine nutzbaren Zugangsdaten erzeugt.')
# Chisel's reverse ACL checks the LISTEN address. The client fixes destination
# to its own SSH. A compromised Pi/root is outside this boundary.
acl = '^R:127\\.0\\.0\\.1:' + str(args.port) + '$'
(out / 'users.json').write_text(json.dumps({auth: [acl]}, indent=2) + '\n')
print('Private Registrierung erzeugt. Key nicht anzeigen oder ins Webroot kopieren.')
