<?php
/**
 * includes/task_nozbe.php
 * Integracja Nozbe PER UŻYTKOWNIK dla modułu Zadania — CAŁKOWICIE OSOBNA od
 * globalnej, jednotokenowej integracji w includes/nozbe.php (używanej dziś
 * przez CRM do wysyłania wiadomości ze Skrzynki, admin/nozbe_settings.php,
 * crm/settings/nozbe.php). Świadoma decyzja użytkownika (2026-09-04): nie
 * mieszać z tamtą, żeby nie ryzykować działającej integracji CRM.
 *
 * Kierunek: JEDNOSTRONNIE feerSZO → Nozbe (Nozbe nie odsyła statusów).
 * Każdy użytkownik wkleja WŁASNY token API Nozbe (Nozbe → Ustawienia →
 * API tokens) w tasks/nozbe_settings.php. Zadania, do których jest
 * przypisany (task_assignments), niezakończone, są automatycznie
 * wypychane do jego osobistego Nozbe co pewien czas przez
 * cron/tasks_nozbe_sync.php. Domyślny projekt docelowy: "task_me"
 * (osobisty Inbox danego użytkownika w Nozbe) — zero konfiguracji.
 *
 * Reużywa klasę NozbeAPI z includes/nozbe.php (czysty klient HTTP,
 * niezależny od tego skąd bierze token) — NIE reużywa tabeli nozbe_links
 * (ta jest globalna, keyed by local_type/local_id bez usera; tu potrzeba
 * klucza (task_id, user_id), bo to samo zadanie może być wypychane do
 * WIELU różnych, osobistych kont Nozbe — po jednym per przypisana osoba).
 */
require_once __DIR__ . '/nozbe.php';

function task_nozbe_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_nozbe_tokens (
        user_id      INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        api_token    TEXT    NOT NULL,
        project_id   TEXT    NOT NULL DEFAULT 'task_me',
        is_active    INTEGER NOT NULL DEFAULT 1,
        last_sync_at TEXT,
        last_error   TEXT,
        created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_nozbe_links (
        task_id       INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
        user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        nozbe_task_id TEXT    NOT NULL,
        pushed_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (task_id, user_id)
    )");
}

/** Konfiguracja Nozbe danego użytkownika (null = nie skonfigurowana). */
function task_nozbe_get_token(int $user_id): ?array {
    task_nozbe_migrate();
    return db_one("SELECT * FROM task_nozbe_tokens WHERE user_id=?", [$user_id]) ?: null;
}

function task_nozbe_configured(int $user_id): bool {
    $row = task_nozbe_get_token($user_id);
    return (bool)$row && (int)$row['is_active'] === 1 && trim($row['api_token']) !== '';
}

function task_nozbe_save_token(int $user_id, string $token, string $project_id): void {
    task_nozbe_migrate();
    $token      = trim($token);
    $project_id = trim($project_id) ?: 'task_me';
    db()->prepare(
        "INSERT INTO task_nozbe_tokens (user_id, api_token, project_id, is_active, created_at, updated_at)
         VALUES (?, ?, ?, 1, datetime('now','localtime'), datetime('now','localtime'))
         ON CONFLICT(user_id) DO UPDATE SET
            api_token=excluded.api_token, project_id=excluded.project_id,
            is_active=1, last_error=NULL, updated_at=datetime('now','localtime')"
    )->execute([$user_id, $token, $project_id]);
}

function task_nozbe_disconnect(int $user_id): void {
    task_nozbe_migrate();
    db()->prepare("DELETE FROM task_nozbe_tokens WHERE user_id=?")->execute([$user_id]);
    db()->prepare("DELETE FROM task_nozbe_links WHERE user_id=?")->execute([$user_id]);
}

