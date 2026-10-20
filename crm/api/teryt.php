<?php
/**
 * crm/api/teryt.php — Kaskadowe dane terytorialne (TERYT GUS) ze Słownika Centralnego (includes/teryt.php).
 * GET ?action=powiaty&woj=14          → lista powiatów
 * GET ?action=gminy&pow=1401          → lista gmin
 * GET ?action=search&q=krakow&level=3 → wyszukiwarka
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/teryt.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$row = fn(array $u) => ['id' => $u['kod'], 'kod' => $u['kod'], 'label' => $u['nazwa'] . ($u['typ'] !== '' ? ' (' . $u['typ'] . ')' : ''), 'name' => $u['nazwa'], 'level' => $u['poziom']];

if ($action === 'powiaty') {
    $woj = preg_replace('/\D/', '', $_GET['woj'] ?? '');
    if (!$woj) { echo json_encode([]); exit; }
    echo json_encode(array_map($row, terytChildren(str_pad($woj, 2, '0', STR_PAD_LEFT))), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'gminy') {
    $pow = preg_replace('/\D/', '', $_GET['pow'] ?? '');
    if (!$pow) { echo json_encode([]); exit; }
    echo json_encode(array_map($row, terytChildren(str_pad($pow, 4, '0', STR_PAD_LEFT))), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'search') {
    $level = max(1, min(3, (int)($_GET['level'] ?? 3)));
    echo json_encode(array_map($row, terytSearch((string)($_GET['q'] ?? ''), $level, 30)), JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Nieznana akcja']);
