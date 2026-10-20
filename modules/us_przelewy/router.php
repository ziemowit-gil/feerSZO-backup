<?php
/**
 * modules/us_przelewy/router.php — jedyne wejście modułu Przelewy do Urzędu Skarbowego (EODoK › Płatności).
 *
 * Adresy: /modules/us_przelewy/router.php (rejestr), …/router.php/nowe, …/router.php/5 (karta zobowiązania),
 * …/router.php/plik (POST — plik przelewów). Dostęp: administrator i osoby zatwierdzające do zapłaty.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/UsPrzelewyController.php';

$r = new Router('/modules/us_przelewy/router.php');
$r->aliasMiddleware('usp.access', function (): void {
    require_login();
    if (uspCanAccess()) return;
    http_response_code(403);
    require_once dirname(__DIR__, 2) . '/includes/header.php';
    echo '<div class="alert alert-danger m-4">Przelewy do US: dostęp ma administrator i osoba zatwierdzająca do zapłaty w EODoK.</div>';
    require_once dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
});

$r->group(['usp.access'], function (Router $r) {
    $r->get('/',               [UsPrzelewyController::class, 'index'])->name('usp.index');
    $r->get('/nowe',           [UsPrzelewyController::class, 'create'])->name('usp.create');
    $r->post('/nowe',          [UsPrzelewyController::class, 'store'])->name('usp.store');
    $r->post('/plik',          [UsPrzelewyController::class, 'generuj'])->name('usp.generuj');
    $r->post('/ustawienia',    [UsPrzelewyController::class, 'settings'])->name('usp.settings');
    $r->get('/{id}',           [UsPrzelewyController::class, 'show'])->name('usp.show');
    $r->post('/{id}',          [UsPrzelewyController::class, 'update'])->name('usp.update');
    $r->post('/{id}/status',   [UsPrzelewyController::class, 'status'])->name('usp.status');
    $r->post('/{id}/zatwierdzenie', [UsPrzelewyController::class, 'zatwierdzenie'])->name('usp.zatwierdzenie');
    $r->get('/{id}/plik',      [UsPrzelewyController::class, 'plik'])->name('usp.plik');
});

$r->dispatch();
