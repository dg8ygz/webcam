#!/usr/bin/env python3
"""Stromberg privacy repair from 2026-03-30 22:20 Europe/Berlin.

Default: header-only inventory. --apply uses that inventory, private encrypted
backups and a durable per-file journal. No old-PROD or camera access.
"""
import argparse, contextlib, datetime as dt, fcntl, hashlib, io, json, math
import errno, os, re, secrets, shlex, shutil, sqlite3, stat, subprocess, sys, tempfile, time, traceback
from pathlib import Path
from zoneinfo import ZoneInfo
from collections import OrderedDict
from PIL import Image

BASE = Path('/var/www/html')
SITES = ('webcam', 'webcam2')
ZONE = ZoneInfo('Europe/Berlin')
FIRST = int(dt.datetime(2026, 3, 30, 22, 20, tzinfo=ZONE).timestamp())
ARCH = BASE/'webcam2/storage/archive'
STATE_BASE = Path('/var/local/webcam')
Image.MAX_IMAGE_PIXELS = 30000000
_VERIFIED_BACKUPS = OrderedDict()
_SOURCE_BACKUPS = OrderedDict()
_CHECKED_ARCHIVES = OrderedDict()
_APPLY_DBS = None

class LockUnavailable(BlockingIOError):
    """Nonblocking flock failed; never continue without the shared lock."""

class SourceBackupUnavailable(RuntimeError):
    """A variant must not be changed without a usable original backup."""
    def __init__(self, source, message):
        super().__init__(message)
        self.source = str(source)

def remember(cache, key, value):
    cache[key] = value
    cache.move_to_end(key)
    while len(cache) > 32: cache.popitem(last=False)

class SourceDecodeError(RuntimeError):
    """Only invalid in-memory source JPEGs; storage/backup failures stay fatal."""
    def __init__(self, message, digest, backup_path):
        super().__init__(message)
        self.digest = digest
        self.backup_path = str(backup_path)

def log(message):
    print(dt.datetime.now(ZONE).isoformat(timespec='seconds'), message, flush=True)

def sha(data):
    return hashlib.sha256(data).hexdigest()

def dimensions(path):
    with Image.open(path) as image:
        if image.format != 'JPEG': raise RuntimeError('Not JPEG: '+str(path))
        return image.size

def target_height(w, h):
    """Keep already shorter pictures; never expand or crop the same file twice."""
    safe = w * 3700 // 6000
    if safe < 1: raise ValueError('Invalid size')
    if h <= math.ceil(w * 3700 / 6000): return h
    if w > 6000 or abs(h - w*2/3) > 1:
        raise ValueError('Unexpected geometry; manual review required')
    return safe

def slot(path):
    """Use the recording filename, never filesystem mtime."""
    text = str(path)
    m = re.search(r'(20\d{6})_(\d{6})(?:_|\.)', path.name)
    if m: return int(dt.datetime.strptime(''.join(m.groups()), '%Y%m%d%H%M%S').replace(tzinfo=ZONE).timestamp())
    m = re.search(r'/(20\d{2})/(\d{2})/(\d{2})/(\d{4})(?:_|\.)', text)
    if m: return int(dt.datetime.strptime(''.join(m.groups()), '%Y%m%d%H%M').replace(tzinfo=ZONE).timestamp())
    if path.suffix.lower() in ('.mp4', '.webm'):
        m = re.search(r'/(20\d{2})/(\d{2})/(\d{2})/', text)
        if m: return int(dt.datetime.strptime(''.join(m.groups()), '%Y%m%d').replace(tzinfo=ZONE).timestamp()) + 86399
    return None

def roots():
    paths=[]
    for site in SITES:
        root=BASE/site
        paths += [root/'cache/stromberg', root/'stromberg', root/'storage/stromberg',
                  root/'storage/current/stromberg', root/'storage/bestof/stromberg']
    paths.append(ARCH/'stromberg')
    return paths

def canonical(site, relative, inventory=None):
    p = Path(relative)
    if p.is_absolute() or '..' in p.parts: raise RuntimeError('Unsafe DB path')
    if relative.startswith('archive/stromberg/'):
        return str(ARCH/relative[len('archive/'):])
    resolved = BASE/site/p if relative.startswith('cache/') else BASE/site/'storage'/p
    exists = str(resolved) in inventory if inventory is not None else resolved.exists()
    if relative.startswith('cache/stromberg/') and not exists:
        match = re.fullmatch(r'cache/stromberg/(20\d{2})/(\d{2})/(\d{2})/\d{8}_(\d{4})\d{2}(?:_(\w+))?\.jpg',relative)
        if match:
            year,month,day,hhmm,variant=match.groups()
            fallback=ARCH/'stromberg'/year/month/day/(hhmm+'_'+(variant or 'hu')+'.jpg')
            if (str(fallback) in inventory if inventory is not None else fallback.is_file()): return str(fallback)
    return str(resolved)

def guards():
    for site, mode in [('webcam','ro'),('webcam2','rw')]:
        p=BASE/site/'storage/archive'
        fs=json.loads(subprocess.check_output(['findmnt','-J','-T',str(p),'-o','TARGET,SOURCE,FSTYPE,OPTIONS'],timeout=10))['filesystems'][0]
        if fs['target']!=str(p) or fs['source']!='//192.0.2.7/webcam_archive' or fs['fstype']!='cifs' or mode not in fs['options'].split(','):
            raise RuntimeError('Archive mount mismatch: '+str(p))
        if not (p/'.webcam-archive-mounted').is_file(): raise RuntimeError('Missing archive sentinel')
    for site in SITES:
        c=sqlite3.connect(f'file:/var/lib/{site}/webcams.sqlite?mode=ro',uri=True)
        row=c.execute("SELECT base_path FROM storage_cfg WHERE camera_id='stromberg'").fetchone();c.close()
        if row != ('storage/stromberg',): raise RuntimeError('Changed Stromberg storage configuration')

