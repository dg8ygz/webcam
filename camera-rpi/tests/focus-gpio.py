#!/usr/bin/env python3
"""Real worker helper + native socket with simulated GPIO, never real hardware."""
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import tempfile
import time

repo = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='camera-focus-gpio-') as directory:
    stage = Path(directory)
    binary = stage / 'gpio'
    subprocess.run(['cc', '-O2', '-Wall', '-Wextra', '-Werror', '-DCLIMATE_TEST',
                    '-o', str(binary), str(repo/'scripts/native/camera-rpi-pwm.c')], check=True)
    function_binary = stage / 'gpio-function'
    subprocess.run(['cc', '-O2', '-Wall', '-Wextra', '-Werror', '-DCLIMATE_TEST',
                    '-DCAMERA_RPI_TEST_FOCUS_LEASE_NS=60000000000LL',
                    '-o', str(function_binary), str(repo/'scripts/native/camera-rpi-pwm.c')], check=True)
    address = str(stage/'focus.sock')
    def command(value):
        with socket.socket(socket.AF_UNIX) as client:
            client.settimeout(2)
            client.connect(address)
            client.sendall(value)
            return client.recv(16)
    pins = dict(dht=4, heater=5, fan=27, camera_power=None, focus=13)
    (stage/'active.json').write_text(json.dumps(pins))
    env = dict(os.environ, CAMERA_RPI_GPIO_CONFIG=str(stage/'not-enrolled.json'),
               CAMERA_RPI_GPIO_ACTIVE=str(stage/'active.json'),
               CAMERA_RPI_FOCUS_SOCKET=address,
               CAMERA_RPI_OPERATION_LOCK=str(stage/'operation.lock'),
               CAMERA_RPI_CLIMATE_CONFIG=str(stage/'no-climate.json'),
               CAMERA_RPI_GPHOTO_BIN=str(repo/'tests/fixtures/fake-focus'),
               FAKE_FOCUS_LOG=str(stage/'moves'), FAKE_FOCUS_TRACE=str(stage/'trace'))
    # Every fake USB operation records the electrical state at that moment.
    probe = stage/'gphoto'
    probe.write_text('''#!/usr/bin/env python3
import os, socket, sys
with socket.socket(socket.AF_UNIX) as s:
    s.connect(os.environ['CAMERA_RPI_FOCUS_SOCKET']); s.sendall(b'STATUS\\n')
    state=s.recv(16).decode().strip()
with open(os.environ['GATE_TRACE'],'a') as f: f.write(state+' '+repr(sys.argv[1:])+'\\n')
os.execv(os.environ['REAL_FAKE'],[os.environ['REAL_FAKE'],*sys.argv[1:]])
''')
    probe.chmod(0o700)
    env.update(CAMERA_RPI_GPHOTO_BIN=str(probe), REAL_FAKE=str(repo/'tests/fixtures/fake-focus'),
               GATE_TRACE=str(stage/'gate-trace'))
    common = str(repo/'scripts/lib/camera-rpi-common.sh')
    def shell(code, extra=None):
        return subprocess.run(['bash', '-c', 'source "$1"; exec 9>"$CAMERA_RPI_OPERATION_LOCK"; flock -n 9 || exit 99; '+code, 'test', common],
                              env=env | (extra or {}), capture_output=True, text=True, timeout=12)
    with (stage/'native.log').open('w') as log:
        owner = subprocess.Popen([str(binary), '--test-focus', '13', address], stderr=log)
        try:
            for _ in range(100):
                if Path(address).exists(): break
                time.sleep(.01)
            assert command(b'STATUS\n') == b'OFF\n'
            assert Path(address).stat().st_mode & 0o777 == 0o600
            assert command(b'ON\n') == b'ON\n'
            assert command(b'OFF\n') == b'OFF\n'
            for bad in [b'ON\0\n', b'ON\nOFF\n', b'OTHER\n']:
                assert command(bad) == b'ERROR\n'
                assert command(b'STATUS\n') == b'OFF\n'
            # Owner bounds HIGH independently, even with an idle client.
            assert command(b'ON\n') == b'ON\n'
            time.sleep(.6)
            assert command(b'ON\n') == b'ON\n'  # does not renew lease
            with socket.socket(socket.AF_UNIX) as stalled:
                stalled.settimeout(2); stalled.connect(address)
                assert stalled.recv(16) == b'ERROR\n'
            time.sleep(.1)
            assert command(b'STATUS\n') == b'OFF\n'
            # Keep the short watchdog test above, but give the real PHP/fake
            # camera integration the production lease on slower ARM machines.
            owner.terminate()
            assert owner.wait(timeout=3) == 0
            owner = subprocess.Popen([str(function_binary), '--test-focus', '13', address], stderr=log)
            for _ in range(100):
                if Path(address).exists(): break
                time.sleep(.01)
            assert command(b'STATUS\n') == b'OFF\n'
            for mode in ['auto', '+']:
                result = shell('cr_focus_to_camera '+mode)
                assert result.returncode == 0, result.stderr
                assert command(b'STATUS\n') == b'OFF\n'
            for fault in [{'FAKE_FOCUS_QUERY_EXIT':'7'}, {'FAKE_FOCUS_EXIT':'8'},
                          {'FAKE_FOCUS_CLEANUP_EXIT':'9'},
                          {'FAKE_FOCUS_HANG':'1', 'CAMERA_RPI_TIMEOUT_BIN':str(repo/'tests/fixtures/fast-timeout')}]:
                result = shell('cr_focus_to_camera auto', fault)
                assert result.returncode != 0
                assert command(b'STATUS\n') == b'OFF\n'
            assert all(line.startswith('ON ') for line in (stage/'gate-trace').read_text().splitlines()
                       if '--capture-preview' in line)
            # Normal capture actively requests LOW, without an AF command.
            assert command(b'ON\n') == b'ON\n'
            result = shell('CAPTURE_BACKEND=gphoto2; GPHOTO_CAPTURE_MODE=standard; '
                           'GPHOTO_TIMEOUT_SECONDS=2; MAX_IMAGE_BYTES=2048; '
                           'cr_capture_backend_to_file "'+str(stage/'image.jpg')+'"')
            assert result.returncode == 0, result.stderr
            assert (stage/'gate-trace').read_text().splitlines()[-1].startswith('OFF ')
            assert command(b'ON\n') == b'ON\n'
            owner.send_signal(signal.SIGTERM); assert owner.wait(timeout=3) == 0
            assert (stage/'native.log').read_text().endswith('FOCUS 0\n')
            # Configured but absent GPIO owner blocks focus AND image capture.
            before = (stage/'gate-trace').read_text()
            assert shell('cr_focus_to_camera auto').returncode != 0
            assert shell('CAPTURE_BACKEND=gphoto2; cr_capture_backend_to_file /tmp/not-used.jpg').returncode != 0
            assert (stage/'gate-trace').read_text() == before
            pins['focus'] = None
            (stage/'active.json').write_text(json.dumps(pins))
            assert shell('cr_focus_gpio ON; cr_focus_gpio OFF').returncode == 0
        finally:
            if owner.poll() is None: owner.terminate(); owner.wait(timeout=3)
print('OK: focus GPIO startup/lease/stalled client/TERM, real focus success/query/error/timeout cleanup, capture LOW, missing owner blocks USB, disabled profile; simulated hardware only')
