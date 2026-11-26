<?php
declare(strict_types=1);

/**
 * modules/teams/lib/TeamService.php — Zespoły w Strukturze Organizacyjnej (2026-11-26).
 *
 * Dane: te same tabele co Zadania i synchronizacja grup w chmurze (task_teams, task_team_members,
 * task_workspace_teams), rozszerzone w task_teams_migrate() o kod, typ, status, podzespoły, okres,
 * rolę lokalną, umowę-podstawę i zastępstwo. Tutaj dochodzą:
 *   • contract_teams      — umowa (contract_type + contract_id, jak contract_access) ↔ zespół, wiele do wielu;
 *   • team_member_history — okresy członkostwa (skład na dzień, eksport); task_team_members = stan bieżący,
 *                           bo z niego korzystają dostęp do obszarów Zadań i grupy w chmurze;
 *   • team_audit_logs     — historia zmian zespołu (kto, kiedy, zdarzenie, przed/po); dubel w audit_log().
 *
 * Role lokalne: leader (zarządza składem, umowami, podzespołami), member, observer (tylko podgląd;
 * w obszarach Zadań najwyżej „viewer”). Zastępca lidera w okresie zastępstwa ma jego uprawnienia.
 * Na karcie zespołu NIE pokazujemy kwot z umów — tylko rodzaj, numer, status i daty.
 */

require_once dirname(__DIR__, 3) . '/includes/tasks.php';
require_once dirname(__DIR__, 3) . '/modules/contract_status/lib/contractStatuses.php';
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

final class TeamService
{
    public const TYPES    = ['permanent' => 'Stały', 'project' => 'Projektowy'];
    public const STATUSES = ['active' => 'Aktywny', 'suspended' => 'Zawieszony', 'archived' => 'Zarchiwizowany'];
    public const ROLES    = ['leader' => 'Lider', 'member' => 'Członek', 'observer' => 'Obserwator'];
    public const ICONS    = ['people-fill', 'person-workspace', 'briefcase-fill', 'mortarboard-fill', 'megaphone-fill',
                             'heart-fill', 'gear-fill', 'cash-coin', 'laptop', 'calendar-event', 'flag-fill', 'star-fill'];
    /** Umowa „wygasa wkrótce”, gdy kończy się w ciągu tylu dni. */
    public const EXPIRING_DAYS = 30;

    private static bool $migrated = false;

