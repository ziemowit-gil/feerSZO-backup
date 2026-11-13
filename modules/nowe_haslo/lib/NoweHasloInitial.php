<?php
declare(strict_types=1);

/**
 * modules/nowe_haslo/lib/NoweHasloInitial.php — hasła startowe (polecenie 2026-11-14: „zachęcaj userów do zmiany haseł w panelu,
 * jak mają ustawione startowe, albo nawet to wymuś” → wybrano: zachęta, po GRACE_DAYS dniach blokada panelu).
 *
 * Hasło startowe = nadane przez system albo administratora, a nie przez samą osobę. Systemy:
 *   'sso'    — logowanie jednokrotne (Logto) + chmura; login = e-mail konta w sso_accounts,
 *   'poczta' — skrzynka na dhosting; login = adres skrzynki.
 * Oznaczają (mark): SsoAccounts::provision / setPassword / resetPassword / renameCloudLogin, DhostingApi::createMailbox /
 * changeMailboxPassword (każde ustawienie hasła skrzynki przez SZO). Czyszczą (clear): NoweHaslo::change (zmiana przez osobę na
 * /nowehaslo), SsoController::selfPassword (Tożsamość), SsoAccounts::markPasswordResetInLogto (reset w Logto), zawieszenie skrzynki.
 * Kolejność „mark, potem clear” w tym samym żądaniu jest zamierzona: zmiana przez osobę też przechodzi przez API.
 * Uzupełnienie istniejących kont: migracja '2026-11_password_initial_backfill' (backfill()).
 * Każda metoda połyka błędy (error_log) — oznaczanie nigdy nie zatrzymuje zmiany hasła.
 */
final class NoweHasloInitial
{
    public const GRACE_DAYS = 7;
    public const SYSTEMS = ['sso' => 'logowanie jednokrotne i chmura plików', 'poczta' => 'poczta'];
    private static bool $migrated = false;

