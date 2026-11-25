<?php
/**
 * modules/kontoBezosobowe/router.php — konto bez umowy, bezosobowe (skrzynka boXXXX@domena, hasło 20 znaków, PDF).
 *
 *   /kontabezosobowe            — lista kont i formularz (administrator + IKA);
 *   /kontabezosobowe/zaloz      — POST: zakłada skrzynkę;
 *   /kontabezosobowe/haslo      — POST: nowe hasło;
 *   /kontabezosobowe/pdf        — GET: PDF z danymi logowania (15 min po nadaniu hasła).
 * Bez przepisywania: /modules/kontoBezosobowe/router.php?_route=/kontabezosobowe. CSRF dla POST sprawdza Router.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/KontoBezosoboweController.php';

$r = new Router('/modules/kontoBezosobowe/router.php', 'path', rtrim(APP_URL, '/'));

$r->group(['auth', 'role:admin'], function (Router $r) {
    $r->get('/kontabezosobowe',        [KontoBezosoboweController::class, 'index'])->name('kbo.index');
    $r->post('/kontabezosobowe/zaloz', [KontoBezosoboweController::class, 'create'])->name('kbo.create');
    $r->post('/kontabezosobowe/haslo', [KontoBezosoboweController::class, 'password'])->name('kbo.password');
    $r->get('/kontabezosobowe/pdf',    [KontoBezosoboweController::class, 'pdf'])->name('kbo.pdf');
});

$r->dispatch();
