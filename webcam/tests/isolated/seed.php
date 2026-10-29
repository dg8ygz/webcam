<?php
declare(strict_types=1);

// Nur in der frisch erzeugten, lokalen Testkopie ausfuehren, nie im Checkout.
$root = realpath(__DIR__ . '/../..');
if (!$root || !is_file($root . '/.isolated-e2e') || getenv('WEBCAM_CONFIG_DB') !== $root . '/fixture.sqlite') {
  throw new RuntimeException('Isolated E2E root required');
}
require $root . '/config/camera_store.php';
date_default_timezone_set('Europe/Berlin');
$pdo = new PDO('sqlite:' . $root . '/fixture.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents($root . '/database/schema.config.sql'));
$cameras = [];
foreach (['bielefeld', 'stromberg', 'testcam'] as $slug) {
  $cameras[$slug] = [
    'id' => $slug, 'slug' => $slug, 'name' => ucfirst($slug), 'title' => ucfirst($slug),
    'location' => ['latitude' => 51.9, 'longitude' => 8.5],
    'tags' => $slug === 'testcam' ? ['__test_camera'] : ['Fixture'],
    'mode' => 'passive-upload',
    'capture' => ['interval_seconds' => 600, 'endpoint' => '', 'upload_key' => 'fixture-key'],
    'storage' => ['base_path' => 'storage', 'current_dir' => 'current', 'archive_dir' => 'archive', 'best_of_dir' => 'bestof'],
    'weather_commands' => [], 'status' => [], 'stream' => ['mode' => 'none'],
    'resolutions' => [150, 320, 400, 640],
  ];
}
webcam_camera_store_save([
  'cameras' => $cameras, 'version' => 0,
  'public_rate_limit' => ['limit' => 10000, 'window_seconds' => 60, 'ttl_days' => 1],
  'timeline' => ['max_entries' => 100], 'maintenance_scripts' => [],
]);
// Ein gemeinsamer Zeitanker haelt Archiv, Current und Browsererwartungen konsistent.
// Alle Bilder/Sidecars entstehen synthetisch; Produktionsarchive werden nicht gelesen.
$anchor = (int) getenv('WEBCAM_E2E_ANCHOR');
$today = (new DateTimeImmutable('@' . (intdiv($anchor, 600) * 600 - 1800)))
  ->setTimezone(new DateTimeZone('Europe/Berlin'));
$times = [];
foreach ([2, 1, 0] as $days) {
  for ($i = 0; $i < 4; $i++) {
    $times[] = $today->modify("-$days days")->modify('+' . ($i * 10) . ' minutes')->getTimestamp();
  }
}
foreach ($cameras as $slug => $camera) {
  mkdir($root . '/camera-db/' . $slug, 0700, true);
  $db = new PDO('sqlite:' . $root . '/camera-db/' . $slug . '/db.sqlite');
  (new Webcam\Infrastructure\Storage\CameraDataSchemaRepository())->ensure($db);
  foreach ($times as $index => $ts) {
    $date = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'));
    $dir = 'cache/' . $slug . '/' . $date->format('Y/m/d');
    if (!is_dir($root . '/' . $dir)) mkdir($root . '/' . $dir, 0700, true);
    $base = $dir . '/' . $date->format('Ymd_His');
    $image = imagecreatetruecolor(640, 360);
    imagefill($image, 0, 0, imagecolorallocate($image, 40 + $index * 10, 90, 130));
    imagestring($image, 5, 20, 20, "$slug fixture $index", imagecolorallocate($image, 255, 255, 255));
    imagejpeg($image, $root . '/' . $base . '.jpg', 85);
    $q = $db->prepare('INSERT INTO captures(slot_ts,captured_ts,relative_path,hash_sha256,created_at,likes_count) VALUES(?,?,?,?,?,?)');
    $q->execute([$ts, $ts, $base . '.jpg', hash_file('sha256', $root . '/' . $base . '.jpg'), $ts, $index === 11 ? 2 : 0]);
    $id = (int) $db->lastInsertId();
    foreach (['sm' => 150, 'la' => 320, 'lm' => 640, 'hu' => 640, 'full' => 640, '150' => 150, '320' => 320, '400' => 400, '640' => 640] as $variant => $width) {
      $height = (int) round($width * 360 / 640);
      $scaled = imagescale($image, $width, $height);
      $path = $base . '_' . $variant . '.jpg';
      imagejpeg($scaled, $root . '/' . $path, 85);
      $q = $db->prepare('INSERT INTO variants(capture_id,variant,width,height,relative_path,size_bytes,mtime) VALUES(?,?,?,?,?,?,?)');
      $q->execute([$id, (string) $variant, $width, $height, $path, filesize($root . '/' . $path), $ts]);
    }
    file_put_contents($root . '/' . $base . '_ex.txt', "Model|Fixture camera\nISO|100\n");
    file_put_contents($root . '/' . $base . '_WX.txt', "Temp: 21.5 C\nHumidity: 55.0 %\n");
    $db->exec("INSERT INTO exif_entries(capture_id,tag,value) VALUES($id,'Model','Fixture camera')");
    $db->exec("INSERT INTO weather_entries(capture_id,key,value,position) VALUES($id,'Temp','Temp: 21.5 C',0),($id,'Humidity','Humidity: 55.0 %',1)");
  }
  mkdir($root . '/' . $slug . '/current', 0700, true);
  foreach (['full', 'hu', 'lm', 'la', 'sm', '150', '320', '400', '640'] as $variant) {
    copy($root . '/' . $base . '_' . $variant . '.jpg', $root . '/' . $slug . '/current/' . $variant . '.jpg');
  }
  copy($root . '/' . $base . '.jpg', $root . '/' . $slug . '/current/current.jpg');
  $bestOf = $root . '/storage/bestof/' . $slug;
  mkdir($bestOf, 0700, true);
  copy($root . '/' . $base . '_la.jpg', $bestOf . '/' . basename($base) . '_la.jpg');
  $state = $root . '/var/state/cameras';
  if (!is_dir($state)) mkdir($state, 0700, true);
  file_put_contents($state . '/' . $slug . '.json', json_encode([
    'last_capture_at' => $ts, 'last_success_at' => $ts,
    'last_weather_data' => "Temp: 21.5 C\nHumidity: 55.0 %", 'last_weather_at' => $ts,
    'last_exif' => ['Model' => 'Fixture camera'],
  ], JSON_THROW_ON_ERROR));
}
file_put_contents($root . '/fixture.json', json_encode([
  'nonce' => trim(file_get_contents($root . '/.isolated-e2e')),
  'anchor' => $anchor, 'from' => $times[0], 'to' => end($times),
  'year' => (int) $today->format('Y'), 'month' => (int) $today->format('m'),
  'day' => (int) $today->format('d'), 'frames_per_camera' => count($times),
], JSON_THROW_ON_ERROR));
echo "Isolated fixtures created: 3 cameras, 36 captures; no live data\n";
