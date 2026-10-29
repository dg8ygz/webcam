<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Security;

/**
 * Entfernt Zugangsdaten aus Log-, Status- und Diagnosewerten.
 *
 * Konfigurationsquellen bleiben unveraendert: Die Redaction greift erst an
 * Ausgabe- und Runtime-Grenzen, damit maskierte Werte niemals zurueck in die
 * Live-Konfiguration geschrieben werden.
 */
final class SecretRedactor
{
  private const REDACTED = '[REDACTED]';

  /** @var list<string> */
  private const SENSITIVE_KEYS = [
    'authorization',
    'cookie',
    'set-cookie',
    'password',
    'passwd',
    'pass',
    'token',
    'access_token',
    'access-token',
    'secret',
    'api_key',
    'api-key',
    'apikey',
    'upload_key',
    'upload-key',
  ];

  public static function redact(string $value): string
  {
    if ($value === '') {
      return '';
    }

    $redacted = preg_replace(
      '#\b((?:https?|rtsps?|ftp)://)(?:[^/@\s]+)@#i',
      '$1' . self::REDACTED . '@',
      $value
    ) ?? $value;
    $redacted = preg_replace(
      '/([?&](?:key|token|secret|password|passwd|pass|api[_-]?key|access[_-]?token|upload[_-]?key)=)[^&#\s]*/i',
      '$1' . self::REDACTED,
      $redacted
    ) ?? $redacted;
    $redacted = preg_replace(
      '/(Authorization\s*:\s*)(?:Basic|Bearer)\s+[^\s,;]+/i',
      '$1' . self::REDACTED,
      $redacted
    ) ?? $redacted;
    // Consume the whole shell value, including quoted/escaped and concatenated
    // pieces. An unterminated quote is redacted through the end of the input.
    $valuePattern = <<<'REGEX'
(?:"(?:\\.|[^"\\])*(?:"|$)|'[^']*(?:'|$)|\\.|[^\s"'\\,;&])+
REGEX;
    $redacted = preg_replace(
      '~(--(?:(?:http|ftp)[-_])?(?:password|passwd|pass|token|secret|api[-_]?key|access[-_]?token|upload[-_]?key)(?:=|\s+))' . $valuePattern . '~i',
      '$1' . self::REDACTED,
      $redacted
    ) ?? $redacted;
    $redacted = preg_replace(
      '~((?:"|\')?(?:password|passwd|pass|token|secret|api[-_]?key|access[-_]?token|upload[-_]?key)(?:"|\')?\s*[:=]\s*)' . $valuePattern . '~i',
      '$1' . self::REDACTED,
      $redacted
    ) ?? $redacted;

    return $redacted;
  }

  public static function redactValue(mixed $value, ?string $key = null): mixed
  {
    if ($key !== null && self::isSensitiveKey($key)) {
      return self::REDACTED;
    }
    if (is_string($value)) {
      return self::redact($value);
    }
    if (!is_array($value)) {
      return $value;
    }

    $redacted = [];
    foreach ($value as $childKey => $childValue) {
      $redacted[$childKey] = self::redactValue(
        $childValue,
        is_string($childKey) ? $childKey : null
      );
    }
    return $redacted;
  }

  private static function isSensitiveKey(string $key): bool
  {
    return in_array(strtolower(trim($key)), self::SENSITIVE_KEYS, true);
  }
}
