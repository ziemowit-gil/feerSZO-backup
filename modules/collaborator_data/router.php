<?php
/**
 * modules/collaborator_data/router.php — „Zmiana danych współpracownika”.
 *
 * Adresy: …/router.php (panel osoby: kontakt, adres, dane rozliczeniowe, dziennik zmian, wnioski SZD/SKK/UPO/DYD),
 * POST …/router.php (zapis → żądanie podpisu wniosku SZD), …/podpis/{token} (podpis kodem i złożenie SZD), …/admin (akceptacja zmian i dziennik — admin/redaktor), POST …/admin/{id} (decyzja).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/CollaboratorDataController.php';

$r = new Router('/modules/collaborator_data/router.php');

$r->group(['auth'], function (Router $r) {
    $r->get('/',  [CollaboratorDataController::class, 'index'])->name('cdc.index');
    $r->post('/', [CollaboratorDataController::class, 'update'])->name('cdc.update');
    $r->get('/podpis/{token}',          [CollaboratorDataController::class, 'signForm'])->name('cdc.sign');
    $r->post('/podpis/{token}',         [CollaboratorDataController::class, 'sign'])->name('cdc.sign.do');
    $r->post('/podpis/{token}/kod',     [CollaboratorDataController::class, 'resend'])->name('cdc.sign.resend');
    $r->post('/podpis/{token}/anuluj',  [CollaboratorDataController::class, 'cancel'])->name('cdc.sign.cancel');
});
$r->group(['role:admin,editor'], function (Router $r) {
    $r->get('/admin',       [CollaboratorDataController::class, 'admin'])->name('cdc.admin');
    $r->post('/admin/{id}', [CollaboratorDataController::class, 'decide'])->name('cdc.decide');
});

$r->dispatch();
