<?php
/**
 * karty30/ti/ext/ticket.php — wystawienie biletu do zasobu (POST).
 * Czytanie → przekierowanie do czytnika, pobieranie → wprost do pliku.
 */
require_once __DIR__ . '/_boot.php';

$EXT_SUBJECT = ext_require_subject($EXT_SUBJECT);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_check();

$ability = ($_POST['ability'] ?? 'stream') === 'download' ? 'download' : 'stream';
$ctx     = ext_resource_context((int)($_POST['resource_id'] ?? 0));

if (!$ctx) {
    ext_log($EXT_SUBJECT, null, $ability, 'denied', 'not_found');
    flash_set('danger', ext_reason_text('not_found'));
    header('Location: index.php'); exit;
}

$t = ext_ticket_issue($EXT_SUBJECT, $ctx, $ability);
if (!$t['ok']) {
    flash_set('danger', $t['msg']);
    header('Location: title.php?id=' . (int)$ctx['title']['id']); exit;
}

header('Location: ' . ($ability === 'download'
    ? 'file.php?t=' . $t['ticket']
    : 'read.php?t=' . $t['ticket']));
exit;
