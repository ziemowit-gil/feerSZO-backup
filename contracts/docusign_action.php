<?php
/**
 * contracts/docusign_action.php — Akcje DocuSign (AJAX).
 *
 * POST ?action=send     — wyślij kopertę do podpisu
 * POST ?action=status   — odśwież status koperty
 * POST ?action=void     — unieważnij kopertę
 *
 * Parametry POST: contract_type, contract_id, [signer_email, signer_name, void_reason]
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/docusign.php';
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

function json_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

function json_ok(array $data = []): never {
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

if (!$contract_type || !isset($type_to_table[$contract_type]) || !$contract_id) {
    json_err('Nieprawidłowe parametry.');
}

$table = $type_to_table[$contract_type];
$row   = db_one("SELECT * FROM {$table} WHERE id = ?", [$contract_id]);
if (!$row) json_err('Umowa nie istnieje.', 404);
if (!can_edit()) json_err('Brak uprawnień do edycji.', 403);
if (($row['forma_podpisania'] ?? '') !== 'elektroniczna') json_err('DocuSign dostępny tylko dla formy podpisania: Elektroniczna.');
if (!docusign_is_enabled()) json_err('Integracja DocuSign nie jest aktywna.');

$ds = new DocuSignClient();
if (!$ds->is_configured()) json_err('DocuSign nie jest skonfigurowany. Sprawdź ustawienia w panelu Admin.');

// ── WYŚLIJ ────────────────────────────────────────────────────────────────────
if ($action === 'send') {
    $signer_name  = trim($_POST['signer_name']  ?? $row['imie_nazwisko'] ?? $row['nazwa_wykonawcy'] ?? '');
    $signer_email = trim($_POST['signer_email'] ?? $row['docusign_signer_email'] ?? $row['email'] ?? '');

    if (!$signer_email || !filter_var($signer_email, FILTER_VALIDATE_EMAIL)) {
        json_err('Podaj prawidłowy adres e-mail podpisującego.');
    }
    if (!$signer_name) {
        json_err('Podaj imię i nazwisko podpisującego.');
    }

    // Wymagany plik PDF — plik_umowy lub plik_potwierdzenia
    $pdf_rel = $row['plik_umowy'] ?? '';
    if (!$pdf_rel) json_err('Brak pliku umowy (PDF). Przed wysłaniem do DocuSign wgraj plik umowy.');

    $pdf_path = UPLOAD_DIR . $pdf_rel;
    if (!file_exists($pdf_path)) json_err('Plik umowy nie istnieje na dysku: ' . $pdf_rel);

    $ext = strtolower(pathinfo($pdf_path, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') json_err('DocuSign obsługuje tylko pliki PDF. Wgraj plik w formacie PDF.');

    try {
        $doc_name    = 'Umowa ' . ($row['numer_umowy'] ?? $contract_id);
        $envelope_id = $ds->send_envelope($pdf_path, $signer_name, $signer_email, $doc_name);

        db_update($table, [
            'forma_podpisania'      => 'docusign',
            'platforma_el'          => 'DocuSign',
            'id_dokumentu_el'       => $envelope_id,
            'docusign_status'       => 'sent',
            'docusign_signer_email' => $signer_email,
            'docusign_signer_name'  => $signer_name,
            'updated_at'            => date('Y-m-d H:i:s'),
        ], $contract_id);

        log_contract_action($contract_type, $contract_id, current_user()['id'],
            'docusign_send', "Wysłano do DocuSign ({$signer_email}), envelope: {$envelope_id}");

        json_ok([
            'envelope_id' => $envelope_id,
            'msg'         => "Koperta wysłana pomyślnie na adres {$signer_email}.",
        ]);
    } catch (\Throwable $e) {
        error_log('DocuSign send error: ' . $e->getMessage());
        json_err('Błąd DocuSign: ' . $e->getMessage());
    }
}

// ── STATUS ────────────────────────────────────────────────────────────────────
if ($action === 'status') {
    $envelope_id = $row['id_dokumentu_el'] ?? '';
    if (!$envelope_id) json_err('Brak ID koperty — najpierw wyślij dokument.');

    try {
        $status = $ds->get_status($envelope_id);
        db_update($table, ['docusign_status' => $status, 'updated_at' => date('Y-m-d H:i:s')], $contract_id);

        log_contract_action($contract_type, $contract_id, current_user()['id'],
            'docusign_status', "Status zaktualizowany: {$status}");

        $label = DOCUSIGN_STATUS_LABELS[$status] ?? $status;
        $badge = DOCUSIGN_STATUS_BADGES[$status] ?? 'bg-secondary';
        json_ok(['status' => $status, 'label' => $label, 'badge' => $badge]);
    } catch (\Throwable $e) {
        json_err('Błąd pobierania statusu: ' . $e->getMessage());
    }
}

// ── UNIEWAŻNIJ ────────────────────────────────────────────────────────────────
if ($action === 'void') {
    require_role('admin', 'editor');
    $envelope_id = $row['id_dokumentu_el'] ?? '';
    if (!$envelope_id) json_err('Brak ID koperty.');

    $reason = trim($_POST['void_reason'] ?? 'Anulowane przez administratora');

    try {
        $ds->void_envelope($envelope_id, $reason);
        db_update($table, ['docusign_status' => 'voided', 'updated_at' => date('Y-m-d H:i:s')], $contract_id);

        log_contract_action($contract_type, $contract_id, current_user()['id'],
            'docusign_void', "Koperta unieważniona. Powód: {$reason}");

        json_ok(['msg' => 'Koperta unieważniona.']);
    } catch (\Throwable $e) {
        json_err('Błąd unieważniania: ' . $e->getMessage());
    }
}

json_err('Nieznana akcja.');
