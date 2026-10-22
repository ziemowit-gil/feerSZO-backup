<?php
/**
 * modules/noclegi/router.php — jedyne wejście modułu Noclegi (organizacja noclegów dla dydaktyków, wolontariuszy i członków Fundacji).
 *
 * Adresy: /modules/noclegi/router.php (moje noclegi), …?_route=/nowe, /zgloszenie/5, /kolejka, /obiekty, /rooming, /budzety, /faktury, /role.
 * Role modułu (osobne od SZO): Użytkownik, Koordynator Projektu, Logistyk / Administrator — patrz lib/noclegiRoles.php.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/NoclegiController.php';

$r = new Router('/modules/noclegi/router.php');
$deny = function (string $msg): void {
    http_response_code(403);
    require_once dirname(__DIR__, 2) . '/includes/header.php';
    echo '<div class="alert alert-danger m-4" role="alert">' . htmlspecialchars($msg) . '</div>';
    require_once dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
};
$r->aliasMiddleware('nocl.user', function () use ($deny): void { require_login(); noclMigrate(); if (noclRole() === '') $deny('Noclegi: brak aktywnego konta.'); if (!noclEligible()) $deny('Moduł Noclegi jest dostępny dla osób, których umowa (wolontariat lub zlecenie) ma zaznaczone „Dop. noclegi”. Poproś opiekuna umowy o zaznaczenie tej opcji.'); });
$r->aliasMiddleware('nocl.coord', function () use ($deny): void { require_login(); noclMigrate(); if (!noclIsKoordynator()) $deny('Ten ekran jest dla Koordynatora Projektu i Logistyka.'); });
$r->aliasMiddleware('nocl.log', function () use ($deny): void { require_login(); noclMigrate(); if (!noclIsLogistyk()) $deny('Ten ekran jest dla Logistyka / Administratora modułu Noclegi.'); });

$r->group(['nocl.user'], function (Router $r) {
    $r->get('/',                          [NoclegiController::class, 'index'])->name('nocl.index');
    $r->get('/nowe',                      [NoclegiController::class, 'create'])->name('nocl.create');
    $r->post('/nowe',                     [NoclegiController::class, 'store'])->name('nocl.store');
    $r->get('/zgloszenie/{id}',           [NoclegiController::class, 'show'])->name('nocl.show');
    $r->post('/zgloszenie/{id}',          [NoclegiController::class, 'update'])->name('nocl.update');
    $r->post('/zgloszenie/{id}/status',   [NoclegiController::class, 'status'])->name('nocl.status');
});
$r->group(['nocl.coord'], function (Router $r) {
    $r->get('/kolejka',                   [NoclegiController::class, 'queue'])->name('nocl.queue');
    $r->post('/zgloszenie/{id}/akceptacja', [NoclegiController::class, 'approve'])->name('nocl.approve');
    $r->get('/budzety',                   [NoclegiController::class, 'budgets'])->name('nocl.budgets');
    $r->get('/faktury',                   [NoclegiController::class, 'invoices'])->name('nocl.invoices');
    $r->post('/faktury/{id}/status',      [NoclegiController::class, 'invoiceStatus'])->name('nocl.invoice.status');
    $r->get('/faktury/{id}/plik',         [NoclegiController::class, 'invoiceFile'])->name('nocl.invoice.file');
});
$r->group(['nocl.log'], function (Router $r) {
    $r->post('/zgloszenie/{id}/pokoj',    [NoclegiController::class, 'assign'])->name('nocl.assign');
    $r->post('/zgloszenie/{id}/budzet',   [NoclegiController::class, 'setBudget'])->name('nocl.setbudget');
    $r->post('/zgloszenie/{id}/instrukcja', [NoclegiController::class, 'sendInstruction'])->name('nocl.sendins');
    $r->get('/obiekty',                   [NoclegiController::class, 'facilities'])->name('nocl.facilities');
    $r->get('/obiekty/nowy',              [NoclegiController::class, 'facilityNew'])->name('nocl.facility.new');
    $r->post('/obiekty/nowy',             [NoclegiController::class, 'facilityStore'])->name('nocl.facility.store');
    $r->get('/obiekty/{id}',              [NoclegiController::class, 'facility'])->name('nocl.facility');
    $r->post('/obiekty/{id}',             [NoclegiController::class, 'facilityUpdate'])->name('nocl.facility.update');
    $r->post('/obiekty/{id}/pokoj',       [NoclegiController::class, 'roomSave'])->name('nocl.room.save');
    $r->get('/rooming',                   [NoclegiController::class, 'rooming'])->name('nocl.rooming');
    $r->get('/rooming/pdf',               [NoclegiController::class, 'roomingPdf'])->name('nocl.rooming.pdf');
    $r->get('/rooming/xlsx',              [NoclegiController::class, 'roomingXlsx'])->name('nocl.rooming.xlsx');
    $r->post('/import-ti',                [NoclegiController::class, 'importTi'])->name('nocl.importti');
    $r->post('/budzety',                  [NoclegiController::class, 'budgetSave'])->name('nocl.budget.save');
    $r->post('/faktury',                  [NoclegiController::class, 'invoiceStore'])->name('nocl.invoice.store');
    $r->get('/role',                      [NoclegiController::class, 'roles'])->name('nocl.roles');
    $r->post('/role',                     [NoclegiController::class, 'roleAdd'])->name('nocl.role.add');
    $r->post('/role/{id}/usun',           [NoclegiController::class, 'roleDel'])->name('nocl.role.del');
});
$r->dispatch();
