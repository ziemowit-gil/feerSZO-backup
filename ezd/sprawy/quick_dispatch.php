<?php
/**
 * ezd/sprawy/quick_dispatch.php — szybka rejestracja w wychodzących z listy
 * dokumentów koszulki: zaznaczone pliki → JEDNO nowe pismo wychodzące → JEDEN
 * wpis w książce nadawczej (RPW-W) → opcjonalnie od razu wysyłka przez
 * Postivo.pl (merge wszystkich zaznaczonych PDF-ów w jedną kopertę).
 *
 * Patrz includes/ezd_rpwy.php::ezd_rpwy_quick_dispatch().
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_rpwy.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/ezd/index.php'); exit;
}
csrf_check();

$sprawa_id = (int)($_POST['sprawa_id'] ?? 0);
$sprawa    = $sprawa_id ? ezd_sprawa_get($sprawa_id) : null;
$redirect  = APP_URL . '/ezd/sprawy/view.php?id=' . $sprawa_id . '#files';

if (!$sprawa) { flash_set('error', 'Koszulka nie istnieje.'); header('Location: ' . APP_URL . '/ezd/index.php'); exit; }

$user_id = (int)current_user()['id'];
$access  = ezd_sprawa_access($sprawa, $user_id);
$can_act = ($access === 'write') && ($sprawa['status'] !== 'closed');
if (!$can_act) { flash_set('error', 'Brak uprawnień do koszulki.'); header('Location: ' . $redirect); exit; }

$zal_ids     = array_map('intval', (array)($_POST['zal_ids'] ?? []));
$title       = trim($_POST['title']    ?? '');
$odbiorca    = trim($_POST['odbiorca'] ?? '');
$sposob      = $_POST['sposob'] ?? 'zwykly';
$via_postivo = !empty($_POST['send_postivo']);

if (!$zal_ids) { flash_set('error', 'Nie zaznaczono żadnego pliku.'); header('Location: ' . $redirect); exit; }
if (!array_key_exists($sposob, EZD_RPWY_SPOSOBY)) $sposob = 'zwykly';

try {
    $res      = ezd_rpwy_quick_dispatch($sprawa_id, $zal_ids, $title, $odbiorca, $sposob, $user_id);
    $pismo_id = $res['pismo_id'];
} catch (\Throwable $e) {
    flash_set('error', 'Nie udało się zarejestrować przesyłki: ' . $e->getMessage());
    header('Location: ' . $redirect); exit;
}

$msg = 'Zarejestrowano w wychodzących: ' . ezd_rpwy_label($res) . ' (' . count($zal_ids) . ' plik(ów) w jednej przesyłce).';

if ($via_postivo) {
    require_once dirname(dirname(__DIR__)) . '/includes/postivo.php';
    require_once dirname(dirname(__DIR__)) . '/includes/postivo_cover.php';

    if (postivo_setting('postivo_enabled') !== '1') {
        flash_set('info', $msg . ' Wysyłka przez Postivo.pl jest wyłączona (Administracja → Postivo) — nadaj ręcznie.');
        header('Location: ' . $redirect); exit;
    }

    $pdf_files = db_all(
        "SELECT filename FROM ezd_zalaczniki WHERE pismo_id=? AND mime_type='application/pdf' ORDER BY id",
        [$pismo_id]
    );
    $pdf_paths = array_map(fn($z) => UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $sprawa_id . '/' . $z['filename'], $pdf_files);
    $pdf_paths = array_values(array_filter($pdf_paths, 'file_exists'));

    if (!$pdf_paths) {
        flash_set('info', $msg . ' Żaden z zaznaczonych plików nie jest PDF-em — Postivo wymaga PDF. Nadaj ręcznie z widoku pisma.');
        header('Location: ' . $redirect); exit;
    }

    $recipient_name = trim($_POST['recipient_name'] ?? $odbiorca);
    $address_line1  = trim($_POST['address_line1']  ?? '');
    $address_line2  = trim($_POST['address_line2']  ?? '');
    $home_number    = trim($_POST['home_number']    ?? '');
    $flat_number    = trim($_POST['flat_number']    ?? '');
    $city           = trim($_POST['city']           ?? '');
    $postcode       = trim($_POST['postcode']       ?? '');
    $phone_number   = trim($_POST['phone_number']   ?? '');
    $country        = trim($_POST['country']        ?? 'PL');
    $doc_reason     = trim($_POST['doc_reason']     ?? 'Korespondencja urzędowa');

    $errors = [];
    if (!$recipient_name) $errors[] = 'nazwa odbiorcy';
    if (!$address_line1)  $errors[] = 'adres';
    if (!$city)           $errors[] = 'miasto';
    if (!$postcode)       $errors[] = 'kod pocztowy';
    if ($postcode && $country === 'PL' && !preg_match('/^\d{2}-\d{3}$/', $postcode)) $errors[] = 'kod pocztowy w formacie XX-XXX';

    if ($errors) {
        flash_set('info', $msg . ' Wysyłki Postivo NIE wykonano — brakuje: ' . implode(', ', $errors) . '. Nadaj ręcznie z widoku pisma (uzupełnij adres).');
        header('Location: ' . $redirect); exit;
    }

    $merged_pdf = null;
    try {
        $referent_name = db_one("SELECT name FROM users WHERE id=?", [$sprawa['owner_id'] ?? 0])['name'] ?? '';
        $merged_pdf = postivo_build_cover_pdf($pdf_paths, [
            'doc_title'   => $title,
            'doc_reason'  => $doc_reason,
            'recipient'   => $recipient_name,
            'sygnatura'   => $sprawa['znak_sprawy'] ?? '',
            'data_pisma'  => date('Y-m-d'),
            'referent'    => $referent_name,
            'org_name'    => defined('ORG_NAME')    ? ORG_NAME    : '',
            'org_address' => defined('ORG_ADDRESS') ? ORG_ADDRESS : '',
            'org_email'   => defined('ORG_EMAIL')   ? ORG_EMAIL   : '',
        ]);

        $client = new PostivoClient();
        $result = $client->send_letter([
            'recipient_name' => $recipient_name,
            'address_line1'  => $address_line1 . ($home_number ? ' ' . $home_number : ''),
            'address_line2'  => $address_line2 ?: null,
            'home_number'    => $home_number    ?: null,
            'flat_number'    => $flat_number    ?: null,
            'phone_number'   => $phone_number   ?: null,
            'city'           => $city,
            'postcode'       => $postcode,
            'country'        => $country,
            'pdf_path'       => $merged_pdf,
        ]);

        $postivo_id = $result['id'];
        $addr_full  = $address_line1 . ($address_line2 ? "\n" . $address_line2 : '');
        db()->prepare(
            "UPDATE ezd_pisma SET postivo_job_id=?, postivo_status='draft', postivo_sent_at=datetime('now'),
             postivo_adres=?, postivo_kod_pocztowy=?, postivo_miasto=?, updated_at=datetime('now') WHERE id=?"
        )->execute([$postivo_id, $addr_full, $postcode, $city, $pismo_id]);

        ezd_log(null, $sprawa_id, $pismo_id, null, $user_id, 'postivo_wyslano',
            'Nadano przez Postivo.pl (szybka wysyłka, ' . count($pdf_paths) . ' plik(ów) scalonych), ID: ' . $postivo_id);
        flash_set('success', $msg . ' Nadano przez Postivo.pl, ID zlecenia: ' . $postivo_id . '.');
    } catch (\Throwable $e) {
        flash_set('error', $msg . ' Rejestracja OK, ale wysyłka Postivo nie powiodła się: ' . $e->getMessage());
    } finally {
        if ($merged_pdf && file_exists($merged_pdf)) @unlink($merged_pdf);
    }
} else {
    flash_set('success', $msg);
}

header('Location: ' . $redirect); exit;
