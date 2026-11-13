<?php
declare(strict_types=1);

/**
 * modules/sso_logto/logic/CloudTeamsSync.php — zespoły i obszary robocze z modułu Zadań SZO jako grupy w chmurze plików
 * (Nextcloud / ownCloud). Polecenia 2026-11-13 „zespoły też pobierz z SZO” i 2026-11-14 „dodaj obszary robocze”.
 *
 * Zespół = task_teams + task_team_members (includes/tasks.php; NIE jednostki organizacyjne) → grupa „szo-zespol-{id}”,
 * nazwa wyświetlana = nazwa zespołu. Obszar roboczy = task_workspaces; członkowie to osoby dopisane do obszaru
 * (task_workspace_members) oraz członkowie aktywnych zespołów przypisanych do obszaru (task_workspace_teams) → grupa
 * „szo-obszar-{id}”, nazwa wyświetlana „Obszar: {nazwa}” (żeby w chmurze nie myliła się z zespołem o podobnej nazwie).
 * Konto w chmurze osoby: sso_accounts.cloud_uid (po user_id, a gdy brak, po adresie e-mail konta SZO). Zasady:
 *   • aktywny zespół / obszar → grupa z przedrostkiem; nazwa wyświetlana działa w Nextcloud (ownCloud zna tylko identyfikator);
 *   • w grupie są dokładnie członkowie z aktywnym kontem SZO i kontem w chmurze: brakujących dopisujemy, pozostałych wypisujemy;
 *   • wyłączony albo usunięty → grupa zostaje (udostępnienia dla grupy nie znikają), ale bez członków;
 *   • ruszamy WYŁĄCZNIE grupy z przedrostkami PREFIX i WS_PREFIX; inne grupy w chmurze zostają bez zmian.
 * Tryb podglądu ($dry) niczego nie zmienia. Wywołują: /ssoadmin#zespoly, cli/ssoCloudTeamsSync.php, agent CRON sso_cloud_teams (co 1 h).
 */
require_once dirname(__DIR__) . '/lib/SsoConfig.php';
require_once dirname(__DIR__) . '/lib/CloudGroups.php';

final class CloudTeamsSync
{
    public const PREFIX    = 'szo-zespol-';
    public const WS_PREFIX = 'szo-obszar-';

