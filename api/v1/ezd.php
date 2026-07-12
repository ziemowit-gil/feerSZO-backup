<?php
/**
 * REST API — EZD „Wirtualne Biurko" (dokumentacja HTTP: docs/ezd/)
 * Bearer token (Authorization: Bearer <key>) lub ?api_key=<key>.
 * Uprawnienia: ezd:read (odczyt).
 *
 * Tylko do odczytu — w przeciwieństwie do crm.php/karty30.php/events.php nie ma
 * tu zapisu. Zapis (nowa koszulka/pismo, dekretacje, e-podpis, załączniki) wiąże
 * się z numeracją (znak_sprawy/sygnatura), stanem obiegu i integracjami
 * (Office Online, SharePoint) zaprojektowanymi pod sesyjny UI — nie replikujemy
 * tego przez klucz API w v1.
 *
 * Routing (jeden plik, bez rewrite — sterowanie parametrem resource):
 *   GET /api/v1/ezd.php?resource=teczki                        → lista teczek (segregatorów), filtr status, paginacja
 *   GET /api/v1/ezd.php?resource=teczki&id=N                   → pojedyncza teczka
 *   GET /api/v1/ezd.php?resource=sprawy                        → lista spraw (koszulek); filtry: status, priority,
 *                                                                  teczka_id, owner_id, q, deadline_od, deadline_do
 *   GET /api/v1/ezd.php?resource=sprawy&id=N                   → pojedyncza sprawa
 *   GET /api/v1/ezd.php?resource=pisma&sprawa_id=N             → lista pism danej sprawy, paginacja
 *   GET /api/v1/ezd.php?resource=pisma&id=N                    → pojedyncze pismo
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/ezd.php';

api_auth_migrate();

// ── Routing ────────────────────────────────────────────────────────────────
$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ($_GET['_method'] ?? '');
if ($override !== '') $method = strtoupper((string)$override);

api_require('ezd:read');
if ($method !== 'GET') api_error('Method Not Allowed — API EZD jest tylko do odczytu.', 405);

$resource = (string)($_GET['resource'] ?? '');
$id       = (int)($_GET['id'] ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
$offset   = ($page - 1) * $per_page;

function ezd_api_paged(string $count_sql, string $list_sql, array $params, int $per_page, int $offset, int $page): array {
    $total = (int)(db_one($count_sql, $params)['cnt'] ?? 0);
    $rows  = db_all($list_sql, array_merge($params, [$per_page, $offset]));
    return [$rows, [
        'total' => $total, 'page' => $page, 'per_page' => $per_page,
        'pages' => max(1, (int)ceil($total / $per_page)),
    ]];
}

// ══════════════════════════════════════════════════════════════════════════════
// TECZKI (segregatory)
// ══════════════════════════════════════════════════════════════════════════════
if ($resource === 'teczki') {
    if ($id > 0) {
        $row = ezd_teczka_get($id);
        if (!$row) api_error('Nie znaleziono teczki.', 404);
        api_json(['data' => ezd_api_teczka($row)]);
    }

    $where  = [];
    $params = [];
    if (!empty($_GET['status'])) { $where[] = 't.status = ?'; $params[] = $_GET['status']; }
    $sql_where = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    [$rows, $meta] = ezd_api_paged(
        "SELECT COUNT(*) AS cnt FROM ezd_teczki t $sql_where",
        "SELECT t.*, j.symbol AS jrwa_symbol, j.title AS jrwa_title, j.kat_arch,
                u.name AS owner_name,
                (SELECT COUNT(*) FROM ezd_sprawy s WHERE s.teczka_id=t.id AND s.status!='closed') AS open_cases,
                (SELECT COUNT(*) FROM ezd_sprawy s WHERE s.teczka_id=t.id) AS total_cases
         FROM ezd_teczki t
         LEFT JOIN ezd_jrwa j ON j.id = t.jrwa_id
         LEFT JOIN users    u ON u.id = t.owner_id
         $sql_where
         ORDER BY t.rok DESC, t.symbol
         LIMIT ? OFFSET ?",
        $params, $per_page, $offset, $page
    );

    api_json(['data' => array_map('ezd_api_teczka', $rows), 'meta' => $meta]);
}

// ══════════════════════════════════════════════════════════════════════════════
// SPRAWY (koszulki)
// ══════════════════════════════════════════════════════════════════════════════
if ($resource === 'sprawy') {
    if ($id > 0) {
        $row = ezd_sprawa_get($id);
        if (!$row) api_error('Nie znaleziono sprawy.', 404);
        api_json(['data' => ezd_api_sprawa($row)]);
    }

    $where  = ['1=1'];
    $params = [];
    if (!empty($_GET['status']))      { $where[] = 's.status = ?';     $params[] = $_GET['status']; }
    if (!empty($_GET['priority']))    { $where[] = 's.priority = ?';   $params[] = $_GET['priority']; }
    if (!empty($_GET['teczka_id']))   { $where[] = 's.teczka_id = ?';  $params[] = (int)$_GET['teczka_id']; }
    if (!empty($_GET['owner_id']))    { $where[] = 's.owner_id = ?';   $params[] = (int)$_GET['owner_id']; }
    if (!empty($_GET['deadline_od'])) { $where[] = 's.deadline >= ?';  $params[] = $_GET['deadline_od']; }
    if (!empty($_GET['deadline_do'])) { $where[] = 's.deadline <= ?';  $params[] = $_GET['deadline_do']; }
    if (!empty($_GET['q'])) {
        $where[] = '(s.title LIKE ? OR s.znak_sprawy LIKE ?)';
        $q = '%' . $_GET['q'] . '%';
        array_push($params, $q, $q);
    }
    $sql_where = 'WHERE ' . implode(' AND ', $where);

    [$rows, $meta] = ezd_api_paged(
        "SELECT COUNT(*) AS cnt FROM ezd_sprawy s $sql_where",
        "SELECT s.*, t.symbol AS teczka_symbol, t.title AS teczka_title, u.name AS owner_name
         FROM ezd_sprawy s
         JOIN ezd_teczki t ON t.id = s.teczka_id
         LEFT JOIN users u ON u.id = s.owner_id
         $sql_where
         ORDER BY s.updated_at DESC
         LIMIT ? OFFSET ?",
        $params, $per_page, $offset, $page
    );

    api_json(['data' => array_map('ezd_api_sprawa', $rows), 'meta' => $meta]);
}

// ══════════════════════════════════════════════════════════════════════════════
// PISMA
// ══════════════════════════════════════════════════════════════════════════════
if ($resource === 'pisma') {
    if ($id > 0) {
        $row = ezd_pismo_get($id);
        if (!$row) api_error('Nie znaleziono pisma.', 404);
        api_json(['data' => ezd_api_pismo($row)]);
    }

    $sprawa_id = (int)($_GET['sprawa_id'] ?? 0);
    if ($sprawa_id <= 0) api_error('Wymagany parametr sprawa_id (lub id pojedynczego pisma).', 400);
    if (!ezd_sprawa_get($sprawa_id)) api_error('Nie znaleziono sprawy.', 404);

    [$rows, $meta] = ezd_api_paged(
        "SELECT COUNT(*) AS cnt FROM ezd_pisma p WHERE p.sprawa_id = ?",
        "SELECT p.*, s.znak_sprawy, u.name AS owner_name FROM ezd_pisma p
         JOIN ezd_sprawy s ON s.id = p.sprawa_id
         LEFT JOIN users u ON u.id = p.owner_id
         WHERE p.sprawa_id = ?
         ORDER BY p.created_at DESC
         LIMIT ? OFFSET ?",
        [$sprawa_id], $per_page, $offset, $page
    );

    api_json(['data' => array_map('ezd_api_pismo', $rows), 'meta' => $meta]);
}

api_error('Nieznany zasób. Dostępne: teczki, sprawy, pisma.', 404);
