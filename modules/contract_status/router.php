<?php
/**
 * modules/contract_status/router.php — „Status umowy (wycofanie)”: administrator ustawia dowolny status
 * albo cofa ostatnią zmianę, z pominięciem walidatorów przejść. Odpowiednik graficzny cli/contractStatus.php.
 *
 * Adresy: …/router.php (wyszukiwanie), …/router.php/{typ}/{id} (umowa), POST …/{typ}/{id} (zmiana). Tylko admin.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/ContractStatusController.php';

$r = new Router('/modules/contract_status/router.php');

$r->group(['role:admin'], function (Router $r) {
    $r->get('/',               [ContractStatusController::class, 'index'])->name('cst.index');
    $r->get('/{type}/{id}',    [ContractStatusController::class, 'show'])->name('cst.show');
    $r->post('/{type}/{id}',   [ContractStatusController::class, 'update'])->name('cst.update');
});

$r->dispatch();
