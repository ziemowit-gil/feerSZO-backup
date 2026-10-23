<?php
/**
 * crm/api/contact_comms.php — ostatnie 5 komunikacji dla kontaktu
 * GET ?contact_id=N → JSON { ok, html }
 */
require_once dirname(__DIR__) . '/../config.php';
require_once dirname(__DIR__) . '/../includes/db.php';
require_once dirname(__DIR__) . '/../includes/auth.php';
require_once dirname(__DIR__) . '/../includes/functions.php';
require_once dirname(__DIR__) . '/../includes/crm.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

crm_require_json('inbox', 'read');
if (!can_read('crm')) {
    echo json_encode(['ok' => false, 'msg' => 'Brak dostępu.']);
    exit;
}

/** Klikalne nazwy załączników wiadomości (otwiera crm/api/attachment.php). */
function crm_contact_comm_atts(array $atts): string {
    $o = '';
    foreach ($atts as $a) {
        $name = h((string)$a['original_name']);
        if (!empty($a['stored_path']) || !empty($a['graph_attachment_id'])) {
            $o .= '<a class="d-block text-truncate" style="font-size:.76rem;max-width:16rem" target="_blank" rel="noopener" href="'
                . APP_URL . '/crm/api/attachment.php?id=' . (int)$a['id'] . '" title="Otwórz załącznik: ' . $name . '">'
                . '<i class="bi bi-paperclip" aria-hidden="true"></i> ' . $name . '</a>';
        } else {
            $o .= '<span class="d-block text-muted text-truncate" style="font-size:.76rem"><i class="bi bi-paperclip" aria-hidden="true"></i> ' . $name . '</span>';
        }
    }
    return $o;
}

$cid = (int)($_GET['contact_id'] ?? 0);
if (!$cid) {
    echo json_encode(['ok' => false]);
    exit;
}

$comms = db_all(
    "SELECT c.*, u.name AS sender_name
     FROM crm_communications c
     LEFT JOIN users u ON u.id = c.sent_by
     WHERE c.contact_id=? ORDER BY c.sent_at DESC LIMIT 5",
    [$cid]
);

require_once dirname(__DIR__) . '/../includes/crm_case_extras.php';
$comms = crm_comms_with_attachments($comms);

$html = '';
if ($comms) {
    $icons = [
        'email'    => 'bi-envelope',
        'sms'      => 'bi-chat-text',
        'telefon'  => 'bi-telephone',
        'osobisty' => 'bi-person-badge',
    ];
    foreach ($comms as $c) {
        $icon = $icons[$c['channel']] ?? 'bi-chat-dots';
        $dir  = in_array($c['direction'], ['out', 'outgoing'], true) ? '→' : '←';
        $html .= '<div style="display:flex;gap:.5rem;align-items:flex-start;padding:.4rem 0;border-bottom:1px solid #f1f5f9;font-size:.8rem">'
               . '<i class="bi ' . $icon . ' text-muted mt-1" aria-hidden="true"></i>'
               . '<div><div style="font-weight:600">' . $dir . ' ' . h($c['subject'] ?: $c['channel']) . '</div>'
               . '<div class="text-muted">' . h(substr($c['body'] ?? '', 0, 80)) . (strlen($c['body'] ?? '') > 80 ? '…' : '') . '</div>'
               . crm_contact_comm_atts($c['attachments'] ?? [])
               . '<div class="text-muted" style="font-size:.73rem">' . h(date('d.m.Y', strtotime($c['sent_at'])))
               . ($c['sender_name'] ? ' · ' . h($c['sender_name']) : '') . '</div>'
               . '</div></div>';
    }
} else {
    $html = '<div class="text-muted small">Brak historii komunikacji.</div>';
}

echo json_encode(['ok' => true, 'html' => $html]);
