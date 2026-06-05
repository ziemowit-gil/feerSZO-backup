<?php
/**
 * api/autenti_webhook.php — Odbiorca zdarzeń Autenti (webhook).
 *
 * Konfiguracja w Autenti: Panel → Integracje → Webhook
 *   URL:     https://twoja-domena/api/autenti_webhook.php
 *   Events:  DOCUMENT_COMPLETED, DOCUMENT_DECLINED, DOCUMENT_CANCELLED, DOCUMENT_EXPIRED
 *   Secret:  wartość z ustawień (autenti_webhook_secret)
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/autenti.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    exit('Empty body');
}

// ── Weryfikacja podpisu (opcjonalna, jeśli skonfigurowany secret) ─────────────
$secret = autenti_setting('autenti_webhook_secret');
if ($secret) {
    $sig = $_SERVER['HTTP_X_AUTENTI_SIGNATURE'] ?? ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
    if ($sig) {
        $expected = hash_hmac('sha256', $raw, $secret);
        if (!hash_equals($expected, strtolower($sig))) {
            http_response_code(401);
            exit('Invalid signature');
        }
    }
}

// ── Parsowanie JSON ───────────────────────────────────────────────────────────
$payload = json_decode($raw, true);
if (!$payload) {
    http_response_code(400);
    exit('Invalid JSON');
}

// Obsługa różnych formatów webhooka Autenti
$doc_id = $payload['documentId']
       ?? $payload['id']
       ?? ($payload['data']['documentId'] ?? '');
$status = $payload['status']
       ?? ($payload['data']['status'] ?? '');
$event  = $payload['event']
       ?? ($payload['type'] ?? '');

// Mapowanie nazw zdarzeń na statusy (jeśli payload zawiera event zamiast status)
if (!$status && $event) {
    $event_map = [
        'DOCUMENT_COMPLETED'  => 'COMPLETED',
        'DOCUMENT_DECLINED'   => 'DECLINED',
        'DOCUMENT_CANCELLED'  => 'CANCELLED',
        'DOCUMENT_EXPIRED'    => 'EXPIRED',
        'DOCUMENT_IN_PROGRESS'=> 'IN_PROGRESS',
    ];
    $status = $event_map[$event] ?? '';
}

if (!$doc_id || !$status) {
    http_response_code(400);
    exit('Missing documentId or status');
}

// ── Szukaj umowy po autenti_document_id ──────────────────────────────────────
$contract_tables = [
    'umowy_zlecenie'    => 'zlecenie',
    'umowy_uslugi'      => 'uslugi',
    'umowy_wolontariat' => 'wolontariat',
    'umowy_dzielo'      => 'dzielo',
    'umowy_praca'       => 'praca',
    'umowy_inne'        => 'inne',
];

$found_table = null;
$found_id    = null;
$found_type  = null;

foreach ($contract_tables as $table => $type) {
    $row = db_one("SELECT * FROM {$table} WHERE autenti_document_id = ?", [$doc_id]);
    if ($row) {
        $found_table = $table;
        $found_id    = (int)$row['id'];
        $found_row   = $row;
        $found_type  = $type;
        break;
    }
}

if (!$found_table) {
    http_response_code(200);
    exit('Document not found in local records');
}

// ── Aktualizuj status ─────────────────────────────────────────────────────────
db_update($found_table, ['autenti_status' => $status, 'updated_at' => date('Y-m-d H:i:s')], $found_id);

// ── Jeśli podpisano — pobierz podpisany PDF ───────────────────────────────────
if ($status === 'COMPLETED') {
    try {
        $at       = new AutentiClient();
        $filename = 'autenti_signed_' . date('Ymd_His') . '_' . substr(md5($doc_id), 0, 6) . '.pdf';
        $dir      = UPLOAD_DIR . $found_type . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $save_path = $dir . $filename;

        $at->download_signed_document($doc_id, $save_path);

        db_update($found_table, [
            'plik_potwierdzenia' => $found_type . '/' . $filename,
            'updated_at'         => date('Y-m-d H:i:s'),
        ], $found_id);
    } catch (\Throwable $e) {
        error_log('Autenti download error for doc ' . $doc_id . ': ' . $e->getMessage());
    }
}

// ── Wpis do audit_log ─────────────────────────────────────────────────────────
try {
    require_once dirname(__DIR__) . '/includes/approval.php';
    $label = AUTENTI_STATUS_LABELS[$status] ?? $status;
    log_contract_action($found_type, $found_id, 0, 'autenti_webhook', 'Autenti: ' . $label);
} catch (\Throwable) {}

http_response_code(200);
echo 'OK';
