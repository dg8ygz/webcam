<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Support/helpers.php';

/**
 * Sicherheitsproben für das administrativ gepflegte Kamera-HTML.
 *
 * Die sichtbare Info-Box gibt das Ergebnis des Sanitizers absichtlich als HTML
 * aus. Diese Tests stellen deshalb sicher, dass aktive Inhalte auch dann nicht
 * überleben, wenn sie in unbekannten oder mehrfach verschachtelten Elementen
 * versteckt werden.
 */
final class AdminHtmlSanitizerTest extends TestCase
{
  #[DataProvider('unsafeHtmlProvider')]
  public function testRemovesActiveContentFromNestedWrappers(string $html): void
  {
    $sanitized = sanitize_admin_html($html);
    $normalized = strtolower($sanitized);

    foreach (['<script', '<svg', '<math', 'onerror', 'onload', 'onclick', 'javascript:', 'vbscript:', 'data:'] as $unsafeFragment) {
      self::assertStringNotContainsString($unsafeFragment, $normalized, $sanitized);
    }
  }

  /** @return iterable<string,array{string}> */
  public static function unsafeHtmlProvider(): iterable
  {
    yield 'script in unknown svg wrapper' => [
      '<svg><script>alert(1)</script></svg>',
    ];
    yield 'event handler in unknown wrapper' => [
      '<x-unknown><img src="image.jpg" onerror="alert(1)"></x-unknown>',
    ];
    yield 'multiple unknown wrappers and math markup' => [
      '<outer><math><inner><script>alert(1)</script><img src="ok.jpg" onclick="alert(2)"></inner></math></outer>',
    ];
    yield 'unsafe link unwraps only sanitized children' => [
      '<a href="javascript:alert(1)"><svg><script>alert(2)</script></svg><strong>sichtbar</strong></a>',
    ];
    yield 'encoded line break cannot hide javascript scheme' => [
      '<wrapper><a href="java&#10;script:alert(1)">Link</a></wrapper>',
    ];
    yield 'data image is removed inside unknown wrapper' => [
      '<wrapper><img src="data:image/svg+xml,&lt;svg onload=alert(1)&gt;"></wrapper>',
    ];
  }

  public function testKeepsDocumentedSafeMarkup(): void
  {
    $sanitized = sanitize_admin_html(
      '<div class="camera-info"><strong>Hinweis</strong> <a href="https://example.invalid/info">Details</a></div>'
    );

    self::assertStringContainsString('<div class="camera-info">', $sanitized);
    self::assertStringContainsString('<strong>Hinweis</strong>', $sanitized);
    self::assertStringContainsString('href="https://example.invalid/info"', $sanitized);
    self::assertStringContainsString('rel="noopener noreferrer"', $sanitized);
    self::assertStringContainsString('target="_blank"', $sanitized);
  }
}
