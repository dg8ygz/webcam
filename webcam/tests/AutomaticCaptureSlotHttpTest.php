<?php
declare(strict_types=1);

/** Exercise the real HTTP endpoint with private test DBs, never a live camera. */
final class AutomaticCaptureSlotHttpTest extends WebcamHttpTestCase
{
  public function testHttpPollHonoursWindowAndAdditionalCaptureTime(): void
  {
    $slug='schedule-'.bin2hex(random_bytes(4));
    $key=bin2hex(random_bytes(16)); $csrf=bin2hex(random_bytes(16));
    $headers=['X-WC-Test: 1','X-CSRF-Token: '.$csrf,'Cookie: admin_csrf='.$csrf,'Content-Type: application/json'];
    $app=require __DIR__.'/../bootstrap.php';
    $zone=new DateTimeZone($app->config('timezone','UTC'));
    $now=new DateTimeImmutable('now',$zone);
    $window=['start'=>$now->modify('+2 hours')->format('H:i'),'end'=>$now->modify('+3 hours')->format('H:i')];
    try {
      [$code,$body]=$this->request('/admin/api.php?path=cameras&test=1','POST',$headers,json_encode([
        'slug'=>$slug,'name'=>$slug,'mode'=>'active-capture','capture'=>['upload_key'=>$key,'interval_seconds'=>86400,'window'=>$window]]));
      self::assertSame(201,$code,$body);
      $poll=fn()=>$this->request('/include/command.php?wc='.$slug,'GET',['X-Command-Key: '.$key]);
      self::assertSame("wait\n0\nwait\n",$poll()[1]);
      // Manual capture remains available outside the automatic window.
      self::assertSame(202,$this->request('/admin/api.php?path=cameras/'.$slug.'/capture&test=1','POST',$headers)[0]);
      self::assertStringContainsString('webcam_capture',$poll()[1]);
      $now=new DateTimeImmutable('now',$zone);
      $extra=$now->setTime((int)$now->format('H'),(int)$now->format('i'))->getTimestamp();
      // Clear the manual marker to exercise a new automatic extra identity.
      @unlink(dirname(__DIR__).'/var/state/command/'.$slug.'.slot');
      [$code,$body]=$this->request('/admin/api.php?path=cameras/'.$slug.'&test=1','PUT',$headers,json_encode([
        'slug'=>$slug,'name'=>$slug,'mode'=>'active-capture',
        'capture'=>['upload_key'=>$key,'interval_seconds'=>86400,'window'=>['start'=>'00:00','end'=>'23:59'],'extra_times'=>[$now->format('H:i')]]]));
      self::assertSame(200,$code,$body);
      self::assertSame("command\n{$extra}\nwebcam_capture\n",$poll()[1]);
      self::assertSame("wait\n0\nwait\n",$poll()[1]);
    } finally {
      $this->request('/admin/api.php?path=cameras/'.$slug.'&test=1','DELETE',$headers);
      @unlink(dirname(__DIR__).'/var/state/command/'.$slug.'.slot');
    }
  }

  public function testLatePollAndLostMarkerDoNotCreateANewAutomaticIdentity(): void
  {
    $slug = 'slot-' . bin2hex(random_bytes(4));
    $key = bin2hex(random_bytes(16));
    $csrf = bin2hex(random_bytes(16));
    $headers = ['X-WC-Test: 1', 'X-CSRF-Token: ' . $csrf,
      'Cookie: admin_csrf=' . $csrf, 'Content-Type: application/json'];
    // A long test interval avoids wall-clock boundary races. No camera helper
    // is connected; the endpoint still uses its real clock/locks/SQLite reader.
    $interval = 86400;
    $dbRoot = (string) getenv('WEBCAM_CAMERA_DB_PATH');
    self::assertStringContainsString('webcam-tests-', $dbRoot);
    $dbPath = $dbRoot . '/' . $slug . '/db.sqlite';
    $marker = dirname(__DIR__) . '/var/state/command/' . $slug . '.slot';
    try {
      [$status, $body] = $this->request('/admin/api.php?path=cameras&test=1', 'POST', $headers,
        json_encode(['slug' => $slug, 'name' => $slug, 'mode' => 'active-capture',
          'capture' => ['upload_key' => $key, 'interval_seconds' => $interval]], JSON_THROW_ON_ERROR));
      self::assertSame(201, $status, $body);
      $poll = fn() => $this->request('/include/command.php?wc=' . $slug, 'GET', ['X-Command-Key: ' . $key]);
      [$status, $first] = $poll();
      self::assertSame(200, $status);
      $slot = intdiv(time(), $interval) * $interval;
      self::assertSame("command\n{$slot}\nwebcam_capture\n", $first);
      self::assertSame("wait\n0\nwait\n", $poll()[1]);

      // Simulate a fresh target/forgotten dispatch marker, after wall time has
      // advanced: the identity must remain stable even before image ingest.
      unlink($marker);
      sleep(1);
      self::assertSame($first, $poll()[1]);

      if (!is_dir(dirname($dbPath))) mkdir(dirname($dbPath), 0700, true);
      $pdo = new PDO('sqlite:' . $dbPath);
      $pdo->exec('CREATE TABLE captures (slot_ts INTEGER, is_duplicate INTEGER)');
      $pdo->exec('INSERT INTO captures VALUES (' . $slot . ', 0)');
      $pdo = null;
      $before = hash_file('sha256', $dbPath);
      unlink($marker);
      self::assertSame("wait\n0\nwait\n", $poll()[1]);
      self::assertSame($before, hash_file('sha256', $dbPath));

      // An explicit manual capture still works despite an already received
      // slot; DB damage must not advance the automatic dispatch marker.
      [$status] = $this->request('/admin/api.php?path=cameras/' . $slug . '/capture&test=1', 'POST', $headers);
      self::assertSame(202, $status);
      self::assertStringContainsString('webcam_capture', $poll()[1]);
      unlink($marker);
      file_put_contents($dbPath, 'invalid SQLite');
      self::assertSame("wait\n0\nwait\n", $poll()[1]);
      self::assertSame('', file_get_contents($marker));
    } finally {
      $this->request('/admin/api.php?path=cameras/' . $slug . '&test=1', 'DELETE', $headers);
      if (is_file($marker)) unlink($marker);
    }
  }
}
