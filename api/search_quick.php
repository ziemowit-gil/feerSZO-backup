<?php
/**
 * api/search_quick.php — JSON endpoint dla live-search w topbarze.
 * Zwraca moduły + wyniki danych pasujące do ?q=
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

$lq   = mb_strtolower($q);
$like = '%' . $q . '%';

// Uwaga: pozycje menu (moduły) obsługuje teraz paleta poleceń po stronie klienta
// na podstawie rejestru includes/menu.php (jedno źródło prawdy). Ten endpoint
// zwraca wyłącznie szybkie wyniki DANYCH; klucz `modules` pozostaje pusty dla
// zgodności wstecznej.

// ── Dane — szybkie wyniki ─────────────────────────────────────────────────────
$data_results = [];

// Wolontariusze
try {
    $rows = db_all(
        "SELECT id, imie_nazwisko, numer_umowy, email FROM umowy_wolontariat
         WHERE (imie_nazwisko LIKE ? OR numer_umowy LIKE ? OR email LIKE ?) LIMIT 4",
        [$like, $like, $like]
    );
    foreach ($rows as $r) {
        $data_results[] = [
            'label'    => $r['imie_nazwisko'],
            'sub'      => ($r['numer_umowy'] ?: '') . ($r['email'] ? ' · ' . $r['email'] : ''),
            'icon'     => 'bi-heart',
            'color'    => 'success',
            'badge'    => 'Wolontariat',
            'url'      => APP_URL . '/contracts/wolontariat/view.php?id=' . (int)$r['id'],
            'type'     => 'data',
        ];
    }
} catch (\Throwable $e) {}

// Osoby
try {
    $rows = db_all(
        "SELECT id, imie_nazwisko, email FROM persons
         WHERE imie_nazwisko LIKE ? OR email LIKE ? LIMIT 3",
        [$like, $like]
    );
    foreach ($rows as $r) {
        $data_results[] = [
            'label' => $r['imie_nazwisko'],
            'sub'   => $r['email'] ?? '',
            'icon'  => 'bi-person',
            'color' => 'info',
            'badge' => 'Osoba',
            'url'   => APP_URL . '/persons/view.php?id=' . (int)$r['id'],
            'type'  => 'data',
        ];
    }
} catch (\Throwable $e) {}

// CRM
try {
    $rows = db_all(
        "SELECT id, imie_nazwisko, email FROM crm_contacts
         WHERE imie_nazwisko LIKE ? OR email LIKE ? LIMIT 3",
        [$like, $like]
    );
    foreach ($rows as $r) {
        $data_results[] = [
            'label' => $r['imie_nazwisko'],
            'sub'   => $r['email'] ?? '',
            'icon'  => 'bi-diagram-2',
            'color' => 'success',
            'badge' => 'CRM',
            'url'   => APP_URL . '/crm/contacts/view.php?id=' . (int)$r['id'],
            'type'  => 'data',
        ];
    }
} catch (\Throwable $e) {}

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
        ];
    }
} catch (\Throwable $e) {}

// Ogranicz dane do 8
$data_results = array_slice($data_results, 0, 8);

echo json_encode([
    'modules' => [],
    'results' => $data_results,
    'query'   => $q,
    'search_url' => APP_URL . '/search.php?q=' . urlencode($q),
], JSON_UNESCAPED_UNICODE);
