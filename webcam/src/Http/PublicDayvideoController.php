<?php
declare(strict_types=1);

namespace Webcam\Http;

use InvalidArgumentException;
use RuntimeException;
use Webcam\App;
use Webcam\Infrastructure\Http\ClientIpResolver;
use Webcam\Service\Dayvideo\DayvideoRequest;
use Webcam\Service\Dayvideo\DayvideoResult;
use Webcam\Service\Dayvideo\DayvideoService;
use Webcam\Service\Security\PublicRateLimiter;

/** Dünner HTTP-Adapter; Build-, Lock- und Limitlogik bleibt im Service. */
final class PublicDayvideoController
{
  public function __construct(private readonly App $app) {}

  /** @param array<string,mixed> $query */
  public function handle(string $slug, array $query, array $server): void
  {
    try {
      $camera = $this->app->cameraOrFail($slug);
    } catch (RuntimeException) {
      \respond(404, ['detail' => 'Camera not found']);
      return;
    }
    try {
      $request = DayvideoRequest::fromQuery(
        $query,
        new \DateTimeZone((string) $this->app->config('timezone', 'UTC'))
      );
      \Webcam\Service\Storage\PresentationFrames::range($request->date, $request->period, $camera->location());
    } catch (InvalidArgumentException $exception) {
      \respond(400, ['detail' => $exception->getMessage()]);
      return;
    }

    $identity = (new ClientIpResolver($this->trustedProxies()))->resolve($server);
    $responded = false;
    $onBuildStarted = null;
    if ($request->async) {
      $onBuildStarted = function () use (&$responded): void {
        $this->emit(new DayvideoResult(
          202,
          ['status' => 'building', 'retry_after' => 3],
          ['Retry-After' => '3']
        ));
        $responded = true;
        if (function_exists('fastcgi_finish_request')) {
          fastcgi_finish_request();
          return;
        }
        if (ob_get_level() > 0) {
          ob_flush();
        }
        flush();
      };
    }

    $result = (new DayvideoService($this->app, new PublicRateLimiter($this->app)))->handle(
      $camera,
      $request,
      $identity['ip'],
      $onBuildStarted
    );
    if (!$responded) {
      $this->emit($result);
    }
  }

  /** @return list<string> */
  private function trustedProxies(): array
  {
    $configured = $this->app->config('trusted_proxies', []);
    if (!is_array($configured)) {
      return ['127.0.0.1', '::1'];
    }
    $proxies = array_values(array_filter(array_map(
      static fn(mixed $proxy): string => trim((string) $proxy),
      $configured
    )));
    return $proxies ?: ['127.0.0.1', '::1'];
  }

  private function emit(DayvideoResult $result): void
  {
    foreach ($result->headers as $name => $value) {
      header($name . ': ' . $value);
    }
    \respond($result->status, $result->payload);
  }
}
