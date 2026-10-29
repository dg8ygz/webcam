import contextlib, importlib.util, io, os, sqlite3, tempfile, unittest, types
from pathlib import Path
from unittest.mock import patch
from PIL import Image
spec=importlib.util.spec_from_file_location('crop',Path(__file__).with_name('crop.py'))
r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)

@contextlib.contextmanager
def transaction(path):
    with contextlib.closing(sqlite3.connect(path)) as db:
        with db:yield db

class CropTests(unittest.TestCase):
    def test_boundary_and_geometry(self):
        self.assertEqual(r.slot(Path('/2026/03/30/2220_hu.jpg')),r.FIRST)
        self.assertLess(r.slot(Path('/2026/03/30/2210_hu.jpg')),r.FIRST)
        self.assertEqual(r.slot(Path('/20260330_222000_1200.jpg')),r.FIRST)
        self.assertEqual(r.target_height(6000,4000),3700)
        self.assertEqual(r.target_height(816,544),503)
        self.assertEqual(r.target_height(816,459),459)
        self.assertEqual(r.target_height(400,247),247)
        with self.assertRaises(ValueError):r.target_height(6000,3900)

    def test_real_files_encrypted_backup_db_resume_and_restore(self):
        with tempfile.TemporaryDirectory() as t:
            root=Path(t);base=root/'www';state=root/'stromberg-privacy-test'
            file=base/'webcam/cache/stromberg/2026/03/30/20260330_222000.jpg'
            file.parent.mkdir(parents=True)
            im=Image.new('RGB',(600,400),'blue');im.paste('red',(0,370,600,400));im.save(file,quality=95)
            original=file.read_bytes();stat=file.stat()
            dbpath=root/'camera.sqlite';db=sqlite3.connect(dbpath)
            db.executescript('''CREATE TABLE captures(id INTEGER PRIMARY KEY,relative_path TEXT,hash_sha256 TEXT);
             CREATE TABLE variants(id INTEGER PRIMARY KEY,relative_path TEXT,width INTEGER,height INTEGER,size_bytes INTEGER,mtime INTEGER);''')
            relative='cache/stromberg/2026/03/30/20260330_222000.jpg'
            db.execute('INSERT INTO captures VALUES(1,?,?)',(relative,r.sha(original)))
            db.execute('INSERT INTO variants VALUES(1,?,600,400,?,?)',(relative,len(original),int(stat.st_mtime)))
            db.commit();db.close()
            with patch.object(r,'BASE',base),patch.object(r,'ARCH',base/'webcam2/storage/archive'),patch.object(r,'STATE_BASE',root),patch.object(r.shutil,'disk_usage',lambda _:types.SimpleNamespace(free=20*1024**3)),patch.object(r,'live_db',lambda _:sqlite3.connect(dbpath)),patch.object(r,'locks',lambda *_:contextlib.nullcontext()):
                c=r.state_open(state)
                c.execute('INSERT INTO files(path,slot,w,h,target,bytes,mtime,decision) VALUES(?,?,600,400,370,?,?,?)',
                          (str(file),r.FIRST,len(original),stat.st_mtime_ns,'crop'))
                c.executemany('INSERT INTO refs VALUES(?,?,?,1)',[(str(file),'webcam','capture'),(str(file),'webcam','variant')]);c.commit()
                row=c.execute('SELECT * FROM files').fetchone();r.apply_one(c,state,row)
                self.assertEqual(r.dimensions(file),(600,370))
                new=file.read_bytes();self.assertNotEqual(new,original)
                self.assertEqual(file.stat().st_mtime_ns,stat.st_mtime_ns)
                db=sqlite3.connect(dbpath)
                self.assertEqual(db.execute('SELECT hash_sha256 FROM captures').fetchone()[0],r.sha(new))
                self.assertEqual(db.execute('SELECT height FROM variants').fetchone()[0],370);db.close()
                # Simulate a crash after publish; repeat only metadata completion.
                c.execute("UPDATE files SET status='prepared'");c.commit()
                r.apply_one(c,state,c.execute('SELECT * FROM files').fetchone())
                self.assertEqual(file.read_bytes(),new)
                r.verify(c)
                db=sqlite3.connect(dbpath);db.execute('UPDATE variants SET height=400');db.commit();db.close()
                with self.assertRaisesRegex(RuntimeError,'unresolved'):r.verify(c)
                r.apply_one(c,state,c.execute('SELECT * FROM files').fetchone())
                r.restore(c,state,file)
                self.assertEqual(file.read_bytes(),original)
                db=sqlite3.connect(dbpath)
                self.assertEqual(db.execute('SELECT height FROM variants').fetchone()[0],400);db.close();c.close()

    def test_archived_database_fallback(self):
        archived=r.ARCH/'stromberg/2026/03/30/2230_lm.jpg'
        relative='cache/stromberg/2026/03/30/20260330_223000_lm.jpg'
        self.assertEqual(r.canonical('webcam',relative,{str(archived)}),str(archived))
        live=r.BASE/'webcam'/relative
        self.assertEqual(r.canonical('webcam',relative,{str(archived),str(live)}),str(live))

    def test_changed_source_is_not_overwritten(self):
        with tempfile.TemporaryDirectory() as t:
            base=Path(t);p=base/'webcam/cache/stromberg/20260330_222000.jpg';p.parent.mkdir(parents=True)
            Image.new('RGB',(600,400)).save(p)
            with patch.object(r,'BASE',base),patch.object(r,'locks',lambda *_:contextlib.nullcontext()):
                row={'path':str(p),'slot':r.FIRST,'decision':'crop','before_sha':None,'after_sha':None,'bytes':1,'mtime':0}
                with self.assertRaisesRegex(RuntimeError,'changed since inventory'):
                    r.apply_one(None,base,row)

