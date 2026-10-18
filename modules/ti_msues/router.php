<?php
declare(strict_types=1);

/**
 * modules/ti_msues/router.php — trasy modułu „Certyfikacja MSUES 2.0” (oświadczenia kadry, checklisty, gotowość usług, paczki ZIP).
 *
 * Wejście wyłącznie przez panel dydaktyka: /karty30/ti/dydaktyk/msues.php?_route=<trasa> (sesja i CSRF panelu: dyd_token/_token;
 * plik wejścia definiuje MSUES_PANEL przed załadowaniem tego routera). Bez wejścia panelu — 404.
 *   dyd        — każdy zalogowany w panelu (prowadzący: tylko własne dane — sprawdza kontroler),
 *   dyd.staff  — kierownik / zastępca / administrator (dyd_is_staff): pulpit, uruchomienie, przełącznik, weryfikacja, usługi, ZIP, EZD.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/MsuesController.php';

if (!defined('MSUES_PANEL')) { http_response_code(404); exit('Nie znaleziono strony.'); }

$r = new Router('/karty30/ti/dydaktyk/msues.php', 'query');
$r->csrfUsing(fn() => dyd_token_check(), fn() => dyd_token(), '_token');
$r->aliasMiddleware('dyd', fn() => dyd_require());
$r->aliasMiddleware('dyd.staff', function () {
    dyd_require();
    if (!dyd_is_staff()) { header('Location: ' . Router::current()->route('msues.mine')); exit; }
});

$r->group(['dyd'], function (Router $r) {
    $r->get('/moje',                       [MsuesController::class, 'mine'])->name('msues.mine');
    $r->get('/kadra/{id}',                 [MsuesController::class, 'trainer'])->name('msues.trainer');
    $r->post('/kadra/{id}/dokumenty',      [MsuesController::class, 'documentAdd'])->name('msues.doc.add');
    $r->get('/dokumenty/{id}',             [MsuesController::class, 'documentGet'])->name('msues.doc');
    $r->post('/dokumenty/{id}/usun',       [MsuesController::class, 'documentDelete'])->name('msues.doc.delete');
    $r->post('/oswiadczenia/nowe',         [MsuesController::class, 'create'])->name('msues.decl.create');
    $r->get('/oswiadczenia/{id}',          [MsuesController::class, 'show'])->name('msues.decl');
    $r->post('/oswiadczenia/{id}',         [MsuesController::class, 'save'])->name('msues.decl.save');
    $r->post('/oswiadczenia/{id}/status',  [MsuesController::class, 'status'])->name('msues.decl.status');
    $r->post('/oswiadczenia/{id}/wersja',  [MsuesController::class, 'newVersion'])->name('msues.decl.version');
    $r->post('/oswiadczenia/{id}/usun',    [MsuesController::class, 'delete'])->name('msues.decl.delete');
    $r->post('/oswiadczenia/{id}/skan',    [MsuesController::class, 'scanUpload'])->name('msues.decl.scan.upload');
    $r->get('/oswiadczenia/{id}/skan',     [MsuesController::class, 'scanGet'])->name('msues.decl.scan');
    $r->get('/oswiadczenia/{id}/pdf',      [MsuesController::class, 'pdf'])->name('msues.decl.pdf');
    $r->get('/oswiadczenia/{id}/docx',     [MsuesController::class, 'docx'])->name('msues.decl.docx');
    $r->get('/gotowosc.json',              [MsuesController::class, 'trainerJson'])->name('msues.trainer.json');
});

$r->group(['dyd.staff'], function (Router $r) {
    $r->get('/',                           [MsuesController::class, 'index'])->name('msues.index');
    $r->post('/uruchom',                   [MsuesController::class, 'launch'])->name('msues.launch');
    $r->post('/przypomnij',                [MsuesController::class, 'remind'])->name('msues.remind');
    $r->get('/mail',                       [MsuesController::class, 'mailPreview'])->name('msues.mail');
    $r->post('/wymog',                     [MsuesController::class, 'toggle'])->name('msues.toggle');
    $r->post('/ustawienia',                [MsuesController::class, 'settings'])->name('msues.settings');
    $r->get('/oswiadczenia',               [MsuesController::class, 'declarations'])->name('msues.decls');
    $r->post('/oswiadczenia/{id}/ezd',     [MsuesController::class, 'ezd'])->name('msues.decl.ezd');
    $r->get('/uslugi/{id}',                [MsuesController::class, 'service'])->name('msues.service');
    $r->get('/uslugi/{id}/gotowosc.json',  [MsuesController::class, 'serviceJson'])->name('msues.service.json');
    $r->get('/uslugi/{id}/paczka',         [MsuesController::class, 'package'])->name('msues.package');
    $r->post('/uslugi/{id}/paczka-ezd',    [MsuesController::class, 'packageEzd'])->name('msues.package.ezd');
});

$r->dispatch();
