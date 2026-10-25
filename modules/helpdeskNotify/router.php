<?php
/**
 * modules/helpdeskNotify/router.php — powiadomienia operatora Helpdesku. Dostęp: operatorzy i administrator.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/helpdesk.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/HelpdeskNotifyController.php';

$r = new Router('/modules/helpdeskNotify/router.php');
$r->aliasMiddleware('hd.operator', function (): void {
    require_login();
    if (hd_is_operator()) return;
    http_response_code(403);
    require_once dirname(__DIR__, 2) . '/includes/header.php';
    echo '<div class="alert alert-danger m-4">Ustawienia powiadomień Helpdesku są dostępne dla operatorów i administratora.</div>';
    require_once dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
});
$r->group(['hd.operator'], function (Router $r) {
    $r->get('/',  [HelpdeskNotifyController::class, 'index'])->name('hdnotify.index');
    $r->post('/', [HelpdeskNotifyController::class, 'save'])->name('hdnotify.save');
});
$r->dispatch();
