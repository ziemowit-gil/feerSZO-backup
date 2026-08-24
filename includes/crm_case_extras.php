<?php
/**
 * includes/crm_case_extras.php — poczta przy sprawie + szablony spraw.
 *
 * Dwie rzeczy, których brakowało sprawom CRM:
 *
 * 1. WIADOMOŚCI PRZY SPRAWIE. Korespondencja żyła w Skrzynce i w historii
 *    kontaktu, ale nie było jak powiedzieć „ten mail dotyczy TEJ sprawy".
 *    Przy kliencie z pięcioma równoległymi tematami to zgaduj-zgadula.
 *    Dopinamy przez `crm_communications.case_id` — jedna kolumna, bez kopiowania
 *    treści, więc wiadomość dalej jest w skrzynce i w historii kontaktu.
 *
 * 2. SZABLONY SPRAW. Powtarzalne sprawy (skarga, wniosek o darowiznę, zgłoszenie
 *    beneficjenta) mają za każdym razem ten sam tytuł, opis i listę kroków.
 *    Szablon wypełnia je jednym kliknięciem; kroki lądują w opisie jako lista
 *    do odhaczenia, bo sprawy nie mają własnego modelu zadań.
 *
 * Szablony spraw celowo NIE są tym samym co przepływy (includes/crm_workflows.php):
 * przepływ tworzy KILKA obiektów naraz, szablon opisuje JEDNĄ sprawę.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crm.php';

(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("ALTER TABLE crm_communications ADD COLUMN case_id INTEGER");
    } catch (\Throwable $e) {}

    // Sprawa dostaje to, czego brakowało do prowadzenia jej jak sprawy, a nie notatki:
    // termin, opiekuna, typ, powód zamknięcia i znacznik pierwszej odpowiedzi (SLA).
    foreach ([
        "ALTER TABLE crm_cases ADD COLUMN due_date DATE",
        "ALTER TABLE crm_cases ADD COLUMN owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL",
        "ALTER TABLE crm_cases ADD COLUMN type_id INTEGER",
        "ALTER TABLE crm_cases ADD COLUMN closed_reason TEXT",
        "ALTER TABLE crm_cases ADD COLUMN first_response_at DATETIME",
        "ALTER TABLE crm_cases ADD COLUMN due_notified_at DATETIME",
        "ALTER TABLE crm_cases ADD COLUMN sla_breach_notified_at DATETIME",
    ] as $sql) {
        try { db()->exec($sql); } catch (\Throwable $e) {}
    }
    try { db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_cases_owner ON crm_cases(owner_id, status)"); }
    catch (\Throwable $e) {}
    try { db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_cases_due ON crm_cases(due_date, status)"); }
    catch (\Throwable $e) {}

    // Typy spraw z własnym SLA — „ile godzin na pierwszą odpowiedź, ile dni na zamknięcie"
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_case_types (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            name           TEXT    NOT NULL,
            color          TEXT    NOT NULL DEFAULT '#6B7280',
            sla_response_h INTEGER NOT NULL DEFAULT 0,   -- 0 = bez SLA
            sla_close_d    INTEGER NOT NULL DEFAULT 0,
            is_active      INTEGER NOT NULL DEFAULT 1,
            sort_order     INTEGER NOT NULL DEFAULT 0
        )");
    } catch (\Throwable $e) {}

    // Historia zmian statusu — „kto, kiedy, z czego na co i dlaczego"
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_case_status_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            case_id     INTEGER NOT NULL,
            from_status TEXT    NOT NULL DEFAULT '',
            to_status   TEXT    NOT NULL DEFAULT '',
            reason      TEXT    NOT NULL DEFAULT '',
            user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_case_log ON crm_case_status_log(case_id, id)");
    } catch (\Throwable $e) {}

    // Zadania sprawy — realne zadania z modułu Zadań, nie „[ ]" w opisie
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_case_tasks (
            case_id    INTEGER NOT NULL,
            task_id    INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (case_id, task_id)
        )");
    } catch (\Throwable $e) {}
    try {
        db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_comm_case ON crm_communications(case_id)");
    } catch (\Throwable $e) {}
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_case_templates (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            name         TEXT    NOT NULL,
            title_tpl    TEXT    NOT NULL DEFAULT '',
            description  TEXT    NOT NULL DEFAULT '',
            checklist    TEXT    NOT NULL DEFAULT '',   -- kroki, jeden w wierszu
            priority     TEXT    NOT NULL DEFAULT 'medium',
            is_active    INTEGER NOT NULL DEFAULT 1,
            created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Throwable $e) {}
})();

// ── Wiadomości przy sprawie ─────────────────────────────────────────────────

/** Wiadomości dopięte do sprawy (najnowsze pierwsze). */
function crm_case_messages(int $case_id): array {
    if ($case_id <= 0) return [];
    try {
        return db_all(
            "SELECT id, direction, subject, body, from_name, from_email, sent_at, msg_no, has_attachments
               FROM crm_communications
              WHERE case_id=? ORDER BY sent_at DESC, id DESC LIMIT 200", [$case_id]
        );
    } catch (\Throwable $e) { return []; }
}

