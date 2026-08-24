<?php
/**
 * crm/api/quick_create.php — szybkie tworzenie z paska CRM.
 *
 * Jedno okno, cztery rodzaje wpisów. Zakładamy minimum (nazwa/tytuł, ewentualnie
 * kontakt), a resztę użytkownik uzupełnia już na karcie — chodzi o to, żeby
 * pomysł „trzeba zadzwonić do X" dało się zapisać w kilka sekund.
 *
 * POST JSON {_csrf, type: contact|note|case|task, title, contact_id?, email?, list_id?}
 * → {ok, url, message, error}
 *
 * GET  ?a=task_lists → listy zadań dostępne użytkownikowi (do wyboru w oknie)
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

header('Content-Type: application/json; charset=utf-8');

function qc_out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (!current_user()) {
    http_response_code(401);
    qc_out(['ok' => false, 'error' => 'Wymagane logowanie.']);
}
$uid = (int)(current_user()['id'] ?? 0);

// ── Listy zadań do wyboru ──────────────────────────────────────────────────
if (($_GET['a'] ?? '') === 'task_lists') {
    $out = [];
    try {
        require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';
        foreach (task_user_workspaces($uid) as $ws) {
            $lists = db_all("SELECT id, name FROM task_lists WHERE workspace_id=? ORDER BY position, id",
                [(int)$ws['id']]);
            foreach ($lists as $l) {
                $out[] = [
                    'list_id'      => (int)$l['id'],
                    'workspace_id' => (int)$ws['id'],
                    'label'        => (string)$ws['name'] . ' · ' . (string)$l['name'],
                ];
            }
        }
    } catch (\Throwable $e) {}
    qc_out(['ok' => true, 'items' => $out]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    qc_out(['ok' => false, 'error' => 'Tylko POST.']);
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (($in['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(403);
    qc_out(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.']);
}

$type       = (string)($in['type'] ?? '');
$title      = trim((string)($in['title'] ?? ''));
$contact_id = (int)($in['contact_id'] ?? 0);
$crm_write  = can_write('crm') || is_admin();

if ($title === '') qc_out(['ok' => false, 'error' => 'Wpisz treść — bez tego nie ma czego zapisać.']);

try {
    switch ($type) {
        // ── Kontakt ────────────────────────────────────────────────────────
        case 'contact':
            if (!$crm_write) qc_out(['ok' => false, 'error' => 'Brak uprawnień do CRM.']);
            crm_migrate();
            $email = trim((string)($in['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                qc_out(['ok' => false, 'error' => 'Adres e-mail jest niepoprawny.']);
            }
            $id = db_insert('crm_contacts', [
                'type'            => 'osoba',
                'status'          => 'nowy',
                'imie_nazwisko'   => mb_substr($title, 0, 200),
                'email'           => $email ?: null,
                'avatar_initials' => CrmManager::makeInitials($title),
                'created_by'      => $uid ?: null,
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
            qc_out(['ok' => true, 'message' => 'Kontakt dodany.',
                    'url' => APP_URL . '/crm/contact/view.php?id=' . (int)$id]);

        // ── Notatka przy kontakcie ─────────────────────────────────────────
        case 'note':
            if (!$crm_write) qc_out(['ok' => false, 'error' => 'Brak uprawnień do CRM.']);
            if ($contact_id <= 0) qc_out(['ok' => false, 'error' => 'Wskaż kontakt, przy którym zapisać notatkę.']);
            if (!crm_can_access_contact($contact_id)) qc_out(['ok' => false, 'error' => 'Brak dostępu do kontaktu.']);
            db_insert('crm_notes', [
                'contact_id' => $contact_id,
                'body'       => $title,
                'created_by' => $uid ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            qc_out(['ok' => true, 'message' => 'Notatka zapisana.',
                    'url' => APP_URL . '/crm/contact/view.php?id=' . $contact_id]);

        // ── Sprawa CRM ─────────────────────────────────────────────────────
        case 'case':
            if (!$crm_write) qc_out(['ok' => false, 'error' => 'Brak uprawnień do CRM.']);
            if ($contact_id <= 0) qc_out(['ok' => false, 'error' => 'Sprawa musi być przy kontakcie — wskaż go.']);
            if (!crm_can_access_contact($contact_id)) qc_out(['ok' => false, 'error' => 'Brak dostępu do kontaktu.']);
            $id = db_insert('crm_cases', [
                'contact_id' => $contact_id,
                'title'      => mb_substr($title, 0, 200),
                'status'     => 'open',
                'priority'   => 'medium',
                'created_by' => $uid ?: null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            qc_out(['ok' => true, 'message' => 'Sprawa założona.',
                    'url' => APP_URL . '/crm/cases/view.php?id=' . (int)$id]);

        // ── Zadanie ────────────────────────────────────────────────────────
        case 'task':
            require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';
            if (!can_write('tasks') && !is_admin()) qc_out(['ok' => false, 'error' => 'Brak uprawnień do zadań.']);

            $list_id = (int)($in['list_id'] ?? 0);
            $list = null;
            if ($list_id > 0) {
                $list = db_one("SELECT id, workspace_id FROM task_lists WHERE id=?", [$list_id]);
            }
            if (!$list) {   // pierwsza lista pierwszego obszaru użytkownika
                foreach (task_user_workspaces($uid) as $ws) {
                    $l = db_one("SELECT id, workspace_id FROM task_lists WHERE workspace_id=? ORDER BY position, id LIMIT 1",
                        [(int)$ws['id']]);
                    if ($l) { $list = $l; break; }
                }
            }
            if (!$list) qc_out(['ok' => false, 'error' => 'Nie masz żadnej listy zadań — załóż obszar w module Zadań.']);

            task_require_workspace_access((int)$list['workspace_id'], ['admin', 'editor']);

            $pos = (int)(db_one("SELECT COALESCE(MAX(position),0)+1 AS p FROM tasks WHERE list_id=?",
                [(int)$list['id']])['p'] ?? 1);
            $id = db_insert('tasks', [
                'workspace_id' => (int)$list['workspace_id'],
                'list_id'      => (int)$list['id'],
                'title'        => mb_substr($title, 0, 300),
                'position'     => $pos,
                'priority'     => 'normal',
                'created_by'   => $uid,
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
            qc_out(['ok' => true, 'message' => 'Zadanie dodane.',
                    'url' => APP_URL . '/tasks/detail.php?id=' . (int)$id]);

        default:
            qc_out(['ok' => false, 'error' => 'Nieznany rodzaj wpisu.']);
    }
} catch (\Throwable $e) {
    error_log('[quick_create] ' . $e->getMessage());
    qc_out(['ok' => false, 'error' => 'Nie udało się zapisać: ' . mb_substr($e->getMessage(), 0, 160)]);
}
