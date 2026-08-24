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
