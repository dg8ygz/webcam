#!/usr/bin/env python3
"""Delayed/failed socket startup; PHP readiness probes never write GPIO."""
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import threading
import time

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='gpio-ready-') as directory:
    stage = Path(directory)
    active = stage / 'active.json'
    active.write_text(json.dumps(dict(dht=4, heater=5, fan=27, camera_power=6, focus=13)))
    address = str(stage / 'control.sock')
    env = dict(os.environ, CAMERA_RPI_GPIO_CONFIG=str(stage / 'absent.json'),
               CAMERA_RPI_GPIO_ACTIVE=str(active), CAMERA_RPI_FOCUS_SOCKET=address,
               CAMERA_RPI_POWER_SOCKET=address)

    def probe(role, timeout):
        return subprocess.run(['php', '-r',
            'require $argv[1]; CameraGpioApp::ready($argv[2],(float)$argv[3]);',
            str(root / 'scripts/camera-rpi-gpio.php'), role, str(timeout)],
            env=env, capture_output=True, text=True, timeout=4)

    def case(role, reply, expected, delay=0):
        done = threading.Event()
        requests = []

        def server():
            time.sleep(delay)
            with socket.socket(socket.AF_UNIX) as listener:
                listener.bind(address)
                listener.listen()
                listener.settimeout(.05)
                while not done.is_set():
                    try:
                        client, _ = listener.accept()
                    except socket.timeout:
                        continue
                    with client:
                        client.settimeout(.3)
                        requests.append(client.recv(16))
                        client.sendall(reply)
        thread = threading.Thread(target=server)
        thread.start()
        start = time.monotonic()
        try:
            p = probe(role, .7)
            assert (p.returncode == 0) == expected, p.stderr
            if expected:
                assert time.monotonic() - start >= delay
            assert requests and set(requests) == {b'STATUS\n'}, requests
        finally:
            done.set()
            thread.join(timeout=2)
            assert not thread.is_alive()
            Path(address).unlink()

    case('focus', b'OFF\n', True, .3)
    case('power', b'ON\n', True, .3)
    case('focus', b'ON\n', False)
    case('power', b'OFF\n', False)
    case('focus', b'ERROR\n', False)
    p = probe('focus', .2)
    assert p.returncode != 0 and 'Zeitlimits' in p.stderr
    active.write_text(json.dumps(dict(dht=4, heater=5, fan=27, camera_power=None, focus=None)))
    assert probe('focus', .2).returncode == 0
    assert probe('power', .2).returncode == 0
print('PASS: 8 readiness cases; delayed sockets, wrong state, error, missing owner, optional pins; STATUS only')
