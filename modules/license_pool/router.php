<?php
declare(strict_types=1);

/**
 * modules/license_pool/router.php — trasy modułu „Pula licencji Fundacji” (wspólny dla TI i IT SZO).
 *   • TI — /karty30/ti/dydaktyk/licencjeFundacji.php (LP_CONTEXT='ti'; sesja i CSRF panelu dydaktyka; kadra zarządzająca),
 *   • IT — /it/licencje.php                          (LP_CONTEXT='it'; sesja SZO; role admin, editor).
 * Obie ścieżki pokazują TĘ SAMĄ pulę; zmiany trafiają do lp_* i (dla uczestników TI) do licencji uczestnika.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';

if (!defined('LP_CONTEXT') || !in_array(LP_CONTEXT, ['ti', 'it'], true)) { http_response_code(404); exit('Nie znaleziono strony.'); }
require_once __DIR__ . '/logic/LpController.php';

if (LP_CONTEXT === 'ti') {
    $r = new Router('/karty30/ti/dydaktyk/licencjeFundacji.php', 'query');
    $r->csrfUsing(fn() => dyd_token_check(), fn() => dyd_token(), '_token');
    $r->aliasMiddleware('lp.access', function () {
        dyd_require();
        if (!dyd_is_staff()) { http_response_code(403); exit('Pula licencji jest dostępna dla kierownika, zastępcy i administratora panelu.'); }
    });
} else {
    $r = new Router('/it/licencje.php', 'query');
    $r->aliasMiddleware('lp.access', function () { require_role('admin', 'editor'); });
}

$r->group(['lp.access'], function (Router $r) {
    $r->get('/',                        [LpController::class, 'index'])->name('lp.index');
    $r->post('/produkty',               [LpController::class, 'saveProduct'])->name('lp.product.save');
    $r->get('/produkty/{id}',           [LpController::class, 'product'])->name('lp.product');
    $r->post('/przydzial',              [LpController::class, 'assign'])->name('lp.assign');
    $r->post('/przydzial/podglad',      [LpController::class, 'previewMail'])->name('lp.assign.preview');
    $r->post('/przydzialy/{id}/odbierz',[LpController::class, 'revoke'])->name('lp.revoke');
    $r->post('/przydzialy/{id}/wyslij', [LpController::class, 'resend'])->name('lp.resend');
    $r->post('/przydzialy/{id}/adres',  [LpController::class, 'revealMail'])->name('lp.mail.reveal');
    $r->post('/licencje/{id}/pokaz',    [LpController::class, 'reveal'])->name('lp.reveal');
    $r->post('/licencje/{id}/wycofaj',  [LpController::class, 'retire'])->name('lp.retire');
    $r->get('/import',                  [LpController::class, 'importForm'])->name('lp.import');
    $r->post('/import/podglad',         [LpController::class, 'importPreview'])->name('lp.import.preview');
    $r->post('/import/zapisz',          [LpController::class, 'importCommit'])->name('lp.import.commit');
});
$r->dispatch();
