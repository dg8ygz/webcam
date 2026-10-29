<?php
declare(strict_types=1);

/**
 * Gemeinsame Dateisystem-Helfer für CLI-Skripte.
 * Ownership-Operationen fangen PHP-Warnungen kontrolliert ab und melden den
 * Fehlertext an den Aufrufer, statt ihn als scheinbaren Erfolg zu verschlucken.
 */

if (!function_exists('ensureWwwDataOwner')) {
  /**
   * Execute filesystem ownership ops without leaking raw PHP warnings.
   */
  function runOwnershipOp(callable $operation, ?string &$error = null): bool
  {
    $error = null;
    set_error_handler(static function (int $severity, string $message) use (&$error): bool {
      $error = $message;
      return true;
    });

    try {
      return (bool) $operation();
    } finally {
      restore_error_handler();
    }
  }

  /**
   * Best-effort chown/chgrp to www-data (no-op if POSIX functions are missing).
   */
  function ensureWwwDataOwner(string $path, bool $verbose = false, string $slug = ''): void
  {
    if (!function_exists('posix_getpwnam')) {
      return;
    }
    $user = posix_getpwnam('www-data');
    if (!$user) {
      return;
    }

    $uid = isset($user['uid']) ? (int) $user['uid'] : null;
    $gid = isset($user['gid']) ? (int) $user['gid'] : null;
    if ($uid === null || $gid === null) {
      return;
    }

    $currentUid = file_exists($path) ? fileowner($path) : false;
    $currentGid = file_exists($path) ? filegroup($path) : false;
    if ($currentUid === $uid && $currentGid === $gid) {
      if ($verbose) {
        $label = $slug !== '' ? "{$slug}: " : '';
        fwrite(STDOUT, "  [INFO] {$label}owner -> www-data\n");
      }
      return;
    }

    $ownerError = null;
    $groupError = null;
    $ownerOk = runOwnershipOp(static fn(): bool => chown($path, $uid), $ownerError);
    $groupOk = runOwnershipOp(static fn(): bool => chgrp($path, $gid), $groupError);
    if (!$ownerOk || !$groupOk) {
      $label = $slug !== '' ? "{$slug}: " : '';
      $details = array_values(array_filter([$ownerError, $groupError], static fn($msg): bool => is_string($msg) && $msg !== ''));
      $suffix = $details ? ' (' . implode('; ', array_unique($details)) . ')' : '';
      fwrite(STDERR, "  [WARN] {$label}owner/group update failed for {$path}{$suffix}\n");
      return;
    }
    if ($verbose) {
      $label = $slug !== '' ? "{$slug}: " : '';
      fwrite(STDOUT, "  [INFO] {$label}owner -> www-data\n");
    }
  }
}

if (!function_exists('normalize_path')) {
  /**
   * Normalize a filesystem path for comparisons (slashes + trailing slash).
   */
  function normalize_path(string $path): string
  {
    $normalized = str_replace('\\', '/', $path);
    if ($normalized === '' || $normalized === '.') {
      return $normalized;
    }
    $normalized = rtrim($normalized, '/');
    if ($normalized === '') {
      return '/';
    }
    return $normalized;
  }
}
