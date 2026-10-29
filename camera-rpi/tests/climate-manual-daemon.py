#!/usr/bin/env python3
"""Real supervisor + pause handshake with a harmless GPIO protocol fixture."""
import fcntl
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='climate-manual-') as directory:
    stage = Path(directory)
    for name in ('run', 'status'):
        (stage/name).mkdir()
    config = json.loads((root/'config/climate.example.json').read_text())
    config['mode'] = 'manual80'
    (stage/'config.json').write_text(json.dumps(config))
    fake = stage/'pwm'
    fake.write_text('#!/bin/sh\nwhile read -r verb heater duty; do printf "OK %s %s\\n" "$heater" "$duty"; done\n')
    fake.chmod(0o700)
    env = dict(os.environ, CAMERA_RPI_CLIMATE_CONFIG=str(stage/'config.json'),
        CAMERA_RPI_CLIMATE_RUNTIME=str(stage/'run'), CAMERA_RPI_STATUS_ROOT=str(stage/'status'),
        CAMERA_RPI_PWM_BIN=str(fake), CAMERA_RPI_GPIO_CONFIG=str(stage/'absent'),
        CAMERA_RPI_GPIO_ACTIVE=str(stage/'absent-active'), CAMERA_RPI_STATUS_GROUP='',
        CAMERA_RPI_ADMIN_TEST_MODE='1')
    env.pop('NOTIFY_SOCKET', None)
    helper = str(root/'scripts/camera-rpi-climate.php')
    def status():
        path = stage/'status/climate.status'
        return dict(line.split('=', 1) for line in path.read_text().splitlines()) if path.exists() else {}
    def expect(heater, pwm):
        until = time.monotonic()+8
        while time.monotonic()<until:
            values = status()
            if values.get('heater')==heater and values.get('fan_percent')==pwm:
                assert values['mode']=='manual80' and values['outputs']=='gpio'
                return
            time.sleep(.1)
        raise AssertionError(status())
    with (stage/'daemon.log').open('w') as log:
        daemon = subprocess.Popen(['php',helper,'run'],env=env,stdout=log,stderr=log)
        try:
            expect('on','80')
            with (stage/'run/capture.lock').open('a') as lock:
                fcntl.flock(lock,fcntl.LOCK_EX)
                subprocess.run(['php',helper,'pause','--seconds','30'],env=env,check=True,timeout=10)
                expect('off','0')
            expect('on','80')
        finally:
            daemon.terminate()
            try:
                daemon.wait(timeout=5)
            except subprocess.TimeoutExpired:
                daemon.kill(); daemon.wait()
    assert 'Reglerfehler' not in (stage/'daemon.log').read_text()
print('PASS: real manual supervisor, no DHT, 80% target, confirmed capture OFF and return to 80%; fake GPIO only')
