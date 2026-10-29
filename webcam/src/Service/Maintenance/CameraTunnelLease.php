<?php
declare(strict_types=1);

namespace Webcam\Service\Maintenance;

use RuntimeException;

/** Desired state, not proof of an SSH connection. A poll never extends a lease. */
final class CameraTunnelLease
{
  public const PROTOCOL = 'camera-rpi-tunnel-v1';
  public function __construct(private readonly string $directory) {}

  /** Liefert Freigabe und letzte Helper-Meldung getrennt; running beweist kein SSH. */
  public function status(string $slug, ?int $now = null): array
  {
    $now ??= time();
    return $this->locked($slug, static fn(array &$s) => [
      'available' => isset($s['seen']) && $s['seen'] <= $now && $now - $s['seen'] < 1800,
      'expires_at' => ($s['until'] ?? 0) > $now ? $s['until'] : null,
      'reported_state' => $s['reported'] ?? 'unknown',
      'reported_at' => $s['seen'] ?? null,
    ]);
  }

  /**
   * Admin-Freigabe fuer 30 Minuten nur bei frischer Capability; nie verlaengern.
   * close entfernt den Sollzustand, den der Pi erst beim naechsten Poll erfaehrt.
   */
  public function request(string $slug, string $action, ?int $now = null): void
  {
    if (!in_array($action, ['open', 'close'], true)) throw new RuntimeException('Ungueltige Tunnelaktion', 400);
    $now ??= time();
    $this->locked($slug, static function (array &$s) use ($action, $now): void {
      if ($action === 'open') {
        if (!isset($s['seen']) || $s['seen'] > $now || $now - $s['seen'] >= 1800) {
          throw new RuntimeException('Wartungsdienst ist nicht eingerichtet oder nicht aktuell gemeldet', 409);
        }
        if (($s['until'] ?? 0) > $now) throw new RuntimeException('Freigabe besteht bereits; keine automatische Verlaengerung', 409);
        $s['id'] = bin2hex(random_bytes(16));
        $s['until'] = $now + 1800;
      } else {
        unset($s['id'], $s['until']);
      }
    });
  }

  /** Must be called after upload-key authentication, only for explicitly capable clients. */
  public function poll(string $slug, string $protocol, string $reported, ?int $now = null): ?string
  {
    if ($protocol !== self::PROTOCOL) return null;
    $now ??= time();
    return $this->locked($slug, static function (array &$s) use ($reported, $now): string {
      $s['seen'] = $now;
      $s['reported'] = in_array($reported, ['closed', 'running', 'error'], true) ? $reported : 'unknown';
      if (($s['until'] ?? 0) > $now && $s['until'] <= $now + 1800) return $s['id'] . ':' . $s['until'];
      unset($s['id'], $s['until']);
      return 'closed';
    });
  }

  /** Schema und Groesse unter stabilem, nichtblockierendem Kameralock pruefen. */
  private function locked(string $slug, callable $callback): mixed
  {
    if (strlen($slug) > 128 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) throw new RuntimeException('Invalid camera', 400);
    if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) throw new RuntimeException('Tunnel state unavailable');
    $fp = @fopen($this->directory . '/' . $slug . '.json', 'c+');
    if (!$fp) throw new RuntimeException('Tunnel state unavailable');
    try {
      if (!flock($fp, LOCK_EX | LOCK_NB)) throw new RuntimeException('Tunnel state busy', 409);
      $raw = stream_get_contents($fp, 2049);
      $s = $raw === '' ? [] : json_decode((string) $raw, true);
      if (!is_array($s) || strlen((string) $raw) > 2048
        || array_diff(array_keys($s), ['seen', 'reported', 'id', 'until'])
        || (isset($s['seen']) && !is_int($s['seen'])) || (isset($s['until']) && !is_int($s['until']))
        || (isset($s['reported']) && !in_array($s['reported'], ['closed', 'running', 'error', 'unknown'], true))
        || (isset($s['until']) !== isset($s['id']))
        || (isset($s['id']) && (!is_string($s['id']) || !preg_match('/^[a-f0-9]{32}$/D', $s['id'])))) throw new RuntimeException('Tunnel state invalid');
      $before = $s;
      $result = $callback($s);
      if ($s !== $before) {
        $json = json_encode($s, JSON_THROW_ON_ERROR);
        if (!rewind($fp) || !ftruncate($fp, 0) || fwrite($fp, $json) !== strlen($json) || !fflush($fp)) throw new RuntimeException('Tunnel state write failed');
      }
      return $result;
    } finally { flock($fp, LOCK_UN); fclose($fp); }
  }
}
