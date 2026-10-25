<?php
/**
 * modules/helpdeskStatuses/router.php — własne statusy kategorii Helpdesku. Dostęp: administrator.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/HelpdeskStatusesController.php';

$r = new Router('/modules/helpdeskStatuses/router.php');
$r->group(['auth', 'role:admin'], function (Router $r) {
    $r->get('/',               [HelpdeskStatusesController::class, 'index'])->name('hdstatuses.index');
    $r->post('/',              [HelpdeskStatusesController::class, 'save'])->name('hdstatuses.save');
    $r->post('/{id}/przelacz', [HelpdeskStatusesController::class, 'toggle'])->name('hdstatuses.toggle');
});
$r->dispatch();
