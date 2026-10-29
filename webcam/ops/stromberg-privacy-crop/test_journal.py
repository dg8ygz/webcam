"""Real SQLite locking tests on private local databases only."""
import contextlib
from pathlib import Path
import sqlite3
import tempfile
import threading
import unittest
from unittest.mock import patch
import test_crop
r = test_crop.r

class JournalTests(unittest.TestCase):
    @contextlib.contextmanager
    def journal(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);state=root/'stromberg-privacy-test'
            with patch.object(r,'STATE_BASE',root):
                c=r.state_open(state)
                try:yield c,state
                finally:c.close()

    def test_old_delete_mode_reader_blocks_commit(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/'old.sqlite'
            with contextlib.closing(sqlite3.connect(path,timeout=.05)) as writer,contextlib.closing(sqlite3.connect(path)) as reader:
                writer.execute('CREATE TABLE progress(status TEXT)')
                writer.execute("INSERT INTO progress VALUES('prepared')");writer.commit()
                reader.execute('BEGIN');reader.execute('SELECT * FROM progress').fetchall()
                writer.execute("UPDATE progress SET status='done'")
                with self.assertRaises(sqlite3.OperationalError):writer.commit()
                reader.rollback();writer.commit()

    def test_wal_reader_does_not_block_durable_completion(self):
        with self.journal() as (writer,state):
            writer.execute("INSERT INTO files(path,status) VALUES('image','prepared')");writer.commit()
            with contextlib.closing(sqlite3.connect(state/'inventory.sqlite')) as reader:
                reader.execute('BEGIN');reader.execute('SELECT * FROM files').fetchall()
                writer.execute("UPDATE files SET status='done'");writer.commit()
                self.assertEqual(reader.execute('SELECT status FROM files').fetchone()[0],'prepared')
                reader.rollback()
                self.assertEqual(reader.execute('SELECT status FROM files').fetchone()[0],'done')
            self.assertEqual(writer.execute('PRAGMA synchronous').fetchone()[0],2)
            self.assertEqual(writer.execute('PRAGMA busy_timeout').fetchone()[0],60000)

    def test_short_competing_writer_is_waited_for(self):
        with self.journal() as (writer,state):
            ready=threading.Event();errors=[]
            def competitor():
                try:
                    with contextlib.closing(sqlite3.connect(state/'inventory.sqlite')) as other:
                        other.execute('BEGIN IMMEDIATE');ready.set()
                        threading.Event().wait(.15);other.commit()
                except Exception as error:errors.append(error);ready.set()
            thread=threading.Thread(target=competitor);thread.start()
            try:
                self.assertTrue(ready.wait(2))
                writer.execute("INSERT INTO meta VALUES('test','complete')");writer.commit()
            finally:thread.join(2)
            self.assertFalse(errors)
            self.assertFalse(thread.is_alive())

    def test_network_mount_rejected_before_database_creation(self):
        with tempfile.TemporaryDirectory() as tmp,patch.object(r,'STATE_BASE',Path(tmp)),patch.object(r.subprocess,'check_output',return_value='cifs\n'):
            state=Path(tmp)/'stromberg-privacy-test'
            with self.assertRaisesRegex(RuntimeError,'local filesystem'):r.state_open(state)
            self.assertFalse((state/'inventory.sqlite').exists())

    def test_prepared_published_file_resumes_without_reencoding(self):
        with test_crop.OriginalOnlyTests.fixture(self) as (c,state,files,dbpath):
            path=files[0]
            r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(path),)).fetchone())
            data=path.read_bytes()
            c.execute("UPDATE files SET status='prepared' WHERE path=?",(str(path),));c.commit()
            with patch.object(r,'atomic',side_effect=AssertionError('Must not publish twice')),patch.object(r,'backup_for_file',side_effect=AssertionError('Must not back up twice')):
                r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(path),)).fetchone())
            self.assertEqual(path.read_bytes(),data)
            self.assertEqual(c.execute('SELECT status FROM files WHERE path=?',(str(path),)).fetchone()[0],'done')

if __name__=='__main__':unittest.main()
