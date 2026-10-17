<?php
/**
 * modules/weryfikacja_sms/logic/phoneVerify.php — weryfikacja numeru telefonu kodem SMS (funkcjonalność współdzielona).
 *
 * Dla dowolnego formularza publicznego lub panelu: phoneVerifySend('cel', $tel) wysyła 6-cyfrowy kod,
 * phoneVerifyCheck('cel', $tel, $kod) go sprawdza, a phoneVerifiedInSession('cel') zwraca numer
 * potwierdzony w tej sesji (formularz porównuje go z numerem, który zapisuje). „Cel” rozdziela
 * weryfikacje różnych formularzy (np. 'ti_nabor').
 *
 * Bezpieczeństwo: w bazie tylko sha256(sesja|kod); kod ważny 10 min, maks. 5 prób; limity:
 * 3 kody na numer/godz., 10 kodów z IP/dobę, 60 s odstępu w sesji. Rodzaj SMS phone_verify_code.
 * Dostawca: opcjonalnie preferowany (np. 'sendly' — smsSendPreferred), przy błędzie zwykły łańcuch sms_send().
 * Tabela: phone_verify_codes.
 */
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/includes/sms.php';
require_once dirname(__DIR__, 3) . '/modules/selfrepairDB/logic/selfRepairDB.php';

const PHONE_VERIFY_TTL_MIN = 10;

function phoneVerifyMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (szo_schema_current('phone_verify_migrate', __FILE__)) return;
    db()->exec("CREATE TABLE IF NOT EXISTS phone_verify_codes (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        purpose     TEXT    NOT NULL,
        phone       TEXT    NOT NULL,          -- znormalizowany (48XXXXXXXXX)
        code_hash   TEXT    NOT NULL,
        session_id  TEXT    NOT NULL,          -- sha256 identyfikatora sesji
        ip_address  TEXT    NOT NULL DEFAULT '',
        attempts    INTEGER NOT NULL DEFAULT 0,
        verified_at DATETIME,
        expires_at  DATETIME NOT NULL,
        created_at  DATETIME NOT NULL DEFAULT (datetime('now'))
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_phone_verify_phone ON phone_verify_codes(phone, created_at)");
    $have = array_column(db()->query("PRAGMA table_info(phone_verify_codes)")->fetchAll(), 'name');
    // Kod wysłany e-mailem (gdy SMS nie dochodzi): kanał i adres
    if (!in_array('channel', $have, true)) db()->exec("ALTER TABLE phone_verify_codes ADD COLUMN channel TEXT NOT NULL DEFAULT 'sms'");
    if (!in_array('email', $have, true)) db()->exec("ALTER TABLE phone_verify_codes ADD COLUMN email TEXT NOT NULL DEFAULT ''");
}

/**
 * Wysyłka SMS przez preferowanego dostawcę (np. 'sendly'), a gdy nie jest skonfigurowany albo zgłosi błąd —
 * przez zwykły łańcuch sms_send() (główny + zapasowy). Zwraca klucz dostawcy, który wysłał; rzuca wyjątek, gdy
 * zawiodą wszyscy (komunikaty obu prób w treści). '' = sam łańcuch (bez preferencji).
 */
function smsSendPreferred(string $preferred, string $phone, string $message, string $kind): string {
    $errors = [];
    if ($preferred !== '' && sms_provider_ready($preferred)) {
        try {
            sms_send_via($preferred, $phone, $message, $kind);
            return $preferred;
        } catch (\Throwable $e) {
            $errors[] = sms_provider_label($preferred) . ': ' . $e->getMessage();
            sms_log_event('preferred_failed', sms_normalize_phone($phone), $errors, '', $kind);
        }
    }
    try {
        return sms_send($phone, $message, $kind);
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
        throw new RuntimeException(implode(' | ', $errors));
    }
}

/** Kanał gotowy: preferowany dostawca skonfigurowany albo działa łańcuch główny/zapasowy. */
function smsPreferredReady(string $preferred): bool {
    return ($preferred !== '' && sms_provider_ready($preferred)) || sms_channel_ready();
}

/** Polski numer komórkowy → 48XXXXXXXXX albo ''. */
function phoneVerifyNormalize(string $phone): string {
    $p = preg_replace('/\D/', '', $phone);
    if (strlen($p) === 9) $p = '48' . $p;
    return preg_match('/^48\d{9}$/', $p) ? $p : '';
}

