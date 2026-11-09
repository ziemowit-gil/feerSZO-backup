<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/router.php — panel zarządzania licencjami: kursant.feer.org.pl/licencje/ (jeden panel w stylu Portalu Kontrahenta).
 * Wejście: karty30/ti/kursant/licencje/index.php (definiuje LK_PANEL i uruchamia sesję szo_licencje — LicencjePanelAuth).
 * Logowanie: login „l” + 8 cyfr i hasło (/logowanie); uczestnik z panelu TI jest wpuszczany bez logowania. CSRF: token sesji panelu (_token).
 * Adres bazowy zależy od hosta: na kurs*. i ti*. „/licencje/” (przepisanie .htaccess na karty30/ti/kursant/), w SZO pełna ścieżka.
 */
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/LicencjeKursantController.php';

if (!defined('LK_PANEL')) { http_response_code(404); exit('Nie znaleziono strony.'); }

$uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$entry = str_starts_with($uri, '/karty30/') ? '/karty30/ti/kursant/licencje/' : '/licencje/';
$r = new Router($entry, 'query');
$r->csrfUsing(fn() => LicencjePanelAuth::check(), fn() => LicencjePanelAuth::token(), '_token');
$r->aliasMiddleware('lp.auth', function () {
    if (!LicencjePanelAuth::user()) { header('Location: ' . route('lk.login')); exit; }
});

$r->get('/logowanie',                  [LicencjeKursantController::class, 'loginForm'])->name('lk.login');
$r->post('/logowanie',                 [LicencjeKursantController::class, 'loginPost'])->name('lk.login.post');
$r->group(['lp.auth'], function (Router $r) {
    $r->get('/',                       [LicencjeKursantController::class, 'index'])->name('lk.index');
    $r->get('/produkty',               [LicencjeKursantController::class, 'products'])->name('lk.products');
    $r->post('/wyloguj',               [LicencjeKursantController::class, 'logout'])->name('lk.logout');
    $r->post('/wlacz/{product}',       [LicencjeKursantController::class, 'activate'])->name('lk.activate');
    $r->post('/konto-edu',             [LicencjeKursantController::class, 'edu'])->name('lk.edu');
    $r->post('/dane/{product}',        [LicencjeKursantController::class, 'buyer'])->name('lk.buyer');
    $r->post('/rezygnacja/{product}',  [LicencjeKursantController::class, 'cancel'])->name('lk.cancel');
    $r->post('/zaplac/{id}',           [LicencjeKursantController::class, 'pay'])->name('lk.pay');
    $r->get('/platnosc/{id}',          [LicencjeKursantController::class, 'payReturn'])->name('lk.pay.return');
    $r->post('/haslo',                 [LicencjeKursantController::class, 'password'])->name('lk.password');
});
$r->dispatch();
