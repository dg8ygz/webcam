<?php
declare(strict_types=1);

/** Speichert und verbraucht kurzlebige Captcha-Challenges ohne Klartextcode. */

function captcha_state_secret(string $directory): string
{
  $envSecret = getenv('WEBCAM_CAPTCHA_SECRET');
  if (is_string($envSecret) && strlen(trim($envSecret)) >= 32) {
    return trim($envSecret);
  }

  $path = $directory . DIRECTORY_SEPARATOR . '.secret';
  $existing = is_file($path) ? trim((string) file_get_contents($path)) : '';
  if (preg_match('/^[a-f0-9]{64}$/', $existing)) {
    return $existing;
  }

  $secret = bin2hex(random_bytes(32));
  $handle = @fopen($path, 'x');
  if (is_resource($handle)) {
    try {
      if (fwrite($handle, $secret . "\n") === false || !fflush($handle)) {
        throw new RuntimeException('Captcha secret could not be written');
      }
      @chmod($path, 0600);
      return $secret;
    } finally {
      fclose($handle);
    }
  }

  // Ein paralleler Request kann die Datei zwischen Prüfung und fopen angelegt haben.
  $existing = is_file($path) ? trim((string) file_get_contents($path)) : '';
  if (preg_match('/^[a-f0-9]{64}$/', $existing)) {
    return $existing;
  }
  throw new RuntimeException('Captcha secret unavailable');
}

function captcha_state_store(string $directory, string $id, string $code, int $createdAt): string
{
  if (!preg_match('/^[a-f0-9]{32}$/', $id) || !preg_match('/^[A-Z0-9]{4,8}$/', $code)) {
    throw new InvalidArgumentException('Invalid captcha challenge');
  }
  $secret = captcha_state_secret($directory);
  $entry = [
    'code_hash' => hash_hmac('sha256', strtolower($code), $secret),
    'created_at' => $createdAt,
  ];
  $path = $directory . DIRECTORY_SEPARATOR . $id . '.json';
  $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
  $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  if (file_put_contents($temporary, $json, LOCK_EX) === false) {
    throw new RuntimeException('Captcha state could not be written');
  }
  @chmod($temporary, 0600);
  if (!rename($temporary, $path)) {
    @unlink($temporary);
    throw new RuntimeException('Captcha state could not be published');
  }
  return $path;
}

function captcha_state_validate(string $directory, string $id, string $code, int $now, int $ttl = 600): bool
{
  $id = strtolower(trim($id));
  $code = strtolower(trim($code));
  if (!preg_match('/^[a-f0-9]{32}$/', $id) || !preg_match('/^[a-z0-9]{4,8}$/', $code)) {
    return false;
  }
  $path = $directory . DIRECTORY_SEPARATOR . $id . '.json';
  $claimedPath = $path . '.used-' . bin2hex(random_bytes(6));
  if (!is_file($path) || !@rename($path, $claimedPath)) {
    return false;
  }

  try {
    $payload = json_decode((string) file_get_contents($claimedPath), true);
    if (!is_array($payload)) {
      return false;
    }
    $storedHash = strtolower((string) ($payload['code_hash'] ?? ''));
    $createdAt = (int) ($payload['created_at'] ?? 0);
    if (!preg_match('/^[a-f0-9]{64}$/', $storedHash) || $createdAt <= 0 || $createdAt < $now - $ttl) {
      return false;
    }
    $expected = hash_hmac('sha256', $code, captcha_state_secret($directory));
    return hash_equals($storedHash, $expected);
  } finally {
    @unlink($claimedPath);
  }
}

/** Bereinigt höchstens $maxEntries und höchstens einmal pro Intervall. */
function captcha_state_cleanup(string $directory, int $now, int $ttl = 600, int $maxEntries = 200): void
{
  $lockPath = $directory . DIRECTORY_SEPARATOR . '.cleanup.lock';
  $handle = @fopen($lockPath, 'c+');
  if (!is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
    if (is_resource($handle)) {
      fclose($handle);
    }
    return;
  }

  try {
    $stat = fstat($handle);
    if (is_array($stat) && (int) ($stat['mtime'] ?? 0) >= $now - 60) {
      return;
    }
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, (string) $now);
    fflush($handle);

    $checked = 0;
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
      if ($checked++ >= max(1, $maxEntries)) {
        break;
      }
      $name = $file->getFilename();
      if (!preg_match('/^[a-f0-9]{32}\.json(?:\.(?:used|tmp)-[a-f0-9]+)?$/', $name)) {
        continue;
      }
      $maxAge = str_contains($name, '.json.') ? 60 : $ttl;
      if ($file->getMTime() < $now - $maxAge) {
        @unlink($file->getPathname());
      }
    }
  } finally {
    flock($handle, LOCK_UN);
    fclose($handle);
  }
}
