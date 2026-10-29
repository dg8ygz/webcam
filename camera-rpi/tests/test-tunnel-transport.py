#!/usr/bin/env python3
"""Real pinned Chisel + verified local TLS + harmless echo service; no live SSH."""
import argparse
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import threading
import time

p = argparse.ArgumentParser()
p.add_argument('--binary', required=True)
a = p.parse_args()

def freeport():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]

def reachable(port):
    try:
        with socket.create_connection(('127.0.0.1', port), timeout=.1): return True
    except OSError: return False

processes = []
with tempfile.TemporaryDirectory(prefix='camera-tunnel-transport-') as directory:
    root = Path(directory)
    os.chmod(root, 0o700)
    def launch(args, name, auth=None):
        handle = (root / name).open('w')
        child = subprocess.Popen([a.binary, *args], env={**os.environ, 'AUTH': auth or ''},
                                 stdout=handle, stderr=handle)
        handle.close()
        processes.append(child)
        return child
    try:
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-subj', '/CN=localhost', '-addext', 'subjectAltName=IP:127.0.0.1',
            '-keyout', str(root/'tls.key'), '-out', str(root/'tls.crt')], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        relay, reverse, echoport = freeport(), freeport(), freeport()
        auth = 'fixture:' + 'a'*40
        (root/'users.json').write_text(json.dumps({auth: ['^R:127\\.0\\.0\\.1:' + str(reverse) + '$']}))
        server = launch(['server', '--host', '127.0.0.1', '--port', str(relay), '--reverse', '--authfile', str(root/'users.json'),
            '--tls-key', str(root/'tls.key'), '--tls-cert', str(root/'tls.crt')], 'server.log')
        for _ in range(50):
            raw = (root/'server.log').read_text()
            found = re.search(r'Fingerprint ([A-Za-z0-9+/]{43}=)', raw)
            if found and reachable(relay): break
            time.sleep(.1)
        assert found, 'missing fingerprint'
        fingerprint = found[1]
        echo = socket.socket()
        echo.bind(('127.0.0.1', echoport))
        echo.listen()
        echo.settimeout(.2)
        done = threading.Event()
        def serve():
            while not done.is_set():
                try:
                    conn, _ = echo.accept()
                    with conn:
                        conn.settimeout(1)
                        data = conn.recv(1024)
                        if data: conn.sendall(data)
                except (OSError, TimeoutError): pass
        thread = threading.Thread(target=serve, daemon=True)
        thread.start()
        common = ['client', '--tls-ca', str(root/'tls.crt'), '--fingerprint', fingerprint,
                  '--max-retry-count', '0', f'https://127.0.0.1:{relay}']
        bad_fingerprint = [*common]
        bad_fingerprint[bad_fingerprint.index('--fingerprint')+1] = 'A'*43+'='
        denied = launch([*bad_fingerprint, f'R:127.0.0.1:{reverse}:127.0.0.1:{echoport}'], 'bad-pin', auth)
        denied.wait(timeout=8)
        assert not reachable(reverse) and 'Connected' not in (root/'bad-pin').read_text()
        # Wrong credentials and prohibited public binds must never open the port.
        for name, credential, remote in [('bad-auth', 'fixture:wrong', f'R:127.0.0.1:{reverse}:127.0.0.1:{echoport}'),
              ('public-bind', auth, f'R:0.0.0.0:{reverse}:127.0.0.1:{echoport}'),
              ('forward', auth, f'127.0.0.1:{reverse}:127.0.0.1:{echoport}')]:
            child = launch([*common, remote], name, credential)
            child.wait(timeout=8)
            assert not reachable(reverse), name + ' exposed a socket'
            assert 'Connected' not in (root/name).read_text(), name + ' accepted'
        child = launch([*common, f'R:127.0.0.1:{reverse}:127.0.0.1:{echoport}'], 'allowed', auth)
        for _ in range(50):
            if reachable(reverse): break
            time.sleep(.1)
        with socket.create_connection(('127.0.0.1', reverse), timeout=2) as conn:
            conn.sendall(b'camera-tunnel-test')
            assert conn.recv(1024) == b'camera-tunnel-test'
        child.terminate()
        child.wait(timeout=5)
        for _ in range(30):
            if not reachable(reverse): break
            time.sleep(.1)
        assert not reachable(reverse), 'reverse port survived client exit'
        done.set()
        echo.close()
        thread.join(timeout=1)
        print('OK: HTTPS verified, fingerprint/auth, reverse echo, public bind/forward rejected, port removed on disconnect.')
    finally:
        for child in processes:
            if child.poll() is None:
                child.terminate()
                try: child.wait(timeout=3)
                except subprocess.TimeoutExpired: child.kill(); child.wait()
