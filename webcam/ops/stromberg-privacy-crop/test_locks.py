"""Lock conflicts must never be mistaken for image permission failures."""
import errno
import contextlib
import os
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest.mock import patch
from test_crop import r


class LockTests(unittest.TestCase):
    def test_completion_retries_acquisition_but_not_body(self):
        attempts=[];released=[]
        @contextlib.contextmanager
        def locks(*args):
            attempts.append(1)
            if len(attempts)<3:raise r.LockUnavailable(errno.EACCES,'busy')
            try:yield
            finally:released.append(1)
        with patch.object(r,'locks',locks),patch.object(r.time,'sleep') as sleep:
            with self.assertRaisesRegex(RuntimeError,'body failed'):
                with r.completion_locks(Path('/image'),0):raise RuntimeError('body failed')
        self.assertEqual(len(attempts),3)
        self.assertEqual(released,[1])
        self.assertEqual([c.args[0] for c in sleep.call_args_list],[2,5])

    def test_completion_persistent_conflict_is_bounded_and_never_enters(self):
        for code in (errno.EACCES,errno.EAGAIN):
            with self.subTest(errno=code),patch.object(r,'locks',side_effect=r.LockUnavailable(code,'busy')) as locks,patch.object(r.time,'sleep') as sleep:
                with self.assertRaises(r.LockUnavailable):
                    with r.completion_locks(Path('/image'),0):self.fail('Entered without lock')
                self.assertEqual(locks.call_count,7)
                self.assertEqual([c.args[0] for c in sleep.call_args_list],[2,5,10,20,30,30])

    def test_completion_open_denied_is_not_a_lock_conflict(self):
        with patch.object(r,'locks',side_effect=PermissionError(errno.EACCES,'open denied')),patch.object(r.time,'sleep') as sleep:
            with self.assertRaises(PermissionError):
                with r.completion_locks(Path('/image'),0):self.fail('Entered despite denied open')
            sleep.assert_not_called()

    def test_local_conflict_and_release(self):
        with tempfile.TemporaryDirectory() as root:
            p=Path(root)/'shared.lock';p.touch()
            with patch.object(r,'lock_paths',return_value=[p]):
                with r.locks(p,0):
                    with self.assertRaises(r.LockUnavailable):
                        with r.locks(p,0):self.fail('Entered without the lock')
                with r.locks(p,0):pass

    def test_smb_eacces_is_deferred_and_all_acquired_handles_close(self):
        with tempfile.TemporaryDirectory() as root:
            paths=[Path(root)/str(i) for i in range(2)];seen=[]
            def flock(handle,flags):
                seen.append(handle)
                if len(seen)==2:raise PermissionError(errno.EACCES,'SMB lock conflict')
            with patch.object(r,'lock_paths',return_value=paths),patch.object(r.fcntl,'flock',side_effect=flock):
                with self.assertRaises(r.LockUnavailable) as error:
                    with r.locks(paths[0],0):self.fail('Entered without all locks')
            self.assertIn(str(paths[1]),str(error.exception))
            self.assertTrue(all(f.closed for f in seen))

    def test_denied_open_and_unrelated_flock_error_are_not_busy(self):
        with tempfile.TemporaryDirectory() as root:
            p=Path(root)/'lock';p.touch()
            with patch.object(r,'lock_paths',return_value=[p]),patch.object(r.os,'open',side_effect=PermissionError(errno.EACCES,'open denied')):
                with self.assertRaises(PermissionError):
                    with r.locks(p,0):self.fail('Open was denied')
            with patch.object(r,'lock_paths',return_value=[p]),patch.object(r.fcntl,'flock',side_effect=OSError(errno.EIO,'I/O failure')):
                with self.assertRaises(OSError) as error:
                    with r.locks(p,0):self.fail('Lock failed')
                self.assertNotIsInstance(error.exception,r.LockUnavailable)

    def test_busy_retries_are_bounded_and_journal_stays_pending(self):
        c=sqlite3.connect(':memory:');c.row_factory=sqlite3.Row
        try:
            c.execute('CREATE TABLE files(path TEXT,status TEXT)')
            c.execute("INSERT INTO files VALUES('/image.jpg','pending')");c.commit()
            with patch.object(r,'apply_record',side_effect=r.LockUnavailable(errno.EACCES,'busy','lock')) as action,patch.object(r.time,'sleep') as sleep:
                with self.assertRaises(r.LockUnavailable):r.apply_with_retry(c,None,c.execute('SELECT * FROM files').fetchone())
                self.assertEqual(action.call_count,3)
                self.assertEqual([call.args[0] for call in sleep.call_args_list],[5,15])
                self.assertEqual(c.execute('SELECT status FROM files').fetchone()[0],'pending')
        finally:c.close()

if __name__=='__main__':unittest.main()
