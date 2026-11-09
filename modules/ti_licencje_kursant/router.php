<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/router.php — panel zarządzania licencjami: kursant.feer.org.pl/licencje/ (jeden panel w stylu Portalu Kontrahenta).
 * Wejście: karty30/ti/kursant/licencje/index.php (definiuje LK_PANEL i uruchamia sesję szo_licencje — LicencjePanelAuth).
 * Logowanie: login „l” + 8 cyfr i hasło (/logowanie); uczestnik z panelu TI jest wpuszczany bez logowania. CSRF: token sesji panelu (_token).
 * Pierwsze wejście: brakujący e-mail / telefon (/kontakt, kod SMS). Każda operacja: kod jednorazowy (/potwierdz) — LicencjeKod.
 * Adres bazowy zależy od hosta: na kurs*. i ti*. „/licencje/” (przepisanie .htaccess na karty30/ti/kursant/), w SZO pełna ścieżka.
 */
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/LicencjeKursantController.php';
require_once __DIR__ . '/logic/LicencjeKod.php';

if (!defined('LK_PANEL')) { http_response_code(404); exit('Nie znaleziono strony.'); }

$uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$entry = str_starts_with($uri, '/karty30/') ? '/karty30/ti/kursant/licencje/' : '/licencje/';
$r = new Router($entry, 'query');
$r->csrfUsing(fn() => LicencjePanelAuth::check(), fn() => LicencjePanelAuth::token(), '_token');
$r->aliasMiddleware('lp.auth', function () {
    if (!LicencjePanelAuth::user()) { header('Location: ' . route('lk.login')); exit; }
});

// Pierwsze wejście: bez e-maila albo telefonu w kartotece — najpierw dane kontaktowe (telefon potwierdzony kodem SMS), dopiero potem operacje.
$r->aliasMiddleware('lp.contact', function () {
    $u = LicencjePanelAuth::user();
    if ($u && LicencjeKod::needsContact((int)$u['cid'])) { header('Location: ' . route('lk.contact')); exit; }
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
    $r->get('/produkty',               [LicencjeKursantController::class, 'products'])->name('lk.products');
    $r->get('/konto',                  [LicencjeKursantController::class, 'account'])->name('lk.account');
    $r->get('/dokument/{id}/{doc}',    [LicencjeKursantController::class, 'document'])->name('lk.doc');
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
    $r->post('/dane/{product}',        [LicencjeKursantController::class, 'buyer'])->name('lk.buyer');
    $r->post('/rezygnacja/{product}',  [LicencjeKursantController::class, 'cancel'])->name('lk.cancel');
    $r->post('/zaplac/{id}',           [LicencjeKursantController::class, 'pay'])->name('lk.pay');
    $r->get('/platnosc/{id}',          [LicencjeKursantController::class, 'payReturn'])->name('lk.pay.return');
    $r->post('/haslo',                 [LicencjeKursantController::class, 'password'])->name('lk.password');
});
$r->dispatch();
