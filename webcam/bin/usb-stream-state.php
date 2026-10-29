<?php
declare(strict_types=1);

// Boot-time private registration -> tmpfs. No App/bootstrap, database or media
// access; PathResolver namespace is identical to config/app.php state_path.
require __DIR__ . '/../src/Infrastructure/Filesystem/PathResolver.php';
require __DIR__ . '/../src/Service/Stream/UsbStream.php';
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0 || $argc !== 2) { exit(64); }
$paths = new \Webcam\Infrastructure\Filesystem\PathResolver(null, null, realpath(__DIR__ . '/../var/state'), null);
$usb = \Webcam\Service\Stream\UsbStream::forPaths($paths);
$dir = $usb->directory('test');
$raw = file_get_contents($argv[1], false, null, 0, 4097);
if (!is_string($raw) || strlen($raw) > 4096) { exit(65); }
if (!is_dir($dir) && !mkdir($dir, 0755, true)) { exit(73); }
chmod($dir, 0700); chown($dir, 'www-data'); chgrp($dir, 'www-data');
// Privacy heartbeats share this installation's RAM namespace, but not its
// media files. Root creates the writable leaf; never grant /run broadly.
$privacyDir=dirname(dirname($dir)).'/camera-privacy';
if (!is_dir($privacyDir) && !mkdir($privacyDir,0700)) { exit(73); }
chmod($privacyDir,0700); chown($privacyDir,'www-data'); chgrp($privacyDir,'www-data');
umask(0077);
if (file_put_contents($dir . '/registration.json', $raw) !== strlen($raw)) { exit(74); }
chown($dir . '/registration.json', 'www-data'); chgrp($dir . '/registration.json', 'www-data');
$usb->registration('test');
echo "USB-Registrierung im installationsgetrennten RAM-Namensraum bereit.\n";
