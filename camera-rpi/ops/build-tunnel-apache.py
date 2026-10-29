#!/usr/bin/env python3
"""Print narrowly scoped Apache configuration, no global timeouts/rewrite changes."""
import argparse
import ipaddress
p = argparse.ArgumentParser()
p.add_argument('role', choices=['edge', 'origin'])
p.add_argument('peer', help='edge: origin IP; origin: trusted edge connection IP')
a = p.parse_args()
ip = str(ipaddress.IPv4Address(a.peer))
# Edge accepts HTTPS and forwards to the trusted origin; it never serves the
# landing page itself. Origin keeps the peer-IP gate for both response paths.
if a.role == 'edge':
    url = f'http://{ip}/camera-maintenance/'
    access = 'Require expr "%{HTTPS} == \'on\'"'
    landing = ''
else:
    url = 'http://127.0.0.1:9086/'
    access = f'Require expr "%{{CONN_REMOTE_ADDR}} == \'{ip}\'"'
    # no-proxy must be set before ProxyPass in Location is evaluated.
    # Ordinary requests serve a static page with HTTP 200, not an error handler.
    landing = '''SetEnvIfExpr "%{REQUEST_URI} =~ m#^/camera-maintenance/# && req('Upgrade') !~ m#(?i)^websocket$#" no-proxy=1
AliasMatch "^/camera-maintenance/.*$" "/usr/local/share/camera-rpi-tunnel-landing/index.html"
<Directory "/usr/local/share/camera-rpi-tunnel-landing">
    AllowOverride None
    Options -Indexes -ExecCGI
    Require all granted
</Directory>
'''
# Emit one scoped Location: Upgrade requests retain relay authentication, while
# non-Upgrade requests use the origin Alias and deliberately return HTTP 200.
print(f'''# Dedicated WebSocket endpoint. Does not affect webcam/upload routes.
{landing}<Location "/camera-maintenance/">
    {access}
    ProxyPass "{url}" upgrade=websocket connectiontimeout=5 timeout=1900
    ProxyPassReverse "{url}"
</Location>''')
