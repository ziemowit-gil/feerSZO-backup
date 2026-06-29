<?php
/**
 * karty30/pfron/sign.php — Zapis podpisu odręcznego + nadanie numeru dokumentu umowy PFRON.
 *
 * POST JSON: { pfron_id, signature_data (base64 PNG) }
 * Odpowiedź JSON: { ok, doc_number, signed_at } lub { ok:false, error }
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

header('Content-Type: application/json; charset=utf-8');

function json_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

k30_require_access();
karty30_migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Metoda niedozwolona', 405);

$body      = (string)file_get_contents('php://input');
$payload   = json_decode($body, true);
$pfron_id  = (int)($payload['pfron_id']       ?? 0);
$sig_data  = (string)($payload['signature_data'] ?? '');
$csrf      = (string)($payload['_csrf']          ?? '');

if (!hash_equals(csrf_token(), $csrf)) json_err('Nieprawidłowy token CSRF.', 403);
if (!$pfron_id)  json_err('Brak ID umowy PFRON.');
if (!$sig_data)  json_err('Brak danych podpisu.');

// Walidacja formatu base64 (PNG z canvasa lub JPEG/PNG ze skanu)
if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,[A-Za-z0-9+\/=]+$/', $sig_data)) {
    json_err('Nieprawidłowy format podpisu.');
}

// Sprawdź czy umowa istnieje i ma uprawnienia
$pfron = k30_pfron_contract_get($pfron_id);
if (!$pfron) json_err('Umowa PFRON nie istnieje.');

// Jeśli umowa ma już numer — zwróć istniejący (idempotentne)
if (!empty($pfron['doc_number'])) {
    echo json_encode([
        'ok'        => true,
        'doc_number'=> $pfron['doc_number'],
        'signed_at' => $pfron['signed_at'],
        'existing'  => true,
    ]);
    exit;
}

// Nadaj numer i zapisz podpis (atomowo — w ramach jednej transakcji SQLite)
$doc_number = k30_pfron_next_doc_number();
$signed_at  = date('Y-m-d H:i:s');

try {
    db()->beginTransaction();
    // Sprawdź ponownie po wejściu w transakcję
    $check = db_one("SELECT doc_number FROM k30_pfron_contracts WHERE id=?", [$pfron_id]);
    if (!empty($check['doc_number'])) {
        db()->rollBack();
        echo json_encode(['ok'=>true,'doc_number'=>$check['doc_number'],'signed_at'=>null,'existing'=>true]);
        exit;
    }
    // Nadaj numer
    $num_check = db_one(
        "SELECT COUNT(*) AS cnt FROM k30_pfron_contracts WHERE doc_number=? AND doc_number!=''",
        [$doc_number]
    );
    if ((int)($num_check['cnt'] ?? 0) > 0) {
        // Kolizja — wygeneruj ponownie
        $doc_number = k30_pfron_next_doc_number();
    }
    db()->prepare(
        "UPDATE k30_pfron_contracts SET doc_number=?, signed_at=?, signature_data=?, updated_at=datetime('now') WHERE id=?"
    )->execute([$doc_number, $signed_at, $sig_data, $pfron_id]);
    db()->commit();
} catch (\Throwable $e) {
    db()->rollBack();
    json_err('Błąd zapisu: ' . $e->getMessage(), 500);
}

echo json_encode([
    'ok'        => true,
    'doc_number'=> $doc_number,
    'signed_at' => $signed_at,
    'existing'  => false,
]);
