<?php
/**
 * modules/skierowania_badania/router.php — jedyne wejście modułu Skierowania na badania lekarskie (medycyna pracy).
 *
 * Adresy: /modules/skierowania_badania/router.php/<trasa>, np. …/router.php/nowe?type=zlecenie&id=12,
 * …/router.php/5 (karta skierowania), …/router.php/5/pdf. Każdy POST przechodzi przez CSRF (Router).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/approval.php';   // log_contract_action()
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/SkierowaniaController.php';

$r = new Router('/modules/skierowania_badania/router.php');

$r->group(['role:admin,editor,manager'], function (Router $r) {
    $r->get('/',                [SkierowaniaController::class, 'index'])->name('skb.index');
    $r->get('/nowe',            [SkierowaniaController::class, 'create'])->name('skb.create');
    $r->post('/nowe',           [SkierowaniaController::class, 'store'])->name('skb.store');
    $r->post('/ustawienia',     [SkierowaniaController::class, 'settings'])->name('skb.settings');
    $r->get('/{id}',            [SkierowaniaController::class, 'show'])->name('skb.show');
    $r->post('/{id}',           [SkierowaniaController::class, 'update'])->name('skb.update');
    $r->get('/{id}/pdf',        [SkierowaniaController::class, 'pdf'])->name('skb.pdf');
    $r->post('/{id}/ezd',       [SkierowaniaController::class, 'ezd'])->name('skb.ezd');
    $r->post('/{id}/wynik',     [SkierowaniaController::class, 'result'])->name('skb.result');
    $r->post('/{id}/umowa',     [SkierowaniaController::class, 'link'])->name('skb.link');
    $r->post('/{id}/anuluj',    [SkierowaniaController::class, 'cancel'])->name('skb.cancel');
});

$r->dispatch();
