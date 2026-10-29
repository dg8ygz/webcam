#!/usr/bin/env python3
"""Isolated native reload/rotation test. Dummy actions only, private socket/logs."""
import datetime
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time

before = Path(sys.argv[1]).resolve()
payload = Path(__file__).resolve().parents[1]/'ops/proxy-fail2ban-20261003/payload'
work = Path(tempfile.mkdtemp(prefix='f2b-20261003-isolated-'))
config = work/'config'
for directory in ('filter.d', 'jail.d', 'action.d'):
    (config/directory).mkdir(parents=True)
shutil.copy2(before/'fail2ban.conf', config/'fail2ban.conf')
shutil.copy2(before/'action.d/dummy.conf', config/'action.d/dummy.conf')
(config/'fail2ban.local').write_text(f'[Definition]\nlogtarget = {work}/server.log\ndbfile = None\n')
(config/'jail.conf').write_text('[DEFAULT]\nenabled = false\nbackend = polling\nusedns = no\n')
for filename in ('access.log', 'access.log.1'):
    (work/filename).touch()
old_jails = ('apache-wp-probe', 'apache-checkjs', 'apache-propfind')
seed_ips = dict(zip(old_jails, ('192.0.2.10', '192.0.2.11', '192.0.2.12')))


def install_jail(source, name):
    # Preserve thresholds and logpath tail; replace backend and action only here.
    lines = (source/f'jail.d/{name}.local').read_text().splitlines()
    clean = []
    for line in lines:
        key = line.split('=', 1)[0].strip()
        if key in ('action', 'banaction', 'backend'):
            continue
        clean.append(line.replace('/var/log/apache2/', str(work)+'/'))
    clean += ['backend = polling', f'action = dummy[target={work}/dummy-{name}.log]']
    (config/f'jail.d/{name}.local').write_text('\n'.join(clean)+'\n')


def client(*args, check=True):
    r = subprocess.run(['fail2ban-client', '-c', str(config), '-s', str(work/'socket'), '-p', str(work/'pid'), *args], capture_output=True, text=True, timeout=20)
    with (work/'client.log').open('a') as log:
        log.write(' '.join(args)+'\n'+r.stdout+r.stderr+'\n')
    if check and r.returncode:
        raise RuntimeError(f'{args}: {r.stdout}{r.stderr}')
    return r.stdout.strip()


def append(ip, method, path, status):
    now = datetime.datetime.now().astimezone().strftime('%d/%b/%Y:%H:%M:%S %z')
    with (work/'access.log').open('a') as log:
        log.write(f'{ip} - - [{now}] "{method} {path} HTTP/1.1" {status} 100 "-" "isolated-test"\n')


def banned(jail, ip):
    return client('get', jail, 'banned', ip) == '1'


def wait_ban(jail, ip):
    deadline = time.monotonic()+18
    while time.monotonic() < deadline:
        if banned(jail, ip):
            return
        time.sleep(0.2)
    raise AssertionError(f'Missing test ban: {jail}, {ip}; {work}')


def preserve():
    for jail, ip in seed_ips.items():
        assert banned(jail, ip), (jail, ip)
        assert '-'+ip not in (work/f'dummy-{jail}.log').read_text()


for name in old_jails:
    for suffix in ('.conf', '.local'):
        f = before/f'filter.d/{name}{suffix}'
        if f.exists():
            shutil.copy2(f, config/'filter.d'/f.name)
    install_jail(before, name)
# History is deliberately fresh: skipping it must come from tail/offsets.
for _ in range(2):
    append('198.51.100.90', 'POST', '/livewire/upload-file', 405)
    append('198.51.100.91', 'GET', '/wp-login.php', 301)
print('Isolated work:', work, flush=True)
try:
    client('-t')
    client('start')
    for jail, ip in seed_ips.items():
        client('set', jail, 'banip', ip)
        wait_ban(jail, ip)
    print('Ban time format:', client('get', 'apache-wp-probe', 'banip', '--with-time'), flush=True)
    # Ensure initial readers have consumed old lines before changing the WP filter.
    append('198.51.100.80', 'GET', '/wp-admin/', 403)
    append('198.51.100.80', 'GET', '/wp-admin/', 403)
    wait_ban('apache-wp-probe', '198.51.100.80')
    for f in (payload/'filter.d').iterdir():
        shutil.copy2(f, config/'filter.d'/f.name)
    for name in ('apache-app-probes', 'apache-checkjs', 'apache-propfind'):
        install_jail(payload, name)
    client('-t')
    client('reload', '--if-exists', 'apache-app-probes')
    for name in old_jails:
        client('reload', name)
    preserve()
    for name in ('apache-app-probes', 'apache-checkjs', 'apache-propfind'):
        assert 'access.log.1' not in client('get', name, 'logpath')
    append('198.51.100.20', 'POST', '/livewire/upload-file', 405)
    time.sleep(1.5)
    assert not banned('apache-app-probes', '198.51.100.20')
    append('198.51.100.20', 'POST', '/access/api/v1/aws/token/', 405)
    wait_ban('apache-app-probes', '198.51.100.20')
    assert not banned('apache-app-probes', '198.51.100.90'), 'Historical app lines banned'
    assert not banned('apache-wp-probe', '198.51.100.91'), 'Historical WP lines reread'
    append('198.51.100.21', 'GET', '/wp-login.php', 301)
    append('198.51.100.21', 'GET', '/cms/wp-login.php', 301)
    wait_ban('apache-wp-probe', '198.51.100.21')
    # Normal log rotation must work without reading the .1 file as another source.
    os.replace(work/'access.log', work/'access.log.1')
    (work/'access.log').touch()
    append('198.51.100.22', 'PROPFIND', '/', 405)
    append('198.51.100.23', 'GET', '/static/a/check.js', 403)
    wait_ban('apache-propfind', '198.51.100.22')
    wait_ban('apache-checkjs', '198.51.100.23')
    preserve()
    # Same targeted rollback as installer: old jails stay alive with their bans.
    client('stop', 'apache-app-probes')
    (config/'jail.d/apache-app-probes.local').unlink()
    (config/'filter.d/apache-app-probes.conf').unlink()
    shutil.copy2(before/'filter.d/apache-wp-probe.local', config/'filter.d/apache-wp-probe.local')
    for name in ('apache-checkjs', 'apache-propfind'):
        install_jail(before, name)
    client('-t')
    for name in old_jails:
        client('reload', name)
    preserve()
    assert 'wp-links-opml' not in client('get', 'apache-wp-probe', 'failregex')
    assert 'access.log.1' in client('get', 'apache-checkjs', 'logpath')
    log = (work/'server.log').read_text()
    assert ' ERROR ' not in log and ' CRITICAL ' not in log, work
    print('PASS: targeted reload, old bans preserved, no history bans, new thresholds, rotation, rollback. Dummy actions only.', flush=True)
finally:
    if (work/'socket').exists():
        client('stop')
    assert not (work/'socket').exists(), f'Test daemon still present: {work}'
