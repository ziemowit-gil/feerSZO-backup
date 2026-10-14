<?php
/**
 * modules/ti_m365_feer/logic/tiM365Feer.php — konta i licencje Office 365 kursantów TI na GŁÓWNYM tenancie FEER
 * (m365_* — ten sam co konta pracowników), hurtowo, z panelu dydaktyka (kierownik). Odpowiednik „Administracji kont”
 * EDU (modules/ms_edu_bulk), ale dla tenanta organizacji.
 *
 * Powiązanie kursant → konto FEER: k30_ti_student_accounts.feer_ms_user_id / feer_ms_upn — ręcznie (UPN),
 * automatycznie (e-mail kursanta w domenie tenanta) albo przez założenie konta (login imie.nazwisko@domena).
 * Bezpiecznik: konto, które jest kontem pracownika/wolontariusza w SZO (users.email / users.microsoft_id),
 * nie da się powiązać ani zmienić — kierownik TI zarządza wyłącznie kontami kursantów.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/m365.php';
require_once dirname(__DIR__, 3) . '/includes/ti_online.php';

final class TiM365Feer
{
    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $have = array_column(db_all("PRAGMA table_info(k30_ti_student_accounts)"), 'name');
        foreach (['feer_ms_user_id' => "TEXT NOT NULL DEFAULT ''", 'feer_ms_upn' => "TEXT NOT NULL DEFAULT ''", 'feer_ms_linked_at' => 'DATETIME'] as $c => $ddl) {
            if (!in_array($c, $have, true)) db()->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN $c $ddl");
        }
        // Rejestr kont wygenerowanych masowo (nazwa + numer) — do przypisania kursantom później
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_feer_generated (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            ms_user_id  TEXT    NOT NULL DEFAULT '',
            upn         TEXT    NOT NULL DEFAULT '',
            label       TEXT    NOT NULL DEFAULT '',     -- nazwa szkolenia / partii
            skus        TEXT    NOT NULL DEFAULT '',     -- przypisane licencje (skuId, po przecinku)
            account_id  INTEGER,                         -- k30_ti_student_accounts.id po przypisaniu kursantowi
            created_by  INTEGER,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_ti_feer_gen_upn ON k30_ti_feer_generated(upn)");
    }

    public static function graph(): M365Graph { return new M365Graph(); }   // główny tenant organizacji (m365_*)

    public static function ready(): bool
    {
        try { return self::graph()->is_configured(); } catch (\Throwable $e) { return false; }
    }

    public static function domain(): string { return m365_setting('m365_domain') ?: 'feer.org.pl'; }

    /** Czy to konto pracownika/wolontariusza SZO (nie kursanta) — wtedy kierownik TI go nie rusza. */
    public static function isStaffAccount(string $userId, string $upn): bool
    {
        $upn = strtolower(trim($upn));
        return (bool)db_one("SELECT 1 FROM users WHERE (microsoft_id <> '' AND microsoft_id = ?) OR (LOWER(email) = ? AND ? <> '') LIMIT 1", [$userId, $upn, $upn]);
    }

    /** Kursanci z kontem panelu (aktywni) + powiązanie z tenantem FEER. */
    public static function list(string $q = '', string $f = ''): array
    {
        self::migrate();
        $w = ['a.is_active = 1']; $p = [];
        if ($q !== '') { $w[] = '(c.name LIKE ? OR c.email LIKE ? OR a.login LIKE ? OR a.feer_ms_upn LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }
        if ($f === 'linked')   $w[] = "a.feer_ms_user_id <> ''";
        if ($f === 'unlinked') $w[] = "a.feer_ms_user_id = ''";
        return db_all("SELECT a.id, a.login, a.client_id, a.feer_ms_user_id, a.feer_ms_upn, a.ms_upn, c.name, c.email,
                              (SELECT GROUP_CONCAT(co.name, ', ') FROM k30_ti_enrollments e JOIN k30_ti_courses co ON co.id = e.course_id
                                WHERE e.client_id = a.client_id AND e.status = 'active') AS groups
                         FROM k30_ti_student_accounts a JOIN k30_clients c ON c.id = a.client_id
                        WHERE " . implode(' AND ', $w) . " ORDER BY c.name COLLATE NOCASE LIMIT 500", $p);
    }

    public static function byIds(array $ids): array
    {
        self::migrate();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        return db_all("SELECT a.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone
                         FROM k30_ti_student_accounts a JOIN k30_clients c ON c.id = a.client_id
                        WHERE a.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids);
    }

    /** Powiązanie z istniejącym kontem FEER (UPN lub e-mail). */
    public static function link(M365Graph $g, array $acc, string $upn): void
    {
        $u = $g->find_by_email_or_upn(trim($upn));
        if (empty($u['id'])) throw new RuntimeException('nie ma takiego konta w tenancie FEER (' . $upn . ').');
        if (self::isStaffAccount((string)$u['id'], (string)$u['userPrincipalName'])) throw new RuntimeException('to konto pracownika/wolontariusza SZO — kierownik TI go nie zmienia.');
        $dup = db_one("SELECT id FROM k30_ti_student_accounts WHERE feer_ms_user_id = ? AND id <> ?", [(string)$u['id'], (int)$acc['id']]);
        if ($dup) throw new RuntimeException('to konto jest już powiązane z innym kursantem.');
        db()->prepare("UPDATE k30_ti_student_accounts SET feer_ms_user_id = ?, feer_ms_upn = ?, feer_ms_linked_at = datetime('now') WHERE id = ?")
            ->execute([(string)$u['id'], (string)$u['userPrincipalName'], (int)$acc['id']]);
        db()->prepare("UPDATE k30_ti_feer_generated SET account_id = ? WHERE ms_user_id = ?")->execute([(int)$acc['id'], (string)$u['id']]);   // konto z puli masowej
    }

    /**
     * Akcje hurtowe: link_auto (e-mail w domenie FEER), create (nowe konto + licencje + dane SMS/e-mail),
     * license (nadaj 1–2 SKU), unlicense (odbierz 1–2 SKU), unlink (tylko rozłącz w systemie).
     * @return array{done:int, errors:list<string>, created:list<array{name:string,upn:string,password:string}>}
     */
    public static function act(array $accs, string $op, array $skus = []): array
    {
        self::migrate();
        $g = self::graph(); $done = 0; $errors = []; $created = [];
        $skus = array_values(array_unique(array_filter(array_map('trim', $skus), fn($s) => preg_match('/^[0-9a-f-]{36}$/i', $s))));
        if (in_array($op, ['license', 'unlicense'], true) && !$skus) return ['done' => 0, 'errors' => ['Wybierz licencję.'], 'created' => []];
        foreach ($accs as $a) {
            $who = (string)$a['client_name'];
            try {
                switch ($op) {
                    case 'link_auto':
                        $em = strtolower(trim((string)$a['client_email']));
                        if ($a['feer_ms_user_id'] !== '') throw new RuntimeException('już powiązane.');
                        if (!str_ends_with($em, '@' . strtolower(self::domain()))) throw new RuntimeException('e-mail kursanta nie jest w domenie ' . self::domain() . ' — powiąż ręcznie albo załóż konto.');
                        self::link($g, $a, $em);
                        break;
                    case 'create':
                        if ($a['feer_ms_user_id'] !== '') throw new RuntimeException('ma już konto FEER (' . $a['feer_ms_upn'] . ').');
                        $name = trim((string)$a['client_name']) ?: (string)$a['login'];
                        $upn  = $g->unique_login($name);
                        $pass = M365Graph::generate_password();
                        $u    = $g->create_user($upn, $name, $pass, true);
                        if (empty($u['id'])) throw new RuntimeException('Graph nie zwrócił identyfikatora konta.');
                        db()->prepare("UPDATE k30_ti_student_accounts SET feer_ms_user_id = ?, feer_ms_upn = ?, feer_ms_linked_at = datetime('now') WHERE id = ?")->execute([(string)$u['id'], $upn, (int)$a['id']]);
                        foreach ($skus as $sku) { try { $g->assign_license((string)$u['id'], $sku); } catch (\Throwable $e) { $errors[] = $who . ': konto założone, licencja nie — ' . $e->getMessage(); } }
                        try { ti_send_ms_credentials($a, $upn, $pass); } catch (\Throwable $e) {}
                        $created[] = ['name' => $name, 'upn' => $upn, 'password' => $pass];
                        break;
                    case 'license':
                    case 'unlicense':
                        if ($a['feer_ms_user_id'] === '') throw new RuntimeException('brak powiązanego konta FEER.');
                        if (self::isStaffAccount((string)$a['feer_ms_user_id'], (string)$a['feer_ms_upn'])) throw new RuntimeException('konto pracownika SZO — pominięto.');
                        foreach ($skus as $sku) $op === 'license' ? $g->assign_license((string)$a['feer_ms_user_id'], $sku) : $g->remove_license((string)$a['feer_ms_user_id'], $sku);
                        break;
                    case 'unlink':
                        db()->prepare("UPDATE k30_ti_student_accounts SET feer_ms_user_id = '', feer_ms_upn = '', feer_ms_linked_at = NULL WHERE id = ?")->execute([(int)$a['id']]);
                        db()->prepare("UPDATE k30_ti_feer_generated SET account_id = NULL WHERE account_id = ?")->execute([(int)$a['id']]);   // konto z puli wraca do przydziału
                        break;
                    default: throw new RuntimeException('Nieznana akcja.');
                }
                $done++;
            } catch (\Throwable $e) { $errors[] = $who . ': ' . $e->getMessage(); }
        }
        try {
            require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            audit_log('ti.m365_feer.' . $op, ['accounts' => array_map(fn($a) => (int)$a['id'], $accs), 'skus' => $skus, 'done' => $done, 'errors' => count($errors)], null);
        } catch (\Throwable $e) {}
        return ['done' => $done, 'errors' => $errors, 'created' => $created];
    }

    /** Licencje konta (nazwy SKU) — do kolumny w tabeli; puste przy błędzie. */
    public static function licenseNames(M365Graph $g, string $userId): array
    {
        if ($userId === '') return [];
        try { return array_map(fn($l) => (string)($l['skuPartNumber'] ?? ''), $g->user_license_details($userId)); } catch (\Throwable $e) { return []; }
    }

    /**
     * Plan kont masowych {nazwa}{sep}{nr}@domena (np. excel.01) — zajęte loginy w tenancie są pomijane, numeracja idzie dalej.
     * @return array{rows:list<array{upn:string,label:string,nr:string}>, skipped:list<int>, error:?string}
     */
    public static function bulkPlan(M365Graph $g, string $label, int $count, int $start = 1, string $sep = '.', int $pad = 2): array
    {
        require_once dirname(__DIR__, 2) . '/ms_edu_bulk/logic/msEduBulk.php';
        $slug = msEduBulkAscii($label);
        if ($slug === '') return ['rows' => [], 'skipped' => [], 'error' => 'Podaj nazwę (np. nazwę szkolenia).'];
        if (!in_array($sep, ['', '.', '_', '-'], true)) $sep = '.';
        $count = min(max($count, 1), 200); $start = max($start, 1); $pad = min(max($pad, 0), 4);
        $dom = self::domain(); $rows = []; $skipped = []; $nr = $start; $guard = 0;
        while (count($rows) < $count && $guard++ < $count + 500) {
            $num = $pad > 0 ? str_pad((string)$nr, $pad, '0', STR_PAD_LEFT) : (string)$nr;
            $upn = $slug . $sep . $num . '@' . $dom;
            if ($g->login_exists($upn)) { $skipped[] = $nr; $nr++; continue; }
            $rows[] = ['upn' => $upn, 'label' => trim($label), 'nr' => $num];
            $nr++;
        }
        return ['rows' => $rows, 'skipped' => $skipped, 'error' => null];
    }

    /** Zakłada konta z planu (+ 1–2 licencje). @return array{ok:list<array>, errors:list<string>} */
    public static function bulkCreate(M365Graph $g, array $plan, array $skus, string $fixedPass, int $by): array
    {
        self::migrate();
        $skus = array_values(array_unique(array_filter(array_map('trim', $skus), fn($s) => preg_match('/^[0-9a-f-]{36}$/i', $s))));
        $out = ['ok' => [], 'errors' => []];
        foreach ($plan as $r) {
            try {
                $pass = $fixedPass !== '' ? $fixedPass : M365Graph::generate_password(16);
                $u = $g->create_user_full($r['upn'], $r['label'], $r['nr'], $pass, $r['label']);
                $warn = [];
                foreach ($skus as $sku) { try { $g->assign_license((string)$u['id'], $sku); } catch (\Throwable $e) { $warn[] = 'licencja: ' . $e->getMessage(); } }
                db()->prepare("INSERT OR REPLACE INTO k30_ti_feer_generated (ms_user_id, upn, label, skus, created_by, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))")
                    ->execute([(string)$u['id'], $r['upn'], $r['label'], implode(',', $skus), $by ?: null]);
                // ten sam kształt co w generatorze EDU — wydruki msEduBulkPdf / PdfCards
                $out['ok'][] = ['imie' => $r['label'], 'nazwisko' => $r['nr'], 'klasa' => $r['label'], 'upn' => $r['upn'], 'password' => $pass, 'anonim' => 1, 'warn' => implode('; ', $warn)];
            } catch (\Throwable $e) { $out['errors'][] = $r['upn'] . ': ' . $e->getMessage(); }
        }
        try {
            require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            audit_log('ti.m365_feer.bulk_create', ['created' => count($out['ok']), 'errors' => count($out['errors']), 'skus' => $skus], null);
        } catch (\Throwable $e) {}
        return $out;
    }

    /** Wygenerowane konta (najnowsze najpierw) z kursantem, jeśli przypisane. */
    public static function generated(string $q = ''): array
    {
        self::migrate();
        $w = '1=1'; $p = [];
        if ($q !== '') { $w = '(g.upn LIKE ? OR g.label LIKE ? OR c.name LIKE ?)'; $p = ["%$q%", "%$q%", "%$q%"]; }
        return db_all("SELECT g.*, c.name AS client_name FROM k30_ti_feer_generated g
                         LEFT JOIN k30_ti_student_accounts a ON a.id = g.account_id LEFT JOIN k30_clients c ON c.id = a.client_id
                        WHERE $w ORDER BY g.id DESC LIMIT 400", $p);
    }

    /** Przypisuje wygenerowane konto kursantowi (bez konta FEER). */
    public static function assignGenerated(int $genId, int $accountId): void
    {
        self::migrate();
        $gRow = db_one("SELECT * FROM k30_ti_feer_generated WHERE id = ?", [$genId]);
        $acc  = db_one("SELECT id, feer_ms_user_id FROM k30_ti_student_accounts WHERE id = ? AND is_active = 1", [$accountId]);
        if (!$gRow || !$acc) throw new RuntimeException('nie znaleziono konta albo kursanta.');
        if ($gRow['account_id']) throw new RuntimeException('to konto jest już przypisane.');
        if ($acc['feer_ms_user_id'] !== '') throw new RuntimeException('kursant ma już konto FEER.');
        db()->prepare("UPDATE k30_ti_student_accounts SET feer_ms_user_id = ?, feer_ms_upn = ?, feer_ms_linked_at = datetime('now') WHERE id = ?")->execute([$gRow['ms_user_id'], $gRow['upn'], $accountId]);
        db()->prepare("UPDATE k30_ti_feer_generated SET account_id = ? WHERE id = ?")->execute([$accountId, $genId]);
    }
}
