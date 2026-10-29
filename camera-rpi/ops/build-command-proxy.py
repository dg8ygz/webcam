#!/usr/bin/env python3
"""Create a narrow legacy command timeout override; never touch uploads."""
from pathlib import Path
import re
import sys


def rewrite(text):
    """Insert only the known legacy command override; refuse changed/duplicate input.

    Worker definition precedes the broad route, specific Location follows it:
    these two ordering rules prevent worker-sharing and Location-merge surprises.
    """
    pattern = (r'<Location "/webcam/">\s*\n'
               r'\s*ProxyPass http://192\.0\.2\.14/webcam/\s*\n'
               r'\s*ProxyPassReverse http://192\.0\.2\.14/webcam/\s*\n'
               r'</Location>')
    matches = list(re.finditer(pattern, text))
    if len(matches) != 1 or "# camera-rpi-command-long-poll" in text:
        raise ValueError("Expected exactly one unmodified webcam location")
    match = matches[0]
    # Define the longer backend URL first to avoid Apache worker sharing.
    before = '''# camera-rpi-command-long-poll
<Proxy "http://192.0.2.14/webcam/include/command.php">
    ProxySet connectiontimeout=10 timeout=710
</Proxy>
'''
    # Location sections are merged in config order: the specific rule must
    # FOLLOW the general /webcam/ location, but its worker already exists.
    after = '''
<Location "/webcam/include/command.php">
    ProxyPass "http://192.0.2.14/webcam/include/command.php"
    ProxyPassReverse "http://192.0.2.14/webcam/include/command.php"
</Location>
'''
    return text[:match.start()] + before + match.group() + after + text[match.end():]


if __name__ == "__main__":
    Path(sys.argv[2]).write_text(rewrite(Path(sys.argv[1]).read_text()))