/** Ile wiadomości wisi przy sprawie (do plakietki na zakładce). */
function crm_case_messages_count(int $case_id): int {
    if ($case_id <= 0) return 0;
    try {
        return (int)(db_one("SELECT COUNT(*) AS n FROM crm_communications WHERE case_id=?", [$case_id])['n'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

/**
 * Dopina wiadomość do sprawy (albo odpina, gdy $case_id = 0).
 * Sprawa i wiadomość muszą dotyczyć tego samego kontaktu — inaczej korespondencja
 * jednego klienta wylądowałaby w sprawie drugiego.
 */
function crm_case_attach_message(int $comm_id, int $case_id): array {
    if ($comm_id <= 0) return ['ok' => false, 'error' => 'Brak wiadomości.'];

    try {
        $m = db_one("SELECT id, contact_id FROM crm_communications WHERE id=?", [$comm_id]);
        if (!$m) return ['ok' => false, 'error' => 'Wiadomość nie istnieje.'];

        if ($case_id === 0) {
            db()->prepare("UPDATE crm_communications SET case_id=NULL WHERE id=?")->execute([$comm_id]);
            return ['ok' => true, 'error' => '', 'detached' => true];
        }

        $c = db_one("SELECT id, contact_id, title FROM crm_cases WHERE id=?", [$case_id]);
        if (!$c) return ['ok' => false, 'error' => 'Sprawa nie istnieje.'];

        if (!empty($m['contact_id']) && (int)$m['contact_id'] !== (int)$c['contact_id']) {
            return ['ok' => false, 'error' => 'Ta sprawa należy do innego kontaktu.'];
        }

        db()->prepare("UPDATE crm_communications SET case_id=?, contact_id=COALESCE(contact_id, ?) WHERE id=?")
            ->execute([$case_id, (int)$c['contact_id'], $comm_id]);

        return ['ok' => true, 'error' => '', 'case' => $c];
    } catch (\Throwable $e) {
        error_log('[crm_case_attach_message] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Nie udało się dopiąć wiadomości.'];
    }
}

/** Sprawy kontaktu do wyboru przy dopinaniu wiadomości. */
function crm_cases_for_contact(int $contact_id, bool $open_only = false): array {
    if ($contact_id <= 0) return [];
    try {
        return db_all(
            "SELECT id, title, status, case_number FROM crm_cases
              WHERE contact_id=?" . ($open_only ? " AND status IN ('open','in_progress')" : '') . "
           ORDER BY updated_at DESC, id DESC LIMIT 50", [$contact_id]
        );
    } catch (\Throwable $e) { return []; }
}

// ── Szablony spraw ──────────────────────────────────────────────────────────

function crm_case_templates(bool $only_active = true): array {
    try {
        return db_all("SELECT * FROM crm_case_templates"
            . ($only_active ? " WHERE is_active=1" : '') . " ORDER BY name");
    } catch (\Throwable $e) { return []; }
}

function crm_case_template(int $id): ?array {
    try { return db_one("SELECT * FROM crm_case_templates WHERE id=?", [$id]) ?: null; }
    catch (\Throwable $e) { return null; }
}

function crm_case_template_save(array $d, ?int $id = null): int {
    $row = [
        'name'        => mb_substr(trim((string)($d['name'] ?? '')), 0, 120),
        'title_tpl'   => mb_substr(trim((string)($d['title_tpl'] ?? '')), 0, 200),
        'description' => trim((string)($d['description'] ?? '')),
        'checklist'   => trim((string)($d['checklist'] ?? '')),
        'priority'    => in_array($d['priority'] ?? '', ['low','medium','high'], true) ? $d['priority'] : 'medium',
        'is_active'   => !empty($d['is_active']) ? 1 : 0,
        'updated_at'  => date('Y-m-d H:i:s'),
    ];
    if ($row['name'] === '') return 0;

    try {
        if ($id) {
            $sets = implode(',', array_map(static fn($k) => "$k=?", array_keys($row)));
            db()->prepare("UPDATE crm_case_templates SET {$sets} WHERE id=?")
                ->execute(array_merge(array_values($row), [$id]));
            return $id;
        }
        // Wołane też z CLI (migracje, seed) — tam nie ma zalogowanego użytkownika
        $row['created_by'] = function_exists('current_user') ? ((int)(current_user()['id'] ?? 0) ?: null) : null;
        $row['created_at'] = date('Y-m-d H:i:s');
        return (int)db_insert('crm_case_templates', $row);
    } catch (\Throwable $e) {
        error_log('[crm_case_template_save] ' . $e->getMessage());
        return 0;
    }
}

function crm_case_template_delete(int $id): bool {
    try { db()->prepare("DELETE FROM crm_case_templates WHERE id=?")->execute([$id]); return true; }
    catch (\Throwable $e) { return false; }
}

/**
 * Rozwija szablon do pól formularza sprawy.
 * Znaczniki: {kontakt}, {data}. Kroki trafiają do opisu jako lista „[ ]".
 *
 * @return array ['title','description','priority']
 */
function crm_case_template_apply(array $tpl, string $contact_name = ''): array {
    $fill = static fn(string $s): string => trim(str_replace(
        ['{kontakt}', '{data}'], [$contact_name, date('d.m.Y')], $s
    ));

    $desc  = $fill((string)$tpl['description']);
    $steps = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$tpl['checklist']))));
    if ($steps) {
        $desc = trim($desc . "\n\nKroki:\n" . implode("\n", array_map(
            static fn($s) => '[ ] ' . $s, $steps
        )));
    }

    return [
        'title'       => $fill((string)$tpl['title_tpl']) ?: (string)$tpl['name'],
        'description' => $desc,
        'priority'    => (string)$tpl['priority'],
    ];
}

