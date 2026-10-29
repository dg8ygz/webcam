<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webcam\Service\Admin\CameraConfigNormalizer;

require_once __DIR__ . '/../src/Service/Admin/CameraConfigNormalizer.php';

/**
 * Vertragstests für die ausgelagerte Admin-Kameranormalisierung.
 *
 * Die HTTP-API wird separat geprüft. Hier geht es ausschließlich darum, welche
 * Browserwerte übernommen, ergänzt oder als ungültig zurückgewiesen werden.
 */
final class CameraConfigNormalizerTest extends TestCase
{
  public function testSectionSavePreservesLegacyAndExplicitCaptureSettings(): void
  {
    foreach ([[], ['capture' => ['interval_seconds' => 600]],
      ['capture' => ['auto_iso' => ['mode' => 'auto', 'min_iso' => 200, 'max_iso' => 800, 'night_ev_max' => 2]]]] as $camera) {
      $saved = $this->normalizer->normalizeSectionUpdate($camera,
        ['name' => 'Updated', 'capture' => ['auto_iso' => ['mode' => 'off']]], 'general', 'test', []);
      self::assertSame('Updated', $saved['name']);
      self::assertSame(array_key_exists('capture', $camera), array_key_exists('capture', $saved));
      self::assertSame($camera['capture'] ?? null, $saved['capture'] ?? null);
    }
    $saved = $this->normalizer->normalizeSectionUpdate([], ['capture' => ['interval_seconds' => 300]], 'capture', 'test', []);
    self::assertSame(['mode' => 'off', 'min_iso' => 100, 'max_iso' => 800], $saved['capture']['auto_iso']);
    self::assertSame(300, $saved['capture']['interval_seconds']);
  }

  public function testGeneralSaveStillValidatesExistingCaptureSettings(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->normalizer->normalizeSectionUpdate(
      ['capture' => ['auto_iso' => ['max_iso' => '800']]], ['name' => 'Updated'], 'general', 'test', []
    );
  }

  private CameraConfigNormalizer $normalizer;

  protected function setUp(): void
  {
    $this->normalizer = new CameraConfigNormalizer();
  }

  public function testHelperUploadModeAcceptsOnlyThreeChoices(): void
  {
    foreach (['local', 'legacy', 'chunked'] as $mode) {
      $result = $this->normalizer->normalizePayload(['capture' => ['helper_upload_mode' => $mode]], 'test', []);
      self::assertSame($mode, $result['capture']['helper_upload_mode']);
    }
    foreach (['shell', '', null, 1, ['chunked']] as $mode) {
      try {
        $this->normalizer->normalizePayload(['capture' => ['helper_upload_mode' => $mode]], 'test', []);
        self::fail('Invalid helper mode accepted');
      } catch (InvalidArgumentException $error) {
        self::assertStringContainsString('helper_upload_mode', $error->getMessage());
      }
    }
  }

  public function testNormalizesCompletePayloadIncludingOverlayAndLabels(): void
  {
    $result = $this->normalizer->normalizePayload([
      'name' => 'Bielefeld',
      'title' => 'Stadtblick',
      'tags' => ['stadt', 123, 'aussicht'],
      'resolutions' => ['1600', 'ungueltig', 800],
      'capture' => [
        'transport' => ' rtsp ',
        'interval_seconds' => '600',
        'endpoint' => 'rtsp://camera.invalid/live',
        'upload_key' => ' secret ',
        'active_upload_fallback_failures' => '4',
        'extra_times' => ['06:10', null, '18:20'],
      ],
      'storage' => [
        'base_path' => 'storage/bielefeld',
        'current_dir' => 'current',
        'archive_dir' => 'archive',
        'best_of_dir' => null,
      ],
      'location' => [
        'latitude' => 52.03,
        'longitude' => 8.53,
        'map_height_px' => '520',
      ],
      'overlay_logo' => [
        'url' => 'logo.png',
        'width' => '200',
        'height' => 0,
        'offset_top' => '12',
        'opacity' => '0.75',
      ],
      'labels' => [
        [],
        [
          'id' => '7',
          'x' => '10.5',
          'y' => 20,
          'text' => 'Rathaus',
          'href' => 'https://example.invalid/info',
          'type' => 2,
          'resolution' => -1,
          'valid_from' => '2026-01-01',
          'valid_to' => '2026-12-31',
          'color' => '#Aa00ff',
          'text_tone' => 'dark',
        ],
      ],
    ], 'bielefeld', ['resolutions' => [640]]);

    self::assertSame('bielefeld', $result['slug']);
    self::assertSame('bielefeld', $result['id']);
    self::assertSame('Bielefeld', $result['short_title']);
    self::assertSame('', $result['description']);
    self::assertSame(['stadt', 'aussicht'], $result['tags']);
    self::assertSame(['1600', 800], $result['resolutions']);
    self::assertSame('rtsp', $result['capture']['transport']);
    self::assertSame(600, $result['capture']['interval_seconds']);
    self::assertSame('secret', $result['capture']['upload_key']);
    self::assertSame(4, $result['capture']['active_upload_fallback_failures']);
    self::assertSame(['06:10', '18:20'], $result['capture']['extra_times']);
    self::assertSame('BestOf', $result['storage']['best_of_dir']);
    self::assertSame(0, $result['location']['map_center_offset']);
    self::assertSame(0, $result['location']['map_center_offset_x']);
    self::assertSame(520.0, $result['location']['map_height_px']);
    self::assertSame([
      'url' => 'overlay.php?slug=bielefeld&file=logo.png',
      'width' => 200,
      'height' => null,
      'offset_top' => 12,
      'offset_right' => 0,
      'opacity' => 0.75,
    ], $result['overlay_logo']);
    self::assertNull($result['overlay_logo_dark']);
    self::assertSame([[
      'x' => 10.5,
      'y' => 20.0,
      'text' => 'Rathaus',
      'href' => 'https://example.invalid/info',
      'type' => 2,
      'resolution' => 0,
      'valid_from' => '2026-01-01',
      'valid_to' => '2026-12-31',
      'color' => '#aa00ff',
      'text_tone' => 'dark',
      'id' => 7,
    ]], $result['labels']);
  }

