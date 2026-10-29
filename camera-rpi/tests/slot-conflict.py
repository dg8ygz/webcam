#!/usr/bin/env python3
"""Strict response binding: unrelated HTTP errors must never retire a photo."""
import hashlib,json,subprocess,tempfile,unittest
from pathlib import Path
WORKER=Path(__file__).resolve().parents[1]/'scripts/camera-rpi-worker'
SOURCE=WORKER.read_text()
FUNCTION=SOURCE[SOURCE.index('upload_slot_conflict_matches() {'):SOURCE.index('\nupload_response_is_ok() {')]
class ConflictTests(unittest.TestCase):
 def test_exact_response_and_rejected_variants(self):
  with tempfile.TemporaryDirectory() as name:
   root=Path(name);image=root/'image';response=root/'response'
   image.write_bytes(b'original jpeg bytes')
   good={'ok':False,'error':'immutable_slot_conflict','timestamp':1792678239,'sha256':hashlib.sha256(image.read_bytes()).hexdigest()}
   cases=[(json.dumps(good),True)]
   for key,value in [('ok',True),('ok',0),('error','chunks incomplete'),('timestamp',1792678200),('timestamp','1792678239'),('sha256','0'*64)]:
    cases.append((json.dumps(dict(good,**{key:value})),False))
   cases.extend([('{"ok":true,"ok":false,'+json.dumps(good)[1:],False),('not json',False),('[]',False),('x'*65537,False)])
   for body,expected in cases:
    with self.subTest(body=body[:80]):
     response.write_text(body)
     result=subprocess.run(['bash','-c',FUNCTION+'\nUPLOAD_RESPONSE_FILE="$1"; upload_slot_conflict_matches "$2" 1792678239','test',str(response),str(image)],capture_output=True)
     self.assertEqual(result.returncode==0,expected,result.stderr.decode())
if __name__=='__main__':unittest.main()
