<?php
/**
 * modules/collaborator_data/router.php — „Zmiana danych współpracownika”.
 *
 * Adresy: …/router.php (panel osoby: kontakt, adres, dane rozliczeniowe, dziennik zmian, wnioski SZD/SKK/UPO/DYD),
 * POST …/router.php (zapis), …/admin (akceptacja zmian i dziennik — admin/redaktor), POST …/admin/{id} (decyzja).
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
});
$r->group(['role:admin,editor'], function (Router $r) {
    $r->get('/admin',       [CollaboratorDataController::class, 'admin'])->name('cdc.admin');
    $r->post('/admin/{id}', [CollaboratorDataController::class, 'decide'])->name('cdc.decide');
});

$r->dispatch();
