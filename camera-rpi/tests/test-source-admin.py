#!/usr/bin/env python3
"""Isolated root bridge transactions with fake services; no camera or live /etc."""
import json, os, pathlib, subprocess, tempfile
repo=pathlib.Path(__file__).resolve().parents[1]
assert os.geteuid()==0, 'Run isolated ownership checks as root'
with tempfile.TemporaryDirectory(prefix='source-admin-test-') as tmp:
    d=pathlib.Path(tmp)
    service=d/'systemctl'
    service.write_text('''#!/bin/bash
printf '%s\\n' "$*" >> "$FIXTURE/calls"
case "$1" in
list-units) echo 'camera-rpi-worker@dev.service loaded active running'; echo 'camera-rpi-video.service loaded active running' ;;
stop) ;;
start) if [[ -f "$FIXTURE/fail-start" ]]; then rm "$FIXTURE/fail-start"; exit 1; fi ;;
*) exit 2 ;;
esac
''');service.chmod(0o700)
    mount=d/'findmnt';mount.write_text('#!/bin/sh\necho rw\n');mount.chmod(0o700)
    env={**os.environ,'CAMERA_RPI_SOURCE_CONFIG':str(d/'source.conf'),'CAMERA_RPI_CONFIG_DIR':str(d/'servers'),
        'CAMERA_RPI_KEY_DIR':str(d/'keys'),'CAMERA_RPI_OPERATION_LOCK':str(d/'operation.lock'),
        'CAMERA_RPI_SYSTEMCTL_BIN':str(service),'CAMERA_RPI_FINDMNT_BIN':str(mount),
        'CAMERA_RPI_ADMIN_TEST_MODE':'0','FIXTURE':str(d),'TMPDIR':str(d)}
    source={'source':'rtsp','rtsp_url':'rtsp://user:secret@192.0.2.10/live','video_device':'/dev/video0',
        'video_width':'1920','video_height':'1080','video_warmup_frames':'15','video_input_fps':'30','gphoto_capture_mode':'standard'}
    def save(c):
        return subprocess.run(['bash',str(repo/'scripts/admin/camera-rpi-admin')],input='action=source_save\nsource_json='+json.dumps(c)+'\n',env=env,text=True,capture_output=True)
    r=save(source);assert r.returncode==0,r.stderr
    original=(d/'source.conf').read_bytes();assert (d/'source.conf').stat().st_mode&0o777==0o600
    assert 'secret' not in r.stdout+r.stderr
    calls=(d/'calls').read_text();assert 'stop camera-rpi-worker@dev.service camera-rpi-video.service' in calls
    malformed={**source,'rtsp_url':'file:///etc/passwd'};r=save(malformed)
    assert r.returncode!=0 and (d/'source.conf').read_bytes()==original
    assert (d/'calls').read_text()==calls, 'invalid profile must not touch services'
    (d/'fail-start').touch();r=save({**source,'source':'gphoto2','gphoto_capture_mode':'eos-legacy'})
    assert r.returncode!=0, 'partial start failure must propagate'
    assert (d/'source.conf').read_bytes()==original, 'old profile restored'
    assert (d/'calls').read_text().splitlines()[-2:]==[
        'stop camera-rpi-worker@dev.service camera-rpi-video.service','start camera-rpi-worker@dev.service camera-rpi-video.service']
    r=save({**source,'source':'gphoto2','gphoto_capture_mode':'eos-legacy'});assert r.returncode==0,r.stderr
    shell='source "'+str(repo/'scripts/lib/camera-rpi-common.sh')+'"; cr_source_load; printf "%s %s" "$CAPTURE_BACKEND" "$GPHOTO_CAPTURE_MODE"'
    loaded=subprocess.run(['bash','-c',shell],env=env,text=True,capture_output=True)
    assert loaded.returncode==0 and loaded.stdout=='gphoto2 eos-legacy',loaded
    # Failed first installation must remove the new profile again.
    (d/'source.conf').unlink();(d/'fail-start').touch();r=save(source)
    assert r.returncode!=0 and not (d/'source.conf').exists()
print('OK: source validation, private atomic save, both services, partial-start rollback, absent-profile rollback, gphoto sequence')
