<?php
/**
 * Obsługa akcji Postivo.pl dla pism (letters).
 *
 * Akcje POST:
 *   - send           — wysyła list przez API Postivo.pl
 *   - refresh_status — odświeża status zlecenia z Postivo.pl
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/letters.php';
require_once dirname(dirname(__DIR__)) . '/includes/postivo.php';

require_login();
if (!can_edit()) {
    http_response_code(403);
    die('Brak uprawnień.');
}
require_module_enabled('letters_enabled', 'Moduł pism');

// Tylko POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/contracts/letters/index.php');
    exit;
}

csrf_check();

$letter_id = intval($_POST['letter_id'] ?? 0);
$action    = $_POST['postivo_action'] ?? '';

if (!$letter_id) {
    flash_set('error', 'Nieprawidłowe ID pisma.');
    header('Location: ' . APP_URL . '/contracts/letters/index.php');
    exit;
}

$letter = get_letter($letter_id);
if (!$letter) {
    http_response_code(404);
    die('Nie znaleziono pisma.');
}

$redirect = APP_URL . '/contracts/letters/view.php?id=' . $letter_id;

// ── Sprawdź czy Postivo jest włączone ─────────────────────────────────────────
if (postivo_setting('postivo_enabled') !== '1') {
    flash_set('error', 'Integracja z Postivo.pl jest wyłączona. Skonfiguruj ją w Administracja → Postivo (poczta).');
    header('Location: ' . $redirect);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Akcja: send — wyślij list
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'send') {
    // Pismo musi mieć załączony PDF
    if (empty($letter['plik'])) {
        flash_set('error', 'Aby wysłać listem, pismo musi mieć załączony plik PDF.');
        header('Location: ' . $redirect);
        exit;
    }

    // Nie wysyłaj ponownie jeśli już wysłano
    if (!empty($letter['postivo_job_id'])) {
        flash_set('error', 'To pismo zostało już nadane przez Postivo.pl (ID: ' . $letter['postivo_job_id'] . ').');
        header('Location: ' . $redirect);
        exit;
    }

    // Walidacja danych adresowych
    $recipient_name = trim($_POST['recipient_name'] ?? '');
    $address_line1  = trim($_POST['address_line1']  ?? '');
    $address_line2  = trim($_POST['address_line2']  ?? '');
    $city           = trim($_POST['city']           ?? '');
    $postcode       = trim($_POST['postcode']       ?? '');
    $country        = trim($_POST['country']        ?? 'PL');

    $errors = [];
    if (!$recipient_name) $errors[] = 'Imię i nazwisko / nazwa odbiorcy jest wymagana.';
    if (!$address_line1)  $errors[] = 'Adres (linia 1) jest wymagany.';
    if (!$city)           $errors[] = 'Miasto jest wymagane.';
    if (!$postcode)       $errors[] = 'Kod pocztowy jest wymagany.';

    // Walidacja kodu pocztowego (format XX-XXX dla PL)
    if ($postcode && $country === 'PL' && !preg_match('/^\d{2}-\d{3}$/', $postcode)) {
        $errors[] = 'Kod pocztowy powinien być w formacie XX-XXX (np. 00-001).';
    }

    if ($errors) {
        flash_set('error', implode(' ', $errors));
        header('Location: ' . $redirect);
        exit;
    }

    // Ścieżka do pliku PDF
    $pdf_path = UPLOAD_DIR . $letter['plik'];
    if (!file_exists($pdf_path)) {
        flash_set('error', 'Nie znaleziono pliku PDF pisma na serwerze. Sprawdź czy plik istnieje.');
        header('Location: ' . $redirect);
        exit;
    }

    // Wyślij przez Postivo.pl
    try {
        $client = new PostivoClient();

        $result = $client->send_letter([
            'recipient_name' => $recipient_name,
            'address_line1'  => $address_line1,
            'address_line2'  => $address_line2,
            'city'           => $city,
            'postcode'       => $postcode,
            'country'        => $country,
            'pdf_path'       => $pdf_path,
        ]);

        $postivo_id = $result['id'];

        // Zapisz dane zlecenia w bazie
        db()->prepare(
            "UPDATE contract_letters
             SET postivo_job_id       = ?,
                 postivo_status       = 'draft',
                 postivo_sent_at      = datetime('now'),
                 postivo_adres        = ?,
                 postivo_kod_pocztowy = ?,
                 postivo_miasto       = ?,
                 updated_at           = datetime('now')
             WHERE id = ?"
        )->execute([
            $postivo_id,
            $address_line1 . ($address_line2 ? "\n" . $address_line2 : ''),
            $postcode,
            $city,
            $letter_id,
        ]);

        flash_set('success', 'List nadany przez Postivo.pl. Identyfikator zlecenia: ' . $postivo_id);

    } catch (RuntimeException $e) {
        flash_set('error', 'Błąd wysyłki Postivo.pl: ' . $e->getMessage());
    }

    header('Location: ' . $redirect);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Akcja: refresh_status — odśwież status zlecenia
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'refresh_status') {
    if (empty($letter['postivo_job_id'])) {
        flash_set('error', 'Brak identyfikatora Postivo.pl dla tego pisma.');
        header('Location: ' . $redirect);
        exit;
    }

    try {
        $client = new PostivoClient();

        $status_data = $client->get_status($letter['postivo_job_id']);

        db()->prepare(
            "UPDATE contract_letters
             SET postivo_status = ?,
                 updated_at     = datetime('now')
             WHERE id = ?"
        )->execute([
            $status_data['status'],
            $letter_id,
        ]);

        $tracking_msg = $status_data['tracking']
            ? ' Numer śledzenia: ' . $status_data['tracking'] . '.'
            : '';
        flash_set('success', 'Status zaktualizowany: ' . $status_data['status'] . '.' . $tracking_msg);

    } catch (RuntimeException $e) {
        flash_set('error', 'Błąd odświeżania statusu Postivo.pl: ' . $e->getMessage());
    }

    header('Location: ' . $redirect);
    exit;
}

// Nieznana akcja
flash_set('error', 'Nieznana akcja Postivo.');
header('Location: ' . $redirect);
exit;
