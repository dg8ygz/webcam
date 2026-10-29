<?php
declare(strict_types=1);

namespace Webcam\Domain;

use Webcam\Domain\Enums\CameraMode;
use Webcam\Domain\Enums\CameraStreamMode;
use Webcam\Domain\Enums\CaptureTransport;

/**
 * Unveränderliche, typisierte Sicht auf die Konfiguration einer Kamera.
 *
 * Der Konstruktor normalisiert optionale Felder einmal. Die vielen kleinen
 * Getter darunter geben nur Konfiguration zurück und führen weder Netzwerk-,
 * Dateisystem- noch Datenbankzugriffe aus.
 */
final class Camera
{
  // Internes Attribut im bestehenden Tags-Feld, kein oeffentlicher Filtertag.
  public const TEST_CAMERA_TAG = '__test_camera';

  private readonly string $id;
  private readonly string $slug;
  private readonly string $name;
  private readonly string $title;
  private readonly string $shortTitle;
  private readonly string $description;
  private readonly string $infoHtml;
  private readonly array $location;
  /**
   * @var list<string>
   */
  private readonly array $tags;
  private readonly CameraMode $mode;
  private readonly array $capture;
  private readonly array $storage;
  private readonly array $status;
  private readonly array $stream;
  /**
   * @var list<int>
   */
  private readonly array $resolutions;
  /**
   * @var list<string>
   */
  private readonly array $weatherCommands;
  /**
   * @var array<string,mixed>|null
   */
  private readonly ?array $overlayLogo;
  private readonly ?array $overlayLogoDark;
  /**
   * @var list<array<string,mixed>>
   */
  private readonly array $labels;

  /**
   * @param array{
   *   id:string,
   *   slug:string,
   *   name:string,
   *   title:string,
   *   short_title?:string,
   *   description?:string,
   *   location:array,
   *   tags?:list<string>,
   *   mode:string,
   *   capture:array,
   *   storage:array,
   *   status?:array,
   *   stream?:array,
   *   resolutions?:list<int>,
   *   weather_commands?:list<string>,
   *   overlay_logo?:array,
   *   overlay_logo_dark?:array,
   *   labels?:list<array<string,mixed>>
   * } $config
   */
  public function __construct(array $config)
  {
    $this->id = $config['id'];
    $this->slug = $config['slug'];
    $this->name = $config['name'];
    $this->title = $config['title'];
    $this->shortTitle = $config['short_title'] ?? $config['name'];
    $this->description = $config['description'] ?? '';
    $this->infoHtml = isset($config['info_html']) ? (string) $config['info_html'] : '';
    $location = $config['location'];
    if (!isset($location['map_center_offset'])) {
      $location['map_center_offset'] = 0;
    }
    if (!isset($location['map_center_offset_x'])) {
      $location['map_center_offset_x'] = 0;
    }
    $this->location = $location;
    $this->tags = $config['tags'] ?? [];
    $this->mode = CameraMode::from($config['mode']);
    $this->capture = $config['capture'];
    $this->storage = $config['storage'];
    $this->status = $config['status'] ?? [];
    $this->stream = $config['stream'] ?? ['mode' => CameraStreamMode::None->value];
    $this->resolutions = $config['resolutions'] ?? [];
    $this->weatherCommands = $config['weather_commands'] ?? [];
    $this->overlayLogo = isset($config['overlay_logo']) && is_array($config['overlay_logo'])
      ? $config['overlay_logo']
      : null;
    $this->overlayLogoDark = isset($config['overlay_logo_dark']) && is_array($config['overlay_logo_dark'])
      ? $config['overlay_logo_dark']
      : null;
    $this->labels = isset($config['labels']) && is_array($config['labels'])
      ? $config['labels']
      : [];
  }

  public function id(): string
  {
    return $this->id;
  }

  public function slug(): string
  {
    return $this->slug;
  }

  public function name(): string
  {
    return $this->name;
  }

  public function title(): string
  {
    return $this->title;
  }

  public function shortTitle(): string
  {
    return $this->shortTitle;
  }

  public function description(): string
  {
    return $this->description;
  }

  public function infoHtml(): string
  {
    return $this->infoHtml;
  }

  public function location(): array
  {
    return $this->location;
  }

  /**
   * @return list<string>
   */
  public function tags(): array
  {
    return $this->tags;
  }

  /** Nicht gelistet, aber per bekanntem Slug vollstaendig nutzbar. Kein ACL. */
  public function isTest(): bool
  {
    return in_array(self::TEST_CAMERA_TAG, $this->tags, true);
  }

  public function mode(): CameraMode
  {
    return $this->mode;
  }

  public function capture(): array
  {
    return $this->capture;
  }

  public function storage(): array
  {
    return $this->storage;
  }

  public function storageCurrentDir(): string
  {
    return $this->normaliseStorageDir($this->storage['current_dir'] ?? null, 'Current');
  }

  public function storageArchiveDir(): string
  {
    return $this->normaliseStorageDir($this->storage['archive_dir'] ?? null, 'Archive');
  }

  public function storageBestOfDir(): string
  {
    return $this->normaliseStorageDir($this->storage['best_of_dir'] ?? null, 'BestOf');
  }

  public function status(): array
  {
    return $this->status;
  }

  public function stream(): array
  {
    return $this->stream;
  }

  /**
   * @return list<int>
   */
  public function resolutions(): array
  {
    return $this->resolutions;
  }

  public function captureTransport(): CaptureTransport
  {
    $transport = $this->capture['transport'] ?? CaptureTransport::HttpGet->value;
    return CaptureTransport::tryFrom((string) $transport) ?? CaptureTransport::HttpGet;
  }

  public function streamMode(): CameraStreamMode
  {
    $mode = $this->stream['mode'] ?? CameraStreamMode::None->value;
    return CameraStreamMode::from($mode);
  }

  /**
   * @return list<string>
   */
  public function weatherCommands(): array
  {
    return $this->weatherCommands;
  }

  public function overlayLogo(): ?array
  {
    return $this->overlayLogo;
  }

  public function overlayLogoDark(): ?array
  {
    return $this->overlayLogoDark;
  }

  /**
   * @return list<array<string,mixed>>
   */
  public function labels(): array
  {
    return $this->labels;
  }

  private function normaliseStorageDir(?string $value, string $fallback): string
  {
    if (!is_string($value) || trim($value) === '') {
      $value = $fallback;
    }
    $value = trim($value);
    $value = trim($value, '/');
    if ($value === '') {
      $value = trim($fallback, '/');
    }
    return $value === '' ? $fallback : $value;
  }
}
