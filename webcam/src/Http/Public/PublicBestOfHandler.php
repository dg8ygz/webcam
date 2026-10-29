<?php
declare(strict_types=1);

namespace Webcam\Http\Public;

use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Beantwortet globale und kamerabezogene Best-Of-Listen.
 *
 * Filter, Pagination und Serializer liegen gemeinsam hier, damit beide Routen
 * denselben klar begrenzten öffentlichen Vertrag nutzen und der API-Router
 * keine Galerie-Details kennen muss.
 */
final class PublicBestOfHandler
{
  public function __construct(private readonly App $app) {}

  /**
   * @param list<string> $segments
   * @param array<string,mixed> $query
   */
  public function handle(string $method, array $segments, array $query): bool
  {
    if ($method === 'GET' && $segments === ['bestof']) {
      $this->globalPage($query);
      return true;
    }

    if (
      $method === 'GET'
      && count($segments) === 3
      && $segments[0] === 'cameras'
      && $segments[2] === 'bestof'
    ) {
      $this->cameraPage($segments[1], $query);
      return true;
    }

    return false;
  }

  /** @param array<string,mixed> $query */
  private function globalPage(array $query): void
  {
    $perPage = $this->boundedInt($query, 'per_page', 48, 1, 200);
    $page = $this->boundedInt($query, 'page', 1, 1, PHP_INT_MAX);
    $year = isset($query['year']) && is_numeric($query['year']) ? (int) $query['year'] : null;
    $cameraFilter = isset($query['camera']) ? trim((string) $query['camera']) : null;
    $cameraFilter = $cameraFilter !== '' ? $cameraFilter : null;
    if ($cameraFilter !== null && $this->app->cameras()->findListed($cameraFilter) === null) {
      \respond(404, ['detail' => 'Camera not found']);
      return;
    }

    $cameras = $this->app->cameras()->listed();
    $result = $this->app->bestOfGallery()->globalPage(
      $cameras,
      $page,
      $perPage,
      $year,
      $cameraFilter
    );

    \respond(200, [
      'items' => array_map([$this, 'serializeGlobalItem'], $result['items'] ?? []),
      'page' => (int) ($result['page'] ?? $page),
      'per_page' => (int) ($result['per_page'] ?? $perPage),
      'total' => (int) ($result['total'] ?? 0),
      'total_pages' => (int) ($result['total_pages'] ?? 1),
      'selected_year' => $year,
      'selected_camera' => $cameraFilter,
      'years' => $result['years'] ?? [],
      'cameras' => array_map(
        static fn(Camera $camera): array => [
          'slug' => $camera->slug(),
          'title' => $camera->title(),
        ],
        $cameras
      ),
    ]);
  }

  /** @param array<string,mixed> $query */
  private function cameraPage(string $slug, array $query): void
  {
    try {
      $camera = $this->app->cameraOrFail($slug);
    } catch (RuntimeException) {
      \respond(404, ['detail' => 'Camera not found']);
      return;
    }

    $perPage = $this->boundedInt($query, 'per_page', 50, 1, 1000);
    $page = $this->boundedInt($query, 'page', 1, 1, PHP_INT_MAX);
    $year = isset($query['year']) && is_numeric($query['year']) ? (int) $query['year'] : null;
    $result = $this->app->bestOfGallery()->page($camera, $page, $perPage, $year);

    \respond(200, [
      'items' => array_map([$this, 'serializeCameraItem'], $result['items'] ?? []),
      'page' => (int) ($result['page'] ?? $page),
      'per_page' => (int) ($result['per_page'] ?? $perPage),
      'total' => (int) ($result['total'] ?? 0),
      'total_pages' => (int) ($result['total_pages'] ?? 1),
      'selected_year' => $year,
      'years' => $this->app->bestOfGallery()->years($camera),
    ]);
  }

  /** @param array<string,mixed> $query */
  private function boundedInt(array $query, string $key, int $default, int $min, int $max): int
  {
    $value = isset($query[$key]) && is_numeric($query[$key])
      ? (int) $query[$key]
      : $default;
    return max($min, min($max, $value));
  }

  private function serializeGlobalItem(array $entry): array
  {
    return [
      'url' => $entry['url'] ?? '',
      'thumb_url' => $entry['thumb_url'] ?? $entry['url'] ?? '',
      'filename' => $entry['filename'] ?? '',
      'modified_at' => isset($entry['slot_ts']) ? date(DATE_ATOM, (int) $entry['slot_ts']) : null,
      'slot_ts' => isset($entry['slot_ts']) ? (int) $entry['slot_ts'] : null,
      'link' => $entry['link'] ?? null,
      'camera_slug' => $entry['camera_slug'] ?? '',
      'camera_title' => $entry['camera_title'] ?? $entry['camera_slug'] ?? '',
    ];
  }

  private function serializeCameraItem(array $entry): array
  {
    return [
      'url' => $entry['url'] ?? '',
      'thumb_url' => $entry['thumb_url'] ?? $entry['url'] ?? '',
      'filename' => $entry['filename'] ?? '',
      'modified_at' => isset($entry['mtime']) ? date(DATE_ATOM, (int) $entry['mtime']) : null,
      'slot_ts' => isset($entry['slot_ts']) ? (int) $entry['slot_ts'] : null,
      'link' => $entry['link'] ?? null,
    ];
  }
}
