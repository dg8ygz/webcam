<?php
declare(strict_types=1);
require __DIR__ . '/../scripts/lib/camera-source.php';

// Exercise the real capture helper and real codecs with small local videos.
// Only the private RTSP input is replaced; no camera, GPIO or network used.
$root = sys_get_temp_dir() . '/pi-rtsp-settle-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$count = 0;
function verify(bool $value, string $message): void {
    global $count;
    if (!$value) { throw new RuntimeException($message); }
    $count++;
}
function command(array $args): string {
    $p = proc_open(array_merge(['/usr/bin/timeout', '-k', '2s', '20s'], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    verify(proc_close($p) === 0, 'Local codec command failed');
    return $out;
}
$oldBin = getenv('CAMERA_RPI_FFMPEG_BIN');
try {
    $wrapper = $root . '/ffmpeg';
    file_put_contents($wrapper, <<<'PY'
#!/usr/bin/python3
import os, sys
args = sys.argv[1:]
m = sys.stdin.read()
if 'fixture-secret' not in m or any('fixture-secret' in a for a in args):
    sys.exit(9)
p = os.path.join(os.path.dirname(__file__), 'source.mkv')
os.execv('/usr/bin/ffmpeg', ['ffmpeg', '-hide_banner', '-v', 'error', '-threads', '2', '-i', p] + args[args.index('-i') + 2:])
PY
    );
    chmod($wrapper, 0700);
    putenv('CAMERA_RPI_FFMPEG_BIN=' . $wrapper);
    $c = CameraSource::validate(['source' => 'rtsp',
        'rtsp_url' => 'rtsp://user:fixture-secret@example.test/source',
        'video_device' => '/dev/video0', 'video_width' => '64', 'video_height' => '48',
        'video_warmup_frames' => '0', 'video_input_fps' => '10']);
    foreach ([['libx264',32,null,20], ['libx264',100,'1.5',35],
              ['libx264',10,null,40], ['libx264',1,null,40],
              ['libx265',32,null,20]] as [$codec,$gop,$forced,$frame]) {
        $args = ['/usr/bin/ffmpeg','-v','error','-f','lavfi','-i','testsrc2=size=64x48:rate=10:duration=6',
            '-c:v',$codec,'-threads','1','-preset','ultrafast','-g',(string)$gop,'-sc_threshold','0'];
        if ($codec === 'libx265') {
            array_push($args,'-x265-params', 'pools=none:frame-threads=1:bframes=0:scenecut=0');
        }
        if ($forced !== null) { array_push($args,'-force_key_frames',$forced); }
        array_push($args,'-y',$root.'/source.mkv'); command($args);
        CameraSource::capture($c,$root.'/actual.jpg');
        command(['/usr/bin/ffmpeg','-v','error','-threads','2','-i',$root.'/source.mkv',
            '-map','0:v:0','-an','-vf',"select='eq(n,{$frame})'",'-fps_mode','passthrough',
            '-frames:v','1','-threads','2','-q:v','2','-f','image2','-update','1','-y',$root.'/expected.jpg']);
        verify(hash_file('sha256',$root.'/expected.jpg') === hash_file('sha256',$root.'/actual.jpg'),
            "Wrong selected frame: {$codec} GOP{$gop}");
        $im = @imagecreatefromjpeg($root.'/actual.jpg');
        verify($im !== false && imagesx($im) === 64 && imagesy($im) === 48, 'Invalid JPEG');
        imagedestroy($im);
        unlink($root.'/actual.jpg'); // Jeder Abruf hat wie im Worker ein frisches Ziel.
    }
    // Ein abgebrochener Stream kann vor Ende der Beruhigungsphase enden.
    // Echtes FFmpeg liefert dann Exit 0, aber select hat kein Bild ausgegeben.
    command(['/usr/bin/ffmpeg','-v','error','-f','lavfi','-i',
        'testsrc2=size=64x48:rate=10:duration=0.5','-c:v','libx264',
        '-pix_fmt','yuvj420p','-color_range','pc',
        '-threads','1','-preset','ultrafast','-y',$root.'/source.mkv']);
    $reason='';
    try { CameraSource::capture($c,$root.'/short.jpg'); }
    catch (RuntimeException $e) { $reason=$e->getMessage(); }
    verify(str_contains($reason,'exit=0, aber kein JPEG'),'Short stream not diagnosed: '.$reason);
    verify(!file_exists($root.'/short.jpg'),'Short stream left an invalid image');
    echo "OK: {$count} RTSP settling checks; H264/HEVC, keyframe reset, short GOP/All-I, premature EOF and JPEG\n";
} finally {
    putenv($oldBin === false ? 'CAMERA_RPI_FFMPEG_BIN' : 'CAMERA_RPI_FFMPEG_BIN=' . $oldBin);
    foreach (glob($root.'/*') as $f) { unlink($f); }
    rmdir($root);
}