    public static function migrate(): void
    {
        if (self::$migrated) return;
        self::$migrated = true;
        task_teams_migrate();
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS contract_teams (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_type VARCHAR(30) NOT NULL,
            contract_id   INTEGER     NOT NULL,
            team_id       INTEGER     NOT NULL REFERENCES task_teams(id) ON DELETE CASCADE,
            zakres        TEXT        NOT NULL DEFAULT '',
            added_by      INTEGER,
            added_at      DATETIME    NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(contract_type, contract_id, team_id)
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_contract_teams_team ON contract_teams(team_id)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS team_member_history (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            team_id       INTEGER     NOT NULL,
            user_id       INTEGER     NOT NULL,
            role          VARCHAR(20) NOT NULL DEFAULT 'member',
            contract_type VARCHAR(30) NULL,
            contract_id   INTEGER     NULL,
            joined_at     DATE        NOT NULL,
            left_at       DATE        NULL
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_team_member_history ON team_member_history(team_id, user_id)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS team_audit_logs (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            team_id        INTEGER     NOT NULL,
            actor_id       INTEGER     NULL,
            event          VARCHAR(40) NOT NULL,
            target_user_id INTEGER     NULL,
            before_json    TEXT        NULL,
            after_json     TEXT        NULL,
            created_at     DATETIME    NOT NULL DEFAULT (datetime('now','localtime'))
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_team_audit_team ON team_audit_logs(team_id, created_at)");
        // Status zgodny z dawnym is_active (wyłączony zespół = zawieszony), okresy członkostwa dla obecnych członków.
        try {
            $pdo->exec("UPDATE task_teams SET status='suspended' WHERE is_active=0 AND status='active'");
            $pdo->exec("INSERT INTO team_member_history (team_id, user_id, role, joined_at)
                        SELECT m.team_id, m.user_id, COALESCE(m.role,'member'), date(COALESCE(m.added_at, 'now'))
                        FROM task_team_members m
                        WHERE NOT EXISTS (SELECT 1 FROM team_member_history h
                                          WHERE h.team_id=m.team_id AND h.user_id=m.user_id AND h.left_at IS NULL)");
        } catch (\Throwable $e) {}
    }

    // ── Odczyt ──────────────────────────────────────────────────────────────

    public static function get(int $teamId): ?array
    {
        self::migrate();
        return db_one(
            "SELECT t.*, ou.name AS unit_name, p.name AS parent_name,
                    (SELECT COUNT(*) FROM task_team_members WHERE team_id=t.id) AS member_count
             FROM task_teams t
             LEFT JOIN org_units ou ON ou.id = t.unit_id
             LEFT JOIN task_teams p ON p.id = t.parent_id
             WHERE t.id=?", [$teamId]);
    }

    /** Lista zespołów z filtrami: type, status, unit_id, q, ids (tablica). */
    public static function list(array $f = []): array
    {
        self::migrate();
        $w = []; $p = [];
        if (!empty($f['type']) && isset(self::TYPES[$f['type']]))       { $w[] = 't.type=?';   $p[] = $f['type']; }
        if (!empty($f['status']) && isset(self::STATUSES[$f['status']])) { $w[] = 't.status=?'; $p[] = $f['status']; }
        if (!empty($f['unit_id']))                                        { $w[] = 't.unit_id=?'; $p[] = (int)$f['unit_id']; }
        if (isset($f['q']) && trim((string)$f['q']) !== '') {
            $w[] = '(t.name LIKE ? OR COALESCE(t.code,\'\') LIKE ?)';
            $q = '%' . trim((string)$f['q']) . '%'; $p[] = $q; $p[] = $q;
        }
        if (isset($f['ids'])) {
            $ids = array_values(array_filter(array_map('intval', (array)$f['ids'])));
            if (!$ids) return [];
            $w[] = 't.id IN (' . implode(',', $ids) . ')';
        }
        return db_all(
            "SELECT t.*, ou.name AS unit_name,
                    (SELECT COUNT(*) FROM task_team_members WHERE team_id=t.id) AS member_count,
                    (SELECT COUNT(*) FROM task_workspace_teams WHERE team_id=t.id) AS workspace_count,
                    (SELECT COUNT(*) FROM contract_teams WHERE team_id=t.id) AS contract_count
             FROM task_teams t LEFT JOIN org_units ou ON ou.id = t.unit_id"
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
            . " ORDER BY CASE t.status WHEN 'active' THEN 0 WHEN 'suspended' THEN 1 ELSE 2 END, t.name", $p);
    }

    /** Bieżący skład z rolą, umową-podstawą i zastępstwem. */
    public static function members(int $teamId): array
    {
        self::migrate();
        return db_all(
            "SELECT m.*, u.name, u.email, COALESCE(u.is_active,1) AS user_active, d.name AS deputy_name
             FROM task_team_members m
             JOIN users u ON u.id = m.user_id
             LEFT JOIN users d ON d.id = m.deputy_user_id
             WHERE m.team_id=?
             ORDER BY CASE COALESCE(m.role,'member') WHEN 'leader' THEN 0 WHEN 'member' THEN 1 ELSE 2 END, u.name", [$teamId]);
    }

    /** Skład na dzień (Y-m-d) z historii członkostwa. */
    public static function membersAt(int $teamId, string $date): array
    {
        self::migrate();
        return db_all(
            "SELECT h.*, u.name, u.email FROM team_member_history h JOIN users u ON u.id = h.user_id
             WHERE h.team_id=? AND h.joined_at <= ? AND (h.left_at IS NULL OR h.left_at > ?)
             ORDER BY CASE h.role WHEN 'leader' THEN 0 WHEN 'member' THEN 1 ELSE 2 END, u.name",
            [$teamId, $date, $date]);
    }

    /** Podzespoły (bezpośrednie). */
    public static function children(int $teamId): array
    {
        self::migrate();
        return db_all("SELECT id, name, code, status, type FROM task_teams WHERE parent_id=? ORDER BY name", [$teamId]);
    }

    /** Zespół i wszystkie jego nadrzędne (od najbliższego), z ochroną przed pętlą. */
    public static function ancestors(int $teamId): array
    {
        $out = []; $seen = [$teamId => true];
        $cur = db_one("SELECT parent_id FROM task_teams WHERE id=?", [$teamId]);
        while ($cur && ($pid = (int)($cur['parent_id'] ?? 0)) && !isset($seen[$pid])) {
            $seen[$pid] = true;
            $row = db_one("SELECT id, name, parent_id FROM task_teams WHERE id=?", [$pid]);
            if (!$row) break;
            $out[] = $row;
            $cur = $row;
        }
        return $out;
    }

    /** Zespół i wszystkie jego podzespoły (w głąb), z ochroną przed pętlą. */
    public static function withDescendants(int $teamId): array
    {
        self::migrate();
        if ($teamId <= 0) return [];
        $out = [$teamId => true]; $queue = [$teamId];
        while ($queue) {
            $id = array_shift($queue);
            foreach (db_all("SELECT id FROM task_teams WHERE parent_id=?", [$id]) as $r) {
                $c = (int)$r['id'];
                if (!isset($out[$c])) { $out[$c] = true; $queue[] = $c; }
            }
        }
        return array_keys($out);
    }

    /** Czy $candidateParent leży w poddrzewie $teamId (wtedy nie może zostać jego rodzicem). */
    public static function isDescendant(int $candidateParent, int $teamId): bool
    {
        if ($candidateParent === $teamId) return true;
        foreach (self::ancestors($candidateParent) as $a) {
            if ((int)$a['id'] === $teamId) return true;
        }
        return false;
    }

    /**
     * Zespoły osoby: członkostwo w aktywnych i zawieszonych zespołach; z $includeInherited także zespoły
     * nadrzędne (członek podzespołu należy do zespołu wyżej) i zespoły, w których osoba jest dziś
     * zastępcą któregoś członka.
     */
    public static function getUserTeamIds(int $userId, bool $includeInherited = true): array
    {
        self::migrate();
        $ids = array_map('intval', array_column(db_all(
            "SELECT m.team_id FROM task_team_members m JOIN task_teams t ON t.id=m.team_id
             WHERE m.user_id=? AND t.status <> 'archived'", [$userId]), 'team_id'));
        if ($includeInherited) {
            $today = date('Y-m-d');
            foreach (db_all(
                "SELECT m.team_id FROM task_team_members m JOIN task_teams t ON t.id=m.team_id
                 WHERE m.deputy_user_id=? AND t.status <> 'archived'
                   AND (m.deputy_from IS NULL OR m.deputy_from <= ?) AND (m.deputy_to IS NULL OR m.deputy_to >= ?)",
                [$userId, $today, $today]) as $r) {
                $ids[] = (int)$r['team_id'];
            }
            foreach ($ids as $id) {
                foreach (self::ancestors($id) as $a) $ids[] = (int)$a['id'];
            }
        }
        return array_values(array_unique($ids));
    }

    /** Kto dziś działa za osobę w zespole: zastępca w okresie zastępstwa, inaczej sama osoba. */
    public static function getEffectiveAssignee(int $userId, int $teamId): int
    {
        self::migrate();
        $m = db_one("SELECT deputy_user_id, deputy_from, deputy_to FROM task_team_members WHERE team_id=? AND user_id=?",
                    [$teamId, $userId]);
        return ($m && self::deputyActive($m)) ? (int)$m['deputy_user_id'] : $userId;
    }

    public static function deputyActive(array $m, ?string $date = null): bool
    {
        $d = $date ?? date('Y-m-d');
        return (int)($m['deputy_user_id'] ?? 0) > 0
            && (empty($m['deputy_from']) || $m['deputy_from'] <= $d)
            && (empty($m['deputy_to'])   || $m['deputy_to']   >= $d);
    }

    // ── Uprawnienia ─────────────────────────────────────────────────────────

    /** Rola lokalna osoby w zespole (leader/member/observer) albo null. */
    public static function localRole(int $teamId, int $userId): ?string
    {
        $r = db_one("SELECT role FROM task_team_members WHERE team_id=? AND user_id=?", [$teamId, $userId]);
        return $r ? (string)($r['role'] ?: 'member') : null;
    }

    /**
     * Zarządza zespołem: administrator; lider tego zespołu lub zespołu nadrzędnego; zastępca lidera
     * w okresie zastępstwa. Zarchiwizowany zespół zmienia tylko administrator (status).
     */
    public static function canManage(int $teamId, ?array $user = null): bool
    {
        $user ??= current_user();
        if (!$user) return false;
        if (($user['role'] ?? '') === 'admin') return true;
        $team = db_one("SELECT status FROM task_teams WHERE id=?", [$teamId]);
        if (!$team || $team['status'] === 'archived') return false;
        $uid = (int)$user['id'];
        $chain = array_merge([$teamId], array_map(fn($a) => (int)$a['id'], self::ancestors($teamId)));
        $today = date('Y-m-d');
        foreach ($chain as $tid) {
            $ok = db_one(
                "SELECT 1 FROM task_team_members WHERE team_id=? AND role='leader' AND (
                    user_id=? OR (deputy_user_id=? AND (deputy_from IS NULL OR deputy_from<=?) AND (deputy_to IS NULL OR deputy_to>=?)))",
                [$tid, $uid, $uid, $today, $today]);
            if ($ok) return true;
        }
        return false;
    }

    public static function canCreate(?array $user = null): bool
    {
        $user ??= current_user();
        return ($user['role'] ?? '') === 'admin';
    }

    // ── Umowy ───────────────────────────────────────────────────────────────

    /** Kolumny tabeli umów (cache), żeby nie zakładać jednolitego schematu umowy_*. */
    private static function cols(string $table): array
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            try { $cache[$table] = array_column(db_all("PRAGMA table_info($table)"), 'name'); }
            catch (\Throwable $e) { $cache[$table] = []; }
        }
        return $cache[$table];
    }

    /** Podstawowe dane umowy BEZ kwot: rodzaj, numer, RU, strona, status, daty, adres karty. */
    public static function contractInfo(string $type, int $id): ?array
    {
        if (!isset(CONTRACT_TYPES[$type]) || $id <= 0) return null;
        $table = table_for_type($type);
        $c = self::cols($table);
        if (!$c) return null;
        $name = CONTRACT_REGISTRY_FIELDS[$type]['name_col'] ?? 'numer_umowy';
        $sel = ['id', 'status',
            in_array('numer_umowy', $c, true) ? 'numer_umowy' : "'' AS numer_umowy",
            in_array('nr_rejestru', $c, true) ? 'nr_rejestru' : "'' AS nr_rejestru",
            in_array($name, $c, true) ? "$name AS strona" : "'' AS strona",
            in_array('data_rozpoczecia', $c, true) ? 'data_rozpoczecia' : 'NULL AS data_rozpoczecia',
            in_array('data_zakonczenia', $c, true) ? 'data_zakonczenia' : 'NULL AS data_zakonczenia',
            in_array('email', $c, true) ? 'email' : "'' AS email",
        ];
        try { $r = db_one("SELECT " . implode(', ', $sel) . " FROM $table WHERE id=?", [$id]); }
        catch (\Throwable $e) { return null; }
        if (!$r) return null;
        $r['contract_type'] = $type;
        $r['contract_type_label'] = CONTRACT_TYPES[$type];
        $r['url'] = rtrim(APP_URL, '/') . '/contracts/' . $type . '/view.php?id=' . $id;
        $r['health'] = self::contractHealth($r);
        return $r;
    }

    /** Stan umowy: active / expiring / ended / draft / cancelled (+ label). */
    public static function contractHealth(array $r): array
    {
        $group = contractStatusGroup((string)($r['status'] ?? ''));
        $end = substr((string)($r['data_zakonczenia'] ?? ''), 0, 10);
        $today = date('Y-m-d');
        if ($group === 'cancelled') return ['code' => 'cancelled', 'label' => 'Rozwiązana / anulowana'];
        if ($group === 'closed' || ($group === 'active' && $end !== '' && $end < $today)) {
            return ['code' => 'ended', 'label' => 'Zakończona'];
        }
        if ($group === 'blocked') return ['code' => 'blocked', 'label' => 'Zablokowana'];
        if ($group !== 'active') return ['code' => 'draft', 'label' => 'Przed podpisaniem'];
        if ($end !== '' && $end <= date('Y-m-d', strtotime('+' . self::EXPIRING_DAYS . ' days'))) {
            return ['code' => 'expiring', 'label' => 'Wygasa ' . date('d.m.Y', strtotime($end))];
        }
        return ['code' => 'active', 'label' => 'Aktywna'];
    }

    /** Aktywne umowy osoby ze wszystkich rodzajów (dopasowanie po e-mailu i koncie M365, jak ts_user_contracts). */
    public static function getPersonActiveContracts(int $userId): array
    {
        $u = db_one("SELECT email, microsoft_id FROM users WHERE id=?", [$userId]);
        $email = strtolower(trim((string)($u['email'] ?? '')));
        $ms = trim((string)($u['microsoft_id'] ?? ''));
        if ($email === '' && $ms === '') return [];
        $out = [];
        foreach (array_keys(CONTRACT_TYPES) as $type) {
            $table = table_for_type($type);
            $c = self::cols($table);
            $conds = []; $p = [];
            if ($email !== '' && in_array('email', $c, true))      { $conds[] = 'LOWER(email)=?';      $p[] = $email; }
            if ($email !== '' && in_array('m365_login', $c, true)) { $conds[] = 'LOWER(m365_login)=?'; $p[] = $email; }
            if ($ms !== '' && in_array('m365_user_id', $c, true))  { $conds[] = 'm365_user_id=?';      $p[] = $ms; }
            if (!$conds) continue;
            try {
                $rows = db_all("SELECT id FROM $table WHERE (" . implode(' OR ', $conds) . ") AND "
                    . contractStatusSqlIn(['active']) . " ORDER BY id DESC", $p);
            } catch (\Throwable $e) { continue; }
            foreach ($rows as $r) {
                $i = self::contractInfo($type, (int)$r['id']);
                if ($i && in_array($i['health']['code'], ['active', 'expiring'], true)) $out[] = $i;
            }
        }
        return $out;
    }

    /**
     * Umowy zespołu: przypięte do zespołu (contract_teams) oraz umowy-podstawy członków, bez powtórzeń.
     * Każda z polami źródło (zespol/czlonek), zakres, osoba. $onlyActive → tylko aktywne i wygasające.
     */
    public static function getTeamContracts(int $teamId, bool $onlyActive = false): array
    {
        self::migrate();
        $out = [];
        foreach (db_all("SELECT * FROM contract_teams WHERE team_id=? ORDER BY added_at", [$teamId]) as $ct) {
            $i = self::contractInfo((string)$ct['contract_type'], (int)$ct['contract_id']);
            if (!$i) continue;
            $i['zakres'] = (string)$ct['zakres'];
            $i['source'] = 'zespol';
            $i['member'] = '';
            $out[$ct['contract_type'] . ':' . $ct['contract_id']] = $i;
        }
        foreach (db_all(
            "SELECT m.contract_type, m.contract_id, u.name FROM task_team_members m JOIN users u ON u.id=m.user_id
             WHERE m.team_id=? AND m.contract_id IS NOT NULL", [$teamId]) as $m) {
            $k = $m['contract_type'] . ':' . $m['contract_id'];
            if (isset($out[$k])) { $out[$k]['member'] = (string)$m['name']; continue; }
            $i = self::contractInfo((string)$m['contract_type'], (int)$m['contract_id']);
            if (!$i) continue;
            $i['zakres'] = '';
            $i['source'] = 'czlonek';
            $i['member'] = (string)$m['name'];
            $out[$k] = $i;
        }
        $out = array_values($out);
        if ($onlyActive) {
            $out = array_values(array_filter($out, fn($i) => in_array($i['health']['code'], ['active', 'expiring'], true)));
        }
        return $out;
    }

    /** Zespoły przypięte do umowy (rodzaj umowy jest potrzebny — id powtarzają się między tabelami umowy_*). */
    public static function getContractTeams(int $contractId, string $contractType = 'zlecenie'): array
    {
        self::migrate();
        return db_all(
            "SELECT ct.id AS link_id, ct.zakres, ct.added_at, t.id, t.name, t.code, t.status, t.type, t.ends_at
             FROM contract_teams ct JOIN task_teams t ON t.id = ct.team_id
             WHERE ct.contract_type=? AND ct.contract_id=? ORDER BY t.name", [$contractType, $contractId]);
    }

    /** Umowy przypięte do zespołów (do filtra w Rejestrze): ['typ:id' => true]. */
    public static function contractKeysForTeams(array $teamIds): array
    {
        self::migrate();
        $ids = array_values(array_filter(array_map('intval', $teamIds)));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $keys = [];
        foreach (db_all("SELECT contract_type, contract_id FROM contract_teams WHERE team_id IN ($in)
                         UNION SELECT contract_type, contract_id FROM task_team_members WHERE team_id IN ($in) AND contract_id IS NOT NULL") as $r) {
            $keys[$r['contract_type'] . ':' . $r['contract_id']] = true;
        }
        return $keys;
    }

    /**
     * Ostrzeżenia o podstawie formalnej członków: brak aktywnej umowy albo umowa kończy się przed
     * końcem zespołu projektowego. [user_id => [['code'=>…, 'text'=>…], …]]
     */
    public static function memberWarnings(array $team, array $members): array
    {
        $out = [];
        $teamEnd = ($team['type'] ?? '') === 'project' ? substr((string)($team['ends_at'] ?? ''), 0, 10) : '';
        foreach ($members as $m) {
            $uid = (int)$m['user_id'];
            $w = [];
            if (!empty($m['contract_id'])) {
                $i = self::contractInfo((string)$m['contract_type'], (int)$m['contract_id']);
                $code = $i['health']['code'] ?? 'ended';
                if (!$i || !in_array($code, ['active', 'expiring'], true)) {
                    $w[] = ['code' => 'no_contract', 'text' => 'Umowa-podstawa nie jest aktywna (' . ($i['health']['label'] ?? 'brak umowy') . ').'];
                } else {
                    if ($code === 'expiring') $w[] = ['code' => 'expiring', 'text' => 'Umowa-podstawa ' . mb_strtolower($i['health']['label']) . '.'];
                    $end = substr((string)($i['data_zakonczenia'] ?? ''), 0, 10);
                    if ($teamEnd !== '' && $end !== '' && $end < $teamEnd) {
                        $w[] = ['code' => 'ends_before_team', 'text' => 'Umowa kończy się ' . date('d.m.Y', strtotime($end))
                            . ', przed końcem zespołu (' . date('d.m.Y', strtotime($teamEnd)) . ').'];
                    }
                }
            } elseif (!self::getPersonActiveContracts($uid)) {
                $w[] = ['code' => 'no_contract', 'text' => 'Brak aktywnej umowy.'];
            }
            if ($w) $out[$uid] = $w;
        }
        return $out;
    }

    // ── Historia ────────────────────────────────────────────────────────────

    public static function audit(int $teamId, string $event, ?int $targetUserId = null, $before = null, $after = null): void
    {
        self::migrate();
        $actor = current_user();
        $enc = fn($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        db()->prepare("INSERT INTO team_audit_logs (team_id, actor_id, event, target_user_id, before_json, after_json, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$teamId, $actor ? (int)$actor['id'] : null, $event, $targetUserId, $enc($before), $enc($after), date('Y-m-d H:i:s')]);
        {
            try { audit_log('teams.' . $event, ['team_id' => $teamId, 'user_id' => $targetUserId, 'before' => $before, 'after' => $after]); }
            catch (\Throwable $e) {}
        }
    }

    public static function auditList(int $teamId, int $limit = 200): array
    {
        self::migrate();
        return db_all(
            "SELECT a.*, u.name AS actor_name, t.name AS target_name FROM team_audit_logs a
             LEFT JOIN users u ON u.id = a.actor_id LEFT JOIN users t ON t.id = a.target_user_id
             WHERE a.team_id=? ORDER BY a.created_at DESC, a.id DESC LIMIT " . max(1, $limit), [$teamId]);
    }

    public const EVENTS = [
        'team_created' => 'Utworzono zespół', 'team_updated' => 'Zmieniono dane zespołu', 'team_status' => 'Zmieniono status',
        'team_deleted' => 'Usunięto zespół', 'member_added' => 'Dodano osobę', 'member_removed' => 'Usunięto osobę',
        'member_updated' => 'Zmieniono rolę lub umowę', 'deputy_set' => 'Ustawiono zastępstwo', 'deputy_cleared' => 'Zakończono zastępstwo',
        'contract_linked' => 'Przypięto umowę', 'contract_unlinked' => 'Odpięto umowę',
        'workspace_linked' => 'Przypięto obszar zadań', 'workspace_unlinked' => 'Odpięto obszar zadań', 'workspace_created' => 'Utworzono obszar zadań',
    ];

    /** Zamyka bieżący okres członkostwa i (opcjonalnie) otwiera nowy — dla składu na dzień. */
    public static function historyTouch(int $teamId, int $userId, ?array $newState): void
    {
        self::migrate();
        $today = date('Y-m-d');
        db()->prepare("UPDATE team_member_history SET left_at=? WHERE team_id=? AND user_id=? AND left_at IS NULL")
            ->execute([$today, $teamId, $userId]);
        // Zmiana tego samego dnia: okres otwarty i zamknięty dziś nie ma znaczenia dla składu na dzień — usuń.
        db()->prepare("DELETE FROM team_member_history WHERE team_id=? AND user_id=? AND joined_at=? AND left_at=?")
            ->execute([$teamId, $userId, $today, $today]);
        if ($newState !== null) {
            db_insert('team_member_history', [
                'team_id' => $teamId, 'user_id' => $userId, 'role' => $newState['role'] ?? 'member',
                'contract_type' => $newState['contract_type'] ?? null, 'contract_id' => $newState['contract_id'] ?? null,
                'joined_at' => $today, 'left_at' => null,
            ]);
        }
    }
}
