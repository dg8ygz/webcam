#!/usr/bin/python3
"""Boot-only recovery for .34; install locally, never execute from CIFS at boot."""
import json
import os
import socket
import subprocess
import sys
import time


def run(*args):
    """Bound one system query; propagate failures to the enclosing boot policy."""
    return subprocess.run(args, capture_output=True, text=True, timeout=3, check=True).stdout


def network_ready():
    """DHCP may outlive ifup: require the actual IPv4 and the NAS route."""
    try:
        links = json.loads(run('/usr/sbin/ip', '-j', '-4', 'address', 'show', 'dev', 'ens18'))
        if not any(a.get('local') == '192.0.2.34' and a.get('scope') == 'global'
                   for link in links for a in link.get('addr_info', [])):
            return False
        routes = json.loads(run('/usr/sbin/ip', '-j', '-4', 'route', 'get',
                                '192.0.2.7', 'from', '192.0.2.34'))
        return any(r.get('dev') == 'ens18' and r.get('type', 'unicast') == 'unicast'
                   for r in routes)
    except (subprocess.SubprocessError, ValueError, OSError):
        return False


def wait_network(seconds=60):
    """Wait for the actual address/route until a monotonic boot-only deadline."""
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        if network_ready():
            print('IPv4 address and NAS route ready.', flush=True)
            return
        time.sleep(1)
    raise RuntimeError('IPv4/NAS route unavailable after network readiness timeout.')


def apache_state():
    """Read enablement and whether Apache has ever become active in this boot."""
    values = run('/usr/bin/systemctl', 'show', 'apache2.service',
                 '-p', 'ActiveState', '-p', 'ActiveEnterTimestampMonotonic',
                 '-p', 'UnitFileState')
    return dict(line.split('=', 1) for line in values.splitlines() if '=' in line)


def recover_apache(seconds=300):
    """Retry initial boot failure only; respect disabled units and later operator stops."""
    # Ends permanently after the first successful start of this boot.
    # In particular, a later operator stop is NOT undone by this helper.
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        state = apache_state()
        if state.get('ActiveState') == 'active':
            print('Apache active; boot recovery complete.', flush=True)
            return
        if int(state.get('ActiveEnterTimestampMonotonic', '0')) > 0:
            print('Apache already ran this boot; leaving later stop/failure untouched.', flush=True)
            return
        if state.get('UnitFileState') != 'enabled':
            print('Apache not enabled; no automatic start.', flush=True)
            return
        if state.get('ActiveState') in ('inactive', 'failed') and network_ready():
            print('Retrying Apache boot start with existing mount dependencies.', flush=True)
            try:
                run('/usr/bin/systemctl', '--no-block', 'start', 'apache2.service')
            except (subprocess.SubprocessError, OSError) as error:
                print('Start request failed: ' + str(error), file=sys.stderr, flush=True)
        time.sleep(15)
    raise RuntimeError('Apache boot recovery exhausted after 300 seconds; inspect mounts/journal.')


def main():
    """Allow only the two fixed host-specific boot actions; recovery requires root."""
    if socket.gethostname().split('.')[0] != 'db0bi-webcamsrv-test':
        raise RuntimeError('This helper is exclusively for host .34.')
    if sys.argv[1:] == ['wait-network']:
        wait_network()
    elif sys.argv[1:] == ['recover-apache'] and os.geteuid() == 0:
        recover_apache()
    else:
        raise RuntimeError('Usage: webcam-boot-guard wait-network|recover-apache (recovery as root)')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
