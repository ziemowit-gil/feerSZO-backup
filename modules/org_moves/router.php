<?php
/**
 * modules/org_moves/router.php — jedyne wejście modułu Przenoszenie w strukturze.
 *
 * Adresy: /modules/org_moves/router.php (lista osób i działów), …/router.php/osoby (POST, przeniesienie
 * osób), …/router.php/dzialy (POST, przeniesienie działów). Dostęp: administrator i zarząd.
 * Wejścia z Katalogu: ?unit=ID (osoby z jednostki), ?user=ID (jedna osoba), #dzialy.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/OrgMovesController.php';

$r = new Router('/modules/org_moves/router.php');
$r->aliasMiddleware('zarzad', function () {
    require_login();
    if (!orgMovesCan()) { http_response_code(403); echo 'Przenoszenie w strukturze jest dostępne dla administratora i zarządu.'; exit; }
});

$r->group(['zarzad'], function (Router $r) {
    $r->get('/',        [OrgMovesController::class, 'index'])->name('moves.index');
    $r->post('/osoby',  [OrgMovesController::class, 'moveMembers'])->name('moves.members');
    $r->post('/dzialy', [OrgMovesController::class, 'moveUnits'])->name('moves.units');
});

$r->dispatch();
