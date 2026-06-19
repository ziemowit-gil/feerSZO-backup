<?php
/**
 * Walidacja kryptograficzna podpisu pliku umowy (AJAX → JSON).
 * Plik wskazywany ścieżką względną (jak w kolumnach plik_umowy / plik_potwierdzenia),
 * rozwiązywaną wyłącznie wewnątrz UPLOAD_DIR (ochrona przed path traversal).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sigcheck.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');

$rel = (string)($_GET['file'] ?? '');
if ($rel === '' || strpos($rel, "\0") !== false || str_contains($rel, '..') || $rel[0] === '/') {
    http_response_code(400); echo json_encode(['error' => 'Nieprawidłowa ścieżka pliku.']); exit;
}

$abs  = UPLOAD_DIR . $rel;
$real = realpath($abs);
$baseDir = realpath(UPLOAD_DIR);
if (!$real || !$baseDir || strncmp($real, $baseDir, strlen($baseDir)) !== 0) {
    http_response_code(404); echo json_encode(['error' => 'Plik nie istnieje.']); exit;
}

$res = ezd_validate_signature($real, basename($rel));
$res['file'] = basename($rel);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
