<?php
declare(strict_types=1);

/**
 * Gemeinsame kompakte Kopfzeile fuer Startseite, globales Best Of, Status und Wetter.
 *
 * @var string $heroText
 * @var string $relativePrefix
 */
?>
<section class="overview-hero">
  <div class="overview-hero__row">
    <h1><a class="overview-home" href="<?= htmlspecialchars($relativePrefix, ENT_QUOTES) ?>" title="Zur Startseite">Webcam-Übersicht</a></h1>
    <p class="lead"><?= htmlspecialchars($heroText) ?></p>
    <div class="hero-actions">
      <a class="btn ghost" href="<?= htmlspecialchars($relativePrefix . 'bestof', ENT_QUOTES) ?>">Globales Best-Of</a>
      <a class="btn ghost" href="<?= htmlspecialchars($relativePrefix . 'status/', ENT_QUOTES) ?>">Status</a>
      <a class="btn ghost" href="<?= htmlspecialchars($relativePrefix . 'explore.php', ENT_QUOTES) ?>">Mehrkamera</a>
      <a class="btn ghost" href="<?= htmlspecialchars($relativePrefix . 'weather', ENT_QUOTES) ?>">Wetter</a>
    </div>
  </div>
</section>
