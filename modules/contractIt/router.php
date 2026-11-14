<?php
/**
 * modules/contractIt/router.php — „Konta i grupy IT” w formularzach umów (panel view/panel.php).
 * Adresy (Router w trybie query): router.php?_route=/stan — GET, stan konta po e-mailu i propozycje jednostki/działania (JSON).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/ContractItController.php';

$r = new Router('/modules/contractIt/router.php');
$r->group(['auth', 'role:admin,editor'], function (Router $r) {
    $r->get('/stan', [ContractItController::class, 'state'])->name('contractIt.state');
});
$r->dispatch();
