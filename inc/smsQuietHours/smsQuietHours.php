<?php
/**
 * inc/smsQuietHours/smsQuietHours.php — godziny wysyłki SMS z systemu: 7:00–22:00.
 *
 * Poza tymi godzinami SMS-y systemowe NIE przepadają: trafiają do kolejki sms_deferred
 * i wychodzą o 7:00 (agent CRON sms_deferred → cron/sms_deferred.php).
 *
 * Zawsze, o każdej porze, wychodzą:
 *   • kody jednorazowe i weryfikacja (logowanie, 2FA, odzyskiwanie, IKA, kody do dokumentów…) —
 *     ktoś właśnie na nie czeka,
 *   • SMS-y wysyłane ręcznie przez kierownika TI (rola „Kierownik Instytucji” w panelu dydaktyka)
 *     albo administratora SZO.
 * Wysyłki z CRON-a nie mają sesji, więc zawsze podlegają godzinom.
 */

const SMS_QUIET_FROM = 22;   // od 22:00…
const SMS_QUIET_TO   = 7;    // …do 7:00 — cisza

/** Rodzaje SMS wysyłane zawsze (kody jednorazowe) — grupa „Kody jednorazowe i weryfikacja” w modules/sms_senders. */
const SMS_ALWAYS_KINDS = ['login_code', 'twofa_code', 'phone_verify_code', 'register_code', 'recovery_code',
                          'parent_access_code', 'authorization_code', 'document_code', 'ika_code'];

/** Czy teraz trwa cisza nocna (22:00–7:00, czas Europe/Warsaw). */
function smsQuietNow(?int $ts = null): bool {
    $h = (int)date('G', $ts ?? time());
    return $h >= SMS_QUIET_FROM || $h < SMS_QUIET_TO;
}

/** Czy SMS wysyła teraz ręcznie kierownik TI albo administrator (nie CRON). */
function smsQuietSenderIsManager(): bool {
    if (PHP_SAPI === 'cli') return false;
    try {
        if (function_exists('dyd_is_staff') && dyd_is_staff()) return true;
        if (function_exists('is_admin') && is_admin()) return true;
    } catch (\Throwable $e) {}
    return false;
}

/** Czy SMS danego rodzaju może wyjść teraz. */
function smsQuietAllowed(string $kind): bool {
    if (!smsQuietNow()) return true;
    if (in_array($kind, SMS_ALWAYS_KINDS, true)) return true;
    return smsQuietSenderIsManager();
}

function smsQuietMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS sms_deferred (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        phone       TEXT    NOT NULL,
        message     TEXT    NOT NULL,
        kind        TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        attempts    INTEGER NOT NULL DEFAULT 0,
        last_error  TEXT    NOT NULL DEFAULT ''
    )");
}

/** Odkłada SMS do wysyłki o 7:00. Zwraca id w kolejce. */
function smsQuietDefer(string $phone, string $message, string $kind): int {
    smsQuietMigrate();
    db()->prepare("INSERT INTO sms_deferred (phone, message, kind) VALUES (?, ?, ?)")->execute([$phone, $message, $kind]);
    return (int)db()->lastInsertId();
}

/**
 * Wysyła odłożone SMS-y (tylko w godzinach wysyłki). Zwraca [wysłane, błędy].
 * Po 5 nieudanych próbach wpis zostaje w tabeli z ostatnim błędem (do wglądu), ale nie jest ponawiany.
 */
function smsQuietFlush(int $limit = 200): array {
    smsQuietMigrate();
    if (smsQuietNow()) return [0, 0];
    $ok = 0; $err = 0;
    foreach (db_all("SELECT * FROM sms_deferred WHERE attempts < 5 ORDER BY id LIMIT " . (int)$limit) as $r) {
        $GLOBALS['__sms_quiet_bypass'] = true;
        try {
            sms_send((string)$r['phone'], (string)$r['message'], (string)$r['kind']);
            db()->prepare("DELETE FROM sms_deferred WHERE id = ?")->execute([(int)$r['id']]);
            $ok++;
        } catch (\Throwable $e) {
            db()->prepare("UPDATE sms_deferred SET attempts = attempts + 1, last_error = ? WHERE id = ?")
                ->execute([mb_substr($e->getMessage(), 0, 500), (int)$r['id']]);
            $err++;
        } finally {
            unset($GLOBALS['__sms_quiet_bypass']);
        }
    }
    return [$ok, $err];
}
