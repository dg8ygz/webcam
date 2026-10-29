<?php
declare(strict_types=1);

namespace Webcam\Service\Dayvideo;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class DayvideoRequest
{
  public const ALLOWED_FPS = [3, 5, 7, 9];
  public const ALLOWED_WIDTHS = [1200, 1600];
  public const MAX_FRAMES = 300;

  public function __construct(
    public readonly DateTimeImmutable $date,
    public readonly int $fps,
    public readonly int $maxWidth,
    public readonly bool $refresh,
    public readonly bool $async,
    public readonly string $period = 'day',
    public readonly string $clock = '12:00'
  ) {}

  /** @param array<string,mixed> $query */
  public static function fromQuery(array $query, DateTimeZone $timezone): self
  {
    $dateValue = self::scalarString($query['date'] ?? '');
    $date = $dateValue === ''
      ? new DateTimeImmutable('today', $timezone)
      : DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue, $timezone);
    if (!$date instanceof DateTimeImmutable || ($dateValue !== '' && $date->format('Y-m-d') !== $dateValue)) {
      throw new InvalidArgumentException('Invalid date');
    }

    $fps = self::integer($query['fps'] ?? 5, 'fps');
    if (!in_array($fps, self::ALLOWED_FPS, true)) {
      throw new InvalidArgumentException('Unsupported fps');
    }
    $maxWidth = self::integer($query['max_width'] ?? 1200, 'max_width');
    if (!in_array($maxWidth, self::ALLOWED_WIDTHS, true)) {
      throw new InvalidArgumentException('Unsupported max_width');
    }

    $period = self::scalarString($query['period'] ?? 'day');
    $clock = self::scalarString($query['clock'] ?? '12:00');
    if (!in_array($period, \Webcam\Service\Storage\PresentationFrames::MODES, true)
      || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $clock)) {
      throw new InvalidArgumentException('Invalid film selection');
    }
    if ($period !== 'day') {
      $date = \Webcam\Service\Storage\PresentationFrames::date($date->format('Y-m-d'), $timezone);
    }
    if ($period === 'month') { $date = $date->modify('first day of this month'); }
    if ($period === 'year') { $date = $date->setDate((int) $date->format('Y'), 1, 1); }
    if (!in_array($period, ['month', 'year'], true)) { $clock = '12:00'; }

    return new self(
      $date,
      $fps,
      $maxWidth,
      self::boolean($query['refresh'] ?? false),
      self::boolean($query['async'] ?? false),
      $period,
      $clock
    );
  }

  private static function scalarString(mixed $value): string
  {
    if (!is_scalar($value)) {
      throw new InvalidArgumentException('Invalid scalar parameter');
    }
    return trim((string) $value);
  }

  private static function integer(mixed $value, string $name): int
  {
    $value = self::scalarString($value);
    if (!preg_match('/^-?\d+$/', $value)) {
      throw new InvalidArgumentException('Invalid ' . $name);
    }
    return (int) $value;
  }

  private static function boolean(mixed $value): bool
  {
    if ($value === false || $value === null || $value === '') {
      return false;
    }
    $parsed = filter_var(self::scalarString($value), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    if ($parsed === null) {
      throw new InvalidArgumentException('Invalid boolean parameter');
    }
    return $parsed;
  }
}