// ── Typy spraw i SLA ────────────────────────────────────────────────────────

/** Typy spraw (kategorie) — z czasami SLA. */
function crm_case_types(bool $only_active = true): array {
    try {
        return db_all("SELECT * FROM crm_case_types"
            . ($only_active ? " WHERE is_active=1" : '') . " ORDER BY sort_order, name");
    } catch (\Throwable $e) { return []; }
}

function crm_case_type(int $id): ?array {
    if ($id <= 0) return null;
    try { return db_one("SELECT * FROM crm_case_types WHERE id=?", [$id]) ?: null; }
    catch (\Throwable $e) { return null; }
}

function crm_case_type_save(array $d, ?int $id = null): int {
    $row = [
        'name'           => mb_substr(trim((string)($d['name'] ?? '')), 0, 100),
        'color'          => preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($d['color'] ?? '')) ? $d['color'] : '#6B7280',
        'sla_response_h' => max(0, (int)($d['sla_response_h'] ?? 0)),
        'sla_close_d'    => max(0, (int)($d['sla_close_d'] ?? 0)),
        'is_active'      => !empty($d['is_active']) ? 1 : 0,
        'sort_order'     => (int)($d['sort_order'] ?? 0),
    ];
    if ($row['name'] === '') return 0;
    try {
        if ($id) {
            $sets = implode(',', array_map(static fn($k) => "$k=?", array_keys($row)));
            db()->prepare("UPDATE crm_case_types SET {$sets} WHERE id=?")
                ->execute(array_merge(array_values($row), [$id]));
            return $id;
        }
        return (int)db_insert('crm_case_types', $row);
    } catch (\Throwable $e) {
        error_log('[crm_case_type_save] ' . $e->getMessage());
        return 0;
    }
}