    /** Rodzaje grup: przedrostek => [etykieta w wynikach, dopisek grupy bez odpowiednika w SZO]. */
    private const KINDS = [
        self::PREFIX    => ['zespół', 'zespołu nie ma w SZO'],
        self::WS_PREFIX => ['obszar', 'obszaru nie ma w SZO'],
    ];

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
     * Wszystkie zespoły (także wyłączone); wyłączony ma pustą listę „want”.
     */
    public static function teams(): array
    {
        try {
            $rows = db_all("SELECT id, name, is_active FROM task_teams ORDER BY name");
        } catch (\Throwable $e) {
            return [];   // moduł Zadań bez tabel zespołów
        }
        $out = self::entities($rows, fn($name) => $name);
        if (!$out) return [];
        self::members($out, db_all("SELECT m.team_id AS eid, m.user_id, u.name, u.email, COALESCE(u.is_active, 1) AS is_active
                                      FROM task_team_members m LEFT JOIN users u ON u.id = m.user_id"));
        return $out;
    }

    /** Obszary robocze z członkami (osoby dopisane wprost + członkowie aktywnych zespołów obszaru); kształt jak teams(). */
    public static function workspaces(): array
    {
        try {
            $rows = db_all("SELECT id, name, is_active FROM task_workspaces ORDER BY name");
        } catch (\Throwable $e) {
            return [];   // moduł Zadań bez obszarów
        }
        $out = self::entities($rows, fn($name) => 'Obszar: ' . $name);
        if (!$out) return [];
        $direct = "SELECT m.workspace_id AS eid, m.user_id, u.name, u.email, COALESCE(u.is_active, 1) AS is_active
                     FROM task_workspace_members m LEFT JOIN users u ON u.id = m.user_id";
        try {
            $members = db_all($direct . "
                UNION
                SELECT wt.workspace_id AS eid, tm.user_id, u.name, u.email, COALESCE(u.is_active, 1) AS is_active
                  FROM task_workspace_teams wt
                  JOIN task_teams t ON t.id = wt.team_id AND t.is_active = 1
                  JOIN task_team_members tm ON tm.team_id = wt.team_id
                  LEFT JOIN users u ON u.id = tm.user_id");
        } catch (\Throwable $e) {
            $members = db_all($direct);   // bez tabel zespołów: tylko osoby dopisane do obszaru
        }
        self::members($out, $members);
        return $out;
    }

    /** Wiersze tabeli (id, name, is_active) → szkielety zespołów / obszarów; $display buduje nazwę wyświetlaną w chmurze. */
    private static function entities(array $rows, callable $display): array
    {
        $out = [];
        foreach ($rows as $r) {
            $name = trim((string)$r['name']);
            $out[(int)$r['id']] = ['id' => (int)$r['id'], 'name' => $name, 'display' => $name !== '' ? $display($name) : '',
                                   'active' => (int)$r['is_active'] === 1, 'want' => [], 'without' => []];
        }
        return $out;
    }

    /** Członkowie (eid, user_id, name, email, is_active) → listy „want” i „without” aktywnych zespołów / obszarów. */
    private static function members(array &$out, array $members): void
    {
        foreach ($members as $m) {
            $eid = (int)$m['eid'];
            if (!isset($out[$eid]) || !$out[$eid]['active']) continue;
            if ((int)$m['is_active'] !== 1) continue;   // konto SZO wyłączone: nie dopisujemy do grupy
            $name = trim((string)($m['name'] ?? '')) ?: (string)($m['email'] ?? ('#' . $m['user_id']));
            $uid = self::cloudUid((int)$m['user_id'], (string)($m['email'] ?? ''));
            if ($uid === '') { if (!in_array($name, $out[$eid]['without'], true)) $out[$eid]['without'][] = $name; }
            else $out[$eid]['want'][$uid] = $name;
        }
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
     * Synchronizacja. Zwraca ['teams' => list<wiersz>, 'error' => ''], wiersz: kind ('zespół'|'obszar'), id, name, gid, active,
     * group ('utworzona'|'jest'|'do utworzenia'|'—'), renamed (bool), added, removed (loginy w chmurze), without (osoby bez konta
     * w chmurze), error. Najpierw zespoły, potem obszary.
     */
    public static function run(bool $dry = false, ?CloudGroups $groups = null): array
    {
        $res = ['teams' => [], 'error' => ''];
        try {
            $groups = $groups ?? new CloudGroups(SsoConfig::cloud());
            $existing = [];
            foreach (self::KINDS as $prefix => $_) $existing[$prefix] = $groups->listByPrefix($prefix);   // obie listy przed pierwszą zmianą
        } catch (\Throwable $e) {
            $res['error'] = $e->getMessage();
            return $res;
        }
        $sources = [self::PREFIX => self::teams(), self::WS_PREFIX => self::workspaces()];
        foreach (self::KINDS as $prefix => [$kind, $orphan]) {
            array_push($res['teams'], ...self::syncKind($groups, $prefix, $kind, $orphan, $sources[$prefix], $existing[$prefix], $dry));
        }
        if (!$dry) self::audit($res);
        return $res;
    }

    /** Jeden rodzaj grup (zespoły albo obszary): zakładanie, nazwy, członkowie, opróżnianie grup bez odpowiednika w SZO. */
    private static function syncKind(CloudGroups $groups, string $prefix, string $kind, string $orphan, array $items, array $existing, bool $dry): array
    {
        $rows = [];
        foreach ($items as $t) {
            $gid = $prefix . $t['id'];
            $row = ['kind' => $kind, 'id' => $t['id'], 'name' => $t['name'], 'gid' => $gid, 'active' => $t['active'], 'group' => '—', 'renamed' => false,
                    'added' => [], 'removed' => [], 'without' => $t['without'], 'error' => ''];
            try {
                $has = array_key_exists($gid, $existing);
                if ($t['active'] && !$has) {
                    $row['group'] = $dry ? 'do utworzenia' : 'utworzona';
                    if (!$dry) $groups->create($gid, $t['display'] !== '' ? $t['display'] : $gid);
                    $current = [];
                } elseif ($has) {
                    $row['group'] = 'jest';
                    // nazwa zmieniona w SZO albo grupa bez nazwy wyświetlanej; ownCloud jej nie zna (rename() zwraca false)
                    if ($t['active'] && $t['display'] !== '' && $existing[$gid] !== $t['display']) {
                        $row['renamed'] = $dry ? true : $groups->rename($gid, $t['display']);
                    }
                    $current = $groups->members($gid);
                } else {
                    $rows[] = $row;   // wyłączony bez grupy: nic do zrobienia
                    continue;
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

        // Grupy z przedrostkiem bez odpowiednika w SZO (zespół / obszar usunięty): opróżniamy, grupę zostawiamy.
        foreach ($existing as $gid => $display) {
            if (str_starts_with($display, 'Obszar: ')) $display = substr($display, strlen('Obszar: '));   // rodzaj pokazuje osobna etykieta
            $row = ['kind' => $kind, 'id' => (int)substr($gid, strlen($prefix)), 'name' => $display . ' (' . $orphan . ')', 'gid' => $gid, 'active' => false,
                    'group' => 'jest', 'renamed' => false, 'added' => [], 'removed' => [], 'without' => [], 'error' => ''];
            try {
                foreach ($groups->members($gid) as $uid) { if (!$dry) $groups->removeMember($uid, $gid); $row['removed'][] = $uid; }
            } catch (\Throwable $e) { $row['error'] = $e->getMessage(); }
            $rows[] = $row;
        }
        return $rows;
    }

    /** Krótkie podsumowanie do dziennika CRON i komunikatów: liczby zespołów, obszarów, nowych grup, dopisanych, wypisanych, błędów. */
    public static function summary(array $res): string
    {
        if ($res['error'] !== '') return 'błąd: ' . $res['error'];
        $count = fn(string $kind) => count(array_filter($res['teams'], fn($r) => ($r['kind'] ?? 'zespół') === $kind));
        $made = count(array_filter($res['teams'], fn($r) => in_array($r['group'], ['utworzona', 'do utworzenia'], true)));
        $add = array_sum(array_map(fn($r) => count($r['added']), $res['teams']));
        $rem = array_sum(array_map(fn($r) => count($r['removed']), $res['teams']));
        $err = count(array_filter($res['teams'], fn($r) => $r['error'] !== ''));
        return 'zespołów: ' . $count('zespół') . ', obszarów: ' . $count('obszar') . ', nowe grupy: ' . $made . ', dopisani: ' . $add
             . ', wypisani: ' . $rem . ($err ? ', błędy: ' . $err : '');
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
        } catch (\Throwable $e) { error_log('[sso] audit zespołów i obszarów: ' . $e->getMessage()); }
    }
}
