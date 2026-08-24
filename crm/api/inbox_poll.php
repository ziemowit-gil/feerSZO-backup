<?php
/**
 * crm/api/inbox_poll.php — lekki sygnał „czy w skrzynce coś się zmieniło".
 *
 * Skrzynka CRM odpytuje ten endpoint co kilkadziesiąt sekund. Zwraca tylko to,
 * co potrzebne do decyzji „odświeżyć listę i zagrać sygnał?", a nie całą listę —
 * pełny HTML dociągamy dopiero, gdy faktycznie coś przyszło.
 *
 * GET ?view=new&mailbox_id=0&q=…
 * → {ok, max_id, total, unread, counts:{view=>n}}
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_mailbox.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Wymagane logowanie.']);
    exit;
}
if (!can_read('crm') && !can_write('crm') && !is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Brak uprawnień.']);
    exit;
}

$view   = (string)($_GET['view'] ?? 'new');
$filter = [
    'view'       => $view,
    'mailbox_id' => (int)($_GET['mailbox_id'] ?? 0),
    'q'          => trim((string)($_GET['q'] ?? '')),
    'page'       => 1,
    'per_page'   => 20,
];

$inbox = crm_mailbox_inbox($filter);

// max_id wystarczy do wykrycia nowej wiadomości: id rosną, a przy odświeżeniu
// listy i tak pobieramy świeży HTML.
$max_id = 0;
$unread = 0;
foreach ($inbox['rows'] as $r) {
    $max_id = max($max_id, (int)$r['id']);
    if (!(int)$r['is_read']) $unread++;
}

echo json_encode([
    'ok'     => true,
    'max_id' => $max_id,
    'total'  => (int)$inbox['total'],
    'unread' => $unread,
    'counts' => crm_mailbox_counts(),
], JSON_UNESCAPED_UNICODE);