def state_open(path):
    if path.parent != STATE_BASE or not re.fullmatch(r'stromberg-privacy-[a-zA-Z0-9_-]+',path.name):
        raise RuntimeError('State must be /var/local/webcam/stromberg-privacy-<name>')
    path.mkdir(mode=0o700,exist_ok=True)
    if path.is_symlink() or path.stat().st_uid != os.getuid(): raise RuntimeError('Unsafe state owner/path')
    os.chmod(path,0o700)
    # Status readers must not block the writer's completion commit. WAL is
    # deliberately restricted to this LOCAL repair journal, never camera DBs
    # or NAS files. FULL keeps the durable prepared/done recovery boundary.
    journal=path/'inventory.sqlite'
    if journal.is_symlink():raise RuntimeError('Journal must not be a symlink')
    fs=subprocess.check_output(['findmnt','-n','-o','FSTYPE','-T',str(path)],text=True,timeout=10).strip()
    if fs not in ('ext4','xfs','btrfs','tmpfs','overlay'):
        raise RuntimeError('Repair journal requires a local filesystem; found '+fs)
    c=sqlite3.connect(journal,timeout=60);c.row_factory=sqlite3.Row
    try:
        if c.execute('PRAGMA journal_mode=WAL').fetchone()[0]!='wal':
            raise RuntimeError('Local journal WAL could not be enabled')
        c.execute('PRAGMA synchronous=FULL')
    except Exception:
        c.close();raise
    c.executescript('''
    CREATE TABLE IF NOT EXISTS dirs(path TEXT PRIMARY KEY);
    CREATE TABLE IF NOT EXISTS files(path TEXT PRIMARY KEY, slot INTEGER, w INTEGER, h INTEGER,
      target INTEGER, bytes INTEGER, mtime INTEGER, decision TEXT, status TEXT DEFAULT 'pending',
      before_sha TEXT, after_sha TEXT, refs_before TEXT, after_bytes INTEGER);
    CREATE TABLE IF NOT EXISTS refs(path TEXT, site TEXT, kind TEXT, id INTEGER);
    CREATE INDEX IF NOT EXISTS refs_path ON refs(path);
    CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY,v TEXT);
    CREATE TABLE IF NOT EXISTS retired_cache(path TEXT PRIMARY KEY, archive_paths TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS file_failures(path TEXT PRIMARY KEY, error TEXT NOT NULL,
      source_sha TEXT NOT NULL, backup_path TEXT NOT NULL, recorded_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS backup_links(path TEXT PRIMARY KEY, source_path TEXT NOT NULL,
      source_sha TEXT NOT NULL, mode TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS backup_issues(path TEXT PRIMARY KEY, source_path TEXT NOT NULL,
      error TEXT NOT NULL);
    ''')
    return c

def index_refs(c):
    c.execute('DELETE FROM refs')
    inventory={r[0] for r in c.execute('SELECT path FROM files')}
    for site in SITES:
        db=sqlite3.connect(f'file:/var/lib/{site}/camera-db/stromberg/db.sqlite?mode=ro',uri=True)
        for kind, sql in [('capture','SELECT id,relative_path FROM captures WHERE slot_ts>=?'),
                          ('variant','SELECT v.id,v.relative_path FROM variants v JOIN captures c ON c.id=v.capture_id WHERE c.slot_ts>=?')]:
            for ident,relative in db.execute(sql,(FIRST,)):
                resolved=canonical(site,relative,inventory)
                if resolved in inventory:
                    c.execute('INSERT INTO refs VALUES(?,?,?,?)',(resolved,site,kind,ident))
        db.close()
    c.commit()

def scan(c):
    """Only known Stromberg trees, bounded traversal; checkpoint each directory."""
    for root in roots():
        if not root.exists(): continue
        for directory, dirs, names in os.walk(root,followlinks=False):
            p=Path(directory)
            # Old years are outside the operator's explicit repair interval.
            dirs[:] = sorted(d for d in dirs if not (p/d).is_symlink() and not d.startswith('.')
                             and d not in ('db_backups','overlays')
                             and not (re.fullmatch(r'20\d{2}',d) and int(d)<2026))
            m=re.search(r'/(20\d{2})/(\d{2})/(\d{2})(?:/|$)',str(p))
            if m and ''.join(m.groups())<'20260330': dirs[:]=[];continue
            if c.execute('SELECT 1 FROM dirs WHERE path=?',(str(p),)).fetchone(): continue
            for name in sorted(names):
                f=p/name
                if f.is_symlink(): raise RuntimeError('Symlink needs review: '+str(f))
                if f.suffix.lower() not in ('.jpg','.jpeg','.mp4','.webm','.png','.webp'): continue
                when=slot(f)
                current='current' in f.parts
                if when is not None and when<FIRST: continue
                if when is None and not current:
                    decision='review:no-slot'
                elif f.suffix.lower() in ('.mp4','.webm'):
                    decision='invalidate-video'
                elif f.suffix.lower() not in ('.jpg','.jpeg'):
                    decision='review:non-jpeg'
                else: decision='keep'
                st=f.stat();w=h=target=0
                if f.suffix.lower() in ('.jpg','.jpeg'):
                    try:
                        w,h=dimensions(f);target=target_height(w,h)
                        if decision=='keep' and target<h: decision='crop'
                    except (OSError,ValueError,RuntimeError) as e:
                        decision='review:'+type(e).__name__
                c.execute('INSERT OR IGNORE INTO files(path,slot,w,h,target,bytes,mtime,decision) VALUES(?,?,?,?,?,?,?,?)',
                          (str(f),when,w,h,target,st.st_size,st.st_mtime_ns,decision))
            c.execute('INSERT INTO dirs VALUES(?)',(str(p),));c.commit()
            log('INVENTORY '+str(p))
    index_refs(c)
    c.execute("INSERT OR REPLACE INTO meta VALUES('scan_complete',?)",(str(time.time()),));c.commit()
    report(c)

def report(c):
    for r in c.execute('SELECT decision,status,count(*) AS n,sum(bytes) AS bytes FROM files GROUP BY decision,status'):
        log(json.dumps(dict(r)))
    log('Inventory describes its scan time, not future uploads. Videos/unknown paths require separate review.')

def recheck_inventory_errors(c, state):
    """Revisit only unreadable inventory JPEGs, without changing public images.

    A failed header read recorded 0x0, not a trustworthy original size. Accept
    a later read only if file identity still matches, full decoding succeeds,
    and its exact bytes have a verified encrypted backup. Keep a journal
    snapshot and per-file evidence so this narrow correction is reviewable.
    """
    rows = c.execute("SELECT * FROM files WHERE decision='review:OSError' "
                     "AND status='pending' AND before_sha IS NULL "
                     "AND after_sha IS NULL ORDER BY path").fetchall()
    if not rows:
        log('INVENTORY RECHECK: no pending I/O errors'); return
    evidence = Path(tempfile.mkdtemp(prefix='inventory-io-recheck-before-', dir=state))
    with contextlib.closing(sqlite3.connect(evidence/'inventory.sqlite')) as saved:
        c.backup(saved)
        if saved.execute('PRAGMA quick_check').fetchone() != ('ok',):
            raise RuntimeError('Inventory snapshot failed')
    log('INVENTORY RECHECK BACKUP '+str(evidence))
    for row in rows:
        path = Path(row['path'])
        if (not any(path.is_relative_to(root) for root in roots())
                or row['slot'] is None or row['slot'] < FIRST
                or slot(path) != row['slot']):
            raise RuntimeError('Inventory recheck outside dated scope: '+str(path))
        with locks(path, row['slot']):
            if path.is_symlink(): raise RuntimeError('Inventory recheck symlink')
            try:
                before = path.stat()
                if not stat.S_ISREG(before.st_mode): raise RuntimeError('Not regular JPEG')
                if (before.st_size, before.st_mtime_ns) != (row['bytes'], row['mtime']):
                    log('INVENTORY REVIEW UNCHANGED: file identity changed '+str(path)); continue
                data = path.read_bytes()
                after = path.stat()
            except OSError as error:
                log('INVENTORY REVIEW UNCHANGED: '+str(path)+'; '+str(error)); continue
            if ((after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns)
                    != (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns)
                    or len(data) != row['bytes']):
                raise RuntimeError('File changed during inventory recheck')
            try:
                with Image.open(io.BytesIO(data)) as image:
                    if image.format != 'JPEG': raise ValueError('Not JPEG')
                    image.load(); w, h = image.size
                target = target_height(w, h)
            except (OSError, ValueError) as error:
                log('INVENTORY REVIEW UNCHANGED: '+str(path)+'; '+str(error)); continue
            encrypted = backup(data, state)  # Backup/storage errors remain fatal.
            decision = 'crop' if target < h else 'keep'
            proof = {'before': dict(row), 'w': w, 'h': h, 'target': target,
                     'decision': decision, 'sha256': sha(data), 'backup': str(encrypted)}
            with (evidence/'rechecked.jsonl').open('a') as out:
                out.write(json.dumps(proof)+'\n'); out.flush(); os.fsync(out.fileno())
            with c:
                c.execute('UPDATE files SET w=?,h=?,target=?,decision=? WHERE path=?',
                          (w, h, target, decision, str(path)))
            log('INVENTORY RECHECK OK '+str(path)+' '+str(w)+'x'+str(h))
    _SOURCE_BACKUPS.clear()

