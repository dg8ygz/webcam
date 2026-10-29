"""Connection reuse and original proof caching preserve recovery checks."""
import contextlib
import os
from pathlib import Path
import sqlite3
import unittest
from unittest.mock import patch
import test_crop
r=test_crop.r

class EfficiencyTests(unittest.TestCase):
    def test_one_connection_and_short_transactions_across_images(self):
        with test_crop.OriginalOnlyTests.fixture(self) as (c,state,files,dbpath):
            opened=[];statements=[]
            def connect(site):
                db=sqlite3.connect(dbpath);db.set_trace_callback(statements.append);opened.append(db);return db
            with patch.object(r,'live_db',side_effect=connect),r.reuse_apply_databases():
                for p in files:
                    r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(p),)).fetchone())
                    self.assertFalse(opened[0].in_transaction)
                    with test_crop.transaction(dbpath) as other:
                        other.execute('BEGIN IMMEDIATE');other.commit()
                self.assertEqual(len(opened),1)
            with self.assertRaises(sqlite3.ProgrammingError):opened[0].execute('SELECT 1')
            self.assertEqual(sum(s=='BEGIN IMMEDIATE' for s in statements),3)

    def test_same_site_refs_rollback_together_on_conflict(self):
        with test_crop.OriginalOnlyTests.fixture(self) as (c,state,files,dbpath):
            refs=r.read_refs(c,files[0])+r.read_refs(c,files[1])
            refs[1]['before']['relative_path']='changed'
            with r.reuse_apply_databases():
                with self.assertRaisesRegex(RuntimeError,'DB path changed'):
                    r.update_refs(refs,files[0],'hash',600,370,20,0)
                with r.reference_db('webcam') as db:
                    self.assertFalse(db.in_transaction)
                    self.assertEqual(db.execute('SELECT height FROM variants WHERE id=1').fetchone()[0],400)

    def test_database_replacement_opens_new_connection(self):
        with test_crop.OriginalOnlyTests.fixture(self) as (c,state,files,dbpath),r.reuse_apply_databases():
            with r.reference_db('webcam') as first:pass
            replacement=dbpath.with_name('replacement.sqlite')
            with contextlib.closing(sqlite3.connect(replacement)) as db:
                db.execute('CREATE TABLE marker(value TEXT)');db.execute("INSERT INTO marker VALUES('new')");db.commit()
            os.replace(replacement,dbpath)
            with r.reference_db('webcam') as second:
                self.assertIsNot(first,second)
                self.assertEqual(second.execute('SELECT value FROM marker').fetchone()[0],'new')

    def test_sibling_reuses_original_proof_without_decrypt_or_backup_read(self):
        with test_crop.OriginalOnlyTests.fixture(self) as (c,state,files,dbpath):
            r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(files[0]),)).fetchone())
            actual=Path.read_bytes
            def read(path):
                if path.suffix=='.enc':raise AssertionError('Unnecessary encrypted backup read')
                return actual(path)
            with patch.object(r,'crypt',side_effect=AssertionError('Unnecessary crypto')),patch.object(Path,'read_bytes',read):
                r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(files[1]),)).fetchone())
            self.assertEqual(r.dimensions(files[1]),(150,92))

    def test_changed_backup_invalidates_reused_proof(self):
        with test_crop.OriginalOnlyTests.fixture(self) as (c,state,files,dbpath):
            r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(files[0]),)).fetchone())
            backup=next(iter(r._SOURCE_BACKUPS.values()))['backup']
            Path(backup).write_bytes(b'broken backup')
            before=files[1].read_bytes()
            with self.assertRaises(Exception):
                r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(files[1]),)).fetchone())
            self.assertEqual(files[1].read_bytes(),before)

if __name__=='__main__':unittest.main()
