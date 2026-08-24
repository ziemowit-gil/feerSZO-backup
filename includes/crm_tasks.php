<?php
/**
 * includes/crm_tasks.php — zadania WŁASNE modułu CRM.
 *
 * DLACZEGO OSOBNO OD MODUŁU ZADAŃ: „zadanie" w CRM to przypomnienie przy
 * kartotece albo sprawie — oddzwonić, wysłać ofertę, dopytać o podpis. Żyje
 * krótko, dotyczy jednego kontaktu i nie ma nic wspólnego z pracą zespołową:
 * obszarami, listami, tablicą, przypisaniami i archiwizacją z modułu Zadań.
 *
 * Wcześniej CRM zakładał wiersze w `tasks`, przez co:
 *   • bez obszaru i listy w module Zadań nie dało się dodać przypomnienia
 *     („Nie masz żadnej listy zadań — załóż obszar"),
 *   • tablica zespołu zapełniała się drobiazgami jednej osoby,
 *   • uprawnienie do CRM nie wystarczało — trzeba było mieć zapis w Zadaniach.
 *
 * Stąd własna tabela `crm_tasks`. Powiązania historyczne (crm_case_tasks →
 * tasks) zostają nietknięte i nadal są pokazywane przy sprawie, żeby nic,
 * co ktoś już założył, nie zniknęło z oczu.
 */

/** Samonaprawa schematu — wywoływana leniwie przez funkcje niżej. */
function crm_tasks_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_tasks (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id  INTEGER REFERENCES crm_contacts(id) ON DELETE CASCADE,
            case_id     INTEGER REFERENCES crm_cases(id)    ON DELETE CASCADE,
            title       TEXT    NOT NULL,
            description TEXT,
            due_date    DATE,
            priority    TEXT    NOT NULL DEFAULT 'medium',
            status      TEXT    NOT NULL DEFAULT 'open',
            owner_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            done_at     DATETIME,
            done_by     INTEGER REFERENCES users(id) ON DELETE SET NULL
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_tasks_case    ON crm_tasks(case_id, status)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_tasks_contact ON crm_tasks(contact_id, status)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_tasks_owner   ON crm_tasks(owner_id, status, due_date)");
    } catch (\Throwable $e) {
        error_log('[crm_tasks_migrate] ' . $e->getMessage());
    }
}

const CRM_TASK_PRIORITIES = [
    'low'    => ['label' => 'Niski',  'color' => '#6B7280'],
    'medium' => ['label' => 'Zwykły', 'color' => '#D97706'],
    'high'   => ['label' => 'Pilne',  'color' => '#DC2626'],
];

/**
 * Dodaje zadanie CRM.
 *
 * @param array $d title, contact_id, case_id, due_date, priority, owner_id, description
 * @return array{ok:bool,error:string,id:int}
 */
