<?php
/**
 * api/search_quick.php — JSON endpoint dla live-search w topbarze.
 * Zwraca wyniki danych pasujące do ?q=: osoby i umowy z GlobalSearch (modules/global_search — PESEL, nazwisko,
 * telefon, e-mail, numer umowy; z uprawnieniami źródeł) + zgłoszenia Helpdesku.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['modules' => [], 'results' => []]);
    exit;
}

// Uwaga: pozycje menu (moduły) obsługuje paleta poleceń po stronie klienta
// na podstawie rejestru includes/menu.php (jedno źródło prawdy). Ten endpoint
// zwraca wyłącznie szybkie wyniki DANYCH; klucz `modules` pozostaje pusty dla
// zgodności wstecznej.

// ── Osoby i umowy — globalna wyszukiwarka (PESEL, nazwisko, telefon, e-mail, nr umowy) ──
require_once dirname(__DIR__) . '/modules/global_search/logic/globalSearch.php';
$global       = GlobalSearch::run($q, 5, 8, 'paleta');
$data_results = GlobalSearch::flat($global);

$like = '%' . $q . '%';

// Helpdesk
try {
    $rows = db_all(
        "SELECT id, number, title FROM helpdesk_tickets
         WHERE (number LIKE ? OR title LIKE ?) LIMIT 3",
        [$like, $like]
    );
    foreach ($rows as $r) {
        $data_results[] = [
            'label' => $r['title'],
            'sub'   => $r['number'],
            'icon'  => 'bi-headset',
            'color' => 'primary',
            'badge' => 'Helpdesk',
            'url'   => APP_URL . '/helpdesk/view.php?id=' . (int)$r['id'],
            'type'  => 'data',
            'group' => 'helpdesk',
            'groupLabel' => 'Helpdesk',
        ];
    }
} catch (\Throwable $e) {}


echo json_encode([
    'modules' => [],
    'results' => $data_results,
    'query'   => $q,
    'kind'    => $global['kind'],
    'hint'    => $global['hint'],
    'search_url' => APP_URL . '/search.php?q=' . urlencode($q),
], JSON_UNESCAPED_UNICODE);
