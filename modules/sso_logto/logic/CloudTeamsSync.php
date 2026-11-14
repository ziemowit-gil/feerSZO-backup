<?php
declare(strict_types=1);

/**
 * modules/sso_logto/logic/CloudTeamsSync.php — grupy z SZO w chmurze plików (Nextcloud / ownCloud). Polecenia: 2026-11-13
 * „zespoły też pobierz z SZO”; 2026-11-14 „dodaj obszary robocze”, „zespoły to działania i grupy w umowach”, „obszar = grupa”,
 * „jednostka organizacyjna w umowie”, „podziel to wszystko” (każdy rodzaj ma własne grupy).
 *
 * Rodzaje (przedrostek grupy → źródło w SZO, nazwa wyświetlana):
 *   • szo-zespol-{id}    — zespół z Zadań (task_teams + task_team_members); nazwa zespołu;
 *   • szo-obszar-{id}    — obszar roboczy Zadań: osoby dopisane (task_workspace_members) + członkowie aktywnych zespołów obszaru
 *                          (task_workspace_teams); „Obszar: {nazwa}”;
 *   • szo-dzialanie-{id} — Działanie (/dzialania, tabela actions): koordynator + osoby z aktywnych umów wolontariackich z tym
 *                          działaniem (umowy_wolontariat.action_id); „Działanie: {nazwa}”; aktywne = nie zakończone i nie anulowane;
 *   • szo-jednostka-{id} — jednostka organizacyjna (org_units): użytkownicy z users.org_unit_id, kierownik (supervisor_user_id)
 *                          i osoby z aktywnych umów z tą jednostką (zlecenie, wolontariat, dzieło, praca); „Jednostka: {nazwa}”.
 * Aktywna umowa = status z grupy „active” słownika statusów (contractStatusSqlList) i data zakończenia pusta albo nie minęła.
 * Konto w chmurze osoby: sso_accounts.cloud_uid (po user_id, a gdy brak, po adresie e-mail z konta SZO albo z umowy). Zasady:
 *   • w grupie są dokładnie osoby z kontem w chmurze (i aktywnym kontem SZO, gdy to użytkownik): brakujących dopisujemy, resztę wypisujemy;
 *   • grupę zakładamy dopiero, gdy ktoś ma do niej trafić (bez pustych grup dla każdego działania);
 *   • wyłączony albo usunięty → grupa zostaje (udostępnienia nie znikają), ale bez członków;
 *   • ruszamy WYŁĄCZNIE grupy z przedrostkami z KINDS; inne grupy w chmurze zostają bez zmian.
 * Tryb podglądu ($dry) niczego nie zmienia. Wywołują: /ssoadmin#zespoly, cli/ssoCloudTeamsSync.php, agent CRON sso_cloud_teams (co 2 min).
 */
require_once dirname(__DIR__) . '/lib/SsoConfig.php';
require_once dirname(__DIR__) . '/lib/CloudGroups.php';

final class CloudTeamsSync
{
    public const PREFIX        = 'szo-zespol-';
    public const WS_PREFIX     = 'szo-obszar-';
    public const ACTION_PREFIX = 'szo-dzialanie-';
    public const UNIT_PREFIX   = 'szo-jednostka-';

    /** Rodzaje grup: przedrostek => [etykieta w wynikach, dopisek grupy bez odpowiednika w SZO, przedrostek nazwy wyświetlanej]. */
    private const KINDS = [
        self::PREFIX        => ['zespół', 'zespołu nie ma w SZO', ''],
        self::WS_PREFIX     => ['obszar', 'obszaru nie ma w SZO', 'Obszar: '],
        self::ACTION_PREFIX => ['działanie', 'działania nie ma w SZO', 'Działanie: '],
        self::UNIT_PREFIX   => ['jednostka', 'jednostki nie ma w SZO', 'Jednostka: '],
    ];

