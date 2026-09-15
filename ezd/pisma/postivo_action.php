<?php
/**
 * Obsługa akcji Postivo.pl dla pism EZD.
 *
 * Akcje POST:
 *   send           — wysyła pismo przez Postivo.pl (wymaga PDF w załączniku)
 *   refresh_status — odświeża status zlecenia
 *   cancel         — anuluje zlecenie
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/postivo.php';
require_once dirname(dirname(__DIR__)) . '/includes/postivo_cover.php';

require_login();
ezd_require_access();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/ezd/index.php');
    exit;
}

csrf_check();

$pismo_id = (int)($_POST['pismo_id'] ?? 0);
$action   = $_POST['postivo_action'] ?? '';

if (!$pismo_id) {
    flash_set('error', 'Nieprawidłowe ID pisma.');
    header('Location: ' . APP_URL . '/ezd/pisma/');
    exit;
}

$pismo = db_one("SELECT * FROM ezd_pisma WHERE id=?", [$pismo_id]);
if (!$pismo) {
    http_response_code(404);
    die('Nie znaleziono pisma.');
}

// Sprawdź dostęp do sprawy
$sprawa = db_one("SELECT * FROM ezd_sprawy WHERE id=?", [$pismo['sprawa_id']]);
$access = $sprawa ? ezd_sprawa_access($sprawa, current_user()['id']) : null;
if (!$access || $access === 'read') {
    flash_set('error', 'Brak uprawnień do tej sprawy.');
    header('Location: ' . APP_URL . '/ezd/pisma/view.php?id=' . $pismo_id);
    exit;
}

$redirect = APP_URL . '/ezd/pisma/view.php?id=' . $pismo_id;

if (postivo_setting('postivo_enabled') !== '1') {
    flash_set('error', 'Integracja z Postivo.pl jest wyłączona. Skonfiguruj ją w Administracja → Postivo (poczta).');
    header('Location: ' . $redirect);
    exit;
}

// ── send ─────────────────────────────────────────────────────────────────────
if ($action === 'send') {
    if (!empty($pismo['postivo_job_id'])) {
        flash_set('error', 'To pismo zostało już nadane przez Postivo.pl (ID: ' . $pismo['postivo_job_id'] . ').');
        header('Location: ' . $redirect);
        exit;
    }

    // Wymagany PDF — szukamy w ezd_zalaczniki dla tego pisma
    $pdf_zal = db_one(
        "SELECT * FROM ezd_zalaczniki WHERE pismo_id=? AND mime_type='application/pdf' ORDER BY id DESC LIMIT 1",
        [$pismo_id]
    );
    if (!$pdf_zal) {
        flash_set('error', 'Aby wysłać listem, pismo musi mieć załączony plik PDF.');
        header('Location: ' . $redirect);
        exit;
    }
    $pdf_path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $pdf_zal['sprawa_id'] . '/' . $pdf_zal['filename'];
    if (!file_exists($pdf_path)) {
        flash_set('error', 'Nie znaleziono pliku PDF na serwerze.');
        header('Location: ' . $redirect);
        exit;
    }

    // Opcjonalne pismo dołączone do TEJ SAMEJ przesyłki (jedna koperta, jedno
    // zlecenie Postivo) — np. pismo przewodnie + załącznik jako osobne wpisy
    // w rejestrze, ale fizycznie jeden list.
    $companion = null;
    $companion_pdf_path = null;
    $companion_id = (int)($_POST['companion_pismo_id'] ?? 0) ?: null;
    if ($companion_id) {
        $companion = db_one(
            "SELECT * FROM ezd_pisma WHERE id=? AND sprawa_id=? AND kierunek='wychodzace'
               AND (postivo_job_id IS NULL OR postivo_job_id='')",
            [$companion_id, $pismo['sprawa_id']]
        );
        if (!$companion) {
            flash_set('error', 'Wybrane pismo do dołączenia jest niedostępne (już nadane albo z innej sprawy).');
            header('Location: ' . $redirect);
            exit;
        }
        $companion_zal = db_one(
            "SELECT * FROM ezd_zalaczniki WHERE pismo_id=? AND mime_type='application/pdf' ORDER BY id DESC LIMIT 1",
            [$companion_id]
        );
        if (!$companion_zal) {
            flash_set('error', 'Dołączane pismo nie ma dostępnego pliku PDF.');
            header('Location: ' . $redirect);
            exit;
        }
        $companion_pdf_path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $companion_zal['sprawa_id'] . '/' . $companion_zal['filename'];
        if (!file_exists($companion_pdf_path)) {
            flash_set('error', 'Dołączane pismo nie ma dostępnego pliku PDF.');
            header('Location: ' . $redirect);
            exit;
        }
    }

    $recipient_name = trim($_POST['recipient_name'] ?? $pismo['odbiorca'] ?? '');
    $address_line1  = trim($_POST['address_line1']  ?? '');
    $address_line2  = trim($_POST['address_line2']  ?? '');
    $home_number    = trim($_POST['home_number']    ?? '');
    $flat_number    = trim($_POST['flat_number']    ?? '');
    $city           = trim($_POST['city']           ?? '');
    $postcode       = trim($_POST['postcode']       ?? '');
    $phone_number   = trim($_POST['phone_number']   ?? '');
    $country        = trim($_POST['country']        ?? 'PL');
    $doc_title      = trim($_POST['doc_title']      ?? $pismo['title'] ?? '');
    $doc_reason     = trim($_POST['doc_reason']     ?? '');

    $errors = [];
    if (!$recipient_name) $errors[] = 'Nazwa odbiorcy jest wymagana.';
    if (!$address_line1)  $errors[] = 'Adres jest wymagany.';
    if (!$city)           $errors[] = 'Miasto jest wymagane.';
    if (!$postcode)       $errors[] = 'Kod pocztowy jest wymagany.';
    if (!$doc_title)      $errors[] = 'Opis dokumentu jest wymagany.';
    if (!$doc_reason)     $errors[] = 'Powód wysyłki jest wymagany.';
    if ($postcode && $country === 'PL' && !preg_match('/^\d{2}-\d{3}$/', $postcode)) {
        $errors[] = 'Kod pocztowy — format XX-XXX (np. 00-001).';
    }

    if ($errors) {
        flash_set('error', implode(' ', $errors));
        header('Location: ' . $redirect);
        exit;
    }

    // Generuj scalony PDF: cover page + oryginał (+ ewentualnie dołączone pismo)
    $referent_name = db_one("SELECT name FROM users WHERE id=?", [$pismo['owner_id'] ?? 0])['name'] ?? '';
    $originals     = $companion_pdf_path ? [$pdf_path, $companion_pdf_path] : [$pdf_path];
    $merged_pdf    = null;
    try {
        $merged_pdf = postivo_build_cover_pdf($originals, [
            'doc_title'   => $doc_title,
            'doc_reason'  => $doc_reason,
            'recipient'   => $recipient_name,
            'sygnatura'   => $pismo['sygnatura'] ?? '',
            'data_pisma'  => $pismo['data_pisma'] ?? '',
            'referent'    => $referent_name,
            'org_name'    => defined('ORG_NAME')    ? ORG_NAME    : '',
            'org_address' => defined('ORG_ADDRESS')  ? ORG_ADDRESS  : '',
            'org_email'   => defined('ORG_EMAIL')    ? ORG_EMAIL    : '',
        ]);
        $send_path = $merged_pdf;
    } catch (\Throwable $e) {
        // Jeśli generowanie cover page (albo scalenie z dołączonym pismem) nie
        // powiedzie się — wyślij sam oryginał; dołączone pismo zostaje nienadane,
        // zamiast wysyłać niekompletną przesyłkę bez wyjaśnienia.
        $send_path = $pdf_path;
        $companion = null;
    }

    try {
        $client = new PostivoClient();
        $result = $client->send_letter([
            'recipient_name' => $recipient_name,
            'address_line1'  => $address_line1 . ($home_number ? ' ' . $home_number : ''),
            'address_line2'  => $address_line2 ?: null,
            'home_number'    => $home_number   ?: null,
            'flat_number'    => $flat_number   ?: null,
            'phone_number'   => $phone_number  ?: null,
            'city'           => $city,
            'postcode'       => $postcode,
            'country'        => $country,
            'pdf_path'       => $send_path,
        ]);

        $postivo_id  = $result['id'];
        $addr_full   = $address_line1 . ($address_line2 ? "\n" . $address_line2 : '');
        // Jedna koperta = jedno zlecenie: obie pozycje rejestru (pismo główne
        // i dołączone, jeśli scalenie się powiodło) dostają ten sam postivo_job_id,
        // żeby dziennik pokazywał je jako wysłane RAZEM, a nie dwa osobne listy.
        $ids_to_mark = $companion ? [$pismo_id, (int)$companion['id']] : [$pismo_id];
        foreach ($ids_to_mark as $pid) {
            db()->prepare(
                "UPDATE ezd_pisma SET
                   postivo_job_id       = ?,
                   postivo_status       = 'draft',
                   postivo_sent_at      = datetime('now'),
                   postivo_adres        = ?,
                   postivo_kod_pocztowy = ?,
                   postivo_miasto       = ?,
                   updated_at           = datetime('now')
                 WHERE id = ?"
            )->execute([$postivo_id, $addr_full, $postcode, $city, $pid]);
        }

        $uid = (int)current_user()['id'];
        if ($companion) {
            ezd_log(null, (int)$pismo['sprawa_id'], $pismo_id, null, $uid, 'postivo_wyslano',
                'Nadano pismo przez Postivo.pl razem z pismem ' . ($companion['sygnatura'] ?: '#' . $companion['id'])
                . ' (jedna przesyłka), ID: ' . $postivo_id);
            ezd_log(null, (int)$pismo['sprawa_id'], (int)$companion['id'], null, $uid, 'postivo_wyslano',
                'Nadano pismo przez Postivo.pl razem z pismem ' . ($pismo['sygnatura'] ?: '#' . $pismo_id)
                . ' (jedna przesyłka), ID: ' . $postivo_id);
            flash_set('success', 'List nadany przez Postivo.pl razem z pismem '
                . ($companion['sygnatura'] ?: '#' . $companion['id']) . '. ID zlecenia: ' . $postivo_id);
        } else {
            ezd_log(null, (int)$pismo['sprawa_id'], $pismo_id, null, $uid,
                'postivo_wyslano', 'Nadano pismo przez Postivo.pl, ID: ' . $postivo_id);
            flash_set('success', 'List nadany przez Postivo.pl. ID zlecenia: ' . $postivo_id);
        }
    } catch (RuntimeException $e) {
        flash_set('error', 'Błąd wysyłki Postivo.pl: ' . $e->getMessage());
    } finally {
        if ($merged_pdf && file_exists($merged_pdf)) {
            @unlink($merged_pdf);
        }
    }

    header('Location: ' . $redirect);
    exit;
}

// ── refresh_status ────────────────────────────────────────────────────────────
if ($action === 'refresh_status') {
    if (empty($pismo['postivo_job_id'])) {
        flash_set('error', 'Brak identyfikatora Postivo.pl.');
        header('Location: ' . $redirect);
        exit;
    }

    try {
        $client      = new PostivoClient();
        $status_data = $client->get_status($pismo['postivo_job_id']);
        $old_status  = $pismo['postivo_status'] ?? '';
        $uid         = (int)current_user()['id'];

        // Jeden postivo_job_id może siedzieć na kilku pismach (jedna koperta) —
        // status dotyczy całej przesyłki, więc aktualizujemy WSZYSTKIE naraz,
        // żeby dziennik nie pokazywał różnych statusów dla tego samego listu.
        $siblings = db_all("SELECT id, sprawa_id FROM ezd_pisma WHERE postivo_job_id=?", [$pismo['postivo_job_id']]);
        db()->prepare(
            "UPDATE ezd_pisma SET postivo_status=?, updated_at=datetime('now') WHERE postivo_job_id=?"
        )->execute([$status_data['status'], $pismo['postivo_job_id']]);

        if ($status_data['status'] !== $old_status) {
            foreach ($siblings as $sib) {
                ezd_log(null, (int)$sib['sprawa_id'], (int)$sib['id'], null, $uid,
                    'postivo_status', 'Status Postivo.pl: ' . ($old_status ?: '—') . ' → ' . $status_data['status']
                    . ($status_data['tracking'] ? ' (nr śledzenia: ' . $status_data['tracking'] . ')' : ''));
            }
        }

        $track = $status_data['tracking'] ? ' Nr śledzenia: ' . $status_data['tracking'] . '.' : '';
        flash_set('success', 'Status zaktualizowany: ' . $status_data['status'] . '.' . $track);
    } catch (RuntimeException $e) {
        flash_set('error', 'Błąd odświeżania statusu Postivo.pl: ' . $e->getMessage());
    }

    header('Location: ' . $redirect);
    exit;
}

// ── cancel ────────────────────────────────────────────────────────────────────
if ($action === 'cancel') {
    if (empty($pismo['postivo_job_id'])) {
        flash_set('error', 'Brak identyfikatora Postivo.pl.');
        header('Location: ' . $redirect);
        exit;
    }
    if (!in_array($pismo['postivo_status'] ?? '', ['draft', 'processing', 'unknown'], true)) {
        flash_set('error', 'Nie można anulować przesyłki o statusie: ' . ($pismo['postivo_status'] ?? '?'));
        header('Location: ' . $redirect);
        exit;
    }

    try {
        $client = new PostivoClient();
        $client->cancel($pismo['postivo_job_id']);

        // Anulowanie dotyczy całej przesyłki (koperty) — jeśli inne pismo
        // dzieli z tym ten sam postivo_job_id, ono też przestaje być nadane.
        $siblings = db_all("SELECT id, sprawa_id FROM ezd_pisma WHERE postivo_job_id=?", [$pismo['postivo_job_id']]);
        db()->prepare(
            "UPDATE ezd_pisma SET postivo_status='cancelled', updated_at=datetime('now') WHERE postivo_job_id=?"
        )->execute([$pismo['postivo_job_id']]);

        $uid = (int)current_user()['id'];
        foreach ($siblings as $sib) {
            ezd_log(null, (int)$sib['sprawa_id'], (int)$sib['id'], null, $uid,
                'postivo_anulowano', 'Anulowano zlecenie Postivo.pl: ' . $pismo['postivo_job_id']);
        }
        flash_set('success', 'Zlecenie Postivo.pl anulowane.');
    } catch (RuntimeException $e) {
        flash_set('error', 'Błąd anulowania Postivo.pl: ' . $e->getMessage());
    }

    header('Location: ' . $redirect);
    exit;
}

flash_set('error', 'Nieznana akcja Postivo.');
header('Location: ' . $redirect);
exit;
