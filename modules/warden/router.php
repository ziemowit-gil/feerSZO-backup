<?php
/**
 * modules/warden/router.php — AccountWarden („Mietek”): strażnik nieaktywnych kont w Logto i SZO, panel administratora.
 *
 * Adresy (tryb query): /modules/warden/router.php?_route=/…
 *   /                — stan, otwarte cykle, zablokowane konta, wyjątki, ustawienia, historia;
 *   /uruchom         — POST „Uruchom AccountWarden”: tryb na żywo od jutra + ogłoszenie do wszystkich aktywnych kont;
 *   /zatrzymaj, /wznow, /przebieg, /podglad, /ustawienia, /wyjatki/dodaj, /wyjatki/usun, /odblokuj — POST.
 * Codzienny przebieg: cron/agents/warden.php (dyspozytor CRON, klucz „warden”).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once dirname(__DIR__, 2) . '/modules/audit_logs/logic/audit_logs.php';
require_once __DIR__ . '/logic/WardenController.php';

use Feer\Szo\Warden\WardenController;

$r = new Router('/modules/warden/router.php');

$r->group(['auth', 'role:admin'], function (Router $r) {
    $r->get('/',               [WardenController::class, 'index'])->name('warden.index');
    $r->post('/ustawienia',    [WardenController::class, 'saveSettings'])->name('warden.settings');
    $r->post('/podglad',       [WardenController::class, 'preview'])->name('warden.preview');
    $r->post('/uruchom',       [WardenController::class, 'launch'])->name('warden.launch');
    $r->post('/zatrzymaj',     [WardenController::class, 'stop'])->name('warden.stop');
    $r->post('/wznow',         [WardenController::class, 'resume'])->name('warden.resume');
    $r->post('/przebieg',      [WardenController::class, 'runNow'])->name('warden.run');
    $r->post('/wyjatki/dodaj', [WardenController::class, 'addExemption'])->name('warden.exemptAdd');
    $r->post('/wyjatki/usun',  [WardenController::class, 'removeExemption'])->name('warden.exemptRemove');
    $r->post('/odblokuj',      [WardenController::class, 'reactivate'])->name('warden.reactivate');
});

$r->dispatch();
