"""Private JPEG/SQLite fixtures only; no production DBs, mounts or locks."""
import json
import sqlite3
import unittest
from unittest.mock import patch
from PIL import Image
import test_crop
r = test_crop.r
transaction = test_crop.transaction


class RecoveryTests(unittest.TestCase):
    fixture = test_crop.OriginalOnlyTests.fixture

    def crop(self, c, state, files):
        for p in files:
            r.apply_one(c, state, c.execute('SELECT * FROM files WHERE path=?', (str(p),)).fetchone())

    def archives(self, c):
        paths, _ = r.cache_archive_paths(r.BASE/'webcam/cache/stromberg/2026/03/30/20260330_222000.jpg')
        for p in paths:
            p.parent.mkdir(parents=True, exist_ok=True)
            Image.new('RGB', (600, 370), 'green').save(p)
            st = p.stat()
            c.execute("INSERT INTO files(path,slot,w,h,target,bytes,mtime,decision) VALUES(?,?,600,370,370,?,?,'keep')",
                      (str(p), r.FIRST, st.st_size, st.st_mtime_ns))
        c.commit()
        return paths

    def test_restore_conflict_keeps_public_crop_and_db_unchanged(self):
        for mutation in ("relative_path='other.jpg'", 'height=123'):
            with self.subTest(mutation=mutation), self.fixture() as (c, state, files, dbpath):
                self.crop(c, state, files)
                cropped = files[0].read_bytes()
                with transaction(dbpath) as db:
                    db.execute('UPDATE variants SET '+mutation+' WHERE id=1')
                before = dbpath.read_bytes()
                with self.assertRaisesRegex(RuntimeError, 'Restore DB row changed'):
                    r.restore(c, state, files[0])
                self.assertEqual(files[0].read_bytes(), cropped)
                self.assertEqual(dbpath.read_bytes(), before)
                self.assertEqual(c.execute('SELECT status FROM files WHERE path=?', (str(files[0]),)).fetchone()[0], 'done')

    def test_restore_publish_failure_is_journalled_and_resumable(self):
        with self.fixture() as (c, state, files, dbpath):
            original = files[0].read_bytes()
            self.crop(c, state, files)
            cropped = files[0].read_bytes()
            with patch.object(r, 'atomic', side_effect=OSError('publish failed')):
                with self.assertRaisesRegex(OSError, 'publish failed'):r.restore(c, state, files[0])
            self.assertEqual(files[0].read_bytes(), cropped)
            self.assertEqual(c.execute('SELECT status FROM files WHERE path=?', (str(files[0]),)).fetchone()[0], 'restoring')
            with self.assertRaisesRegex(RuntimeError, 'resolve before applying'):r.apply(c, state, 0)
            with self.assertRaisesRegex(RuntimeError, 'unresolved'):r.verify(c)
            r.restore(c, state, files[0])
            self.assertEqual(files[0].read_bytes(), original)
            self.assertEqual(c.execute('SELECT status FROM files WHERE path=?', (str(files[0]),)).fetchone()[0], 'restored')

    def test_second_database_commit_failure_keeps_crop_retry_finishes_both(self):
        with self.fixture() as (c, state, files, dbpath):
            original = files[0].read_bytes()
            second = dbpath.with_name('second.sqlite')
            self.crop(c, state, files)
            second.write_bytes(dbpath.read_bytes())
            refs=json.loads(c.execute('SELECT refs_before FROM files WHERE path=?',(str(files[0]),)).fetchone()[0])
            refs.append({**refs[0], 'site':'webcam2'})
            c.execute('UPDATE files SET refs_before=? WHERE path=?',(json.dumps(refs),str(files[0])));c.commit()
            def open_db(site):return sqlite3.connect(second if site=='webcam2' else dbpath)
            with patch.object(r, 'live_db', side_effect=open_db):
                cropped = files[0].read_bytes()
                class CommitFailure:
                    def __init__(self, db):self.db=db
                    def __getattr__(self, name):return getattr(self.db, name)
                    def commit(self):raise sqlite3.OperationalError('second commit failed')
                with patch.object(r, 'live_db', side_effect=lambda site: CommitFailure(open_db(site)) if site=='webcam2' else open_db(site)):
                    with self.assertRaisesRegex(sqlite3.OperationalError, 'second commit'):r.restore(c, state, files[0])
                self.assertEqual(files[0].read_bytes(), cropped)
                with transaction(dbpath) as db:self.assertEqual(db.execute('SELECT height FROM variants WHERE id=1').fetchone()[0],400)
                with transaction(second) as db:self.assertEqual(db.execute('SELECT height FROM variants WHERE id=1').fetchone()[0],370)
                r.restore(c, state, files[0])
                self.assertEqual(files[0].read_bytes(), original)
                with transaction(second) as db:self.assertEqual(db.execute('SELECT height FROM variants WHERE id=1').fetchone()[0],400)

    def test_crash_after_publish_does_not_leave_done_and_retry_finishes(self):
        with self.fixture() as (c, state, files, dbpath):
            original = files[0].read_bytes()
            self.crop(c, state, files)
            real = r.atomic
            def crash(*args, **kwargs):
                real(*args, **kwargs)
                raise OSError('crash after publish')
            with patch.object(r, 'atomic', side_effect=crash):
                with self.assertRaisesRegex(OSError, 'crash after publish'):r.restore(c, state, files[0])
            self.assertEqual(files[0].read_bytes(), original)
            self.assertEqual(c.execute('SELECT status FROM files WHERE path=?',(str(files[0]),)).fetchone()[0],'restoring')
            r.restore(c, state, files[0])
            self.assertEqual(c.execute('SELECT status FROM files WHERE path=?',(str(files[0]),)).fetchone()[0],'restored')

    def test_completed_retention_reconciles_hash_metadata_and_numeric_variants(self):
        with self.fixture() as (c, state, files, dbpath):
            relative=str(files[0].relative_to(r.BASE/'webcam'))
            with transaction(dbpath) as db:
                db.execute('INSERT INTO captures VALUES(1,?,?)',(relative,r.sha(files[0].read_bytes())))
            c.execute("INSERT INTO refs VALUES(?,'webcam','capture',1)",(str(files[0]),));c.commit()
            self.crop(c,state,files)
            paths=self.archives(c)
            for p in files:p.unlink()
            with self.assertRaisesRegex(RuntimeError,'unresolved'):r.verify(c)
            # Simulate a DB error after durable reference moves; resume must work.
            with patch.object(r,'update_refs',side_effect=sqlite3.OperationalError('database busy')):
                with self.assertRaisesRegex(sqlite3.OperationalError,'busy'):r.reconcile_completed(c)
            self.assertEqual(c.execute('SELECT status FROM files WHERE path=?',(str(files[0]),)).fetchone()[0],'retiring-cache')
            r.reconcile_completed(c)
            r.verify(c)
            with transaction(dbpath) as db:
                self.assertEqual(db.execute('SELECT hash_sha256 FROM captures').fetchone()[0],r.sha(paths[0].read_bytes()))
                self.assertEqual(db.execute('SELECT width,height,size_bytes FROM variants WHERE id=1').fetchone(),(600,370,paths[0].stat().st_size))
                self.assertEqual(db.execute('SELECT height FROM variants WHERE id=2').fetchone()[0],92)
            # Repeated reconciliation is a no-op and a reappearing cache fails.
            r.reconcile_completed(c)
            files[0].write_bytes(paths[0].read_bytes())
            with self.assertRaisesRegex(RuntimeError,'unresolved'):r.verify(c)

    def test_retention_rejects_missing_truncated_uncropped_archive_and_changed_db(self):
        for defect in ('missing','truncated','uncropped','changed-db'):
            with self.subTest(defect=defect), self.fixture() as (c,state,files,dbpath):
                self.crop(c,state,files);paths=self.archives(c);files[0].unlink()
                if defect=='missing':paths[0].unlink()
                elif defect=='truncated':paths[0].write_bytes(paths[0].read_bytes()[:-100])
                elif defect=='uncropped':Image.new('RGB',(600,400)).save(paths[0])
                else:
                    with transaction(dbpath) as db:db.execute("UPDATE variants SET relative_path='elsewhere.jpg' WHERE id=1")
                before=dbpath.read_bytes()
                with self.assertRaises((OSError,RuntimeError)):r.reconcile_completed(c)
                self.assertEqual(dbpath.read_bytes(),before)
                self.assertEqual(c.execute('SELECT status FROM files WHERE path=?',(str(files[0]),)).fetchone()[0],'done')

    def test_archive_proof_is_reused_but_changed_file_is_rechecked(self):
        with self.fixture() as (c,state,files,dbpath):
            paths=self.archives(c)
            with patch.object(r.Image,'open',wraps=r.Image.open) as decode:
                r.checked_archive(c,paths[0]);r.checked_archive(c,paths[0])
                self.assertEqual(decode.call_count,1)
                paths[0].write_bytes(paths[0].read_bytes()[:-100])
                with self.assertRaises(OSError):r.checked_archive(c,paths[0])
                self.assertEqual(decode.call_count,2)

    def test_new_restore_commands_pin_new_code_preserving_old_snapshot(self):
        with self.fixture() as (c,state,files,dbpath):
            self.crop(c,state,files)
            (state/'crop.py').write_text('historical snapshot')
            c.execute("INSERT INTO meta VALUES('scan_complete','1')");c.commit()
            r.apply(c,state,0)
            self.assertEqual((state/'crop.py').read_text(),'historical snapshot')
            snapshots=list(state.glob('crop-*.py'))
            self.assertEqual(len(snapshots),1)
            self.assertEqual(snapshots[0].read_bytes(),r.Path(r.__file__).read_bytes())
            self.assertIn(str(snapshots[0]),(state/'restore-commands.txt').read_text())


if __name__=='__main__':unittest.main()
