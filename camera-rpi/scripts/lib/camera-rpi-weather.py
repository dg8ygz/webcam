#!/usr/bin/env python3
"""Snapshot existing RAM sensor data; never query GPIO or attach a later sample."""
import json
import math
import os
from pathlib import Path
import sys
import time


def validate(data, capture, now):
    """Validate weather identity, finite ranges and proximity to image capture.

    Retries may reuse an old snapshot: freshness is measured against capture,
    not the later upload, so a retry never attaches a different sensor reading.
    """
    if set(data) != {'source', 'measured_at', 'captured_at', 'temperature_c', 'humidity_percent'}:
        raise ValueError('weather fields')
    if data['source'] != 'dht22' or type(data['measured_at']) is not int or type(data['captured_at']) is not int:
        raise ValueError('weather identity')
    if not (0 <= data['captured_at'] - data['measured_at'] <= 120
            and abs(data['captured_at'] - capture) <= 900 and data['captured_at'] <= now + 30):
        raise ValueError('weather age')
    for key, low, high in [('temperature_c', -40, 80), ('humidity_percent', 0, 100)]:
        value = data[key]
        if type(value) not in (int, float) or not math.isfinite(value) or not low <= value <= high:
            raise ValueError('weather range')
    return data


def snapshot(status, capture, now):
    """Read existing bounded RAM status, returning None if the sensor is not OK.

    Does not touch GPIO or perform another measurement; invalid data raises.
    """
    with status.open('rb') as handle:
        raw = handle.read(4097)
    if len(raw) > 4096:
        raise ValueError('sensor status too large')
    fields = dict(line.split('=', 1) for line in raw.decode().splitlines() if '=' in line)
    if fields.get('sensor_state') != 'ok':
        return None
    return validate(dict(source='dht22', measured_at=int(fields['updated_epoch']), captured_at=now,
                         temperature_c=float(fields['temperature_c']), humidity_percent=float(fields['humidity_percent'])), capture, now)


def main():
    """Create an atomic image sidecar or append validated weather to a curl form."""
    action, source, target, stamp = sys.argv[1:]
    now = int(time.time())
    if action == 'snapshot':
        data = snapshot(Path(source), int(stamp), now)
        if data is not None:
            temporary = target + '.tmp'
            with open(temporary, 'w', encoding='utf-8') as handle:
                json.dump(data, handle, separators=(',', ':'), allow_nan=False)
            os.replace(temporary, target)
    elif action == 'form':
        with Path(source).open('rb') as handle:
            raw = handle.read(513)
        if len(raw) > 512:
            raise ValueError('weather too large')
        data = validate(json.loads(raw), int(stamp), now)
        # Re-encode numbers, not raw text; escaping for a curl config string.
        value = json.dumps(data, separators=(',', ':'), allow_nan=False).replace('"', '\\"')
        with open(target, 'a', encoding='utf-8') as handle:
            handle.write('form-string = "local_weather=' + value + '"\n')
    else:
        raise ValueError('action')


if __name__ == '__main__':
    try:
        main()
    except FileNotFoundError:
        pass  # Optional: no sensor / pre-update image in spool.
    except (ValueError, KeyError, OSError):
        print('Lokale Wetterdaten ungueltig oder veraltet; Bild ohne Sensorwerte.', file=sys.stderr)
        sys.exit(1)
