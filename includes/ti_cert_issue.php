<?php
/**
 * includes/ti_cert_issue.php — wystawianie certyfikatu prowadzącego przez kierownika TI.
 *
 * Plik .p12 idzie do prowadzącego mailem jako załącznik, hasło do pliku SMS-em (rodzaj
 * 'document_code'). Hasło nie trafia do maila ani do bazy. Plik tymczasowy jest usuwany
 * zaraz po wysłaniu maila; jeśli SMS się nie uda, hasło pokazuje się kierownikowi jednorazowo.
 */
require_once dirname(__DIR__) . '/includes/x509_login.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/includes/sms.php';

/** Katalog tymczasowy na plik .p12 (w uploads/, ale z zakazem dostępu przez WWW). */
function ti_cert_tmp_dir(): string {
    $dir = rtrim(UPLOAD_DIR, '/') . '/ti_certs_tmp/';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $ht = $dir . '.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
    return $dir;
}

/** Osoby, które mogą dostać certyfikat: prowadzący przypisani do kursów, aktywne konta. */
function ti_cert_instructors(): array {
    return db_all(
        "SELECT DISTINCT u.id, u.name, u.email, u.phone_number
           FROM users u JOIN k30_ti_courses c ON c.instructor_id = u.id
          WHERE u.is_active = 1
          ORDER BY u.name"
    );
}

/** Treść maila z instrukcją (bez hasła). */
function ti_cert_mail_body(string $name, string $issuer): array {
    $txt = "Dzień dobry, {$name},\n\n"
         . "w załączniku przesyłamy certyfikat cyfrowy (plik .p12), którym będzie Pan/Pani podpisywać "
         . "protokoły miesięczne w module Dydaktyka.\n\n"
         . "Hasło do pliku przyjdzie w osobnej wiadomości SMS na Pana/Pani numer telefonu.\n\n"
         . "Do czego służy?\n"
         . "Zatwierdzając protokół, podpisuje Pan/Pani go swoim certyfikatem. Podpis potwierdza, że to Pan/Pani "
         . "zatwierdził(a) ewidencję godzin i że jej treść nie została zmieniona po podpisaniu. "
         . "Bez certyfikatu nie da się zatwierdzić protokołu.\n\n"
         . "Co zrobić:\n"
         . "1. Zapisz plik w bezpiecznym miejscu. Nie przesyłaj go dalej i nie zostawiaj na wspólnym komputerze.\n"
         . "2. Przy zatwierdzaniu protokołu (Protokoły → Zamknij) wskaż ten plik i wpisz hasło z SMS-a.\n"
         . "3. Plik i hasło są używane tylko do tego podpisu i nie są zapisywane w systemie.\n\n"
         . "Certyfikat jest ważny 2 lata. Jeśli plik się zgubi albo hasła nie ma, poproś o ponowne wystawienie.\n\n"
         . "Pozdrawiam,\n{$issuer}\nFundacja FEER";
    $html = '<p>' . nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8')) . '</p>';
    return [$txt, $html];
}

/**
 * Wystawia certyfikat prowadzącemu i wysyła go. Zwraca:
 *   ok (bool), error (string), sms (bool — hasło wysłane), password (string — tylko gdy SMS się nie udał).
 */
function ti_cert_issue_for_instructor(int $target_id, string $issuer_name): array {
    $u = db_one("SELECT id, name, email, phone_number FROM users WHERE id = ? AND is_active = 1", [$target_id]);
    if (!$u) return ['ok' => false, 'error' => 'Nie ma takiego aktywnego konta.'];
    if (trim((string)$u['email']) === '') return ['ok' => false, 'error' => 'Prowadzący nie ma adresu e-mail w koncie.'];
    if (preg_replace('/\D/', '', (string)$u['phone_number']) === '') return ['ok' => false, 'error' => 'Prowadzący nie ma numeru telefonu w koncie (potrzebny do hasła SMS).'];

    $password = bin2hex(random_bytes(6));  // 12 znaków, pokazywane tylko w razie błędu SMS
    $gen = x509_generate_for_user($target_id, $password, (string)$u['name'], 'Fundacja FEER');
    $p12 = (string)($gen['p12_data'] ?? '');
    if ($p12 === '') return ['ok' => false, 'error' => 'Nie udało się wygenerować certyfikatu.'];

    $dir  = ti_cert_tmp_dir();
    $file = bin2hex(random_bytes(12)) . '.p12';
    if (file_put_contents($dir . $file, $p12) === false) return ['ok' => false, 'error' => 'Nie udało się zapisać pliku certyfikatu.'];

    [$txt, $html] = ti_cert_mail_body((string)$u['name'], $issuer_name);
    $mail_id = mail_queue_add(
        (string)$u['email'], (string)$u['name'],
        'Certyfikat do podpisywania protokołów TI — Fundacja FEER',
        $html, $txt, 'ti_cert', $target_id, '', true,
        [['path' => 'ti_certs_tmp/' . $file, 'name' => 'certyfikat-' . $target_id . '.p12', 'mime' => 'application/x-pkcs12', 'size' => strlen($p12)]]
    );
    $status = (string)db_one("SELECT status FROM mail_queue WHERE id = ?", [$mail_id])['status'];
    @unlink($dir . $file);  // plik nie może zostać na serwerze po wysyłce

    if ($status !== 'sent') {
        // Bez pliku wiadomość poszłaby bez załącznika — anuluj ją w kolejce.
        mail_queue_cancel($mail_id);
        return ['ok' => false, 'error' => 'Mail z certyfikatem nie został wysłany. Certyfikat został wystawiony — spróbuj ponownie lub zgłoś to administratorowi.'];
    }

    $sms_ok = false;
    try {
        $sender = sms_send((string)$u['phone_number'], 'Haslo do certyfikatu FEER: ' . $password . '. Nie udostepniaj.', 'document_code');
        $sms_ok = $sender !== '';
    } catch (\Throwable $e) {
        error_log('[ti_cert_issue] SMS: ' . $e->getMessage());
    }
    return $sms_ok
        ? ['ok' => true, 'error' => '', 'sms' => true, 'password' => '']
        : ['ok' => true, 'error' => '', 'sms' => false, 'password' => $password];
}