/** Wysyła kod. Zwraca komunikat błędu albo '' przy sukcesie. $label — czego dotyczy kod (w treści SMS, ASCII). */
function phoneVerifySend(string $purpose, string $rawPhone, string $label = 'potwierdzenia numeru', string $provider = ''): string {
    phoneVerifyMigrate();
    auth_start();
    $phone = phoneVerifyNormalize($rawPhone);
    if ($phone === '') return 'Podaj polski numer telefonu komórkowego (9 cyfr).';
    if (!smsPreferredReady($provider)) return 'Wysyłka SMS jest chwilowo niedostępna. Spróbuj później.';
    $key = 'pv_last_' . $purpose;
    if (time() - (int)($_SESSION[$key] ?? 0) < 60) return 'Kod został właśnie wysłany. Nowy możesz wysłać za minutę.';
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    if ((int)db_one("SELECT COUNT(*) n FROM phone_verify_codes WHERE phone=? AND created_at>?", [$phone, gmdate('Y-m-d H:i:s', time() - 3600)])['n'] >= 3)
        return 'Wysłano już 3 kody na ten numer w ciągu godziny. Spróbuj później.';
    if ($ip !== '' && (int)db_one("SELECT COUNT(*) n FROM phone_verify_codes WHERE ip_address=? AND created_at>?", [$ip, gmdate('Y-m-d H:i:s', time() - 86400)])['n'] >= 10)
        return 'Przekroczono dzienny limit kodów. Spróbuj jutro.';

    $code = (string)random_int(100000, 999999);
    db_insert('phone_verify_codes', [
        'purpose'    => $purpose,
        'phone'      => $phone,
        'code_hash'  => hash('sha256', session_id() . '|' . $code),
        'session_id' => hash('sha256', session_id()),
        'ip_address' => $ip,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + PHONE_VERIFY_TTL_MIN * 60),
    ]);
    try {
        smsSendPreferred($provider, $phone, 'Kod ' . sms_ascii($label) . ': ' . $code . '. Wazny ' . PHONE_VERIFY_TTL_MIN . ' min. Nie przekazuj go nikomu.', 'phone_verify_code');
    } catch (\Throwable $e) {
        error_log('[phone_verify] ' . $purpose . ': ' . $e->getMessage());
        return 'Nie udało się wysłać SMS-a. Sprawdź numer i spróbuj ponownie.';
    }
    $_SESSION[$key] = time();
    unset($_SESSION['pv_ok'][$purpose]);
    return '';
}

/**
 * Kod na e-mail — gdy SMS nie dochodzi. Ten sam mechanizm i ten sam formularz kodu (wiązany z numerem i sesją),
 * ale po sprawdzeniu phoneVerifiedVia() zwraca 'email' — numer telefonu NIE jest wtedy uznany za zweryfikowany
 * (wywołujący zapisuje, że potwierdzono adres e-mail). Limity: 3 kody na adres / godz., 10 z IP / dobę. Zwraca błąd albo ''.
 */
