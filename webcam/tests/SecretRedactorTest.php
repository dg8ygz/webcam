<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Logging\Logger;
use Webcam\Infrastructure\Security\SecretRedactor;

final class SecretRedactorTest extends TestCase
{
  public function testRedactsUrlCredentialsQueriesHeadersAndAssignments(): void
  {
    $input = 'rtsp://camera-user:camera-pass@example.test/live?token=abc123 '
      . 'Authorization: Bearer bearer-value password="plain-value" --api-key cli-value';

    $redacted = SecretRedactor::redact($input);

    foreach (['camera-user', 'camera-pass', 'abc123', 'bearer-value', 'plain-value', 'cli-value'] as $secret) {
      self::assertStringNotContainsString($secret, $redacted);
    }
    self::assertStringContainsString('rtsp://[REDACTED]@example.test/live', $redacted);
    self::assertStringContainsString('[REDACTED]', $redacted);
  }

  public function testRecursivelyRedactsSensitiveContextKeys(): void
  {
    $redacted = SecretRedactor::redactValue([
      'camera' => 'demo',
      'nested' => [
        'password' => 'nested-password',
        'endpoint' => 'https://user:pass@example.test/path?key=query-secret',
      ],
      'authorization' => 'Basic encoded-secret',
    ]);

    self::assertIsArray($redacted);
    self::assertSame('demo', $redacted['camera']);
    self::assertSame('[REDACTED]', $redacted['nested']['password']);
    self::assertSame('[REDACTED]', $redacted['authorization']);
    $encoded = json_encode($redacted, JSON_THROW_ON_ERROR);
    foreach (['nested-password', 'user:pass', 'query-secret', 'encoded-secret'] as $secret) {
      self::assertStringNotContainsString($secret, $encoded);
    }
  }

  public function testLoggerRedactsQuotedEscapedAndConcatenatedSecrets(): void
  {
    $path = TestTempDir::ensure() . '/quoted-secrets-' . bin2hex(random_bytes(4)) . '.log';
    $logger = new Logger($path);
    $inputs = [
      "wget --password 'dummy quoted secret' https://example.invalid",
      "wget --http-password 'dummy quoted secret' https://example.invalid",
      'client --token "double quoted secret" --other public',
      'password="assignment with spaces"',
      "--password 'first'\\''second'",
      '--secret escaped\\ space',
      '--token "escaped\\"quote"',
      '--password "unterminated secret',
    ];
    foreach ($inputs as $input) { $logger->warning('fixture', ['stderr' => $input]); }
    $raw = file_get_contents($path);
    foreach (['dummy', 'double', 'assignment with', 'first', 'second', 'escaped', 'unterminated'] as $secret) {
      self::assertStringNotContainsString($secret, $raw);
    }
    self::assertStringContainsString('--other public', $raw);
    self::assertStringContainsString('[REDACTED]', $raw);
  }

  public function testLoggerAppliesRedactionAtFinalOutputBoundary(): void
  {
    $path = TestTempDir::ensure() . '/secret-redactor-' . bin2hex(random_bytes(4)) . '.log';
    $logger = new Logger($path);
    $logger->error(
      'Failed for rtsp://viewer:message-secret@example.test/live',
      [
        'url' => 'https://api-user:context-secret@example.test/?token=query-secret',
        'password' => 'key-secret',
      ]
    );

    $raw = (string) file_get_contents($path);
    foreach (['viewer', 'message-secret', 'api-user', 'context-secret', 'query-secret', 'key-secret'] as $secret) {
      self::assertStringNotContainsString($secret, $raw);
    }
    self::assertStringContainsString('[REDACTED]', $raw);
  }
}
