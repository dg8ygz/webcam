<?php
declare(strict_types=1);

namespace Webcam\Service\Dayvideo;

final class DayvideoResult
{
  /**
   * @param array<string,mixed> $payload
   * @param array<string,string> $headers
   */
  public function __construct(
    public readonly int $status,
    public readonly array $payload,
    public readonly array $headers = []
  ) {}
}
