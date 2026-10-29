<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

use Webcam\Infrastructure\Filesystem\PathResolver;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;

/** Short-lived authenticated Pi reports, not configuration or capture records.
 * A vanished Pi may not indefinitely hide a watchdog failure. HLS, conversely,
 * fails closed if a previously enabled privacy heartbeat becomes stale.
 */
final class CameraPrivacyState
{
  public function __construct(private readonly PathResolver $paths) {}
  /** Same installation hash as the USB relay; never share DEV/PROD reports. */
  public static function forPaths(PathResolver $paths): self
  {
    $id=substr(hash('sha256',$paths->statePath()),0,16);
    return new self(new PathResolver(null,null,'/run/webcam-usb-stream/'.$id,null));
  }
  private function path(string $slug, string $channel): string
  {
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$slug) || !in_array($channel,['command','video'],true)) { throw new \InvalidArgumentException('Invalid privacy state'); }
    return $this->paths->statePath('camera-privacy/'.$channel.'-'.$slug.'.json');
  }
  /** Validate bounded Base64 JSON from an authenticated device, then publish
   * atomically. Video expires quickly; command polling needs a longer lease.
   * An omitted header must not remove a previously enabled restriction.
   */
  public function receive(string $slug, string $channel, string $encoded, ?int $now=null): void
  {
    if ($encoded==='') { return; } // Legacy helper: do not clear an existing restriction.
    $now ??= time();
    $raw=strlen($encoded)<=512 ? base64_decode($encoded,true) : false;
    $v=$raw!==false ? json_decode($raw,true,4) : null;
    if (!is_array($v) || count($v)!==4 || !is_bool($v['enabled'] ?? null) || !is_bool($v['allowed'] ?? null)
      || !is_bool($v['suppressed'] ?? null) || !is_int($v['until'] ?? null)
      || $v['until']<$now-60 || $v['until']>$now+86500) { throw new \InvalidArgumentException('Invalid privacy heartbeat'); }
    $v['expires']=$now+($channel==='video' ? 35 : 900);
    $path=$this->path($slug,$channel); $dir=dirname($path);
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) { throw new \RuntimeException('Privacy runtime directory unavailable'); }
    if (!AtomicFileWriter::write($path,json_encode($v,JSON_THROW_ON_ERROR),null,0600)) { throw new \RuntimeException('Privacy heartbeat not saved'); }
  }
  /** Absent state preserves legacy operation; corrupt state throws rather
   * than silently granting video or hiding a failed watchdog decision.
   */
  private function read(string $slug, string $channel): ?array
  {
    $path=$this->path($slug,$channel);
    if (!file_exists($path)) { return null; }
    $raw=file_get_contents($path,false,null,0,1025);
    $v=is_string($raw) && strlen($raw)<=1024 ? json_decode($raw,true) : null;
    if (!is_array($v) || !isset($v['enabled'],$v['allowed'],$v['until'],$v['expires'],$v['suppressed'])) { throw new \RuntimeException('Privacy state unreadable'); }
    return $v;
  }
  /** A closed time window OR stale enabled heartbeat blocks every HLS request. */
  public function videoBlocked(string $slug, ?int $now=null): bool
  {
    $v=$this->read($slug,'video'); $now ??= time();
    return $v!==null && $v['enabled'] && (!$v['allowed'] || $now >= $v['until'] || $now >= $v['expires']);
  }
  /** Suppress resets only during a freshly reported intentional pause. After
   * 900 seconds without renewal the regular watchdog owns the decision again.
   */
  public function pauseFresh(string $slug, ?int $now=null): bool
  {
    $v=$this->read($slug,'command'); $now ??= time();
    return $v!==null && $now < $v['expires'] && ($v['suppressed'] || ($v['enabled'] && $now >= $v['until']));
  }
}
