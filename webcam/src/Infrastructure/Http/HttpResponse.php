<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Http;

/** Unveränderliches Ergebnis eines internen HTTP-Aufrufs. */
final class HttpResponse
{
  /**
   * @param list<string> $headers
   */
  public function __construct(
    public readonly int $status,
    public readonly string $body,
    public readonly array $headers = [],
  ) {}

  public function isSuccessful(): bool
  {
    return $this->status >= 200 && $this->status < 300;
  }
}
