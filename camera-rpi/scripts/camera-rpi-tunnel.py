#!/usr/bin/env python3
"""Opt-in HTTPS maintenance. Fixed SSH target; root-provisioned connection only.

receive/report run in the root worker. run uses a separate systemd DynamicUser
and LoadCredential. No credential is ever returned to HTTP, argv or a logfile.
"""
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
import time
from urllib.parse import urlsplit

CONFIG = Path('/etc/camera-rpi/maintenance')
REQUESTS = Path('/run/camera-rpi-maintenance')
CHISEL = '/usr/local/bin/chisel'
PROTOCOL = 'camera-rpi-tunnel-v1'


def identifier(value):
    """Validate a target ID before using it in configuration/runtime paths."""
    if not re.fullmatch(r'[a-z0-9]+(?:-[a-z0-9]+)*', value) or len(value) > 64:
        raise ValueError('target')
    return value


def load_json(path, limit=4096):
    """Read bounded JSON; reject duplicate fields instead of silently overriding."""
    with path.open('rb') as handle:
        raw = handle.read(limit + 1)
    if len(raw) > limit:
        raise ValueError('size')
    def unique(pairs):
        data = {}
        for key, value in pairs:
            if key in data:
                raise ValueError('duplicate key')
            data[key] = value
        return data
    return json.loads(raw, object_pairs_hook=unique)


def config(path):
    """Validate the provisioned HTTPS peer, pinned key and loopback port.

    Returns credentials for internal use only; never log the returned object.
    File ownership is checked by the caller or systemd LoadCredential.
    """
    data = load_json(path)
    if not isinstance(data, dict) or set(data) != {'url', 'fingerprint', 'auth', 'port'}:
        raise ValueError('config fields')
    if not isinstance(data['url'], str) or not re.fullmatch(r'https://[A-Za-z0-9.-]+(?::443)?/[A-Za-z0-9/_-]+', data['url']):
        raise ValueError('HTTPS endpoint')
    url = urlsplit(data['url'])
    if url.scheme != 'https' or not url.hostname or url.username or url.query or url.fragment:
        raise ValueError('HTTPS endpoint')
    if not isinstance(data['fingerprint'], str) or not re.fullmatch(r'[A-Za-z0-9+/]{43}=', data['fingerprint']):
        raise ValueError('server fingerprint')
    if not isinstance(data['auth'], str) or not re.fullmatch(r'[a-z0-9-]{1,64}:[A-Za-z0-9_-]{32,128}', data['auth']):
        raise ValueError('separate tunnel credential')
    if type(data['port']) is not int or not 22000 <= data['port'] <= 22999:
        raise ValueError('loopback port')
    return data


def lease(value, now):
    """Return (lease ID, expiry), or None for closed/expired; reject >30 minutes."""
    if value == 'closed':
        return None
    match = re.fullmatch(r'([a-f0-9]{32}):([0-9]{10})', value)
    if not match:
        raise ValueError('lease format')
    end = int(match[2])
    if end <= now:
        return None
    if end > now + 1800:
        raise ValueError('lease duration')
    return match[1], end


def header_lease(raw):
    """Extract one lease from the final HTTP 200 header block, defaulting closed."""
    if len(raw) > 16384:
        raise ValueError('headers size')
    result = []
    status = ''
    for line in raw.splitlines():
        if line.startswith('HTTP/'):
            result = []
            parts = line.split()
            status = parts[1] if len(parts) > 1 else ''
        elif line.lower().startswith('x-camera-tunnel-lease:'):
            result.append(line.split(':', 1)[1].strip())
    if status != '200' or len(result) > 1:
        raise ValueError('response')
    return result[0] if result else 'closed'


def atomic(path, value):
    """Publish non-secret RAM state atomically for readers in another service."""
    tmp = path.with_name(path.name + '.tmp.' + str(os.getpid()))
    fd = os.open(tmp, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o644)
    try:
        with os.fdopen(fd, 'w') as handle:
            handle.write(value)
            os.fchmod(handle.fileno(), 0o644)
        os.replace(tmp, path)
    finally:
        tmp.unlink(missing_ok=True)


def receive(target, headers):
    """Root worker: validate poll headers and publish an immutable-deadline lease.

    Invalid input closes the request before raising; a repeated lease cannot
    extend its expiry. No tunnel process is started in the capture worker.
    """
    path = REQUESTS / (target + '.request')
    try:
        with open(headers, 'r') as handle:
            value = header_lease(handle.read(16385))
        requested = lease(value, int(time.time()))
        if requested is None:
            value = 'closed'
    except (ValueError, OSError):
        atomic(path, 'closed')
        raise
    # Repeated polls cannot change the deadline of an already received lease.
    if path.exists():
        previous = path.read_text()[:128]
        if requested and previous.startswith(requested[0] + ':') and previous != value:
            raise ValueError('lease changed')
        if previous == value:
            return
    atomic(path, value)


