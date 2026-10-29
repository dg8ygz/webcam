#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Korrigiert Owner, Gruppe und Modi ausschließlich auf beschreibbaren Laufzeitpfaden.
 *
 * Ziel-Owner kommt aus Env/App-Konfiguration oder nachweisbarer Host-Ownership;
 * `nobody:nogroup` wird nicht geraten. Archivdateien bleiben read-only und sind
 * nicht Teil der rekursiven Korrektur. Die gesondert behandelten DB-Backups
 * werden nur geprüft, wenn ihr vorhandenes Verzeichnis wirklich beschreibbar
 * ist; das verhindert erwartbare chmod-Fehler auf einem read-only Archivmount.
 */

function logLine(string $line): void
{
  global $logFile, $verbose;
  $ts = date('Y-m-d H:i:s');
  if (file_put_contents($logFile, "[{$ts}] {$line}\n", FILE_APPEND) === false) {
    fwrite(STDERR, "WARN unable to write log line\n");
  }
  if ($verbose) {
    echo "[{$ts}] {$line}\n";
  }
}

/**
 * Resolve the desired writable owner/group.
 *
 * Explicit env vars win. Otherwise use the app config values. Only if those are
 * blank fall back to detected path ownership.
 *
 * @return array{0:string,1:string}
 */
function resolveTargetOwnership($app): array
{
  $fallbackUser = 'www-data';
  $fallbackGroup = 'www-data';

  $envUser = trim((string) (getenv('WEBCAM_STORAGE_USER') ?: ''));
  $envGroup = trim((string) (getenv('WEBCAM_STORAGE_GROUP') ?: ''));
  $configuredUser = trim((string) $app->config('storage_owner_user', ''));
  $configuredGroup = trim((string) $app->config('storage_owner_group', ''));

  $detected = [];
  if ($envUser === '' && $envGroup === '' && ($configuredUser === '' || $configuredGroup === '')) {
    $detected = detectOwnershipFromPaths([
      (string) $app->config('camera_config_db', ''),
      $app->paths()->cameraDbPath(),
      $app->paths()->cachePath(),
    ]);
  }

  $ownerName = $envUser !== '' ? $envUser : ($configuredUser !== '' ? $configuredUser : ($detected['user'] ?? $fallbackUser));
  $groupName = $envGroup !== '' ? $envGroup : ($configuredGroup !== '' ? $configuredGroup : ($detected['group'] ?? $fallbackGroup));

  return [$ownerName, $groupName];
}

/**
 * @param list<string> $paths
 * @return array{user?:string,group?:string,path?:string}
 */
function detectOwnershipFromPaths(array $paths): array
{
  foreach ($paths as $path) {
    if ($path === '' || !file_exists($path)) {
      continue;
    }

    $uid = fileowner($path);
    $gid = filegroup($path);
    $userInfo = is_int($uid) ? posix_getpwuid($uid) : false;
    $groupInfo = is_int($gid) ? posix_getgrgid($gid) : false;
    $user = is_array($userInfo) ? (string) ($userInfo['name'] ?? '') : '';
    $group = is_array($groupInfo) ? (string) ($groupInfo['name'] ?? '') : '';

    if ($user !== '' || $group !== '') {
      return [
        'user' => $user,
        'group' => $group,
        'path' => $path,
      ];
    }
  }

  return [];
}

/**
 * @param list<string> $paths
 */
function ensurePaths(array $paths, int $uid, int $gid, int $dirMode, int $fileMode): array
{
  $fixed = ['owners' => 0, 'modes' => 0, 'missing' => 0, 'errors' => 0];
  foreach ($paths as $path) {
    if (!file_exists($path)) {
      $fixed['missing']++;
      logLine("WARN missing {$path}");
      continue;
    }

    // Ein CIFS-Mount mit `nounix`, `file_mode` und `dir_mode` erzwingt Owner
    // und Modi bereits mountweit. Ein rekursiver chmod/chown-Lauf kann dort
    // nichts dauerhaft ändern und würde lediglich Millionen Netzdateien
    // anfassen. Die Wurzel wird zur Sichtkontrolle einmal geprüft, der teure
    // Unterbaum aber vollständig ausgelassen.
    if (isMountManagedPermissionTree($path)) {
      $fixed = applyFix(new SplFileInfo($path), $uid, $gid, $dirMode, $fileMode, $fixed);
      logLine("INFO mount-managed tree skipped {$path}");
      continue;
    }

    $items = [new SplFileInfo($path)];
    if (is_dir($path)) {
      $items[] = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
      );
    }

    foreach ($items as $entry) {
      if ($entry instanceof RecursiveIteratorIterator) {
        foreach ($entry as $item) {
          $fixed = applyFix($item, $uid, $gid, $dirMode, $fileMode, $fixed);
        }
      } else {
        $fixed = applyFix($entry, $uid, $gid, $dirMode, $fileMode, $fixed);
      }
    }
  }
  return $fixed;
}

