<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Storage\DeferredImageRuntimeConfig;

require_once __DIR__ . '/../autoload.php';

final class DeferredImageRuntimeConfigTest extends TestCase
{
  private function policy(): array
  {
    return ['version'=>1, 'project_root'=>'/apps/dev', 'config_db'=>'/db/dev/config.sqlite',
      'camera_db_root'=>'/db/dev/cameras', 'cameras'=>['bielefeld'],
      'root'=>'/apps/dev/orgin', 'backup_root'=>'/private/backup', 'guard'=>'/etc/webcam-deferred/dev-storage.json'];
  }

  public function testScopedRuntimeAndReaderRetentionAfterDisable(): void
  {
    $policy=$this->policy();
    $loaded=DeferredImageRuntimeConfig::forInstallation($policy,'/apps/dev','/db/dev/config.sqlite','/db/dev/cameras');
    self::assertSame(['bielefeld'],$loaded['deferred_image_cameras']);
    self::assertSame('/apps/dev/orgin',$loaded['deferred_image_root']);
    $policy['cameras']=[];
    $off=DeferredImageRuntimeConfig::forInstallation($policy,'/apps/dev','/db/dev/config.sqlite','/db/dev/cameras');
    self::assertSame([],$off['deferred_image_cameras']);
    self::assertSame($loaded['deferred_image_root'],$off['deferred_image_root']);
  }

  public function testProdAndTestCopiesCannotInheritPilot(): void
  {
    foreach ([['/apps/prod','/db/dev/config.sqlite','/db/dev/cameras'],
      ['/apps/dev','/db/test/config.sqlite','/db/dev/cameras'],
      ['/apps/dev','/db/dev/config.sqlite','/db/prod/cameras']] as $paths) {
      self::assertSame([],DeferredImageRuntimeConfig::forInstallation($this->policy(),...$paths));
    }
  }

  public function testInvalidCameraIsRejected(): void
  {
    $policy=$this->policy();$policy['cameras']=['stromberg'];
    $this->expectException(RuntimeException::class);
    DeferredImageRuntimeConfig::forInstallation($policy,'/apps/dev','/db/dev/config.sqlite','/db/dev/cameras');
  }

  public function testRelativePathIsRejected(): void
  {
    $policy=$this->policy();$policy['root']='public';
    $this->expectException(RuntimeException::class);
    DeferredImageRuntimeConfig::forInstallation($policy,'/apps/dev','/db/dev/config.sqlite','/db/dev/cameras');
  }
}
