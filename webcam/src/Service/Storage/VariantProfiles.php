<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

/**
 * Zentrale Namen und Zielbreiten der stabilen Bildvarianten.
 *
 * Die Suffixe sind Teil von Dateinamen, API und Frontend und dürfen deshalb nicht
 * beiläufig umbenannt werden.
 */
final class VariantProfiles
{
  /**
   * Resized variants keyed by suffix with their target width in pixels.
   *
   * These values mirror the historic Foto-Webcam profile (sm/la/lm).
   *
   * @var array<string,int>
   */
  public const CANONICAL_WIDTHS = [
    'sm' => 114,
    'la' => 816,
    'lm' => 1200,
  ];

  /**
   * Variants that should be exact copies of the original capture.
   *
   * @var list<string>
   */
  public const ORIGINAL_CLONES = ['hu', 'full'];

  private function __construct()
  {
  }
}