class RetentionRaceTests(unittest.TestCase):
    def test_missing_cache_migrates_refs_and_requires_finished_archive(self):
        with tempfile.TemporaryDirectory() as t:
            root=Path(t);base=root/'www';state=root/'stromberg-privacy-test'
            missing=base/'webcam/cache/stromberg/2026/03/30/20260330_222000.jpg'
            archive=base/'webcam2/storage/archive'
            dbpath=root/'live.sqlite';db=sqlite3.connect(dbpath)
            db.executescript("CREATE TABLE captures(id INTEGER PRIMARY KEY,relative_path TEXT,hash_sha256 TEXT); CREATE TABLE variants(id INTEGER PRIMARY KEY,relative_path TEXT,width INTEGER,height INTEGER,size_bytes INTEGER,mtime INTEGER);")
            relative=str(missing.relative_to(base/'webcam'))
            db.execute('INSERT INTO captures VALUES(1,?,?)',(relative,'old'));db.commit();db.close()
            with patch.object(r,'BASE',base),patch.object(r,'ARCH',archive),patch.object(r,'STATE_BASE',root),patch.object(r,'live_db',lambda _:sqlite3.connect(dbpath)),patch.object(r,'locks',lambda *_:contextlib.nullcontext()),patch.object(r.shutil,'disk_usage',lambda _:types.SimpleNamespace(free=20*1024**3)):
                c=r.state_open(state)
                c.execute("INSERT INTO files(path,slot,w,h,target,bytes,mtime,decision) VALUES(?,?,600,400,370,1,0,'crop')",(str(missing),r.FIRST))
                c.execute("INSERT INTO refs VALUES(?,'webcam','capture',1)",(str(missing),));c.commit()
                row=c.execute('SELECT * FROM files WHERE path=?',(str(missing),)).fetchone()
                # Do not silently discard an arbitrary missing image.
                with self.assertRaisesRegex(RuntimeError,'lacks usable inventoried archive'):r.apply_one(c,state,row)
                archives=[]
                for suffix in ('hu','full','la','lm','sm'):
                    p=archive/'stromberg/2026/03/30'/('2220_'+suffix+'.jpg');p.parent.mkdir(parents=True,exist_ok=True)
                    Image.new('RGB',(600,400),'blue').save(p);st=p.stat();archives.append(p)
                    c.execute("INSERT INTO files(path,slot,w,h,target,bytes,mtime,decision) VALUES(?,?,600,400,370,?,?,'crop')",(str(p),r.FIRST,st.st_size,st.st_mtime_ns))
                numeric=missing.with_name('20260330_222000_150.jpg')
                c.execute("INSERT INTO files(path,slot,w,h,target,bytes,mtime,decision) VALUES(?,?,150,100,92,1,0,'crop')",(str(numeric),r.FIRST))
                c.execute("INSERT INTO refs VALUES(?,'webcam','variant',1)",(str(numeric),))
                with transaction(dbpath) as db:
                    db.execute('INSERT INTO variants VALUES(1,?,150,100,1,0)',(str(numeric.relative_to(base/'webcam')),))
                c.commit()
                r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(numeric),)).fetchone())
                with transaction(dbpath) as db:self.assertEqual(db.execute('SELECT height FROM variants').fetchone()[0],100)
                r.apply_one(c,state,row)
                self.assertEqual(c.execute('SELECT status FROM files WHERE path=?',(str(missing),)).fetchone()[0],'retired-cache')
                self.assertEqual(c.execute("SELECT path FROM refs WHERE kind='capture'").fetchone()[0],str(archives[0]))
                with self.assertRaisesRegex(RuntimeError,'unresolved'):r.verify(c)
                for p in archives:r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(p),)).fetchone())
                r.verify(c)
                with transaction(dbpath) as db:self.assertEqual(db.execute('SELECT hash_sha256 FROM captures').fetchone()[0],r.sha(archives[0].read_bytes()))
                missing.parent.mkdir(parents=True);missing.write_bytes(archives[0].read_bytes())
                with self.assertRaisesRegex(RuntimeError,'unresolved'):r.verify(c)
                c.close()

    def test_missing_prepared_file_stops(self):
        with self.assertRaisesRegex(RuntimeError,'Prepared/non-image'):
            r.reconcile_missing_cache(None,{'path':str(r.BASE/'webcam/cache/stromberg/a.jpg'),'before_sha':'hash','after_sha':None,'decision':'crop'})

