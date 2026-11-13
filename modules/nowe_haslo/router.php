<?php
/**
 * modules/nowe_haslo/router.php — samodzielna zmiana haseł do kont Fundacji (polecenie 2026-11-13).
 *
 * Adresy (czyste, .htaccess: ^nowehaslo(/.*)?$ → ten plik; trasa ze ścieżki żądania, jak /migracjapoczty):
 *   /nowehaslo            — strona (zalogowany albo bez logowania: prywatny e-mail/PESEL + adres służbowy + kod SMS);
 *   /nowehaslo/wejscie    — POST: dane gościa;   /nowehaslo/kod — POST: kod SMS;
 *   /nowehaslo/zmien      — POST: zmiana hasła (logowanie jednokrotne + chmura albo poczta);  /nowehaslo/zakoncz — POST.
 * Bez przepisywania (np. php -S): /modules/nowe_haslo/router.php?_route=/nowehaslo. CSRF dla POST sprawdza Router.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/NoweHasloController.php';

$r = new Router('/modules/nowe_haslo/router.php', 'path', rtrim(APP_URL, '/'));

// Bez middleware 'auth': kontroler wpuszcza zalogowanego albo gościa potwierdzonego danymi + kodem SMS (NoweHasloAuth).
// CSRF działa także bez logowania (sesja).
$r->get('/nowehaslo',          [NoweHasloController::class, 'index'])->name('nh.index');
$r->post('/nowehaslo/wejscie', [NoweHasloController::class, 'guestPost'])->name('nh.guest');
$r->post('/nowehaslo/kod',     [NoweHasloController::class, 'codePost'])->name('nh.code');
$r->post('/nowehaslo/zmien',   [NoweHasloController::class, 'changePost'])->name('nh.change');
$r->post('/nowehaslo/zakoncz', [NoweHasloController::class, 'endPost'])->name('nh.end');

$r->dispatch();
