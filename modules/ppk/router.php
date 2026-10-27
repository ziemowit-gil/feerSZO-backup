<?php
/**
 * modules/ppk/router.php — PPK (Pracownicze Plany Kapitałowe) przy umowie o pracę.
 *
 * Adresy (trasa w ?_route=): /umowa/{id} (strona PPK umowy), POST /umowa/{id}/wersja, POST /umowa/{id}/druk,
 * GET /druk/{id}/pdf, POST /druk/{id}/zloz, POST /druk/{id}/anuluj.
 * Dostęp sprawdza kontroler: wgląd jak w karcie umowy, zmiany — can_edit() i umowa niezablokowana.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/PpkController.php';

$r = new Router('/modules/ppk/router.php');
$r->group(['auth'], function (Router $r) {
    $r->get('/umowa/{id}',            [PpkController::class, 'show'])->name('ppk.show');
    $r->post('/umowa/{id}/wersja',    [PpkController::class, 'addVersion'])->name('ppk.version');
    $r->post('/umowa/{id}/druk',      [PpkController::class, 'createDeclaration'])->name('ppk.declare');
    $r->get('/druk/{id}/pdf',         [PpkController::class, 'pdf'])->name('ppk.pdf');
    $r->post('/druk/{id}/zloz',       [PpkController::class, 'submit'])->name('ppk.submit');
    $r->post('/druk/{id}/anuluj',     [PpkController::class, 'cancel'])->name('ppk.cancel');
});

$r->dispatch();
