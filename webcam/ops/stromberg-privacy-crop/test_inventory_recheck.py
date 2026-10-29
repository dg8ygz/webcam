"""A transient inventory read error must not be mistaken for a bad backup."""
import json
import os
import sqlite3
import unittest
from unittest.mock import patch
import test_crop

r = test_crop.r


class InventoryRecheckTests(unittest.TestCase):
    def mark(self, c, path):
        c.execute("UPDATE files SET w=0,h=0,target=0,decision='review:OSError' WHERE path=?", (str(path),))
        c.commit()

    def test_recovered_header_has_exact_backup_and_variant_can_resume(self):
        with test_crop.OriginalOnlyTests.fixture(self) as (c, state, files, db):
            original = files[0].read_bytes()
            self.mark(c, files[0])
            variant = c.execute('SELECT * FROM files WHERE path=?', (str(files[1]),)).fetchone()
            with self.assertRaisesRegex(RuntimeError, 'inventory dimensions unknown'):
                r.apply_one(c, state, variant)
            r.recheck_inventory_errors(c, state)
            self.assertEqual(files[0].read_bytes(), original)
            row = c.execute('SELECT * FROM files WHERE path=?', (str(files[0]),)).fetchone()
            self.assertEqual((row['w'], row['h'], row['target'], row['decision']), (600,400,370,'crop'))
            self.assertIsNone(row['before_sha'])
            evidence = next(state.glob('inventory-io-recheck-before-*'))
            saved = sqlite3.connect(evidence/'inventory.sqlite')
            self.assertEqual(saved.execute('SELECT w,h FROM files WHERE path=?',(str(files[0]),)).fetchone(), (0,0))
            saved.close()
            proof = json.loads((evidence/'rechecked.jsonl').read_text())
            self.assertEqual(r.crypt(r.Path(proof['backup']).read_bytes(),state/'backup.key',True), original)
            r.apply_one(c,state,variant)
            self.assertEqual(r.dimensions(files[1]), (150,92))

    def test_changed_file_not_adopted(self):
        with test_crop.OriginalOnlyTests.fixture(self) as (c,state,files,db):
            self.mark(c,files[0]); files[0].write_bytes(b'changed')
            r.recheck_inventory_errors(c,state)
            self.assertEqual(c.execute('SELECT w FROM files WHERE path=?',(str(files[0]),)).fetchone()[0],0)

    def test_header_only_success_does_not_accept_truncated_jpeg(self):
        with test_crop.OriginalOnlyTests.fixture(self) as (c,state,files,db):
            files[0].write_bytes(files[0].read_bytes()[:-100])
            st=files[0].stat(); self.mark(c,files[0])
            c.execute('UPDATE files SET bytes=?,mtime=? WHERE path=?',(st.st_size,st.st_mtime_ns,str(files[0])));c.commit()
            r.recheck_inventory_errors(c,state)
            self.assertEqual(c.execute('SELECT w FROM files WHERE path=?',(str(files[0]),)).fetchone()[0],0)

    def test_backup_failure_stays_fatal_and_does_not_change_inventory(self):
        with test_crop.OriginalOnlyTests.fixture(self) as (c,state,files,db):
            self.mark(c,files[0])
            with patch.object(r,'backup',side_effect=OSError('backup unavailable')):
                with self.assertRaisesRegex(OSError,'backup unavailable'):r.recheck_inventory_errors(c,state)
            self.assertEqual(c.execute('SELECT w FROM files WHERE path=?',(str(files[0]),)).fetchone()[0],0)

if __name__=='__main__':unittest.main()