function crm_task_add(array $d): array
{
    crm_tasks_migrate();

    $title = trim((string)($d['title'] ?? ''));
    if ($title === '') return ['ok' => false, 'error' => 'Wpisz, co jest do zrobienia.', 'id' => 0];

    $uid  = function_exists('current_user') ? (int)(current_user()['id'] ?? 0) : 0;
    $prio = isset(CRM_TASK_PRIORITIES[$d['priority'] ?? '']) ? (string)$d['priority'] : 'medium';

    // Termin przyjmujemy tylko w formacie daty — pusty ciąg zapisany jako '' udawałby
    // termin i psułby sortowanie po dacie
    $due = trim((string)($d['due_date'] ?? ''));
    if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = '';

    try {
        $id = db_insert('crm_tasks', [
            'contact_id'  => ((int)($d['contact_id'] ?? 0)) ?: null,
            'case_id'     => ((int)($d['case_id']    ?? 0)) ?: null,
            'title'       => mb_substr($title, 0, 300),
            'description' => trim((string)($d['description'] ?? '')) ?: null,
            'due_date'    => $due ?: null,
            'priority'    => $prio,
            'status'      => 'open',
            // Bez wskazania właściciela zadanie należy do tego, kto je zapisał —
            // przypomnienie bez adresata nie przypomina nikomu
            'owner_id'    => ((int)($d['owner_id'] ?? 0)) ?: ($uid ?: null),
            'created_by'  => $uid ?: null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'error' => '', 'id' => (int)$id];
    } catch (\Throwable $e) {
        error_log('[crm_task_add] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Nie udało się zapisać zadania.', 'id' => 0];
    }
}

/** Odhaczenie i cofnięcie odhaczenia. */
function crm_task_set_done(int $id, bool $done = true): bool
{
    crm_tasks_migrate();
    $uid = function_exists('current_user') ? (int)(current_user()['id'] ?? 0) : 0;
    try {
        db()->prepare("UPDATE crm_tasks SET status=?, done_at=?, done_by=? WHERE id=?")
            ->execute([
                $done ? 'done' : 'open',
                $done ? date('Y-m-d H:i:s') : null,
                $done ? ($uid ?: null) : null,
                $id,
            ]);
        return true;
    } catch (\Throwable $e) { return false; }
}

/** Usunięcie. Zadanie to notatka do odhaczenia — nie ma czego archiwizować. */
function crm_task_delete(int $id): bool
{
    crm_tasks_migrate();
    try { db()->prepare("DELETE FROM crm_tasks WHERE id=?")->execute([$id]); return true; }
    catch (\Throwable $e) { return false; }
}

/** Zmiana terminu albo pilności bez otwierania formularza. */
function crm_task_update(int $id, array $d): bool
{
    crm_tasks_migrate();
    $set = []; $par = [];
    if (array_key_exists('due_date', $d)) {
        $due = trim((string)$d['due_date']);
        if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) return false;
        $set[] = 'due_date=?'; $par[] = $due ?: null;
    }
    if (isset($d['priority']) && isset(CRM_TASK_PRIORITIES[$d['priority']])) {
        $set[] = 'priority=?'; $par[] = (string)$d['priority'];
    }
    if (isset($d['owner_id'])) { $set[] = 'owner_id=?'; $par[] = ((int)$d['owner_id']) ?: null; }
    if (isset($d['title'])) {
        $t = trim((string)$d['title']);
        if ($t === '') return false;
        $set[] = 'title=?'; $par[] = mb_substr($t, 0, 300);
    }
    if (!$set) return false;
    $par[] = $id;
    try { db()->prepare("UPDATE crm_tasks SET " . implode(',', $set) . " WHERE id=?")->execute($par); return true; }
    catch (\Throwable $e) { return false; }
}

/** Jedno zadanie z nazwami kontaktu i sprawy. */
function crm_task(int $id): ?array
{
    crm_tasks_migrate();
    try {
        return db_one(
            "SELECT t.*, ct.imie_nazwisko AS contact_name, cs.title AS case_title, u.name AS owner_name
               FROM crm_tasks t
          LEFT JOIN crm_contacts ct ON ct.id = t.contact_id
          LEFT JOIN crm_cases    cs ON cs.id = t.case_id
          LEFT JOIN users        u  ON u.id  = t.owner_id
              WHERE t.id=?", [$id]
        ) ?: null;
    } catch (\Throwable $e) { return null; }
}

/** Zadania sprawy — otwarte na górze, potem po terminie. */
function crm_tasks_for_case(int $case_id): array
{
    crm_tasks_migrate();
    if ($case_id <= 0) return [];
    try {
        return db_all(
            "SELECT t.*, u.name AS owner_name
               FROM crm_tasks t LEFT JOIN users u ON u.id = t.owner_id
              WHERE t.case_id=?
           ORDER BY t.status='done', t.due_date IS NULL, t.due_date, t.id", [$case_id]
        );
    } catch (\Throwable $e) { return []; }
}

