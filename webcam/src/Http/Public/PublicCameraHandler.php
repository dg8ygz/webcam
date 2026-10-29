<?php
declare(strict_types=1);

namespace Webcam\Http\Public;

use DateTimeImmutable;
use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Liefert die öffentliche Kameraübersicht und das Detail einer Kamera.
 *
 * Der Handler kennt ausschließlich die beiden Kamera-Stammdatenrouten. Dadurch
 * bleibt im API-Einstieg keine Presenter-, Pagination- oder Statusformatierung
 * zurück. `handle()` meldet mit seinem booleschen Rückgabewert, ob die Route zu
 * diesem Handler gehörte; die eigentliche HTTP-Antwort erfolgt wie bisher über
 * den zentralen `respond()`-Helfer.
 */
final class PublicCameraHandler
{
  public function __construct(private readonly App $app) {}

  /**
   * @param list<string> $segments
   * @param array<string,mixed> $query
   */
  public function handle(string $method, array $segments, array $query): bool
  {
    if ($method === 'GET' && $segments === ['cameras']) {
      $this->overview($query);
      return true;
    }

    if ($method === 'GET' && count($segments) === 2 && $segments[0] === 'cameras') {
      $this->detail($segments[1]);
      return true;
    }

    return false;
  }

  /** @param array<string,mixed> $query */
  private function overview(array $query): void
  {
    $all = $this->app->cameraPresenter()->overviewList();
    [$pagination, $slice] = $this->paginate($all, $query);

    \respond(200, [
      'cameras' => array_map([$this, 'serializeOverview'], $slice),
      ...$pagination,
    ]);
  }

  private function detail(string $slug): void
  {
    try {
      $camera = $this->app->cameraOrFail($slug);
    } catch (RuntimeException) {
      \respond(404, ['detail' => 'Camera not found']);
      return;
    }

    $overview = $this->serializeOverview(
      $this->app->cameraPresenter()->overviewData($camera)
    );
    \respond(200, $this->buildDetail($camera, $overview));
  }

  /**
   * Begrenzt die vom Presenter bereits geladene Liste. Querywerte werden erst
   * numerisch geprüft und anschließend auf das öffentliche Maximum geklemmt.
   *
   * @param list<mixed> $items
   * @param array<string,mixed> $query
   * @return array{0:array{page:int,per_page:int,total:int,total_pages:int},1:list<mixed>}
   */
  private function paginate(array $items, array $query): array
  {
    $total = count($items);
    $perPage = isset($query['per_page']) && is_numeric($query['per_page'])
      ? (int) $query['per_page']
      : 20;
    $perPage = max(1, min(200, $perPage));
    $page = isset($query['page']) && is_numeric($query['page'])
      ? (int) $query['page']
      : 1;
    $page = max(1, $page);

    return [
      [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => max(1, (int) ceil($total / $perPage)),
      ],
      array_slice($items, ($page - 1) * $perPage, $perPage),
    ];
  }

  /** Übersetzt Presenter-Daten in den stabilen öffentlichen API-Vertrag. */
  private function serializeOverview(array $camera): array
  {
    return [
      'slug' => (string) ($camera['slug'] ?? ''),
      'title' => (string) ($camera['title'] ?? ''),
      'short_title' => (string) ($camera['short_title'] ?? ''),
      'description' => (string) ($camera['description'] ?? ''),
      'mode' => (string) ($camera['mode'] ?? ''),
      'resolutions' => array_values($camera['resolutions'] ?? []),
      'resolutions_urls' => $camera['resolutions_urls'] ?? [],
      'current_image_url' => (string) ($camera['current_image_url'] ?? ''),
      'full_image_url' => (string) ($camera['full_image_url'] ?? ''),
      'last_capture_at' => isset($camera['last_capture']) && $camera['last_capture'] instanceof DateTimeImmutable
        ? $camera['last_capture']->format(DATE_ATOM)
        : null,
      'age_seconds' => $camera['age_seconds'] ?? null,
      'interval_seconds' => $camera['interval_seconds'] ?? null,
      'weather_quality' => $camera['state']['weather_quality'] ?? null,
      'duplicate_active' => !empty($camera['state']['duplicate_active']),
      'location' => $camera['location'] ?? [],
      'storage' => [
        'current_dir' => $camera['storage_current_dir'] ?? '',
        'archive_dir' => $camera['storage_archive_dir'] ?? '',
        'best_of_dir' => $camera['storage_best_of_dir'] ?? '',
      ],
    ];
  }

  /** Verknüpft Stammdaten mit Status, Infotext, Best-Of und Archivjahren. */
  private function buildDetail(Camera $camera, array $overview): array
  {
    $bestOf = array_map(
      static fn(array $entry): array => [
        'url' => $entry['url'] ?? '',
        'thumb_url' => $entry['thumb_url'] ?? $entry['url'] ?? '',
        'filename' => $entry['filename'] ?? '',
        'modified_at' => isset($entry['mtime']) ? date(DATE_ATOM, (int) $entry['mtime']) : null,
        'slot_ts' => isset($entry['slot_ts']) ? (int) $entry['slot_ts'] : null,
        'link' => $entry['link'] ?? null,
      ],
      $this->app->bestOfGallery()->list($camera, 50)
    );

    $infoHtml = $camera->infoHtml();
    if ($infoHtml !== '') {
      $infoHtml = \sanitize_admin_html($infoHtml);
    }

    return [
      'camera' => $overview,
      'info_html' => $infoHtml,
      'best_of' => $bestOf,
      'top_liked' => $this->app->bestOfGallery()->topLiked($camera, 20),
      'archive_years' => $this->app->archiveBrowser()->listYears($camera),
      'status' => $this->serializeStatus($this->app->statusService()->state($camera)),
    ];
  }

  /** Filtert internen Runtime-State auf ausdrücklich öffentliche Felder. */
  private function serializeStatus(array $state): array
  {
    $timestamp = static fn(mixed $value): ?string => $value
      ? date(DATE_ATOM, (int) $value)
      : null;

    return [
      'last_capture_at' => $timestamp($state['last_capture_at'] ?? null),
      'last_success_at' => $timestamp($state['last_success_at'] ?? null),
      'last_failure_at' => $timestamp($state['last_failure_at'] ?? null),
      'last_duplicate_at' => $timestamp($state['last_duplicate_at'] ?? null),
      'last_reset_at' => $timestamp($state['last_reset_at'] ?? null),
      'last_failure_note' => $state['last_failure_note'] ?? null,
      'last_capture_note' => $state['last_capture_note'] ?? null,
      'last_duplicate_note' => $state['last_duplicate_note'] ?? null,
      'duplicate_active' => !empty($state['duplicate_active']),
      'consecutive_failures' => (int) ($state['consecutive_failures'] ?? 0),
      'resets_total' => (int) ($state['resets_total'] ?? 0),
      'last_weather_at' => $timestamp($state['last_weather_at'] ?? null),
      'last_weather_data' => $state['last_weather_data'] ?? null,
      'weather_quality' => $state['weather_quality'] ?? null,
    ];
  }
}
