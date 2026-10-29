#!/usr/bin/env python3
"""Turn one FHEM detail response into weather text, only for fresh readings.

The caller fetches the authenticated page. This filter receives HTML on stdin;
it neither knows credentials nor changes FHEM. Failure produces no weather text.
"""
from datetime import datetime
from html.parser import HTMLParser
import math
import re
import sys
import time
from zoneinfo import ZoneInfo

MAX_BYTES = 1024 * 1024
MAX_AGE_SECONDS = 30 * 60
ZONE = ZoneInfo('Europe/Berlin')
# Same numeric limits as the server's WeatherMetrics import contract.
FIELDS = {
    'temperature': (-40, 60),
    'humidity': (0, 100),
    'dewpoint': (-60, 60),
    'windSpeed': (0, 250),
    'windDirection': (0, 360),
}


class Readings(HTMLParser):
    """Read exact station fields; unrelated statistics cannot refresh a sample."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.values = {}
        self.divs = []
        self.wanted = {'Wetterstation-' + name + suffix
                       for name in FIELDS for suffix in ('', '-ts')}

    def handle_starttag(self, tag, attrs):
        if tag == 'div':
            name = dict(attrs).get('informid')
            self.divs.append((name if name in self.wanted else None, []))

    def handle_data(self, data):
        for name, parts in self.divs:
            if name is not None:
                parts.append(data)

    def handle_endtag(self, tag):
        if tag == 'div' and self.divs:
            name, parts = self.divs.pop()
            if name is not None:
                if name in self.values:
                    raise ValueError('duplicate reading')
                self.values[name] = ''.join(parts).strip()


def measurement_epoch(value):
    """FHEM uses local wall time. Reject nonexistent/ambiguous DST timestamps.

    During the repeated autumn hour the page cannot tell which occurrence it
    means. Missing weather is preferable to silently accepting an older value.
    """
    if not re.fullmatch(r'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}', value):
        raise ValueError('invalid measurement time')
    wall = datetime.strptime(value, '%Y-%m-%d %H:%M:%S')
    candidates = set()
    for fold in (0, 1):
        stamp = wall.replace(tzinfo=ZONE, fold=fold).timestamp()
        if datetime.fromtimestamp(stamp, ZONE).replace(tzinfo=None) == wall:
            candidates.add(stamp)
    if len(candidates) != 1:
        raise ValueError('ambiguous or nonexistent measurement time')
    return candidates.pop()


def weather(raw, now):
    if len(raw) > MAX_BYTES:
        raise ValueError('response too large')
    parser = Readings()
    parser.feed(raw.decode('utf-8', errors='strict'))
    parser.close()
    # The live FHEM page leaves an unrelated layout div open. Only an unfinished
    # reading is relevant here; every required reading must still be complete.
    if any(name is not None for name, _ in parser.divs):
        raise ValueError('incomplete response')
    numbers = {}
    for name, (low, high) in FIELDS.items():
        key = 'Wetterstation-' + name
        if key not in parser.values or key + '-ts' not in parser.values:
            raise ValueError('missing reading or measurement time: ' + name)
        stamp = measurement_epoch(parser.values[key + '-ts'])
        age = now - stamp
        if not 0 <= age <= MAX_AGE_SECONDS:
            # Only controlled field names and timestamps enter diagnostics.
            raise ValueError('reading stale or future: ' + name
                             + ' measured_at=' + parser.values[key + '-ts']
                             + ' max_age_seconds=' + str(MAX_AGE_SECONDS))
        value = float(parser.values[key])
        if not math.isfinite(value) or not low <= value <= high:
            raise ValueError('invalid reading: ' + name)
        numbers[name] = value

    # Use validated individual readings, never the unvalidated STATE summary.
    directions = ('N', 'NO', 'O', 'SO', 'S', 'SW', 'W', 'NW')
    direction = directions[int((numbers['windDirection'] + 22.5) // 45) % 8]
    return (f"Temp: {numbers['temperature']:g} °C, Hum: {numbers['humidity']:g} %, "
            f"DewPt: {numbers['dewpoint']:g} °C, Wind: {numbers['windSpeed']:g} km/h, "
            f"Windrichtung: {direction}\n")


def main():
    try:
        result = weather(sys.stdin.buffer.read(MAX_BYTES + 1), time.time())
    except (ValueError, OverflowError) as error:
        # float()/date parsers may include untrusted input in their exceptions.
        message = str(error)
        if not message.startswith(('reading stale or future:', 'missing reading or measurement time:')):
            message = 'invalid or incomplete source response'
        print('FHEM weather rejected: ' + message, file=sys.stderr)
        return 2
    sys.stdout.write(result)
    return 0


if __name__ == '__main__':
    sys.exit(main())
