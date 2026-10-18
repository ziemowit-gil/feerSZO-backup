<?php
/**
 * Obsługa formularzy kreatora (POST, po csrf_check). Zmienne z index.php: $user, $uid, $url, $back, $panel.
 * Każda akcja kończy się przekierowaniem (PRG).
 */
$do  = (string)($_POST['_action'] ?? '');
$say = function (string $type, string $text) { $_SESSION['first_run_msg'] = [$type, $text]; };
$go  = function (string $location) { header('Location: ' . $location); exit; };

switch ($do) {
    case 'later':   // „Później” — kreator wróci przy następnym logowaniu
        $_SESSION['first_run_skip'] = 1;
        $go($back);

    case 'finish':
        firstRunFinish($uid);
        unset($_SESSION['first_run_phone']);
        $go($back);

    case 'phone_send':
        require_once dirname(__DIR__, 3) . '/includes/sms.php';
        $raw = preg_replace('/\D/', '', (string)($_POST['phone_number'] ?? ''));
        if (strlen($raw) < 9) { $say('error', 'Podaj poprawny 9-cyfrowy numer telefonu komórkowego.'); $go($url(['step' => 'phone'])); }
        $norm = sms_normalize_phone((string)$_POST['phone_number']);
        try {
            $otp = sms_generate_otp($norm, $uid);
            $via = sms_send_with_fallback($norm, "Kod weryfikacyjny numeru telefonu: {$otp} (ważny 5 min). Nie udostępniaj go nikomu.", $user['email'] ?? '', 'phone_verify_code');
            $_SESSION['first_run_phone'] = $norm;
            $say($via === 'email' ? 'warn' : 'ok', $via === 'email'
                ? 'Nie udało się wysłać SMS — kod wysłaliśmy na Twój adres e-mail.'
                : 'Wysłaliśmy 6-cyfrowy kod SMS na numer ' . $norm . '.');
        } catch (\Throwable $e) {
            $say('error', 'Nie udało się wysłać kodu: ' . $e->getMessage());
        }
        $go($url(['step' => 'phone']));

    case 'phone_verify':
        require_once dirname(__DIR__, 3) . '/includes/sms.php';
        $norm = $_SESSION['first_run_phone'] ?? '';
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        $ok   = $norm !== '' ? sms_verify_otp($norm, $code) : null;
        if ($ok && (int)$ok['id'] === $uid) {
            db_exec("UPDATE users SET phone_number=?, phone_verified_at=datetime('now') WHERE id=?", [$norm, $uid]);
            unset($_SESSION['first_run_phone']);
            if (is_file(dirname(__DIR__, 3) . '/includes/auth_security.php')) {
                require_once dirname(__DIR__, 3) . '/includes/auth_security.php';
                if (function_exists('authlog_write')) authlog_write($uid, 'phone_verified', $user['email'] ?? '', 'Zweryfikowano numer telefonu (kreator pierwszego logowania): ' . $norm);
            }
            $say('ok', 'Numer telefonu potwierdzony.');
        } else {
            $say('error', $norm === '' ? 'Sesja weryfikacji wygasła — wyślij kod jeszcze raz.' : 'Nieprawidłowy lub wygasły kod. Spróbuj ponownie albo wyślij nowy.');
        }
        $go($url(['step' => 'phone']));

    case 'phone_cancel':
        unset($_SESSION['first_run_phone']);
        $go($url(['step' => 'phone']));

    case 'stopka_save':
        require_once dirname(__DIR__, 2) . '/stopka/logic/stopka.php';
        stopkaMigrate();
        stopkaSaveProfile($uid, trim((string)($_POST['job_title'] ?? '')), trim((string)($_POST['phone'] ?? '')));
        $say('ok', 'Dane do stopki zapisane.');
        $go($url(['step' => 'stopka']));

    case 'admin_save':
        if (!is_admin()) $go($back);
        org_setting_set('first_run_mode', ($_POST['mode'] ?? '') === 'off' ? 'off' : 'on');
        $say('ok', 'Ustawienia kreatora zapisane.');
        $go($url(['admin' => 1]));

    case 'admin_reset_all':
        if (!is_admin()) $go($back);
        $n = firstRunReset(null);
        $say('ok', "Kreator pokaże się ponownie wszystkim ($n kont) przy najbliższym wejściu.");
        $go($url(['admin' => 1]));

    case 'admin_reset_me':
        firstRunReset($uid);
        unset($_SESSION['first_run_skip']);
        $go($url());
}
$go($url());
