<?php
/**
 * edok/wyplata_action.php — ręczne odnotowanie / cofnięcie wypłaty pozycji listy płac
 * z karty umowy zlecenie (zakładka Rachunki). Logika ta sama co w edok/view.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();
csrf_check();

// Powrót tylko do znanych widoków (karta umowy zlecenie albo pozycje listy płac) — bez otwartego przekierowania.
$back = (string)($_POST['return_to'] ?? '');
if (!preg_match('~^(contracts/zlecenie/view\.php\?id=\d+&tab=rachunki|edok/view\.php\?id=\d+#wyplaty)$~', $back)) {
    $back = 'edok/index.php';
}

$action = $_POST['action'] ?? '';
$wid    = (int)($_POST['wyplata_id'] ?? 0);
$w      = $wid ? db_one("SELECT doc_id FROM edok_wyplaty WHERE id = ?", [$wid]) : null;

if (!(is_admin() || edok_has_role('zatwierdza')) || !$w) {
    flash_set('danger', 'Brak uprawnień do odnotowania wypłaty albo nie ma takiej pozycji.');
} elseif ($action === 'wyplata_paid') {
    $err = edok_wyplata_mark_paid($wid, (int)$w['doc_id'], mb_substr(trim((string)($_POST['paid_comment'] ?? '')), 0, 200));
    if ($err) flash_set('danger', $err); else flash_set('success', 'Odnotowano wypłatę.');
} elseif ($action === 'wyplata_unpaid') {
    $err = edok_wyplata_unmark($wid, (int)$w['doc_id']);
    if ($err) flash_set('danger', $err); else flash_set('success', 'Cofnięto odnotowanie wypłaty.');
} else {
    flash_set('danger', 'Nieznana operacja.');
}

header('Location: ' . APP_URL . '/' . $back);
exit;
