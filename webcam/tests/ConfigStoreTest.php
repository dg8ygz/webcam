<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/TempSqlite.php';

final class ConfigStoreTest extends TestCase
{
    private function sampleDocument(): array
    {
        return [
          'version' => 1,
	          'defaults' => [],
	          'archive_cache' => ['enabled' => false, 'last_built_at' => null],
	          'runtime' => ['weather_command_timeout_seconds' => 33],
            'maintenance_scripts' => [
              'backup_daily' => ['enabled' => false, 'time' => '04:15'],
              'weather_quality' => ['enabled' => false, 'time' => '04:35'],
            ],
	          'cameras' => [
            'testcam' => [
              'id' => 'testcam',
              'slug' => 'testcam',
              'name' => 'Test Cam',
              'title' => 'Test Cam',
              'short_title' => 'Test',
              'description' => 'desc',
              'mode' => 'pull-snapshot',
              'location' => [
                'latitude' => 1.1,
                'longitude' => 2.2,
                'elevation' => 3.3,
                'orientation_deg' => 45,
                'field_of_view_deg' => 60,
                'map_center_offset' => 0.2,
                'map_center_offset_x' => -0.1,
                'orientation_visualization_km' => 5.5,
              ],
              'tags' => [],
              'capture' => [
                'interval_seconds' => 600,
                'transport' => 'http-get',
                'endpoint' => 'http://192.0.2.10/current.jpg',
                'timeout_seconds' => 10,
                'expected_response' => 200,
                'extra_times' => [],
                'upload_key' => 'initial-test-key',
                'active_upload_fallback_failures' => 4,
              ],
              'storage' => [],
              'stream' => [
                'mode' => 'rtsp',
                'fps' => 3,
                'quality' => 5,
                'max_clients' => 4,
              ],
              'status' => [],
              'resolutions' => [],
              'weather_commands' => [],
            ],
          ],
        ];
    }

    public function testRoundtripStoresLocationFields(): void
    {
        $schema = (string) file_get_contents(__DIR__ . '/../database/schema.config.sql');
        $tmp = new TempSqlite($schema);
        $tmp->pdo = null;

        // inject temp db path into globals used by camera_store.php
        $GLOBALS['webcam_camera_config_options'] = [
            'db_path' => $tmp->path,
            'driver' => 'sqlite',
            'json_path' => null,
        ];

        require_once __DIR__ . '/../config/camera_store.php';

        $doc = $this->sampleDocument();
        $doc['cameras']['testcam']['capture']['helper_upload_mode'] = 'chunked';
        $doc['cameras']['testcam']['capture']['auto_iso'] = ['mode' => 'observe', 'min_iso' => 100, 'max_iso' => 800, 'night_ev_max' => 2];

        $doc['cameras']['testcam']['capture']['image_processing'] = ['enabled' => true, 'gamma' => 1.2, 'regions' => [
          ['type' => 'mask', 'x' => 1, 'y' => 2, 'width' => 10, 'height' => 20],
          ['type' => 'copy', 'x' => 1, 'y' => 2, 'width' => 10, 'height' => 20, 'dest_x' => 20, 'dest_y' => 30],
        ]];
        $saved = webcam_camera_store_save($doc, true);
        $loaded = webcam_camera_store_load();
        self::assertSame($doc['cameras']['testcam']['capture']['image_processing'], $loaded['cameras']['testcam']['capture']['image_processing']);
        $loc = $loaded['cameras']['testcam']['location'];
        $this->assertSame('chunked', $loaded['cameras']['testcam']['capture']['helper_upload_mode']);
        $this->assertSame($doc['cameras']['testcam']['capture']['auto_iso'], $loaded['cameras']['testcam']['capture']['auto_iso']);

        $this->assertSame(5.5, $loc['orientation_visualization_km']);
	        $this->assertSame(0.2, $loc['map_center_offset']);
	        $this->assertSame(-0.1, $loc['map_center_offset_x']);
	        $this->assertSame(4, $loaded['cameras']['testcam']['stream']['max_clients']);
	        $this->assertSame(33, $loaded['runtime']['weather_command_timeout_seconds']);
          $this->assertSame(
            'http://192.0.2.10/current.jpg',
            $loaded['cameras']['testcam']['capture']['endpoint']
          );
          $this->assertSame(
            'initial-test-key',
            $loaded['cameras']['testcam']['capture']['upload_key']
          );
          $this->assertSame(
            4,
            $loaded['cameras']['testcam']['capture']['active_upload_fallback_failures']
          );
          $this->assertFalse($loaded['maintenance_scripts']['backup_daily']['enabled']);
          $this->assertSame('04:15', $loaded['maintenance_scripts']['backup_daily']['time']);
          $this->assertSame(
            'bin/backup_daily.php',
            $loaded['maintenance_scripts']['backup_daily']['path']
          );
          $this->assertFalse($loaded['maintenance_scripts']['weather_quality']['enabled']);
          $this->assertSame('04:35', $loaded['maintenance_scripts']['weather_quality']['time']);
          $this->assertSame(
            'bin/weather_quality_previous_day.sh',
            $loaded['maintenance_scripts']['weather_quality']['path']
          );

          $loaded['cameras']['testcam']['capture']['endpoint'] = 'http://192.0.2.20/current.jpg';
          $loaded['cameras']['testcam']['capture']['upload_key'] = 'updated-test-key';
          $loaded['cameras']['testcam']['capture']['helper_upload_mode'] = 'local';
          webcam_camera_store_save($loaded, true);
          $updated = webcam_camera_store_load();
          $this->assertSame('local', $updated['cameras']['testcam']['capture']['helper_upload_mode']);
          $this->assertSame(
            'http://192.0.2.20/current.jpg',
            $updated['cameras']['testcam']['capture']['endpoint']
          );
          $this->assertSame(
            'updated-test-key',
            $updated['cameras']['testcam']['capture']['upload_key']
          );
	    }

