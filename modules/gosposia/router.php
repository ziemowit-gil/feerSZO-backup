<?php
/**
 * modules/gosposia/router.php — Gosposia: sprzątanie reklam i newsletterów ze skrzynki dPoczta (polecenie 2026-11-13).
 *
 * Adresy (czyste, .htaccess: ^pomocne/gosposia(/.*)?$ → ten plik; trasa ze ścieżki żądania, jak /nowehaslo):
 *   /pomocne/gosposia                 — strona; POST …/polacz, …/rozlacz (formularze), …/przeglad, …/biala-lista[/usun], …/kosz (fetch, JSON).
 * Bez przepisywania (np. php -S): /modules/gosposia/router.php?_route=/pomocne/gosposia. Tylko zalogowani; CSRF dla POST sprawdza Router.
 * Artykuł dla użytkowników: /uslugi-it/uslugi/moja-poczta/gosposia (modules/it_portal/lib/itPortalGosposiaSeed.php).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/GosposiaController.php';

$r = new Router('/modules/gosposia/router.php', 'path', rtrim(APP_URL, '/'));

$r->group(['auth'], function (Router $r) {
    $r->get('/pomocne/gosposia',                   [GosposiaController::class, 'index'])->name('gosposia.index');
    $r->post('/pomocne/gosposia/polacz',           [GosposiaController::class, 'connect'])->name('gosposia.connect');
    $r->post('/pomocne/gosposia/rozlacz',          [GosposiaController::class, 'disconnect'])->name('gosposia.disconnect');
    $r->post('/pomocne/gosposia/przeglad',         [GosposiaController::class, 'scan'])->name('gosposia.scan');
    $r->post('/pomocne/gosposia/biala-lista',      [GosposiaController::class, 'whitelistAdd'])->name('gosposia.whitelist.add');
    $r->post('/pomocne/gosposia/biala-lista/usun', [GosposiaController::class, 'whitelistRemove'])->name('gosposia.whitelist.remove');
    $r->post('/pomocne/gosposia/kosz',             [GosposiaController::class, 'trash'])->name('gosposia.trash');
});

$r->dispatch();