class DecodeFailureTests(unittest.TestCase):
    def test_corrupt_source_deferred_with_backup_next_file_runs_storage_error_fatal(self):
        with tempfile.TemporaryDirectory() as t:
            root=Path(t);base=root/'www';state=root/'stromberg-privacy-test'
            bad=base/'webcam/cache/stromberg/2026/04/23/20260423_042000.jpg'
            good=bad.with_name('20260423_043000.jpg');bad.parent.mkdir(parents=True)
            Image.new('RGB',(600,400),'blue').save(bad)
            good.write_bytes(bad.read_bytes());damaged=bad.read_bytes()[:-100];bad.write_bytes(damaged)
            dbpath=root/'camera.sqlite'
            with transaction(dbpath) as db:
                db.execute('CREATE TABLE captures(id INTEGER PRIMARY KEY,relative_path TEXT,hash_sha256 TEXT)')
                db.execute('INSERT INTO captures VALUES(1,?,?)',(str(bad.relative_to(base/'webcam')),r.sha(damaged)))
            with patch.object(r,'BASE',base),patch.object(r,'ARCH',base/'webcam2/storage/archive'),patch.object(r,'STATE_BASE',root),patch.object(r,'live_db',lambda _:sqlite3.connect(dbpath)),patch.object(r,'locks',lambda *_:contextlib.nullcontext()),patch.object(r.shutil,'disk_usage',lambda _:types.SimpleNamespace(free=20*1024**3)):
                c=r.state_open(state)
                for p in (bad,good):
                    st=p.stat();c.execute("INSERT INTO files(path,slot,w,h,target,bytes,mtime,decision) VALUES(?,?,600,400,370,?,?,'crop')",(str(p),r.slot(p),st.st_size,st.st_mtime_ns))
                c.execute("INSERT INTO refs VALUES(?,'webcam','capture',1)",(str(bad),));c.commit()
                row=c.execute('SELECT * FROM files WHERE path=?',(str(bad),)).fetchone()
                self.assertFalse(r.apply_record(c,state,row))
                self.assertEqual(bad.read_bytes(),damaged)
                record=c.execute('SELECT * FROM file_failures').fetchone()
                self.assertEqual(record['source_sha'],r.sha(damaged))
                self.assertEqual(r.crypt(Path(record['backup_path']).read_bytes(),state/'backup.key',True),damaged)
                self.assertEqual(c.execute('SELECT status FROM files WHERE path=?',(str(bad),)).fetchone()[0],'review-decode')
                with transaction(dbpath) as db:self.assertEqual(db.execute('SELECT hash_sha256 FROM captures').fetchone()[0],r.sha(damaged))
                self.assertTrue(r.apply_record(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(good),)).fetchone()))
                self.assertEqual(r.dimensions(good),(600,370))
                with self.assertRaisesRegex(RuntimeError,'unresolved'):r.verify(c)
                # A backup disk/permission error must stop, not become a skipped JPEG.
                with patch.object(r,'backup',side_effect=OSError('backup storage failed')):
                    with self.assertRaisesRegex(OSError,'backup storage failed'):r.apply_record(c,state,row)
                prepared=dict(row);prepared['before_sha']=r.sha(damaged)
                with self.assertRaisesRegex(RuntimeError,'Prepared repair'):r.apply_record(c,state,prepared)
                c.close()

