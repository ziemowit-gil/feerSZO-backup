<?php
declare(strict_types=1);

/**
 * modules/sso_logto/logic/CloudAssignIssues.php — problemy z przypisaniami, przez które ktoś nie trafia do grup w chmurze
 * (CloudTeamsSync), z podpowiedzią, jak je naprawić. Polecenie 2026-11-14: „popup dla admina, jak problem z przypisaniami,
 * i sugestia, jak przypisać”. Okno pokazuje /ssoadmin (view/assignIssuesModal.php).
 *
 * Rodzaje problemów:
 *   • nocloud   — osoba ma trafić do grupy (zespół, obszar, działanie, jednostka), ale nie ma konta w chmurze;
 *                 podpowiedź: karta konta przy jej aktywnej umowie (Zarządzanie dostępem #sso) albo samodzielne założenie po logowaniu;
 *   • nounit    — aktywna umowa (zlecenie, wolontariat, dzieło, praca) bez jednostki organizacyjnej; podpowiedź: jednostka konta SZO
 *                 tej osoby albo jednostka najczęstsza w aktywnych umowach tego typu;
 *   • noaction  — aktywna umowa wolontariacka bez działania; podpowiedź: działanie najczęstsze w aktywnych umowach tej samej jednostki;
 *   • nocoord   — aktywne działanie bez koordynatora.
 * Konta techniczne i adresy bez domeny (np. serwis@local) pomijamy.
 *
 * Ignorowanie (polecenie 2026-11-14 „pozwól ignorować ostrzeżenia o nieprzypisanych jednostkach”): tabela sso_assign_ignores, wspólna dla
 * wszystkich Koordynatorów IT. Klucz pozycji: nocloud:{e-mail}, nounit:{typ}:{nr umowy}, noaction:{nr umowy}, nocoord:{nr działania};
 * cały rodzaj: type:{rodzaj}. Zignorowane nie liczą się do okna; można je przywrócić.
 * Pozycja „nocloud” ma też „create”: [ctype, cid] (konto z umowy) albo [user_id] (bez umowy: konto z danych SZO + zgłoszenie w Helpdesku).
 */
require_once __DIR__ . '/CloudTeamsSync.php';

final class CloudAssignIssues
{
    /** Typy umów z jednostką: tabela umowy_{typ} => nazwa w tekście. */
    private const TYPES = ['zlecenie' => 'zlecenie', 'wolontariat' => 'wolontariat', 'dzielo' => 'dzieło', 'praca' => 'umowa o pracę'];

    /**
     * @return list<array{key:string, title:string, hint:string, count:int,
     *               items:list<array{key:string, name:string, detail:string, suggest:string, url:string, action:string, create?:array}>}>
     *         tylko rodzaje, w których coś jest; w każdym najwyżej $limit pozycji (count = wszystkie)
     */
    public static function collect(int $limit = 20): array
    {
        $out = [];
        $ign = self::ignoredKeys();
        foreach ([self::noCloud(), self::noUnit(), self::noAction(), self::noCoordinator()] as $issue) {
            if (isset($ign['type:' . $issue['key']])) continue;   // cały rodzaj zignorowany
            $issue['items'] = array_values(array_filter($issue['items'], fn($it) => !isset($ign[$it['key']])));
            if (!$issue['items']) continue;
            $issue['count'] = count($issue['items']);
            $issue['items'] = array_slice($issue['items'], 0, $limit);
            $out[] = $issue;
        }
        return $out;
    }

