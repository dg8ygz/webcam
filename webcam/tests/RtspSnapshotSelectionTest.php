<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Process\BoundedProcessRunner;
use Webcam\Service\Capture\RtspSnapshotSelection;

/** Realer FFmpeg-Filter an kleinen lokalen Videos; keine Kamera/Netzwerkaktion. */
final class RtspSnapshotSelectionTest extends TestCase
{
  private string $root;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/rtsp-settle-test-' . bin2hex(random_bytes(6));
    mkdir($this->root, 0700);
  }

  protected function tearDown(): void
  {
    foreach (glob($this->root . '/*') as $path) { unlink($path); }
    rmdir($this->root);
  }

  private function video(int $gop, ?string $forced = null): string
  {
    $path = $this->root . '/source.mkv';
    $args = ['/usr/bin/ffmpeg', '-v', 'error', '-f', 'lavfi', '-i',
      'testsrc2=size=64x48:rate=10:duration=6', '-c:v', 'libx264',
      '-threads', '1', '-preset', 'ultrafast', '-g', (string) $gop,
      '-keyint_min', (string) $gop, '-sc_threshold', '0'];
    if ($forced !== null) { array_push($args, '-force_key_frames', $forced); }
    array_push($args, '-y', $path);
    $this->runFfmpeg($args);
    return $path;
  }

  private function runFfmpeg(array $args): array
  {
    $result = (new BoundedProcessRunner())->run($args, 15);
    self::assertSame(0, $result['exit_code'], $result['stderr']);
    self::assertFalse($result['timed_out']);
    return $result;
  }

  private function selectedTime(string $video, int $timeout = 10, string $prefix = ''): array
  {
    $r = $this->runFfmpeg(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'info',
      '-threads', '1', '-i', $video, '-vf', $prefix . RtspSnapshotSelection::filter($timeout) . ',showinfo',
      '-frames:v', '1', '-fps_mode', 'passthrough', '-f', 'image2pipe', '-c:v', 'mjpeg', 'pipe:1']);
    self::assertNotFalse(@imagecreatefromstring($r['stdout']), 'Selected frame must decode as JPEG');
    self::assertMatchesRegularExpression('/n:\s*0.*pts_time:[0-9.]+/', $r['stderr']);
    preg_match('/n:\s*0.*pts_time:([0-9.]+).*type:([IPB])/', $r['stderr'], $match);
    self::assertCount(3, $match, $r['stderr']);
    return [(float) $match[1], $match[2]];
  }

  public function testWaitsForSettledPredictedFrame(): void
  {
    self::assertSame([2.0, 'P'], $this->selectedTime($this->video(32)));
  }

  public function testNewKeyframeRestartsSettlingPeriod(): void
  {
    self::assertSame([3.5, 'P'], $this->selectedTime($this->video(100, '1.5')));
  }

  public function testShortGopAndAllIStillProduceAnImageWithinLimit(): void
  {
    foreach ([10, 1] as $gop) {
      self::assertSame(4.0, $this->selectedTime($this->video($gop))[0]);
    }
  }

  public function testTimestampOffsetDoesNotSkipSettling(): void
  {
    self::assertSame([102.0, 'P'], $this->selectedTime($this->video(32), 10, 'setpts=PTS+100/TB,'));
  }

  public function testSmallTimeoutLeavesConnectionAndEncodingBudget(): void
  {
    self::assertSame([0.5, 'P'], $this->selectedTime($this->video(32), 2));
  }
  public function testMissingTimestampsDoNotLeaveSelectionWaitingForever(): void
  {
    $r = $this->runFfmpeg(['/usr/bin/ffmpeg', '-v', 'error', '-i', $this->video(32),
      '-vf', 'setpts=NAN,' . RtspSnapshotSelection::filter(10),
      '-frames:v', '1', '-fps_mode', 'passthrough', '-f', 'image2pipe', '-c:v', 'mjpeg', 'pipe:1']);
    self::assertNotFalse(@imagecreatefromstring($r['stdout']));
  }

  public function testCaptureManagerUsesSettledFrameAndKeepsUrlOffCommandLine(): void
  {
    $video = $this->video(32);
    $wrapper = $this->root . '/ffmpeg-fixture';
    file_put_contents($wrapper, <<<'PYTHON'
#!/usr/bin/python3
import os, sys
args = sys.argv[1:]
if '-h' in args:
    os.execv('/usr/bin/ffmpeg', ['ffmpeg'] + args)
manifest = sys.stdin.read()
if 'fixture-secret' not in manifest or any('fixture-secret' in a for a in args):
    sys.exit(9)
# Keep the application's output options, but replace only its network input.
rest = args[args.index('-i') + 2:]
os.execv('/usr/bin/ffmpeg', ['ffmpeg', '-v', 'error', '-i', os.path.join(os.path.dirname(__file__), 'source.mkv')] + rest)
PYTHON
    );
    chmod($wrapper, 0700);
    $before = getenv('WEBCAM_FFMPEG_PATH');
    putenv('WEBCAM_FFMPEG_PATH=' . $wrapper);
    try {
      file_put_contents($this->root . '/cameras.php', '<?php return [];');
      $app = new \Webcam\App(['camera_config_path' => $this->root . '/cameras.php',
        'log_path' => $this->root, 'state_path' => $this->root]);
      $manager = new \Webcam\Service\Capture\CaptureManager($app);
      $method = new ReflectionMethod($manager, 'rtspSnapshot');
      $jpeg = $method->invoke($manager, 'rtsp://user:fixture-secret@example.test/source', ['timeout_seconds' => 10]);
      $expected = $this->runFfmpeg(['/usr/bin/ffmpeg', '-v', 'error', '-i', $video,
        '-map', '0:v:0', '-an', '-vf', "select='eq(n,20)'", '-fps_mode', 'passthrough',
        '-frames:v', '1', '-f', 'image2pipe', '-vcodec', 'mjpeg', 'pipe:1']);
      self::assertSame(hash('sha256', $expected['stdout']), hash('sha256', $jpeg));
      self::assertNotFalse(@imagecreatefromstring($jpeg));
    } finally {
      putenv($before === false ? 'WEBCAM_FFMPEG_PATH' : 'WEBCAM_FFMPEG_PATH=' . $before);
    }
  }

}
