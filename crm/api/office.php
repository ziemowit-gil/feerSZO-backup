<?php
/**
 * crm/api/office.php — akcje integracji z Microsoft 365 wywoływane z kartoteki.
 *
 * POST action=push_contact  {contact_id}  → zapis kontaktu do książki adresowej Outlooka
 * POST action=pull_mail     {contact_id}  → pobranie korespondencji kontaktu do kartoteki
 * POST action=unlink        {contact_id}  → usunięcie wpisu z książki adresowej
 * POST action=push_pending  {limit}       → masowy zapis zaległych kontaktów (admin)
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_office.php';

header('Content-Type: application/json; charset=utf-8');

function office_ok(mixed $d = null): never { echo json_encode(['ok' => true, 'data' => $d], JSON_UNESCAPED_UNICODE); exit; }
function office_err(string $m, int $c = 400): never { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE); exit; }

if (!current_user()) office_err('Wymagane logowanie.', 401);

crm_require_json('inbox', 'read');
if (!can_write('crm') && !is_admin()) office_err('Brak uprawnień.', 403);
crm_office_migrate();

$body   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = (string)($body['action'] ?? '');
$cid    = (int)($body['contact_id'] ?? 0);

// CSRF — ten sam token co w formularzach CRM
$token = (string)($body['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) office_err('Błąd CSRF — odśwież stronę.', 403);

if (in_array($action, ['push_contact', 'pull_mail', 'unlink'], true)) {
    if (!$cid) office_err('Brak kontaktu.');
    if (!crm_can_access_contact($cid)) office_err('Brak dostępu do tego kontaktu.', 403);
}

switch ($action) {

case 'push_contact':
    $r = crm_office_push_contact($cid);
    if (!$r['ok']) office_err($r['error'] ?: 'Nie udało się zapisać kontaktu w Outlooku.');
    office_ok([
        'action'  => $r['action'],
        'message' => $r['action'] === 'created'
            ? 'Kontakt dodany do książki adresowej Outlooka.'
            : 'Wpis w książce adresowej zaktualizowany.',
    ]);

case 'pull_mail':
    $r = crm_office_pull_contact_mail($cid);
    if (!$r['ok']) office_err($r['error'] ?: 'Nie udało się pobrać korespondencji.');
    office_ok([
        'logged'  => $r['logged'],
        'fetched' => $r['fetched'],
        'message' => $r['logged'] > 0
            ? ('Dopisano ' . $r['logged'] . ' wiadomości do kartoteki (przejrzano ' . $r['fetched'] . ').')
            : ('Brak nowych wiadomości (przejrzano ' . $r['fetched'] . ').'),
    ]);

case 'unlink':
    $r = crm_office_unlink_contact($cid, !empty($body['delete_remote']));
    if (!$r['ok']) office_err($r['error'] ?: 'Nie udało się odłączyć kontaktu.');
    office_ok(['message' => 'Powiązanie z Outlookiem usunięte.']);

case 'push_pending':
    if (!is_admin()) office_err('Tylko administrator.', 403);
    $r = crm_office_push_pending((int)($body['limit'] ?? 100));
    office_ok($r + ['message' => 'Zapisano: ' . $r['created'] . ' nowych, ' . $r['updated']
        . ' zaktualizowanych, błędów: ' . $r['failed'] . '.']);

default:
    office_err('Nieznana akcja.');
}
