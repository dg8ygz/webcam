<?php
declare(strict_types=1);

// Only this private test router exposes the per-process identity challenge.
if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/__phpunit_identity') {
  header('Content-Type: text/plain');
  header('Cache-Control: no-store');
  echo getenv('WEBCAM_TEST_SERVER_NONCE') ?: '';
  return;
}

return require __DIR__ . '/../router.php';
