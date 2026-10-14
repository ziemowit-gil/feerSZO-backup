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
}
