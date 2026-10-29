<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Hält die Schutzgrenze vor PHP überprüfbar.
 *
 * Der PHP-Testserver wertet .htaccess nicht aus. Deshalb prüft dieser Vertrag
 * die tatsächlich ausgelieferte Apache-Konfiguration: Beide Endpunkte müssen
 * getrennte Limits besitzen und der Chunk-Weg muss deutlich kleiner bleiben.
 */
final class UploadRequestHardeningConfigTest extends TestCase
{
  public function testUploadEndpointsHaveSeparateApacheBodyLimits(): void
  {
    $configuration = file_get_contents(__DIR__ . '/../.htaccess');
    $this->assertIsString($configuration);

    $legacyLimit = $this->limitFor($configuration, '^upload\\.php$');
    $chunkLimit = $this->limitFor($configuration, '^upload-chunk\\.php$');

    // 25 MiB Bildlimit plus genau 1 MiB Reserve für Multipart-Metadaten.
    $this->assertSame(26 * 1024 * 1024, $legacyLimit);

    // Ein Chunk ist höchstens 1 MiB groß; der komplette HTTP-Body bleibt bei
    // höchstens 2 MiB und damit weit unterhalb des Legacy-Wegs.
    $this->assertSame(2 * 1024 * 1024, $chunkLimit);
    $this->assertLessThan($legacyLimit, $chunkLimit);
  }

  public function testPublicProxyPolicyRejectsUnknownAndOversizeBodiesEarly(): void
  {
    $configuration = file_get_contents(
      __DIR__ . '/../ops/apache-webcam-upload-limits.conf.example'
    );
    $this->assertIsString($configuration);

    // Der gemeinsame öffentliche vHost trägt weitere Anwendungen und läuft
    // mit MPM prefork. Deshalb bleiben globale Timeouts und das damit nicht
    // unterstützte mod_qos ausdrücklich außerhalb dieser Webcam-Datei.
    $this->assertDoesNotMatchRegularExpression(
      '/^\s*RequestReadTimeout\b/m',
      $configuration
    );
    $this->assertDoesNotMatchRegularExpression('/^\s*QS_Loc/m', $configuration);
    $this->assertStringContainsString('RewriteEngine On', $configuration);
    $this->assertSame(1, substr_count($configuration, '[R=411,L]'));
    $this->assertSame(2, substr_count($configuration, '[R=413,L]'));

    // Der gemeinsame Ausdruck deckt die beiden normalen Prefixe, den
    // DEV-Alias /testx und den historischen direkten /include-Weg ab. Die
    // numerische Content-Length wird vor ProxyPass geprüft; unbekannte
    // beziehungsweise chunked Bodys werden nicht als unbegrenzter Nebenweg
    // zugelassen.
    $this->assertStringContainsString(
      '(?:webcam|webcam2|testx)/)?include/upload(?:-chunk)?\\.php$',
      $configuration
    );
    $this->assertStringContainsString(
      "req_novary('Content-Length') !~ m#^[0-9]+$#",
      $configuration
    );
    $this->assertStringContainsString(
      'include/upload-chunk\\.php$# && req_novary(\'Content-Length\') -gt 2097152',
      $configuration
    );
    $this->assertStringContainsString(
      'include/upload\\.php$# && req_novary(\'Content-Length\') -gt 27262976',
      $configuration
    );
    $this->assertStringContainsString(
      'innerhalb des VirtualHost-Kontexts',
      $configuration
    );
    $this->assertDoesNotMatchRegularExpression(
      '/^\s*<LocationMatch\b/m',
      $configuration
    );
  }

  private function limitFor(string $configuration, string $filePattern): int
  {
    $pattern = sprintf(
      '/<FilesMatch\s+"%s">\s*LimitRequestBody\s+([0-9]+)\s*<\/FilesMatch>/s',
      preg_quote($filePattern, '/')
    );
    $this->assertSame(1, preg_match($pattern, $configuration, $matches));
    return (int) ($matches[1] ?? 0);
  }
}
