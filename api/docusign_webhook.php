<?php
/**
 * api/docusign_webhook.php — Odbiorca zdarzeń DocuSign Connect (webhook).
 *
 * Konfiguracja w DocuSign: Rooms → Connect → Add Configuration
 *   URL:    https://twoja-domena/api/docusign_webhook.php
 *   Events: Envelope Complete, Envelope Declined, Envelope Voided
 *   HMAC:   klucz z ustawień (docusign_webhook_key)
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/docusign.php';

// ── Obsługa redirect po consent ──────────────────────────────────────────────
if (isset($_GET['consent'])) {
    http_response_code(200);
    echo '<p>Zgoda udzielona. Możesz zamknąć to okno.</p>';
    exit;
}

// ── Tylko POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    exit('Empty body');
}

// ── Weryfikacja HMAC (opcjonalna, jeśli skonfigurowany klucz) ─────────────────
$hmac_key = docusign_setting('docusign_webhook_key');
if ($hmac_key) {
    $sig_header = $_SERVER['HTTP_X_DOCUSIGN_SIGNATURE_1'] ?? '';
    if ($sig_header) {
        $expected = base64_encode(hash_hmac('sha256', $raw, $hmac_key, true));
        if (!hash_equals($expected, $sig_header)) {
            http_response_code(401);
            exit('Invalid signature');
        }
    }
}

// ── Parsowanie XML ────────────────────────────────────────────────────────────
$xml = @simplexml_load_string($raw);
if (!$xml) {
    http_response_code(400);
    exit('Invalid XML');
}

$ns = $xml->getNamespaces(true);
$x  = $xml->children($ns[''] ?? '');

$envelope_id = (string)($xml->EnvelopeStatus->EnvelopeID ?? $xml->EnvelopeID ?? '');
$status      = strtolower((string)($xml->EnvelopeStatus->Status ?? $xml->Status ?? ''));

if (!$envelope_id || !$status) {
    http_response_code(400);
    exit('Missing envelope data');
}

// ── Szukaj umowy po envelope_id ───────────────────────────────────────────────
$contract_tables = [
    'umowy_zlecenie'   => 'zlecenie',
    'umowy_uslugi'     => 'uslugi',
    'umowy_wolontariat'=> 'wolontariat',
    'umowy_dzielo'     => 'dzielo',
    'umowy_praca'      => 'praca',
    'umowy_inne'       => 'inne',
];

$found_table = null;
$found_id    = null;
$found_row   = null;

foreach ($contract_tables as $table => $type) {
    $row = db_one("SELECT * FROM {$table} WHERE id_dokumentu_el = ?", [$envelope_id]);
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
    exit('Envelope not found in local records');
}

// ── Aktualizuj status ─────────────────────────────────────────────────────────
db_update($found_table, ['docusign_status' => $status, 'updated_at' => date('Y-m-d H:i:s')], $found_id);

// ── Jeśli podpisano — pobierz podpisany PDF ───────────────────────────────────
if ($status === 'completed') {
    try {
        $ds        = new DocuSignClient();
        $filename  = 'docusign_signed_' . date('Ymd_His') . '_' . substr(md5($envelope_id), 0, 6) . '.pdf';
        $subfolder = $found_type;
        $dir       = UPLOAD_DIR . $subfolder . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $save_path = $dir . $filename;

        $ds->download_signed_document($envelope_id, $save_path);

        db_update($found_table, [
            'plik_potwierdzenia' => $subfolder . '/' . $filename,
            'updated_at'         => date('Y-m-d H:i:s'),
        ], $found_id);
    } catch (\Throwable $e) {
        error_log('DocuSign download error for envelope ' . $envelope_id . ': ' . $e->getMessage());
    }
}

// ── Log do audit_log jeśli dostępny ──────────────────────────────────────────
try {
    require_once dirname(__DIR__) . '/includes/approval.php';
    $label = DOCUSIGN_STATUS_LABELS[$status] ?? $status;
    log_contract_action($found_type, $found_id, 0, 'docusign_webhook', 'DocuSign: ' . $label);
} catch (\Throwable) {}

http_response_code(200);
echo 'OK';
