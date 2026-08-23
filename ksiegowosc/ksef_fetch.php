<?php
/**
 * AJAX endpoint: pobierz dane faktury z KSeF po numerze referencyjnym.
 * Zwraca JSON: { ok, data: { title, kwota, currency, description, seller_name, seller_nip, invoice_number, issue_date } }
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';
require_once __DIR__ . '/../includes/kdok_ksef.php';

header('Content-Type: application/json; charset=utf-8');

function json_err(string $msg): never {
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

require_login();
if (!kdok_has_role('upload') && !is_admin()) {
    json_err('Brak uprawnień.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Metoda niedozwolona.');
}
csrf_check();

if (org_setting('kdok_ksef_enabled') !== '1') {
    json_err('Integracja KSeF nie jest włączona. Skonfiguruj ją w panelu admina.');
}

// Pobranie danych faktury to czysty odczyt z API KSeF — bez bramki IKA/IKAKS.
// Dostęp chroni require_login() + rola upload/admin powyżej.

$ref = trim($_POST['ksef_reference'] ?? '');
if ($ref === '') {
    json_err('Podaj numer referencyjny KSeF.');
}

$nip = org_setting('kdok_ksef_nip');
if (!$nip) {
    json_err('Brak konfiguracji KSeF (NIP). Skonfiguruj w panelu admina.');
}

try {
    // Autoryzacja (auto: token lub certyfikat)
    $auth = kdok_ksef_authenticate_auto($nip);
    if (!$auth['ok']) {
        json_err('Błąd autoryzacji KSeF: ' . ($auth['error'] ?? 'nieznany błąd'));
    }
    $jwt = $auth['token'];

    // Pobierz XML faktury
    $xml = kdok_ksef_get_invoice_xml($jwt, $ref);

    // Zakończ sesję
    kdok_ksef_session_terminate($jwt);

    // Parsuj XML
    $data = kdok_ksef_parse_xml($xml);
    $data['ksef_reference'] = $ref;

    // Zbuduj sugerowany tytuł i opis
    $title = trim(($data['invoice_number'] ?? '') . ($data['seller_name'] ? ' — ' . $data['seller_name'] : ''));
    if (!$title) $title = 'Faktura KSeF ' . $ref;

    $desc_parts = [];
    if ($data['seller_nip'])      $desc_parts[] = 'NIP sprzedawcy: ' . $data['seller_nip'];
    if ($data['gross_value'])     $desc_parts[] = 'Kwota brutto: ' . $data['gross_value'] . ' ' . ($data['currency'] ?: 'PLN');
    if ($data['issue_date'])      $desc_parts[] = 'Data wystawienia: ' . $data['issue_date'];
    if ($ref)                     $desc_parts[] = 'Nr KSeF: ' . $ref;
    $description = implode(' | ', $desc_parts);

    echo json_encode([
        'ok'   => true,
        'data' => [
            'title'          => $title,
            'kwota'          => $data['gross_value'] ?? '',
            'currency'       => $data['currency']    ?? 'PLN',
            'description'    => $description,
            'seller_name'    => $data['seller_name'] ?? '',
            'seller_nip'     => $data['seller_nip']  ?? '',
            'invoice_number' => $data['invoice_number'] ?? '',
            'issue_date'     => $data['issue_date']  ?? '',
            'ksef_reference' => $ref,
        ],
    ]);
} catch (\Throwable $e) {
    // Upewnij się że sesja jest zamknięta
    if (!empty($jwt)) { try { kdok_ksef_session_terminate($jwt); } catch (\Throwable $_) {} }
    json_err('Błąd KSeF: ' . $e->getMessage());
}