class OriginalOnlyTests(unittest.TestCase):
    @contextlib.contextmanager
    def fixture(self):
        with tempfile.TemporaryDirectory() as t, contextlib.ExitStack() as stack:
            root=Path(t);base=root/'www';state=root/'stromberg-privacy-test'
            dbpath=root/'camera.sqlite'
            with transaction(dbpath) as db:
                db.executescript('CREATE TABLE captures(id INTEGER PRIMARY KEY,relative_path TEXT,hash_sha256 TEXT); CREATE TABLE variants(id INTEGER PRIMARY KEY,relative_path TEXT,width INTEGER,height INTEGER,size_bytes INTEGER,mtime INTEGER);')
            for name,value in [('BASE',base),('ARCH',base/'webcam2/storage/archive'),('STATE_BASE',root),('live_db',lambda _:sqlite3.connect(dbpath)),('locks',lambda *_:contextlib.nullcontext())]:
                stack.enter_context(patch.object(r,name,value))
            stack.enter_context(patch.object(r.shutil,'disk_usage',lambda _:types.SimpleNamespace(free=20*1024**3)))
            r._VERIFIED_BACKUPS.clear();r._SOURCE_BACKUPS.clear()
            c=r.state_open(state)
            files=[]
            for i,(suffix,w,h) in enumerate([('',600,400),('_150',150,100),('_300',300,200)],1):
                p=base/'webcam/cache/stromberg/2026/03/30'/('20260330_222000'+suffix+'.jpg')
                p.parent.mkdir(parents=True,exist_ok=True)
                im=Image.new('RGB',(w,h),'blue');im.paste('red',(0,h*37//40,w,h));im.save(p,quality=80)
                st=p.stat();files.append(p)
                c.execute("INSERT INTO files(path,slot,w,h,target,bytes,mtime,decision) VALUES(?,?,?,?,?,?,?,'crop')",(str(p),r.FIRST,w,h,r.target_height(w,h),st.st_size,st.st_mtime_ns))
                with transaction(dbpath) as db:
                    db.execute('INSERT INTO variants VALUES(?,?,?,?,?,?)',(i,str(p.relative_to(base/'webcam')),w,h,st.st_size,int(st.st_mtime)))
                c.execute("INSERT INTO refs VALUES(?,'webcam','variant',?)",(str(p),i))
            c.commit()
            try:yield c,state,files,dbpath
            finally:c.close()

    def test_all_sizes_cropped_one_backup_and_regenerated_restore(self):
        with self.fixture() as (c,state,files,dbpath):
            originals=[p.read_bytes() for p in files]
            # One variant first: originals need not have been processed yet.
            for p in (files[1],files[0],files[2]):
                r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(p),)).fetchone())
            self.assertEqual([r.dimensions(p) for p in files],[(600,370),(150,92),(300,185)])
            backups=list((r.BASE/'webcam/var/backups'/state.name/'encrypted').glob('*.enc'))
            self.assertEqual(len(backups),1)
            self.assertEqual(r.crypt(backups[0].read_bytes(),state/'backup.key',True),originals[0])
            self.assertEqual(c.execute("SELECT count(*) FROM backup_links WHERE mode='derived'").fetchone()[0],2)
            r.verify(c)
            r.restore(c,state,files[1])
            self.assertEqual(r.dimensions(files[1]),(150,100))
            with transaction(dbpath) as db:
                self.assertEqual(db.execute('SELECT width,height,size_bytes FROM variants WHERE id=2').fetchone(),(150,100,files[1].stat().st_size))
            r.restore(c,state,files[1]) # repeated surgical restore is safe
            r.restore(c,state,files[0]);self.assertEqual(files[0].read_bytes(),originals[0])

    def test_prepared_variant_resumes_without_individual_backup(self):
        with self.fixture() as (c,state,files,dbpath):
            row=c.execute('SELECT * FROM files WHERE path=?',(str(files[1]),)).fetchone()
            real=r.atomic
            def fail_publish(path,*args,**kwargs):
                if path==files[1]:raise PermissionError('publish denied')
                return real(path,*args,**kwargs)
            with patch.object(r,'atomic',side_effect=fail_publish):
                with self.assertRaises(PermissionError):r.apply_one(c,state,row)
            row=c.execute('SELECT * FROM files WHERE path=?',(str(files[1]),)).fetchone()
            self.assertEqual(row['status'],'prepared')
            r._VERIFIED_BACKUPS.clear();r._SOURCE_BACKUPS.clear()
            r.apply_one(c,state,row)
            self.assertEqual(r.dimensions(files[1]),(150,92))
            self.assertFalse((r.BASE/'webcam/var/backups'/state.name/'encrypted'/(row['before_sha']+'.enc')).exists())

    def test_missing_or_corrupt_original_leaves_variant_untouched(self):
        with self.fixture() as (c,state,files,dbpath):
            data=files[0].read_bytes();variant=files[1].read_bytes()
            files[0].unlink()
            row=c.execute('SELECT * FROM files WHERE path=?',(str(files[1]),)).fetchone()
            self.assertFalse(r.apply_record(c,state,row));self.assertEqual(files[1].read_bytes(),variant)
            self.assertEqual(c.execute('SELECT status FROM files WHERE path=?',(str(files[1]),)).fetchone()[0],'review-source')
            files[0].write_bytes(data[:-100]);st=files[0].stat()
            c.execute('UPDATE files SET bytes=?,mtime=? WHERE path=?',(st.st_size,st.st_mtime_ns,str(files[0])));c.commit()
            self.assertFalse(r.apply_record(c,state,row));self.assertEqual(files[1].read_bytes(),variant)
            with self.assertRaisesRegex(RuntimeError,'unresolved'):r.verify(c)

    def test_tampered_original_backup_not_reused(self):
        with self.fixture() as (c,state,files,dbpath):
            r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(files[0]),)).fetchone())
            r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(files[1]),)).fetchone())
            backup=next((r.BASE/'webcam/var/backups'/state.name/'encrypted').glob('*.enc'))
            backup.write_bytes(b'corrupt')
            data=files[2].read_bytes()
            with self.assertRaises(Exception):r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(files[2]),)).fetchone())
            self.assertEqual(files[2].read_bytes(),data)

    def test_fullsize_clone_shares_backup_distinct_fullsize_keeps_own(self):
        with self.fixture() as (c,state,files,dbpath):
            for suffix,color in [('hu',None),('full','green')]:
                p=files[0].with_name('20260330_222000_'+suffix+'.jpg')
                if color:Image.new('RGB',(600,400),color).save(p)
                else:p.write_bytes(files[0].read_bytes())
                original=p.read_bytes();st=p.stat()
                c.execute("INSERT INTO files(path,slot,w,h,target,bytes,mtime,decision) VALUES(?,?,600,400,370,?,?,'crop')",(str(p),r.FIRST,st.st_size,st.st_mtime_ns));c.commit()
                r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(p),)).fetchone())
                r.restore(c,state,p);self.assertEqual(p.read_bytes(),original)
            self.assertEqual(len(list((r.BASE/'webcam/var/backups'/state.name/'encrypted').glob('*.enc'))),2)

    def test_preexisting_prepared_variant_keeps_exact_backup(self):
        with self.fixture() as (c,state,files,dbpath):
            original=files[1].read_bytes();r.backup(original,state)
            c.execute("UPDATE files SET before_sha=?,status='prepared' WHERE path=?",(r.sha(original),str(files[1])));c.commit()
            r.apply_one(c,state,c.execute('SELECT * FROM files WHERE path=?',(str(files[1]),)).fetchone())
            self.assertIsNone(c.execute('SELECT * FROM backup_links WHERE path=?',(str(files[1]),)).fetchone())
            r.restore(c,state,files[1]);self.assertEqual(files[1].read_bytes(),original)