  public function testSectionUpdatesUseStrictPositiveFieldLists(): void
  {
    $camera = [
      'slug' => 'bielefeld',
      'name' => 'Alt',
      'title' => 'Alt',
      'description' => 'Alt',
      'tags' => ['alt'],
      'mode' => 'active-capture',
      'location' => ['latitude' => 1, 'private' => 'keep'],
      'capture' => ['endpoint' => 'old', 'active_upload_fallback_failures' => 0, 'private' => 'keep'],
      'stream' => ['mode' => 'none', 'private' => 'keep'],
      'storage' => ['base_path' => 'old', 'private' => 'keep'],
      'status' => ['watchdog' => ['enabled' => false], 'private' => 'keep'],
      'overlay_logo' => null,
      'labels' => [],
      'weather_commands' => [],
      'untouched' => 'keep',
    ];

    $general = $this->normalizer->applySectionUpdate($camera, [
      'name' => ' Neu ',
      'tags' => ['stadt', 42],
      'untouched' => 'replace',
    ], 'general');
    self::assertSame('Neu', $general['name']);
    self::assertSame(['stadt'], $general['tags']);
    self::assertSame('keep', $general['untouched']);

    $info = $this->normalizer->applySectionUpdate(
      $camera,
      ['description' => ' Neu ', 'info_html' => '<p>Info</p>'],
      'info'
    );
    self::assertSame(' Neu ', $info['description']);
    self::assertSame('<p>Info</p>', $info['info_html']);

    $live = $this->normalizer->applySectionUpdate($camera, ['tags' => ['live']], 'liveview');
    self::assertSame(['live'], $live['tags']);

    $map = $this->normalizer->applySectionUpdate($camera, [
      'location' => ['longitude' => 8.53, 'private' => 'replace'],
    ], 'map');
    self::assertSame(1, $map['location']['latitude']);
    self::assertSame(8.53, $map['location']['longitude']);
    self::assertSame('keep', $map['location']['private']);

    $capture = $this->normalizer->applySectionUpdate($camera, [
      'mode' => ' passive-upload ',
      'capture' => ['endpoint' => 'new', 'active_upload_fallback_failures' => 3, 'extra_times' => ['06:10', false], 'private' => 'replace'],
      'stream' => ['mode' => 'rtsp', 'fps' => 3, 'private' => 'replace'],
    ], 'capture');
    self::assertSame('passive-upload', $capture['mode']);
    self::assertSame('new', $capture['capture']['endpoint']);
    self::assertSame(3, $capture['capture']['active_upload_fallback_failures']);
    self::assertSame(['06:10'], $capture['capture']['extra_times']);
    self::assertSame('keep', $capture['capture']['private']);
    self::assertSame('rtsp', $capture['stream']['mode']);
    self::assertSame('keep', $capture['stream']['private']);

    $storage = $this->normalizer->applySectionUpdate(
      $camera,
      ['storage' => ['base_path' => ' cache/new ', 'private' => 'replace']],
      'storage'
    );
    self::assertSame('cache/new', $storage['storage']['base_path']);
    self::assertSame('keep', $storage['storage']['private']);

    $overlay = $this->normalizer->applySectionUpdate(
      $camera,
      ['overlay_logo' => ['url' => 'logo.png']],
      'overlay'
    );
    self::assertSame(['url' => 'logo.png'], $overlay['overlay_logo']);

    $labels = $this->normalizer->applySectionUpdate(
      $camera,
      ['labels' => [['text' => 'Punkt']]],
      'labels'
    );
    self::assertSame([['text' => 'Punkt']], $labels['labels']);

    $weather = $this->normalizer->applySectionUpdate(
      $camera,
      ['weather_commands' => ['echo ok', null]],
      'weather'
    );
    self::assertSame(['echo ok'], $weather['weather_commands']);

    $watchdog = $this->normalizer->applySectionUpdate(
      $camera,
      ['status' => ['watchdog' => ['enabled' => true]]],
      'watchdog'
    );
    self::assertTrue($watchdog['status']['watchdog']['enabled']);
    self::assertSame('keep', $watchdog['status']['private']);
  }

