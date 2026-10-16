<?php
/**
 * modules/dyd_totp_exempt/logic/dydTotpExempt.php — zwolnienie wskazanych kont z obowiązkowego TOTP
 * w panelu dydaktyka (karty30/ti/dydaktyk). Ustawiane wyłącznie z CLI: php cli/2fa.php dyd-exempt|dyd-require <email>.
 *
 * Zwolnione konto po haśle albo Office wchodzi od razu (totp_gate.php kończy logowanie bez kodu),
 * a dyd_require() nie odsyła go na zakładanie TOTP.
 */

function dydTotpExemptEnsure(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS k30_dyd_totp_exempt (
        user_id    INTEGER PRIMARY KEY,
        reason     TEXT NOT NULL DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

function dydTotpIsExempt(int $userId): bool {
    if ($userId <= 0) return false;
    try {
        dydTotpExemptEnsure();
        return (bool)db_one("SELECT 1 AS x FROM k30_dyd_totp_exempt WHERE user_id=?", [$userId]);
    } catch (\Throwable $e) {
        return false;
    }
}

function dydTotpExemptSet(int $userId, bool $exempt, string $reason = ''): void {
    dydTotpExemptEnsure();
    if ($exempt) {
        db()->prepare("INSERT OR REPLACE INTO k30_dyd_totp_exempt (user_id, reason, created_at) VALUES (?, ?, datetime('now'))")
            ->execute([$userId, $reason]);
    } else {
        db()->prepare("DELETE FROM k30_dyd_totp_exempt WHERE user_id=?")->execute([$userId]);
    }
}

/** @return array<int, array{user_id:int, reason:string, created_at:string, name:?string, email:?string}> */
function dydTotpExemptList(): array {
    dydTotpExemptEnsure();
    return db_all("SELECT e.user_id, e.reason, e.created_at, u.name, u.email
                   FROM k30_dyd_totp_exempt e LEFT JOIN users u ON u.id = e.user_id ORDER BY e.created_at");
}
