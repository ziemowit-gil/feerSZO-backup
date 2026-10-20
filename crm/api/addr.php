<?php
/**
 * crm/api/addr.php — Autocomplete adresu: miasto (TERYT) + kody pocztowe.
 *
 * GET ?action=city&q=...              → lista miast z TERYT (gminy — Słownik Centralny, includes/teryt.php)
 * GET ?action=postal&q=XX            → lista kodów pocztowych z tabeli postal_codes
 * GET ?action=postal_city&code=XX-XXX → miasta przypisane do kodu (do auto-fill)
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/teryt.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: max-age=120, private');

$action = $_GET['action'] ?? '';

// ── Szukaj miasta (gminy TERYT ze Słownika Centralnego) ─────────────────────
if ($action === 'city') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    // najpierw „zaczyna się od”, potem „zawiera” (terytSearch); bez powtórzeń nazw (części gmin miejsko-wiejskich)
    $out = array_values(array_unique(array_column(terytSearch($q, 3, 60), 'nazwa')));
    echo json_encode(array_slice($out, 0, 20), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Szukaj kodów pocztowych ──────────────────────────────────────────────────
if ($action === 'postal') {
    $q = preg_replace('/[^0-9\-]/', '', trim($_GET['q'] ?? ''));
    if (strlen($q) < 2 || !_postal_table_exists()) { echo json_encode([]); exit; }

    $rows = db_all(
        "SELECT DISTINCT code, city FROM postal_codes WHERE code LIKE ? ORDER BY code LIMIT 20",
        [$q . '%']
    );
    echo json_encode(array_map(fn($r) => [
        'code' => $r['code'],
        'city' => $r['city'],
    ], $rows), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Kod pocztowy → lista miast (do auto-fill pola Miasto) ───────────────────
if ($action === 'postal_city') {
    $code = preg_replace('/[^0-9\-]/', '', trim($_GET['code'] ?? ''));
    if (!$code || !_postal_table_exists()) {
        echo json_encode(['cities' => []]); exit;
    }
    $rows = db_all(
        "SELECT DISTINCT city FROM postal_codes WHERE code = ? ORDER BY city LIMIT 10",
        [$code]
    );
    echo json_encode(['cities' => array_column($rows, 'city')], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Dane TERYT dla nazwy miejscowości (gminy level=3) ───────────────────────
if ($action === 'city_info') {
    $name = trim($_GET['name'] ?? '');
    if (!$name) { echo json_encode(null); exit; }
    echo json_encode(_teryt_details('nazwa', $name), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Dane TERYT dla kodu gminy (kod_gmi) ──────────────────────────────────────
if ($action === 'teryt_info') {
    $code = preg_replace('/\D/', '', trim($_GET['code'] ?? ''));
    if (!$code) { echo json_encode(null); exit; }
    echo json_encode(_teryt_details('kod_gmi', $code), JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Nieznana akcja'], JSON_UNESCAPED_UNICODE);

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Pełne dane TERYT gminy (Słownik Centralny) po nazwie albo kodzie. */
function _teryt_details(string $col, string $val): ?array {
    if (!in_array($col, ['nazwa', 'kod_gmi'], true)) return null;
    return terytGmina($col === 'kod_gmi' ? 'kod' : 'nazwa', $val);
}

function _postal_table_exists(): bool {
    static $v = null;
    if ($v !== null) return $v;
    $r = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='postal_codes'")->fetch();
    return $v = (bool)$r;
}
