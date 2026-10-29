<?php
declare(strict_types=1);

namespace Webcam\Service\Admin;

use InvalidArgumentException;

/**
 * Validiert die öffentlich sichtbaren Bildbeschriftungen einer Kamera.
 *
 * Pflichtkoordinaten, Farb-Allowlist, Gültigkeitsfenster und Linkprotokolle
 * werden an einer Stelle geprüft. Leere Editorzeilen werden entfernt;
 * unvollständige, aber begonnene Zeilen lösen dagegen einen sichtbaren Fehler aus.
 */
final class CameraLabelNormalizer
{
  private const COLORS = ['blue', 'teal', 'green', 'yellow', 'orange', 'red', 'purple', 'gray'];
  private const TEXT_TONES = ['auto', 'light', 'dark'];

  /** @return list<array<string,mixed>> */
  public function normalize(mixed $labels): array
  {
    if ($labels === null) {
      return [];
    }
    if (!is_array($labels)) {
      throw new InvalidArgumentException('labels must be an array');
    }
    $result = [];
    foreach ($labels as $index => $label) {
      if (!is_array($label)) {
        continue;
      }
      $normalized = $this->normalizeLabel($label, (int) $index + 1);
      if ($normalized !== null) {
        $result[] = $normalized;
      }
    }
    return $result;
  }

  /** @param array<string,mixed> $label @return array<string,mixed>|null */
  private function normalizeLabel(array $label, int $number): ?array
  {
    $text = isset($label['text']) ? trim((string) $label['text']) : '';
    $x = $label['x'] ?? null;
    $y = $label['y'] ?? null;
    $href = isset($label['href']) ? trim((string) $label['href']) : '';
    $validFrom = isset($label['valid_from']) ? trim((string) $label['valid_from']) : '';
    $validTo = isset($label['valid_to']) ? trim((string) $label['valid_to']) : '';
    $colorInput = isset($label['color']) ? strtolower(trim((string) $label['color'])) : '';
    $toneInput = isset($label['text_tone']) ? strtolower(trim((string) $label['text_tone'])) : '';
    $color = $this->normalizeColor($colorInput);
    $tone = in_array($toneInput, self::TEXT_TONES, true) ? $toneInput : '';

    $hasAny = $text !== '' || $x !== null || $y !== null || $href !== ''
      || $validFrom !== '' || $validTo !== '' || $color !== '' || $tone !== ''
      || isset($label['type']) || isset($label['resolution']);
    if (!$hasAny) {
      return null;
    }
    if ($text === '' || !is_numeric($x) || !is_numeric($y)) {
      throw new InvalidArgumentException("Label $number benötigt x, y und text");
    }
    $this->assertHref($href, $number);
    if ($colorInput !== '' && $color === '') {
      throw new InvalidArgumentException("Label $number Farbe ist nicht erlaubt");
    }
    if ($toneInput !== '' && $tone === '') {
      throw new InvalidArgumentException("Label $number Schriftfarbe ist nicht erlaubt");
    }

    $type = isset($label['type']) && is_numeric($label['type']) ? (int) $label['type'] : 0;
    $type = $type >= 0 && $type <= 4 ? $type : 0;
    $entry = [
      'x' => (float) $x,
      'y' => (float) $y,
      'text' => $text,
      'href' => $href,
      'type' => $type,
      'resolution' => isset($label['resolution']) && is_numeric($label['resolution'])
        ? max(0, (int) $label['resolution'])
        : 0,
      'valid_from' => $validFrom,
      'valid_to' => $validTo,
    ];
    if ($color !== '') {
      $entry['color'] = $color;
    }
    if ($tone !== '' && $tone !== 'auto') {
      $entry['text_tone'] = $tone;
    }
    if (isset($label['id']) && is_numeric($label['id'])) {
      $entry['id'] = (int) $label['id'];
    }
    return $entry;
  }

  private function normalizeColor(string $color): string
  {
    if ($color === '' || in_array($color, self::COLORS, true)) {
      return $color;
    }
    return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color) ? $color : '';
  }

  private function assertHref(string $href, int $number): void
  {
    if ($href !== '' && preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) && !preg_match('#^https?://#i', $href)) {
      throw new InvalidArgumentException("Label $number enthält ein nicht unterstütztes Link-Protokoll");
    }
    if ($href !== '' && preg_match('#^(?:javascript|data):#i', $href)) {
      throw new InvalidArgumentException("Label $number Link ist nicht erlaubt");
    }
  }
}
