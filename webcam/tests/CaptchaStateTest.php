<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../include/captcha_state.php';

final class CaptchaStateTest extends TestCase
{
  private string $directory;

  protected function setUp(): void
  {
    $this->directory = TestTempDir::ensure() . '/captcha-' . bin2hex(random_bytes(6));
    mkdir($this->directory, 0775, true);
  }

  public function testChallengeStoresOnlyHashAndCanBeUsedOnce(): void
  {
    $id = bin2hex(random_bytes(16));
    $path = captcha_state_store($this->directory, $id, 'ABCD', 1000);
    $payload = json_decode((string) file_get_contents($path), true);

    self::assertArrayHasKey('code_hash', $payload);
    self::assertArrayNotHasKey('code', $payload);
    self::assertStringNotContainsString('abcd', strtolower((string) file_get_contents($path)));
    self::assertTrue(captcha_state_validate($this->directory, $id, 'abcd', 1100));
    self::assertFalse(captcha_state_validate($this->directory, $id, 'abcd', 1100));
  }

  public function testWrongOrExpiredAnswerConsumesChallenge(): void
  {
    $wrongId = bin2hex(random_bytes(16));
    captcha_state_store($this->directory, $wrongId, 'WXYZ', 1000);
    self::assertFalse(captcha_state_validate($this->directory, $wrongId, 'AAAA', 1100));
    self::assertFalse(captcha_state_validate($this->directory, $wrongId, 'WXYZ', 1100));

    $expiredId = bin2hex(random_bytes(16));
    captcha_state_store($this->directory, $expiredId, '2345', 1000);
    self::assertFalse(captcha_state_validate($this->directory, $expiredId, '2345', 1601, 600));
  }
}
