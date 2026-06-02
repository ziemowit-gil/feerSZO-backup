<?php
/**
 * AJAX — lista Security Groups z M365.
 * Zwraca JSON: [{"id":"...","displayName":"..."}, ...]
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/m365.php';

require_role('admin', 'editor');
header('Content-Type: application/json; charset=utf-8');

try {
    $m365   = new M365Graph();
    if (!$m365->is_configured()) {
        echo json_encode(['error' => 'M365 nie jest skonfigurowane.']);
        exit;
    }
    $groups = $m365->get_security_groups();
    echo json_encode($groups);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
