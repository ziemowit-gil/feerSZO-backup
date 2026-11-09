<?php
declare(strict_types=1);

/**
 * modules/claude_billing/router.php — trasy panelu „Abonament Claude” (panel dydaktyka, kadra zarządzająca).
 * Wejście: /karty30/ti/dydaktyk/claudeAbonament.php?_route=<trasa> (definiuje CB_PANEL; sesja i CSRF panelu dydaktyka: dyd_token / _token).
 * Strona testu całego przebiegu: ?_route=/test.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';

if (!defined('CB_PANEL')) { http_response_code(404); exit('Nie znaleziono strony.'); }

require_once __DIR__ . '/logic/ClaudeBillingController.php';
$r = new Router('/karty30/ti/dydaktyk/claudeAbonament.php', 'query');
$r->csrfUsing(fn() => dyd_token_check(), fn() => dyd_token(), '_token');
$r->aliasMiddleware('dyd.staff', function () {
    dyd_require();
    if (!dyd_is_staff()) { http_response_code(403); exit('Abonament Claude jest dostępny dla kierownika, zastępcy i administratora panelu.'); }
});

$r->group(['dyd.staff'], function (Router $r) {
    $r->get('/',                              [ClaudeBillingController::class, 'index'])->name('cb.index');
    $r->post('/ustawienia',                   [ClaudeBillingController::class, 'saveSettings'])->name('cb.settings');
    $r->post('/kurs',                         [ClaudeBillingController::class, 'rateCheck'])->name('cb.rate');
    $r->post('/uruchom',                      [ClaudeBillingController::class, 'runNow'])->name('cb.run');
    $r->post('/komunikat',                    [ClaudeBillingController::class, 'notice'])->name('cb.notice');
    $r->post('/zmiana-ceny',                  [ClaudeBillingController::class, 'priceCreate'])->name('cb.price.create');
    $r->post('/zmiana-ceny/{id}/wyslij',      [ClaudeBillingController::class, 'priceSend'])->name('cb.price.send');
    $r->post('/zmiana-ceny/{id}/wycofaj',     [ClaudeBillingController::class, 'priceCancel'])->name('cb.price.cancel');
    $r->post('/aktywuj',                      [ClaudeBillingController::class, 'activate'])->name('cb.activate');
    $r->post('/konto-licencji',               [ClaudeBillingController::class, 'licAccount'])->name('cb.lic.account');
    $r->get('/abonament/{id}',                [ClaudeBillingController::class, 'show'])->name('cb.show');
    $r->post('/abonament/{id}/dane',          [ClaudeBillingController::class, 'buyer'])->name('cb.buyer');
    $r->post('/abonament/{id}/dzien',         [ClaudeBillingController::class, 'dueDay'])->name('cb.dueday');
    $r->post('/abonament/{id}/zakoncz',       [ClaudeBillingController::class, 'end'])->name('cb.end');
    $r->post('/naliczenie/{id}/oplacone',     [ClaudeBillingController::class, 'chargePaid'])->name('cb.charge.paid');
    $r->post('/naliczenie/{id}/anuluj',       [ClaudeBillingController::class, 'chargeCancel'])->name('cb.charge.cancel');
    $r->post('/naliczenie/{id}/faktura',      [ClaudeBillingController::class, 'chargeInvoice'])->name('cb.charge.invoice');
    // test całego przebiegu (abonament testowy, bez wysyłek do ludzi; proforma w Fakturowni)
    $r->get('/test',                          [ClaudeBillingController::class, 'test'])->name('cb.test');
    $r->post('/test/start',                   [ClaudeBillingController::class, 'testStart'])->name('cb.test.start');
    $r->post('/test/naliczenie/{id}/zaplac',  [ClaudeBillingController::class, 'testPay'])->name('cb.test.pay');
    $r->post('/test/naliczenie/{id}/p24',     [ClaudeBillingController::class, 'testP24'])->name('cb.test.p24');
    $r->post('/test/czas',                    [ClaudeBillingController::class, 'testTime'])->name('cb.test.time');
    $r->post('/test/wyczysc',                 [ClaudeBillingController::class, 'testClean'])->name('cb.test.clean');
});
$r->dispatch();
