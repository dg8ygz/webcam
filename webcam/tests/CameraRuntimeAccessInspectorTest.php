<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Admin\CameraRuntimeAccessInspector;

/**
 * Prüft den nicht privilegierten Betriebsstatus für Kamera-Current-Pfade.
 */
final class CameraRuntimeAccessInspectorTest extends TestCase
{
  public function testReportsWritableExistingCurrentDirectory(): void
  {
    $inspector = new CameraRuntimeAccessInspector(
      '/srv/webcam',
      static fn(string $path): bool => $path === '/srv/webcam/cache/testcam/current',
      static fn(string $path): bool => $path === '/srv/webcam/cache/testcam/current',
      '/usr/local/sbin/webcam-sync-apache-runtime-paths --apply --project-root /srv/webcam'
    );

    $status = $inspector->inspect('testcam');

    self::assertSame('/srv/webcam/cache/testcam/current', $status['current_path']);
    self::assertTrue($status['exists']);
    self::assertTrue($status['writable']);
    self::assertFalse($status['provision_required']);
    self::assertStringContainsString('--project-root /srv/webcam', $status['provision_command']);
  }

  public function testMissingOrInvalidCameraRequiresRootProvisioning(): void
  {
    $inspector = new CameraRuntimeAccessInspector(
      '/srv/webcam',
      static fn(string $path): bool => false,
      static fn(string $path): bool => false
    );

    $missing = $inspector->inspect('new-camera');
    self::assertFalse($missing['exists']);
    self::assertTrue($missing['provision_required']);
    self::assertSame(
      '/usr/local/sbin/webcam-sync-apache-runtime-paths --apply',
      $missing['provision_command']
    );

    $invalid = $inspector->inspect('../escape');
    self::assertSame('', $invalid['current_path']);
    self::assertTrue($invalid['provision_required']);
  }

  public function testBuildsSortedStatusMapWithoutEmptyEntries(): void
  {
    $inspector = new CameraRuntimeAccessInspector(
      '/srv/webcam',
      static fn(string $path): bool => true,
      static fn(string $path): bool => true
    );

    $statuses = $inspector->inspectAll([
      'z-camera' => ['slug' => 'z-camera'],
      'empty' => ['slug' => ''],
      'a-camera' => ['slug' => 'a-camera'],
    ]);

    self::assertSame(['a-camera', 'z-camera'], array_keys($statuses));
    self::assertArrayNotHasKey('empty', $statuses);
  }
}
