<?php
/**
 * crm/api/teryt.php — Kaskadowe dane terytorialne (TERYT GUS).
 * GET ?action=powiaty&woj=14          → lista powiatów
 * GET ?action=gminy&pow=1401          → lista gmin
 * GET ?action=search&q=krakow&level=3 → wyszukiwarka
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

if ($action === 'powiaty') {
    $woj = preg_replace('/\D/', '', $_GET['woj'] ?? '');
    if (!$woj) { echo json_encode([]); exit; }
    // Pad do 2 cyfr
    $woj = str_pad($woj, 2, '0', STR_PAD_LEFT);
    $rows = db_all(
        "SELECT kod_pow, nazwa, nazwa_typ FROM teryt_units
         WHERE level=2 AND kod_woj=? ORDER BY nazwa",
        [$woj]
    );
    echo json_encode(array_map(fn($r) => [
        'id'    => $r['kod_pow'],
        'label' => $r['nazwa'] . ($r['nazwa_typ'] ? ' (' . $r['nazwa_typ'] . ')' : ''),
        'name'  => $r['nazwa'],
    ], $rows), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'gminy') {
    $pow = preg_replace('/\D/', '', $_GET['pow'] ?? '');
    if (!$pow) { echo json_encode([]); exit; }
    $pow = str_pad($pow, 4, '0', STR_PAD_LEFT);
    $rows = db_all(
        "SELECT kod_gmi, nazwa, nazwa_typ FROM teryt_units
         WHERE level=3 AND kod_pow=? ORDER BY nazwa",
        [$pow]
    );
    echo json_encode(array_map(fn($r) => [
        'id'    => $r['kod_gmi'],
        'label' => $r['nazwa'] . ($r['nazwa_typ'] ? ' (' . $r['nazwa_typ'] . ')' : ''),
        'name'  => $r['nazwa'],
    ], $rows), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'search') {
    $q     = trim($_GET['q'] ?? '');
    $level = (int)($_GET['level'] ?? 3);
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    $rows = db_all(
        "SELECT kod_gmi, kod_pow, kod_woj, level, nazwa, nazwa_typ
         FROM teryt_units WHERE level=? AND nazwa LIKE ? ORDER BY nazwa LIMIT 30",
        [$level, '%' . $q . '%']
    );
    echo json_encode(array_map(fn($r) => [
        'id'    => $r['kod_gmi'] ?? $r['kod_pow'] ?? $r['kod_woj'],
        'kod'   => $r['kod_gmi'] ?? $r['kod_pow'] ?? $r['kod_woj'],
        'label' => $r['nazwa'] . ($r['nazwa_typ'] ? ' (' . $r['nazwa_typ'] . ')' : ''),
        'name'  => $r['nazwa'],
        'level' => $r['level'],
    ], $rows), JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Nieznana akcja']);
