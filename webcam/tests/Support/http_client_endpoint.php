<?php
declare(strict_types=1);

// Isolated loopback-only test server, started and reaped by HttpClientTest.
switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
  case '/large-known':
    header('Content-Length: 4097');
    echo str_repeat('x', 4097);
    break;
  case '/large-unknown':
    echo str_repeat('x', 4097);
    flush();
    break;
  case '/exact-budget':
    echo str_repeat('x', 4096);
    break;
  case '/redirect':
    header('Location: /ok', true, 302);
    break;
  case '/loop':
    header('Location: /loop', true, 302);
    break;
  case '/file':
    header('Location: file:///not-a-real-watchdog-test-file', true, 302);
    break;
  case '/ftp':
    header('Location: ftp://127.0.0.1:1/not-a-real-watchdog-test-file', true, 302);
    break;
  case '/slow':
    header('Content-Length: 204800');
    for ($chunk = 0; $chunk < 50 && !connection_aborted(); $chunk++) {
      echo str_repeat('x', 4096);
      flush();
      usleep(100000);
    }
    break;
  default:
    http_response_code(201);
    header('X-Fixture: bounded-http');
    echo json_encode([
      'method' => $_SERVER['REQUEST_METHOD'],
      'body' => file_get_contents('php://input'),
      'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
      'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    ]);
}