function phoneVerifySendEmail(string $purpose, string $rawPhone, string $email, string $label = 'potwierdzenia'): string {
    phoneVerifyMigrate();
    auth_start();
    $phone = phoneVerifyNormalize($rawPhone);
    $email = mb_strtolower(trim($email));
    if ($phone === '') return 'Najpierw wpisz numer telefonu komórkowego (9 cyfr).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Wpisz poprawny adres e-mail — tam wyślemy kod.';
    $key = 'pv_last_mail_' . $purpose;
    if (time() - (int)($_SESSION[$key] ?? 0) < 60) return 'Kod został właśnie wysłany e-mailem. Nowy możesz wysłać za minutę.';
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    if ((int)db_one("SELECT COUNT(*) n FROM phone_verify_codes WHERE email=? AND created_at>?", [$email, gmdate('Y-m-d H:i:s', time() - 3600)])['n'] >= 3)
        return 'Wysłano już 3 kody na ten adres w ciągu godziny. Spróbuj później.';
    if ($ip !== '' && (int)db_one("SELECT COUNT(*) n FROM phone_verify_codes WHERE ip_address=? AND created_at>?", [$ip, gmdate('Y-m-d H:i:s', time() - 86400)])['n'] >= 10)
        return 'Przekroczono dzienny limit kodów. Spróbuj jutro.';
    $code = (string)random_int(100000, 999999);
    db_insert('phone_verify_codes', ['purpose' => $purpose, 'phone' => $phone, 'code_hash' => hash('sha256', session_id() . '|' . $code),
        'session_id' => hash('sha256', session_id()), 'ip_address' => $ip, 'channel' => 'email', 'email' => $email,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + PHONE_VERIFY_TTL_MIN * 60)]);
    try {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $org = function_exists('org_setting') ? (org_setting('org_name') ?: 'FEER') : 'FEER';
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px">'
              . '<p>Kod ' . htmlspecialchars($label, ENT_QUOTES) . ':</p>'
              . '<p style="font-size:32px;font-weight:700;letter-spacing:6px;margin:16px 0">' . $code . '</p>'
              . '<p style="color:#4b5563;font-size:14px">Kod jest ważny ' . PHONE_VERIFY_TTL_MIN . ' minut. Nie przekazuj go nikomu. Jeśli to nie Ty — zignoruj wiadomość.</p>'
              . '<p style="color:#4b5563;font-size:13px">' . htmlspecialchars($org, ENT_QUOTES) . '</p></div>';
        mail_queue_add($email, '', 'Kod: ' . $code . ' — ' . $org, $html, '', 'phone_verify', null, '', true);
    } catch (\Throwable $e) {
        error_log('[phone_verify] e-mail ' . $purpose . ': ' . $e->getMessage());
        return 'Nie udało się wysłać e-maila. Sprawdź adres i spróbuj ponownie.';
    }
    $_SESSION[$key] = time();
    unset($_SESSION['pv_ok'][$purpose]);
    return '';
}

/** Sprawdza kod. Zwraca komunikat błędu albo '' (numer zapamiętany w sesji dla celu). */
function phoneVerifyCheck(string $purpose, string $rawPhone, string $code): string {
    phoneVerifyMigrate();
    auth_start();
    $phone = phoneVerifyNormalize($rawPhone);
    $code  = preg_replace('/\D/', '', $code);
    if ($phone === '' || strlen($code) !== 6) return 'Wpisz 6-cyfrowy kod z SMS-a.';
    $row = db_one("SELECT * FROM phone_verify_codes WHERE purpose=? AND phone=? AND session_id=? AND verified_at IS NULL AND expires_at>?
                   ORDER BY id DESC LIMIT 1", [$purpose, $phone, hash('sha256', session_id()), gmdate('Y-m-d H:i:s')]);
    if (!$row) return 'Kod wygasł albo nie został wysłany na ten numer. Wyślij nowy kod.';
    if ((int)$row['attempts'] >= 5) return 'Za dużo błędnych prób. Wyślij nowy kod.';
    if (!hash_equals($row['code_hash'], hash('sha256', session_id() . '|' . $code))) {
        db_exec("UPDATE phone_verify_codes SET attempts=attempts+1 WHERE id=?", [$row['id']]);
        return 'Nieprawidłowy kod.';
    }
    db_exec("UPDATE phone_verify_codes SET verified_at=datetime('now') WHERE id=?", [$row['id']]);
    $_SESSION['pv_ok'][$purpose] = $phone;
    $_SESSION['pv_via'][$purpose] = ($row['channel'] ?? 'sms') === 'email' ? 'email' : 'sms';
    $_SESSION['pv_email'][$purpose] = (string)($row['email'] ?? '');
    return '';
}

/** Kanał, którym potwierdzono kod w tej sesji: 'sms' | 'email' | null. Przy 'email' — adres w phoneVerifiedEmail(). */
function phoneVerifiedVia(string $purpose): ?string {
    auth_start();
    return isset($_SESSION['pv_ok'][$purpose]) ? ($_SESSION['pv_via'][$purpose] ?? 'sms') : null;
}

function phoneVerifiedEmail(string $purpose): string {
    auth_start();
    return (string)($_SESSION['pv_email'][$purpose] ?? '');
}

function phoneVerifiedInSession(string $purpose): ?string {
    auth_start();
    return $_SESSION['pv_ok'][$purpose] ?? null;
}

/** Po wykorzystaniu (zapis formularza) — kolejne zgłoszenie wymaga nowej weryfikacji. */
function phoneVerifyForget(string $purpose): void {
    auth_start();
    unset($_SESSION['pv_ok'][$purpose], $_SESSION['pv_via'][$purpose], $_SESSION['pv_email'][$purpose]);
}
