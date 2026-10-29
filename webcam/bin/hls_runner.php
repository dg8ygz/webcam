#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\Service\Stream\HlsRunner;

require __DIR__ . '/../autoload.php';

$configPath = '';
foreach (array_slice($argv, 1) as $argument) {
  if (str_starts_with($argument, '--config=')) {
    $configPath = substr($argument, strlen('--config='));
  }
}

if ($configPath === '') {
  fwrite(STDERR, "Usage: php bin/hls_runner.php --config=<path>\n");
  exit(64);
}

try {
  $inputManifest = stream_get_contents(STDIN, 65537);
  if (!is_string($inputManifest) || strlen($inputManifest) > 65536) {
    fwrite(STDERR, "Protected HLS input is invalid\n");
    exit(65);
  }
  exit(HlsRunner::runFromConfig($configPath, $inputManifest));
} catch (Throwable $exception) {
  fwrite(
    STDERR,
    Webcam\Infrastructure\Security\SecretRedactor::redact($exception->getMessage()) . PHP_EOL
  );
  exit(70);
}