def lock_paths(path, when):
    paths=[Path('/var/lib/webcam/cache-retention-shared.lock')]
    m=re.search(r'/(20\d{2})/(\d{2})/(\d{2})/',str(path))
    if m: paths.append(ARCH/'.webcam-archive-locks'/('-'.join(m.groups())+'.lock'))
    if path.suffix.lower() in ('.mp4','.webm'):
        paths.append(Path(str(path)+'.lock'))
    for site in SITES:
        if when is not None:
            bucket=int(hashlib.sha256(str(when).encode()).hexdigest()[:2],16)%64
            paths.append(BASE/site/'var/state/upload-dedupe'/f'stromberg-bucket-{bucket}.lock')
        if 'current' in path.parts:
            paths.append(BASE/site/'var/state/current-publication/stromberg.lock')
    return paths


@contextlib.contextmanager
def locks(path, when):
    handles=[]
    try:
        for p in lock_paths(path,when):
            if not p.parent.is_dir(): raise RuntimeError('Missing lock directory: '+str(p.parent))
            # New shared locks must stay writable by the existing web-service owner.
            try:
                fd=os.open(p,os.O_RDWR|os.O_CREAT|os.O_EXCL,0o660)
                owner=p.parent.stat();os.fchown(fd,owner.st_uid,owner.st_gid);os.fchmod(fd,0o660)
            except FileExistsError: fd=os.open(p,os.O_RDWR)
            f=os.fdopen(fd,'a+');handles.append(f)
            try:
                fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)
            except OSError as error:
                # The real CIFS mount returns EACCES for a conflicting flock,
                # whereas local ext4 returns EAGAIN. Only classify the flock
                # call this way: denied open/chmod/read/write stays fatal.
                if error.errno in (errno.EACCES,errno.EAGAIN,errno.EWOULDBLOCK):
                    raise LockUnavailable(error.errno,'Shared flock unavailable: '+str(p),str(p)) from error
                raise
        yield
    finally:
        for f in reversed(handles): f.close()

def crypt(data,key,decrypt=False):
    args=['openssl','enc','-aes-256-cbc','-pbkdf2','-iter','100000','-pass','file:'+str(key)]
    if decrypt: args.append('-d')
    return subprocess.run(args,input=data,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=60,check=True).stdout

