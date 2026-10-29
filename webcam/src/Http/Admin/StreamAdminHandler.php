<?php
declare(strict_types=1);

namespace Webcam\Http\Admin;

use InvalidArgumentException;
use Webcam\App;
use Webcam\Service\Stream\StreamState;

/**
 * HTTP-Handler für die kurzlebige Stream-Verwaltung im Adminbereich.
 *
 * Der zentrale Router hat den Request bereits authentifiziert und bei POST
 * zusätzlich den CSRF-Nachweis geprüft. Diese Klasse ordnet danach nur noch
 * die `/streams`-Routen den vorhandenen Operationen von StreamState zu.
 *
 * Leseführung:
 * 1. GET vereinigt aktive MJPEG-/HLS-Viewer und vorhandene IP-Sperren,
 * 2. die Übersicht zählt Viewer zusätzlich pro Kamera,
 * 3. POST liest den JSON-Body erst nach einer exakten Routenentscheidung,
 * 4. fachlich ungültige Tokens oder IPs werden einheitlich zu HTTP 400,
 * 5. Header und Antwortausgabe bleiben ausschließlich in `admin/api.php`.
 *
 * StreamState bleibt die einzige Quelle für Runtime-Dateien, Lockprüfung und
 * Clientidentitäten. Der Handler führt weder eine zweite Zustandsablage noch
 * einen eigenen Cache ein.
 */
final class StreamAdminHandler
{
  private readonly StreamState $streamState;

  public function __construct(App $app, ?StreamState $streamState = null)
  {
    // Die optionale Übergabe hält Vertragstests klein. Im echten Request wird
    // immer der normale, an dieselbe App gebundene StreamState verwendet.
    $this->streamState = $streamState ?? new StreamState($app);
  }

  /** @param list<string> $segments */
  public function supports(array $segments): bool
  {
    return ($segments[0] ?? null) === 'streams';
  }

  /**
   * @param list<string> $segments
   * @param callable():array<string,mixed> $readJson
   */
  public function handle(
    string $method,
    array $segments,
    callable $readJson
  ): AdminRouteResult {
    if (!$this->supports($segments)) {
      return $this->result(404, ['detail' => 'Not found']);
    }

    if ($method === 'GET' && $segments === ['streams']) {
      return $this->overview();
    }

    // Nur bekannte POST-Aktionen dürfen den Request-Body konsumieren. Das ist
    // wichtig für klare Methodensemantik und für spätere Middleware-Schritte.
    if ($method === 'POST' && $segments === ['streams', 'disconnect']) {
      return $this->disconnect($readJson());
    }
    if ($method === 'POST' && $segments === ['streams', 'block']) {
      return $this->block($readJson());
    }
    if ($method === 'POST' && $segments === ['streams', 'unblock']) {
      return $this->unblock($readJson());
    }

    return $this->result(404, ['detail' => 'Not found']);
  }

  /** Erstellt das im Admin sichtbare Laufzeitbild ohne persistente Kopie. */
  private function overview(): AdminRouteResult
  {
    $clients = $this->streamState->activeClients();
    $blockedIps = $this->streamState->blockedIps();
    $byCamera = [];

    // Leere oder beschädigte Legacy-Metadaten dürfen keinen anonymen
    // Sammelschlüssel erzeugen. StreamState liefert ansonsten normalisierte
    // Kamera-Slugs, die hier lediglich gezählt werden.
    foreach ($clients as $client) {
      $slug = (string) ($client['camera'] ?? '');
      if ($slug === '') {
        continue;
      }
      $byCamera[$slug] = ($byCamera[$slug] ?? 0) + 1;
    }

    return $this->result(200, [
      'items' => $clients,
      'blocked_ips' => $blockedIps,
      'summary' => [
        'total_active' => count($clients),
        'blocked_total' => count($blockedIps),
        'by_camera' => $byCamera,
      ],
    ]);
  }

  /** Markiert genau den angegebenen Viewer-Token als administrativ getrennt. */
  private function disconnect(array $body): AdminRouteResult
  {
    try {
      $this->streamState->disconnectToken((string) ($body['token'] ?? ''));
    } catch (InvalidArgumentException $exception) {
      return $this->result(400, ['detail' => $exception->getMessage()]);
    }

    return $this->result(200, ['ok' => true]);
  }

  /** Fügt eine IP-Sperre hinzu und liefert die autoritative Sperrliste zurück. */
  private function block(array $body): AdminRouteResult
  {
    try {
      $this->streamState->blockIp(
        (string) ($body['ip'] ?? ''),
        (string) ($body['note'] ?? 'admin')
      );
    } catch (InvalidArgumentException $exception) {
      return $this->result(400, ['detail' => $exception->getMessage()]);
    }

    return $this->result(200, [
      'ok' => true,
      'blocked_ips' => $this->streamState->blockedIps(),
    ]);
  }

  /** Entfernt genau eine validierte IP aus der autoritativen Sperrliste. */
  private function unblock(array $body): AdminRouteResult
  {
    try {
      $this->streamState->unblockIp((string) ($body['ip'] ?? ''));
    } catch (InvalidArgumentException $exception) {
      return $this->result(400, ['detail' => $exception->getMessage()]);
    }

    return $this->result(200, [
      'ok' => true,
      'blocked_ips' => $this->streamState->blockedIps(),
    ]);
  }

  /** @param array<string,mixed> $payload */
  private function result(int $status, array $payload): AdminRouteResult
  {
    return new AdminRouteResult($status, $payload);
  }
}
