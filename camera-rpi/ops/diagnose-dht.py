#!/usr/bin/env python3
"""Two-minute, RAM-only DHT observation; does not read the sensor directly."""
import datetime
import grp
import json
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import tempfile
import time

DURATION = 120
WATCHDOG_SECONDS = 150
CONTROL_PATHS = (Path('/proc/dynamic_debug/control'),
                 Path('/sys/kernel/debug/dynamic_debug/control'))
STATUS = Path('/run/camera-rpi-status/current.status')
STATUS_KEYS = {'updated_epoch', 'sensor_state', 'sensor_message',
               'temperature_c', 'humidity_percent'}


def debug_sites(text):
    """Select only DHT source locations and remember their original print flags."""
    sites = []
    for line in text.splitlines():
        match = re.match(r'^(\S+):(\d+)\s+\[[^\]]+\]\S+\s+=(\S+)\s', line)
        if match and match[1].endswith('drivers/iio/humidity/dht11.c'):
            if not re.fullmatch(r'[A-Za-z0-9_./-]+', match[1]):
                raise ValueError('Unexpected debug source path')
            sites.append({'file': match[1], 'line': int(match[2]),
                          'enabled': 'p' in match[3]})
    return sites


def switch(control, site, enabled):
    """Change only the print flag for one previously validated debug location."""
    with control.open('w') as stream:
        stream.write(f"file {site['file']} line {site['line']} {'+p' if enabled else '-p'}\n")


def restore(state_path):
    """Undo this run's debug flags and verify them before marking restoration."""
    state = json.loads(state_path.read_text())
    control = Path(state['control'])
    if control not in CONTROL_PATHS:
        raise ValueError('Unexpected debug control')
    for site in state['sites']:
        # Only remove flags this diagnosis could have added. Other flags stay.
        if not site['enabled']:
            switch(control, site, False)
    actual = {(s['file'], s['line']): s['enabled']
              for s in debug_sites(control.read_text())}
    if any(actual.get((s['file'], s['line'])) != s['enabled'] for s in state['sites']):
        raise RuntimeError('Debug restoration verification failed')
    (state_path.parent / 'RESTORED').write_text('DHT debug flags restored\n')


def stop_signal(signum, frame):
    """Turn termination into an exception so collection cleanup still runs."""
    raise InterruptedError(f'Signal {signum}')


def collect_passive():
    """Observe normal DHT journal/status for ten minutes, entirely in RAM.

    No driver flags or sensor services change; stop on capture failure or budget
    exhaustion and always reap the journal follower.
    """
    fs = subprocess.check_output(['findmnt', '-n', '-o', 'FSTYPE', '-T', '/run'], text=True).strip()
    if fs != 'tmpfs':
        raise RuntimeError('/run is not tmpfs; nothing changed')
    gid = grp.getgrnam('admin').gr_gid
    work = Path(tempfile.mkdtemp(prefix='camera-rpi-dht-passive.', dir='/run'))
    os.chown(work, 0, gid)
    work.chmod(0o750)
    journal = None
    for sig in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
        signal.signal(sig, stop_signal)
    print(f'Passive Diagnose: {work}', flush=True)
    print('10 Minuten: vorhandenen Status und DHT-Meldungen beobachten; keine Aenderungen.', flush=True)
    try:
        with (work / 'kernel.jsonl').open('w') as kernel, (work / 'samples.jsonl').open('w') as samples:
            for output in (work / 'kernel.jsonl', work / 'samples.jsonl'):
                os.chown(output, 0, gid)
                output.chmod(0o640)
            journal = subprocess.Popen(['journalctl', '-k', '-f', '-n', '0', '-o', 'json',
                                        '--no-pager', '--grep=dht11'], stdout=kernel)
            previous = None
            end = time.monotonic() + 600
            while time.monotonic() < end:
                if journal.poll() is not None:
                    raise RuntimeError('Journal capture ended prematurely')
                if (work / 'kernel.jsonl').stat().st_size > 8 * 1024 * 1024:
                    raise RuntimeError('8 MiB output budget reached; stopping')
                try:
                    current = dict(line.split('=', 1) for line in STATUS.read_text().splitlines()
                                   if '=' in line and line.split('=', 1)[0] in STATUS_KEYS)
                except OSError as exc:
                    current = {'read_error': type(exc).__name__}
                if current != previous:
                    samples.write(json.dumps({'observed_at': datetime.datetime.now().astimezone().isoformat(),
                                              'status': current}) + '\n')
                    samples.flush()
                    previous = current
                time.sleep(0.5)
        print('OK: Passive Beobachtung abgeschlossen.', flush=True)
    finally:
        if journal is not None:
            journal.terminate()
            try:
                journal.wait(timeout=5)
            except subprocess.TimeoutExpired:
                journal.kill()
                journal.wait(timeout=5)
        print(f'RAM-Ergebnisse: {work}', flush=True)