    /** Typy umów z jednostką organizacyjną (tabela umowy_{typ}, kolumna org_unit_id). */
    private const UNIT_CONTRACTS = ['zlecenie', 'wolontariat', 'dzielo', 'praca'];

    public static function gid(int $teamId): string
    {
        return self::PREFIX . $teamId;
    }

    public static function wsGid(int $workspaceId): string
    {
        return self::WS_PREFIX . $workspaceId;
    }

    /**
     * Zespoły z członkami: [team_id => ['id','name','display','active', 'want' => [cloud_uid => nazwa osoby], 'without' => [nazwy bez konta w chmurze]]].
     * Wszystkie zespoły (także wyłączone); wyłączony ma pustą listę „want”. Ten sam kształt mają workspaces(), actions(), units().
     */
    public static function teams(): array
    {
        $out = self::entities("SELECT id, name, is_active AS active FROM task_teams ORDER BY name", self::PREFIX);
        if ($out) self::members($out, ["SELECT m.team_id AS eid, m.user_id, u.name, u.email, COALESCE(u.is_active, 1) AS is_active
                                          FROM task_team_members m LEFT JOIN users u ON u.id = m.user_id"]);
        return $out;
    }

    /** Obszary robocze z członkami (osoby dopisane wprost + członkowie aktywnych zespołów obszaru). */
    public static function workspaces(): array
    {
        $out = self::entities("SELECT id, name, is_active AS active FROM task_workspaces ORDER BY name", self::WS_PREFIX);
        if ($out) self::members($out, [
            "SELECT m.workspace_id AS eid, m.user_id, u.name, u.email, COALESCE(u.is_active, 1) AS is_active
               FROM task_workspace_members m LEFT JOIN users u ON u.id = m.user_id",
            "SELECT wt.workspace_id AS eid, tm.user_id, u.name, u.email, COALESCE(u.is_active, 1) AS is_active
               FROM task_workspace_teams wt
               JOIN task_teams t ON t.id = wt.team_id AND t.is_active = 1
               JOIN task_team_members tm ON tm.team_id = wt.team_id
               LEFT JOIN users u ON u.id = tm.user_id",
        ]);
        return $out;
    }

    /** Działania (/dzialania): koordynator + osoby z aktywnych umów wolontariackich z tym działaniem. */
    public static function actions(): array
    {
        $out = self::entities("SELECT id, nazwa AS name, CASE WHEN status IN ('zakończone', 'anulowane') THEN 0 ELSE 1 END AS active
                                 FROM actions ORDER BY nazwa", self::ACTION_PREFIX);
        if ($out) self::members($out, [
            "SELECT a.id AS eid, a.koordynator_id AS user_id, u.name, u.email, COALESCE(u.is_active, 1) AS is_active
               FROM actions a JOIN users u ON u.id = a.koordynator_id",
            ["SELECT action_id AS eid, 0 AS user_id, imie_nazwisko AS name, email, 1 AS is_active, 'wolontariat' AS ctype, id AS cid
                FROM umowy_wolontariat WHERE COALESCE(action_id, 0) > 0 AND " . self::activeContractSql(), [date('Y-m-d')]],
        ]);
        return $out;
    }

    /** Jednostki organizacyjne: użytkownicy jednostki, kierownik i osoby z aktywnych umów z tą jednostką. */
    public static function units(): array
    {
        $out = self::entities("SELECT id, name, CASE WHEN COALESCE(status, 'active') = 'active' THEN 1 ELSE 0 END AS active
                                 FROM org_units ORDER BY name", self::UNIT_PREFIX);
        if (!$out) return [];
        $sql = [
            "SELECT org_unit_id AS eid, id AS user_id, name, email, COALESCE(is_active, 1) AS is_active FROM users WHERE COALESCE(org_unit_id, 0) > 0",
            "SELECT o.id AS eid, o.supervisor_user_id AS user_id, u.name, u.email, COALESCE(u.is_active, 1) AS is_active
               FROM org_units o JOIN users u ON u.id = o.supervisor_user_id",
        ];
        foreach (self::UNIT_CONTRACTS as $t) {
            $sql[] = ["SELECT org_unit_id AS eid, 0 AS user_id, imie_nazwisko AS name, email, 1 AS is_active, '{$t}' AS ctype, id AS cid
                         FROM umowy_{$t} WHERE COALESCE(org_unit_id, 0) > 0 AND " . self::activeContractSql(), [date('Y-m-d')]];
        }
        self::members($out, $sql);
        return $out;
    }

    /** Warunek SQL aktywnej umowy (status z grupy „active”, data zakończenia pusta albo nie minęła); parametr: dzisiejsza data. */
    public static function activeContractSql(): string
    {
        $in = function_exists('contractStatusSqlList') ? contractStatusSqlList('active') : "'podpisana','w realizacji','aneks'";
        return "status IN ($in) AND (COALESCE(data_zakonczenia, '') = '' OR data_zakonczenia >= ?)";
    }

    /** Wiersze (id, name, active) → szkielety; nazwa wyświetlana w chmurze = przedrostek rodzaju + nazwa. Brak tabeli = []. */
    private static function entities(string $sql, string $prefix): array
    {
        try {
            $rows = db_all($sql);
        } catch (\Throwable $e) {
            return [];   // moduł bez swoich tabel
        }
        $out = [];
        foreach ($rows as $r) {
            $name = trim((string)$r['name']);
            $out[(int)$r['id']] = ['id' => (int)$r['id'], 'name' => $name, 'display' => $name !== '' ? self::KINDS[$prefix][2] . $name : '',
                                   'active' => (int)$r['active'] === 1, 'want' => [], 'without' => [], 'withoutRefs' => []];
        }
        return $out;
    }

    /**
     * Członkowie (zapytania zwracające eid, user_id, name, email, is_active, przy umowach także ctype i cid; element może być
     * [sql, parametry]) → listy „want” i „without” aktywnych pozycji; „withoutRefs” [nazwa => user_id, email, ctype, cid] mówi,
     * skąd jest osoba bez konta w chmurze (podpowiedzi w CloudAssignIssues). Zapytanie bez swojej tabeli albo kolumny pomijamy.
     */
    private static function members(array &$out, array $queries): void
    {
        foreach ($queries as $q) {
            [$sql, $params] = is_array($q) ? $q : [$q, []];
            try { $rows = db_all($sql, $params); } catch (\Throwable $e) { continue; }
            foreach ($rows as $m) {
                $eid = (int)$m['eid'];
                if (!isset($out[$eid]) || !$out[$eid]['active']) continue;
                if ((int)$m['is_active'] !== 1) continue;   // konto SZO wyłączone: nie dopisujemy do grupy
                $name = trim((string)($m['name'] ?? '')) ?: (string)($m['email'] ?? ('#' . $m['user_id']));
                $uid = self::cloudUid((int)$m['user_id'], (string)($m['email'] ?? ''));
                if ($uid !== '') { $out[$eid]['want'][$uid] = $name; continue; }
                if (!in_array($name, $out[$eid]['without'], true)) $out[$eid]['without'][] = $name;
                $ref = ['user_id' => (int)$m['user_id'], 'email' => strtolower(trim((string)($m['email'] ?? ''))), 'ctype' => (string)($m['ctype'] ?? ''), 'cid' => (int)($m['cid'] ?? 0)];
                $prev = $out[$eid]['withoutRefs'][$name] ?? null;
                if (!$prev || ($prev['ctype'] === '' && $ref['ctype'] !== '')) $out[$eid]['withoutRefs'][$name] = $ref;   // umowa daje lepszą podpowiedź
            }
        }
        // osoba z kontem w chmurze z innego źródła (np. umowa i konto SZO) nie jest „bez konta”
        foreach ($out as &$e) {
            if (!$e['without'] || !$e['want']) continue;
            $e['without'] = array_values(array_diff($e['without'], $e['want']));
            $e['withoutRefs'] = array_intersect_key($e['withoutRefs'], array_flip($e['without']));
        }
        unset($e);
    }

    /** Login osoby w chmurze ('' gdy nie ma konta w chmurze). */
    private static function cloudUid(int $userId, string $email): string
    {
        static $cache = [];
        $key = $userId . '|' . strtolower(trim($email));
        if (isset($cache[$key])) return $cache[$key];
        $r = null;
        try {
            if ($userId > 0) $r = db_one("SELECT cloud_uid FROM sso_accounts WHERE user_id = ? AND COALESCE(cloud_uid,'') <> '' LIMIT 1", [$userId]);
            if (!$r && trim($email) !== '') $r = db_one("SELECT cloud_uid FROM sso_accounts WHERE email = ? AND COALESCE(cloud_uid,'') <> ''", [strtolower(trim($email))]);
        } catch (\Throwable $e) { $r = null; }
        return $cache[$key] = (string)($r['cloud_uid'] ?? '');
    }

    /**
     * Synchronizacja. Zwraca ['teams' => list<wiersz>, 'error' => ''], wiersz: kind ('zespół'|'obszar'|'działanie'|'jednostka'), id, name,
     * gid, active, group ('utworzona'|'jest'|'do utworzenia'|'—'), renamed (bool), added, removed (loginy w chmurze), without (osoby
     * bez konta w chmurze), error. Pomija pozycje wyłączone bez grupy (nic do zrobienia).
     */
    public static function run(bool $dry = false, ?CloudGroups $groups = null): array
    {
        $res = ['teams' => [], 'error' => ''];
        try {
            $groups = $groups ?? new CloudGroups(SsoConfig::cloud());
            $existing = [];
            foreach (self::KINDS as $prefix => $_) $existing[$prefix] = $groups->listByPrefix($prefix);   // wszystkie listy przed pierwszą zmianą
        } catch (\Throwable $e) {
            $res['error'] = $e->getMessage();
            return $res;
        }
        $sources = [self::PREFIX => self::teams(), self::WS_PREFIX => self::workspaces(), self::ACTION_PREFIX => self::actions(), self::UNIT_PREFIX => self::units()];
        foreach (self::KINDS as $prefix => [$kind, $orphan, $label]) {
            array_push($res['teams'], ...self::syncKind($groups, $prefix, $kind, $orphan, $label, $sources[$prefix], $existing[$prefix], $dry));
        }
        if (!$dry) self::audit($res);
        return $res;
    }

    /** Jeden rodzaj grup: zakładanie, nazwy, członkowie, opróżnianie grup bez odpowiednika w SZO. */
    private static function syncKind(CloudGroups $groups, string $prefix, string $kind, string $orphan, string $label, array $items, array $existing, bool $dry): array
    {
        $rows = [];
        foreach ($items as $t) {
            $gid = $prefix . $t['id'];
            $has = array_key_exists($gid, $existing);
            if (!$has && !$t['active']) continue;   // wyłączony bez grupy: nic do zrobienia
            $row = ['kind' => $kind, 'id' => $t['id'], 'name' => $t['name'], 'gid' => $gid, 'active' => $t['active'], 'group' => '—', 'renamed' => false,
                    'added' => [], 'removed' => [], 'without' => $t['without'], 'error' => ''];
            try {
                if (!$has) {
                    if (!$t['want']) { $rows[] = $row; continue; }   // nikt nie ma konta w chmurze: grupę założymy przy pierwszej osobie
                    $row['group'] = $dry ? 'do utworzenia' : 'utworzona';
                    if (!$dry) $groups->create($gid, $t['display'] !== '' ? $t['display'] : $gid);
                    $current = [];
                } else {
                    $row['group'] = 'jest';
                    // nazwa zmieniona w SZO albo grupa bez nazwy wyświetlanej; ownCloud jej nie zna (rename() zwraca false)
                    if ($t['active'] && $t['display'] !== '' && $existing[$gid] !== $t['display']) {
                        $row['renamed'] = $dry ? true : $groups->rename($gid, $t['display']);
                    }
                    $current = $groups->members($gid);
                }
                $want = $t['active'] ? array_keys($t['want']) : [];
                foreach (array_diff($want, $current) as $uid) { if (!$dry) $groups->addMember((string)$uid, $gid); $row['added'][] = (string)$uid; }
                foreach (array_diff($current, $want) as $uid) { if (!$dry) $groups->removeMember((string)$uid, $gid); $row['removed'][] = (string)$uid; }
            } catch (\Throwable $e) {
                $row['error'] = $e->getMessage();
            }
            $rows[] = $row;
            unset($existing[$gid]);
        }

        // Grupy z przedrostkiem bez odpowiednika w SZO (usunięte w SZO): opróżniamy, grupę zostawiamy.
        foreach ($existing as $gid => $display) {
            if ($label !== '' && str_starts_with($display, $label)) $display = substr($display, strlen($label));   // rodzaj pokazuje osobna etykieta
            $row = ['kind' => $kind, 'id' => (int)substr($gid, strlen($prefix)), 'name' => $display . ' (' . $orphan . ')', 'gid' => $gid, 'active' => false,
                    'group' => 'jest', 'renamed' => false, 'added' => [], 'removed' => [], 'without' => [], 'error' => ''];
            try {
                foreach ($groups->members($gid) as $uid) { if (!$dry) $groups->removeMember($uid, $gid); $row['removed'][] = $uid; }
            } catch (\Throwable $e) { $row['error'] = $e->getMessage(); }
            $rows[] = $row;
        }
        return $rows;
    }

    /** Krótkie podsumowanie do dziennika CRON i komunikatów: liczby pozycji każdego rodzaju, nowych grup, dopisanych, wypisanych, błędów. */
    public static function summary(array $res): string
    {
        if ($res['error'] !== '') return 'błąd: ' . $res['error'];
        $count = fn(string $kind) => count(array_filter($res['teams'], fn($r) => ($r['kind'] ?? 'zespół') === $kind));
        $made = count(array_filter($res['teams'], fn($r) => in_array($r['group'], ['utworzona', 'do utworzenia'], true)));
        $add = array_sum(array_map(fn($r) => count($r['added']), $res['teams']));
        $rem = array_sum(array_map(fn($r) => count($r['removed']), $res['teams']));
        $err = count(array_filter($res['teams'], fn($r) => $r['error'] !== ''));
        return 'zespołów: ' . $count('zespół') . ', obszarów: ' . $count('obszar') . ', działań: ' . $count('działanie') . ', jednostek: ' . $count('jednostka')
             . ', nowe grupy: ' . $made . ', dopisani: ' . $add . ', wypisani: ' . $rem . ($err ? ', błędy: ' . $err : '');
    }

    private static function audit(array $res): void
    {
        $changed = array_values(array_filter($res['teams'], fn($r) => $r['added'] || $r['removed'] || $r['group'] === 'utworzona' || $r['renamed'] || $r['error'] !== ''));
        if (!$changed) return;
        try {
            if (!function_exists('audit_log')) require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            audit_log('sso.cloud_teams_sync', ['podsumowanie' => self::summary($res), 'zmiany' => array_map(fn($r) => [
                'rodzaj' => $r['kind'], 'nazwa' => $r['name'], 'grupa' => $r['gid'], 'stan' => $r['group'], 'dopisani' => $r['added'], 'wypisani' => $r['removed'], 'blad' => $r['error'],
            ], $changed)]);
        } catch (\Throwable $e) { error_log('[sso] audit grup w chmurze: ' . $e->getMessage()); }
    }
}
