<?php
/**
 * AJAX endpoint: pobierz dane faktury z KSeF po numerze referencyjnym i prefill
 * formularza nowego dokumentu EODoK. Reużywa wspólnej infrastruktury KSeF
 * (autoryzacja, zapytania, parsowanie XML) z includes/kdok_ksef.php — to samo
 * połączenie organizacyjne co moduł KDOK, tylko inny docelowy dokument.
 * Zwraca JSON: { ok, data: {...}, file_path }
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';
require_once __DIR__ . '/../includes/kdok_ksef.php';

header('Content-Type: application/json; charset=utf-8');

function json_err(string $msg): never {
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

require_login();
if (!edok_has_role('upload') && !is_admin()) {
    json_err('Brak uprawnień.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Metoda niedozwolona.');
}
csrf_check();

if (org_setting('kdok_ksef_enabled') !== '1') {
    json_err('Integracja KSeF nie jest włączona. Skonfiguruj ją w panelu admina (EOD Dokumentów Księgowych → Ustawienia).');
}

$ref = trim($_POST['ksef_reference'] ?? '');
if ($ref === '') {
    json_err('Podaj numer referencyjny KSeF.');
}

$nip = org_setting('kdok_ksef_nip');
if (!$nip) {
    json_err('Brak konfiguracji KSeF (NIP). Skonfiguruj w panelu admina.');
}

$jwt = null;
try {
    $auth = kdok_ksef_authenticate_auto($nip);
    if (!$auth['ok']) {
        json_err('Błąd autoryzacji KSeF: ' . ($auth['error'] ?? 'nieznany błąd'));
    }
    $jwt = $auth['token'];

    $xml = kdok_ksef_get_invoice_xml($jwt, $ref);
    kdok_ksef_session_terminate($jwt);
    $jwt = null;

    $data = kdok_ksef_parse_xml($xml);

    // Zapisz XML jako dokument źródłowy — to autorytatywne źródło faktury z KSeF,
    // zastępuje ręcznie wgrywany skan (wymagany do kontroli merytorycznej).
    $dir = UPLOAD_DIR . 'edok_docs/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fname = 'ksef_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ref) . '_' . date('Ymd_His') . '.xml';
    file_put_contents($dir . $fname, $xml);
    $file_path = 'edok_docs/' . $fname;

    echo json_encode([
        'ok'   => true,
        'file_path' => $file_path,
        'data' => [
            'typ_dokumentu'    => 'faktura_vat',
            'nr_faktury'       => $data['invoice_number'] ?? '',
            'kontrahent_nazwa' => $data['seller_name']    ?? '',
            'kontrahent_nip'   => $data['seller_nip']     ?? '',
            'kwota_brutto'     => $data['gross_value']    ?? '',
            'waluta'           => $data['currency']       ?: 'PLN',
            'data_wystawienia' => $data['issue_date']     ?? '',
            'description'      => 'Faktura pobrana z KSeF, nr referencyjny: ' . $ref,
        ],
    ]);
} catch (\Throwable $e) {
    if ($jwt) { try { kdok_ksef_session_terminate($jwt); } catch (\Throwable $_) {} }
    json_err('Błąd KSeF: ' . $e->getMessage());
}