function crm_case_type_delete(int $id): bool {
    try {
        db()->prepare("UPDATE crm_cases SET type_id=NULL WHERE type_id=?")->execute([$id]);
        db()->prepare("DELETE FROM crm_case_types WHERE id=?")->execute([$id]);
        return true;
    } catch (\Throwable $e) { return false; }
}

/**
 * Stan SLA sprawy. Liczymy od WPŁYWU (created_at) — bo klient liczy czas od
 * momentu, w którym się zgłosił, a nie od chwili, gdy ktoś u nas kliknął.
 *
 * @return array ['has'=>bool, 'response'=>?array, 'close'=>?array]
 *               każdy element: ['deadline','left_h','state'=>ok|soon|breach|met','met_at'=>?string]
 */
function crm_case_sla(array $case): array {
    $type = crm_case_type((int)($case['type_id'] ?? 0));
    if (!$type || ((int)$type['sla_response_h'] === 0 && (int)$type['sla_close_d'] === 0)) {
        return ['has' => false, 'response' => null, 'close' => null];
    }

    $start = strtotime((string)($case['created_at'] ?? 'now')) ?: time();
    $now   = time();
    $closed = in_array((string)($case['status'] ?? ''), ['closed', 'cancelled'], true);

    $mk = static function (int $deadline, ?int $met_ts) use ($now): array {
        if ($met_ts !== null) {
            return ['deadline' => $deadline, 'left_h' => 0,
                    'state' => $met_ts <= $deadline ? 'met' : 'breach',
                    'met_at' => date('Y-m-d H:i:s', $met_ts)];
        }
        $left = (int)round(($deadline - $now) / 3600);
        $state = $left < 0 ? 'breach' : ($left <= 8 ? 'soon' : 'ok');
        return ['deadline' => $deadline, 'left_h' => $left, 'state' => $state, 'met_at' => null];
    };

    $out = ['has' => true, 'response' => null, 'close' => null];

    if ((int)$type['sla_response_h'] > 0) {
        $met = !empty($case['first_response_at']) ? strtotime((string)$case['first_response_at']) : null;
        $out['response'] = $mk($start + (int)$type['sla_response_h'] * 3600, $met ?: null);
    }
    if ((int)$type['sla_close_d'] > 0) {
        $met = $closed && !empty($case['closed_at']) ? strtotime((string)$case['closed_at']) : null;
        $out['close'] = $mk($start + (int)$type['sla_close_d'] * 86400, $met ?: null);
    }
    return $out;
}

/**
 * Znacznik pierwszej odpowiedzi — ustawiany raz, przy pierwszej wiadomości
 * WYCHODZĄCEJ powiązanej ze sprawą. Bez tego SLA odpowiedzi nigdy się nie domyka.
 */
