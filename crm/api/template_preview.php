<?php
/**
 * crm/api/template_preview.php — Podgląd szablonu z uzupełnionymi zmiennymi.
 * GET ?template_id=X&contact_id=Y
 * Zwraca JSON {subject, body, contact_name} z podstawionymi zmiennymi.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) { http_response_code(401); echo json_encode(['error' => 'Brak sesji']); exit; }

crm_require_json('inbox', 'read');
if (!can_write('crm') && !is_admin()) { http_response_code(403); echo json_encode(['error' => 'Brak uprawnień']); exit; }

$template_id = (int)($_GET['template_id'] ?? 0);
$contact_id  = (int)($_GET['contact_id']  ?? 0);

if (!$template_id || !$contact_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Wymagane: template_id i contact_id']);
    exit;
}

$tpl = db_one("SELECT * FROM crm_templates WHERE id=? AND is_active=1", [$template_id]);
if (!$tpl) {
    http_response_code(404);
    echo json_encode(['error' => 'Szablon nie istnieje lub jest nieaktywny']);
    exit;
}

if (!crm_can_access_contact($contact_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'Brak dostępu do kontaktu']);
    exit;
}

$contact = db_one(
    "SELECT id, imie_nazwisko, email, telefon, organizacja, stanowisko, wojewodztwo, powiat, gmina
     FROM crm_contacts WHERE id=?",
    [$contact_id]
);
if (!$contact) {
    http_response_code(404);
    echo json_encode(['error' => 'Kontakt nie istnieje']);
    exit;
}

$subject = CrmManager::renderTemplate((string)($tpl['subject'] ?? ''), $contact);
$body    = CrmManager::renderTemplate((string)($tpl['body']    ?? ''), $contact);

echo json_encode([
    'subject'      => $subject,
    'body'         => $body,
    'contact_name' => $contact['imie_nazwisko'] ?? '',
    'channel'      => $tpl['channel'],
], JSON_UNESCAPED_UNICODE);
