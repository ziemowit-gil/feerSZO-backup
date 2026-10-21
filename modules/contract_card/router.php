<?php
/**
 * modules/contract_card/router.php — wejście funkcjonalności „Karta umowy — informacje o umowie” (wydruk A4
 * w układzie urzędowego formularza, wzór CIT-8: numerowane pola w ramkach, szare paski sekcji).
 *
 * Adres: /modules/contract_card/router.php?_route=/{typ}/{id}, np. …/router.php?_route=/zlecenie/12 — dla KAŻDEGO typu umowy.
 * Dostęp: zalogowany i viewer_owns_contract() (te same zasady co karta umowy w contracts/{typ}/view.php).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/ContractCardController.php';

$r = new Router('/modules/contract_card/router.php');

$r->group(['auth'], function (Router $r) {
    $r->get('/{type}/{id}', [ContractCardController::class, 'show'])->name('ccard.show');
});

$r->dispatch();
