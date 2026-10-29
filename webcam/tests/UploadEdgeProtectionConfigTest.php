<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Schuetzt die getrennten Rate- und Verbindungslimits des oeffentlichen Proxys.
 *
 * Die eigentliche Aktivierung benoetigt Root-Rechte auf dem Proxy. Dieser
 * Vertrag verhindert bereits im Repository, dass Pfade zusammenfallen,
 * Grenzwerte vertauscht werden oder die eigene nftables-Tabelle versehentlich
 * andere Firewalltabellen veraendert.
 */
final class UploadEdgeProtectionConfigTest extends TestCase
{
  public function testFail2BanKeepsLegacyAndChunkBudgetsSeparate(): void
  {
    $legacyFilter = $this->read('ops/fail2ban-webcam-upload-legacy-rate.conf.example');
    $chunkFilter = $this->read('ops/fail2ban-webcam-upload-chunk-rate.conf.example');
    $jails = $this->read('ops/fail2ban-webcam-upload-rate.local.example');

    $this->assertStringContainsString('include/upload\\.php', $legacyFilter);
    $this->assertStringNotContainsString('upload-chunk', $legacyFilter);
    $this->assertStringContainsString('include/upload-chunk\\.php', $chunkFilter);

    // Beide Filter muessen jeden real vorhandenen Prefix und den direkten
    // /include-Altweg mit demselben dokumentierten Ausdruck abdecken.
    $this->assertStringContainsString('(?:webcam|webcam2|testx)/)?include/', $legacyFilter);
    $this->assertStringContainsString('(?:webcam|webcam2|testx)/)?include/', $chunkFilter);

    $this->assertSame(12, $this->jailInteger($jails, 'webcam-upload-legacy-rate', 'maxretry'));
    $this->assertSame(120, $this->jailInteger($jails, 'webcam-upload-chunk-rate', 'maxretry'));
    $this->assertSame(60, $this->jailInteger($jails, 'webcam-upload-legacy-rate', 'findtime'));
    $this->assertSame(600, $this->jailInteger($jails, 'webcam-upload-chunk-rate', 'bantime'));
    $this->assertSame(2, substr_count($jails, 'nftables-multiport['));
  }

  public function testFail2BanFiltersDescribeOnlyPostUploads(): void
  {
    $legacyFilter = $this->read('ops/fail2ban-webcam-upload-legacy-rate.conf.example');
    $chunkFilter = $this->read('ops/fail2ban-webcam-upload-chunk-rate.conf.example');

    // Fail2Ban ersetzt <HOST> intern durch seinen IP-Ausdruck und entfernt
    // den erkannten Zeitstempel. Fuer den PHP-Vertrag bilden wir genau diese
    // beiden Schritte mit einer IPv4-Gruppe und einem flexiblen Mittelteil ab.
    $legacyRegex = $this->extractFailRegex($legacyFilter);
    $chunkRegex = $this->extractFailRegex($chunkFilter);

    foreach (['webcam', 'webcam2', 'testx'] as $prefix) {
      $this->assertSame(
        1,
        preg_match($legacyRegex, "192.0.2.10 - -  \"POST /$prefix/include/upload.php HTTP/1.1\" 403 12")
      );
      $this->assertSame(
        1,
        preg_match($chunkRegex, "192.0.2.10 - -  \"POST /$prefix/include/upload-chunk.php?action=chunk HTTP/1.1\" 200 42")
      );
    }

    $this->assertSame(1, preg_match($legacyRegex, '192.0.2.10 - -  "POST /include/upload.php HTTP/2.0" 200 -'));
    $this->assertSame(1, preg_match($chunkRegex, '192.0.2.10 - -  "POST /include/upload-chunk.php HTTP/2" 413 4802'));
    $this->assertSame(0, preg_match($legacyRegex, '192.0.2.10 - -  "GET /webcam/include/upload.php HTTP/1.1" 200 12'));
    $this->assertSame(0, preg_match($legacyRegex, '192.0.2.10 - -  "POST /unrelated/upload.php HTTP/1.1" 200 12'));
    $this->assertSame(0, preg_match($chunkRegex, '192.0.2.10 - -  "POST /webcam/include/upload.php HTTP/1.1" 200 12'));
  }

  public function testNftablesGuardHasPerIpRateAndConnectionCaps(): void
  {
    $rules = $this->read('ops/nftables-webcam-proxy-connection-guard.nft.example');
    $service = $this->read('ops/webcam-proxy-connection-guard.service');
    $installer = $this->read('ops/install-webcam-upload-protection.sh');

    $this->assertStringContainsString('table inet webcam_proxy_guard', $rules);
    $this->assertStringContainsString('tcp dport { 80, 443 }', $rules);
    $this->assertSame(2, substr_count($rules, 'limit rate over 30/second burst 60 packets'));
    $this->assertSame(2, substr_count($rules, 'ct count over 64'));
    $this->assertStringContainsString('meta nfproto ipv4', $rules);
    $this->assertStringContainsString('meta nfproto ipv6', $rules);
    $this->assertStringContainsString('policy accept', $rules);
    $this->assertStringNotContainsString('flush ruleset', $rules);

    // Der Service darf ausschliesslich seine eigene Tabelle ersetzen. So
    // bleiben Fail2Ban und alle bereits vorhandenen Firewallregeln erhalten.
    $this->assertStringContainsString(
      'nft delete table inet webcam_proxy_guard',
      $service
    );
    $this->assertStringContainsString(
      '/etc/nftables.d/webcam-proxy-connection-guard.nft',
      $service
    );
    $this->assertStringNotContainsString('flush ruleset', $service);

    // Der Root-Installer muss vorhandene Zielnamen ablehnen und bei einem
    // Teilfehler ausschließlich seine eigene Tabelle entfernen. Apache und
    // die übrige nftables-Konfiguration dürfen nicht neu gestartet/geflusht
    // werden.
    $this->assertStringContainsString('Ziel ist bereits vorhanden', $installer);
    $this->assertStringContainsString('nft delete table inet webcam_proxy_guard', $installer);
    $this->assertStringContainsString('fail2ban-client -t', $installer);
    $this->assertStringNotContainsString('flush ruleset', $installer);
    $this->assertStringNotContainsString('restart apache2', $installer);
  }

  private function read(string $relativePath): string
  {
    $contents = file_get_contents(__DIR__ . '/../' . $relativePath);
    $this->assertIsString($contents);
    return $contents;
  }

  private function jailInteger(string $configuration, string $jail, string $key): int
  {
    $pattern = sprintf(
      '/^\[%s\]\s*(.*?)(?=^\[|\z)/ms',
      preg_quote($jail, '/')
    );
    $this->assertSame(1, preg_match($pattern, $configuration, $section));

    $valuePattern = sprintf('/^%s\s*=\s*([0-9]+)\s*$/m', preg_quote($key, '/'));
    $this->assertSame(1, preg_match($valuePattern, (string) ($section[1] ?? ''), $value));
    return (int) ($value[1] ?? 0);
  }

  private function extractFailRegex(string $configuration): string
  {
    $this->assertSame(1, preg_match('/^failregex\s*=\s*(.+)$/m', $configuration, $match));
    $expression = str_replace('<HOST>', '(?:[0-9]{1,3}\\.){3}[0-9]{1,3}', (string) ($match[1] ?? ''));
    return '~' . str_replace('~', '\\~', $expression) . '~';
  }
}
