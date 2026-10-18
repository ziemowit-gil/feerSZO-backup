<?php
declare(strict_types=1);

/**
 * modules/ti_dop/router.php — trasy wniosków DOP1 (zwrot nadpłaty) i DOP2 (płatność z subkonta).
 *
 *   • panel     — /karty30/ti/dydaktyk/wnioskiDop.php?_route=<trasa> (DOP_PANEL; sesja i CSRF panelu dydaktyka, kadra zarządzająca),
 *   • publiczne — /karty30/ti/wniosekDop.php?_route=/potwierdz/{token} (DOP_PUBLIC; podpis elektroniczny wnioskodawcy po tokenie).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';

if (defined('DOP_PUBLIC')) {
    require_once __DIR__ . '/logic/DopPublicController.php';
    $r = new Router('/karty30/ti/wniosekDop.php', 'query');
    $r->aliasMiddleware('pub', function () {
        auth_start();
        header('X-Robots-Tag: noindex, nofollow'); header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store, private'); header('X-Frame-Options: DENY');
    });
    $r->group(['pub'], function (Router $r) {
        $r->get('/potwierdz/{token}',     [DopPublicController::class, 'form'])->name('dop.pub.sign');
        $r->post('/potwierdz/{token}',    [DopPublicController::class, 'submit']);
        $r->get('/potwierdz/{token}/pdf', [DopPublicController::class, 'pdf'])->name('dop.pub.pdf');
        $r->get('/gotowe',                [DopPublicController::class, 'done'])->name('dop.pub.done');
    });
    $r->dispatch();
    return;
}

if (!defined('DOP_PANEL')) { http_response_code(404); exit('Nie znaleziono strony.'); }

require_once __DIR__ . '/logic/DopController.php';
$r = new Router('/karty30/ti/dydaktyk/wnioskiDop.php', 'query');
$r->csrfUsing(fn() => dyd_token_check(), fn() => dyd_token(), '_token');
$r->aliasMiddleware('dyd.staff', function () {
    dyd_require();
    if (!dyd_is_staff()) { http_response_code(403); exit('Wnioski DOP są dostępne dla kierownika, zastępcy i administratora panelu.'); }
});
$r->group(['dyd.staff'], function (Router $r) {
    $r->get('/',                              [DopController::class, 'index'])->name('dop.index');
    $r->get('/nowy',                          [DopController::class, 'createForm'])->name('dop.new');
    $r->post('/nowy',                         [DopController::class, 'create']);
    $r->get('/wnioski/{id}',                  [DopController::class, 'show'])->name('dop.show');
    $r->get('/wnioski/{id}/pdf',              [DopController::class, 'pdf'])->name('dop.pdf');
    $r->get('/wnioski/{id}/skan',             [DopController::class, 'scan'])->name('dop.scan.get');
    $r->post('/wnioski/{id}/skan',            [DopController::class, 'scanUpload'])->name('dop.scan');
    $r->post('/wnioski/{id}/link',            [DopController::class, 'signLink'])->name('dop.link');
    $r->post('/wnioski/{id}/weryfikacja',     [DopController::class, 'verify'])->name('dop.verify');
    $r->post('/wnioski/{id}/zatwierdzenie',   [DopController::class, 'approve'])->name('dop.approve');
    $r->post('/wnioski/{id}/realizacja',      [DopController::class, 'execute'])->name('dop.execute');
    $r->post('/wnioski/{id}/wycofaj',         [DopController::class, 'cancel'])->name('dop.cancel');
    $r->get('/wzor/{form}',                   [DopController::class, 'blank'])->name('dop.blank');
});
$r->dispatch();
