#!/usr/bin/env python3
"""Real isolated Apache + loopback backend: no production traffic/config."""
import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import threading
import time
import unittest

SPEC = importlib.util.spec_from_file_location(
    "builder", Path(__file__).resolve().parents[1] / "ops/build-command-proxy.py")
builder = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(builder)
ORIGINAL = '''<Location "/webcam/">
        ProxyPass http://192.0.2.14/webcam/
        ProxyPassReverse http://192.0.2.14/webcam/
</Location>'''


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        time.sleep(2)
        self.send_response(200)
        self.send_header("Content-Length", "2")
        self.end_headers()
        try:
            self.wfile.write(b"OK")
        except BrokenPipeError:
            pass

    def log_message(self, *args):
        pass


class ProxyTests(unittest.TestCase):
    def test_rewrite_guards(self):
        with self.assertRaises(ValueError):
            builder.rewrite("unknown config")
        with self.assertRaises(ValueError):
            builder.rewrite(ORIGINAL + ORIGINAL)
        with self.assertRaises(ValueError):
            builder.rewrite(builder.rewrite(ORIGINAL))
        self.assertIn(ORIGINAL, builder.rewrite(ORIGINAL))

    @unittest.skipUnless(Path("/usr/sbin/apache2").exists(), "Apache not installed")
    def test_timeout_isolation(self):
        backend = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        threading.Thread(target=backend.serve_forever, daemon=True).start()
        self.addCleanup(backend.server_close)
        self.addCleanup(backend.shutdown)
        with socket.socket() as probe:
            probe.bind(("127.0.0.1", 0))
            frontend_port = probe.getsockname()[1]
        with tempfile.TemporaryDirectory(prefix="camera-proxy-test-") as directory:
            stage = Path(directory)
            stage.chmod(0o755)
            mapping = builder.rewrite(ORIGINAL).replace(
                "http://192.0.2.14", f"http://127.0.0.1:{backend.server_port}")
            # Speed up the fixture, preserving the relative timeout behavior.
            mapping = mapping.replace("timeout=710", "timeout=4")
            identity = "User www-data\nGroup www-data" if os.geteuid() == 0 else ""
            config = stage / "httpd.conf"
            config.write_text(f'''ServerRoot "{stage}"
ServerName localhost
Listen 127.0.0.1:{frontend_port}
PidFile "{stage}/httpd.pid"
ErrorLog "{stage}/error.log"
LoadModule mpm_event_module /usr/lib/apache2/modules/mod_mpm_event.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule unixd_module /usr/lib/apache2/modules/mod_unixd.so
LoadModule proxy_module /usr/lib/apache2/modules/mod_proxy.so
LoadModule proxy_http_module /usr/lib/apache2/modules/mod_proxy_http.so
{identity}
Timeout 1
ProxyRequests Off
<Location "/">
Require all granted
</Location>
{mapping}
'''.replace("LoadModule unixd_module /usr/lib/apache2/modules/mod_unixd.so\n", ""))
            proc = subprocess.Popen(["/usr/sbin/apache2", "-f", str(config), "-DFOREGROUND"],
                                    stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
            try:
                for _ in range(50):
                    if proc.poll() is not None:
                        self.fail(proc.stderr.read().decode())
                    try:
                        with socket.create_connection(("127.0.0.1", frontend_port), timeout=.1):
                            break
                    except OSError:
                        time.sleep(.1)
                for path, expected in (("command.php", 200), ("upload.php", None),
                                       ("other.php", None)):
                    conn = http.client.HTTPConnection("127.0.0.1", frontend_port, timeout=5)
                    try:
                        started = time.monotonic()
                        conn.request("GET", f"/webcam/include/{path}")
                        response = conn.getresponse()
                        response.read()
                        if expected == 200:
                            self.assertEqual(response.status, 200, path)
                            self.assertGreaterEqual(time.monotonic() - started, 2)
                        else:
                            # Apache versions differ for timeout reading the
                            # backend status line: Bad Gateway or Gateway Timeout.
                            self.assertIn(response.status, (502, 504), path)
                            self.assertLess(time.monotonic() - started, 1.8)
                    finally:
                        conn.close()
                self.assertNotIn("worker sharing", (stage / "error.log").read_text())
                self.assertIn("timeout", (stage / "error.log").read_text().lower())
            finally:
                proc.terminate()
                try:
                    proc.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    proc.kill()
                    proc.wait()
                proc.stderr.close()


if __name__ == "__main__":
    unittest.main()
