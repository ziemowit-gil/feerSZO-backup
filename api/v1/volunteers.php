<?php
/**
 * REST API – Volunteers (umowy_wolontariat)
 * GET /api/v1/volunteers.php
 *
 * Query params:
 *   status     – filter by status
 *   date_from  – filter data_zawarcia >= (YYYY-MM-DD)
 *   date_to    – filter data_zawarcia <= (YYYY-MM-DD)
 *   page       – page number (default 1)
 *   per_page   – results per page (default 50, max 100)
 *   api_key    – alternative to Authorization header
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';

api_auth_migrate();
api_require('volunteers:read');

// Only GET is supported
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method Not Allowed', 405);
}

// ── Pagination ───────────────────────────────────────────────────────────────
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
$offset   = ($page - 1) * $per_page;

// ── Filters ──────────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if (!empty($_GET['status'])) {
    $where[]  = 'status = ?';
    $params[] = $_GET['status'];
}

if (!empty($_GET['date_from'])) {
    $where[]  = 'data_zawarcia >= ?';
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where[]  = 'data_zawarcia <= ?';
    $params[] = $_GET['date_to'];
}

$sql_where = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── Count ────────────────────────────────────────────────────────────────────
$count_row = db_one(
    "SELECT COUNT(*) AS cnt FROM umowy_wolontariat $sql_where",
    $params
);
$total = (int)($count_row['cnt'] ?? 0);

// ── Data ─────────────────────────────────────────────────────────────────────
$rows = db_all(
    "SELECT id, numer_umowy, imie_nazwisko, email, status,
            data_zawarcia, data_zakonczenia, bezterminowa, m365_login, created_at
       FROM umowy_wolontariat
      $sql_where
      ORDER BY id DESC
      LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $offset])
);

// Cast types
foreach ($rows as &$r) {
    $r['id']          = (int)$r['id'];
    $r['bezterminowa'] = (bool)$r['bezterminowa'];
}
unset($r);

api_json([
    'data' => $rows,
    'meta' => [
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'pages'    => max(1, (int)ceil($total / $per_page)),
    ],
]);
