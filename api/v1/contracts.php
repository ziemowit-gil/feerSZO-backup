<?php
/**
 * REST API – Contracts (all types)
 * GET /api/v1/contracts.php
 *
 * Query params:
 *   type       – wolontariat|zlecenie|uslugi|dzielo|praca|inne|all (default: all)
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
api_require('contracts:read');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method Not Allowed', 405);
}

// ── Allowed types ────────────────────────────────────────────────────────────
const VALID_CONTRACT_TYPES = ['wolontariat', 'zlecenie', 'uslugi', 'dzielo', 'praca', 'inne'];

$type_param = strtolower(trim($_GET['type'] ?? 'all'));

if ($type_param !== 'all' && !in_array($type_param, VALID_CONTRACT_TYPES, true)) {
    api_error('Invalid type. Allowed: ' . implode(', ', VALID_CONTRACT_TYPES) . ', all');
}

$types = ($type_param === 'all') ? VALID_CONTRACT_TYPES : [$type_param];

// ── Pagination ───────────────────────────────────────────────────────────────
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
$offset   = ($page - 1) * $per_page;

// ── Per-type filters ─────────────────────────────────────────────────────────
$filter_where  = [];
$filter_params = [];

if (!empty($_GET['status'])) {
    $filter_where[]  = 'status = ?';
    $filter_params[] = $_GET['status'];
}

if (!empty($_GET['date_from'])) {
    $filter_where[]  = 'data_zawarcia >= ?';
    $filter_params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $filter_where[]  = 'data_zawarcia <= ?';
    $filter_params[] = $_GET['date_to'];
}

$sql_filter = $filter_where ? ('AND ' . implode(' AND ', $filter_where)) : '';

// ── Collect results across all requested types ────────────────────────────────
// We use UNION ALL approach per-type for portability, then apply global pagination.

$all_rows = [];
$total    = 0;

// Check which tables exist (graceful degradation if a module is missing)
$existing_tables = [];
$pdo = db();
if (defined('DB_TYPE') && DB_TYPE === 'sqlite') {
    $table_rows = db_all("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'umowy_%'");
    foreach ($table_rows as $tr) {
        $existing_tables[] = $tr['name'];
    }
} else {
    // MySQL
    $table_rows = db_all("SHOW TABLES LIKE 'umowy_%'");
    foreach ($table_rows as $tr) {
        $existing_tables[] = array_values($tr)[0];
    }
}

foreach ($types as $t) {
    $table = 'umowy_' . $t;
    if (!in_array($table, $existing_tables, true)) {
        continue;
    }

    // Count for this type
    $cnt_row = db_one(
        "SELECT COUNT(*) AS cnt FROM {$table} WHERE 1=1 $sql_filter",
        $filter_params
    );
    $total += (int)($cnt_row['cnt'] ?? 0);
}

// For pagination across types, fetch only for this page.
// We iterate types again, collecting rows with running offset tracking.
$remaining_offset = $offset;
$remaining_limit  = $per_page;

foreach ($types as $t) {
    if ($remaining_limit <= 0) break;

    $table = 'umowy_' . $t;
    if (!in_array($table, $existing_tables, true)) {
        continue;
    }

    // Count for this type (reuse from above would need caching; simpler to re-query)
    $cnt_row   = db_one(
        "SELECT COUNT(*) AS cnt FROM {$table} WHERE 1=1 $sql_filter",
        $filter_params
    );
    $type_count = (int)($cnt_row['cnt'] ?? 0);

    if ($remaining_offset >= $type_count) {
        // Skip this type entirely
        $remaining_offset -= $type_count;
        continue;
    }

    // Columns that all umowy_ tables share; request only common ones
    $rows = db_all(
        "SELECT id, numer_umowy, imie_nazwisko, email, status,
                data_zawarcia, data_zakonczenia, created_at
           FROM {$table}
          WHERE 1=1 $sql_filter
          ORDER BY id DESC
          LIMIT ? OFFSET ?",
        array_merge($filter_params, [$remaining_limit, $remaining_offset])
    );

    foreach ($rows as $r) {
        $r['id']            = (int)$r['id'];
        $r['contract_type'] = $t;
        $all_rows[]         = $r;
    }

    $fetched           = count($rows);
    $remaining_limit  -= $fetched;
    $remaining_offset  = 0; // offset fully consumed in first matching type
}

api_json([
    'data' => $all_rows,
    'meta' => [
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'pages'    => max(1, (int)ceil($total / $per_page)),
        'type'     => $type_param,
    ],
]);
