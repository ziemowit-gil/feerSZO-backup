<?php
declare(strict_types=1);

/**
 * modules/wsCloud/lib/WsCloud.php — pliki obszarów Zadań w chmurze Nextcloud (od 2026-11-13 zamiast SharePointa;
 * polecenie „w zadaniach wywal Microsoft i dodaj Nextcloud”).
 *
 * Układ w chmurze: konto API SZO (SsoConfig: sso_cloud_user) jest właścicielem folderu „{root}/{obszar}”
 * (root = ustawienie ws_cloud_root, domyślnie „Obszary zadań”), a w nim foldery wspólne i foldery zadań.
 * Folder obszaru jest udostępniony każdej osobie z dostępem do obszaru (dopisanej wprost albo przez zespół), która ma konto
 * w chmurze (sso_accounts.cloud_uid): rola „viewer” — tylko odczyt, pozostałe role — odczyt, zmiana, dodawanie i usuwanie.
 * Udostępnienia osobom (a nie grupie „szo-obszar-{id}” z CloudTeamsSync), bo grupa nie rozróżnia ról. Członkowie widzą folder
 * w swoich Plikach, w aplikacji na komputerze i w telefonie. Obszar bez członków z kontem w chmurze nie ma udostępnień.
 *
 * Ścieżka folderu obszaru zapisuje się przy pierwszym użyciu (ws_cloud_roots) i nie zmienia się po zmianie nazwy obszaru.
 * Udostępnienia: syncShares() przy zakładaniu folderów, przy zapisie członków i co godzinę (agent CRON ws_cloud_shares).
 * Ruszamy wyłącznie udostępnienia osobom (share_type 0) folderu obszaru; udostępnienia grupom i linki zostają.
 *
 * Administratorzy folderów (ustawienie ws_cloud_admins: loginy w chmurze, polecenie 2026-11-14): cały folder „{root}”
 * z pełnymi uprawnieniami (także dalsze udostępnianie), więc widzą wszystkie obszary, też przyszłe. Nie dostają osobnych
 * udostępnień folderów obszarów (byłyby podwójne). Ustawia je cli/wsCloudAdmins.php; uzgadnia syncRootShares().
 */
require_once dirname(__DIR__, 2) . '/sso_logto/lib/SsoConfig.php';

final class WsCloud
{
    public const DEFAULT_ROOT = 'Obszary zadań';
    public const PERM_READ = 1;
    public const PERM_EDIT = 15;   // odczyt + zmiana + dodawanie + usuwanie (bez dalszego udostępniania)
    public const PERM_ALL  = 31;   // jak wyżej + dalsze udostępnianie (administratorzy folderów)

    private static ?CloudApi $api = null;

    /** Chmura skonfigurowana (adres + konto API z hasłem). */
    public static function available(): bool
    {
        try { return SsoConfig::cloudConfigured(); } catch (\Throwable $e) { return false; }
    }

    public static function api(): CloudApi
    {
        return self::$api ??= SsoConfig::cloud();
    }

    /** Do testów: podmiana łącznika. */
    public static function setApi(?CloudApi $api): void
    {
        self::$api = $api;
    }

    public static function label(): string
    {
        try { return SsoConfig::cloudLabel(); } catch (\Throwable $e) { return 'Nextcloud'; }
    }

    public static function baseUrl(): string
    {
        try { return rtrim(SsoConfig::cloudUrl(), '/'); } catch (\Throwable $e) { return ''; }
    }

    /** Link do pliku albo folderu w chmurze (Nextcloud otwiera go u każdego, kto ma dostęp). */
    public static function webUrl(int $fileId): string
    {
        return $fileId > 0 && self::baseUrl() !== '' ? self::baseUrl() . '/f/' . $fileId : '';
    }

    public static function root(): string
    {
        $v = function_exists('org_setting') ? trim((string)org_setting('ws_cloud_root'), "/ \t") : '';
        return $v !== '' ? $v : self::DEFAULT_ROOT;
    }

