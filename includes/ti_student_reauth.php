<?php
/**
 * includes/ti_student_reauth.php — ponowna autoryzacja kont kursantów przy wejściu w Tożsamość.
 * Konta założone przed TI_REAUTH_OD nie mogą przenieść dotychczasowych haseł do nowego logowania,
 * więc traktujemy je jak nowe: kod SMS i nowe hasło. Robi się to raz (kolumna reauth_at).
 */

const TI_REAUTH_OD = '2026-10-10 00:00:00';

function ti_reauth_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN reauth_at TEXT");
    } catch (\Throwable $e) {
        // kolumna już istnieje
    }
}

/** Czy konto ma przejść przez logowanie jak pierwsze (kod SMS). */
function ti_student_needs_reauth(array $acc): bool {
    if (empty($acc['last_login']) || empty($acc['sec_chosen'])) return true;
    $created = (string)($acc['created_at'] ?? '');
    return $created !== '' && $created < TI_REAUTH_OD && empty($acc['reauth_at']);
}
