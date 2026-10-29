<?php
declare(strict_types=1);

namespace Webcam\Service\Stream;

/**
 * Besitzt ausschließlich die persistierte IP-Sperrliste.
 *
 * Das Trennen bereits verbundener Clients bleibt Aufgabe der StreamState-
 * Fassade. Dadurch kennt diese Klasse weder MJPEG- noch HLS-Clientformate.
 */
final class StreamBlocklist
{
  public function __construct(
    private readonly StreamRuntimeStore $store,
    private readonly StreamClientIdentityResolver $identity
  ) {}

  /** @return list<array<string,mixed>> */
  public function all(): array
  {
    $items = [];
    foreach (($this->read()['ips'] ?? []) as $ip => $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $items[] = [
        'ip' => (string) $ip,
        'created_at' => (int) ($entry['created_at'] ?? 0),
        'note' => (string) ($entry['note'] ?? ''),
      ];
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string) $a['ip'], (string) $b['ip']));
    return $items;
  }

  public function contains(string $ip): bool
  {
    $ip = $this->identity->normalizeIp($ip);
    return $ip !== '' && isset($this->read()['ips'][$ip]);
  }

  public function block(string $ip, string $note = ''): string
  {
    $ip = $this->identity->requireIp($ip);
    $this->update(static function (array $data) use ($ip, $note): array {
      $data['ips'][$ip] = ['ip' => $ip, 'created_at' => time(), 'note' => trim($note)];
      return $data;
    });
    return $ip;
  }

  public function unblock(string $ip): void
  {
    $ip = $this->identity->requireIp($ip);
    $this->update(static function (array $data) use ($ip): array {
      unset($data['ips'][$ip]);
      return $data;
    });
  }

  /** @return array{ips:array<string,array<string,mixed>>} */
  private function read(): array
  {
    $data = $this->store->readJson($this->path());
    return is_array($data['ips'] ?? null) ? ['ips' => $data['ips']] : ['ips' => []];
  }

  private function update(callable $mutator): void
  {
    $this->store->updateJsonUnderLock($this->path(), static function (array $data) use ($mutator): array {
      $data['ips'] = is_array($data['ips'] ?? null) ? $data['ips'] : [];
      return $mutator($data);
    });
  }

  private function path(): string
  {
    return $this->store->path('stream/blocked_ips.json');
  }
}
