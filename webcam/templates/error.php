<?php
declare(strict_types=1);

// Kleine Fehleransicht: Nachricht escaped ausgeben und einen zur Mount-Tiefe
// passenden relativen Link zurück zur Übersicht erzeugen.

/** @var string $message */

$basePathValue = trim((string) $app->config('base_url', ''), '/');
$relativePrefix = webcam_document_relative_prefix($basePathValue);
?>
<section class="error">
  <h1>Ups!</h1>
  <p><?= htmlspecialchars($message) ?></p>
  <p><a class="button" href="<?= htmlspecialchars($relativePrefix) ?>">Zurück zur Übersicht</a></p>
</section>
