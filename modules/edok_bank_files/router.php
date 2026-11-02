<?php
/**
 * modules/edok_bank_files/router.php — PDF-y z banku (wyciągi, potwierdzenia przelewów) dołączane do transakcji EODoK.
 * Interfejs jest na stronie Wyciągi bankowe (edok/wyciag.php); tu: wgrywanie, podgląd pliku, ręczne przypisanie.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/edok_bank.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/EdokBankFilesController.php';

$r = new Router('/modules/edok_bank_files/router.php');
$r->aliasMiddleware('edokAccess', function (): void { edok_require_access(); edok_migrate(); });
$r->aliasMiddleware('edokEdit', function (): void {
    edok_require_access(); edok_migrate();
    if (!(is_admin() || edok_has_role('upload') || edok_has_role('ksiegowy'))) { http_response_code(403); exit('Brak uprawnień.'); }
});

$r->group(['auth', 'edokAccess'], function (Router $r) {
    $r->get('/plik/{id}', [EdokBankFilesController::class, 'file'])->name('edokbank.file');
});
$r->group(['auth', 'edokEdit'], function (Router $r) {
    $r->post('/wgraj',     [EdokBankFilesController::class, 'upload'])->name('edokbank.upload');
    $r->post('/przypisz',  [EdokBankFilesController::class, 'assign'])->name('edokbank.assign');
    $r->post('/odepnij',   [EdokBankFilesController::class, 'detach'])->name('edokbank.detach');
    $r->post('/usun',      [EdokBankFilesController::class, 'remove'])->name('edokbank.remove');
    $r->post('/dopasuj',   [EdokBankFilesController::class, 'rematch'])->name('edokbank.rematch');
});

$r->dispatch();
