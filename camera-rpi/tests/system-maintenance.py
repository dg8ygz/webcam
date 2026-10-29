#!/usr/bin/env python3
"""Real flock/process tests with isolated mount and maintenance-command doubles."""
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'scripts/camera-rpi-system-maintenance'


class MaintenanceTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        (self.root/'root-mode').write_text('ro')
        (self.root/'boot-mode').write_text('ro')
        fake = self.root/'fake'
        fake.write_text('''#!/usr/bin/python3
import os,pathlib,sys,time
p=pathlib.Path(os.environ['TEST_ROOT']); cmd=pathlib.Path(sys.argv[0]).name; args=sys.argv[1:]
with (p/'calls').open('a') as f:f.write(cmd+' '+' '.join(args)+'\\n')
if cmd=='findmnt':
 if '-M' in args:sys.exit(0 if args[-1]=='/boot' or (args[-1]=='/boot/firmware' and (p/'firmware-mode').exists()) else 1)
 print((p/('root-mode' if args[-1]=='/' else 'firmware-mode' if args[-1]=='/boot/firmware' else 'boot-mode')).read_text())
elif cmd=='mount':
 if args[-2]=='remount,ro' and (p/'fail-ro').exists():sys.exit(1)
 (p/('root-mode' if args[-1]=='/' else 'firmware-mode' if args[-1]=='/boot/firmware' else 'boot-mode')).write_text(args[-2].split(',')[1])
elif cmd=='systemctl':
 marker=p/('failed-'+args[1])
 if args[0]=='show':print('failed' if marker.exists() else 'inactive')
 elif args[0]=='reset-failed':
  if not marker.exists():sys.exit(1)
  marker.unlink()
elif cmd=='apt':
 if (p/'block').exists():
  (p/'entered').touch()
  time.sleep(60)
 if args[0]=='update' and (p/'fail-job').exists():sys.exit(17)
elif cmd=='clock' and (p/'fail-clock').exists():sys.exit(9)
elif cmd=='python' and (p/'fail-listchanges').exists():sys.exit(7)
elif cmd=='timeout':
 import subprocess
 sys.exit(subprocess.call(args[2:]))
''')
        fake.chmod(0o755)
        self.env = {**os.environ, 'TEST_ROOT':str(self.root),
                    'CAMERA_RPI_MAINT_STATE_DIR':str(self.root/'state'),
                    'CAMERA_RPI_OPERATION_LOCK':str(self.root/'operation.lock')}
        for var, name in [('FINDMNT','findmnt'),('MOUNT','mount'),('SYNC','sync'),
                          ('SYSTEMCTL','systemctl'),('APT_DAILY','apt'),('LOGROTATE','logrotate'),
                          ('FAKE_HWCLOCK','clock'),('LOCATE_DAILY','locate'),
                          ('PYTHON','python'),('DPKG_BACKUP','dpkg-backup'),('TIMEOUT','timeout')]:
            target=self.root/name
            target.symlink_to(fake)
            self.env['CAMERA_RPI_'+var+'_BIN']=str(target)

    def run_mode(self, mode='run'):
        return subprocess.run(['bash',str(SCRIPT),mode],env=self.env,capture_output=True,text=True,timeout=45)

    def test_success_restores_both_mounts_and_job_order(self):
        r=self.run_mode()
        self.assertEqual(r.returncode,0,r.stderr)
        self.assertEqual((self.root/'root-mode').read_text(),'ro')
        self.assertEqual((self.root/'boot-mode').read_text(),'ro')
        calls=(self.root/'calls').read_text()
        self.assertLess(calls.index('apt update'),calls.index('apt install'))
        self.assertLess(calls.index('apt install'),calls.index('logrotate /etc'))
        self.assertLess(calls.index('mount -o remount,ro /boot'),calls.index('mount -o remount,ro /\n'))

    def test_failed_job_still_runs_logrotate_and_restores_ro(self):
        (self.root/'fail-job').touch()
        r=self.run_mode()
        self.assertNotEqual(r.returncode,0)
        self.assertIn('OK logrotate.service',r.stdout)
        self.assertNotIn('reset-failed apt-daily.service',(self.root/'calls').read_text())
        self.assertEqual((self.root/'root-mode').read_text(),'ro')

    def test_optional_legacy_jobs_restore_ro_and_clear_only_successful_failures(self):
        self.env['CAMERA_RPI_MAINT_LEGACY_JOBS']='1'
        (self.root/'failed-fake-hwclock-save.service').touch()
        (self.root/'failed-locate.service').touch()
        (self.root/'fail-clock').touch()
        r=self.run_mode()
        self.assertNotEqual(r.returncode,0)
        calls=(self.root/'calls').read_text()
        self.assertIn('clock save',calls)
        self.assertIn('locate systemd-timer',calls)
        self.assertTrue((self.root/'failed-fake-hwclock-save.service').exists())
        self.assertFalse((self.root/'failed-locate.service').exists())
        self.assertEqual((self.root/'root-mode').read_text(),'ro')
        self.assertEqual((self.root/'boot-mode').read_text(),'ro')

    def test_legacy_jobs_are_opt_in(self):
        r=self.run_mode()
        self.assertEqual(r.returncode,0,r.stderr)
        calls=(self.root/'calls').read_text()
        self.assertNotIn('clock save',calls)
        self.assertNotIn('locate systemd-timer',calls)

    def test_invalid_legacy_switch_never_remounts(self):
        self.env['CAMERA_RPI_MAINT_LEGACY_JOBS']='yes'
        r=self.run_mode()
        self.assertEqual(r.returncode,2)
        self.assertFalse((self.root/'calls').exists())

    def test_trixie_jobs_are_opt_in(self):
        r=self.run_mode()
        self.assertEqual(r.returncode,0,r.stderr)
        calls=(self.root/'calls').read_text()
        self.assertNotIn('populate_database',calls)
        self.assertNotIn('dpkg-backup',calls)

    def test_trixie_failure_keeps_failure_but_runs_backup_and_restores_ro(self):
        self.env['CAMERA_RPI_MAINT_TRIXIE_JOBS']='1'
        (self.root/'failed-apt-listchanges.service').touch()
        (self.root/'failed-dpkg-db-backup.service').touch()
        (self.root/'fail-listchanges').touch()
        r=self.run_mode()
        self.assertNotEqual(r.returncode,0)
        calls=(self.root/'calls').read_text()
        self.assertIn('python -m apt_listchanges.populate_database --profile apt --no-network',calls)
        self.assertIn('timeout --kill-after=5 120',calls)
        self.assertIn('OK dpkg-db-backup.service',r.stdout)
        self.assertTrue((self.root/'failed-apt-listchanges.service').exists())
        self.assertFalse((self.root/'failed-dpkg-db-backup.service').exists())
        self.assertEqual((self.root/'root-mode').read_text(),'ro')

    def test_invalid_trixie_switch_never_remounts(self):
        self.env['CAMERA_RPI_MAINT_TRIXIE_JOBS']='yes'
        r=self.run_mode()
        self.assertEqual(r.returncode,2)
        self.assertFalse((self.root/'calls').exists())

    def test_resets_only_genuinely_failed_units(self):
        marker=self.root/'failed-apt-daily.service'
        marker.touch()
        r=self.run_mode()
        self.assertEqual(r.returncode,0,r.stderr)
        self.assertFalse(marker.exists())
        calls=(self.root/'calls').read_text()
        self.assertIn('reset-failed apt-daily.service',calls)
        self.assertNotIn('reset-failed logrotate.service',calls)

    def test_firmware_alias_and_existing_ram_logrotate_state(self):
        (self.root/'firmware-mode').write_text('ro')
        self.env['CAMERA_RPI_LOGROTATE_STATE']='/var/log/logrotate.status'
        r=self.run_mode()
        self.assertEqual(r.returncode,0,r.stderr)
        self.assertEqual((self.root/'firmware-mode').read_text(),'ro')
        self.assertEqual((self.root/'boot-mode').read_text(),'ro')
        self.assertIn('logrotate --state /var/log/logrotate.status /etc/logrotate.conf',(self.root/'calls').read_text())

    def test_foreign_rw_window_is_not_taken_over(self):
        (self.root/'root-mode').write_text('rw')
        r=self.run_mode()
        self.assertNotEqual(r.returncode,0)
        self.assertEqual((self.root/'root-mode').read_text(),'rw')
        self.assertNotIn('mount -o',(self.root/'calls').read_text())

    def test_ro_failure_is_reported_and_marker_retained_for_recovery(self):
        (self.root/'fail-ro').touch()
        r=self.run_mode()
        self.assertNotEqual(r.returncode,0)
        self.assertTrue((self.root/'state/root').exists())
        (self.root/'fail-ro').unlink()
        self.assertEqual(self.run_mode('restore').returncode,0)
        self.assertEqual((self.root/'root-mode').read_text(),'ro')

    def blocked(self):
        (self.root/'block').touch()
        p=subprocess.Popen(['bash',str(SCRIPT),'run'],env=self.env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        def cleanup():
            try:os.killpg(p.pid,signal.SIGKILL)
            except ProcessLookupError:pass
            p.wait(timeout=10)
        self.addCleanup(cleanup)
        deadline=time.monotonic()+25
        while not (self.root/'entered').exists():
            if time.monotonic()>deadline:self.fail('child did not start')
            time.sleep(.02)
        return p

    def test_sigterm_restores_ro_under_operation_lock(self):
        p=self.blocked()
        locked=subprocess.run(['flock','-n',str(self.root/'operation.lock'),'true'])
        self.assertNotEqual(locked.returncode,0)
        os.killpg(p.pid,signal.SIGTERM)
        self.assertNotEqual(p.wait(timeout=30),0)
        self.assertEqual((self.root/'root-mode').read_text(),'ro')

    def test_sigkill_then_execstoppost_recovers_ro(self):
        p=self.blocked()
        os.killpg(p.pid,signal.SIGKILL)
        p.wait(timeout=3)
        self.assertEqual((self.root/'root-mode').read_text(),'rw')
        self.assertEqual(self.run_mode('restore').returncode,0)
        self.assertEqual((self.root/'root-mode').read_text(),'ro')
        self.assertEqual((self.root/'boot-mode').read_text(),'ro')


if __name__=='__main__':
    unittest.main(verbosity=2)
