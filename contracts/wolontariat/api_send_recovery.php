<?php
/**
 * AJAX — wyślij kod odzyskiwania dla umowy wolontariatu.
 * POST: { id, channel: 'sms'|'email' }
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/sms.php';
require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
require_once dirname(dirname(__DIR__)) . '/includes/email_templates.php';

require_role('admin', 'editor');
header('Content-Type: application/json; charset=utf-8');

$id      = (int)($_POST['id']      ?? 0);
$channel = $_POST['channel'] ?? '';
$code    = trim($_POST['code'] ?? ''); // kod plain (z sesji/flash, nie z bazy)

if (!$id || !in_array($channel, ['sms','email'], true) || !preg_match('/^\d{8}$/', $code)) {
    echo json_encode(['ok' => false, 'error' => 'Nieprawidłowe parametry.']);
    exit;
}

$row = db_one("SELECT imie_nazwisko, email, telefon FROM umowy_wolontariat WHERE id=?", [$id]);
if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'Umowa nie znaleziona.']);
    exit;
}

$org  = defined('ORG_NAME') ? ORG_NAME : '';
$name = $row['imie_nazwisko'] ?? '';
$msg  = "Kod odzyskiwania dostępu do systemu {$org}: {$code}\nZachowaj go w bezpiecznym miejscu.";

if ($channel === 'sms') {
    $phone = preg_replace('/\D/', '', $row['telefon'] ?? '');
    if (!$phone) {
        echo json_encode(['ok' => false, 'error' => 'Brak numeru telefonu w umowie.']);
        exit;
    }
    try {
        sms_send($phone, $msg);
        // Zapisz czas wysyłki
        db()->prepare("UPDATE umowy_wolontariat SET recovery_code_sent_at=? WHERE id=?")
           ->execute([date('Y-m-d H:i:s'), $id]);
        echo json_encode(['ok' => true, 'info' => 'SMS wysłany na numer z umowy.']);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Błąd wysyłki SMS: ' . $e->getMessage()]);
    }
    exit;
}

if ($channel === 'email') {
    $email = trim($row['email'] ?? '');
    if (!$email) {
        echo json_encode(['ok' => false, 'error' => 'Brak adresu e-mail w umowie.']);
        exit;
    }
    $rendered = email_tpl_render('recovery_code', [
        'org'  => htmlspecialchars($org),
        'name' => htmlspecialchars($name),
        'code' => htmlspecialchars($code),
    ]);
    mail_queue_add($email, $name, $rendered['subject'], $rendered['html'], '', 'wolontariat', $id);
    db()->prepare("UPDATE umowy_wolontariat SET recovery_code_sent_at=? WHERE id=?")
       ->execute([date('Y-m-d H:i:s'), $id]);
    echo json_encode(['ok' => true, 'info' => 'E-mail z kodem dodany do kolejki wysyłki.']);
    exit;
}