    /** Bezpieczna nazwa elementu ścieżki w chmurze (polskie znaki zostają). */
    public static function name(string $s, string $fallback = 'Folder'): string
    {
        $s = preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/u', '-', $s);
        $s = trim(preg_replace('/\s+/u', ' ', (string)$s), " .-");
        return $s !== '' ? mb_substr($s, 0, 120) : $fallback;
    }

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db()->exec("CREATE TABLE IF NOT EXISTS ws_cloud_roots (
            workspace_id INTEGER PRIMARY KEY REFERENCES task_workspaces(id) ON DELETE CASCADE,
            path         TEXT NOT NULL,
            fileid       INTEGER NOT NULL DEFAULT 0,
            owner        TEXT NOT NULL,
            shares_at    TEXT,
            shares_error TEXT NOT NULL DEFAULT '',
            created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )");
    }

    /** Zapisany folder obszaru albo null. */
    public static function rootRow(int $wsId): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM ws_cloud_roots WHERE workspace_id = ?", [$wsId]) ?: null;
    }

    /**
     * Folder obszaru w chmurze (zakłada go przy pierwszym użyciu). Zwraca ['path','fileid','owner'].
     * Wyjątek, gdy folder należy do innego konta API niż obecne (SZO nie ma jego hasła).
     */
    public static function workspaceRoot(int $wsId): array
    {
        $row = self::rootRow($wsId);
        $api = self::api();
        if ($row) {
            if ($row['owner'] !== $api->apiUid()) {
                throw new RuntimeException('Folder obszaru należy do konta „' . $row['owner'] . '” w chmurze, a SZO używa teraz konta „'
                    . $api->apiUid() . '”. Przenieś pliki poleceniem occ files:transfer-ownership albo przywróć poprzednie konto.');
            }
            return ['path' => (string)$row['path'], 'fileid' => (int)$row['fileid'], 'owner' => (string)$row['owner']];
        }
        $ws = db_one("SELECT id, name FROM task_workspaces WHERE id = ?", [$wsId]);
        if (!$ws) throw new RuntimeException('Nie znaleziono obszaru.');
        $base = self::root() . '/' . self::name((string)$ws['name'], 'Obszar ' . $wsId);
        // Inny obszar o tej samej nazwie ma już folder → dopisek z numerem
        $path = $base;
        if (db_one("SELECT 1 AS x FROM ws_cloud_roots WHERE path = ?", [$path])) $path = $base . ' (' . $wsId . ')';
        $api->ownMkdirs($path);
        $st = $api->ownStat($path);
        db()->prepare("INSERT OR REPLACE INTO ws_cloud_roots (workspace_id, path, fileid, owner) VALUES (?,?,?,?)")
            ->execute([$wsId, $path, (int)($st['fileid'] ?? 0), $api->apiUid()]);
        return ['path' => $path, 'fileid' => (int)($st['fileid'] ?? 0), 'owner' => $api->apiUid()];
    }

    /** Podfolder w folderze obszaru (z rodzicami). Zwraca ['path','fileid']. Istniejący folder to nie błąd. */
    public static function ensureFolder(int $wsId, string $relative): array
    {
        $root = self::workspaceRoot($wsId);
        $parts = array_values(array_filter(array_map(fn($p) => self::name($p), explode('/', $relative)), fn($p) => $p !== ''));
        $path = $root['path'] . ($parts ? '/' . implode('/', $parts) : '');
        self::api()->ownMkdirs($path);
        $st = self::api()->ownStat($path);
        return ['path' => $path, 'fileid' => (int)($st['fileid'] ?? 0)];
    }

    /**
     * Wolna nazwa pliku w folderze: „nazwa.ext”, a gdy zajęta — „nazwa (2).ext”, „nazwa (3).ext”…
     */
    public static function freeName(string $folderPath, string $fileName): string
    {
        $name = self::name($fileName, 'plik');
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $stem = $ext !== '' ? mb_substr($name, 0, -(mb_strlen($ext) + 1)) : $name;
        for ($i = 1; $i < 200; $i++) {
            $try = $i === 1 ? $name : $stem . ' (' . $i . ')' . ($ext !== '' ? '.' . $ext : '');
            if (self::api()->ownStat($folderPath . '/' . $try) === null) return $try;
        }
        return $stem . ' ' . date('YmdHis') . ($ext !== '' ? '.' . $ext : '');
    }

    // ── Udostępnienia ───────────────────────────────────────────────────────────────────────

    /** Administratorzy folderów: loginy w chmurze z ustawienia ws_cloud_admins (czytane wprost z bazy, bez cache). */
    public static function admins(): array
    {
        try { $v = (string)(db_one("SELECT value FROM settings WHERE key_ = 'ws_cloud_admins'")['value'] ?? ''); } catch (\Throwable $e) { $v = ''; }
        $out = [];
        foreach (preg_split('/[\s,;]+/', $v) as $uid) {
            $uid = trim($uid);
            if ($uid !== '') $out[strtolower($uid)] = $uid;
        }
        return array_values($out);
    }

    public static function setAdmins(array $uids): void
    {
        $uids = array_values(array_unique(array_filter(array_map('trim', $uids), fn($u) => $u !== '')));
        org_setting_set('ws_cloud_admins', implode(',', $uids));
    }

    /**
     * Folder „{root}” udostępniony administratorom folderów z pełnymi uprawnieniami; osobom spoza listy udostępnienie
     * folderu głównego jest odbierane. Zwraca ['added','updated','removed','error'].
     */
    public static function syncRootShares(bool $dry = false): array
    {
        $res = ['added' => [], 'updated' => [], 'removed' => [], 'error' => ''];
        try {
            $api = self::api();
            $admins = array_values(array_filter(self::admins(), fn($u) => strcasecmp($u, $api->apiUid()) !== 0));
            if (!$admins && !$api->ownStat(self::root())) return $res;   // nic do udostępniania, folderu jeszcze nie ma
            if (!$dry) $api->ownMkdirs(self::root());
            $have = [];
            if ($api->ownStat(self::root())) {
                foreach ($api->shares(self::root()) as $s) if ($s['share_type'] === 0) $have[strtolower($s['share_with'])] = $s;
            }
            foreach ($admins as $uid) {
                $s = $have[strtolower($uid)] ?? null;
                if (!$s) { if (!$dry) $api->shareCreate(self::root(), $uid, self::PERM_ALL); $res['added'][] = $uid; continue; }
                if ($s['permissions'] !== self::PERM_ALL) { if (!$dry) $api->shareUpdate($s['id'], self::PERM_ALL); $res['updated'][] = $uid; }
            }
            $lc = array_map('strtolower', $admins);
            foreach ($have as $k => $s) {
                if (!in_array($k, $lc, true)) { if (!$dry) $api->shareDelete($s['id']); $res['removed'][] = $s['share_with']; }
            }
        } catch (\Throwable $e) {
            $res['error'] = mb_substr($e->getMessage(), 0, 300);
        }
        return $res;
    }

    /** Konto w chmurze osoby z SZO (po user_id, a gdy brak — po e-mailu konta). */
    public static function cloudUid(int $userId, string $email): string
    {
        try {
            $r = $userId > 0 ? db_one("SELECT cloud_uid FROM sso_accounts WHERE user_id = ? AND COALESCE(cloud_uid,'') <> '' LIMIT 1", [$userId]) : null;
            if (!$r && trim($email) !== '') $r = db_one("SELECT cloud_uid FROM sso_accounts WHERE email = ? AND COALESCE(cloud_uid,'') <> '' LIMIT 1", [strtolower(trim($email))]);
        } catch (\Throwable $e) {
            return '';
        }
        return (string)($r['cloud_uid'] ?? '');
    }

    /**
     * Kto powinien mieć dostęp do folderu obszaru: [cloud_uid => uprawnienia] + osoby bez konta w chmurze.
     * Kandydaci: osoby dopisane do obszaru i członkowie zespołów przypisanych do obszaru (te same źródła co grupa
     * „szo-obszar-{id}” w CloudTeamsSync); rolę liczy task_workspace_role() — viewer dostaje odczyt, reszta edycję.
     * @return array{want: array<string,int>, without: list<string>}
     */
    public static function wantedShares(int $wsId): array
    {
        require_once dirname(__DIR__, 3) . '/includes/tasks.php';
        $want = []; $without = [];
        $sql = "SELECT m.user_id FROM task_workspace_members m WHERE m.workspace_id = ?";
        $args = [$wsId];
        try {
            db_one("SELECT 1 AS x FROM task_workspace_teams LIMIT 1");
            $sql .= " UNION SELECT tm.user_id FROM task_workspace_teams wt
                        JOIN task_teams t ON t.id = wt.team_id AND t.is_active = 1
                        JOIN task_team_members tm ON tm.team_id = wt.team_id WHERE wt.workspace_id = ?";
            $args[] = $wsId;
        } catch (\Throwable $e) {}   // bez tabel zespołów: tylko osoby dopisane do obszaru
        $ids = array_map('intval', array_column(db_all($sql, $args), 'user_id'));
        foreach ($ids as $userId) {
            $u = db_one("SELECT id, name, email, COALESCE(is_active, 1) AS is_active FROM users WHERE id = ?", [$userId]);
            if (!$u || (int)$u['is_active'] !== 1) continue;
            $role = task_workspace_role($wsId, $userId);
            if ($role === null) continue;
            $uid = self::cloudUid($userId, (string)($u['email'] ?? ''));
            if ($uid === '') { $without[] = trim((string)$u['name']) ?: (string)$u['email']; continue; }
            $perm = $role === 'viewer' ? self::PERM_READ : self::PERM_EDIT;
            $want[$uid] = max($want[$uid] ?? 0, $perm);
        }
        return ['want' => $want, 'without' => $without];
    }

    /**
     * Uzgadnia udostępnienia folderu obszaru z członkami. Obszar bez folderu w chmurze pomijamy (nie zakładamy go tylko dla
     * udostępnień). Zwraca ['added','updated','removed','without','error'].
     */
    public static function syncShares(int $wsId, bool $dry = false): array
    {
        $res = ['added' => [], 'updated' => [], 'removed' => [], 'without' => [], 'error' => ''];
        $row = self::rootRow($wsId);
        if (!$row) return $res;
        try {
            $api = self::api();
            if ($row['owner'] !== $api->apiUid()) throw new RuntimeException('folder należy do konta „' . $row['owner'] . '”, SZO używa „' . $api->apiUid() . '”');
            $w = self::wantedShares($wsId);
            $res['without'] = $w['without'];
            unset($w['want'][$api->apiUid()]);
            // Administratorzy folderów mają cały folder główny — bez osobnych (podwójnych) udostępnień obszaru
            $adminsLc = array_map('strtolower', self::admins());
            foreach (array_keys($w['want']) as $uid) if (in_array(strtolower($uid), $adminsLc, true)) unset($w['want'][$uid]);
            $have = [];
            foreach ($api->shares((string)$row['path']) as $s) {
                if ($s['share_type'] === 0) $have[strtolower($s['share_with'])] = $s;
            }
            foreach ($w['want'] as $uid => $perm) {
                $s = $have[strtolower($uid)] ?? null;
                if (!$s) { if (!$dry) $api->shareCreate((string)$row['path'], $uid, $perm); $res['added'][] = $uid; continue; }
                if ($s['permissions'] !== $perm) { if (!$dry) $api->shareUpdate($s['id'], $perm); $res['updated'][] = $uid; }
            }
            $wantLc = array_change_key_case($w['want'], CASE_LOWER);
            foreach ($have as $lc => $s) {
                if (!isset($wantLc[$lc])) { if (!$dry) $api->shareDelete($s['id']); $res['removed'][] = $s['share_with']; }
            }
        } catch (\Throwable $e) {
            $res['error'] = mb_substr($e->getMessage(), 0, 300);
        }
        if (!$dry) db()->prepare("UPDATE ws_cloud_roots SET shares_at = ?, shares_error = ? WHERE workspace_id = ?")
                       ->execute([date('Y-m-d H:i:s'), $res['error'], $wsId]);
        return $res;
    }

    /** Wszystkie obszary z folderem w chmurze (agent CRON). */
    public static function syncAll(bool $dry = false): array
    {
        self::migrate();
        $out = [];
        foreach (db_all("SELECT r.workspace_id FROM ws_cloud_roots r JOIN task_workspaces w ON w.id = r.workspace_id") as $r) {
            $out[(int)$r['workspace_id']] = self::syncShares((int)$r['workspace_id'], $dry);
        }
        return $out;
    }

    /** Synchronizacja po zmianie członków obszaru — bez wyjątków (zapis członków nie może się przez nią wywrócić). */
    public static function syncQuiet(int $wsId): void
    {
        try {
            if (self::available() && self::rootRow($wsId)) self::syncShares($wsId);
        } catch (\Throwable $e) {
            error_log('[WsCloud] udostępnienia obszaru ' . $wsId . ': ' . $e->getMessage());
        }
    }
}