def collect():
    """Enable bounded DHT debug logging with independent systemd restoration.

    Refuse unsupported kernels without mounting debugfs or changing drivers;
    retain original flags before enabling anything and collect no extra reads.
    """
    control = next((p for p in CONTROL_PATHS if p.is_file()), None)
    if control is None:
        raise RuntimeError('Dynamic-debug control unavailable; nothing changed. '
                           'No debugfs mount or kernel change performed.')
    sites = debug_sites(control.read_text())
    if not sites:
        raise RuntimeError('No DHT debug sites available; nothing changed.')
    for name in ('systemd-run', 'systemctl', 'journalctl'):
        if not shutil.which(name):
            raise RuntimeError(f'{name} missing; nothing changed')
    # Output must stay in RAM, including the independent restoration helper.
    fs = subprocess.check_output(['findmnt', '-n', '-o', 'FSTYPE', '-T', '/run'], text=True).strip()
    if fs != 'tmpfs':
        raise RuntimeError('/run is not tmpfs; nothing changed')
    gid = grp.getgrnam('admin').gr_gid
    work = Path(tempfile.mkdtemp(prefix='camera-rpi-dht-diag.', dir='/run'))
    os.chown(work, 0, gid)
    work.chmod(0o750)
    helper = work / 'diagnose-dht.py'
    shutil.copyfile(Path(__file__).resolve(), helper)
    helper.chmod(0o700)
    state_path = work / 'restore.json'
    state_path.write_text(json.dumps({'control': str(control), 'sites': sites}))
    state_path.chmod(0o600)
    unit = 'camera-rpi-dht-restore-' + work.name.rsplit('.', 1)[1]
    journal = None
    restored = False
    for sig in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
        signal.signal(sig, stop_signal)
    print(f'Diagnose: {work}', flush=True)
    try:
        # Arm before enabling anything. This survives a killed SSH/script process.
        subprocess.run(['systemd-run', '--quiet', '--unit=' + unit,
                        '--on-active=' + str(WATCHDOG_SECONDS) + 's',
                        '--timer-property=AccuracySec=1s',
                        '--property=Restart=on-failure', '--property=RestartSec=5s',
                        sys.executable, str(helper), '--restore', str(state_path)],
                       check=True, timeout=15)
        with (work / 'kernel.jsonl').open('w') as kernel, (work / 'samples.jsonl').open('w') as samples:
            for output in (work / 'kernel.jsonl', work / 'samples.jsonl'):
                os.chown(output, 0, gid)
                output.chmod(0o640)
            # Capture only this driver's messages. No keys, general logs or images.
            journal = subprocess.Popen(['journalctl', '-k', '-f', '-n', '0', '-o', 'json',
                                        '--no-pager', '--grep=dht11'], stdout=kernel)
            for site in sites:
                if not site['enabled']:
                    switch(control, site, True)
            print('120 Sekunden: normale Sensorabfragen beobachten; keine Zusatzabfragen.', flush=True)
            end = time.monotonic() + DURATION
            previous = None
            while time.monotonic() < end:
                if journal.poll() is not None:
                    raise RuntimeError('Journal capture ended prematurely')
                if (work / 'kernel.jsonl').stat().st_size > 8 * 1024 * 1024:
                    raise RuntimeError('8 MiB output budget reached; stopping')
                try:
                    current = dict(line.split('=', 1) for line in STATUS.read_text().splitlines()
                                   if '=' in line and line.split('=', 1)[0] in STATUS_KEYS)
                except OSError as exc:
                    current = {'read_error': type(exc).__name__}
                if current != previous:
                    samples.write(json.dumps({'observed_at': datetime.datetime.now().astimezone().isoformat(),
                                              'status': current}) + '\n')
                    samples.flush()
                    previous = current
                time.sleep(0.5)
    finally:
        # Do not let a second terminal signal interrupt restoration.
        for sig in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
            signal.signal(sig, signal.SIG_IGN)
        try:
            restore(state_path)
            restored = True
        finally:
            if journal is not None:
                journal.terminate()
                try:
                    journal.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    journal.kill()
                    journal.wait(timeout=5)
            if restored:
                subprocess.run(['systemctl', 'stop', unit + '.timer'], timeout=10, check=False)
                print('OK: Vorherige DHT-Debugflags wiederhergestellt.', flush=True)
            else:
                print('ACHTUNG: Wiederherstellung fehlgeschlagen; unabhaengiger '
                      f'Rueckstell-Timer {unit}.timer bleibt aktiv.', file=sys.stderr)
        print(f'RAM-Ergebnisse: {work}', flush=True)


if __name__ == '__main__':
    os.umask(0o077)
    if os.geteuid() != 0:
        sys.exit('Bitte einmal mit sudo python3 starten.')
    try:
        if len(sys.argv) == 3 and sys.argv[1] == '--restore':
            restore(Path(sys.argv[2]))
        elif sys.argv[1:] == ['--passive']:
            collect_passive()
        elif len(sys.argv) == 1:
            collect()
        else:
            sys.exit('Keine Optionen fuer den normalen Diagnoselauf erforderlich.')
    except Exception as exc:
        sys.exit(f'Diagnose beendet: {exc}')
