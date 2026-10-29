<?php
declare(strict_types=1);

namespace Webcam\Http\Admin;

use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Bereitet den kompakten Kamera-Laufzeitstatus für die Admin-API auf.
 *
 * StatusService bleibt die autoritative Quelle für Capture-, Fehler- und
 * Reset-Zähler. Dieser Handler ergänzt nur die für die Übersicht benötigten
 * Felder `age_seconds` und `severity`; er schreibt dabei keinen Status zurück.
 *
 * Leseführung:
 * 1. nur `GET /status` akzeptieren,
 * 2. jede konfigurierte Kamera genau einmal beim StatusService abfragen,
 * 3. das Alter relativ zu einem gemeinsamen Request-Zeitpunkt berechnen,
 * 4. den Schweregrad anhand des Kamera-Intervalls einordnen,
 * 5. das neutrale Ergebnis an `admin/api.php` zurückgeben.
 */
final class StatusAdminHandler
{
  public function __construct(
    private readonly App $app,
    private readonly ?int $nowOverride = null
  ) {}

  /** @param list<string> $segments */
  public function supports(array $segments): bool
  {
    return $segments === ['status'];
  }

  /** @param list<string> $segments */
  public function handle(string $method, array $segments): AdminRouteResult
  {
    if ($method !== 'GET' || !$this->supports($segments)) {
      return $this->result(404, ['detail' => 'Not found']);
    }

    $statusService = $this->app->statusService();
    $now = $this->nowOverride ?? time();
    $items = [];

    // Ein gemeinsamer Zeitwert verhindert, dass sich age_seconds innerhalb
    // einer langen Kameraliste allein durch die Schleifenlaufzeit unterscheidet.
    foreach ($this->app->cameras()->all() as $camera) {
      $items[] = $this->cameraItem($camera, $statusService->state($camera), $now);
    }

    return $this->result(200, ['items' => $items]);
  }

  /**
   * Formt ausschließlich die Felder des bestehenden HTTP-Vertrags.
   *
   * @param array<string,mixed> $state
   * @return array<string,mixed>
   */
  private function cameraItem(Camera $camera, array $state, int $now): array
  {
    $interval = (int) ($camera->capture()['interval_seconds'] ?? 600);
    $lastSuccess = isset($state['last_success_at'])
      ? (int) $state['last_success_at']
      : null;
    $lastCapture = isset($state['last_capture_at'])
      ? (int) $state['last_capture_at']
      : null;

    // Der Altvertrag behandelt den Zeitwert 0 wie einen unbekannten Status.
    // Diese Wahrheitstabelle bleibt bei der reinen Auslagerung bewusst gleich.
    $age = $lastSuccess ? $now - $lastSuccess : null;

    return [
      'slug' => $camera->slug(),
      'title' => $camera->title(),
      'interval_seconds' => $interval,
      'last_capture_at' => $lastCapture,
      'last_success_at' => $lastSuccess,
      'age_seconds' => $age,
      'consecutive_failures' => (int) ($state['consecutive_failures'] ?? 0),
      'resets_total' => (int) ($state['resets_total'] ?? 0),
      'last_reset_at' => isset($state['last_reset_at'])
        ? (int) $state['last_reset_at']
        : null,
      'severity' => $this->severity($age, $interval),
    ];
  }

  /** Ordnet das Capture-Alter in dieselben Intervallgrenzen wie bisher ein. */
  private function severity(?int $age, int $interval): string
  {
    if ($age === null) {
      return 'unknown';
    }
    if ($age <= $interval * 2) {
      return 'ok';
    }
    if ($age <= $interval * 3) {
      return 'warn';
    }
    return 'error';
  }

  /** @param array<string,mixed> $payload */
  private function result(int $status, array $payload): AdminRouteResult
  {
    return new AdminRouteResult($status, $payload);
  }
}
