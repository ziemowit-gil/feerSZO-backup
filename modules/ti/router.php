<?php
/**
 * modules/ti/router.php — trasy modułu TI (na wzór Laravela). Na razie: Nabór do grup.
 *
 * Dwa wejścia, jedna tablica tras:
 *   • publiczne  — /modules/ti/router.php/<trasa> (sesja SZO, CSRF _csrf): formularz naboru, kod SMS, potwierdzenie e-mail;
 *   • panel      — /karty30/ti/dydaktyk/nabor.php?_route=<trasa> (definiuje TI_ROUTER_PANEL i ładuje sesję panelu
 *                  dydaktyka przed tym plikiem; CSRF dyd_token/_token): obieg zgłoszeń kierownika i widok prowadzącego.
 * Trasy panelu bez wejścia panelu dają 404, trasy publiczne w panelu też — sesje się nie mieszają.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/nabor/nabor.php';

$panel = defined('TI_ROUTER_PANEL');

if (!$panel) {
    require_once __DIR__ . '/logic/nabor/NaborPublicController.php';
    $r = new Router(NABOR_ENTRY);
    $r->group([], function (Router $r) {
        $r->get('/zapisy',     [NaborPublicController::class, 'form'])->name('nabor.form');
        $r->post('/zapisy',    [NaborPublicController::class, 'submit']);
        $r->post('/zapisy/sms', [NaborPublicController::class, 'sms'])->name('nabor.sms');
        $r->get('/potwierdz',  [NaborPublicController::class, 'confirmForm'])->name('nabor.confirm');
        $r->post('/potwierdz', [NaborPublicController::class, 'confirm']);
    }, '/nabor');
} else {
    require_once __DIR__ . '/logic/nabor/NaborPanelController.php';
    $r = new Router(NABOR_PANEL_ENTRY, 'query');
    $r->csrfUsing(fn() => dyd_token_check(), fn() => dyd_token(), '_token');
    // Sesja panelu dydaktyka: każdy zalogowany (prowadzący) / tylko kierownik (dyd_is_staff)
    $r->aliasMiddleware('dyd', fn() => dyd_require());
    $r->aliasMiddleware('dyd.staff', function () {
        dyd_require();
        if (!dyd_is_staff()) { header('Location: ' . Router::current()->route('nabor.instructor')); exit; }
    });

    $r->get('/prowadzacy', [NaborPanelController::class, 'instructor'])->name('nabor.instructor')->middleware('dyd');
    $r->group(['dyd.staff'], function (Router $r) {
        $r->get('/',                     [NaborPanelController::class, 'index'])->name('nabor.index');
        $r->post('/zgloszenia/masowo',   [NaborPanelController::class, 'bulk'])->name('nabor.bulk');
        $r->post('/zgloszenia/przenies', [NaborPanelController::class, 'move'])->name('nabor.move');
        $r->post('/przelacz',            [NaborPanelController::class, 'toggleOpen'])->name('nabor.toggle');
        $r->post('/zgloszenia/testowe',  [NaborPanelController::class, 'createTest'])->name('nabor.test');
        $r->get('/zgloszenia/{id}',      [NaborPanelController::class, 'show'])->name('nabor.show');
        $r->post('/zgloszenia/{id}',     [NaborPanelController::class, 'update'])->name('nabor.update');
        $r->get('/zgloszenia/{id}/pdf',  [NaborPanelController::class, 'pdf'])->name('nabor.pdf');
        $r->get('/grupy',                [NaborPanelController::class, 'courses'])->name('nabor.courses');
        $r->post('/grupy/{id}',          [NaborPanelController::class, 'courseSave'])->name('nabor.courses.save');
    });
}

$r->dispatch();
