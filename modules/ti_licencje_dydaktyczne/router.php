<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/router.php — trasy modułu „Licencje dydaktyczne (AM5) i oprogramowanie (AM7)”.
 *
 * Dwa wejścia, osobne sesje:
 *   • panel     — /karty30/ti/dydaktyk/licencjeDydaktyczne.php?_route=<trasa> (definiuje LD_PANEL; sesja i CSRF panelu
 *                 dydaktyka: dyd_token / _token; tylko kadra zarządzająca — dyd_is_staff),
 *   • publiczne — /karty30/ti/licencja.php?_route=<trasa> (definiuje LD_PUBLIC; sesja SZO, CSRF _csrf; dostęp po tokenie
 *                 z linku: licencjodawca AM5 → /decyzja/{token}, kursant AM7 (podpis wniosku) → /wniosek/{token},
 *                 licencjobiorca → /oswiadczenie/{token}, osoba potwierdzająca zmianę płatnika AM20 → /zmiana-platnika/{token}).
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
        $r->get('/zmiana-platnika/{token}',     [LicensePublicController::class, 'paymentForm'])->name('ld.pub.payment');
        $r->post('/zmiana-platnika/{token}',    [LicensePublicController::class, 'paymentSubmit']);
        $r->get('/zmiana-platnika/{token}/pdf', [LicensePublicController::class, 'paymentPdf'])->name('ld.pub.payment.pdf');
        $r->get('/gotowe',                   [LicensePublicController::class, 'done'])->name('ld.pub.done');
    });
    $r->dispatch();
    return;
}

if (!defined('LD_PANEL')) { http_response_code(404); exit('Nie znaleziono strony.'); }

require_once __DIR__ . '/logic/LicenseAdminController.php';
require_once __DIR__ . '/logic/LicenseOperationController.php';
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
    $r->post('/ustawienia/przypomnienia',        [LicenseAdminController::class, 'agentSettings'])->name('ld.settings.agent');
    $r->get('/nowy',                             [LicenseAdminController::class, 'createForm'])->name('ld.new');
    $r->post('/nowy',                            [LicenseAdminController::class, 'create']);
    $r->get('/nowy-am7',                         [LicenseAdminController::class, 'createAm7Form'])->name('ld.new7');
    $r->post('/nowy-am7',                        [LicenseAdminController::class, 'createAm7']);
    $r->get('/licencje/{id}',                    [LicenseAdminController::class, 'show'])->name('ld.show');
    $r->post('/licencje/{id}/podpis-link',       [LicenseAdminController::class, 'signLink'])->name('ld.am7.signlink');
    $r->post('/licencje/{id}/projekt-decyzji',   [LicenseAdminController::class, 'am7Prepare'])->name('ld.am7.prepare');
    $r->post('/licencje/{id}/zatwierdz',         [LicenseAdminController::class, 'am7Approve'])->name('ld.am7.approve');
    $r->get('/licencje/{id}/decyzja.pdf',        [LicenseAdminController::class, 'am7DecisionPdf'])->name('ld.am7.pdf');
    $r->post('/licencje/{id}/decyzja-ponownie', [LicenseAdminController::class, 'am7Resend'])->name('ld.am7.resend');
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
    // Rejestr operacji na licencjach: konta i posiadane licencje, AM11–AM13, AM20–AM24
    $r->get('/konta',                            [LicenseOperationController::class, 'accounts'])->name('ld.accounts');
    $r->get('/am20',                             [LicenseOperationController::class, 'am20Wizard'])->name('ld.am20');
    $r->post('/operacje/{id}/am20-1',            [LicenseOperationController::class, 'am20AnnexSave'])->name('ld.op.am20annex');
    $r->get('/konto/{kind}/{id}',                [LicenseOperationController::class, 'account'])->name('ld.account');
    $r->get('/operacje/nowa',                    [LicenseOperationController::class, 'createForm'])->name('ld.op.new');
    $r->post('/operacje/nowa',                   [LicenseOperationController::class, 'create']);
    $r->get('/operacje/{id}',                    [LicenseOperationController::class, 'show'])->name('ld.op');
    $r->get('/operacje/{id}/pdf',                [LicenseOperationController::class, 'pdf'])->name('ld.op.pdf');
    $r->get('/operacje/{id}/json',               [LicenseOperationController::class, 'json'])->name('ld.op.json');
    $r->get('/operacje/{id}/dowod',              [LicenseOperationController::class, 'proof'])->name('ld.op.proof');
    $r->post('/operacje/{id}/decyzja',           [LicenseOperationController::class, 'decide'])->name('ld.op.decide');
    $r->post('/operacje/{id}/wykonane',          [LicenseOperationController::class, 'done'])->name('ld.op.done');
    $r->post('/operacje/{id}/wycofaj',           [LicenseOperationController::class, 'cancel'])->name('ld.op.cancel');
    $r->post('/operacje/{id}/link',              [LicenseOperationController::class, 'signLink'])->name('ld.op.signlink');
    $r->post('/operacje/{id}/ponownie',          [LicenseOperationController::class, 'resend'])->name('ld.op.resend');
    $r->get('/wzor-op/{form}',                   [LicenseOperationController::class, 'blank'])->name('ld.op.blank');
    $r->get('/wzor/{doc}',                       [LicenseAdminController::class, 'blank'])->name('ld.blank');
});

$r->dispatch();
