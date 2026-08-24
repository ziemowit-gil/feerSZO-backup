<?php
/**
 * crm/api/workflows.php — przepływy dla widżetu szybkich akcji.
 *
 * GET  ?a=list          → {ok, items:[{id,name,description,icon,steps:[{type,label,title}]}]}
 * POST JSON {_csrf, id, subject, contact_id?, owner_id?} → uruchomienie przepływu
 *   → {ok, url, results:[{label,title,ok,error,url}], error}
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_workflows.php';

header('Content-Type: application/json; charset=utf-8');

function wf_out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (!current_user()) {
    http_response_code(401);
    wf_out(['ok' => false, 'error' => 'Wymagane logowanie.']);
}

require_once dirname(dirname(__DIR__)) . '/includes/crm_perms.php';
crm_require_json('contacts', 'write');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $types = crm_quick_types();
    $items = [];
    foreach (crm_workflows_for() as $wf) {
        $steps = array_map(static fn($s) => [
            'type'  => $s['type'],
            'label' => $types[$s['type']]['label'] ?? $s['type'],
            'title' => $s['title'],
        ], crm_workflow_steps($wf));
        if (!$steps) continue;
        $items[] = [
            'id'          => (int)$wf['id'],
            'name'        => (string)$wf['name'],
            'description' => (string)$wf['description'],
            'icon'        => (string)$wf['icon'],
            'shared'      => empty($wf['owner_id']),
            'steps'       => $steps,
            // Przepływ z krokiem wymagającym kartoteki musi dostać kontakt
            'needs_contact' => (bool)array_filter($steps, static fn($s) => in_array($s['type'], ['note', 'case'], true))
                               && !array_filter($steps, static fn($s) => $s['type'] === 'contact'),
        ];
    }
    wf_out(['ok' => true, 'items' => $items]);
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (($in['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(403);
    wf_out(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.']);
}

$r = crm_workflow_run((int)($in['id'] ?? 0), [
    'subject'    => (string)($in['subject'] ?? ''),
    'contact_id' => (int)($in['contact_id'] ?? 0),
    'owner_id'   => (int)($in['owner_id'] ?? 0),
]);
wf_out($r);
