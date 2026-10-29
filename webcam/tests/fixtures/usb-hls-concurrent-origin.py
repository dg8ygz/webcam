"""Bounded, threaded loopback fixture. Never contacts a real camera."""
import http.server
import pathlib
import random
import sys
import threading
import time

root = pathlib.Path(sys.argv[1])
log_lock = threading.Lock()


class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        if (root / 'status').exists():
            self.send_error(int((root / 'status').read_text()))
            return
        name = self.path.removeprefix('/')
        path = root / 'origin' / name
        if '/' in name or self.headers.get('Authorization') != 'Bearer ' + 'a' * 64 or not path.is_file():
            self.send_error(403)
            return
        with log_lock:
            with (root / 'requests').open('a') as log:
                log.write(name + '\n')
        (root / ('entered-' + name)).touch()
        deadline = time.monotonic() + 1.8
        while (root / ('hold-' + name)).exists() and time.monotonic() < deadline:
            time.sleep(.01)
        body = path.read_bytes()
        self.send_response(200)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *_):
        pass


for port in random.sample(range(23500, 24000), 500):
    try:
        server = http.server.ThreadingHTTPServer(('127.0.0.1', port), Handler)
        break
    except OSError:
        continue
else:
    raise RuntimeError('No free fixture port')
(root / 'port').write_text(str(port))
server.serve_forever()
