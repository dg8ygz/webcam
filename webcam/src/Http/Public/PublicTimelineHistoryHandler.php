<?php
declare(strict_types=1);

namespace Webcam\Http\Public;

use Webcam\App;

/**
 * Leichter Dispatcher für die drei öffentlichen Zeitrouten einer Kamera.
 *
 * Die früher hier vermischte Auswahl- und Serialisierungslogik liegt nun in
 * fachlich getrennten Handlern. Diese Fassade prüft nur noch das gemeinsame
 * URL-Muster und leitet die Anfrage weiter. Der Aufrufer in api/public.php
 * behält dadurch unverändert genau einen Ansprechpartner.
 */
final class PublicTimelineHistoryHandler
{
  private readonly PublicTimelapseHandler $timelapse;
  private readonly PublicTimelineHandler $timeline;
  private readonly PublicHistoryHandler $history;

  public function __construct(App $app)
  {
    $this->timelapse = new PublicTimelapseHandler($app);
    $this->timeline = new PublicTimelineHandler($app);
    $this->history = new PublicHistoryHandler($app);
  }

  /**
   * @param list<string> $segments
   * @param array<string,mixed> $query
   */
  public function handle(string $method, array $segments, array $query): bool
  {
    if ($method !== 'GET' || count($segments) !== 3 || $segments[0] !== 'cameras') {
      return false;
    }

    $handler = match ($segments[2]) {
      'timelapse' => $this->timelapse,
      'timeline' => $this->timeline,
      'history' => $this->history,
      default => null,
    };
    if ($handler === null) {
      return false;
    }

    $handler->handle($segments[1], $query);
    return true;
  }
}