def backup(data,state):
    key=state/'backup.key'
    if not key.exists():
        fd=os.open(key,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        with os.fdopen(fd,'w') as f: f.write(secrets.token_hex(32));f.flush();os.fsync(f.fileno())
    dest=BASE/'webcam/var/backups'/state.name/'encrypted'
    dest.mkdir(parents=True,exist_ok=True)
    if shutil.disk_usage(dest).free < len(data)*3+10*1024**3: raise RuntimeError('Backup reserve below 10 GiB')
    p=dest/(sha(data)+'.enc')
    # Content-addressed originals/clones may occur several times per sequence.
    # Reuse a backup verified in THIS process only while its stat is unchanged.
    cache_key = str(p)
    try: st=p.stat()
    except FileNotFoundError: st=None
    if cache_key in _VERIFIED_BACKUPS and st is not None:
        if (st.st_ino,st.st_size,st.st_mtime_ns)==_VERIFIED_BACKUPS[cache_key]:return p
    if st is None:
        encrypted=crypt(data,key)
        if crypt(encrypted,key,True)!=data: raise RuntimeError('Encrypted backup validation failed')
        atomic(p,encrypted,None,0o600)
    elif sha(crypt(p.read_bytes(),key,True))!=sha(data): raise RuntimeError('Existing backup invalid')
    st=p.stat();remember(_VERIFIED_BACKUPS,cache_key,(st.st_ino,st.st_size,st.st_mtime_ns))
    return p

def sequence_original(path):
    """Same directory and recording stamp only; never guess across targets."""
    m=re.fullmatch(r'(20\d{6}_\d{6})(?:_(\d+|hu|full|la|lm|sm))?\.jpg',path.name)
    if m:return path.with_name(m[1]+'.jpg')
    m=re.fullmatch(r'(\d{4})_(hu|full|la|lm|sm)\.jpg',path.name)
    if m:return path.with_name(m[1]+'_hu.jpg')
    # Current/best-of/unknown layouts are independent unless explicitly proven.
    return path

def original_backup(c, state, source, row):
    recorded=c.execute('SELECT * FROM files WHERE path=?',(str(source),)).fetchone()
    if not recorded or recorded['slot']!=row['slot']:
        raise SourceBackupUnavailable(source,'No inventoried original for variant')
    if recorded['w'] <= 0 or recorded['h'] <= 0:
        raise RuntimeError('Original inventory dimensions unknown; use --recheck-inventory-errors: '+str(source))
    identity=(str(state),str(source),recorded['before_sha'],recorded['bytes'],recorded['mtime'])
    cached=_SOURCE_BACKUPS.get(identity)
    if cached:
        # Backup deletion/replacement must not silently lose the restore path.
        p=Path(cached['backup']);st=p.stat()
        if (st.st_ino,st.st_size,st.st_mtime_ns)==cached['stat']:return cached
    if recorded['before_sha']:
        # Original may already be cropped. Recover its unmodified source bytes,
        # never derive the restore source from the newly cropped public file.
        digest=recorded['before_sha']
        p=BASE/'webcam/var/backups'/state.name/'encrypted'/(digest+'.enc')
        data=crypt(p.read_bytes(),state/'backup.key',True)
        if sha(data)!=digest:raise RuntimeError('Original backup hash mismatch')
    else:
        if source.is_symlink():raise RuntimeError('Original source is a symlink')
        try:st=source.stat()
        except FileNotFoundError as e:
            raise SourceBackupUnavailable(source,'Original disappeared; no usable backup recorded') from e
        if (st.st_size,st.st_mtime_ns)!=(recorded['bytes'],recorded['mtime']):
            raise RuntimeError('Original changed since inventory')
        data=source.read_bytes();digest=sha(data)
        p=backup(data,state)
    try:
        with Image.open(io.BytesIO(data)) as image:
            image.load();w,h=image.size
    except OSError as e:
        raise SourceBackupUnavailable(source,'Original backup cannot be fully decoded: '+str(e)) from e
    if (w,h)!=(recorded['w'],recorded['h']):raise RuntimeError('Original backup dimensions mismatch')
    st=p.stat();result={'sha':digest,'w':w,'h':h,'backup':str(p),'stat':(st.st_ino,st.st_size,st.st_mtime_ns)}
    remember(_SOURCE_BACKUPS,identity,result)
    return result

def backup_for_file(c,state,row,data,refs):
    """Only originals get a new encrypted backup; variants get a restore recipe.

    Existing prepared repairs retain their old exact backup semantics. Unknown
    layouts and independent originals are never grouped merely by timestamp.
    """
    path=Path(row['path']);source=sequence_original(path)
    link=c.execute('SELECT * FROM backup_links WHERE path=?',(str(path),)).fetchone()
    if row['before_sha'] and not link:return backup(data,state)
    if source==path or row['decision']!='crop' or any(r['kind']=='capture' for r in refs):
        return backup(data,state)
    original=original_backup(c,state,source,row)
    if row['w']>original['w'] or row['h']>original['h']:
        raise SourceBackupUnavailable(source,'Variant exceeds original dimensions')
    # Do not silently discard a distinct full-resolution edited original.
    if (row['w'],row['h'])==(original['w'],original['h']) and sha(data)!=original['sha']:
        return backup(data,state)
    mode='exact' if sha(data)==original['sha'] else 'derived'
    with c:
        c.execute('INSERT OR REPLACE INTO backup_links VALUES(?,?,?,?)',
                  (str(path),str(source),original['sha'],mode))
    return Path(original['backup'])

def atomic(path,data,mtime,mode=0o664):
    fd,tmp=tempfile.mkstemp(prefix='.privacy-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
        os.chmod(tmp,mode)
        if mtime is not None: os.utime(tmp,ns=(mtime,mtime))
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def live_db(site):
    return sqlite3.connect(f'file:/var/lib/{site}/camera-db/stromberg/db.sqlite?mode=rw',uri=True,timeout=10)

@contextlib.contextmanager
def reuse_apply_databases():
    """Keep at most two connections, but never a transaction between files.

    Used only by apply. Restore keeps its separate transaction handling.
    Replaced database files force a fresh connection on the next access.
    """
    global _APPLY_DBS
    if _APPLY_DBS is not None:raise RuntimeError('Nested database reuse')
    _APPLY_DBS={}
    try:yield
    finally:
        entries=_APPLY_DBS;_APPLY_DBS=None
        for db,_,_ in entries.values():db.close()

@contextlib.contextmanager
def reference_db(site):
    if site not in SITES:raise RuntimeError('Unknown database site')
    db=None
    if _APPLY_DBS is not None and site in _APPLY_DBS:
        candidate,path,identity=_APPLY_DBS[site]
        try:
            st=path.stat()
            same=(st.st_dev,st.st_ino)==identity
        except FileNotFoundError:same=False
        if same:db=candidate
        else:
            candidate.close();del _APPLY_DBS[site]
    if db is None:
        db=live_db(site);db.row_factory=sqlite3.Row
        if _APPLY_DBS is not None:
            try:
                path=Path(db.execute('PRAGMA database_list').fetchone()[2])
                st=path.stat()
                _APPLY_DBS[site]=(db,path,(st.st_dev,st.st_ino))
            except Exception:
                db.close();raise
    try:
        if db.in_transaction:raise RuntimeError('Unfinished reference transaction')
        yield db
    finally:
        # A failed update must not retain a write lock for the next picture.
        if db.in_transaction:db.rollback()
        if _APPLY_DBS is None:db.close()

def read_refs(c,path):
    result=[]
    for ref in c.execute('SELECT site,kind,id FROM refs WHERE path=?',(str(path),)):
        site,kind,ident=ref
        table='captures' if kind=='capture' else 'variants'
        with reference_db(site) as db:
            row=db.execute('SELECT * FROM '+table+' WHERE id=?',(ident,)).fetchone()
        if row is None or canonical(site,row['relative_path'])!=str(path):
            raise RuntimeError('Database reference changed')
        result.append({'site':site,'kind':kind,'before':dict(row)})
    return result

def update_refs(refs,path,digest,w,h,size,mtime):
    by_site={}
    for ref in refs:
        by_site.setdefault(ref['site'],[]).append(ref)
    # Capture and variant metadata for this ONE file share one transaction
    # per target. Never batch across images or across the prepared checkpoint.
    for site,group in by_site.items():
        with reference_db(site) as db:
            db.execute('BEGIN IMMEDIATE')
            for ref in group:
                old=ref['before']
                table='captures' if ref['kind']=='capture' else 'variants'
                now=db.execute('SELECT * FROM '+table+' WHERE id=?',(old['id'],)).fetchone()
                if now is None or now['relative_path']!=old['relative_path']: raise RuntimeError('DB path changed')
                if table=='captures':
                    if now['hash_sha256'] not in (old['hash_sha256'],digest):raise RuntimeError('Capture hash changed')
                    db.execute('UPDATE captures SET hash_sha256=? WHERE id=?',(digest,old['id']))
                else:
                    # No timeline, EXIF, weather, likes, timestamps or labels are rebuilt.
                    db.execute('UPDATE variants SET width=?,height=?,size_bytes=?,mtime=? WHERE id=?',
                               (w,h,size,mtime//1000000000,old['id']))
            db.commit()

def cache_archive_paths(path):
    match = re.fullmatch(r'(webcam2?)/cache/stromberg/(20\d{2})/(\d{2})/(\d{2})/(\d{8})_(\d{4})00(?:_(\w+))?\.jpg',
                         str(path.relative_to(BASE)))
    if not match:raise RuntimeError('Missing file outside supported cache layout')
    site,year,month,day,date,hhmm,variant=match.groups()
    if date!=year+month+day:raise RuntimeError('Cache filename/date mismatch')
    paths=[ARCH/'stromberg'/year/month/day/(hhmm+'_'+v+'.jpg') for v in ('hu','full','la','lm','sm')]
    return paths,variant


def checked_archive(c, path):
    """Read the whole fallback JPEG: a valid header alone cannot prove safety."""
    row=c.execute('SELECT * FROM files WHERE path=?',(str(path),)).fetchone()
    if not row or row['decision'] not in ('crop','keep'):
        raise RuntimeError('Missing cache lacks usable inventoried archive: '+str(path))
    if path.is_symlink() or not stat.S_ISREG(path.stat().st_mode):
        raise RuntimeError('Archive fallback is not a regular file')
    st=path.stat()
    identity=(str(path),st.st_dev,st.st_ino,st.st_size,st.st_mtime_ns,st.st_ctime_ns,
              row['decision'],row['status'],row['after_sha'],row['w'],row['h'],
              row['target'],row['bytes'],row['mtime'])
    cached=_CHECKED_ARCHIVES.get(identity)
    if cached:return (row,*cached)
    data=path.read_bytes()
    with Image.open(io.BytesIO(data)) as image:
        image.load();w,h=image.size
        if image.format!='JPEG' or target_height(w,h)!=h:
            raise RuntimeError('Archive fallback is not a cropped JPEG')
    digest=sha(data);size=len(data)
    if row['decision']=='crop':
        if row['status']!='done' or digest!=row['after_sha'] or (w,h)!=(row['w'],row['target']):
            raise RuntimeError('Archive repair incomplete or changed')
    elif (w,h)!=(row['w'],row['h']) or (size,st.st_mtime_ns)!=(row['bytes'],row['mtime']):
        raise RuntimeError('Inventoried safe archive changed')
    after=path.stat()
    if (st.st_dev,st.st_ino,st.st_size,st.st_mtime_ns,st.st_ctime_ns)!=(after.st_dev,after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns):
        raise RuntimeError('Archive changed while checking')
    # Sibling thumbnails share the same five archive files. Keep only 32 small
    # proof records, no decoded images; any file/journal change forces a reread.
    result=(digest,size,w,h)
    remember(_CHECKED_ARCHIVES,identity,result)
    return (row,*result)



def reconcile_completed_cache(c, row):
    """Retire a cropped cache only after its real archive is complete.

    Save reference moves before touching live metadata, so an interrupted
    reconciliation can be retried without losing its original restore record.
    Numeric thumbnails have no archive counterpart; retain their DB rows.
    """
    path=Path(row['path'])
    if path.exists() or path.is_symlink():raise RuntimeError('Cache is not absent')
    archives,variant=cache_archive_paths(path)
    checked={str(p):checked_archive(c,p) for p in archives}
    changes=[]
    for ref in json.loads(row['refs_before'] or '[]'):
        old=ref['before'];db=live_db(ref['site']);db.row_factory=sqlite3.Row
        table='captures' if ref['kind']=='capture' else 'variants'
        try:live=db.execute('SELECT * FROM '+table+' WHERE id=?',(old['id'],)).fetchone()
        finally:db.close()
        if not live or live['relative_path']!=old['relative_path']:
            raise RuntimeError('Missing cache has changed database reference')
        fallback=canonical(ref['site'],live['relative_path'])
        if ref['kind']=='variant' and variant and variant.isdigit() and fallback==str(path):
            expected=(row['w'],row['target'],row['after_bytes'],row['mtime']//1000000000)
            if tuple(live[k] for k in ('width','height','size_bytes','mtime'))!=expected:
                raise RuntimeError('Numeric cache metadata changed')
            continue
        if fallback not in checked:raise RuntimeError('Missing cache has unresolved database reference')
        target,digest,size,w,h=checked[fallback]
        if ref['kind']=='capture':
            valid=live['hash_sha256'] in (row['after_sha'],digest)
        else:
            actual=tuple(live[k] for k in ('width','height','size_bytes','mtime'))
            valid=actual in ((row['w'],row['target'],row['after_bytes'],row['mtime']//1000000000),
                             (w,h,size,target['mtime']//1000000000))
        if not valid:raise RuntimeError('Missing cache metadata changed')
        changes.append((fallback,ref,target,digest,size,w,h))
    with c:
        c.execute("UPDATE files SET status='retiring-cache' WHERE path=?",(str(path),))
        c.execute('INSERT OR REPLACE INTO retired_cache VALUES(?,?)',(str(path),json.dumps([str(p) for p in archives])))
        for fallback,ref,target,digest,size,w,h in changes:
            # Append, never replace another site's or an earlier repair's record.
            saved=c.execute('SELECT refs_before FROM files WHERE path=?',(fallback,)).fetchone()[0]
            refs=json.loads(saved or '[]')
            if not any((x['site'],x['kind'],x['before']['id'])==(ref['site'],ref['kind'],ref['before']['id']) for x in refs):
                refs.append(ref)
            c.execute('UPDATE files SET refs_before=? WHERE path=?',(json.dumps(refs),fallback))
            c.execute('UPDATE refs SET path=? WHERE path=? AND site=? AND kind=? AND id=?',
                      (fallback,str(path),ref['site'],ref['kind'],ref['before']['id']))
    for fallback,ref,target,digest,size,w,h in changes:
        # Old capture hash here is the already-cropped cache hash, not the
        # pre-crop hash. update_refs still rejects concurrent path/hash changes.
        expected={'site':ref['site'],'kind':ref['kind'],'before':dict(ref['before'])}
        if ref['kind']=='capture':expected['before']['hash_sha256']=row['after_sha']
        update_refs([expected],Path(fallback),digest,w,h,size,target['mtime'])
    with c:c.execute("UPDATE files SET status='retired-cache' WHERE path=?",(str(path),))
    log('RECONCILED completed cache against cropped archive: '+str(path))


@contextlib.contextmanager
def completion_locks(path, when):
    """Wait briefly for active uploads, without weakening their shared locks.

    Only lock acquisition is retried. Once the body starts, its errors must
    propagate: repeating a partly completed database change would be unsafe.
    locks() closes every acquired handle before a failed attempt returns.
    """
    delays=(2,5,10,20,30,30)
    with contextlib.ExitStack() as held:
        for attempt in range(len(delays)+1):
            try:
                held.enter_context(locks(path,when))
                break
            except LockUnavailable as error:
                if attempt==len(delays):raise
                delay=delays[attempt]
                log('RETRY completion lock '+str(attempt+1)+'/'+str(len(delays))+
                    ' after '+str(delay)+'s: '+str(path)+'; '+str(error))
                time.sleep(delay)
        yield


def reconcile_completed(c):
    """Explicit mutating pass; --verify itself stays read-only."""
    count=0
    for row in c.execute("SELECT * FROM files WHERE decision='crop' AND status IN ('done','retiring-cache')").fetchall():
        path=Path(row['path'])
        # Restrict to cache. A missing archive/current file must remain an error.
        if not any(path.is_relative_to(BASE/site/'cache/stromberg') for site in SITES):continue
        with completion_locks(path,row['slot']):
            if path.is_symlink():raise RuntimeError('Cache is a symlink')
            if not path.exists():reconcile_completed_cache(c,row)
        count+=1
        if count%1000==0:log('RECONCILE CHECKED '+str(count)+' '+str(path))
    log('RECONCILE finished: checked '+str(count)+' cache files')


def reconcile_missing_cache(c, row):
    """Retention may remove a scanned cache file before apply acquires its lock.

    Keep an explicit absence record, require the five inventoried archive
    variants, and move database references to their real archive fallback.
    This does not declare the archive safe: verify checks its final crop too.
    Unknown missing files or partially published repairs still stop the run.
    """
    path = Path(row['path'])
    if 'status' in row.keys() and row['decision']=='crop' and row['status'] in ('done','retiring-cache'):
        return reconcile_completed_cache(c,row)
    if row['before_sha'] or row['after_sha'] or row['decision'] != 'crop':
        raise RuntimeError('Prepared/non-image file missing; manual review required')
    archives,variant=cache_archive_paths(path)
    for archived in archives:
        recorded = c.execute('SELECT decision FROM files WHERE path=?', (str(archived),)).fetchone()
        if not recorded or recorded['decision'] not in ('crop', 'keep'):
            raise RuntimeError('Missing cache lacks usable inventoried archive: '+str(archived))
        if archived.is_symlink() or not stat.S_ISREG(archived.stat().st_mode):
            raise RuntimeError('Archive fallback is not a regular file')
        with Image.open(archived) as image:image.load()
    moves = []
    for ref in c.execute('SELECT site,kind,id FROM refs WHERE path=?', (str(path),)).fetchall():
        db = live_db(ref['site'])
        try:
            table = 'captures' if ref['kind'] == 'capture' else 'variants'
            live = db.execute('SELECT relative_path FROM '+table+' WHERE id=?', (ref['id'],)).fetchone()
        finally:
            db.close()
        fallback = canonical(ref['site'], live[0]) if live else None
        # Numeric cache sizes have no same-named archive file. Do not invent
        # a replacement path or attach e.g. 150px metadata to the hu original.
        # Their existing DB rows remain unchanged; absence and all five real
        # archive variants are checked at final verification.
        if (ref['kind'] == 'variant' and variant and variant.isdigit()
                and live and BASE/ref['site']/live[0] == path
                and fallback == str(path)):
            continue
        if fallback not in {str(p) for p in archives}:
            raise RuntimeError('Missing cache has unresolved database reference')
        target = c.execute('SELECT status FROM files WHERE path=?', (fallback,)).fetchone()
        if target['status'] != 'pending':
            raise RuntimeError('Archive already processed; reference reconciliation needs review')
        moves.append((fallback, str(path), ref['site'], ref['kind'], ref['id']))
    with c:
        c.executemany('UPDATE refs SET path=? WHERE path=? AND site=? AND kind=? AND id=?', moves)
        c.execute('INSERT OR REPLACE INTO retired_cache VALUES(?,?)',
                  (str(path), json.dumps([str(p) for p in archives])))
        c.execute("UPDATE files SET status='retired-cache' WHERE path=?", (str(path),))
    log('RECONCILED missing cache; archive still requires verification: '+str(path))

def apply_one(c,state,row):
    if 'status' in row.keys() and row['status'] in ('restoring','restored'):
        raise RuntimeError('Restore in progress/completed; refusing automatic recrop')
    path=Path(row['path'])
    if not any(path.is_relative_to(root) for root in roots()):raise RuntimeError('Path outside scope')
    if row['decision']=='invalidate-video' and row['before_sha'] and not path.exists():
        c.execute("UPDATE files SET status='done' WHERE path=?",(str(path),));c.commit();return
    with locks(path,row['slot']):
        if path.is_symlink():raise RuntimeError('Not regular file')
        try:
            st = path.stat()
        except FileNotFoundError:
            reconcile_missing_cache(c, row)
            return
        if not stat.S_ISREG(st.st_mode):raise RuntimeError('Not regular file')
        if 'current' in path.parts and not row['before_sha']:
            w,h=dimensions(path)
            if target_height(w,h)==h:
                c.execute("UPDATE files SET status='already-safe' WHERE path=?",(str(path),));c.commit();return
        data=path.read_bytes();digest=sha(data)
        if row['after_sha'] and digest==row['after_sha']:
            refs=json.loads(row['refs_before'])
            update_refs(refs,path,digest,row['w'],row['target'],len(data),row['mtime'])
        else:
            st=path.stat()
            if row['before_sha']:
                if digest!=row['before_sha']:raise RuntimeError('File changed after preparation')
            elif (st.st_size,st.st_mtime_ns)!=(row['bytes'],row['mtime']):
                raise RuntimeError('File changed since inventory; new inventory required')
            refs=json.loads(row['refs_before']) if row['refs_before'] else read_refs(c,path)
            backup_path = backup_for_file(c,state,row,data,refs)
            if row['decision']=='invalidate-video':
                c.execute("UPDATE files SET before_sha=?,refs_before='[]',status='prepared' WHERE path=?",(digest,str(path)));c.commit()
                if sha(path.read_bytes())!=digest:raise RuntimeError('Video changed')
                path.unlink()
                c.execute("UPDATE files SET status='done' WHERE path=?",(str(path),));c.commit();return
            # Decode strictly. Do not enable LOAD_TRUNCATED_IMAGES: it could
            # fabricate missing pixels and make a damaged source look repaired.
            try:
                image = Image.open(io.BytesIO(data))
                try:
                    image.load()
                except Exception:
                    image.close()
                    raise
            except OSError as error:
                raise SourceDecodeError(str(error), digest, backup_path) from error
            with image:
                if image.size!=(row['w'],row['h']): raise RuntimeError('Dimensions changed')
                if sequence_original(path)==path:
                    # The encrypted backup and this complete original were
                    # just validated. Sibling variants can reuse that proof,
                    # rather than rereading/decrypting/decoding it on the NAS.
                    # Only small metadata is retained; original_backup still
                    # checks the backup's identity before using this entry.
                    st_backup=backup_path.stat()
                    identity=(str(state),str(path),digest,row['bytes'],row['mtime'])
                    remember(_SOURCE_BACKUPS,identity,{
                        'sha':digest,'w':row['w'],'h':row['h'],
                        'backup':str(backup_path),
                        'stat':(st_backup.st_ino,st_backup.st_size,st_backup.st_mtime_ns)})
                with image.crop((0,0,row['w'],row['target'])) as cut:
                    out=io.BytesIO()
                    # Do not carry an embedded uncropped EXIF thumbnail into the result.
                    # Existing EXIF/weather sidecars and DB metadata remain untouched;
                    # the encrypted original preserves all original embedded metadata.
                    cut.convert('RGB').save(out,format='JPEG',quality=95,subsampling=0)
                    cropped=out.getvalue()
            with Image.open(io.BytesIO(cropped)) as image:
                image.load()
                if image.size!=(row['w'],row['target']):raise RuntimeError('Result size mismatch')
            after=sha(cropped)
            c.execute('UPDATE files SET before_sha=?,after_sha=?,refs_before=?,after_bytes=?,status=? WHERE path=?',
                      (digest,after,json.dumps(refs),len(cropped),'prepared',str(path)));c.commit()
            if sha(path.read_bytes())!=digest:raise RuntimeError('Concurrent source write')
            atomic(path,cropped,row['mtime'])
            if sha(path.read_bytes())!=after:raise RuntimeError('Published readback mismatch')
            update_refs(refs,path,after,row['w'],row['target'],len(cropped),row['mtime'])
        c.execute("UPDATE files SET status='done' WHERE path=?",(str(path),));c.commit()

def apply_record(c, state, row):
    """Defer a broken source, but never hide publication/DB/storage failures."""
    try:
        apply_one(c, state, row)
    except SourceBackupUnavailable as error:
        if row['before_sha'] or row['after_sha']:
            raise RuntimeError('Prepared repair lost its original backup: '+row['path']) from error
        with c:
            c.execute('INSERT OR REPLACE INTO backup_issues VALUES(?,?,?)',
                      (row['path'],error.source,str(error)))
            c.execute("UPDATE files SET status='review-source' WHERE path=?",(row['path'],))
        log('REVIEW SOURCE (variant unchanged, original needs review): '+row['path']+'; '+str(error))
        return False
    except SourceDecodeError as error:
        if row['before_sha'] or row['after_sha']:
            raise RuntimeError('Prepared repair has invalid source: '+row['path']) from error
        with c:
            c.execute('INSERT OR REPLACE INTO file_failures VALUES(?,?,?,?,?)',
                      (row['path'], str(error), error.digest, error.backup_path,
                       dt.datetime.now(ZONE).isoformat(timespec='seconds')))
            c.execute("UPDATE files SET status='review-decode' WHERE path=?", (row['path'],))
        log('REVIEW DECODE (unchanged, encrypted restore source verified): '+row['path']+'; '+str(error))
        return False
    return True

def apply_with_retry(c, state, row):
    """Two bounded retries for access denial, with fresh durable journal state.

    Never change permissions or skip the file. In particular a failed publish
    may already have a prepared journal entry; reuse that entry, not old state.
    Each attempt reacquires the operation locks inside apply_one.
    """
    for attempt in range(3):
        try:
            return apply_record(c, state, row)
        except (PermissionError,LockUnavailable) as error:
            if attempt == 2:
                raise
            delay = (5, 15)[attempt]
            reason='shared lock unavailable' if isinstance(error,LockUnavailable) else 'access denied'
            log('RETRY '+reason+' '+str(attempt+1)+'/2 after '+str(delay)+'s: '+row['path']+'; '+str(error))
            time.sleep(delay)
            fresh = c.execute('SELECT * FROM files WHERE path=?', (row['path'],)).fetchone()
            if fresh is None:raise RuntimeError('Retry journal entry disappeared') from error
            row = fresh

def apply(c,state,limit):
    if c.execute("SELECT 1 FROM files WHERE status IN ('restoring','restored') LIMIT 1").fetchone():
        raise RuntimeError('Explicit restore recorded; resolve before applying privacy crop again')
    if not c.execute("SELECT 1 FROM meta WHERE k='scan_complete'").fetchone():raise RuntimeError('Complete --audit first')
    # Do not reuse an old frozen implementation for newly generated rollback
    # commands. Keep historical snapshots, and pin this exact code by hash.
    source=Path(__file__).read_bytes()
    frozen=state/('crop-'+sha(source)+'.py')
    if not frozen.exists():atomic(frozen,source,None,0o600)
    if frozen.read_bytes()!=source:raise RuntimeError('Frozen restore script differs')
    with (state/'restore-commands.txt').open('w') as out:
        for record in c.execute("SELECT path FROM files WHERE decision IN ('crop','invalidate-video')"):
            out.write(shlex.join(['python3',str(frozen),'--state',str(state),'--restore-file',record[0]])+'\n')
    # Snapshot DBs online, once. Per-file refs_before is the surgical restore record.
    for site in SITES:
        dest=state/(site+'-before.sqlite')
        if not dest.exists():
            log('DATABASE BACKUP '+site)
            temporary=state/(site+'-before.partial.sqlite')
            if temporary.exists(): temporary.unlink()
            db=live_db(site);out=sqlite3.connect(temporary);db.backup(out,pages=1024,sleep=0.05)
            if out.execute('PRAGMA quick_check').fetchone()!=('ok',):raise RuntimeError('DB backup failed')
            out.close();db.close();os.chmod(temporary,0o600);os.replace(temporary,dest)
        # Also reject incomplete snapshots left by an interrupted older script.
        saved=sqlite3.connect('file:'+str(dest)+'?mode=ro',uri=True)
        if saved.execute("SELECT count(*) FROM sqlite_master WHERE type='table' AND name IN ('captures','variants')").fetchone()!=(2,):
            raise RuntimeError('Incomplete DB backup: '+str(dest))
        saved.close()
    count=0
    for row in c.execute("SELECT * FROM files WHERE decision IN ('crop','invalidate-video') AND status NOT IN ('done','already-safe','retired-cache','review-decode','review-source') ORDER BY (decision='invalidate-video'),path").fetchall():
        if limit and count>=limit:break
        try:apply_with_retry(c,state,row)
        except BlockingIOError as error:
            log('BUSY, kept pending for next run: '+row['path']+'; '+str(error));continue
        except Exception as error:
            raise RuntimeError(row['path']+': '+str(error)) from error
        count+=1
        if count<=10 or count%25==0:log('PROCESSED '+str(count)+' '+row['path'])
    report(c)
    log('Apply ended. Re-run --apply to resume. Review entries and future captures are not covered by this result.')

def verify(c):
    """Verify actual output, not just a successful earlier return code."""
    problems=[];count=0
    for row in c.execute("SELECT * FROM files WHERE decision IN ('crop','invalidate-video')").fetchall():
        p=Path(row['path'])
        try:
            if row['status'] == 'retired-cache':
                if p.exists() or p.is_symlink():raise RuntimeError('Retired cache reappeared')
                retired = c.execute('SELECT archive_paths FROM retired_cache WHERE path=?', (str(p),)).fetchone()
                if not retired:raise RuntimeError('Missing retirement evidence')
                for archived in json.loads(retired[0]):
                    target,digest,size,w,h=checked_archive(c,Path(archived))
                    for ref in json.loads(target['refs_before'] or '[]'):
                        db=live_db(ref['site']);old=ref['before']
                        try:
                            if ref['kind']=='capture':
                                actual=db.execute('SELECT relative_path,hash_sha256 FROM captures WHERE id=?',(old['id'],)).fetchone()
                                expected=(old['relative_path'],digest)
                            else:
                                actual=db.execute('SELECT relative_path,width,height,size_bytes FROM variants WHERE id=?',(old['id'],)).fetchone()
                                expected=(old['relative_path'],w,h,size)
                            if actual!=expected:raise RuntimeError('Retired cache DB metadata mismatch')
                        finally:db.close()
                count+=1
                continue
            if row['status'] not in ('done','already-safe'):raise RuntimeError('Not completed: '+row['status'])
            if row['decision']=='invalidate-video':
                if p.exists():raise RuntimeError('Video still exists or was regenerated; review required')
            elif 'current' in p.parts:
                w,h=dimensions(p)
                if target_height(w,h)!=h:raise RuntimeError('Current image is not cropped')
            else:
                data=p.read_bytes()
                if sha(data)!=row['after_sha']:raise RuntimeError('Output hash differs')
                with Image.open(io.BytesIO(data)) as im:
                    im.load()
                    if im.size!=(row['w'],row['target']):raise RuntimeError('Output dimensions differ')
                for ref in json.loads(row['refs_before']):
                    db=live_db(ref['site']);old=ref['before']
                    try:
                        if ref['kind']=='capture':
                            actual=db.execute('SELECT relative_path,hash_sha256 FROM captures WHERE id=?',(old['id'],)).fetchone()
                            expected=(old['relative_path'],row['after_sha'])
                        else:
                            actual=db.execute('SELECT relative_path,width,height,size_bytes FROM variants WHERE id=?',(old['id'],)).fetchone()
                            expected=(old['relative_path'],row['w'],row['target'],len(data))
                        if actual!=expected:raise RuntimeError('Database metadata mismatch: '+ref['site'])
                    finally:db.close()
            count+=1
            if count%100==0:log('VERIFIED '+str(count))
        except (OSError,RuntimeError,ValueError) as e:
            problems.append(str(p)+': '+str(e));log('VERIFY ERROR '+problems[-1])
    for row in c.execute("SELECT path,decision FROM files WHERE decision LIKE 'review:%'"):
        problems.append(row['path']+': '+row['decision']);log('REVIEW '+problems[-1])
    log('Verified files: '+str(count)+'; unresolved: '+str(len(problems)))
    if problems:raise RuntimeError('Verification has unresolved entries; see log')


def restore(c,state,path):
    row=c.execute('SELECT * FROM files WHERE path=?',(str(path),)).fetchone()
    if not row or not row['before_sha']:raise RuntimeError('No recorded backup for this file')
    link=c.execute('SELECT * FROM backup_links WHERE path=?',(str(path),)).fetchone()
    derived=link is not None and link['mode']=='derived'
    digest=link['source_sha'] if link else row['before_sha']
    encrypted=BASE/'webcam/var/backups'/state.name/'encrypted'/(digest+'.enc')
    original=crypt(encrypted.read_bytes(),state/'backup.key',True)
    if sha(original)!=digest:raise RuntimeError('Backup checksum mismatch')
    if derived:
        # Operator-approved regeneration: preserve original dimensions, but do
        # not claim byte-identical historical encoding or embedded thumbnails.
        with Image.open(io.BytesIO(original)) as image:
            image.load()
            if row['w']>image.width or row['h']>image.height:
                raise RuntimeError('Restore source smaller than variant')
            with image.resize((row['w'],row['h']),Image.Resampling.LANCZOS) as scaled:
                out=io.BytesIO();scaled.convert('RGB').save(out,format='JPEG',quality=95,subsampling=0)
                original=out.getvalue()
    restored_sha=sha(original)
    with locks(path,row['slot']), contextlib.ExitStack() as stack:
        if path.is_symlink():raise RuntimeError('Refusing to restore through a symlink')
        if path.exists() and sha(path.read_bytes()) not in (row['after_sha'],row['before_sha'],restored_sha):
            raise RuntimeError('Refusing to overwrite a newer file')
        refs=json.loads(row['refs_before'])
        databases={}
        # Reserve every affected DB before checking any reference. No original
        # is published while an unchecked reference can still reject restore.
        for site in sorted({ref['site'] for ref in refs}):
            db=live_db(site)
            stack.callback(db.close)  # rolls back uncommitted updates on error
            db.execute('BEGIN IMMEDIATE')
            databases[site]=db
        for ref in refs:
            db=databases[ref['site']];old=ref['before']
            if ref['kind']=='capture':
                if derived:raise RuntimeError('Capture must have an exact original backup')
                actual=db.execute('SELECT relative_path,hash_sha256 FROM captures WHERE id=?',(old['id'],)).fetchone()
                allowed=[(old['relative_path'],value) for value in (row['after_sha'],old['hash_sha256'])]
                if actual not in allowed:raise RuntimeError('Restore DB row changed; explicit review needed')
                db.execute('UPDATE captures SET hash_sha256=? WHERE id=?',(old['hash_sha256'],old['id']))
            else:
                values=(row['w'],row['h'],len(original),row['mtime']//1000000000) if derived else (old['width'],old['height'],old['size_bytes'],old['mtime'])
                actual=db.execute('SELECT relative_path,width,height,size_bytes,mtime FROM variants WHERE id=?',(old['id'],)).fetchone()
                cropped=(row['w'],row['target'],row['after_bytes'],row['mtime']//1000000000)
                before=(old['width'],old['height'],old['size_bytes'],old['mtime'])
                if actual not in [(old['relative_path'],*v) for v in (cropped,before,values)]:
                    raise RuntimeError('Restore DB row changed; explicit review needed')
                db.execute('UPDATE variants SET width=?,height=?,size_bytes=?,mtime=? WHERE id=?',(*values,old['id']))
        # SQLite databases and the JPEG cannot share one atomic transaction.
        # Persist intent first. After a crash, --restore-file can accept either
        # old or restored metadata and finish; --apply must never recrop it.
        c.execute("UPDATE files SET status='restoring' WHERE path=?",(str(path),));c.commit()
        for db in databases.values():db.commit()
        # Publish LAST: a DB conflict or failed commit leaves the crop intact.
        atomic(path,original,row['mtime'])
        directory=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY)
        try:os.fsync(directory)
        finally:os.close(directory)
        if sha(path.read_bytes())!=restored_sha:raise RuntimeError('Restore readback mismatch')
        c.execute("UPDATE files SET status='restored' WHERE path=?",(str(path),));c.commit()
    log(('RESTORED regenerated variant (not byte-identical): ' if derived else 'RESTORED exact backup: ')+str(path))


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--state',required=True,type=Path)
    modes=parser.add_mutually_exclusive_group()
    modes.add_argument('--audit',action='store_true');modes.add_argument('--apply',action='store_true')
    modes.add_argument('--report',action='store_true')
    modes.add_argument('--recheck-inventory-errors',action='store_true',help='Revalidate only pending inventory I/O errors, with snapshot and encrypted originals')
    modes.add_argument('--reconcile-missing-cache',action='store_true',help='Reconcile retired completed cache files against fully repaired archive and DB references')
    modes.add_argument('--verify',action='store_true',help='Read back repaired JPEGs and both databases; nonzero if pending/review/failure')
    modes.add_argument('--restore-file',type=Path,help='Restore exactly one recorded file (removes its privacy crop!)')
    parser.add_argument('--limit',type=int,default=0,help='Maximum changes in this invocation; zero means all planned files')
    args=parser.parse_args()
    if os.geteuid()!=0:raise RuntimeError('Run as root on DEV34')
    os.umask(0o077);guards()
    # Acquire the single-run lock before schema or journal-mode changes.
    if args.state.parent!=STATE_BASE or not re.fullmatch(r'stromberg-privacy-[a-zA-Z0-9_-]+',args.state.name):
        raise RuntimeError('Invalid state path')
    args.state.mkdir(mode=0o700,exist_ok=True)
    if args.state.is_symlink() or args.state.stat().st_uid!=os.getuid():
        raise RuntimeError('Unsafe state owner/path')
    with (args.state/'run.lock').open('a+') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        c=state_open(args.state)
        if args.restore_file:restore(c,args.state,args.restore_file)
        elif args.recheck_inventory_errors:recheck_inventory_errors(c,args.state)
        elif args.apply:
            with reuse_apply_databases():apply(c,args.state,args.limit)
        elif args.reconcile_missing_cache:reconcile_completed(c)
        elif args.report:report(c)
        elif args.verify:verify(c)
        else:scan(c)
    c.close()

if __name__=='__main__':
    try:main()
    except (Exception,KeyboardInterrupt) as error:
        # No local variables/credentials: retain the failing operation and stack.
        traceback.print_exc()
        log('STOP: '+str(error));sys.exit(1)
