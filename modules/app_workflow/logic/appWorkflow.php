<?php
/**
 * modules/app_workflow/logic/appWorkflow.php — edytowalny workflow pism i wniosków użytkowników.
 *
 * Każdy typ wniosku (application_types) ma własną listę etapów (app_wf_stages): nazwa, status wniosku,
 * który etap oznacza (nowy / w_trakcie / rozpatrzony / odrzucony), odpowiedzialna rola albo osoba,
 * termin w dniach i akcje przy wejściu w etap (wystaw UPZ, powiadom składającego e-mailem).
 * Typ bez własnych etapów korzysta z etapów domyślnych (appWfDefaultStages) — zapis w edytorze
 * zakłada mu własną kopię.
 *
 * Wniosek pamięta bieżący etap (user_applications.wf_stage_id, wf_stage_at, wf_due_at, wf_assignee_id);
 * każde przejście trafia do app_wf_log i ustawia status wniosku z etapu. Etap docelowy wybiera się
 * dowolnie (dalej, wstecz, odrzucenie) — workflow podpowiada, nie blokuje.
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';

const APP_WF_STATUSES = [
    'nowy'        => 'Nowy',
    'w_trakcie'   => 'W trakcie',
    'rozpatrzony' => 'Rozpatrzony',
    'odrzucony'   => 'Odrzucony',
];

const APP_WF_ROLES = [
    ''       => 'każdy z dostępem do pism',
    'admin'  => 'administrator',
    'editor' => 'redaktor',
];

function appWfMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_wf_stages (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        type_id          INTEGER NOT NULL REFERENCES application_types(id) ON DELETE CASCADE,
        position         INTEGER NOT NULL DEFAULT 0,
        name             TEXT NOT NULL,
        status           TEXT NOT NULL DEFAULT 'w_trakcie' CHECK (status IN ('nowy','w_trakcie','rozpatrzony','odrzucony')),
        role             TEXT NOT NULL DEFAULT '',
        assignee_user_id INTEGER,
        due_days         INTEGER NOT NULL DEFAULT 0,
        act_upz          INTEGER NOT NULL DEFAULT 0,
        act_notify       INTEGER NOT NULL DEFAULT 0,
        is_final         INTEGER NOT NULL DEFAULT 0,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_app_wf_stages_type ON app_wf_stages(type_id, position)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_wf_log (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        application_id INTEGER NOT NULL,
        from_stage_id  INTEGER,
        to_stage_id    INTEGER,
        from_name      TEXT NOT NULL DEFAULT '',
        to_name        TEXT NOT NULL DEFAULT '',
        note           TEXT NOT NULL DEFAULT '',
        user_id        INTEGER,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_app_wf_log_app ON app_wf_log(application_id, id)");
    $cols = array_column($pdo->query("PRAGMA table_info(user_applications)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
    foreach (['wf_stage_id' => 'INTEGER', 'wf_stage_at' => 'DATETIME', 'wf_due_at' => 'DATETIME', 'wf_assignee_id' => 'INTEGER'] as $c => $def) {
        if ($cols && !in_array($c, $cols, true)) $pdo->exec("ALTER TABLE user_applications ADD COLUMN {$c} {$def}");
    }
}

/** Etapy domyślne dla typu bez własnej konfiguracji (id = 0 — nie są zapisane w bazie). */
function appWfDefaultStages(): array {
    $s = fn(int $pos, string $name, string $status, int $due, int $upz, int $notify, int $final) =>
        ['id' => 0, 'position' => $pos, 'name' => $name, 'status' => $status, 'role' => '', 'assignee_user_id' => null,
         'due_days' => $due, 'act_upz' => $upz, 'act_notify' => $notify, 'is_final' => $final];
    return [
        $s(10, 'Rejestracja',  'nowy',        1, 1, 0, 0),
        $s(20, 'Weryfikacja',  'w_trakcie',   3, 0, 1, 0),
        $s(30, 'Realizacja',   'w_trakcie',   7, 0, 0, 0),
        $s(40, 'Zakończony',   'rozpatrzony', 0, 0, 1, 1),
        $s(50, 'Odrzucony',    'odrzucony',   0, 0, 1, 1),
    ];
}

/** Etapy typu (własne albo domyślne). */
function appWfStages(int $typeId): array {
    appWfMigrate();
    $rows = db_all("SELECT * FROM app_wf_stages WHERE type_id = ? ORDER BY position, id", [$typeId]);
    return $rows ?: appWfDefaultStages();
}

function appWfHasOwn(int $typeId): bool {
    appWfMigrate();
    return (bool)db_one("SELECT 1 AS x FROM app_wf_stages WHERE type_id = ? LIMIT 1", [$typeId]);
}

/**
 * Zapis etapów typu z edytora. $rows — wiersze formularza: id (0 = nowy), name, status, role, assignee_user_id,
 * due_days, act_upz, act_notify, is_final, _delete. Kolejność = kolejność w tablicy.
 * Usunięcie etapu, na którym stoją wnioski, przenosi je na pierwszy etap.
 */
