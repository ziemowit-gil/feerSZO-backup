<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/grants.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

$events = [];
$rows = db_all("SELECT a.*, u.name AS koordynator_name FROM actions a LEFT JOIN users u ON u.id = a.koordynator_id WHERE a.status != 'anulowane'");

$status_colors = [
    'planowane'       => '#6c757d',
    'w_przygotowaniu' => '#0d6efd',
    'w_trakcie'       => '#198754',
    'zawieszone'      => '#ffc107',
    'zakończone'      => '#adb5bd',
    'anulowane'       => '#dc3545',
];

foreach ($rows as $r) {
    $end = null;
    if ($r['data_do']) {
        $end = date('Y-m-d', strtotime($r['data_do'] . ' +1 day'));
    }
    $events[] = [
        'id'    => $r['id'],
        'title' => $r['nazwa'],
        'start' => $r['data_od'],
        'end'   => $end,
        'color' => $status_colors[$r['status']] ?? '#6c757d',
        'extendedProps' => [
            'koordynator' => $r['koordynator_name'],
            'status'      => $r['status'],
        ],
    ];
}

echo json_encode($events);
