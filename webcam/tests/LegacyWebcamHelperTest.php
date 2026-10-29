<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LegacyWebcamHelperTest extends TestCase
{
  private string $dir;
  private string $script;

  protected function setUp(): void
  {
    $this->dir = sys_get_temp_dir() . '/webcam-legacy-helper-test-' . bin2hex(random_bytes(6));
    $this->assertTrue(mkdir($this->dir, 0700, true) || is_dir($this->dir));
    $this->script = dirname(__DIR__) . '/ops/legacy-webcam-helper/webcam-legacy-helper.sh';
  }

  protected function tearDown(): void
  {
    $this->removeTree($this->dir);
  }

  public function testCheckConfigDoesNotExposeUploadKey(): void
  {
    $config = $this->writeConfig('top-secret-helper-key');
    [$exitCode, $output] = $this->runHelper('--check-config', $config);

    $this->assertSame(0, $exitCode, $output);
    $this->assertStringContainsString('Konfiguration gültig: slug=testcam source=konfiguriert', $output);
    $this->assertStringNotContainsString('top-secret-helper-key', $output);
  }

  public function testSystemdUnitUsesRuntimeDirectoryPerInstance(): void
  {
    $unit = (string) file_get_contents(
      dirname(__DIR__) . '/ops/legacy-webcam-helper/webcam-legacy-helper@.service'
    );

    $this->assertStringContainsString('RuntimeDirectory=webcam-legacy-helper/%i', $unit);
    $this->assertStringNotContainsString("RuntimeDirectory=webcam-legacy-helper\n", $unit);
  }

  public function testOnceDownloadsAndUploadsCaptureCommandWithNegotiatedChunks(): void
  {
    $config = $this->writeConfig('upload-secret');
    $fakeCurl = $this->writeFakeCurl();
    [$exitCode, $output] = $this->runHelper('--once', $config, $fakeCurl, '200');

    $this->assertSame(0, $exitCode, $output);
    $this->assertStringContainsString('Capture angefordert', $output);
    $this->assertStringContainsString('Chunk-v1-Upload bestätigt', $output);
    $this->assertSame([], glob($this->dir . '/state/testcam/*.jpg') ?: []);
    $this->assertSame([], glob($this->dir . '/state/testcam/*.request') ?: []);
    $this->assertFileExists($this->dir . '/runtime/testcam.heartbeat');
    $this->assertFileDoesNotExist($this->dir . '/runtime/testcam.headers');

    $calls = (string) file_get_contents($this->dir . '/curl.calls');
    $this->assertStringContainsString('/include/command.php', $calls);
    $this->assertStringContainsString('/testcam/current/raw.jpg', $calls);
    $this->assertStringContainsString('/include/upload-chunk.php', $calls);
    $this->assertStringNotContainsString('/include/upload.php', $calls);
    $this->assertSame(5, substr_count($calls, '/include/upload-chunk.php'));
    $this->assertStringNotContainsString('upload-secret', $calls);
  }

  public function testAutoModeFallsBackWhenChunkEndpointIsExplicitlyUnsupported(): void
  {
    $config = $this->writeConfig('upload-secret');
    $fakeCurl = $this->writeFakeCurl();
    [$exitCode, $output] = $this->runHelper('--once', $config, $fakeCurl, '200', 'unsupported');

    $this->assertSame(0, $exitCode, $output);
    $this->assertStringContainsString('Chunk-v1 nicht angeboten', $output);
    $this->assertStringContainsString('Upload bestätigt', $output);
    $calls = (string) file_get_contents($this->dir . '/curl.calls');
    $this->assertStringContainsString('/include/upload-chunk.php', $calls);
    $this->assertStringContainsString('/include/upload.php', $calls);
  }

  public function testFailedChunkIsRetriedWithoutLosingSpoolImage(): void
  {
    $config = $this->writeConfig('upload-secret');
    $fakeCurl = $this->writeFakeCurl();
    [$exitCode, $output] = $this->runHelper('--once', $config, $fakeCurl, '200', 'supported', true);

    $this->assertSame(0, $exitCode, $output);
    $this->assertStringContainsString('wiederhole', $output);
    $this->assertStringContainsString('Chunk-v1-Upload bestätigt', $output);
    $this->assertSame([], glob($this->dir . '/state/testcam/*.jpg') ?: []);
    $calls = (string) file_get_contents($this->dir . '/curl.calls');
    $this->assertSame(6, substr_count($calls, '/include/upload-chunk.php'));
  }

  public function testCapabilityTransportFailureDoesNotTriggerLargeLegacyUpload(): void
  {
    $config = $this->writeConfig('upload-secret');
    $fakeCurl = $this->writeFakeCurl();
    [$exitCode, $output] = $this->runHelper('--once', $config, $fakeCurl, '200', 'transport-error');

    $this->assertSame(1, $exitCode, $output);
    $this->assertStringContainsString('Capability-Prüfung fehlgeschlagen', $output);
    $images = glob($this->dir . '/state/testcam/*.jpg') ?: [];
    $this->assertCount(1, $images);
    $calls = (string) file_get_contents($this->dir . '/curl.calls');
    $this->assertStringContainsString('/include/upload-chunk.php', $calls);
    $this->assertStringNotContainsString('/include/upload.php', $calls);
  }

  public function testRejectedUploadRemainsInPersistentSpool(): void
  {
    $config = $this->writeConfig('upload-secret');
    $fakeCurl = $this->writeFakeCurl();
    [$exitCode, $output] = $this->runHelper('--once', $config, $fakeCurl, '503');

    $this->assertSame(1, $exitCode, $output);
    $this->assertStringContainsString('Bild bleibt im Spool', $output);
    $images = glob($this->dir . '/state/testcam/*.jpg') ?: [];
    $this->assertCount(1, $images);
    $this->assertGreaterThan(128, filesize($images[0]) ?: 0);
  }

  private function writeConfig(string $key): string
  {
    $path = $this->dir . '/testcam.conf';
    $content = <<<CONF
CAMERA_SLUG="testcam"
PROD_BASE_URL="https://prod.invalid/webcam2"
UPLOAD_KEY="{$key}"
LEGACY_BASE_URL="http://legacy.invalid"
SOURCE_SLUG="testcam"
POLL_SECONDS=1
ERROR_BACKOFF_SECONDS=1
CONNECT_TIMEOUT_SECONDS=1
COMMAND_TIMEOUT_SECONDS=2
SOURCE_TIMEOUT_SECONDS=2
UPLOAD_TIMEOUT_SECONDS=5
CHUNK_TIMEOUT_SECONDS=5
CHUNK_RETRY_COUNT=3
CHUNK_RETRY_DELAY_SECONDS=0
UPLOAD_TRANSPORT="auto"
SOURCE_DELAY_SECONDS=0
MAX_SOURCE_AGE_SECONDS=300
MIN_IMAGE_BYTES=128
MAX_IMAGE_BYTES=1048576
CONF;
    $this->assertNotFalse(file_put_contents($path, $content . "\n"));
    chmod($path, 0600);
    return $path;
  }

  private function writeFakeCurl(): string
  {
    $jpeg = base64_decode(
      '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAAQABADASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAABAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwC4A//Z',
      true
    );
    $this->assertNotFalse($jpeg);
    $jpeg .= str_repeat("\0", 140000);
    $jpegPath = $this->dir . '/source.jpg';
    $this->assertNotFalse(file_put_contents($jpegPath, $jpeg));

    $path = $this->dir . '/fake-curl';
    $script = <<<'BASH'
#!/usr/bin/env bash
set -u
output=""
url=""
action=""
want_output=0
want_form_string=0
for argument in "$@"; do
  if [[ "$want_output" == "1" ]]; then
    output="$argument"
    want_output=0
    continue
  fi
  if [[ "$want_form_string" == "1" ]]; then
    if [[ "$argument" == action=* ]]; then
      action="${argument#action=}"
    fi
    want_form_string=0
    continue
  fi
  if [[ "$argument" == "--output" ]]; then
    want_output=1
    continue
  fi
  if [[ "$argument" == "--form-string" ]]; then
    want_form_string=1
    continue
  fi
  if [[ "$argument" == http://* || "$argument" == https://* ]]; then
    url="$argument"
  fi
done
printf '%s\n' "$url" >> "$FAKE_CALL_LOG"
case "$url" in
  */include/command.php)
    printf 'command\n%s\nwebcam_capture\n' "$(date +%s)" > "$output"
    printf '200'
    ;;
  */current/raw.jpg)
    cp "$FAKE_JPEG" "$output"
    ;;
  */include/upload-chunk.php)
    case "$action" in
      capabilities)
        if [[ "$FAKE_CAPABILITY_MODE" == "transport-error" ]]; then
          exit 28
        elif [[ "$FAKE_CAPABILITY_MODE" == "supported" ]]; then
          printf '{"ok":true,"protocol":"camera-rpi-chunk-v1","preferred_chunk_bytes":65536,"min_chunk_bytes":65536,"max_chunk_bytes":1048576,"max_upload_bytes":1048576}' > "$output"
          printf '200'
        else
          printf '{"ok":false,"error":"not found"}' > "$output"
          printf '404'
        fi
        ;;
      chunk)
        if [[ "$FAKE_CHUNK_FAIL_ONCE" == "1" && ! -e "$FAKE_STATE/chunk-failed-once" ]]; then
          : > "$FAKE_STATE/chunk-failed-once"
          printf '{"ok":false,"error":"temporary"}' > "$output"
          printf '503'
        else
          printf '{"ok":true,"protocol":"camera-rpi-chunk-v1"}' > "$output"
          printf '200'
        fi
        ;;
      finalize)
        if [[ "$FAKE_UPLOAD_STATUS" == "200" ]]; then
          printf '{"ok":true,"protocol":"camera-rpi-chunk-v1"}' > "$output"
        else
          printf '{"ok":false,"error":"test rejection"}' > "$output"
        fi
        printf '%s' "$FAKE_UPLOAD_STATUS"
        ;;
      *)
        exit 22
        ;;
    esac
    ;;
  */include/upload.php)
    if [[ "$FAKE_UPLOAD_STATUS" == "200" ]]; then
      printf '{"ok":true}' > "$output"
    else
      printf '{"ok":false,"error":"test rejection"}' > "$output"
    fi
    printf '%s' "$FAKE_UPLOAD_STATUS"
    ;;
  *)
    exit 22
    ;;
