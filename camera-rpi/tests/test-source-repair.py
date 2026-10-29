from pathlib import Path
import tempfile,subprocess,os,shutil
# Local fixture files and fake service commands only; never touches live units.
if os.geteuid()!=0:
 print('SKIP: root-owned configuration fixture requires root');raise SystemExit(0)
repo=Path(__file__).resolve().parents[1]
script=(repo/'ops/repair-source-cli-20260928.sh').read_text()
for bad in (False,True):
 with tempfile.TemporaryDirectory(prefix='source-repair-fixture-') as td:
  root=Path(td)
  for d in ['libexec','sbin','etc/servers','etc/keys','backup','runtime','spool']:(root/d).mkdir(parents=True,exist_ok=True)
  for name in ['camera-rpi-common.sh','camera-rpi-worker','camera-rpi-pool.sh']:
   shutil.copy2(repo/'scripts'/name if name=='camera-rpi-worker' else repo/'scripts/lib'/name,root/'libexec'/name)
  (root/'libexec/camera-source.php').write_text('');(root/'sbin/camera-rpi-source').write_text('')
  (root/'etc/camera-source.conf').write_text('');(root/'etc/camera-source.conf').chmod(0o600)
  (root/'etc/capture-pool.conf').write_text('source_server=dev-test\n');(root/'etc/capture-pool.conf').chmod(0o600)
  for name in ['dev-test','test']:
   key=root/'etc/keys'/f'{name}.key';key.write_text('fixture-token\n');key.chmod(0o600)
   conf=root/'etc/servers'/f'{name}.conf'
   conf.write_text(f'server_id={name}\nserver_base_url=https://example.invalid\ncamera_slug=test\nkey_file={key}\nspool_root={root}/spool\nruntime_root={root}/runtime\nmanage_root_mount=false\ncapture_backend=v4l2\n'+('video_width=0\n' if bad else ''))
   conf.chmod(0o600)
  local=script
  for a,b in [('/usr/local/libexec',str(root/'libexec')),('/usr/local/sbin',str(root/'sbin')),('/etc/camera-rpi',str(root/'etc')),('/root/',str(root/'backup')+'/'),('/run/lock/camera-rpi.operation.lock',str(root/'operation.lock')),('/run/camera-source-feedback-',str(root/'runtime/feedback-'))]:local=local.replace(a,b)
  run=root/'repair.sh';run.write_text(local)
  env=os.environ.copy();env.update(CAMERA_RPI_COMMON_LIB=str(root/'libexec/camera-rpi-common.sh'),CAMERA_RPI_SOURCE_CONFIG=str(root/'etc/camera-source.conf'),CAMERA_RPI_POOL_CONFIG=str(root/'etc/capture-pool.conf'))
  mocks='hostname(){ echo pi-cam-olaf; }; systemctl(){ if [[ $1 == is-active ]]; then echo active; fi; }; systemd-run(){ :; }; findmnt(){ echo rw; }; mount(){ return 99; }; sync(){ :; }; export -f hostname systemctl systemd-run findmnt mount sync; bash "$1" --apply'
  result=subprocess.run(['bash','-c',mocks,'test',str(run)],env=env,capture_output=True,text=True,timeout=30)
  if bad:
   assert result.returncode!=0,result.stdout
   assert (root/'libexec/camera-source.php').stat().st_size==0
   assert (root/'etc/camera-source.conf').stat().st_size==0
  else:
   assert result.returncode==0,(result.stdout,result.stderr)
   assert (root/'libexec/camera-source.php').stat().st_size>0
   assert 'source=rtsp\n' in (root/'etc/camera-source.conf').read_text()
   assert (root/'sbin/camera-rpi-source').stat().st_size>0
  print('OK: full repair with empty helper/CLI/config; '+('rollback on real worker validation failure' if bad else 'both real worker startup checks pass')+'; mocked services only')
