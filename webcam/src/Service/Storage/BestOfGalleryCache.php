<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Verwaltet ausschließlich die abgeleiteten JSON-Caches der Best-Of-Galerie.
 *
 * Fingerprints binden einen Cache an Verzeichnis beziehungsweise Kamera-DB.
 * Ungültige oder nicht schreibbare Caches ändern nie das Ergebnis: Der Aufrufer
 * berechnet die Liste dann einfach erneut.
 */
final class BestOfGalleryCache
{
    private const LIST_VERSION = 3;
    private const TOP_LIKED_VERSION = 1;

    public function __construct(private readonly App $app)
    {
    }

    /** @return list<string>|null */
    public function loadBasenames(Camera $camera, string $fingerprint, ?int $limit = null): ?array
    {
        $payload = $this->loadPayload($this->listFile($camera, $limit, false), self::LIST_VERSION, $fingerprint);
        if (!is_array($payload['basenames'] ?? null)) {
            return null;
        }
        return array_values(array_filter(
            $payload['basenames'],
            static fn(mixed $value): bool => is_string($value) && $value !== ''
        ));
    }

    /** @param list<string> $basenames */
    public function writeBasenames(Camera $camera, string $fingerprint, array $basenames, ?int $limit = null): void
    {
        $this->writePayload($this->listFile($camera, $limit), [
            'version' => self::LIST_VERSION,
            'fingerprint' => $fingerprint,
            'generated_at' => date(DATE_ATOM),
            'basenames' => $basenames,
        ]);
    }

    /** @return list<array<string,mixed>>|null */
    public function loadTopLiked(Camera $camera, int $limit): ?array
    {
        $payload = $this->loadPayload(
            $this->topLikedFile($camera, $limit, false),
            self::TOP_LIKED_VERSION,
            $this->databaseFingerprint($camera, $limit)
        );
        return is_array($payload['rows'] ?? null) ? $payload['rows'] : null;
    }

    /** @param list<array<string,mixed>> $rows */
    public function writeTopLiked(Camera $camera, int $limit, array $rows): void
    {
        $this->writePayload($this->topLikedFile($camera, $limit), [
            'version' => self::TOP_LIKED_VERSION,
            'fingerprint' => $this->databaseFingerprint($camera, $limit),
            'generated_at' => date(DATE_ATOM),
            'rows' => $rows,
        ]);
    }

    /** Der Verzeichnis-Fingerprint invalidiert Sortierlisten bei Bestandsänderung. */
    public function directoryFingerprint(string $directory): string
    {
        clearstatcache(true, $directory);
        $stat = @stat($directory) ?: [];
        // Whole-second stat values are not stable while that second is open.
        // A unique token prevents reuse/publication of a stale same-second list.
        $volatile = max((int) ($stat['mtime'] ?? 0), (int) ($stat['ctime'] ?? 0)) >= time();
        return $this->fingerprint([
            'volatile' => $volatile ? bin2hex(random_bytes(16)) : '',
            'dir' => str_replace('\\', '/', $directory),
            'mtime' => (int) ($stat['mtime'] ?? 0),
            'ctime' => (int) ($stat['ctime'] ?? 0),
            'size' => (int) ($stat['size'] ?? 0),
        ]);
    }

    private function databaseFingerprint(Camera $camera, int $limit): string
    {
        $path = rtrim($this->app->paths()->cameraDbPath($camera->slug()), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'db.sqlite';
        clearstatcache(true, $path);
        $stat = @stat($path) ?: [];
        return $this->fingerprint([
            'camera' => $camera->slug(),
            'db_path' => str_replace('\\', '/', $path),
            'mtime' => (int) ($stat['mtime'] ?? 0),
            'ctime' => (int) ($stat['ctime'] ?? 0),
            'size' => (int) ($stat['size'] ?? 0),
            'limit' => $limit,
        ]);
    }

    private function loadPayload(string $file, int $version, string $fingerprint): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        $payload = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($payload)
            || ($payload['version'] ?? null) !== $version
            || ($payload['fingerprint'] ?? null) !== $fingerprint
            || !is_string($payload['generated_at'] ?? null)
            || ($generated = strtotime($payload['generated_at'])) === false
            || $generated > time() || time() - $generated >= 60
        ) {
            return null;
        }
        return $payload;
    }

    private function writePayload(string $file, array $payload): void
    {
        $directory = dirname($file);
        if ((!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory))
            || !is_writable($directory)
        ) {
            return;
        }
        \webcam_atomic_write_json($file, $payload, JSON_UNESCAPED_SLASHES);
    }

    private function listFile(Camera $camera, ?int $limit, bool $ensure = true): string
    {
        return $this->file($camera, $limit === null ? 'list.json' : 'list-' . max(1, $limit) . '.json', $ensure);
    }

    private function topLikedFile(Camera $camera, int $limit, bool $ensure = true): string
    {
        return $this->file($camera, 'top-liked-' . max(1, $limit) . '.json', $ensure);
    }

    private function file(Camera $camera, string $name, bool $ensure): string
    {
        $directory = $this->app->paths()->statePath('bestof-gallery/' . $camera->slug());
        if ($ensure && !is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    }

    private function fingerprint(array $data): string
    {
        return sha1(json_encode($data, JSON_UNESCAPED_SLASHES) ?: serialize($data));
    }
}
