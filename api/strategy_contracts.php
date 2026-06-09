<?php
/**
 * api/strategy_contracts.php — Endpoint dla dynamicznego ładowania umów
 * w formularzu powiązań celu strategicznego.
 *
 * Params:
 *   ?type=wolontariat|zlecenie|dzielo|uslugi|praca|inne
 *
 * Returns: {"items": [{"id": 1, "label": "UMW/0001/2025"}]}
 */
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Wymaga zalogowania
if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
    exit;
}

$type = trim($_GET['type'] ?? '');
$valid_types = ['wolontariat','zlecenie','dzielo','uslugi','praca','inne'];

if (!in_array($type, $valid_types, true)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid type', 'items' => []]);
    exit;
}

$table = 'umowy_' . $type;
$items = [];

try {
    $rows = db_all(
        "SELECT id, numer_umowy, status, data_zakonczenia FROM {$table}
         WHERE status NOT IN ('anulowana') ORDER BY id DESC LIMIT 300"
    );
    foreach ($rows as $r) {
        $label = $r['numer_umowy'] ?: ('#' . $r['id']);
        $label .= ' [' . ($r['status'] ?: '—') . ']';
        if ($r['data_zakonczenia']) {
            $label .= ' do ' . date('d.m.Y', strtotime($r['data_zakonczenia']));
        }
        $items[] = ['id' => (int)$r['id'], 'label' => $label];
    }
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => 'DB error', 'items' => []]);
    exit;
}

echo json_encode(['ok' => true, 'items' => $items]);
