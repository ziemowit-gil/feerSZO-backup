<?php
/**
 * modules/login_sentry/router.php — LoginSentry („Staszek”): strażnik podejrzanych logowań (SZO + Logto), panel administratora.
 *
 * Adresy (tryb query): /modules/login_sentry/router.php?_route=/…
 *   /                — karty statystyk, zdarzenia z filtrem, logowania, wyjątki, ustawienia, dziennik audytu;
 *   /podglad, /przebieg, /sprawdz, /cofnij, /ustawienia, /wyjatki/osoba/dodaj|usun, /wyjatki/siec/dodaj|usun — POST.
 * Przebieg co 6 h: bin/login_sentry.php (dyspozytor CRON, klucz „login_sentry”).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once dirname(__DIR__, 2) . '/modules/audit_logs/logic/audit_logs.php';
require_once __DIR__ . '/logic/LoginSentryController.php';

use Feer\Szo\LoginSentry\LoginSentryController as C;

$r = new Router('/modules/login_sentry/router.php');

$r->group(['auth', 'role:admin'], function (Router $r) {
    $r->get('/',                      [C::class, 'index'])->name('login_sentry.index');
    $r->post('/ustawienia',           [C::class, 'saveSettings'])->name('login_sentry.settings');
    $r->post('/podglad',              [C::class, 'preview'])->name('login_sentry.preview');
    $r->post('/przebieg',             [C::class, 'runNow'])->name('login_sentry.run');
    $r->post('/sprawdz',              [C::class, 'check'])->name('login_sentry.check');
    $r->post('/cofnij',               [C::class, 'revert'])->name('login_sentry.revert');
    $r->post('/wyjatki/osoba/dodaj',  [C::class, 'addUserExemption'])->name('login_sentry.userExAdd');
    $r->post('/wyjatki/osoba/usun',   [C::class, 'removeUserExemption'])->name('login_sentry.userExRemove');
    $r->post('/wyjatki/siec/dodaj',   [C::class, 'addNetExemption'])->name('login_sentry.netExAdd');
    $r->post('/wyjatki/siec/usun',    [C::class, 'removeNetExemption'])->name('login_sentry.netExRemove');
});

$r->dispatch();