def report(target):
    """Return a fresh supervisor state, not proof of a working SSH connection."""
    # DynamicUser-owned status is non-secret. Freshness proves supervisor is alive,
    # not that a client has connected through SSH.
    data = load_json(Path('/run/camera-rpi-tunnel-' + target) / 'status')
    now = int(time.time())
    if type(data.get('updated_at')) is not int or not 0 <= now - data['updated_at'] <= 90:
        raise ValueError('stale supervisor')
    if data.get('state') not in ('closed', 'running', 'error'):
        raise ValueError('supervisor state')
    return data['state']


def stop_child(child):
    """Terminate the dedicated child process group, with bounded kill fallback."""
    if child and child.poll() is None:
        os.killpg(child.pid, signal.SIGTERM)
        try:
            child.wait(timeout=2)
        except subprocess.TimeoutExpired:
            os.killpg(child.pid, signal.SIGKILL)
            child.wait(timeout=2)


class Supervisor:
    """Own one Chisel child; enforce both wall-clock and monotonic lease expiry."""

    def __init__(self, cfg, launch=None):
        self.cfg = cfg
        self.launch = launch or self.start
        self.child = None
        self.current = None
        self.deadline = 0
        self.attempted = False

    def start(self):
        """Launch only the fixed reverse SSH mapping; credentials stay out of argv."""
        # Dedicated DynamicUser isolates AUTH from other camera users/services.
        env = {'PATH': '/usr/bin:/bin', 'AUTH': self.cfg['auth']}
        return subprocess.Popen([CHISEL, 'client', '--fingerprint', self.cfg['fingerprint'],
            '--keepalive', '25s', '--max-retry-interval', '10s', self.cfg['url'],
            f"R:127.0.0.1:{self.cfg['port']}:127.0.0.1:22"], env=env,
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            start_new_session=True)

    def tick(self, value, now, monotonic):
        """Reconcile the lease once; never restart a failed child within that lease."""
        requested = lease(value, now)
        if requested != self.current:
            # Same ID with a modified deadline is not a new authorisation.
            if requested and self.current and requested[0] == self.current[0]:
                requested = self.current
            else:
                self.close()
                self.current = requested
                self.deadline = monotonic + (requested[1] - now if requested else 0)
                self.attempted = False
        if not requested or now >= requested[1] or monotonic >= self.deadline:
            self.close()
            return 'closed'
        if not self.attempted:
            self.attempted = True
            self.child = self.launch()
        return 'running' if self.child and self.child.poll() is None else 'error'

    def close(self):
        """Stop the child without forgetting the lease's prior launch attempt."""
        stop_child(self.child)
        self.child = None


def run(target):
    """DynamicUser service loop: enforce leases independently of camera polling.

    Publish non-secret status in RAM and close the child on malformed requests,
    shutdown or expiry. A living child is reported as running, not connected.
    """
    cfg = config(Path(os.environ['CREDENTIALS_DIRECTORY']) / 'tunnel.json')
    supervisor = Supervisor(cfg)
    running = True
    def finish(*_):
        nonlocal running
        running = False
    signal.signal(signal.SIGTERM, finish)
    signal.signal(signal.SIGINT, finish)
    status_path = Path(os.environ['RUNTIME_DIRECTORY']) / 'status'
    last_state = None
    last_write = 0
    try:
        while running:
            now = time.time()
            try:
                path = REQUESTS / (target + '.request')
                if path.exists():
                    with path.open() as handle:
                        value = handle.read(129)
                else:
                    value = 'closed'
                if len(value) > 128:
                    raise ValueError('request size')
                state = supervisor.tick(value, int(now), time.monotonic())
            except (ValueError, OSError):
                supervisor.close()
                state = 'error'
            if state != last_state or now - last_write >= 30:
                atomic(status_path, json.dumps({'state': state, 'updated_at': int(now)}))
                last_write = now
                if state != last_state:
                    print('Wartung ' + state + ' (running = Prozess aktiv, kein SSH-Nachweis)', flush=True)
                last_state = state
            time.sleep(1)
    finally:
        supervisor.close()
        atomic(status_path, json.dumps({'state': 'closed', 'updated_at': int(time.time())}))


def main():
    """Dispatch the fixed CLI actions; check validates without opening a tunnel."""
    action, target, *args = sys.argv[1:]
    identifier(target)
    if action == 'run' and not args:
        run(target)
    elif action == 'report' and not args:
        print(report(target))
    elif action == 'receive' and len(args) == 1:
        if os.geteuid() != 0:
            raise ValueError('root receiver required')
        receive(target, args[0])
    elif action == 'check' and not args:
        path = CONFIG / (target + '.json')
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077:
            raise ValueError('private root configuration required')
        config(path)
        print('Wartungskonfiguration gueltig; keine Verbindung gestartet.')
    else:
        raise ValueError('arguments')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, OSError):
        print('Wartungsdienst: Konfiguration, Status oder Freigabe ungueltig.', file=sys.stderr)
        sys.exit(1)
