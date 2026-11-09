<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/router.php — panel „Moje licencje” uczestnika: kursant.feer.org.pl/licencje/ (szata portalu wniosków, Metro).
 * Wejście: karty30/ti/kursant/licencje/index.php (definiuje LK_PANEL; sesja i CSRF panelu uczestnika: student_token / _token).
 * Adres bazowy zależy od hosta: na kurs*. i ti*. „/licencje/” (przepisanie .htaccess na karty30/ti/kursant/), w SZO pełna ścieżka.
 */
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/LicencjeKursantController.php';

if (!defined('LK_PANEL')) { http_response_code(404); exit('Nie znaleziono strony.'); }

$uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$entry = str_starts_with($uri, '/karty30/') ? '/karty30/ti/kursant/licencje/' : '/licencje/';
$r = new Router($entry, 'query');
$r->csrfUsing(fn() => student_token_check(), fn() => student_token(), '_token');

$r->get('/',                           [LicencjeKursantController::class, 'index'])->name('lk.index');
$r->post('/wlacz/{product}',           [LicencjeKursantController::class, 'activate'])->name('lk.activate');
$r->post('/dane/{product}',            [LicencjeKursantController::class, 'buyer'])->name('lk.buyer');
$r->post('/rezygnacja/{product}',      [LicencjeKursantController::class, 'cancel'])->name('lk.cancel');
$r->post('/zaplac/{id}',               [LicencjeKursantController::class, 'pay'])->name('lk.pay');
$r->get('/platnosc/{id}',              [LicencjeKursantController::class, 'payReturn'])->name('lk.pay.return');
$r->post('/login',                     [LicencjeKursantController::class, 'login'])->name('lk.login');
$r->dispatch();
