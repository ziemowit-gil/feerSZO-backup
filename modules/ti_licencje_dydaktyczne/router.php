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
        $r->get('/amsk/{token}',             [LicensePublicController::class, 'amskVerify'])->name('ld.pub.amsk');
        $r->get('/up1/{token}',              [LicensePublicController::class, 'rotationVerify'])->name('ld.pub.up1');
        $r->get('/spis/{token}',             [LicensePublicController::class, 'indexView'])->name('ld.pub.spis');
    });
    $r->dispatch();
    return;
}

if (!defined('LD_PANEL')) { http_response_code(404); exit('Nie znaleziono strony.'); }

require_once __DIR__ . '/logic/LicenseAdminController.php';
require_once __DIR__ . '/logic/LicenseOperationController.php';
require_once __DIR__ . '/logic/LicenseCorrectionController.php';
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
    $r->get('/licencje/{id}/konto-w-tenancie',   [LicenseAdminController::class, 'am7TenantForm'])->name('ld.am7.tenant');
    $r->post('/licencje/{id}/konto-w-tenancie',  [LicenseAdminController::class, 'am7TenantCreate']);
    $r->get('/licencje/{id}/am90.pdf',           [LicenseAdminController::class, 'am90Pdf'])->name('ld.am90.pdf');
    $r->post('/licencje/{id}/am90-wyslij',       [LicenseAdminController::class, 'am90Send'])->name('ld.am90.send');
    $r->post('/licencje/{id}/zmiana-upn',        [LicenseAdminController::class, 'am91Change'])->name('ld.am91.change');
    $r->get('/licencje/{id}/am91.pdf',           [LicenseAdminController::class, 'am91Pdf'])->name('ld.am91.pdf');
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
    // AMSK: korekty danych w dokumentach AM (druk kontrolny AMSK/1 z QR i Code128, podpis odręczny kierownika), skan kodu z formularza
    $r->get('/korekty',                          [LicenseCorrectionController::class, 'index'])->name('ld.corr.index');
    $r->get('/korekty/kod',                      [LicenseCorrectionController::class, 'scan'])->name('ld.scan');
    $r->post('/korekty/nowa',                    [LicenseCorrectionController::class, 'create'])->name('ld.corr.new');
    $r->get('/korekty/{id}',                     [LicenseCorrectionController::class, 'show'])->name('ld.corr');
    $r->get('/korekty/{id}/pdf',                 [LicenseCorrectionController::class, 'pdf'])->name('ld.corr.pdf');
    $r->post('/korekty/{id}/zatwierdz',          [LicenseCorrectionController::class, 'approve'])->name('ld.corr.approve');
    $r->post('/korekty/{id}/pakiet',             [LicenseCorrectionController::class, 'pack'])->name('ld.corr.pack');
    $r->get('/pakiet/{pid}/zip',                 [LicenseCorrectionController::class, 'packZip'])->name('ld.pack.zip');
    $r->post('/pakiet/{pid}/wyslij',             [LicenseCorrectionController::class, 'packSend'])->name('ld.pack.send');
    $r->post('/pakiet/{pid}/regeneruj',          [LicenseCorrectionController::class, 'packRegen'])->name('ld.pack.regen');
    $r->post('/pakiet/{pid}/haslo',              [LicenseCorrectionController::class, 'packPwd'])->name('ld.pack.pwd');
    $r->get('/pakiet/{pid}/amsg.pdf',            [LicenseCorrectionController::class, 'packAmsg'])->name('ld.pack.amsg');
    $r->get('/pakiet/{pid}/pp.pdf',              [LicenseCorrectionController::class, 'packPp'])->name('ld.pack.pp');
    $r->post('/pakiet/{pid}/zablokuj',           [LicenseCorrectionController::class, 'packLock'])->name('ld.pack.lock');
    $r->get('/pakiet/{pid}/scalony.pdf',         [LicenseCorrectionController::class, 'packMerged'])->name('ld.pack.merged');
    $r->get('/licencje/{id}/am56.pdf',           [LicenseCorrectionController::class, 'am56License'])->name('ld.am56');
    $r->get('/licencje/{id}/okladka.pdf',        [LicenseCorrectionController::class, 'coverLicense'])->name('ld.cover');
    $r->get('/operacje/{id}/okladka.pdf',        [LicenseCorrectionController::class, 'coverOperation'])->name('ld.cover.op');
    $r->get('/operacje/{id}/am56.pdf',           [LicenseCorrectionController::class, 'am56Operation'])->name('ld.am56.op');
    // ASK-UP1: uzasadnienie rotacji licencji (druk z QR i Code128, podpis odręczny kierownika)
    $r->post('/rotacje/nowa',                    [LicenseCorrectionController::class, 'rotCreate'])->name('ld.rot.new');
    $r->get('/rotacje/{id}',                     [LicenseCorrectionController::class, 'rotShow'])->name('ld.rot');
    $r->get('/rotacje/{id}/pdf',                 [LicenseCorrectionController::class, 'rotPdf'])->name('ld.rot.pdf');
    $r->post('/rotacje/{id}/podpisano',          [LicenseCorrectionController::class, 'rotConfirm'])->name('ld.rot.confirm');
    $r->post('/rotacje/{id}/anuluj',             [LicenseCorrectionController::class, 'rotCancel'])->name('ld.rot.cancel');
    $r->post('/korekty/{id}/anuluj',             [LicenseCorrectionController::class, 'cancel'])->name('ld.corr.cancel');
    $r->post('/korekty/{id}/wyslij',             [LicenseCorrectionController::class, 'send'])->name('ld.corr.send');
    // Rejestr operacji na licencjach: konta i posiadane licencje, AM11–AM13, AM20–AM24
    $r->get('/konta',                            [LicenseOperationController::class, 'accounts'])->name('ld.accounts');
    $r->get('/zestawienie/{id}',                 [LicenseOperationController::class, 'amz'])->name('ld.amz');
    $r->get('/zestawienie/{id}/pdf',             [LicenseOperationController::class, 'amzPdf'])->name('ld.amz.pdf');
    $r->post('/zestawienie/{id}/wyslij',         [LicenseOperationController::class, 'amzSend'])->name('ld.amz.send');
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
