<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Filesystem\PathResolver;
use Webcam\Service\Capture\CameraPrivacyState;

final class CameraPrivacyStateTest extends TestCase
{
  public function testPrivacyDeadlinesAndWatchdogExpiry(): void
  {
    $root=TestTempDir::ensure().'/privacy-'.bin2hex(random_bytes(5));
    $s=new CameraPrivacyState(new PathResolver(null,null,$root,null)); $now=1789326000;
    $encode=static fn(array $v): string=>base64_encode(json_encode($v));
    self::assertFalse($s->videoBlocked('test',$now));
    $v=['enabled'=>true,'allowed'=>true,'until'=>$now+20,'suppressed'=>false];
    $s->receive('test','video',$encode($v),$now);
    self::assertFalse($s->videoBlocked('test',$now+19));
    self::assertTrue($s->videoBlocked('test',$now+20));
    self::assertTrue($s->videoBlocked('test',$now+40));
    $s->receive('test','video','',$now+50);
    self::assertTrue($s->videoBlocked('test',$now+50),'missing header cannot clear privacy');
    $v['allowed']=false; $v['suppressed']=true;
    $s->receive('test','command',$encode($v),$now);
    self::assertTrue($s->pauseFresh('test',$now+899));
    self::assertFalse($s->pauseFresh('test',$now+900),'dead Pi cannot suppress watchdog forever');
    $v['enabled']=false; $v['allowed']=true; $v['suppressed']=false;
    $s->receive('test','video',$encode($v),$now+10);
    self::assertFalse($s->videoBlocked('test',$now+50));
    self::assertFalse($s->videoBlocked('other',$now));
    $this->expectException(InvalidArgumentException::class);
    $v['allowed']='false'; $s->receive('test','video',$encode($v),$now);
  }
}
