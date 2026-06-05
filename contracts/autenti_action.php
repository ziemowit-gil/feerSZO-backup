<?php
/**
 * contracts/autenti_action.php — Akcje Autenti (AJAX).
 *
 * POST ?action=send    — wyślij dokument do podpisu
 * POST ?action=status  — odśwież status dokumentu
 * POST ?action=cancel  — anuluj dokument
 *
 * Parametry POST: contract_type, contract_id, [signer_email, signer_name]
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/autenti.php';
require_once dirname(__DIR__) . '/includes/approval.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
csrf_check();

$action        = $_POST['action']        ?? '';
$contract_type = $_POST['contract_type'] ?? '';
$contract_id   = (int)($_POST['contract_id'] ?? 0);

$type_to_table = [
    'zlecenie'    => 'umowy_zlecenie',
    'uslugi'      => 'umowy_uslugi',
    'wolontariat' => 'umowy_wolontariat',
    'dzielo'      => 'umowy_dzielo',
    'praca'       => 'umowy_praca',
    'inne'        => 'umowy_inne',
];

function json_err_at(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

function json_ok_at(array $data = []): never {
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

if (!$contract_type || !isset($type_to_table[$contract_type]) || !$contract_id) {
    json_err_at('Nieprawidłowe parametry.');
}

$table = $type_to_table[$contract_type];
$row   = db_one("SELECT * FROM {$table} WHERE id = ?", [$contract_id]);
if (!$row) json_err_at('Umowa nie istnieje.', 404);
if (!can_edit()) json_err_at('Brak uprawnień do edycji.', 403);
if (($row['forma_podpisania'] ?? '') !== 'elektroniczna') json_err_at('Autenti dostępne tylko dla formy podpisania: Elektroniczna.');
if (!autenti_is_enabled()) json_err_at('Integracja Autenti nie jest aktywna.');

$at = new AutentiClient();
if (!$at->is_configured()) json_err_at('Autenti nie jest skonfigurowane. Sprawdź ustawienia w panelu Admin.');

// ── WYŚLIJ ────────────────────────────────────────────────────────────────────
if ($action === 'send') {
    $signer_name  = trim($_POST['signer_name']  ?? $row['imie_nazwisko'] ?? $row['nazwa_wykonawcy'] ?? '');
    $signer_email = trim($_POST['signer_email'] ?? $row['autenti_signer_email'] ?? $row['email'] ?? '');

    if (!$signer_email || !filter_var($signer_email, FILTER_VALIDATE_EMAIL)) {
        json_err_at('Podaj prawidłowy adres e-mail podpisującego.');
    }
    if (!$signer_name) {
        json_err_at('Podaj imię i nazwisko podpisującego.');
    }

    $pdf_rel = $row['plik_umowy'] ?? '';
    if (!$pdf_rel) json_err_at('Brak pliku umowy (PDF). Przed wysłaniem do Autenti wgraj plik umowy.');

    $pdf_path = UPLOAD_DIR . $pdf_rel;
    if (!file_exists($pdf_path)) json_err_at('Plik umowy nie istnieje na dysku: ' . $pdf_rel);

    $ext = strtolower(pathinfo($pdf_path, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') json_err_at('Autenti obsługuje tylko pliki PDF. Wgraj plik w formacie PDF.');

    try {
        $doc_name   = 'Umowa ' . ($row['numer_umowy'] ?? $contract_id);
        $doc_id     = $at->send_document($pdf_path, $signer_name, $signer_email, $doc_name);

        db_update($table, [
            'autenti_document_id'  => $doc_id,
            'autenti_status'       => 'IN_PROGRESS',
            'autenti_signer_email' => $signer_email,
            'autenti_signer_name'  => $signer_name,
            'forma_podpisania'     => 'autenti',
            'platforma_el'         => 'Autenti',
            'updated_at'           => date('Y-m-d H:i:s'),
        ], $contract_id);

        log_contract_action($contract_type, $contract_id, current_user()['id'],
            'autenti_send', "Wysłano do Autenti ({$signer_email}), document_id: {$doc_id}");

        json_ok_at([
            'document_id' => $doc_id,
            'msg'         => "Dokument wysłany pomyślnie na adres {$signer_email}.",
        ]);
    } catch (\Throwable $e) {
        error_log('Autenti send error: ' . $e->getMessage());
        json_err_at('Błąd Autenti: ' . $e->getMessage());
    }
}

// ── STATUS ────────────────────────────────────────────────────────────────────
if ($action === 'status') {
    $doc_id = $row['autenti_document_id'] ?? '';
    if (!$doc_id) json_err_at('Brak ID dokumentu — najpierw wyślij dokument.');

    try {
        $status = $at->get_status($doc_id);
        db_update($table, ['autenti_status' => $status, 'updated_at' => date('Y-m-d H:i:s')], $contract_id);

        log_contract_action($contract_type, $contract_id, current_user()['id'],
            'autenti_status', "Status zaktualizowany: {$status}");

        $label = AUTENTI_STATUS_LABELS[$status] ?? $status;
        $badge = AUTENTI_STATUS_BADGES[$status] ?? 'bg-secondary';
        json_ok_at(['status' => $status, 'label' => $label, 'badge' => $badge]);
    } catch (\Throwable $e) {
        json_err_at('Błąd pobierania statusu: ' . $e->getMessage());
    }
}

// ── ANULUJ ────────────────────────────────────────────────────────────────────
if ($action === 'cancel') {
    require_role('admin', 'editor');
    $doc_id = $row['autenti_document_id'] ?? '';
    if (!$doc_id) json_err_at('Brak ID dokumentu.');

    try {
        $at->cancel_document($doc_id);
        db_update($table, ['autenti_status' => 'CANCELLED', 'updated_at' => date('Y-m-d H:i:s')], $contract_id);

        log_contract_action($contract_type, $contract_id, current_user()['id'],
            'autenti_cancel', 'Dokument Autenti anulowany.');

        json_ok_at(['msg' => 'Dokument anulowany.']);
    } catch (\Throwable $e) {
        json_err_at('Błąd anulowania: ' . $e->getMessage());
    }
}

json_err_at('Nieznana akcja.');
