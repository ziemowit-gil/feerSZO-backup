<?php
declare(strict_types=1);

/**
 * modules/sso_logto/logic/CloudTeamsSync.php — zespoły z modułu Zadań SZO jako grupy w chmurze plików (Nextcloud / ownCloud).
 * Polecenie 2026-11-13: „zespoły też pobierz z SZO”.
 *
 * Zespół = task_teams + task_team_members (includes/tasks.php; NIE jednostki organizacyjne). Konto w chmurze osoby:
 * sso_accounts.cloud_uid (po user_id, a gdy brak — po adresie e-mail konta SZO). Zasady:
 *   • aktywny zespół → grupa „szo-zespol-{id}” z nazwą wyświetlaną = nazwa zespołu (Nextcloud; ownCloud zna tylko identyfikator);
 *   • w grupie są dokładnie członkowie zespołu z aktywnym kontem SZO i kontem w chmurze: brakujących dopisujemy, pozostałych wypisujemy;
 *   • zespół wyłączony albo usunięty → grupa zostaje (udostępnienia dla grupy nie znikają), ale bez członków;
 *   • ruszamy WYŁĄCZNIE grupy z przedrostkiem PREFIX — inne grupy w chmurze zostają bez zmian.
 * Tryb podglądu ($dry) niczego nie zmienia. Wywołują: /ssoadmin#zespoly, cli/ssoCloudTeamsSync.php, agent CRON sso_cloud_teams (co 1 h).
 */
require_once dirname(__DIR__) . '/lib/SsoConfig.php';
require_once dirname(__DIR__) . '/lib/CloudGroups.php';

final class CloudTeamsSync
{
    public const PREFIX = 'szo-zespol-';

    public static function gid(int $teamId): string
    {
        return self::PREFIX . $teamId;
    }

