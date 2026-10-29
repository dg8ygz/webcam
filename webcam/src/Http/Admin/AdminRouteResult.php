<?php
declare(strict_types=1);

namespace Webcam\Http\Admin;

/**
 * Neutrales Ergebnis einer ausgelagerten Admin-Route.
 *
 * Der Handler entscheidet über Statuscode und JSON-Nutzdaten, schreibt aber
 * selbst keine Header und beendet den PHP-Prozess nicht. Dadurch bleibt
 * `admin/api.php` die einzige Stelle, die eine HTTP-Antwort tatsächlich
 * ausgibt, während sich der komplette Kamera-Routenvertrag separat testen
 * lässt. `null` ist ausschließlich für Antworten ohne Body wie HTTP 204
 * vorgesehen.
 */
final class AdminRouteResult
{
  /** @param array<string,mixed>|null $payload */
  public function __construct(
    public readonly int $status,
    public readonly ?array $payload
  ) {}
}