    public static function migrate(): void
    {
        if (self::$migrated) return;
        db()->exec("CREATE TABLE IF NOT EXISTS password_initial (
            system  TEXT NOT NULL,
            login   TEXT NOT NULL,
            user_id INTEGER NULL,
            set_at  TEXT NOT NULL,
            set_by  TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (system, login))");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_password_initial_user ON password_initial(user_id)");
        self::$migrated = true;
    }

    public static function mark(string $system, string $login, ?int $userId = null, string $by = '', ?string $at = null): void
    {
        try {
            $login = strtolower(trim($login));
            if ($login === '' || !isset(self::SYSTEMS[$system])) return;
            self::migrate();
            $userId = $userId ?: self::resolveUser($system, $login);
            db_exec("INSERT OR REPLACE INTO password_initial (system, login, user_id, set_at, set_by) VALUES (?, ?, ?, ?, ?)",
                [$system, $login, $userId ?: null, $at ?? date('Y-m-d H:i:s'), mb_substr($by, 0, 190)]);
        } catch (\Throwable $e) { error_log('[password_initial] mark: ' . $e->getMessage()); }
    }

    public static function clear(string $system, string $login): void
    {
        try {
            self::migrate();
            db_exec("DELETE FROM password_initial WHERE system = ? AND login = ?", [$system, strtolower(trim($login))]);
        } catch (\Throwable $e) { error_log('[password_initial] clear: ' . $e->getMessage()); }
    }

    /** Konto SZO dla loginu systemu (po e-mailu, loginie Microsoft albo zapasowym e-mailu; sso_accounts / rejestr skrzynek). */
    public static function resolveUser(string $system, string $login): ?int
    {
        try {
            $id = 0;
            if ($system === 'sso') $id = (int)(db_one("SELECT user_id FROM sso_accounts WHERE lower(email) = ?", [$login])['user_id'] ?? 0);
            if (!$id && $system === 'poczta') $id = (int)(db_one("SELECT user_id FROM dhosting_mailboxes WHERE lower(mailbox) = ?", [$login])['user_id'] ?? 0);
            if (!$id) $id = (int)(db_one("SELECT id FROM users WHERE lower(email) = ? OR lower(COALESCE(m365_login,'')) = ? OR lower(COALESCE(alt_email,'')) = ?
                                          ORDER BY (lower(email) = ?) DESC LIMIT 1", [$login, $login, $login, $login])['id'] ?? 0);
            return $id ?: null;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Stan osoby. @return array{rows:list<array{system:string,login:string,set_at:string}>, labels:list<string>, force:bool, deadline:?int}
     * force — najstarsze hasło startowe ma więcej niż GRACE_DAYS dni; deadline — kiedy minie (albo minął) termin.
     */
    public static function status(?array $u): array
    {
        $out = ['rows' => [], 'labels' => [], 'force' => false, 'deadline' => null];
        if (!$u || empty($u['id'])) return $out;
        try {
            self::migrate();
            $db = db_one("SELECT email, m365_login, alt_email FROM users WHERE id = ?", [(int)$u['id']]) ?: [];
            $logins = array_values(array_unique(array_filter(array_map(fn($v) => strtolower(trim((string)$v)), [$db['email'] ?? '', $db['m365_login'] ?? '', $db['alt_email'] ?? '']))));
            $ph = $logins ? ' OR login IN (' . implode(',', array_fill(0, count($logins), '?')) . ')' : '';
            $rows = db_all("SELECT system, login, set_at FROM password_initial WHERE user_id = ?{$ph} ORDER BY set_at", [(int)$u['id'], ...$logins]);
        } catch (\Throwable $e) { error_log('[password_initial] status: ' . $e->getMessage()); return $out; }
        if (!$rows) return $out;
        $oldest = strtotime((string)$rows[0]['set_at']) ?: time();
        $out['rows'] = $rows;
        $out['labels'] = array_values(array_unique(array_map(fn($r) => self::SYSTEMS[$r['system']] ?? $r['system'], $rows)));
        $out['deadline'] = $oldest + self::GRACE_DAYS * 86400;
        $out['force'] = time() >= $out['deadline'];
        return $out;
    }

    /**
     * Panel: po terminie przekierowuje na /nowehaslo (zwykłe GET, bez podglądu cudzego konta przez administratora).
     * Zwraca stan do banera.
     */
    public static function enforce(?array $u): array
    {
        $st = self::status($u);
        if (!$st['force'] || PHP_SAPI === 'cli') return $st;
        $impersonated = !empty($_SESSION['_admin_original']);
        $plainGet = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_SERVER['HTTP_X_REQUESTED_WITH']) && !isset($_GET['_ajax']) && !isset($_GET['ajax']);
        if (!$impersonated && $plainGet && !headers_sent()) {
            header('Location: ' . rtrim(APP_URL, '/') . '/nowehaslo?startowe=1', true, 303);
            exit;
        }
        return $st;
    }

    /** Polska data terminu, np. „21 listopada”. */
    public static function dateLabel(int $ts): string
    {
        $m = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
        return date('j', $ts) . ' ' . $m[(int)date('n', $ts) - 1];
    }

    /**
     * Uzupełnienie: konta logowania jednokrotnego / chmury i skrzynki założone przez SZO, których osoba sama jeszcze nie zmieniła
     * (brak 'nowehaslo.sso' / 'nowehaslo.mail' w audit_logs, brak resetu w Logto, brak zmiany w Tożsamości). Termin liczony od teraz.
     * @return array{sso:int, poczta:int}
     */
    public static function backfill(): array
    {
        self::migrate();
        $n = ['sso' => 0, 'poczta' => 0];
        $changed = function (string $action, ?int $uid, array $keys): bool {
            try {
                foreach (db_all("SELECT details FROM audit_logs WHERE action = ?", [$action]) as $r) {
                    $d = json_decode((string)$r['details'], true) ?: [];
                    if ($uid && (int)($d['user_id'] ?? 0) === $uid) return true;
                    if (in_array(strtolower((string)($d['key'] ?? '')), $keys, true)) return true;
                }
            } catch (\Throwable $e) {}
            return false;
        };
        $self = [];
        try {
            foreach (db_all("SELECT details FROM audit_logs WHERE action = 'sso.password'") as $r) {
                $d = json_decode((string)$r['details'], true) ?: [];
                if (strtolower((string)($d['by'] ?? '')) !== '' && strtolower((string)($d['by'] ?? '')) === strtolower((string)($d['email'] ?? ''))) $self[strtolower((string)$d['email'])] = true;
            }
        } catch (\Throwable $e) {}
        try {
            foreach (db_all("SELECT email, user_id, logto_pwd_reset_at FROM sso_accounts WHERE COALESCE(logto_id,'') <> '' OR COALESCE(cloud_uid,'') <> ''") as $a) {
                $e = strtolower((string)$a['email']);
                if ((string)($a['logto_pwd_reset_at'] ?? '') !== '' || isset($self[$e])) continue;
                if (db_one("SELECT 1 FROM password_initial WHERE system = 'sso' AND login = ?", [$e])) continue;
                $uid = (int)($a['user_id'] ?? 0) ?: self::resolveUser('sso', $e);
                if ($changed('nowehaslo.sso', $uid, [$e])) continue;
                self::mark('sso', $e, $uid, 'uzupełnienie 2026-11-14');
                $n['sso']++;
            }
        } catch (\Throwable $e) { error_log('[password_initial] backfill sso: ' . $e->getMessage()); }
        try {
            $has = fn(string $t) => (bool)db_one("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]);
            $boxes = $has('dhosting_mailboxes') ? db_all("SELECT mailbox, user_id FROM dhosting_mailboxes WHERE status = 'created'") : [];
            if ($has('dhosting_contract_boxes')) {
                $susp = array_flip(array_map('strtolower', array_column(db_all("SELECT mailbox FROM dhosting_contract_boxes WHERE status = 'suspended'"), 'mailbox')));
                $boxes = array_values(array_filter($boxes, fn($b) => !isset($susp[strtolower((string)$b['mailbox'])])));
                foreach (db_all("SELECT mailbox, NULL AS user_id FROM dhosting_contract_boxes WHERE status = 'active' AND contract_type <> 'migracja'") as $cb) $boxes[] = $cb;
            }
            foreach ($boxes as $m) {
                $box = strtolower((string)$m['mailbox']);
                if (db_one("SELECT 1 FROM password_initial WHERE system = 'poczta' AND login = ?", [$box])) continue;
                $uid = (int)($m['user_id'] ?? 0) ?: self::resolveUser('poczta', $box);
                if ($changed('nowehaslo.mail', $uid, [$box])) continue;
                self::mark('poczta', $box, $uid, 'uzupełnienie 2026-11-14');
                $n['poczta']++;
            }
        } catch (\Throwable $e) { error_log('[password_initial] backfill poczta: ' . $e->getMessage()); }
        return $n;
    }
}
