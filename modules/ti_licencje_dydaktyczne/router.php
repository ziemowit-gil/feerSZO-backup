<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/router.php — trasy modułu „Licencje dydaktyczne (AM5) i Office 365 (AM7)”.
 *
 * Dwa wejścia, osobne sesje:
 *   • panel     — /karty30/ti/dydaktyk/licencjeDydaktyczne.php?_route=<trasa> (definiuje LD_PANEL; sesja i CSRF panelu
 *                 dydaktyka: dyd_token / _token; tylko kadra zarządzająca — dyd_is_staff),
 *   • publiczne — /karty30/ti/licencja.php?_route=<trasa> (definiuje LD_PUBLIC; sesja SZO, CSRF _csrf; dostęp po tokenie
 *                 z linku: licencjodawca AM5 → /decyzja/{token}, kursant AM7 (podpis wniosku) → /wniosek/{token},
 *                 licencjobiorca → /oswiadczenie/{token}).
 * Bez pliku wejścia — 404.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';

if (defined('LD_PUBLIC')) {
    require_once __DIR__ . '/logic/LicensePublicController.php';
    $r = new Router('/karty30/ti/licencja.php', 'query');
    $r->aliasMiddleware('pub', function () {
        auth_start();
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store, private');
        header('X-Frame-Options: DENY');
    });
    $r->group(['pub'], function (Router $r) {
        $r->get('/decyzja/{token}',          [LicensePublicController::class, 'licensorForm'])->name('ld.pub.decision');
        $r->post('/decyzja/{token}',         [LicensePublicController::class, 'licensorSubmit']);
        $r->get('/decyzja/{token}/wniosek',  [LicensePublicController::class, 'licensorPdf'])->name('ld.pub.decision.pdf');
        $r->get('/oswiadczenie/{token}',     [LicensePublicController::class, 'holderForm'])->name('ld.pub.holder');
        $r->post('/oswiadczenie/{token}',    [LicensePublicController::class, 'holderSubmit']);
        $r->get('/wniosek/{token}',          [LicensePublicController::class, 'requesterForm'])->name('ld.pub.request');
        $r->post('/wniosek/{token}',         [LicensePublicController::class, 'requesterSubmit']);
        $r->get('/wniosek/{token}/pdf',      [LicensePublicController::class, 'requesterPdf'])->name('ld.pub.request.pdf');
        $r->get('/gotowe',                   [LicensePublicController::class, 'done'])->name('ld.pub.done');
    });
    $r->dispatch();
    return;
}

if (!defined('LD_PANEL')) { http_response_code(404); exit('Nie znaleziono strony.'); }

require_once __DIR__ . '/logic/LicenseAdminController.php';
$r = new Router('/karty30/ti/dydaktyk/licencjeDydaktyczne.php', 'query');
$r->csrfUsing(fn() => dyd_token_check(), fn() => dyd_token(), '_token');
$r->aliasMiddleware('dyd.staff', function () {
    dyd_require();
    if (!dyd_is_staff()) { http_response_code(403); exit('Licencje dydaktyczne (AM5) są dostępne dla kierownika, zastępcy i administratora panelu.'); }
});

$r->group(['dyd.staff'], function (Router $r) {
    $r->get('/',                                 [LicenseAdminController::class, 'index'])->name('ld.index');
    $r->post('/przypomnij',                      [LicenseAdminController::class, 'remindDue'])->name('ld.remind');
    $r->post('/ustawienia',                      [LicenseAdminController::class, 'settings'])->name('ld.settings');
    $r->get('/nowy',                             [LicenseAdminController::class, 'createForm'])->name('ld.new');
    $r->post('/nowy',                            [LicenseAdminController::class, 'create']);
    $r->get('/nowy-am7',                         [LicenseAdminController::class, 'createAm7Form'])->name('ld.new7');
    $r->post('/nowy-am7',                        [LicenseAdminController::class, 'createAm7']);
    $r->get('/licencje/{id}',                    [LicenseAdminController::class, 'show'])->name('ld.show');
    $r->post('/licencje/{id}/podpis-link',       [LicenseAdminController::class, 'signLink'])->name('ld.am7.signlink');
    $r->post('/licencje/{id}/projekt-decyzji',   [LicenseAdminController::class, 'am7Prepare'])->name('ld.am7.prepare');
    $r->post('/licencje/{id}/zatwierdz',         [LicenseAdminController::class, 'am7Approve'])->name('ld.am7.approve');
    $r->get('/licencje/{id}/decyzja.pdf',        [LicenseAdminController::class, 'am7DecisionPdf'])->name('ld.am7.pdf');
    $r->post('/licencje/{id}/helpdesk',          [LicenseAdminController::class, 'am7Helpdesk'])->name('ld.am7.helpdesk');
    $r->get('/licencje/{id}/edycja',             [LicenseAdminController::class, 'editForm'])->name('ld.edit');
    $r->post('/licencje/{id}/edycja',            [LicenseAdminController::class, 'update']);
    $r->post('/licencje/{id}/wszczecie',         [LicenseAdminController::class, 'start'])->name('ld.start');
    $r->post('/licencje/{id}/licencjodawca',     [LicenseAdminController::class, 'resendLicensor'])->name('ld.licensor.resend');
    $r->post('/licencje/{id}/decyzja',           [LicenseAdminController::class, 'decide'])->name('ld.decide');
    $r->post('/licencje/{id}/wycofaj',           [LicenseAdminController::class, 'withdraw'])->name('ld.withdraw');
    $r->post('/licencje/{id}/usun',              [LicenseAdminController::class, 'delete'])->name('ld.delete');
    $r->post('/licencje/{id}/zawieszenie',       [LicenseAdminController::class, 'suspend'])->name('ld.suspend');
    $r->post('/licencje/{id}/przydzial',         [LicenseAdminController::class, 'assign'])->name('ld.assign');
    $r->get('/licencje/{id}/wniosek.pdf',        [LicenseAdminController::class, 'requestPdf'])->name('ld.request.pdf');
    $r->post('/przydzialy/{id}/link',            [LicenseAdminController::class, 'invite'])->name('ld.invite');
    $r->post('/przydzialy/{id}/cofnij',          [LicenseAdminController::class, 'revoke'])->name('ld.revoke');
    $r->post('/przydzialy/{id}/weryfikacja',     [LicenseAdminController::class, 'verify'])->name('ld.verify');
    $r->post('/zmiany/{id}',                     [LicenseAdminController::class, 'decideChange'])->name('ld.change');
    $r->get('/oswiadczenia/{id}',                [LicenseAdminController::class, 'declarationFile'])->name('ld.decl.file');
    $r->get('/wzor/{doc}',                       [LicenseAdminController::class, 'blank'])->name('ld.blank');
});

$r->dispatch();
