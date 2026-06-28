<?php
/**
 * contracts/wolontariat/assign_guardian.php — Wyznacz/powiąż konto opiekuna.
 *
 * Dla umowy niepełnoletniego wolontariusza: tworzy (jeśli brak) konto rodzica
 * z e-maila umowy i twardo wiąże je z kontem dziecka (users.guardian_user_id),
 * dzięki czemu opiekun może wejść w kontekst dziecka (zob. includes/context.php).
 * Akcja administracyjna, idempotentna; wraca do edycji umowy.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/guardian.php';

require_role('admin', 'editor');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); die('Brak id umowy.'); }

$r = wolontariat_assign_guardian($id);

if (!empty($r['ok'])) {
    flash_set('success', $r['msg']);
    try { log_contract_action('wolontariat', $id, current_user()['id'], 'note', 'Wyznaczono/powiązano konto opiekuna z kontem dziecka.'); } catch (\Throwable $e) {}
} else {
    flash_set('error', $r['msg'] ?: 'Nie udało się wyznaczyć konta opiekuna.');
}

header('Location: ' . APP_URL . '/contracts/wolontariat/edit.php?id=' . $id);
exit;
