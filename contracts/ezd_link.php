<?php
/**
 * AJAX handler: powiązanie umowy ↔ koszulka EZD.
 *
 * GET  ?type=zlecenie&id=42&action=search&q=UMW   → JSON {results:[...]}
 * POST action=create   → tworzy nową koszulkę i powiązuje
 * POST action=link     → powiązuje z istniejącą (sprawa_id w body)
 * POST action=unlink   → odwiązuje
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/contract_ezd.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth ───────────────────────────────────────────────────────────────────────
$USER = current_user();
if (!$USER) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Brak sesji.']);
    exit;
}

if (!module_enabled('ezd_enabled')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Moduł EZD jest wyłączony.']);
    exit;
}

// ── Parametry ──────────────────────────────────────────────────────────────────
$type = (string)($_GET['type'] ?? '');
$id   = (int)($_GET['id']   ?? 0);

if (!$type || !$id || !array_key_exists($type, CONTRACT_TYPES)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nieprawidłowe parametry.']);
    exit;
}

// Weryfikacja dostępu do umowy
$tbl = table_for_type($type);
$row = db_one("SELECT * FROM {$tbl} WHERE id=?", [$id]);
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Umowa nie istnieje.']);
    exit;
}

// Uprawnienia: tylko admin/editor/manager mogą edytować
$can_write = in_array($USER['role'] ?? '', ['admin','editor','manager'], true);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── GET: wyszukaj koszulki ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'search') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }
    $like = '%' . $q . '%';
    $rows = db_all(
        "SELECT s.id, s.znak_sprawy, s.title, s.status,
                t.symbol AS teczka_symbol, t.title AS teczka_title
         FROM ezd_sprawy s
         JOIN ezd_teczki t ON t.id = s.teczka_id
         WHERE (s.znak_sprawy LIKE ? OR s.title LIKE ?)
           AND s.status = 'open'
         ORDER BY s.id DESC
         LIMIT 30",
        [$like, $like]
    );
    echo json_encode(['results' => $rows]);
    exit;
}

// ── POST: operacje zapisu ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metoda niedozwolona.']);
    exit;
}

if (!$can_write) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Brak uprawnień.']);
    exit;
}

if (!verify_csrf_token($_POST['_token'] ?? '', false)) {
    // Toleruj brak CSRF-tokena dla wewnętrznych wywołań JS (nie sensu form)
    // Zamiast tego sprawdzamy nagłówek XHR
    $is_xhr = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
           || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if (!$is_xhr) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Błąd CSRF.']);
        exit;
    }
}

try {
    switch ($action) {
        case 'create':
            if (!function_exists('ezd_sprawa_create')) require_once __DIR__ . '/../includes/ezd.php';
            $sprawa_id = contract_ezd_create_and_link($type, $id, (int)$USER['id'], $row);
            echo json_encode(['ok' => true, 'sprawa_id' => $sprawa_id]);
            break;

        case 'link':
            $sprawa_id = (int)($_POST['sprawa_id'] ?? 0);
            if (!$sprawa_id) throw new \RuntimeException('Brak sprawa_id.');
            if (!function_exists('ezd_sprawa_get')) require_once __DIR__ . '/../includes/ezd.php';
            $sprawa = ezd_sprawa_get($sprawa_id);
            if (!$sprawa) throw new \RuntimeException('Koszulka nie istnieje.');
            contract_ezd_link($type, $id, $sprawa_id, (int)$USER['id']);
            echo json_encode(['ok' => true, 'sprawa_id' => $sprawa_id]);
            break;

        case 'unlink':
            contract_ezd_link($type, $id, null, (int)$USER['id']);
            echo json_encode(['ok' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Nieznana akcja.']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
