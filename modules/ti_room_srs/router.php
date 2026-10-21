<?php
/**
 * modules/ti_room_srs/router.php — trasy modułu „Formularze rezerwacji sal” (sale TI ↔ wzory operatorów ↔ SRS).
 *
 * Dwa wejścia, te same trasy i ta sama logika (źródło formularza/rezerwacji zapisywane jako DYD albo SZO):
 *   • panel — /karty30/ti/dydaktyk/saleFormularz.php?_route=<trasa> (definiuje TI_ROOM_SRS_PANEL; sesja i CSRF panelu
 *             dydaktyka; kierownik = dyd_is_staff());
 *   • SZO   — /modules/ti_room_srs/router.php?_route=<trasa> (sesja SZO; kierownik = administrator albo zapis w module SRS).
 * Trasy: / (grupy z salą + rezerwacje przestrzeni), /formularz (grupa), /nowa („Rezerwuj przestrzeń”), /sale (przypisanie
 * sal — tylko kierownik), /formularz/{id}/plik (podgląd z karty rezerwacji SRS — tylko SZO).
 * Dostęp: kierownik albo uprawnienie „Może rezerwować” (Zespół panelu dydaktyka).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/ti_print_log.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/RoomSrsController.php';

if (defined('TI_ROOM_SRS_PANEL')) {
    $r = new Router('/karty30/ti/dydaktyk/saleFormularz.php', 'query');
    $r->csrfUsing(fn() => dyd_token_check(), fn() => dyd_token(), '_token');
} else {
    $r = new Router('/modules/ti_room_srs/router.php');
    $r->get('/formularz/{id}/plik', [RoomSrsController::class, 'file'])->name('trs.file')->middleware('auth');
    $r->get('/formularz/{id}/docx', [RoomSrsController::class, 'file'])->middleware('auth');   // pierwsze linki z SRS
}

$r->aliasMiddleware('trs.book', function () {
    $me = RoomSrsController::me();
    if (!tiRoomSrsCanBook($me['id'], $me['staff'])) {
        http_response_code(403);
        exit('Brak uprawnienia „Może rezerwować” — nadaje je kierownik w Zespole panelu dydaktyka.');
    }
});
$r->aliasMiddleware('trs.staff', function () {
    if (!RoomSrsController::me()['staff']) { header('Location: ' . Router::current()->route('trs.index')); exit; }
});

$r->group(['trs.book'], function (Router $r) {
    $r->get('/',           [RoomSrsController::class, 'index'])->name('trs.index');
    $r->get('/formularz',  [RoomSrsController::class, 'form'])->name('trs.form');
    $r->post('/formularz', [RoomSrsController::class, 'generate'])->name('trs.generate');
    $r->get('/nowa',       [RoomSrsController::class, 'adhoc'])->name('trs.adhoc');
    $r->post('/nowa',      [RoomSrsController::class, 'adhocSave'])->name('trs.adhoc.save');
});
$r->group(['trs.staff'], function (Router $r) {
    $r->get('/sale',  [RoomSrsController::class, 'rooms'])->name('trs.rooms');
    $r->post('/sale', [RoomSrsController::class, 'roomsSave'])->name('trs.rooms.save');
    $r->post('/zatwierdz/{id}', [RoomSrsController::class, 'approve'])->name('trs.approve');
});

$r->dispatch();