esac
BASH;
    $this->assertNotFalse(file_put_contents($path, $script . "\n"));
    chmod($path, 0700);
    return $path;
  }

  /** @return array{0:int,1:string} */
  private function runHelper(
    string $mode,
    string $config,
    ?string $curl = null,
    string $uploadStatus = '200',
    string $capabilityMode = 'supported',
    bool $chunkFailOnce = false
  ): array {
    $parts = [
      'env',
      'WEBCAM_HELPER_STATE_ROOT=' . escapeshellarg($this->dir . '/state'),
      'WEBCAM_HELPER_RUNTIME_ROOT=' . escapeshellarg($this->dir . '/runtime'),
    ];
    if ($curl !== null) {
      $parts[] = 'WEBCAM_HELPER_CURL_BIN=' . escapeshellarg($curl);
      $parts[] = 'FAKE_JPEG=' . escapeshellarg($this->dir . '/source.jpg');
      $parts[] = 'FAKE_CALL_LOG=' . escapeshellarg($this->dir . '/curl.calls');
      $parts[] = 'FAKE_UPLOAD_STATUS=' . escapeshellarg($uploadStatus);
      $parts[] = 'FAKE_CAPABILITY_MODE=' . escapeshellarg($capabilityMode);
      $parts[] = 'FAKE_CHUNK_FAIL_ONCE=' . escapeshellarg($chunkFailOnce ? '1' : '0');
      $parts[] = 'FAKE_STATE=' . escapeshellarg($this->dir);
    }
    $parts[] = 'bash';
    $parts[] = escapeshellarg($this->script);
    $parts[] = escapeshellarg($mode);
    $parts[] = escapeshellarg($config);
    $parts[] = '2>&1';

    $output = [];
    $exitCode = -1;
    exec(implode(' ', $parts), $output, $exitCode);
    return [$exitCode, implode("\n", $output)];
  }

  private function removeTree(string $path): void
  {
    if (!file_exists($path)) {
      return;
    }
    if (is_file($path) || is_link($path)) {
      @unlink($path);
      return;
    }
    foreach (scandir($path) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $this->removeTree($path . '/' . $entry);
    }
    @rmdir($path);
  }
}
