<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . APP_URL); exit; }
csrf_check();

$type = preg_replace('/[^a-z]/', '', $_POST['type'] ?? '');
$id   = intval($_POST['id'] ?? 0);
$back = APP_URL . "/contracts/{$type}/view.php?id={$id}";

if (!$type || !$id) { flash_set('danger', 'Błędne parametry.'); header('Location: ' . APP_URL); exit; }

$table = table_for_type($type);
$row   = db_one("SELECT * FROM {$table} WHERE id=?", [$id]);
if (!$row) { flash_set('danger', 'Nie znaleziono umowy.'); header('Location: ' . $back); exit; }

// Sprawdź czy nie ma już oczekującej
$existing = get_current_approval($type, $id);
if ($existing && $existing['status'] === 'oczekuje') {
    flash_set('warning', 'Ta umowa ma już oczekujący wniosek o akceptację.');
    header('Location: ' . $back); exit;
}

// Podpisanej umowy nie składa się do akceptacji
if (($row['status'] ?? '') === 'podpisana') {
    flash_set('warning', 'Podpisanej umowy nie można złożyć do akceptacji — zmień najpierw status (np. na „w realizacji").');
    header('Location: ' . $back); exit;
}

$user = current_user();
$result = submit_for_approval($type, $id, $user['id'], $row['numer_umowy']);

$msg = 'Wniosek o akceptację złożony.';
if ($result['emails_sent'] > 0) {
    $msg .= " Wysłano powiadomienie do {$result['emails_sent']} administratora/ów.";
} else {
    $msg .= ' (Nie udało się wysłać powiadomień e-mail — sprawdź konfigurację M365 lub mail PHP.)';
}
flash_set('success', $msg);
header('Location: ' . $back);
exit;