  /** @param array<string,mixed> $payload */
  #[DataProvider('invalidCompletePayloadProvider')]
  public function testRejectsInvalidCompletePayload(array $payload, string $message): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage($message);
    $this->normalizer->normalizePayload($payload, 'bielefeld', []);
  }

  /** @return iterable<string,array{array<string,mixed>,string}> */
  public static function invalidCompletePayloadProvider(): iterable
  {
    yield 'transport type' => [
      ['capture' => ['transport' => []]],
      'capture.transport must be a string',
    ];
    yield 'transport value' => [
      ['capture' => ['transport' => 'ftp']],
      'capture.transport must be http-get or rtsp',
    ];
    yield 'fallback failures type' => [
      ['capture' => ['active_upload_fallback_failures' => 'never']],
      'capture.active_upload_fallback_failures must be an integer',
    ];
    yield 'fallback failures fraction' => [
      ['capture' => ['active_upload_fallback_failures' => 2.5]],
      'capture.active_upload_fallback_failures must be an integer',
    ];
    yield 'fallback failures range' => [
      ['capture' => ['active_upload_fallback_failures' => 101]],
      'capture.active_upload_fallback_failures must be between 0 and 100',
    ];
    yield 'absolute storage path' => [
      ['storage' => ['base_path' => '/srv/webcam']],
      'storage.base_path must be relative',
    ];
    yield 'storage traversal' => [
      ['storage' => ['current_dir' => '../current']],
      "storage.current_dir must not contain '..'",
    ];
    yield 'map height' => [
      ['location' => ['map_height_px' => 99]],
      'location.map_height_px must be between 100 and 1200',
    ];
    yield 'file endpoint' => [
      ['capture' => ['endpoint' => 'file:///etc/passwd']],
      'Capture endpoint must not be file://',
    ];
    yield 'endpoint traversal' => [
      ['capture' => ['endpoint' => 'http://camera/../secret']],
      'Capture endpoint contains invalid path traversal',
    ];
    yield 'overlay traversal' => [
      ['overlay_logo' => ['url' => '../logo.png']],
      'overlay url must not contain path traversal',
    ];
    yield 'labels type' => [
      ['labels' => 'invalid'],
      'labels must be an array',
    ];
    yield 'label coordinates' => [
      ['labels' => [['text' => 'Punkt']]],
      'Label 1 benötigt x, y und text',
    ];
    yield 'label protocol' => [
      ['labels' => [['text' => 'Punkt', 'x' => 1, 'y' => 2, 'href' => 'javascript:alert(1)']]],
      'Label 1 enthält ein nicht unterstütztes Link-Protokoll',
    ];
    yield 'label color' => [
      ['labels' => [['text' => 'Punkt', 'x' => 1, 'y' => 2, 'color' => 'transparent']]],
      'Label 1 Farbe ist nicht erlaubt',
    ];
  }

  /** @param array<string,mixed> $payload */
  #[DataProvider('invalidSectionProvider')]
  public function testRejectsInvalidSectionPayload(
    string $section,
    array $payload,
    string $message
  ): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage($message);
    $this->normalizer->applySectionUpdate([], $payload, $section);
  }

  /** @return iterable<string,array{string,array<string,mixed>,string}> */
  public static function invalidSectionProvider(): iterable
  {
    yield 'unknown section' => ['unknown', [], 'Unknown section'];
    yield 'map object' => ['map', [], 'location must be an object'];
    yield 'capture extra times' => [
      'capture',
      ['capture' => ['extra_times' => '06:10']],
      'capture.extra_times must be an array',
    ];
    yield 'storage value' => [
      'storage',
      ['storage' => ['base_path' => 123]],
      'storage.base_path must be a string',
    ];
    yield 'overlay object' => ['overlay', ['overlay_logo' => 'logo.png'], 'overlay_logo must be an object or null'];
    yield 'missing labels' => ['labels', [], 'labels missing'];
    yield 'missing weather' => ['weather', [], 'weather_commands missing'];
    yield 'missing watchdog' => ['watchdog', [], 'watchdog must be an object'];
  }
}
