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
    return '';
}

function phoneVerifiedInSession(string $purpose): ?string {
    auth_start();
    return $_SESSION['pv_ok'][$purpose] ?? null;
}

/** Po wykorzystaniu (zapis formularza) — kolejne zgłoszenie wymaga nowej weryfikacji. */
function phoneVerifyForget(string $purpose): void {
    auth_start();
    unset($_SESSION['pv_ok'][$purpose]);
}
