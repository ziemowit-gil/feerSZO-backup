<?php
/**
 * modules/contract_status/router.php — „Status umowy (wycofanie)”: administrator ustawia dowolny status
 * albo cofa ostatnią zmianę, z pominięciem walidatorów przejść. Odpowiednik graficzny cli/contractStatus.php.
 *
 * Adresy (tryb query — na serwerze wszystko z .php w adresie to plik, więc trasa idzie w ?_route=, nie po „router.php/”):
 * …/router.php (wyszukiwanie), …/router.php?_route=/{typ}/{id} (umowa), POST …?_route=/{typ}/{id} (zmiana),
 * …/router.php?_route=/statusy (rozpiska statusów ze słownika umowy_statusy: grupy, typy, przejścia, liczby umów). Tylko admin.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/ContractStatusController.php';

$r = new Router('/modules/contract_status/router.php', 'query');

$r->group(['role:admin'], function (Router $r) {
    $r->get('/',               [ContractStatusController::class, 'index'])->name('cst.index');
    $r->get('/statusy',        [ContractStatusController::class, 'map'])->name('cst.map');
    $r->get('/{type}/{id}',    [ContractStatusController::class, 'show'])->name('cst.show');
    $r->post('/{type}/{id}',   [ContractStatusController::class, 'update'])->name('cst.update');
});

$r->dispatch();
