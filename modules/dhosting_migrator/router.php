<?php
/**
 * modules/dhosting_migrator/router.php — migrator kont Microsoft 365 → skrzynki pocztowe dhosting.pl.
 *
 * Adresy (czyste, .htaccess: ^migracjapoczty/?$ i ^migracjaadmin(/.*)?$ → ten plik; trasa ze ścieżki żądania, jak /seeder-test):
 *   /migracjapoczty             — użytkownik (zalogowany): sam zakłada skrzynkę z loginem Microsoft albo dostaje nowe hasło SMS-em;
 *   /migracjaadmin              — administrator (IKA): lista kont M365 obok stanu na dhosting, konfiguracja API dhosting;
 *   /migracjaadmin/zaloz        — POST: zakładanie skrzynek (hasła widoczne raz, opcjonalnie SMS);
 *   /migracjaadmin/ustawienia   — POST: zapis konfiguracji API;  /migracjaadmin/test — POST: test połączenia;
 *   /migracjaadmin/kopia        — POST: kopiowanie poczty (ponów teraz / anuluj);  /migracjapoczty/kopia — POST: to samo dla siebie.
 * Bez przepisywania (np. php -S): /modules/dhosting_migrator/router.php?_route=/migracjaadmin. CSRF dla POST sprawdza Router.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/DhostingMigratorController.php';

$r = new Router('/modules/dhosting_migrator/router.php', 'path', rtrim(APP_URL, '/'));

$r->group(['auth', 'role:admin'], function (Router $r) {
    $r->get('/migracjaadmin',             [DhostingMigratorController::class, 'index'])->name('dhm.index');
    $r->post('/migracjaadmin/zaloz',      [DhostingMigratorController::class, 'create'])->name('dhm.create');
    $r->post('/migracjaadmin/ustawienia', [DhostingMigratorController::class, 'saveSettings'])->name('dhm.settings');
    $r->post('/migracjaadmin/test',       [DhostingMigratorController::class, 'test'])->name('dhm.test');
    $r->post('/migracjaadmin/kopia',      [DhostingMigratorController::class, 'copyAction'])->name('dhm.copy');
});

$r->group(['auth'], function (Router $r) {
    $r->get('/migracjapoczty',  [DhostingMigratorController::class, 'self'])->name('dhm.self');
    $r->post('/migracjapoczty', [DhostingMigratorController::class, 'selfPost'])->name('dhm.selfPost');
    $r->post('/migracjapoczty/kopia', [DhostingMigratorController::class, 'selfCopyPost'])->name('dhm.selfCopy');
});

$r->dispatch();