    public function testConcurrentDocumentsCannotOverwriteEachOther(): void
    {
        $tmp = new TempSqlite((string) file_get_contents(__DIR__ . '/../database/schema.config.sql'));
        $previousOptions = $GLOBALS['webcam_camera_config_options'] ?? null;
        $GLOBALS['webcam_camera_config_options'] = ['db_path' => $tmp->path];
        require_once __DIR__ . '/../config/camera_store.php';
        webcam_camera_store_reset_connections();
        try {
            webcam_camera_store_save($this->sampleDocument(), false);
            $a = webcam_camera_store_load();
            $b = webcam_camera_store_load();
            $a['overview_hero'] = 'Concurrent edit A';
            $saved = webcam_camera_store_save($a);
            $b['runtime']['weather_command_timeout_seconds'] = 44;
            try {
                webcam_camera_store_save($b);
                self::fail('Stale config overwrote a newer edit');
            } catch (\Webcam\Infrastructure\Config\ConfigConflictException $exception) {
                self::assertStringContainsString('reload', $exception->getMessage());
            }
            $current = webcam_camera_store_load();
            self::assertSame('Concurrent edit A', $current['overview_hero']);
            self::assertSame(33, $current['runtime']['weather_command_timeout_seconds']);
            self::assertSame($saved['version'], $current['version']);
            $current['runtime']['weather_command_timeout_seconds'] = 44;
            $updated = webcam_camera_store_save($current, false);
            self::assertSame($saved['version'] + 1, $updated['version']);
            unset($updated['version']);
            $this->expectException(\Webcam\Infrastructure\Config\ConfigConflictException::class);
            webcam_camera_store_save($updated);
        } finally {
            webcam_camera_store_reset_connections();
            $GLOBALS['webcam_camera_config_options'] = $previousOptions;
        }
    }

    public function testLoadCanReopenReadOnlyConfigDb(): void
    {
        $schema = (string) file_get_contents(__DIR__ . '/../database/schema.config.sql');
        $tmp = new TempSqlite($schema);
        $tmp->pdo = null;

        $GLOBALS['webcam_camera_config_options'] = [
            'db_path' => $tmp->path,
            'driver' => 'sqlite',
            'json_path' => null,
        ];

        require_once __DIR__ . '/../config/camera_store.php';

        webcam_camera_store_save($this->sampleDocument(), true);
        webcam_camera_store_reset_connections();

        chmod($tmp->path, 0444);

        $loaded = webcam_camera_store_load();

        $this->assertSame('testcam', $loaded['cameras']['testcam']['slug']);
    }
}