class AccessRetryTests(unittest.TestCase):
    def test_retry_reads_prepared_journal_state(self):
        c=sqlite3.connect(':memory:');c.row_factory=sqlite3.Row
        c.execute('CREATE TABLE files(path TEXT,status TEXT)')
        c.execute("INSERT INTO files VALUES('/image.jpg','pending')");c.commit()
        calls=[]
        def action(db,state,row):
            calls.append(row['status'])
            if len(calls)==1:
                db.execute("UPDATE files SET status='prepared'");db.commit()
                raise PermissionError('publish denied')
            return True
        with patch.object(r,'apply_record',side_effect=action),patch.object(r.time,'sleep') as sleep:
            self.assertTrue(r.apply_with_retry(c,None,c.execute('SELECT * FROM files').fetchone()))
            self.assertEqual(calls,['pending','prepared']);sleep.assert_called_once_with(5)
        c.close()

    def test_persistent_denial_stops_after_three_attempts(self):
        c=sqlite3.connect(':memory:');c.row_factory=sqlite3.Row
        c.execute('CREATE TABLE files(path TEXT)');c.execute("INSERT INTO files VALUES('/image.jpg')");c.commit()
        with patch.object(r,'apply_record',side_effect=PermissionError('denied')) as action,patch.object(r.time,'sleep') as sleep:
            with self.assertRaises(PermissionError):r.apply_with_retry(c,None,c.execute('SELECT * FROM files').fetchone())
            self.assertEqual(action.call_count,3);self.assertEqual([call.args[0] for call in sleep.call_args_list],[5,15])
        with patch.object(r,'apply_record',side_effect=RuntimeError('DB changed')) as action,patch.object(r.time,'sleep') as sleep:
            with self.assertRaises(RuntimeError):r.apply_with_retry(c,None,c.execute('SELECT * FROM files').fetchone())
            self.assertEqual(action.call_count,1);sleep.assert_not_called()
        c.close()

if __name__=='__main__':unittest.main()
