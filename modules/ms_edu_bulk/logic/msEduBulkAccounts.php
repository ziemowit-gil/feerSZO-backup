<?php
/**
 * Rejestr kont EDU założonych generatorem + cykl życia: zawieszenie (offboarding), przywrócenie,
 * menedżer, MFA, role administracyjne Entra (tylko Helpdesk / User Administrator — bez Global Admina).
 */

function msEduBulkMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS msedu_bulk_accounts (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            ms_user_id   TEXT NOT NULL DEFAULT '',
            upn          TEXT NOT NULL DEFAULT '',
            imie         TEXT NOT NULL DEFAULT '',
            nazwisko     TEXT NOT NULL DEFAULT '',
            rola         TEXT NOT NULL DEFAULT '',
            klasa        TEXT NOT NULL DEFAULT '',
            email        TEXT NOT NULL DEFAULT '',
            anonim       INTEGER NOT NULL DEFAULT 0,
            status       TEXT NOT NULL DEFAULT 'active',
            manager_upn  TEXT NOT NULL DEFAULT '',
            mfa          INTEGER NOT NULL DEFAULT 0,
            admin_role   TEXT NOT NULL DEFAULT '',
            suspended_at DATETIME,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_msedu_bulk_upn ON msedu_bulk_accounts(upn)");
        foreach (["grupa TEXT NOT NULL DEFAULT ''", "grupa_ok INTEGER NOT NULL DEFAULT 1"] as $col) {
            try { db()->exec("ALTER TABLE msedu_bulk_accounts ADD COLUMN $col"); } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) { error_log('[msedu_bulk migrate] ' . $e->getMessage()); }
}

/** Zapis konta w rejestrze (wołane po udanym utworzeniu). Nigdy nie przerywa tworzenia. */
function msEduBulkRegister(string $msUserId, array $r): void {
    try {
        msEduBulkMigrate();
        db()->prepare("INSERT OR REPLACE INTO msedu_bulk_accounts (ms_user_id, upn, imie, nazwisko, rola, klasa, email, anonim, grupa, grupa_ok)
                       VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$msUserId, $r['upn'], $r['imie'] ?? '', $r['nazwisko'] ?? '', $r['rola'] ?? '', $r['klasa'] ?? '', $r['email'] ?? '', !empty($r['anonim']) ? 1 : 0,
                       $r['grupa'] ?? '', array_key_exists('grupa_ok', $r) && !$r['grupa_ok'] ? 0 : 1]);
    } catch (\Throwable $e) { /* rejestr pomocniczy */ }
}

