<?php
/**
 * crm/api/addr.php — Autocomplete adresu: miasto (TERYT) + kody pocztowe.
 *
 * GET ?action=city&q=...              → lista miast z TERYT (gminy level=3)
 * GET ?action=postal&q=XX            → lista kodów pocztowych z tabeli postal_codes
 * GET ?action=postal_city&code=XX-XXX → miasta przypisane do kodu (do auto-fill)
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: max-age=120, private');

$action = $_GET['action'] ?? '';

// ── Szukaj miasta (gminy TERYT, level=3) ────────────────────────────────────
if ($action === 'city') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }

    // Najpierw: zaczyna od frazy — wyższy priorytet
    $starts = db_all(
        "SELECT DISTINCT nazwa FROM teryt_units
         WHERE level = 3 AND nazwa LIKE ? ORDER BY nazwa LIMIT 15",
        [$q . '%']
    );
    $out = array_column($starts, 'nazwa');

    // Uzupełnij do 20 wyników: zawiera frazę (nie zaczyna)
    if (count($out) < 20) {
        $rest = db_all(
            "SELECT DISTINCT nazwa FROM teryt_units
             WHERE level = 3 AND nazwa LIKE ? AND nazwa NOT LIKE ?
             ORDER BY nazwa LIMIT " . (20 - count($out)),
            ['%' . $q . '%', $q . '%']
        );
        $out = array_merge($out, array_column($rest, 'nazwa'));
    }

    echo json_encode(array_values(array_unique($out)), JSON_UNESCAPED_UNICODE);
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

/** Pobiera pełne dane TERYT dla gminy (level=3) przez dowolną kolumnę. */
function _teryt_details(string $col, string $val): ?array {
    $allowed = ['nazwa', 'kod_gmi'];
    if (!in_array($col, $allowed, true)) return null;

    $gmi = db_one("SELECT nazwa, kod_gmi, kod_pow, kod_woj, nazwa_typ
                   FROM teryt_units WHERE level=3 AND {$col}=? LIMIT 1", [$val]);
    if (!$gmi) return null;

    // Osobne zapytania — bezpieczniejsze niż trójkowy self-join na SQLite
    $pow = db_one("SELECT nazwa FROM teryt_units
                   WHERE level=2 AND kod_pow=? AND kod_woj=? LIMIT 1",
                  [$gmi['kod_pow'], $gmi['kod_woj']]);
    $woj = db_one("SELECT nazwa FROM teryt_units
                   WHERE level=1 AND kod_woj=? LIMIT 1",
                  [$gmi['kod_woj']]);

    return [
        'gmina'   => $gmi['nazwa'],
        'kod_gmi' => $gmi['kod_gmi'],
        'kod_pow' => $gmi['kod_pow'],
        'kod_woj' => $gmi['kod_woj'],
        'powiat'  => $pow['nazwa'] ?? null,
        'woj'     => $woj['nazwa'] ?? null,
    ];
}

function _postal_table_exists(): bool {
    static $v = null;
    if ($v !== null) return $v;
    $r = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='postal_codes'")->fetch();
    return $v = (bool)$r;
}