function crm_case_touch_first_response(int $case_id, ?string $when = null): void {
    if ($case_id <= 0) return;
    try {
        db()->prepare("UPDATE crm_cases SET first_response_at=?
                        WHERE id=? AND (first_response_at IS NULL OR first_response_at='')")
            ->execute([$when ?: date('Y-m-d H:i:s'), $case_id]);
    } catch (\Throwable $e) {}
}

// ── Historia statusów ───────────────────────────────────────────────────────

/**
 * Zmiana statusu z zapisem w historii. Jedyna droga zmiany statusu sprawy —
 * inaczej historia kłamie, a przy reklamacji obsługi to pierwsze pytanie.
 *
 * @param string $reason powód (wymagany przy zamknięciu, patrz crm_case_close_reasons())
 */
function crm_case_set_status(int $case_id, string $to, string $reason = '', ?int $user_id = null): array {
    $case = null;
    try { $case = db_one("SELECT * FROM crm_cases WHERE id=?", [$case_id]); } catch (\Throwable $e) {}
    if (!$case) return ['ok' => false, 'error' => 'Sprawa nie istnieje.'];

    $from = (string)$case['status'];
    if ($from === $to) return ['ok' => true, 'error' => '', 'unchanged' => true];

    $now    = date('Y-m-d H:i:s');
    $closed = in_array($to, ['closed', 'cancelled'], true);
    $uid    = $user_id ?? (function_exists('current_user') ? (int)(current_user()['id'] ?? 0) : 0);

    try {
        db()->prepare("UPDATE crm_cases SET status=?, updated_at=?, closed_at=?, closed_reason=? WHERE id=?")
            ->execute([$to, $now, $closed ? $now : null, $closed ? ($reason ?: null) : null, $case_id]);

        db_insert('crm_case_status_log', [
            'case_id'     => $case_id,
            'from_status' => $from,
            'to_status'   => $to,
            'reason'      => $reason,
            'user_id'     => $uid ?: null,
            'created_at'  => $now,
        ]);
    } catch (\Throwable $e) {
        error_log('[crm_case_set_status] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Nie udało się zmienić statusu.'];
    }

    if (!empty($case['contact_id'])) {
        try {
            require_once __DIR__ . '/crm_automation.php';
            crm_automation_fire('case_status_changed', (int)$case['contact_id'], [
                'case_id' => $case_id, 'from_status' => $from, 'to_status' => $to,
            ]);
        } catch (\Throwable $e) {}
    }
    return ['ok' => true, 'error' => '', 'from' => $from, 'to' => $to];
}

/** Powody zamknięcia — zamknięcie „bez powodu" nie mówi nic o skuteczności. */
function crm_case_close_reasons(): array {
    return [
        'zalatwiona'   => 'Załatwiona — cel osiągnięty',
        'odrzucona'    => 'Odrzucona — odmowa / poza zakresem',
        'brak_kontaktu'=> 'Bez odpowiedzi klienta',
        'duplikat'     => 'Duplikat innej sprawy',
        'inny'         => 'Inny powód (opisany w notatce)',
    ];
}

function crm_case_status_history(int $case_id): array {
    try {
        return db_all(
            "SELECT l.*, u.name AS user_name FROM crm_case_status_log l
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.case_id=? ORDER BY l.id DESC LIMIT 100", [$case_id]
        );
    } catch (\Throwable $e) { return []; }
}

// ── Zadania sprawy ──────────────────────────────────────────────────────────

/** Zadania powiązane ze sprawą (z modułu Zadań). */
function crm_case_tasks(int $case_id): array {
    if ($case_id <= 0) return [];
    try {
        return db_all(
            "SELECT t.id, t.title, t.due_date, t.completed_at, t.priority, tl.name AS list_name
               FROM crm_case_tasks ct
               JOIN tasks t       ON t.id = ct.task_id AND t.deleted_at IS NULL
          LEFT JOIN task_lists tl ON tl.id = t.list_id
              WHERE ct.case_id=? ORDER BY t.completed_at IS NOT NULL, t.due_date, t.id", [$case_id]
        );
    } catch (\Throwable $e) { return []; }
}

/**
 * Zakłada zadanie w module Zadań i wiąże je ze sprawą.
 * Zadanie dostaje w opisie link do sprawy, żeby dało się wrócić z drugiej strony.
 */
function crm_case_add_task(int $case_id, string $title, int $list_id = 0, string $due = ''): array {
    require_once __DIR__ . '/crm_quick.php';

    $case = null;
    try { $case = db_one("SELECT id, title, contact_id, owner_id FROM crm_cases WHERE id=?", [$case_id]); }
    catch (\Throwable $e) {}
    if (!$case) return ['ok' => false, 'error' => 'Sprawa nie istnieje.'];

    $uid = function_exists('current_user') ? (int)(current_user()['id'] ?? 0) : 0;
    $r = crm_quick_create([
        'type'        => 'task',
        'title'       => $title,
        'list_id'     => $list_id,
        'contact_id'  => (int)($case['contact_id'] ?? 0),
        'description' => 'Sprawa CRM: ' . (string)$case['title'] . ' — '
                       . APP_URL . '/crm/cases/view.php?id=' . $case_id,
    ], $uid);
    if (!$r['ok']) return $r;

    try {
        db()->prepare("INSERT OR IGNORE INTO crm_case_tasks (case_id, task_id, created_at) VALUES (?,?,?)")
            ->execute([$case_id, (int)$r['id'], date('Y-m-d H:i:s')]);
        if ($due !== '') {
            db()->prepare("UPDATE tasks SET due_date=? WHERE id=?")->execute([$due, (int)$r['id']]);
        }
    } catch (\Throwable $e) {
        error_log('[crm_case_add_task] link: ' . $e->getMessage());
    }
    return $r;
}
