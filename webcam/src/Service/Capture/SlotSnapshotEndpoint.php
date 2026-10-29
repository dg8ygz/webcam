<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Webcam\Infrastructure\Http\HttpClient;

/**
 * Bindet eine Snapshot-URL optional an einen exakten Aufnahme-Slot.
 *
 * Nur {slot_path}, {slot_stamp} und {slot_unix} in Pfad/Query werden ersetzt.
 * Die Quelle muss unter diesen datierten URLs genau das angeforderte Original
 * liefern, niemals einen Current-Fallback. HTTP 404 vor Slotende bedeutet dann
 * lediglich "noch nicht veroeffentlicht". Kein Sleep und kein eigenes Polling:
 * der vorhandene Pull-Scheduler versucht es in seiner naechsten Runde erneut.
 * Normale URLs behalten ihren bisherigen Aufnahme- und Fehlervertrag.
 */
final class SlotSnapshotEndpoint
{
  public readonly bool $templated;
  public readonly string $url;
  public readonly ?int $slot;

  public function __construct(
    string $endpoint,
    ?int $slot,
    private readonly int $interval,
    int $now,
    string $timezone
  ) {
    $this->templated = str_contains($endpoint, '{') || str_contains($endpoint, '}');
    if (!$this->templated) {
      $this->url = $endpoint;
      $this->slot = $slot;
      return;
    }
    if (!HttpClient::isHttpUrl($endpoint) || $interval < 60 || $now <= 0 || ($slot !== null && $slot <= 0)) {
      throw new RuntimeException('Invalid slot snapshot endpoint or interval');
    }
    // Zeitplatzhalter duerfen nur die Ressource, nie Host, Port oder Auth aendern.
    $parts = parse_url($endpoint);
    foreach (['scheme', 'host', 'user', 'pass', 'fragment'] as $key) {
      if (strpbrk((string) ($parts[$key] ?? ''), '{}') !== false) {
        throw new RuntimeException('Snapshot slot placeholders are only allowed in path and query');
      }
    }
    $this->slot = $slot ?? intdiv($now, $interval) * $interval;
    $date = (new DateTimeImmutable('@' . $this->slot))->setTimezone(new DateTimeZone($timezone));
    $this->url = strtr($endpoint, [
      '{slot_path}' => $date->format('Y/m/d'),
      '{slot_stamp}' => $date->format('Ymd_His'),
      '{slot_unix}' => (string) $this->slot,
    ]);
    if (strpbrk($this->url, '{}') !== false || !HttpClient::isHttpUrl($this->url)) {
      throw new RuntimeException('Unknown or invalid snapshot slot placeholder');
    }
  }

  /** Nur erwartete Publikationsverzoegerung warten lassen, echte Fehler nicht verschlucken. */
  public function isAwaitingPublication(int $status, int $now): bool
  {
    return $this->templated && $status === 404
      && $now >= $this->slot && $now < $this->slot + $this->interval;
  }
}
