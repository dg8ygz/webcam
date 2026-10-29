#!/usr/bin/env python3
"""Real shell/timeout signals; fake USB. Cleanup must survive repeated TERM."""
import os, pathlib, signal, subprocess, tempfile, time
root = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix="eos-signals-") as tmp:
    for iteration in range(12):
        folder = pathlib.Path(tmp) / str(iteration)
        folder.mkdir()
        log = folder / "commands"
        env = dict(os.environ, CAMERA_RPI_GPHOTO_BIN=str(root / "tests/fixtures/fake-eos-gphoto"),
                   EOS_TEST_LOG=str(log), EOS_TEST_CASE="hang", EOS_TEST_RELEASE_DELAY="0.2")
        p = subprocess.Popen([str(root / "scripts/lib/camera-rpi-eos-legacy"), "--capture",
                              str(folder / "image.jpg"), "1048576"], env=env,
                             stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, start_new_session=True)
        try:
            deadline = time.monotonic() + 5
            while not log.exists():
                assert time.monotonic() < deadline, "capture did not start"
                time.sleep(0.01)
            for _ in range(15):
                if p.poll() is not None: break
                (os.killpg if iteration % 2 else os.kill)(p.pid, signal.SIGTERM)
                time.sleep(0.005)
            _, errors = p.communicate(timeout=15)
            assert p.returncode == 143, errors
            assert b'Ruecksetzen' not in errors, errors
            assert log.read_text().splitlines()[-1] == "--set-config-value eosremoterelease=Release Full"
            assert not list(folder.glob(".eos-download.*"))
        finally:
            if p.poll() is None:
                os.killpg(p.pid, signal.SIGKILL)
                p.wait()
    folder = pathlib.Path(tmp) / "release-timeout"
    folder.mkdir()
    env = dict(os.environ, CAMERA_RPI_GPHOTO_BIN=str(root / "tests/fixtures/fake-eos-gphoto"),
               EOS_TEST_LOG=str(folder / "commands"), EOS_TEST_CASE="no-file", EOS_TEST_RELEASE_DELAY="60")
    started = time.monotonic()
    result = subprocess.run([str(root / "scripts/lib/camera-rpi-eos-legacy"), "--capture",
                             str(folder / "image.jpg"), "1048576"], env=env,
                            stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, timeout=16)
    assert result.returncode != 0
    assert time.monotonic() - started < 15, "cleanup exceeded hard deadline"
    assert b"Ruecksetzen" in result.stderr
print("OK: 12 repeated process/group-TERM and bounded hung-release EOS cases; no hardware")