    /**
     * Zespoły z członkami: [team_id => ['id','name','active', 'want' => [cloud_uid => nazwa osoby], 'without' => [nazwy bez konta w chmurze]]].
     * Wszystkie zespoły (także wyłączone) — wyłączony ma pustą listę „want”.
     */
    public static function teams(): array
    {
        try {
            $teams = db_all("SELECT id, name, is_active FROM task_teams ORDER BY name");
        } catch (\Throwable $e) {
            return [];   // moduł Zadań bez tabel zespołów
        }
        $out = [];
        foreach ($teams as $t) {
            $out[(int)$t['id']] = ['id' => (int)$t['id'], 'name' => trim((string)$t['name']), 'active' => (int)$t['is_active'] === 1, 'want' => [], 'without' => []];
        }
        if (!$out) return [];
        $members = db_all("SELECT m.team_id, m.user_id, u.name, u.email, COALESCE(u.is_active, 1) AS is_active
                             FROM task_team_members m LEFT JOIN users u ON u.id = m.user_id");
        foreach ($members as $m) {
            $tid = (int)$m['team_id'];
            if (!isset($out[$tid]) || !$out[$tid]['active']) continue;
            $name = trim((string)($m['name'] ?? '')) ?: (string)($m['email'] ?? ('#' . $m['user_id']));
            if ((int)$m['is_active'] !== 1) continue;   // konto SZO wyłączone — nie dopisujemy do grupy
            $uid = self::cloudUid((int)$m['user_id'], (string)($m['email'] ?? ''));
            if ($uid === '') $out[$tid]['without'][] = $name;
            else $out[$tid]['want'][$uid] = $name;
        }
        return $out;
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
     * Synchronizacja. Zwraca ['teams' => list<wiersz>, 'error' => ''], wiersz: id, name, gid, active, group ('utworzona'|'jest'|'do utworzenia'|'—'),
     * renamed (bool), added, removed (loginy w chmurze), without (osoby bez konta w chmurze), error.
     */
    public static function run(bool $dry = false, ?CloudGroups $groups = null): array
    {
        $res = ['teams' => [], 'error' => ''];
        try {
            $groups = $groups ?? new CloudGroups(SsoConfig::cloud());
            $existing = $groups->listByPrefix(self::PREFIX);
        } catch (\Throwable $e) {
            $res['error'] = $e->getMessage();
            return $res;
        }
        $teams = self::teams();

        foreach ($teams as $t) {
            $gid = self::gid($t['id']);
            $row = ['id' => $t['id'], 'name' => $t['name'], 'gid' => $gid, 'active' => $t['active'], 'group' => '—', 'renamed' => false,
                    'added' => [], 'removed' => [], 'without' => $t['without'], 'error' => ''];
            try {
                $has = array_key_exists($gid, $existing);
                if ($t['active'] && !$has) {
                    $row['group'] = $dry ? 'do utworzenia' : 'utworzona';
                    if (!$dry) $groups->create($gid, $t['name']);
                    $current = [];
                } elseif ($has) {
                    $row['group'] = 'jest';
                    // nazwa zespołu zmieniona w SZO albo grupa bez nazwy wyświetlanej; ownCloud jej nie zna (rename() zwraca false)
                    if ($t['active'] && $t['name'] !== '' && $existing[$gid] !== $t['name']) {
                        $row['renamed'] = $dry ? true : $groups->rename($gid, $t['name']);
                    }
                    $current = $groups->members($gid);
                } else {
                    $res['teams'][] = $row;   // zespół wyłączony bez grupy — nic do zrobienia
                    continue;
                }
                $want = $t['active'] ? array_keys($t['want']) : [];
                foreach (array_diff($want, $current) as $uid) { if (!$dry) $groups->addMember((string)$uid, $gid); $row['added'][] = (string)$uid; }
                foreach (array_diff($current, $want) as $uid) { if (!$dry) $groups->removeMember((string)$uid, $gid); $row['removed'][] = (string)$uid; }
            } catch (\Throwable $e) {
                $row['error'] = $e->getMessage();
            }
            $res['teams'][] = $row;
            unset($existing[$gid]);
        }

        // Grupy z przedrostkiem bez zespołu w SZO (zespół usunięty) — opróżniamy, grupę zostawiamy.
        foreach ($existing as $gid => $display) {
            $row = ['id' => (int)substr($gid, strlen(self::PREFIX)), 'name' => $display . ' (zespołu nie ma w SZO)', 'gid' => $gid, 'active' => false,
                    'group' => 'jest', 'renamed' => false, 'added' => [], 'removed' => [], 'without' => [], 'error' => ''];
            try {
                foreach ($groups->members($gid) as $uid) { if (!$dry) $groups->removeMember($uid, $gid); $row['removed'][] = $uid; }
            } catch (\Throwable $e) { $row['error'] = $e->getMessage(); }
            $res['teams'][] = $row;
        }

        if (!$dry) self::audit($res);
        return $res;
    }

    /** Krótkie podsumowanie do dziennika CRON i komunikatów: liczby grup, dopisanych, wypisanych, błędów. */
    public static function summary(array $res): string
    {
        if ($res['error'] !== '') return 'błąd: ' . $res['error'];
        $made = count(array_filter($res['teams'], fn($r) => in_array($r['group'], ['utworzona', 'do utworzenia'], true)));
        $add = array_sum(array_map(fn($r) => count($r['added']), $res['teams']));
        $rem = array_sum(array_map(fn($r) => count($r['removed']), $res['teams']));
        $err = count(array_filter($res['teams'], fn($r) => $r['error'] !== ''));
        return 'zespołów: ' . count($res['teams']) . ', nowe grupy: ' . $made . ', dopisani: ' . $add . ', wypisani: ' . $rem . ($err ? ', błędy: ' . $err : '');
    }

    private static function audit(array $res): void
    {
        $changed = array_values(array_filter($res['teams'], fn($r) => $r['added'] || $r['removed'] || $r['group'] === 'utworzona' || $r['renamed'] || $r['error'] !== ''));
        if (!$changed) return;
        try {
            if (!function_exists('audit_log')) require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            audit_log('sso.cloud_teams_sync', ['podsumowanie' => self::summary($res), 'zmiany' => array_map(fn($r) => [
                'zespol' => $r['name'], 'grupa' => $r['gid'], 'stan' => $r['group'], 'dopisani' => $r['added'], 'wypisani' => $r['removed'], 'blad' => $r['error'],
            ], $changed)]);
        } catch (\Throwable $e) { error_log('[sso] audit zespołów: ' . $e->getMessage()); }
    }
}
