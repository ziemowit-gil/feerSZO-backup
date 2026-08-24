<?php
/**
 * crm/api/addin_lookup.php — Lookup kontaktów CRM po adresie e-mail (dla add-inu Outlook).
 *
 * GET/POST  ?emails=a@x.pl,b@y.pl  (lub pojedyncze ?email=)
 *   Autoryzacja: sesja (admin/can_read) LUB token API (Authorization: Bearer <crm_sync_token> | ?token=).
 *
 * Odpowiedź JSON: { ok, contacts:[{id,name,type,status,organizacja,email,url}] }
 */

$_root = dirname(__DIR__, 2);
require_once $_root . '/config.php';
require_once $_root . '/includes/db.php';
require_once $_root . '/includes/auth.php';
require_once $_root . '/includes/functions.php';
require_once $_root . '/includes/crm.php';

auth_start();
header('Content-Type: application/json; charset=utf-8');

// ── Autoryzacja: sesja LUB token API (identycznie jak outlook_sync.php) ─────────
$auth_ok = (current_user() && crm_can('contacts', 'read'));
if (!$auth_ok) {
    $bearer = '';
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($hdr && str_starts_with($hdr, 'Bearer ')) $bearer = substr($hdr, 7);
    if (!$bearer) $bearer = $_GET['token'] ?? $_POST['token'] ?? '';
    $sync_token = crm_setting('crm_sync_token');
    if ($bearer && $sync_token && hash_equals($sync_token, $bearer)) $auth_ok = true;
}
if (!$auth_ok) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Brak dostępu.']);
    exit;
}

crm_migrate();

// ── Zbierz adresy z zapytania ───────────────────────────────────────────────────
$raw_emails = $_GET['emails'] ?? $_POST['emails'] ?? $_GET['email'] ?? $_POST['email'] ?? '';
$emails = array_values(array_unique(array_filter(array_map(
    fn($e) => strtolower(trim($e)),
    preg_split('/[,;\s]+/', (string)$raw_emails) ?: []
))));

if (!$emails) {
    echo json_encode(['ok' => true, 'contacts' => []]);
    exit;
}

// ── Lookup ───────────────────────────────────────────────────────────────────────
$ph   = implode(',', array_fill(0, count($emails), '?'));
$rows = db_all(
    "SELECT id, imie_nazwisko, type, status, organizacja, email
     FROM crm_contacts
     WHERE LOWER(email) IN ($ph) AND crm_active = 1
     ORDER BY imie_nazwisko",
    $emails
);

$statuses = crm_statuses();
$contacts = array_map(fn($r) => [
    'id'          => (int)$r['id'],
    'name'        => $r['imie_nazwisko'],
    'type'        => $r['type'],
    'status'      => $statuses[$r['status']]['label'] ?? $r['status'],
    'organizacja' => $r['organizacja'] ?: '',
    'email'       => $r['email'] ?: '',
    'url'         => APP_URL . '/crm/contact/view.php?id=' . (int)$r['id'],
], $rows);

echo json_encode(['ok' => true, 'contacts' => $contacts], JSON_UNESCAPED_UNICODE);
