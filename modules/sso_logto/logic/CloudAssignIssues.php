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
 * Konta techniczne i adresy bez domeny (np. serwis@local) pomijamy. Tylko odczyt.
 */
require_once __DIR__ . '/CloudTeamsSync.php';

final class CloudAssignIssues
{
    /** Typy umów z jednostką: tabela umowy_{typ} => nazwa w tekście. */
    private const TYPES = ['zlecenie' => 'zlecenie', 'wolontariat' => 'wolontariat', 'dzielo' => 'dzieło', 'praca' => 'umowa o pracę'];

    /**
     * @return list<array{key:string, title:string, hint:string, count:int,
     *               items:list<array{name:string, detail:string, suggest:string, url:string, action:string}>}>
     *         tylko rodzaje, w których coś jest; w każdym najwyżej $limit pozycji (count = wszystkie)
     */
    public static function collect(int $limit = 20): array
    {
        $out = [];
        foreach ([self::noCloud(), self::noUnit(), self::noAction(), self::noCoordinator()] as $issue) {
            if (!$issue['items']) continue;
            $issue['count'] = count($issue['items']);
            $issue['items'] = array_slice($issue['items'], 0, $limit);
            $out[] = $issue;
        }
        return $out;
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
            if ($ctype !== '') {
                $rows[] = ['name' => $p['name'], 'detail' => $groups,
                    'suggest' => 'Załóż konto w chmurze na karcie umowy (' . (self::TYPES[$ctype] ?? $ctype) . ' nr ' . $cid . '). Osoba trafi do grup w ciągu 2 minut.',
                    'url' => self::app() . '/tozsamosc/access.php?type=' . rawurlencode($ctype) . '&id=' . $cid . '#sso', 'action' => 'Załóż konto'];
            } else {
                $rows[] = ['name' => $p['name'], 'detail' => $groups,
                    'suggest' => 'Ta osoba nie ma aktywnej umowy. Konto w chmurze założy sama: po zalogowaniu do SZO zobaczy prośbę o założenie konta.',
                    'url' => '', 'action' => ''];
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
                $rows[] = ['name' => (string)$c['imie_nazwisko'], 'detail' => ucfirst($label) . ' nr ' . (int)$c['id'] . '.',
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
            $rows[] = ['name' => (string)$c['imie_nazwisko'], 'detail' => 'Wolontariat nr ' . (int)$c['id'] . '.',
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
        $rows = array_map(fn($a) => ['name' => (string)$a['nazwa'], 'detail' => 'Działanie nr ' . (int)$a['id'] . '.',
            'suggest' => 'Wskaż koordynatora w karcie działania. Koordynator trafia do grupy działania.',
            'url' => self::app() . '/dzialania/' . (int)$a['id'], 'action' => 'Wskaż koordynatora'], $list);
        return ['key' => 'nocoord', 'title' => 'Działania bez koordynatora', 'items' => $rows,
                'hint' => 'Grupa działania powstaje, gdy ma do niej trafić pierwsza osoba z kontem w chmurze. Często jest nią koordynator.'];
    }
}
