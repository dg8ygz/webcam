#!/usr/bin/python3
"""Opt-in Canon local camera clock sync; serialize with all camera workers."""
import argparse
import calendar
import fcntl
import os
import re
import subprocess
import sys
import time

LOCK = '/run/lock/camera-rpi.operation.lock'


def run(args, utc=False):
    env = dict(os.environ, LC_ALL='C')
    if utc:
        env['TZ'] = 'UTC'
    return subprocess.run(args, env=env, check=True, text=True,
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          timeout=20).stdout


def require_ntp():
    if run(['timedatectl', 'show', '-p', 'NTPSynchronized', '--value']).strip() != 'yes':
        raise RuntimeError('Pi clock is not NTP synchronized; camera unchanged')


def camera_time(output):
    if not re.search(r'^Type: DATE$', output, re.M) or not re.search(r'^Readonly: 0$', output, re.M):
        raise RuntimeError('No writable datetime DATE field')
    match = re.search(r'^Current: ([0-9]+)$', output, re.M)
    if not match:
        raise RuntimeError('Missing camera clock readback')
    return int(match[1])


def local_stamp(epoch):
    # Encode local wall-clock components as UTC, avoiding libgphoto2's
    # tm_isdst/mktime bug when writing datetime during daylight saving time.
    return calendar.timegm(time.localtime(epoch)[:6])


def synchronize(check=False):
    require_ntp()
    before = camera_time(run(['gphoto2', '--get-config', 'datetime']))
    delta = before - time.time()
    print(f'Camera clock before: offset={delta:.1f}s', flush=True)
    if check:
        return
    require_ntp()  # Recheck immediately before changing the camera.
    stamp = local_stamp(time.time())
    run(['gphoto2', '--set-config-value', f'datetime={stamp}'], utc=True)
    after = camera_time(run(['gphoto2', '--get-config', 'datetime']))
    delta = after - time.time()
    if abs(delta) > 5:
        raise RuntimeError(f'Camera clock verification failed: offset={delta:.1f}s')
    print(f'Camera clock synchronized and verified: offset={delta:.1f}s', flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Read only; do not set camera clock')
    args = parser.parse_args()
    # The timer runs as root; never replace/unlink the shared lock inode.
    with open(LOCK, 'a') as lock:
        deadline = time.monotonic() + 180
        while True:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise RuntimeError('Camera remains busy; clock unchanged')
                time.sleep(1)
        synchronize(args.check)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, subprocess.SubprocessError) as exc:
        print(f'Camera clock sync failed: {exc}', file=sys.stderr)
        sys.exit(1)
