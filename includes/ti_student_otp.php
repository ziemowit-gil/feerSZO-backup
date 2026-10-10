<?php
/**
 * includes/ti_student_otp.php — jednorazowy kod SMS przy PIERWSZYM logowaniu kursanta.
 * Kod: 6 cyfr, ważny 5 minut, najwyżej 5 prób, zużywany po poprawnym użyciu.
 * Kod jest przechowywany jako hash (password_hash), nie w czytelnej postaci.
 */
require_once dirname(__DIR__) . '/includes/sms.php';

function ti_student_otp_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_student_otp (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        account_id  INTEGER NOT NULL,
        code_hash   TEXT    NOT NULL,
        expires_at  DATETIME NOT NULL,
        used_at     DATETIME,
        attempts    INTEGER NOT NULL DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

/**
 * Wysyła kod na telefon z karty klienta konta. Zwraca false, gdy brak telefonu albo SMS się nie udał.
 */
function ti_student_otp_send(int $account_id, int $client_id): bool {
    ti_student_otp_migrate();
    $klient = db_one("SELECT phone FROM k30_clients WHERE id = ?", [$client_id]);
    $phone  = (string)($klient['phone'] ?? '');
    if (preg_replace('/\D/', '', $phone) === '') return false;

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    // Poprzednie, niezużyte kody tego konta przestają działać.
    db()->prepare("UPDATE k30_ti_student_otp SET used_at = datetime('now') WHERE account_id = ? AND used_at IS NULL")
        ->execute([$account_id]);
    db()->prepare("INSERT INTO k30_ti_student_otp (account_id, code_hash, expires_at) VALUES (?, ?, datetime('now', '+5 minutes'))")
        ->execute([$account_id, password_hash($code, PASSWORD_DEFAULT)]);

    try {
        return sms_send($phone, 'FEER: kod do logowania: ' . $code . ' (wazny 5 min). Nie udostepniaj.', 'login_code') !== '';
    } catch (\Throwable $e) {
        error_log('[ti_student_otp] SMS: ' . $e->getMessage());
        return false;
    }
}

/** Sprawdza kod. Zużywa go po poprawnym wpisaniu; po 5 błędnych próbach kod przestaje działać. */
function ti_student_otp_check(int $account_id, string $code): bool {
    ti_student_otp_migrate();
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $row = db_one(
        "SELECT * FROM k30_ti_student_otp
          WHERE account_id = ? AND used_at IS NULL AND expires_at > datetime('now')
          ORDER BY id DESC LIMIT 1",
        [$account_id]
    );
    if (!$row || (int)$row['attempts'] >= 5) return false;
    db()->prepare("UPDATE k30_ti_student_otp SET attempts = attempts + 1 WHERE id = ?")->execute([(int)$row['id']]);
    if (!password_verify($code, (string)$row['code_hash'])) return false;
    db()->prepare("UPDATE k30_ti_student_otp SET used_at = datetime('now') WHERE id = ?")->execute([(int)$row['id']]);
    return true;
}
