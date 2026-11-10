<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/router.php — Panel Licencji: kursant.feer.org.pl/licencje/ (jeden panel w stylu Portalu Kontrahenta).
 * Wejście: licencje/index.php w korzeniu (definiuje LK_PANEL i uruchamia sesję szo_licencje — LicencjePanelAuth); panel jest niezależny od panelu TI.
 * Logowanie: login „l” + 8 cyfr i hasło (/logowanie); z panelu TI jednorazowy link ?wejscie=…. CSRF: token sesji panelu (_token).
 * Pierwsze wejście: brakujący e-mail / telefon (/kontakt, kod SMS). Każda operacja: kod jednorazowy (/potwierdz) — LicencjeKod.
 * Adres bazowy: zawsze /licencje/ (na każdym hoście).
 */
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/LicencjeKursantController.php';
require_once __DIR__ . '/logic/LicencjeKod.php';
require_once __DIR__ . '/logic/LicencjeAdminController.php';

if (!defined('LK_PANEL')) { http_response_code(404); exit('Nie znaleziono strony.'); }

$r = new Router(LicencjePanelAuth::PATH, 'query');
$r->csrfUsing(fn() => LicencjePanelAuth::check(), fn() => LicencjePanelAuth::token(), '_token');
$r->aliasMiddleware('lp.auth', function () {
    if (!LicencjePanelAuth::user()) { header('Location: ' . route('lk.login')); exit; }
});

// Pierwsze wejście: bez e-maila albo telefonu w kartotece — najpierw dane kontaktowe (telefon potwierdzony kodem SMS), dopiero potem operacje.
$r->aliasMiddleware('lp.contact', function () {
    $u = LicencjePanelAuth::user();
    if ($u && LicencjeKod::needsContact((int)$u['cid'])) { header('Location: ' . route('lk.contact')); exit; }
});

// Administrator Licencji (logowanie SZO / Microsoft 365 przez licencje/sso.php)
$r->aliasMiddleware('lp.admin', function () {
    if (!LicencjePanelAuth::admin()) { header('Location: ' . route('lk.login')); exit; }
});
$r->group(['lp.admin'], function (Router $r) {
    $r->get('/admin',                  [LicencjeAdminController::class, 'index'])->name('lk.admin');
    $r->post('/admin/dostep',          [LicencjeAdminController::class, 'access'])->name('lk.admin.access');
    $r->post('/admin/office',          [LicencjeAdminController::class, 'office'])->name('lk.admin.office');
    $r->post('/admin/wyloguj',         [LicencjeAdminController::class, 'logout'])->name('lk.admin.logout');
    $r->get('/admin/ceny-office',      [LicencjeAdminController::class, 'officePrices'])->name('lk.admin.prices');      // Office A3 / A5: ceny (kierownik / admin)
    $r->post('/admin/ceny-office',     [LicencjeAdminController::class, 'officePricesSave'])->name('lk.admin.prices.save');
});

