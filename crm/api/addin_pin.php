<?php
/**
 * crm/api/addin_pin.php — Przypina mail z Outlooka do historii komunikacji kontaktu.
 *
 * POST (JSON lub form):
 *   contact_id  (wymagane)  — ID kontaktu CRM
 *   direction   'in'|'out'  — przychodzący / wychodzący (domyślnie 'in')
 *   subject, body           — temat i treść (podgląd)
 *   message_id              — identyfikator wiadomości Outlook (dedup; namespace 'addin:')
 *   sent_at                 — data ISO/own format (opcjonalnie)
 *   Autoryzacja: sesja (can_write/admin) LUB token API (Bearer <crm_sync_token> | token=).
 *
 * Odpowiedź JSON: { ok, id, duplicate? }
 */

$_root = dirname(__DIR__, 2);
require_once $_root . '/config.php';
require_once $_root . '/includes/db.php';
require_once $_root . '/includes/auth.php';
require_once $_root . '/includes/functions.php';
require_once $_root . '/includes/crm.php';

auth_start();
header('Content-Type: application/json; charset=utf-8');

// ── Autoryzacja: sesja (zapis) LUB token API ────────────────────────────────────
$auth_ok = (current_user() && (can_write('crm') || is_admin()));
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Wymagana metoda POST.']);
    exit;
}

crm_migrate();
// Kolumna dedup (idempotentnie — gdyby OutlookSync nigdy nie był uruchomiony)
try { crm_db()->exec("ALTER TABLE crm_communications ADD COLUMN outlook_message_id TEXT"); } catch (\Throwable $e) {}

// ── Odczyt parametrów (JSON lub form) ───────────────────────────────────────────
$raw   = file_get_contents('php://input');
$input = ($raw && ($j = json_decode($raw, true)) !== null) ? $j : $_POST;

$contact_id = (int)($input['contact_id'] ?? 0);
$direction  = ($input['direction'] ?? 'in') === 'out' ? 'out' : 'in';
$subject    = trim((string)($input['subject'] ?? '')) ?: '(bez tematu)';
$body       = trim((string)($input['body'] ?? '')) ?: '(brak treści)';
$msg_id     = trim((string)($input['message_id'] ?? ''));
$sent_at    = trim((string)($input['sent_at'] ?? ''));

if (!$contact_id) {
    echo json_encode(['ok' => false, 'error' => 'Brak contact_id.']);
    exit;
}

// Kontakt musi istnieć i być aktywny
$contact = db_one("SELECT id FROM crm_contacts WHERE id=? AND crm_active=1", [$contact_id]);
if (!$contact) {
    echo json_encode(['ok' => false, 'error' => 'Nie znaleziono kontaktu.']);
    exit;
}

// Namespacujemy id z add-inu, by nie kolidowało z auto-syncem (Graph message id)
$pin_id = $msg_id ? 'addin:' . $msg_id : null;

// Normalizacja daty → 'Y-m-d H:i:s'
$sent_at_db = date('Y-m-d H:i:s');
if ($sent_at) {
    try {
        $dt = new \DateTime($sent_at);
        $dt->setTimezone(new \DateTimeZone(crm_setting('timezone') ?: 'Europe/Warsaw'));
        $sent_at_db = $dt->format('Y-m-d H:i:s');
    } catch (\Throwable $e) {}
}

// ── Dedup: ten sam mail już przypięty do tego kontaktu ──────────────────────────
if ($pin_id) {
    $dup = db_one(
        "SELECT id FROM crm_communications WHERE outlook_message_id=? AND contact_id=? LIMIT 1",
        [$pin_id, $contact_id]
    );
    if ($dup) {
        echo json_encode(['ok' => true, 'id' => (int)$dup['id'], 'duplicate' => true]);
        exit;
    }
}

$sent_by = current_user()['id'] ?? null;

$id = db_insert('crm_communications', [
    'contact_id'         => $contact_id,
    'channel'            => 'email',
    'direction'          => $direction,
    'subject'            => mb_substr($subject, 0, 500),
    'body'               => mb_substr($body, 0, 5000),
    'status'             => 'zsynchronizowana',
    'outlook_message_id' => $pin_id,
    'sent_by'            => $sent_by,
    'sent_at'            => $sent_at_db,
]);

db()->prepare("UPDATE crm_contacts SET updated_at=? WHERE id=?")
    ->execute([date('Y-m-d H:i:s'), $contact_id]);

echo json_encode(['ok' => true, 'id' => (int)$id], JSON_UNESCAPED_UNICODE);
