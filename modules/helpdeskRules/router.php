<?php
/**
 * modules/helpdeskRules/router.php — reguły automatyczne Helpdesku (Helpdesk › Reguły). Dostęp: administrator.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/HelpdeskRulesController.php';

$r = new Router('/modules/helpdeskRules/router.php');
$r->group(['auth', 'role:admin'], function (Router $r) {
    $r->get('/',                [HelpdeskRulesController::class, 'index'])->name('hdrules.index');
    $r->post('/',               [HelpdeskRulesController::class, 'save'])->name('hdrules.save');
    $r->post('/{id}/przelacz',  [HelpdeskRulesController::class, 'toggle'])->name('hdrules.toggle');
    $r->post('/{id}/usun',      [HelpdeskRulesController::class, 'delete'])->name('hdrules.delete');
});
$r->dispatch();