/**
 * Erkennt CIFS-Pfade, deren sichtbare Rechte vollständig aus Mountoptionen
 * stammen. Der längste passende Mountpoint gewinnt, damit ein verschachtelter
 * Archivmount nicht fälschlich wie sein Elternmount behandelt wird.
 *
 * @internal Der optionale Text macht die Erkennung ohne echte Mounts testbar.
 */
function isMountManagedPermissionTree(string $path, ?string $mountInfo = null): bool
{
  if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR) {
    return false;
  }
  $mountInfo ??= @file_get_contents('/proc/self/mountinfo');
  if (!is_string($mountInfo) || $mountInfo === '') {
    return false;
  }

  $bestMount = '';
  $bestType = '';
  $bestOptions = '';
  foreach (preg_split('/\R/', $mountInfo) ?: [] as $line) {
    if ($line === '' || !str_contains($line, ' - ')) {
      continue;
    }
    [$before, $after] = explode(' - ', $line, 2);
    $beforeFields = preg_split('/\s+/', trim($before)) ?: [];
    $afterFields = preg_split('/\s+/', trim($after)) ?: [];
    if (count($beforeFields) < 6 || count($afterFields) < 3) {
      continue;
    }

    $mountPoint = strtr((string) $beforeFields[4], [
      '\\040' => ' ',
      '\\011' => "\t",
      '\\012' => "\n",
      '\\134' => '\\',
    ]);
    $isWithinMount = $path === $mountPoint
      || ($mountPoint === DIRECTORY_SEPARATOR)
      || str_starts_with($path, rtrim($mountPoint, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    if (!$isWithinMount || strlen($mountPoint) < strlen($bestMount)) {
      continue;
    }

    $bestMount = $mountPoint;
    $bestType = strtolower((string) $afterFields[0]);
    $bestOptions = implode(',', [
      (string) ($beforeFields[5] ?? ''),
      (string) ($afterFields[2] ?? ''),
    ]);
  }

  return $bestType === 'cifs'
    && str_contains($bestOptions, 'nounix')
    && str_contains($bestOptions, 'file_mode=')
    && str_contains($bestOptions, 'dir_mode=');
}

/**
 * Korrigiert nur die ausdrücklich genannten Einträge, ohne Verzeichnisse zu
 * durchlaufen.
 *
 * Das wird für den gemeinsamen Elternordner der lokalen Datenbanken benötigt:
 * `/var/lib/webcam` soll nicht für fremde lokale Benutzer durchsuchbar sein,
 * aber ein frei konfigurierter DB-Elternpfad darf niemals versehentlich einen
 * kompletten System- oder Projektbaum in Besitz von `www-data` überführen.
 *
 * @param list<string> $paths
 */
function ensureExactPaths(array $paths, int $uid, int $gid, int $dirMode, int $fileMode): array
{
  $fixed = ['owners' => 0, 'modes' => 0, 'missing' => 0, 'errors' => 0];
  foreach ($paths as $path) {
    if (!file_exists($path)) {
      $fixed['missing']++;
      logLine("WARN missing {$path}");
      continue;
    }
    $fixed = applyFix(new SplFileInfo($path), $uid, $gid, $dirMode, $fileMode, $fixed);
  }
  return $fixed;
}

/**
 * Addiert die Zähler mehrerer Schutzklassen zu einer gemeinsamen Logausgabe.
 *
 * @param list<array{owners:int,modes:int,missing:int,errors:int}> $results
 * @return array{owners:int,modes:int,missing:int,errors:int}
 */
function mergePermissionResults(array $results): array
{
  $merged = ['owners' => 0, 'modes' => 0, 'missing' => 0, 'errors' => 0];
  foreach ($results as $result) {
    foreach ($merged as $key => $unused) {
      $merged[$key] += (int) ($result[$key] ?? 0);
    }
  }
  return $merged;
}

function applyFix(SplFileInfo $item, int $uid, int $gid, int $dirMode, int $fileMode, array $fixed): array
{
  $targetMode = $item->isDir() ? $dirMode : $fileMode;
  $abs = $item->getPathname();

  // Normale Archivdateien bleiben grundsätzlich unangetastet. DB-Backups
  // erreichen diese Funktion nur, wenn buildPermissionFixPaths() ihr
  // Verzeichnis zuvor als beschreibbar eingestuft hat.
  if (str_contains($abs, '/storage/archive/') && !str_contains($abs, '/db_backups/')) {
    return $fixed;
  }

  $needChown = $item->getOwner() !== $uid || $item->getGroup() !== $gid;
  if ($needChown && (!chown($abs, $uid) || !chgrp($abs, $gid))) {
    $fixed['errors']++;
    logLine("ERROR chown/chgrp failed for {$abs}");
  } elseif ($needChown) {
    $fixed['owners']++;
    logLine("FIX owner {$abs} -> {$uid}:{$gid}");
  }

  $currentMode = fileperms($abs) & 07777;
  if ($currentMode !== $targetMode) {
    if (!chmod($abs, $targetMode)) {
      $fixed['errors']++;
      logLine("ERROR chmod failed for {$abs}");
    } else {
      $fixed['modes']++;
      logLine("FIX mode {$abs} {$currentMode} -> {$targetMode}");
    }
  }

  return $fixed;
}

/**
 * Entscheidet, ob ein Kamera-Verzeichnis mit DB-Backups geprüft werden darf.
 *
 * Bei einem vorhandenen Verzeichnis ist `is_writable()` maßgeblich. Die
 * Prüfung berücksichtigt dadurch auch einen read-only Mount und nicht nur die
 * sichtbaren Unix-Modusbits. Ein fehlender Pfad wird ebenfalls nicht gescannt:
 * Ohne vorhandenes Verzeichnis gibt es dort weder Rechte noch Inhalte zu
 * korrigieren.
 *
 * Die optionalen Prüffunktionen dienen ausschließlich dazu, beide Fälle ohne
 * einen echten read-only Testmount zuverlässig testen zu können.
 */
function shouldScanDbBackupDirectory(
  string $path,
  ?callable $isDirectory = null,
  ?callable $isWritable = null
): bool {
  $isDirectory ??= static fn (string $candidate): bool => is_dir($candidate);
  $isWritable ??= static fn (string $candidate): bool => is_writable($candidate);

  if (!$isDirectory($path)) {
    return false;
  }

  return $isWritable($path);
}

/**
 * @return list<string>
 */
function buildPermissionFixPaths($app): array
{
  $paths = [];

  // general writable roots
  $paths[] = $app->paths()->statePath();
  $paths[] = $app->paths()->logPath();

  // captcha state (likes captcha)
  $paths[] = $app->paths()->statePath('captcha');

  // Öffentliche Laufzeitdaten: Bilder, Status und Logs. Diese Pfade behalten
  // die historisch kompatiblen Gruppen-/Leserechte; lokale SQLite-Dateien
  // werden weiter unten bewusst einer strengeren Schutzklasse zugeordnet.
  $paths[] = $app->paths()->cachePath();
  $paths[] = $app->paths()->cachePath('current');
  $paths[] = $app->paths()->storagePath('current');

  $projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

  // per camera paths
  foreach ($app->cameras()->all() as $camera) {
    $slug = $camera->slug();
    $paths[] = $app->paths()->cachePath($slug);
    $overlayPath = $app->paths()->cachePath($slug . '/overlays');
    if (file_exists($overlayPath)) {
      $paths[] = $overlayPath;
    }
    $bestOfPath = $app->bestOfDirectory($camera);
    if (file_exists($bestOfPath)) {
      $paths[] = $bestOfPath;
    }
    // PROD bindet dasselbe Archiv beschreibbar ein, DEV dagegen bewusst nur
    // lesbar. Nur auf einem beschreibbaren Verzeichnis sind chown/chmod hier
    // sinnvoll; andernfalls würde jeder Nachtlauf dieselben Fehler erzeugen.
    $dbBackupPath = $app->paths()->storagePath('archive/' . $slug . '/db_backups');
    if (shouldScanDbBackupDirectory($dbBackupPath)) {
      $paths[] = $dbBackupPath;
    }
    $paths[] = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'current';
  }

  return array_values(array_unique($paths));
}

/**
 * Liefert lokale Datenbankpfade, die niemals world-readable sein dürfen.
 *
 * Die Config-DB enthält Upload-Keys und Reset-Sequenzen. Kamera-DBs und der
 * Public-Limiter sind zwar weniger privilegiert, enthalten aber interne
 * Betriebsdaten. Alle drei verwenden deshalb `2770/0660` statt der Modi für
 * öffentlich ausgelieferte Bilder und Logs.
 *
 * @return list<string>
 */
function buildPrivatePermissionPaths($app): array
{
  $paths = [];
  $configDb = trim((string) $app->config('camera_config_db', ''));
  if ($configDb !== '') {
    $paths[] = $configDb;
  }

  $cameraDbPath = trim((string) $app->paths()->cameraDbPath());
  if ($cameraDbPath !== '') {
    $paths[] = $cameraDbPath;
  }

  $rateDbOverride = getenv('WEBCAM_PUBLIC_RATE_DB');
  $rateDbPath = is_string($rateDbOverride) && trim($rateDbOverride) !== ''
    ? trim($rateDbOverride)
    : '/var/lib/webcam/ratelimit_public.sqlite';
  if (is_file($rateDbPath)) {
    $paths[] = $rateDbPath;
  }

  return array_values(array_unique($paths));
}

/**
 * Liefert nur einen eindeutig gemeinsamen DB-Elternordner.
 *
 * Eine frei konfigurierte Config-DB kann beispielsweise in `database/`
 * liegen. Dessen kompletter Baum darf nicht rekursiv umgechowned werden. Der
 * Elternordner wird deshalb nur aufgenommen, wenn Config-DB und Kamera-DB
 * denselben dedizierten Root besitzen; `ensureExactPaths()` ändert danach nur
 * diesen einen Ordner.
 *
 * @return list<string>
 */
function buildPrivatePermissionRootPaths($app): array
{
  $configDb = trim((string) $app->config('camera_config_db', ''));
  $cameraDbPath = trim((string) $app->paths()->cameraDbPath());
  if ($configDb === '' || $cameraDbPath === '') {
    return [];
  }

  $configRoot = rtrim(dirname($configDb), DIRECTORY_SEPARATOR);
  $cameraDbRoot = rtrim(dirname($cameraDbPath), DIRECTORY_SEPARATOR);
  if ($configRoot === '' || $configRoot !== $cameraDbRoot) {
    return [];
  }

  return [$configRoot];
}

function runFixPermissions(array $argv): int
{
  global $logFile, $verbose;

  // hard fail early if required extensions missing
  if (!function_exists('posix_getpwnam')) {
    fwrite(STDERR, "POSIX extension required\n");
    return 1;
  }

  $app = require __DIR__ . '/../bootstrap.php';
  $verbose = in_array('--verbose', $argv, true);

  [$ownerName, $groupName] = resolveTargetOwnership($app);
  // Der öffentliche Laufzeitbaum liegt auf CIFS. Der Mount bildet das
  // setgid-Bit nicht verlässlich ab und würde `2775` deshalb bei jedem Lauf
  // erneut als Abweichung melden. `0775` ist dort der tatsächlich
  // darstellbare Sollmodus; der Dienst schreibt ohnehin immer als www-data.
  $runtimeDirMode = 0775; // rwxrwxr-x, CIFS-kompatibel
  $runtimeFileMode = 0664;
  $privateDirMode = 02770; // setgid, rwxrwx---
  $privateFileMode = 0660;

  $uid = posix_getpwnam($ownerName)['uid'] ?? null;
  $gid = posix_getgrnam($groupName)['gid'] ?? null;
  if ($uid === null || $gid === null) {
    fwrite(STDERR, "Owner/group {$ownerName}:{$groupName} not found\n");
    return 1;
  }

  $logFile = $app->paths()->logPath('permission_fix.log');
  if (!is_dir(dirname($logFile))) {
    if (!mkdir(dirname($logFile), 0775, true) && !is_dir(dirname($logFile))) {
      fwrite(STDERR, "WARN unable to create log directory: " . dirname($logFile) . "\n");
    }
  }

  // Erst die historisch öffentlich lesbaren Bild-/Statuspfade korrigieren.
  // Danach werden lokale Datenbanken und ihr dedizierter Elternroot enger
  // gesetzt. Die Reihenfolge verhindert, dass ein überlappender Pfad am Ende
  // versehentlich wieder world-readable bleibt.
  $result = mergePermissionResults([
    ensurePaths(
      buildPermissionFixPaths($app),
      $uid,
      $gid,
      $runtimeDirMode,
      $runtimeFileMode
    ),
    ensurePaths(
      buildPrivatePermissionPaths($app),
      $uid,
      $gid,
      $privateDirMode,
      $privateFileMode
    ),
    ensureExactPaths(
      buildPrivatePermissionRootPaths($app),
      $uid,
      $gid,
      $privateDirMode,
      $privateFileMode
    ),
  ]);

  $summary = sprintf(
    "done owners=%d modes=%d missing=%d errors=%d",
    $result['owners'],
    $result['modes'],
    $result['missing'],
    $result['errors']
  );

  logLine($summary);

  if ($result['errors'] > 0) {
    fwrite(STDERR, "permission fix completed with errors\n");
    return 1;
  }

  echo $summary . PHP_EOL;
  return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  exit(runFixPermissions($argv));
}
