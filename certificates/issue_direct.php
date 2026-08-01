<?php
/**
 * certificates/issue_direct.php
 * Admin shortcut: tworzy wniosek "na miejscu" i przekierowuje do issue.php.
 * Omija krok składania wniosku przez wolontariusza — wyłącznie dla adminów.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/certificates.php';

require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die('Metoda niedozwolona.'); }
csrf_check();

$type = preg_replace('/[^a-z]/', '', $_POST['type'] ?? '');
$cid  = (int)($_POST['id'] ?? 0);
if (!$type || !$cid) { http_response_code(400); die('Brak parametrów.'); }

$TABLE = table_for_type($type);
$row   = db_one("SELECT * FROM {$TABLE} WHERE id=?", [$cid]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }

// Sprawdź istniejący wniosek ze statusem 'oczekuje'
$existing = db_one(
    "SELECT id FROM certificate_requests WHERE contract_type=? AND contract_id=? AND status='oczekuje' ORDER BY id DESC LIMIT 1",
    [$type, $cid]
);
if ($existing) {
    header('Location: ' . APP_URL . '/certificates/issue.php?id=' . $existing['id']);
    exit;
}

// Utwórz nowy wniosek w imieniu admina
$u    = current_user();
$name = $row['imie_nazwisko'] ?? $u['name'] ?? '';
$mail = $row['email'] ?? $u['email'] ?? '';
$req_id = create_certificate_request($type, $cid, (int)$u['id'], $name, $mail, 'Wydane przez administratora');

flash_set('info', 'Wniosek o zaświadczenie utworzony automatycznie. Wypełnij treść i wydaj.');
header('Location: ' . APP_URL . '/certificates/issue.php?id=' . $req_id);
exit;