/** Test połączenia z tokenem danego użytkownika — ['ok','error']. */
function task_nozbe_test(int $user_id): array {
    $row = task_nozbe_get_token($user_id);
    if (!$row || trim($row['api_token']) === '') {
        return ['ok' => false, 'error' => 'Brak zapisanego tokenu API.'];
    }
    try {
        (new NozbeAPI($row['api_token']))->get_projects();
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * Synchronizuje zadania jednego użytkownika do jego osobistego Nozbe.
 * Wypycha nowe (nieukończone, nieusunięte, nie-zarchiwizowane) przypisane
 * zadania, aktualizuje tytuł/termin już wypchniętych, oznacza w Nozbe jako
 * zakończone te, które zakończono w feerSZO od ostatniej synchronizacji.
 *
 * @return array ['ok','pushed'=>int,'updated'=>int,'completed'=>int,'error']
 */
function task_nozbe_sync_user(int $user_id): array {
    task_nozbe_migrate();
    $cfg = task_nozbe_get_token($user_id);
    if (!$cfg || (int)$cfg['is_active'] !== 1 || trim($cfg['api_token']) === '') {
        return ['ok' => false, 'pushed' => 0, 'updated' => 0, 'completed' => 0, 'error' => 'Nie skonfigurowano.'];
    }

    $api        = new NozbeAPI($cfg['api_token']);
    $project_id = $cfg['project_id'] ?: 'task_me';
    $pushed = $updated = $completed = 0;

    try {
        // ── Zadania przypisane, jeszcze aktywne ─────────────────────────────
        $tasks = db_all(
            "SELECT t.id, t.title, t.description, t.due_date, t.completed_at
             FROM tasks t
             JOIN task_assignments ta ON ta.task_id = t.id AND ta.user_id = ?
             WHERE t.deleted_at IS NULL AND t.archived_at IS NULL",
            [$user_id]
        );

        $active_ids = [];
        foreach ($tasks as $t) {
            if (!$t['completed_at']) $active_ids[] = (int)$t['id'];

            $link = db_one(
                "SELECT * FROM task_nozbe_links WHERE task_id=? AND user_id=?",
                [$t['id'], $user_id]
            );

            if (!$link) {
                if ($t['completed_at']) continue; // nie wypychaj już zakończonych po raz pierwszy
                $nz = $api->create_task($t['title'], $project_id, (string)($t['description'] ?? ''),
                    !empty($t['due_date']) ? substr($t['due_date'], 0, 10) : null);
                $nid = (string)($nz['id'] ?? '');
                if ($nid === '') continue;
                db()->prepare(
                    "INSERT INTO task_nozbe_links (task_id, user_id, nozbe_task_id, pushed_at, updated_at)
                     VALUES (?, ?, ?, datetime('now','localtime'), datetime('now','localtime'))"
                )->execute([$t['id'], $user_id, $nid]);
                $pushed++;
            } elseif ($t['completed_at']) {
                $api->complete_task($link['nozbe_task_id']);
                db()->prepare("UPDATE task_nozbe_links SET updated_at=datetime('now','localtime') WHERE task_id=? AND user_id=?")
                    ->execute([$t['id'], $user_id]);
                $completed++;
            } else {
                $fields = ['name' => mb_substr($t['title'], 0, 255)];
                $fields['due_at'] = !empty($t['due_date']) ? strtotime($t['due_date'] . ' 00:00:00 UTC') * 1000 : 0;
                $api->update_task($link['nozbe_task_id'], $fields);
                db()->prepare("UPDATE task_nozbe_links SET updated_at=datetime('now','localtime') WHERE task_id=? AND user_id=?")
                    ->execute([$t['id'], $user_id]);
                $updated++;
            }
        }

        db()->prepare(
            "UPDATE task_nozbe_tokens SET last_sync_at=datetime('now','localtime'), last_error=NULL WHERE user_id=?"
        )->execute([$user_id]);

        return ['ok' => true, 'pushed' => $pushed, 'updated' => $updated, 'completed' => $completed, 'error' => ''];
    } catch (\Throwable $e) {
        $msg = mb_substr($e->getMessage(), 0, 300);
        try {
            db()->prepare(
                "UPDATE task_nozbe_tokens SET last_sync_at=datetime('now','localtime'), last_error=? WHERE user_id=?"
            )->execute([$msg, $user_id]);
        } catch (\Throwable $e2) {}
        return ['ok' => false, 'pushed' => $pushed, 'updated' => $updated, 'completed' => $completed, 'error' => $msg];
    }
}

/** Wszyscy użytkownicy z aktywną konfiguracją — do użytku przez cron. */
function task_nozbe_active_user_ids(): array {
    task_nozbe_migrate();
    return array_map('intval', array_column(
        db_all("SELECT user_id FROM task_nozbe_tokens WHERE is_active=1 AND api_token != ''"),
        'user_id'
    ));
}