/** Zadania kontaktu (także te przypięte przez sprawę tego kontaktu). */
function crm_tasks_for_contact(int $contact_id, bool $only_open = false): array
{
    crm_tasks_migrate();
    if ($contact_id <= 0) return [];
    $extra = $only_open ? " AND t.status='open'" : '';
    try {
        return db_all(
            "SELECT t.*, u.name AS owner_name, cs.title AS case_title
               FROM crm_tasks t
          LEFT JOIN users     u  ON u.id  = t.owner_id
          LEFT JOIN crm_cases cs ON cs.id = t.case_id
              WHERE t.contact_id=? {$extra}
           ORDER BY t.status='done', t.due_date IS NULL, t.due_date, t.id", [$contact_id]
        );
    } catch (\Throwable $e) { return []; }
}

/**
 * Lista do ekranu zadań CRM.
 *
 * @param array $f owner_id, status (open|done|all), overdue, q, contact_id
 */
function crm_tasks_list(array $f = [], int $limit = 300): array
{
    crm_tasks_migrate();
    $w = ['1=1']; $p = [];

    $status = (string)($f['status'] ?? 'open');
    if ($status === 'open' || $status === 'done') { $w[] = 't.status=?'; $p[] = $status; }

    if (!empty($f['owner_id'])) { $w[] = 't.owner_id=?'; $p[] = (int)$f['owner_id']; }
    if (!empty($f['contact_id'])) { $w[] = 't.contact_id=?'; $p[] = (int)$f['contact_id']; }
    if (!empty($f['overdue'])) {
        $w[] = "t.status='open' AND t.due_date IS NOT NULL AND date(t.due_date) < date('now')";
    }
    if (!empty($f['q'])) {
        $q = '%' . trim((string)$f['q']) . '%';
        $w[] = '(t.title LIKE ? OR ct.imie_nazwisko LIKE ?)';
        array_push($p, $q, $q);
    }

    try {
        return db_all(
            "SELECT t.*, ct.imie_nazwisko AS contact_name, cs.title AS case_title, u.name AS owner_name
               FROM crm_tasks t
          LEFT JOIN crm_contacts ct ON ct.id = t.contact_id
          LEFT JOIN crm_cases    cs ON cs.id = t.case_id
          LEFT JOIN users        u  ON u.id  = t.owner_id
              WHERE " . implode(' AND ', $w) . "
           ORDER BY t.status='done', t.due_date IS NULL, t.due_date, t.id
              LIMIT " . max(1, $limit), $p
        );
    } catch (\Throwable $e) { return []; }
}

/** Liczniki do pigułek filtrów: moje otwarte, po terminie, wszystkie otwarte. */
function crm_tasks_counts(int $uid): array
{
    crm_tasks_migrate();
    $one = static function (string $sql, array $p = []): int {
        try { return (int)(db_one($sql, $p)['n'] ?? 0); } catch (\Throwable $e) { return 0; }
    };
    return [
        'mine'    => $one("SELECT COUNT(*) AS n FROM crm_tasks WHERE status='open' AND owner_id=?", [$uid]),
        'open'    => $one("SELECT COUNT(*) AS n FROM crm_tasks WHERE status='open'"),
        'overdue' => $one("SELECT COUNT(*) AS n FROM crm_tasks
                            WHERE status='open' AND due_date IS NOT NULL AND date(due_date) < date('now')"),
    ];
}

/** Etykieta terminu wraz z informacją, czy jest przekroczony. */
function crm_task_due_label(?string $due): array
{
    if (!$due) return ['', false];
    $ts = strtotime($due);
    if (!$ts) return ['', false];
    $today = strtotime(date('Y-m-d'));
    $days  = (int)round(($ts - $today) / 86400);
    if ($days < 0)  return [date('d.m.Y', $ts), true];
    if ($days === 0) return ['dziś', false];
    if ($days === 1) return ['jutro', false];
    return [date('d.m.Y', $ts), false];
}