    // ── Ignorowanie ─────────────────────────────────────────────────────────────────────────

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db()->exec("CREATE TABLE IF NOT EXISTS sso_assign_ignores (
            issue_key  VARCHAR(200) NOT NULL PRIMARY KEY,
            label      VARCHAR(255) NOT NULL DEFAULT '',
            ignored_by INTEGER NULL,
            ignored_at VARCHAR(19) NULL)");
    }

    /** Klucze zignorowanych ostrzeżeń [klucz => true]. */
    private static function ignoredKeys(): array
    {
        try { self::migrate(); return array_fill_keys(array_column(db_all("SELECT issue_key FROM sso_assign_ignores"), 'issue_key'), true); }
        catch (\Throwable $e) { return []; }
    }

    /** Zignorowane ostrzeżenia do listy w oknie: key, label, by (nazwa), at. */
    public static function ignoredList(): array
    {
        try {
            self::migrate();
            return db_all("SELECT i.issue_key AS `key`, i.label, i.ignored_at AS at, COALESCE(u.name, '') AS `by`
                             FROM sso_assign_ignores i LEFT JOIN users u ON u.id = i.ignored_by ORDER BY i.ignored_at DESC");
        } catch (\Throwable $e) { return []; }
    }

    /** Poprawny klucz ostrzeżenia (pozycja albo cały rodzaj). */
    public static function validKey(string $key): bool
    {
        return (bool)preg_match('/^(type:(nocloud|nounit|noaction|nocoord)|nocloud:[^\s:]{3,190}|nounit:(zlecenie|wolontariat|dzielo|praca):\d{1,10}|noaction:\d{1,10}|nocoord:\d{1,10})$/', $key);
    }

    /** Ignoruj ($on) albo przywróć ostrzeżenie. Zapis w audit_logs. */
    public static function setIgnored(string $key, bool $on, string $label, int $by): bool
    {
        if (!self::validKey($key)) return false;
        self::migrate();
        $label = mb_substr(trim($label), 0, 250);
        if ($on) {
            db_exec("DELETE FROM sso_assign_ignores WHERE issue_key = ?", [$key]);
            db_exec("INSERT INTO sso_assign_ignores (issue_key, label, ignored_by, ignored_at) VALUES (?, ?, ?, ?)", [$key, $label, $by ?: null, date('Y-m-d H:i:s')]);
        } else {
            db_exec("DELETE FROM sso_assign_ignores WHERE issue_key = ?", [$key]);
        }
        try {
            if (!function_exists('audit_log')) require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            audit_log($on ? 'sso.assign_ignore' : 'sso.assign_restore', ['klucz' => $key, 'opis' => $label]);
        } catch (\Throwable $e) {}
        return true;
    }

    /** Liczba wszystkich problemów (do przycisku i do decyzji, czy pokazać okno). */
    public static function total(array $issues): int
    {
        return array_sum(array_column($issues, 'count'));
    }

    private static function app(): string
    {
        return rtrim(APP_URL, '/');
    }

    /** Prawdziwy adres e-mail osoby (bez kont technicznych typu x@local). */
    private static function realEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && !str_ends_with($email, '@local');
    }

    /** Osoby, które mają trafić do grup, a nie mają konta w chmurze; jedna pozycja na osobę z listą jej grup. */
    private static function noCloud(): array
    {
        $people = [];
        $sources = [CloudTeamsSync::teams(), CloudTeamsSync::workspaces(), CloudTeamsSync::actions(), CloudTeamsSync::units()];
        foreach ($sources as $items) {
            foreach ($items as $e) {
                if (!$e['active']) continue;
                foreach ($e['withoutRefs'] ?? [] as $name => $ref) {
                    if (!self::realEmail($ref['email'])) continue;
                    $key = $ref['email'];
                    $people[$key] ??= ['name' => (string)$name, 'groups' => [], 'ref' => $ref];
                    $people[$key]['groups'][] = $e['display'] !== '' ? $e['display'] : $e['name'];
                    if ($people[$key]['ref']['ctype'] === '' && $ref['ctype'] !== '') $people[$key]['ref'] = $ref;
                }
            }
        }
        $rows = [];
        foreach ($people as $p) {
            $ref = $p['ref'];
            [$ctype, $cid] = $ref['ctype'] !== '' ? [$ref['ctype'], $ref['cid']] : self::contractByEmail($ref['email']);
            $groups = 'Grupy: ' . implode(', ', array_unique($p['groups'])) . '.';
            $key = 'nocloud:' . $ref['email'];
            if ($ctype !== '') {
                $rows[] = ['key' => $key, 'name' => $p['name'], 'detail' => $groups,
                    'suggest' => 'Załóż konto z danych umowy (' . (self::TYPES[$ctype] ?? $ctype) . ' nr ' . $cid . '). Login i hasło pójdą SMS-em, osoba trafi do grup w ciągu 2 minut.',
                    'url' => '', 'action' => '', 'create' => ['ctype' => $ctype, 'cid' => $cid]];
            } elseif ($ref['user_id'] > 0) {
                $rows[] = ['key' => $key, 'name' => $p['name'], 'detail' => $groups,
                    'suggest' => 'Ta osoba nie ma aktywnej umowy. Możesz mimo to założyć konto z danych SZO: powstanie też zgłoszenie w Helpdesku, żeby uzupełnić umowę.',
                    'url' => '', 'action' => '', 'create' => ['user_id' => $ref['user_id']]];
            } else {
                $rows[] = ['key' => $key, 'name' => $p['name'], 'detail' => $groups,
                    'suggest' => 'Ta osoba nie ma aktywnej umowy ani konta SZO. Konto w chmurze założy po zalogowaniu do SZO.', 'url' => '', 'action' => ''];
            }
        }
        usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));
        return ['key' => 'nocloud', 'title' => 'Osoby bez konta w chmurze', 'items' => $rows,
                'hint' => 'Te osoby należą w SZO do zespołu, obszaru, działania albo jednostki, ale bez konta w chmurze nie trafią do grupy.'];
    }

    /** Pierwsza aktywna umowa osoby po adresie e-mail: [typ, numer] albo ['', 0]. */
    private static function contractByEmail(string $email): array
    {
        if ($email === '') return ['', 0];
        foreach (array_keys(self::TYPES) as $t) {
            try {
                $r = db_one("SELECT id FROM umowy_{$t} WHERE LOWER(TRIM(email)) = ? AND " . CloudTeamsSync::activeContractSql() . " ORDER BY id DESC LIMIT 1",
                            [$email, date('Y-m-d')]);
            } catch (\Throwable $e) { $r = null; }
            if ($r) return [$t, (int)$r['id']];
        }
        return ['', 0];
    }

    /** Nazwy jednostek [id => nazwa]. */
    private static function unitNames(): array
    {
        try { return array_column(db_all("SELECT id, name FROM org_units WHERE COALESCE(status, 'active') = 'active'"), 'name', 'id'); }
        catch (\Throwable $e) { return []; }
    }

    /** Aktywne umowy bez jednostki organizacyjnej, z propozycją jednostki. */
    private static function noUnit(): array
    {
        $units = self::unitNames();
        $rows = [];
        if ($units) foreach (self::TYPES as $t => $label) {
            try {
                $list = db_all("SELECT id, imie_nazwisko, email FROM umowy_{$t} WHERE COALESCE(org_unit_id, 0) = 0 AND " . CloudTeamsSync::activeContractSql() . " ORDER BY imie_nazwisko",
                               [date('Y-m-d')]);
                $common = db_one("SELECT org_unit_id, COUNT(*) n FROM umowy_{$t} WHERE COALESCE(org_unit_id, 0) > 0 AND " . CloudTeamsSync::activeContractSql()
                               . " GROUP BY org_unit_id ORDER BY n DESC LIMIT 1", [date('Y-m-d')]);
            } catch (\Throwable $e) { continue; }
            foreach ($list as $c) {
                $suggest = '';
                $email = strtolower(trim((string)$c['email']));
                if ($email !== '') {
                    $u = db_one("SELECT org_unit_id FROM users WHERE LOWER(email) = ? AND COALESCE(org_unit_id, 0) > 0", [$email]);
                    if ($u && isset($units[(int)$u['org_unit_id']])) $suggest = 'Proponowana jednostka: ' . $units[(int)$u['org_unit_id']] . ' (taka jest na koncie SZO tej osoby).';
                }
                if ($suggest === '' && $common && isset($units[(int)$common['org_unit_id']])) {
                    $suggest = 'Proponowana jednostka: ' . $units[(int)$common['org_unit_id']] . ' (najczęstsza w aktywnych umowach tego typu).';
                }
                $rows[] = ['key' => 'nounit:' . $t . ':' . (int)$c['id'], 'name' => (string)$c['imie_nazwisko'], 'detail' => ucfirst($label) . ' nr ' . (int)$c['id'] . '.',
                    'suggest' => $suggest !== '' ? $suggest : 'Wybierz jednostkę w edycji umowy.',
                    'url' => self::app() . '/contracts/' . $t . '/edit.php?id=' . (int)$c['id'], 'action' => 'Przypisz jednostkę'];
            }
        }
        return ['key' => 'nounit', 'title' => 'Umowy bez jednostki organizacyjnej', 'items' => $rows,
                'hint' => 'Osoba z aktywną umową trafia do grupy swojej jednostki. Bez jednostki w umowie nie trafi do żadnej.'];
    }

    /** Aktywne umowy wolontariackie bez działania, z propozycją działania. */
    private static function noAction(): array
    {
        $rows = [];
        try {
            $actions = array_column(db_all("SELECT id, nazwa FROM actions WHERE status NOT IN ('zakończone', 'anulowane')"), 'nazwa', 'id');
            if (!$actions) return ['key' => 'noaction', 'title' => '', 'hint' => '', 'items' => []];
            $list = db_all("SELECT id, imie_nazwisko, org_unit_id FROM umowy_wolontariat WHERE COALESCE(action_id, 0) = 0 AND "
                         . CloudTeamsSync::activeContractSql() . " ORDER BY imie_nazwisko", [date('Y-m-d')]);
            $byUnit = [];   // org_unit_id => najczęstsze aktywne działanie w aktywnych umowach tej jednostki
            foreach (db_all("SELECT COALESCE(org_unit_id, 0) AS u, action_id, COUNT(*) n FROM umowy_wolontariat WHERE COALESCE(action_id, 0) > 0 AND "
                          . CloudTeamsSync::activeContractSql() . " GROUP BY u, action_id ORDER BY n DESC", [date('Y-m-d')]) as $r) {
                if (!isset($byUnit[(int)$r['u']]) && isset($actions[(int)$r['action_id']])) $byUnit[(int)$r['u']] = (int)$r['action_id'];
            }
        } catch (\Throwable $e) {
            return ['key' => 'noaction', 'title' => '', 'hint' => '', 'items' => []];
        }
        foreach ($list as $c) {
            $fromUnit = $byUnit[(int)$c['org_unit_id']] ?? 0;
            $aid = $fromUnit ?: (count($actions) === 1 ? (int)array_key_first($actions) : 0);
            $why = $fromUnit ? ' (najczęstsze w umowach tej jednostki).' : ' (jedyne trwające działanie).';
            $rows[] = ['key' => 'noaction:' . (int)$c['id'], 'name' => (string)$c['imie_nazwisko'], 'detail' => 'Wolontariat nr ' . (int)$c['id'] . '.',
                'suggest' => $aid ? 'Proponowane działanie: ' . $actions[$aid] . $why : 'Wybierz działanie w edycji umowy.',
                'url' => self::app() . '/contracts/wolontariat/edit.php?id=' . (int)$c['id'], 'action' => 'Przypisz działanie'];
        }
        return ['key' => 'noaction', 'title' => 'Umowy wolontariackie bez działania', 'items' => $rows,
                'hint' => 'Wolontariusz trafia do grupy działania, do którego przypisana jest jego umowa.'];
    }

    /** Trwające działania bez koordynatora. */
    private static function noCoordinator(): array
    {
        try {
            $list = db_all("SELECT id, nazwa FROM actions WHERE status NOT IN ('zakończone', 'anulowane') AND COALESCE(koordynator_id, 0) = 0 ORDER BY nazwa");
        } catch (\Throwable $e) { $list = []; }
        $rows = array_map(fn($a) => ['key' => 'nocoord:' . (int)$a['id'], 'name' => (string)$a['nazwa'], 'detail' => 'Działanie nr ' . (int)$a['id'] . '.',
            'suggest' => 'Wskaż koordynatora w karcie działania. Koordynator trafia do grupy działania.',
            'url' => self::app() . '/dzialania/' . (int)$a['id'], 'action' => 'Wskaż koordynatora'], $list);
        return ['key' => 'nocoord', 'title' => 'Działania bez koordynatora', 'items' => $rows,
                'hint' => 'Grupa działania powstaje, gdy ma do niej trafić pierwsza osoba z kontem w chmurze. Często jest nią koordynator.'];
    }
}
