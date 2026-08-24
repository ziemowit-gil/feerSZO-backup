<?php
/**
 * crm/api/quick_create.php — szybkie tworzenie z paska CRM i z pływającego widżetu.
 *
 * Logika tworzenia siedzi w includes/crm_quick.php, bo korzystają z niej też
 * przepływy (includes/crm_workflows.php).
 *
 * POST JSON {_csrf, type: contact|note|case|task, title, contact_id?, email?, list_id?}
 * → {ok, url, message, error}
 * GET  ?a=task_lists → listy zadań dostępne użytkownikowi
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_quick.php';

header('Content-Type: application/json; charset=utf-8');

function qc_out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (!current_user()) {
    http_response_code(401);
    qc_out(['ok' => false, 'error' => 'Wymagane logowanie.']);
}

require_once dirname(dirname(__DIR__)) . '/includes/crm_perms.php';
crm_require_json('contacts', 'write');
$uid = (int)(current_user()['id'] ?? 0);

// Zadania CRM nie mają list ani obszarów (to nie moduł Zadań) — do wyboru jest
// osoba, która ma je zrobić. Zob. includes/crm_tasks.php.
if (($_GET['a'] ?? '') === 'task_people') {
    require_once dirname(dirname(__DIR__)) . '/includes/crm_owner_rules.php';
    $people = array_values(array_filter(crm_owner_candidates(),
        static fn($u) => (int)$u['id'] !== $uid));
    qc_out(['ok' => true, 'items' => array_map(
        static fn($u) => ['id' => (int)$u['id'], 'name' => (string)$u['name']], $people)]);
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

$r = crm_quick_create($in, $uid);
qc_out([
    'ok'      => $r['ok'],
    'url'     => $r['url'],
    'message' => $r['ok'] ? $r['label'] . ' zapisane.' : '',
    'error'   => $r['error'],
]);
