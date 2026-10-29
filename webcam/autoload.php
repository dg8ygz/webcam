<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Support/helpers.php';

// Gemeinsamer, konfigurationsfreier Klassenlader fuer Web- und isolierte
// Runtime-Prozesse. Erst bootstrap.php laedt danach die konkrete App-Config.
spl_autoload_register(static function (string $class): void {
  $prefix = 'Webcam\\';
  if (!str_starts_with($class, $prefix)) {
    return;
  }
  $relative = substr($class, strlen($prefix));
  $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
  if (is_file($path)) {
    require_once $path;
  }
});
