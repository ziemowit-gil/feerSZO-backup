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
        $r->get('/dop5/{token}',          [DopPublicController::class, 'dop5Form'])->name('dop5.pub.sign');
        $r->post('/dop5/{token}',         [DopPublicController::class, 'dop5Submit']);
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
    $r->get('/',                              [DopController::class, 'hub'])->name('dop.index');
    $r->get('/wnioski',                       [DopController::class, 'index'])->name('dop.list');
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
    $r->get('/rop',                           [DopController::class, 'ropIndex'])->name('rop.index');
    $r->post('/rop',                          [DopController::class, 'ropCreate'])->name('rop.create');
    $r->post('/rop/auto',                     [DopController::class, 'ropAuto'])->name('rop.auto');
    $r->get('/rop/{id}',                      [DopController::class, 'ropShow'])->name('rop.show');
    $r->get('/rop/{id}/pdf',                  [DopController::class, 'ropPdf'])->name('rop.pdf');
    $r->post('/rop/{id}/wyslij',              [DopController::class, 'ropSend'])->name('rop.send');
    $r->get('/rozliczenia',                   [DopController::class, 'settleIndex'])->name('settle.index');
    $r->get('/rozliczenia/nowy',              [DopController::class, 'settleForm'])->name('settle.new');
    $r->post('/rozliczenia/nowy',             [DopController::class, 'settleCreate']);
    $r->post('/rozliczenia/auto-rm',          [DopController::class, 'autoRmSave'])->name('settle.autorm');
    $r->get('/rozliczenia/{id}',              [DopController::class, 'settleShow'])->name('settle.show');
    $r->get('/rozliczenia/{id}/pdf',          [DopController::class, 'settlePdf'])->name('settle.pdf');
    $r->post('/rozliczenia/{id}/decyzja',     [DopController::class, 'settleDecide'])->name('settle.decide');
    $r->post('/rozliczenia/{id}/wyslij',      [DopController::class, 'settleSend'])->name('settle.send');
    $r->get('/dop5',                          [DopController::class, 'dop5Index'])->name('dop5.index');
    $r->get('/dop5/nowy',                     [DopController::class, 'dop5Form'])->name('dop5.new');
    $r->post('/dop5/nowy',                    [DopController::class, 'dop5Create']);
    $r->get('/dop5/wzor',                     [DopController::class, 'dop5Blank'])->name('dop5.blank');
    $r->get('/dop5/{id}',                     [DopController::class, 'dop5Show'])->name('dop5.show');
    $r->get('/dop5/{id}/pdf',                 [DopController::class, 'dop5Pdf'])->name('dop5.pdf');
    $r->get('/dop5/{id}/plik/{i}',            [DopController::class, 'dop5File'])->name('dop5.file');
    $r->post('/dop5/{id}/skan',               [DopController::class, 'dop5Scan'])->name('dop5.scan');
    $r->post('/dop5/{id}/link/{sid}',         [DopController::class, 'dop5Link'])->name('dop5.link');
    $r->post('/dop5/{id}/wycofaj',            [DopController::class, 'dop5Cancel'])->name('dop5.cancel');
    $r->get('/wzor/{form}',                   [DopController::class, 'blank'])->name('dop.blank');
});
$r->dispatch();
