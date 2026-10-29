<?php
declare(strict_types=1);

/*
 * Gemeinsamer, nicht oeffentlich ausgelieferter Teil der Admin-Seite.
 *
 * Die Webseite bearbeitet niemals selbst /etc-Dateien. Sie uebergibt wenige,
 * fest definierte Felder per Standardeingabe an camera-rpi-admin. Dadurch
 * entsteht aus einem Servernamen oder einer URL kein Shellbefehl.
 */

const CAMERA_RPI_ADMIN_BRIDGE = '/usr/local/sbin/camera-rpi-admin';
const CAMERA_RPI_ADMIN_SESSION_PATH = '/run/camera-rpi-admin/sessions';

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Robots-Tag: noindex, nofollow');

if (empty($_SERVER['REMOTE_USER'])) {
    http_response_code(403);
    exit('Die Admin-Seite muss durch die konfigurierte HTTP-Anmeldung aufgerufen werden.');
}

session_name('camera_rpi_admin');
if (!is_dir(CAMERA_RPI_ADMIN_SESSION_PATH) || !is_writable(CAMERA_RPI_ADMIN_SESSION_PATH)) {
    http_response_code(503);
    exit('Das flüchtige, read-only-kompatible Sitzungsverzeichnis fehlt.');
}
session_save_path(CAMERA_RPI_ADMIN_SESSION_PATH);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (($_SESSION['remote_user'] ?? null) !== $_SERVER['REMOTE_USER']) {
    session_regenerate_id(true);
    $_SESSION = ['remote_user' => (string)$_SERVER['REMOTE_USER']];
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post_text(string $name, int $maximum): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value) || strlen($value) > $maximum || str_contains($value, "\n") || str_contains($value, "\r")) {
        throw new RuntimeException("Feld $name ist ungültig.");
    }
    return trim($value);
}

// Browser validation is convenience only. Validate identifiers again before
// invoking sudo, using the same ASCII grammar as the root bridge. Never echo
// rejected input (or other POST fields, which may contain a key) in the error.
function post_identifier(string $name, int $maximum, string $label): string
{
    $message = "$label: 1 bis $maximum Zeichen, nur Kleinbuchstaben (a–z), Zahlen und Bindestriche; Anfang und Ende müssen Buchstabe oder Zahl sein.";
    try {
        $value = post_text($name, $maximum);
    } catch (RuntimeException $error) {
        throw new RuntimeException($message);
    }
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $value)) {
        throw new RuntimeException($message);
    }
    return $value;
}

// One physical camera has one local focus panel. Its source profile owns the
// existing asynchronous worker queue, not the server card currently being edited.
// Without a pool profile, only a single configured target is unambiguous.
function camera_focus_target(array $servers): ?array
{
    $sources = [];
    foreach ($servers as $server) {
        $source = (string)($server['capture_source_server'] ?? '');
        if ($source !== '' && $source !== '-') { $sources[$source] = true; }
    }
    if (count($sources) === 1) {
        $source = (string)array_key_first($sources);
        foreach ($servers as $server) {
            if (($server['server_id'] ?? '') === $source) { return $server; }
        }
        return null;
    }
    return $sources === [] && count($servers) === 1 ? $servers[0] : null;
}

function bridge_call(array $fields): array
{
    $lines = [];
    foreach ($fields as $name => $value) {
        if (!preg_match('/^[a-z_]+$/D', (string)$name)) {
            throw new RuntimeException('Interner Feldname ist ungültig.');
        }
        $value = (string)$value;
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException('Mehrzeilige Eingaben sind nicht erlaubt.');
        }
        $lines[] = $name . '=' . $value;
    }
    $input = implode("\n", $lines) . "\n";

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    // Array-Aufruf: PHP startet keine Shell und interpretiert keine Zeichen
    // aus den Formularfeldern als Optionen oder Befehle.
    $process = proc_open(
        ['/usr/bin/sudo', '-n', CAMERA_RPI_ADMIN_BRIDGE],
        $descriptors,
        $pipes,
        null,
        ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Admin-Helfer konnte nicht gestartet werden.');
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    // Das Protokoll kann einen neuen Key enthalten. Nach dem Schreiben wird
    // seine lokale Kopie nicht mehr benötigt.
    $input = '';
    $lines = [];
    if (array_key_exists('key', $fields)) {
        $fields['key'] = '';
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $message = trim((string)$stderr);
        if (str_starts_with($message, 'ERROR' . "\t")) {
            $message = substr($message, 6);
        }
        throw new RuntimeException($message !== '' ? $message : 'Admin-Helfer meldet einen Fehler.');
    }
    return ['stdout' => (string)$stdout, 'stderr' => (string)$stderr];
}

function server_list(): array
{
    $response = bridge_call(['action' => 'list']);
    $rows = preg_split('/\r?\n/', trim($response['stdout']));
    if (!$rows || count($rows) < 1) {
        return [];
    }
    $header = str_getcsv(array_shift($rows), "\t");
    $servers = [];
    foreach ($rows as $row) {
        if ($row === '') {
            continue;
        }
        $values = str_getcsv($row, "\t");
        if (count($values) !== count($header)) {
            continue;
        }
        $servers[] = array_combine($header, $values);
    }
    return $servers;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function redirect_home(): never
{
    header('Location: ./', true, 303);
    exit;
}
