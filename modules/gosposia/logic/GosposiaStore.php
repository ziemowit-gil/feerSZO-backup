<?php
declare(strict_types=1);

/**
 * modules/gosposia/logic/GosposiaStore.php — dane Gosposi w bazie SZO: biała lista nadawców i zapamiętane skrzynki.
 *
 * Tabele (tworzone leniwie, samonaprawa):
 *   gosposia_whitelist — pattern (adres e-mail albo domena; domena obejmuje subdomeny), user_id NULL = lista globalna (zaufane: .gov.pl,
 *                        feer.org.pl, dostawca poczty, banki), inaczej prywatna lista użytkownika;
 *   gosposia_accounts  — user_id, email, password_enc (fcEncrypt, kontekst 'gosposia.account') — tylko po zaznaczeniu „Zapamiętaj w SZO”.
 * Ślad audytowy idzie do wspólnego audit_logs (akcje gosposia.*), bez tematów i treści wiadomości.
 */
require_once dirname(__DIR__, 2) . '/field_crypto/lib/fieldCrypto.php';
require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';

final class GosposiaStore
{
    /** Zaufane domeny: ich poczta nigdy nie trafia do AI ani na listę do sprzątania. */
    public const DEFAULT_WHITELIST = [
        'gov.pl', 'feer.org.pl', 'dhosting.pl', 'dpoczta.pl', 'zus.pl', 'epuap.gov.pl',
        'pkobp.pl', 'ipko.pl', 'mbank.pl', 'santander.pl', 'ing.pl', 'ingbank.pl', 'pekao.com.pl', 'bnpparibas.pl',
        'bankmillennium.pl', 'aliorbank.pl', 'credit-agricole.pl', 'velobank.pl', 'citibank.pl', 'bosbank.pl', 'nestbank.pl', 'bankbps.pl',
    ];
    private const CTX = 'gosposia.account';
    private static bool $migrated = false;

    public static function migrate(): void
    {
        if (self::$migrated) return;
        $db = db();
        $db->exec("CREATE TABLE IF NOT EXISTS gosposia_whitelist (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NULL,
            pattern    TEXT    NOT NULL,
            created_at TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_gosposia_whitelist ON gosposia_whitelist(COALESCE(user_id, 0), pattern)");
        $db->exec("CREATE TABLE IF NOT EXISTS gosposia_accounts (
            user_id      INTEGER PRIMARY KEY,
            email        TEXT NOT NULL,
            password_enc TEXT NOT NULL,
            updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        if (!db_one("SELECT 1 AS x FROM gosposia_whitelist WHERE user_id IS NULL LIMIT 1")) {
            foreach (self::DEFAULT_WHITELIST as $p) db_exec("INSERT OR IGNORE INTO gosposia_whitelist (user_id, pattern) VALUES (NULL, ?)", [$p]);
        }
        self::$migrated = true;
    }

    // ── Biała lista ─────────────────────────────────────────────────────────────────────────────

    /** Adres e-mail albo domena (bez @, bez kropki na początku); '' gdy niepoprawne. */
    public static function normalizePattern(string $p): string
    {
        $p = strtolower(trim($p));
        $p = ltrim(preg_replace('/^\*?@/', '', $p) ?? '', '.');
        if (filter_var($p, FILTER_VALIDATE_EMAIL)) return $p;
        return preg_match('/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $p) ? $p : '';
    }

    /** @return array{global:list<string>, own:list<array{id:int, pattern:string}>} */
    public static function whitelist(int $userId): array
    {
        self::migrate();
        $out = ['global' => [], 'own' => []];
        foreach (db_all("SELECT id, user_id, pattern FROM gosposia_whitelist WHERE user_id IS NULL OR user_id = ? ORDER BY pattern", [$userId]) as $r) {
            if ($r['user_id'] === null) $out['global'][] = (string)$r['pattern'];
            else $out['own'][] = ['id' => (int)$r['id'], 'pattern' => (string)$r['pattern']];
        }
        return $out;
    }

    /** @return list<string> wszystkie wzorce obowiązujące użytkownika */
    public static function patterns(int $userId): array
    {
        $w = self::whitelist($userId);
        return array_merge($w['global'], array_column($w['own'], 'pattern'));
    }

    public static function isWhitelisted(string $email, array $patterns): bool
    {
        $email = strtolower($email);
        $domain = (string)substr((string)strrchr($email, '@'), 1);
        foreach ($patterns as $p) {
            if (str_contains($p, '@')) { if ($p === $email) return true; continue; }
            if ($domain === $p || str_ends_with($domain, '.' . $p)) return true;
        }
        return false;
    }

    public static function whitelistAdd(int $userId, string $pattern): string
    {
        self::migrate();
        $p = self::normalizePattern($pattern);
        if ($p === '') throw new \RuntimeException('Podaj poprawny adres e-mail albo domenę.');
        db_exec("INSERT OR IGNORE INTO gosposia_whitelist (user_id, pattern) VALUES (?, ?)", [$userId, $p]);
        audit_log('gosposia.whitelist_add', ['pattern' => $p]);
        return $p;
    }

    public static function whitelistRemove(int $userId, int $id): void
    {
        self::migrate();
        $row = db_one("SELECT pattern FROM gosposia_whitelist WHERE id = ? AND user_id = ?", [$id, $userId]);
        if (!$row) return;
        db_exec("DELETE FROM gosposia_whitelist WHERE id = ? AND user_id = ?", [$id, $userId]);
        audit_log('gosposia.whitelist_remove', ['pattern' => $row['pattern']]);
    }

    // ── Zapamiętana skrzynka ───────────────────────────────────────────────────────────────────

    /** @return array{email:string, password:string}|null */
    public static function account(int $userId): ?array
    {
        self::migrate();
        $r = db_one("SELECT email, password_enc FROM gosposia_accounts WHERE user_id = ?", [$userId]);
        if (!$r) return null;
        $pass = fcDecrypt((string)$r['password_enc'], self::CTX);
        return $pass === null || $pass === '' ? null : ['email' => (string)$r['email'], 'password' => $pass];
    }

    public static function saveAccount(int $userId, string $email, string $password): void
    {
        self::migrate();
        db_exec("INSERT INTO gosposia_accounts (user_id, email, password_enc, updated_at) VALUES (?, ?, ?, datetime('now'))
                 ON CONFLICT(user_id) DO UPDATE SET email = excluded.email, password_enc = excluded.password_enc, updated_at = excluded.updated_at",
            [$userId, $email, (string)fcEncrypt($password, self::CTX)]);
    }

    public static function forgetAccount(int $userId): void
    {
        self::migrate();
        db_exec("DELETE FROM gosposia_accounts WHERE user_id = ?", [$userId]);
    }
}
