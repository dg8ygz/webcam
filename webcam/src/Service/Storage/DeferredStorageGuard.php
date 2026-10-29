<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use RuntimeException;
use Webcam\App;

/**
 * Die Webroot-Ausnahme ist eine Betreiberentscheidung, kein Uploadparameter.
 * Root provisioniert die Policy und eine unveraenderbare Apache-Sperre.
 * Ein vorhandener Ordner allein beweist keinen erreichbaren NAS-Mount.
 */
final class DeferredStorageGuard
{
  private ?array $policy = null;
  private bool $loaded = false;

  public function __construct(private readonly App $app) {}

  private function policy(): ?array
  {
    if ($this->loaded) return $this->policy;
    $file = (string) $this->app->config('deferred_image_guard', '');
    if ($file === '') { $this->loaded = true; return null; }
    $this->assertRootOwnedFile($file);
    $data = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data) || ($data['version'] ?? null) !== 1) throw new RuntimeException('Invalid storage policy');
    foreach (['original', 'backup'] as $kind) {
      if (!is_array($data[$kind] ?? null)) throw new RuntimeException('Incomplete storage policy');
      foreach (['root', 'mountpoint', 'source', 'fstype'] as $field) {
        if (!is_string($data[$kind][$field] ?? null) || $data[$kind][$field] === '') throw new RuntimeException('Incomplete mount policy');
      }
    }
    $conf = $data['apache_conf'] ?? '';
    if (!is_string($conf)) throw new RuntimeException('Invalid Apache guard');
    $this->assertRootOwnedFile($conf);
    if (!hash_equals((string) ($data['apache_sha256'] ?? ''), (string) hash_file('sha256', $conf))) {
      throw new RuntimeException('Apache storage guard changed; new acceptance required');
    }
    $this->policy = $data; $this->loaded = true;
    return $data;
  }

  public static function assertRootOwnedFile(string $path): void
  {
    if (!str_starts_with($path, '/') || is_link($path) || !is_file($path)
      || fileowner($path) !== 0 || (fileperms($path) & 0022) !== 0) {
      throw new RuntimeException('Storage policy and Apache guard must be root-owned and not writable by others');
    }
    // Eine nicht beschreibbare Datei in einem beschreibbaren Verzeichnis
    // waere trotzdem austauschbar.
    for ($dir = dirname($path); ; $dir = dirname($dir)) {
      if (is_link($dir) || fileowner($dir) !== 0 || (fileperms($dir) & 0022) !== 0) {
        throw new RuntimeException('Storage guard parent directory is not protected');
      }
      if ($dir === '/') break;
    }
  }

  public function allowsWebroot(string $root): bool
  {
    $policy = $this->policy();
    if ($policy === null) return false;
    $project = realpath((string) $this->app->config('current_project_root', dirname(__DIR__, 3)));
    if (!$project || $root !== $project . '/orgin' || $root !== $policy['original']['root']) return false;
    $this->checkMount($root, true);
    return true;
  }

  public function checkMount(string $root, bool $original): void
  {
    $policy = $this->policy();
    if ($policy === null) return;
    $expected = $policy[$original ? 'original' : 'backup'];
    if ($root !== $expected['root']) throw new RuntimeException('Storage root differs from accepted policy');
    $info = file_get_contents('/proc/self/mountinfo');
    if (!is_string($info) || !self::matchesMount($info, $root, $expected)) {
      throw new RuntimeException('Expected private storage mount missing or changed');
    }
  }

  /** Pure Parserfunktion, damit fehlende/fremde/RO-Mounts ohne NAS-Ausfall testbar sind. */
  public static function matchesMount(string $info, string $root, array $expected): bool
  {
    $best = null;
    foreach (explode("\n", $info) as $line) {
      $halves = explode(' - ', $line, 2);
      if (count($halves) !== 2) continue;
      $left = explode(' ', $halves[0]); $right = explode(' ', $halves[1]);
      if (count($left) < 6 || count($right) < 3) continue;
      $decode = static fn(string $v): string => preg_replace_callback('/\\\\([0-7]{3})/', static fn($m) => chr(octdec($m[1])), $v);
      $point = $decode($left[4]);
      if ($root !== $point && !str_starts_with($root, rtrim($point, '/') . '/')) continue;
      if ($best !== null && strlen($point) < strlen($best['mountpoint'])) continue;
      $best = ['mountpoint' => $point, 'source' => $decode($right[1]), 'fstype' => $right[0],
        'rw' => in_array('rw', explode(',', $left[5]), true) && in_array('rw', explode(',', $right[2]), true)];
    }
    return $best !== null && $best['rw']
      && $best['mountpoint'] === $expected['mountpoint']
      && $best['source'] === $expected['source'] && $best['fstype'] === $expected['fstype'];
  }
}
