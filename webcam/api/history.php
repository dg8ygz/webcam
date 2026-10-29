<?php
declare(strict_types=1);

/**
 * Dünner Kompatibilitäts-Endpunkt für alte History-URLs.
 *
 * Er prüft nur den Slug, setzt die erwarteten Query-Werte und übergibt danach an
 * api/public.php. Abfrage, Fehlerbehandlung und Ausgabe werden nicht dupliziert.
 */
header('Content-Type: application/json');

$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';
if ($slug === '') {
  http_response_code(400);
  echo json_encode(['detail' => 'Missing slug']);
  exit;
}
if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
  http_response_code(400);
  echo json_encode(['detail' => 'Invalid slug']);
  exit;
}

$_GET['resource'] = 'history';
$_GET['slug'] = $slug;

require __DIR__ . '/public.php';
