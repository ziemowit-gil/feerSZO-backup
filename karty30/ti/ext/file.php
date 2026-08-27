<?php
/**
 * karty30/ti/ext/file.php — strumień pliku po bilecie.
 *
 * Jedyna droga do treści. Ścieżka w magazynie nie wychodzi na zewnątrz nigdy —
 * na zewnątrz istnieje wyłącznie identyfikator biletu.
 *
 * Bilet do CZYTANIA działa wielokrotnie aż do wygaśnięcia, bo czytnik PDF prosi
 * o kolejne fragmenty osobnymi żądaniami. Bilet do POBRANIA jest jednorazowy.
 */
require_once __DIR__ . '/_boot.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ext_deliver.php';

$ticket = ext_ticket_get((string)($_GET['t'] ?? ''));
if (!$ticket)                                          ext_fail(404, 'Nieprawidłowy link do materiału.');
if ($ticket['expires_at'] < date('Y-m-d H:i:s'))       ext_fail(410, 'Link wygasł — otwórz materiał ponownie.');
if ($ticket['ability'] === 'download' && $ticket['used_at']) ext_fail(410, 'Link został już użyty.');

// Bilet jest przypisany do przeglądarki, która o niego poprosiła — przeklejenie
// adresu na inne urządzenie nie daje dostępu.
if (!hash_equals((string)$ticket['ua_hash'], hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')))) {
    ext_fail(403, 'Link wystawiono dla innej przeglądarki.');
}

// Podmiot z sesji musi zgadzać się z tym z biletu — inaczej wystarczyłoby
// podejrzeć cudzy adres w logach serwera pośredniczącego.
if (!$EXT_SUBJECT
    || $EXT_SUBJECT['type'] !== $ticket['subject_type']
    || (int)$EXT_SUBJECT['id'] !== (int)$ticket['subject_id']) {
    ext_fail(403, 'Ten link należy do innego konta.');
}

$resource = ext_resource_get((int)$ticket['resource_id']);
if (!$resource || empty($resource['is_active'])) ext_fail(404, 'Materiał został wycofany.');

$prep = ext_prepare_file($resource, $ticket);
if (!empty($prep['queued'])) {
    ext_fail(202, 'Dokument jest przygotowywany (nakładamy znak wodny). Odśwież stronę za chwilę.');
}
if (!empty($prep['error'])) {
    ext_log($EXT_SUBJECT, ['resource' => $resource], (string)$ticket['ability'], 'denied', 'prepare_failed', (string)$ticket['id']);
    ext_fail(500, $prep['error']);
}

db_exec("UPDATE k30_ext_tickets SET used_at=datetime('now') WHERE id=?", [$ticket['id']]);
ext_log($EXT_SUBJECT, ['resource' => $resource], (string)$ticket['ability'], 'served', 'ok', (string)$ticket['id']);

ext_stream_file(
    $prep['path'],
    (string)$resource['mime'],
    (string)$resource['orig_name'],
    $ticket['ability'] !== 'download'
);