function msEduBulkAccountsList(string $q = '', string $status = ''): array {
    msEduBulkMigrate();
    $sql = "SELECT * FROM msedu_bulk_accounts WHERE 1=1"; $p = [];
    if ($q !== '') { $sql .= " AND (upn LIKE ? OR imie LIKE ? OR nazwisko LIKE ? OR klasa LIKE ?)"; $p = array_fill(0, 4, '%' . $q . '%'); }
    if (in_array($status, ['active', 'suspended'], true)) { $sql .= " AND status=?"; $p[] = $status; }
    $st = db()->prepare($sql . " ORDER BY created_at DESC, id DESC LIMIT 500");
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function msEduBulkAccountsByIds(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    msEduBulkMigrate();
    $st = db()->prepare("SELECT * FROM msedu_bulk_accounts WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute($ids);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Wykonuje akcję na zaznaczonych kontach.
 * @param string $action suspend | restore | manager | mfa | role
 * @param array  $opts   ['licenses'=>bool (suspend), 'manager'=>upn, 'role'=>helpdesk|user]
 * @return array{done:int,errors:string[]}
 */
function msEduBulkAccountsAct(M365Graph $graph, array $accounts, string $action, array $opts = []): array {
    $done = 0; $errors = [];
    $managerId = null;
    if ($action === 'manager') {
        $managerId = $graph->user_id_by_upn(trim((string)($opts['manager'] ?? '')));
        if (!$managerId) return ['done' => 0, 'errors' => ['Nie znaleziono menedżera o podanym UPN w tenancie EDU.']];
    }
    foreach ($accounts as $a) {
        $id = $a['ms_user_id']; $upn = $a['upn'];
        try {
            switch ($action) {
                case 'suspend':
                    $graph->set_enabled($id, false);          // AccountEnabled = false
                    $graph->revoke_sessions($id);             // revokeSignInSessions
                    if (!empty($opts['licenses'])) $graph->remove_all_licenses($id);
                    db()->prepare("UPDATE msedu_bulk_accounts SET status='suspended', suspended_at=datetime('now') WHERE id=?")->execute([$a['id']]);
                    break;
                case 'restore':
                    $graph->set_enabled($id, true);
                    db()->prepare("UPDATE msedu_bulk_accounts SET status='active', suspended_at=NULL WHERE id=?")->execute([$a['id']]);
                    break;
                case 'manager':
                    if (!$graph->set_manager($id, $managerId)) throw new RuntimeException('Graph odrzucił ustawienie menedżera (HTTP ' . $graph->last_status() . ').');
                    db()->prepare("UPDATE msedu_bulk_accounts SET manager_upn=? WHERE id=?")->execute([trim((string)$opts['manager']), $a['id']]);
                    break;
                case 'mfa':
                    if (!$graph->set_per_user_mfa($id, true)) throw new RuntimeException('Graph odrzucił wymuszenie MFA (brak uprawnienia UserAuthenticationMethod.ReadWrite.All?).');
                    db()->prepare("UPDATE msedu_bulk_accounts SET mfa=1 WHERE id=?")->execute([$a['id']]);
                    break;
                case 'role':
                    $role = M365Graph::SAFE_DIRECTORY_ROLES[$opts['role'] ?? ''] ?? null;
                    if (!$role) throw new RuntimeException('Niedozwolona rola.');
                    if (!$graph->assign_directory_role($id, $role[0])) throw new RuntimeException('Graph odrzucił przypisanie roli (' . $role[1] . ').');
                    db()->prepare("UPDATE msedu_bulk_accounts SET admin_role=? WHERE id=?")->execute([$role[1], $a['id']]);
                    break;
                default: throw new RuntimeException('Nieznana akcja.');
            }
            $done++;
        } catch (\Throwable $e) { $errors[] = $upn . ': ' . $e->getMessage(); }
    }
    return ['done' => $done, 'errors' => $errors];
}

/** Liczba kont (z podanych UPN) czekających na dodanie do grupy MS365 (wcześniejszy błąd, np. 404). */
function msEduBulkPendingGroups(array $upns): int {
    if (!$upns) return 0;
    msEduBulkMigrate();
    $st = db()->prepare("SELECT COUNT(*) FROM msedu_bulk_accounts WHERE grupa<>'' AND grupa_ok=0 AND upn IN (" . implode(',', array_fill(0, count($upns), '?')) . ")");
    $st->execute(array_values($upns));
    return (int)$st->fetchColumn();
}

/**
 * Ponawia dodanie do grup MS365 dla kont z nieudanym dodaniem (np. HTTP 404 przy replikacji).
 * Wołane po wysyłce maili oraz ręcznie („Ponów dodanie do grup”).
 * @param array|null $upns ograniczenie do tych kont (null = wszystkie oczekujące)
 * @return array{fixed:string[],failed:string[]}
 */
function msEduBulkRetryGroups(M365Graph $graph, ?array $upns = null): array {
    msEduBulkMigrate();
    $sql = "SELECT * FROM msedu_bulk_accounts WHERE grupa<>'' AND grupa_ok=0"; $p = [];
    if ($upns !== null) {
        if (!$upns) return ['fixed' => [], 'failed' => []];
        $sql .= " AND upn IN (" . implode(',', array_fill(0, count($upns), '?')) . ")"; $p = array_values($upns);
    }
    $st = db()->prepare($sql . " LIMIT 300"); $st->execute($p);
    $fixed = []; $failed = []; $gc = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        try {
            msEduBulkEnsureGroupAdd($graph, $a['ms_user_id'], $a['grupa'], $gc);
            db()->prepare("UPDATE msedu_bulk_accounts SET grupa_ok=1 WHERE id=?")->execute([$a['id']]);
            $fixed[] = $a['upn'];
        } catch (\Throwable $e) { $failed[] = $a['upn'] . ': ' . $e->getMessage(); }
    }
    return ['fixed' => $fixed, 'failed' => $failed];
}

/** Po udanym ponowieniu usuwa z wyników w sesji ostrzeżenie o grupie dla tych kont. */
function msEduBulkClearGroupWarn(array $upns): void {
    foreach ($_SESSION['msEduBulkResult'] ?? [] as $i => $r) {
        if (in_array($r['upn'], $upns, true)) {
            $_SESSION['msEduBulkResult'][$i]['warn'] = trim(preg_replace('/grupa „[^;]*(;\s*|$)/u', '', (string)($r['warn'] ?? '')));
        }
    }
}
