<?php
declare(strict_types=1);

// Private first-test provisioning. Never print tokens or copy upload keys.
// Second invocation adds Pi config after reading the relay's PUBLIC fingerprint.
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0 || !in_array($argc, [2, 3], true)) {
  fwrite(STDERR, "Aufruf als root: php prepare-video-registration.php PRIVATE-DIR [FINGERPRINT]\n"); exit(1);
}
$dir = realpath($argv[1]);
if (!$dir || is_link($argv[1]) || (fileperms($dir) & 0077) !== 0 || fileowner($dir) !== 0) {
  throw new RuntimeException('Privates root-Verzeichnis 0700 erforderlich');
}
umask(0077);
if ($argc === 2) {
  if (file_exists($dir . '/users.json') || file_exists($dir . '/registration.json')) { throw new RuntimeException('Registrierung existiert bereits'); }
  $auth = 'video-test:' . bin2hex(random_bytes(24));
  file_put_contents($dir . '/users.json', json_encode([$auth => ['^R:127\\.0\\.0\\.1:23001$']], JSON_THROW_ON_ERROR));
  file_put_contents($dir . '/registration.json', json_encode(['port' => 23001, 'token' => bin2hex(random_bytes(32))], JSON_THROW_ON_ERROR));
} else {
  if (!preg_match('~^[A-Za-z0-9+/]{43}=$~D', $argv[2]) || file_exists($dir . '/video.json')) { throw new RuntimeException('Fingerprint/Zieldatei ungueltig'); }
  $registration = json_decode(file_get_contents($dir . '/registration.json'), true, 16, JSON_THROW_ON_ERROR);
  $users = json_decode(file_get_contents($dir . '/users.json'), true, 16, JSON_THROW_ON_ERROR);
  $c = ['control_url' => 'https://webcam.example.invalid/testx/include/video-command.php',
    'camera_slug' => 'test', 'key_file' => '/etc/camera-rpi/keys/dev-test.key',
    'tunnel_url' => 'https://webcam.example.invalid/camera-maintenance/video/',
    'fingerprint' => $argv[2], 'auth' => array_key_first($users), 'port' => $registration['port'],
    'token' => $registration['token'], 'device' => '/dev/video0', 'input_fps' => 30];
  file_put_contents($dir . '/video.json', json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo "Private Video-Registrierung vorbereitet; keine Verbindung gestartet.\n";
