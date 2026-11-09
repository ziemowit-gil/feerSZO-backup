<?php
/**
 * modules/edok_bulk/router.php — akcje zbiorcze na zaznaczonych dokumentach EODoK (pasek na /edok/index.php).
 *
 * Adresy (tryb query, bo formularze lecą z listy dokumentów): …/router.php?_route=/sign | /zip | /paid.
 * Tylko POST z CSRF; wymaga dostępu do EODoK. Uprawnienia do każdego dokumentu i etapu sprawdzają te same
 * funkcje co akcja pojedyncza (rola etapu, PIN, blokady sekwencji, prawo zmiany statusu płatności).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/EdokBulkController.php';

$r = new Router('/modules/edok_bulk/router.php', 'query');

$r->aliasMiddleware('edok', function () {
    edok_require_access();
    edok_migrate();
});

$r->group(['auth', 'edok'], function (Router $r) {
    $r->post('/sign', [EdokBulkController::class, 'sign'])->name('edokBulk.sign');
    $r->post('/zip',  [EdokBulkController::class, 'zip'])->name('edokBulk.zip');
    $r->post('/paid', [EdokBulkController::class, 'paid'])->name('edokBulk.paid');
});

$r->dispatch();