function appWfSaveStages(int $typeId, array $rows): void {
    appWfMigrate();
    $clean = [];
    foreach ($rows as $r) {
        if (!empty($r['_delete'])) { $clean[] = ['id' => (int)($r['id'] ?? 0), '_delete' => true]; continue; }
        $name = mb_substr(trim((string)($r['name'] ?? '')), 0, 80);
        if ($name === '') continue;
        $status = isset(APP_WF_STATUSES[$r['status'] ?? '']) ? (string)$r['status'] : 'w_trakcie';
        $clean[] = [
            'id' => (int)($r['id'] ?? 0), 'name' => $name, 'status' => $status,
            'role' => isset(APP_WF_ROLES[$r['role'] ?? '']) ? (string)($r['role'] ?? '') : '',
            'assignee_user_id' => (int)($r['assignee_user_id'] ?? 0) ?: null,
            'due_days' => max(0, min(365, (int)($r['due_days'] ?? 0))),
            'act_upz' => !empty($r['act_upz']) ? 1 : 0, 'act_notify' => !empty($r['act_notify']) ? 1 : 0,
            'is_final' => !empty($r['is_final']) || in_array($status, ['rozpatrzony', 'odrzucony'], true) ? 1 : 0,
        ];
    }
    $keep = array_values(array_filter($clean, fn($r) => empty($r['_delete'])));
    if (!$keep) throw new \InvalidArgumentException('Workflow musi mieć co najmniej jeden etap.');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $own = array_map('intval', array_column(db_all("SELECT id FROM app_wf_stages WHERE type_id = ?", [$typeId]), 'id'));
        $pos = 0;
        $ids = [];
        foreach ($keep as $r) {
            $pos += 10;
            if ($r['id'] && in_array($r['id'], $own, true)) {
                $pdo->prepare("UPDATE app_wf_stages SET position=?, name=?, status=?, role=?, assignee_user_id=?, due_days=?, act_upz=?, act_notify=?, is_final=? WHERE id=? AND type_id=?")
                    ->execute([$pos, $r['name'], $r['status'], $r['role'], $r['assignee_user_id'], $r['due_days'], $r['act_upz'], $r['act_notify'], $r['is_final'], $r['id'], $typeId]);
                $ids[] = $r['id'];
            } else {
                $pdo->prepare("INSERT INTO app_wf_stages (type_id, position, name, status, role, assignee_user_id, due_days, act_upz, act_notify, is_final) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$typeId, $pos, $r['name'], $r['status'], $r['role'], $r['assignee_user_id'], $r['due_days'], $r['act_upz'], $r['act_notify'], $r['is_final']]);
                $ids[] = (int)$pdo->lastInsertId();
            }
        }
        $gone = array_diff($own, $ids);
        if ($gone) {
            $ph = implode(',', array_fill(0, count($gone), '?'));
            // wnioski z usuniętych etapów wracają na pierwszy etap typu
            $pdo->prepare("UPDATE user_applications SET wf_stage_id = ? WHERE wf_stage_id IN ({$ph})")->execute(array_merge([$ids[0]], array_values($gone)));
            $pdo->prepare("DELETE FROM app_wf_stages WHERE id IN ({$ph})")->execute(array_values($gone));
        }
        // wnioski typu, które stały na etapach domyślnych (wf_stage_id NULL), zostają bez zmian — dostaną etap przy pierwszym przejściu
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if (function_exists('audit_log')) { try { audit_log('app_workflow.stages_saved', ['type_id' => $typeId, 'stages' => count($keep)]); } catch (\Throwable $e) {} }
}

/**
 * Bieżący etap wniosku. Bez zapisanego etapu — etap odpowiadający statusowi (pierwszy z tym statusem).
 * Zwraca [etapy typu, indeks bieżącego].
 */
function appWfState(array $app): array {
    $stages = appWfStages((int)$app['type_id']);
    $idx = null;
    if (!empty($app['wf_stage_id'])) {
        foreach ($stages as $i => $s) if ((int)$s['id'] === (int)$app['wf_stage_id']) { $idx = $i; break; }
    }
    if ($idx === null) {
        foreach ($stages as $i => $s) if ($s['status'] === $app['status']) { $idx = $i; break; }
    }
    return [$stages, $idx ?? 0];
}

/**
 * Przejście wniosku do etapu $toIndex (indeks w appWfStages typu). Typ na etapach domyślnych
 * dostaje przy pierwszym przejściu własną kopię etapów (żeby wf_stage_id wskazywał na wiersz w bazie).
 * Skutki: status wniosku z etapu, termin, osoba odpowiedzialna, wpis w app_wf_log, akcje etapu.
 * @return array{stage: array, upz: ?string, mail: ?bool}
 */
function appWfMove(int $appId, int $toIndex, string $note, ?array $actor): array {
    appWfMigrate();
    $app = db_one("SELECT a.*, t.name AS type_name FROM user_applications a LEFT JOIN application_types t ON t.id = a.type_id WHERE a.id = ?", [$appId]);
    if (!$app) throw new \InvalidArgumentException('Wniosek nie istnieje.');
    $typeId = (int)$app['type_id'];
    if ($typeId && !appWfHasOwn($typeId)) appWfSaveStages($typeId, appWfDefaultStages());
    [$stages, $cur] = appWfState($app);
    if (!isset($stages[$toIndex])) throw new \InvalidArgumentException('Nie ma takiego etapu.');
    // bez zapisanego etapu (świeży wniosek) start na etapie 0 nie ma etapu „z”
    $from = !empty($app['wf_stage_id']) || $toIndex !== $cur ? ($stages[$cur] ?? null) : null;
    $to   = $stages[$toIndex];
    $now  = date('Y-m-d H:i:s');
    $due  = (int)$to['due_days'] > 0 && !(int)$to['is_final'] ? date('Y-m-d H:i:s', strtotime('+' . (int)$to['due_days'] . ' days')) : null;
    $note = mb_substr(trim($note), 0, 2000);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE user_applications SET wf_stage_id=?, wf_stage_at=?, wf_due_at=?, wf_assignee_id=?, status=?, updated_at=? WHERE id=?")
            ->execute([(int)$to['id'] ?: null, $now, $due, $to['assignee_user_id'] ?: null, $to['status'], $now, $appId]);
        $pdo->prepare("INSERT INTO app_wf_log (application_id, from_stage_id, to_stage_id, from_name, to_name, note, user_id, created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$appId, $from ? ((int)$from['id'] ?: null) : null, (int)$to['id'] ?: null, $from['name'] ?? '', $to['name'], $note, $actor['id'] ?? null, $now]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $out = ['stage' => $to, 'upz' => null, 'mail' => null];
    if ((int)$to['act_upz']) {
        require_once dirname(__DIR__, 2) . '/application_documents/logic/applicationDocuments.php';
        try { $out['upz'] = appDocsIssueReceipt($appId); } catch (\Throwable $e) { error_log('[app_workflow] UPZ #' . $appId . ': ' . $e->getMessage()); }
    }
    if ((int)$to['act_notify']) $out['mail'] = appWfNotify($appId, $to, $note);
    if (function_exists('audit_log')) { try { audit_log('app_workflow.moved', ['application_id' => $appId, 'from' => $from['name'] ?? '', 'to' => $to['name']]); } catch (\Throwable $e) {} }
    return $out;
}

/** Start workflow przy złożeniu wniosku: pierwszy etap typu (z jego akcjami). Błąd nie cofa złożenia. */
function appWfStart(int $appId): void {
    try { appWfMove($appId, 0, 'Złożenie wniosku', null); }
    catch (\Throwable $e) { error_log('[app_workflow] start #' . $appId . ': ' . $e->getMessage()); }
}

/** E-mail do składającego o przejściu do etapu — wysyłany od razu (bez kolejki). */
function appWfNotify(int $appId, array $stage, string $note): bool {
    $a = db_one("SELECT a.*, u.name AS user_name, u.email AS user_email FROM user_applications a JOIN users u ON u.id = a.user_id WHERE a.id = ?", [$appId]);
    if (!$a || !filter_var((string)$a['user_email'], FILTER_VALIDATE_EMAIL)) return false;
    require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $url = APP_URL . '/panel/apply.php';
    $html = '<p>Dzień dobry ' . $e($a['user_name']) . ',</p>'
          . '<p>Twój wniosek <strong>' . $e($a['tytul']) . '</strong>' . ($a['doc_number'] ? ' (' . $e($a['doc_number']) . ')' : '')
          . ' przeszedł do etapu: <strong>' . $e($stage['name']) . '</strong> — status: ' . $e(APP_WF_STATUSES[$stage['status']] ?? $stage['status']) . '.</p>'
          . ($note !== '' ? '<p><strong>Uwagi:</strong><br>' . nl2br($e($note)) . '</p>' : '')
          . '<p>Szczegóły w Panelu współpracownika: <a href="' . $e($url) . '">' . $e($url) . '</a></p>';
    $id = mail_queue_add((string)$a['user_email'], (string)$a['user_name'], 'Wniosek: ' . $a['tytul'] . ' — ' . $stage['name'], $html, '', 'app_workflow', $appId, '', true);
    $row = $id ? db_one("SELECT status FROM mail_queue WHERE id = ?", [$id]) : null;
    return ($row['status'] ?? '') === 'sent';
}

/** Historia przejść wniosku. */
function appWfLog(int $appId): array {
    appWfMigrate();
    return db_all("SELECT l.*, u.name AS user_name FROM app_wf_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.application_id = ? ORDER BY l.id", [$appId]);
}
