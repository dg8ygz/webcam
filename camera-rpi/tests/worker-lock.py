"""A rejected second worker must leave the active worker's runtime files intact."""

import fcntl
import os
from pathlib import Path
import subprocess
import tempfile

PROJECT = Path(__file__).resolve().parents[1]

with tempfile.TemporaryDirectory(prefix="camera-rpi-worker-lock-") as temporary:
    root = Path(temporary)
    runtime = root / "run" / "review"
    runtime.mkdir(parents=True)
    key = root / "keys" / "review.key"
    key.parent.mkdir()
    key.write_text("test-review-key\n")
    key.chmod(0o600)
    config = root / "review.conf"
    config.write_text(
        "server_id=review\nserver_base_url=http://review.invalid/webcam\n"
        "camera_slug=review\n"
        f"key_file={key}\nspool_root={root}/spool\nruntime_root={root}/run\n"
        "manage_root_mount=false\n"
    )
    config.chmod(0o600)
    files = [
        "command.header", "command.form", "command.response",
        "command.response.headers", "upload.header", "upload.form",
        "upload.response", "upload.chunk",
    ]
    contents = "active-worker-owned-fixture\n"
    for name in files:
        (runtime / name).write_text(contents)
    with (runtime / "worker.lock").open("w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        environment = os.environ | {
            "CAMERA_RPI_COMMON_LIB": str(PROJECT / "scripts/lib/camera-rpi-common.sh"),
            "CAMERA_RPI_POOL_CONFIG": str(root / "no-pool"),
            "CAMERA_RPI_SOURCE_CONFIG": str(root / "no-source"),
            "CAMERA_RPI_GPHOTO_BIN": "/bin/true",
            "CAMERA_RPI_OPERATION_LOCK": str(root / "operation.lock"),
        }
        result = subprocess.run(
            ["bash", str(PROJECT / "scripts/camera-rpi-worker"), "--once", str(config)],
            env=environment, capture_output=True, text=True, timeout=10,
        )
    assert result.returncode == 1 and "bereits ein Worker" in result.stderr
    assert all((runtime / name).read_text() == contents for name in files)
    print("OK: rejected second worker retains all eight active runtime files; no network/GPIO")
