<?php
/**
 * api/internal/rc_signature.php — stopka e-mail z SZO dla webmaila poczta.feer.org.pl.
 *
 * Wołane przez plugin Roundcube feer_signature (docker/roundcube/mydevil/plugins) przy
 * każdym logowaniu: GET ?email=jan.kowalski@feer.org.pl → {"name": "...", "html": "..."}.
 * Stopka jest ta sama co w module Stopka e-mail (stopkaRenderHtml: dane organizacji,
 * wygląd, stanowisko i telefon z stopka_profile).
 *
 * Zabezpieczenie: nagłówek X-Internal-Key = ustawienie roundcube_signature_key
 * (osobny klucz, kopia w ~/roundcube/secrets/szo-key na serwerze). Bez ustawionego
 * klucza endpoint zawsze odmawia.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/stopka/logic/stopka.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$key      = org_setting('roundcube_signature_key');
$incoming = (string)($_SERVER['HTTP_X_INTERNAL_KEY'] ?? '');
if (strlen($key) < 32 || $incoming === '' || !hash_equals($key, $incoming)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$email = strtolower(trim((string)($_GET['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'email']);
    exit;
}

stopkaMigrate();
$user = db_one("SELECT * FROM users WHERE LOWER(email)=? AND is_active=1 ORDER BY id LIMIT 1", [$email]);
if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

$p = stopkaProfile($user);
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$name = trim($p['first_name'] . ' ' . $p['last_name']);
$html = stopkaRenderHtml(stopkaSettings(), [
    'name'  => $e($name),
    'title' => $e($p['job_title']),
    'phone' => $e($p['phone']),
    'email' => $e($p['email']),
]);

echo json_encode(['name' => $name, 'html' => $html], JSON_UNESCAPED_UNICODE);