$r->get('/logowanie',                  [LicencjeKursantController::class, 'loginForm'])->name('lk.login');
$r->post('/logowanie',                 [LicencjeKursantController::class, 'loginPost'])->name('lk.login.post');
// decyzja o zmianie ceny z linku w e-mailu (także opiekun bez konta w panelu) — potwierdzana kodem
$r->get('/zmiana-ceny/{token}',        [LicencjeKursantController::class, 'priceLink'])->name('lk.price.link');
$r->post('/zmiana-ceny/{token}',       [LicencjeKursantController::class, 'priceLinkPost'])->name('lk.price.link.post');
$r->group(['lp.auth'], function (Router $r) {
    $r->post('/wyloguj',               [LicencjeKursantController::class, 'logout'])->name('lk.logout');
    $r->get('/kontakt',                [LicencjeKursantController::class, 'contactForm'])->name('lk.contact');
    $r->post('/kontakt',               [LicencjeKursantController::class, 'contactPost'])->name('lk.contact.post');
    $r->post('/kontakt/kod',           [LicencjeKursantController::class, 'contactCode'])->name('lk.contact.code');
});
$r->group(['lp.auth', 'lp.contact'], function (Router $r) {
    $r->get('/',                       [LicencjeKursantController::class, 'index'])->name('lk.index');
    $r->get('/licencja/{product}',     [LicencjeKursantController::class, 'licence'])->name('lk.licence');
    $r->get('/office',                 [LicencjeKursantController::class, 'office'])->name('lk.office');
    $r->get('/programy',               [LicencjeKursantController::class, 'programs'])->name('lk.programs');
    $r->get('/materialy',              [LicencjeKursantController::class, 'materials'])->name('lk.materials');
    $r->get('/sprawy',                 [LicencjeKursantController::class, 'cases'])->name('lk.cases');
    $r->get('/sprawy/{kind}/{id}',     [LicencjeKursantController::class, 'caseView'])->name('lk.case');          // podgląd sprawy AM / zgłoszenia AM…
    $r->post('/sprawy/zgloszenie/{id}/odpowiedz', [LicencjeKursantController::class, 'caseReply'])->name('lk.case.reply');
    $r->get('/produkty',               [LicencjeKursantController::class, 'products'])->name('lk.products');
    $r->get('/konto',                  [LicencjeKursantController::class, 'account'])->name('lk.account');
    $r->get('/dysk',                   [LicencjeKursantController::class, 'drive'])->name('lk.drive');             // Dysk Online (pilotaż, sdata.net.pl)
    $r->post('/dysk/zgloszenie',       [LicencjeKursantController::class, 'driveJoin'])->name('lk.drive.join');
    $r->get('/rozliczenia',            [LicencjeKursantController::class, 'billing'])->name('lk.billing');          // karta i dane do faktur
    $r->get('/karta/{product}',        [LicencjeKursantController::class, 'cardForm'])->name('lk.card');            // zgoda na płatności kartą
    $r->post('/karta/{product}',       [LicencjeKursantController::class, 'cardPost'])->name('lk.card.post');
    $r->get('/karta/{product}/powrot', [LicencjeKursantController::class, 'cardReturn'])->name('lk.card.return');   // powrót z Przelewy24
    $r->post('/karta-usun',            [LicencjeKursantController::class, 'cardRemove'])->name('lk.card.remove');   // wycofanie zgody
    $r->get('/zgoda/{id}',             [LicencjeKursantController::class, 'consentPdf'])->name('lk.consent');
    $r->get('/dokument/{id}/{doc}',    [LicencjeKursantController::class, 'document'])->name('lk.doc');
    $r->get('/platnosci',              [LicencjeKursantController::class, 'portal'])->name('lk.portal');
    $r->post('/symulacja/zaplac/{id}', [LicencjeKursantController::class, 'simPay'])->name('lk.sim.pay');
    $r->get('/potwierdz',              [LicencjeKursantController::class, 'confirmForm'])->name('lk.confirm');
    $r->post('/potwierdz',             [LicencjeKursantController::class, 'confirmPost'])->name('lk.confirm.post');
    $r->post('/potwierdz/ponownie',    [LicencjeKursantController::class, 'confirmResend'])->name('lk.confirm.resend');
    $r->post('/potwierdz/anuluj',      [LicencjeKursantController::class, 'confirmCancel'])->name('lk.confirm.cancel');
    $r->post('/zmiana-ceny-decyzja/{id}', [LicencjeKursantController::class, 'priceDecide'])->name('lk.price.decide');
    $r->get('/wniosek/{product}',      [LicencjeKursantController::class, 'wizard'])->name('lk.wizard');
    $r->post('/wniosek/{product}',     [LicencjeKursantController::class, 'wizardPost'])->name('lk.wizard.post');
    $r->post('/wlacz/{product}',       [LicencjeKursantController::class, 'activate'])->name('lk.activate');
    $r->post('/konto-edu',             [LicencjeKursantController::class, 'edu'])->name('lk.edu');
    $r->post('/office/plan',           [LicencjeKursantController::class, 'officePlan'])->name('lk.office.plan');   // wniosek o aktywację A3 / A5
    $r->get('/claude/goodstack',       [LicencjeKursantController::class, 'goodstack'])->name('lk.goodstack');       // Claude: weryfikacja Goodstack (drugi sposób)
    $r->post('/dane/{product}',        [LicencjeKursantController::class, 'buyer'])->name('lk.buyer');
    $r->post('/rezygnacja/{product}',  [LicencjeKursantController::class, 'cancel'])->name('lk.cancel');
    $r->post('/zaplac/{id}',           [LicencjeKursantController::class, 'pay'])->name('lk.pay');
    $r->get('/platnosc/{id}',          [LicencjeKursantController::class, 'payReturn'])->name('lk.pay.return');
    $r->post('/haslo',                 [LicencjeKursantController::class, 'password'])->name('lk.password');
});
$r->dispatch();
