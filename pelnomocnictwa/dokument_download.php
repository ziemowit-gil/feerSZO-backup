<?php
/** Pobranie przesłanego skanu podpisanego dokumentu pełnomocnictwa. */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/pelnomocnictwa.php';

require_login();
require_module_enabled('pelnomocnictwa_enabled', 'Rejestr pełnomocnictw');
if (!can_edit()) { http_response_code(403); die('Brak uprawnień.'); }

$id  = (int)($_GET['id'] ?? 0);
$row = $id ? pelnomocnictwo_get($id) : null;
if (!$row || !$row['dokument_plik']) { http_response_code(404); die('Nie znaleziono pliku.'); }

$path = dirname(__DIR__) . '/uploads/pelnomocnictwa/' . $row['dokument_plik'];
if (!file_exists($path)) { http_response_code(404); die('Plik nie istnieje.'); }

$mime = mime_content_type($path) ?: 'application/octet-stream';
$name = $row['dokument_oryginal_nazwa'] ?: basename($row['dokument_plik']);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
