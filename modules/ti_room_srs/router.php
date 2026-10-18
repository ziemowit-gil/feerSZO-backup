<?php
/**
 * modules/ti_room_srs/router.php — trasy modułu „Formularze rezerwacji sal” (sale TI ↔ SRS).
 *
 * Dwa wejścia:
 *   • panel — /karty30/ti/dydaktyk/saleFormularz.php?_route=<trasa> (definiuje TI_ROOM_SRS_PANEL, sesja i CSRF panelu
 *             dydaktyka): lista grup z salą, formularz, generowanie DOCX + rezerwacja SRS, przypisanie sal;
 *   • SZO   — /modules/ti_room_srs/router.php/<trasa> (sesja SZO): podgląd formularza z karty rezerwacji SRS.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/RoomSrsController.php';

if (defined('TI_ROOM_SRS_PANEL')) {
    require_once dirname(__DIR__, 2) . '/includes/ti_print_log.php';
    $r = new Router('/karty30/ti/dydaktyk/saleFormularz.php', 'query');
    $r->csrfUsing(fn() => dyd_token_check(), fn() => dyd_token(), '_token');
    $r->aliasMiddleware('dyd.staff', function () {
        dyd_require();
        if (!dyd_is_staff()) { header('Location: index.php'); exit; }
    });
    $r->group(['dyd.staff'], function (Router $r) {
        $r->get('/',          [RoomSrsController::class, 'index'])->name('trs.index');
        $r->get('/formularz', [RoomSrsController::class, 'form'])->name('trs.form');
        $r->post('/formularz', [RoomSrsController::class, 'generate'])->name('trs.generate');
        $r->get('/sale',      [RoomSrsController::class, 'rooms'])->name('trs.rooms');
        $r->post('/sale',     [RoomSrsController::class, 'roomsSave'])->name('trs.rooms.save');
    });
} else {
    $r = new Router('/modules/ti_room_srs/router.php');
    $r->get('/formularz/{id}/docx', [RoomSrsController::class, 'docx'])->name('trs.docx')->middleware('auth');
}

$r->dispatch();
