<?php
/**
 * modules/cloudDav/router.php — „Kalendarz i kontakty z chmury” (CalDAV, CardDAV z Nextcloud w dPoczcie, telefonie i programach).
 *
 * Adresy (czyste, .htaccess: ^kalendarz-kontakty(/.*)?$ → ten plik), tylko dla zalogowanych:
 *   GET  /kalendarz-kontakty          — wybór urządzenia, oczekiwanie na potwierdzenie w chmurze, gotowe dane do wklejenia;
 *   POST /kalendarz-kontakty/polacz   — początek Login Flow v2 (hasło aplikacji w chmurze);
 *   POST /kalendarz-kontakty/sprawdz  — czy użytkownik potwierdził dostęp (odpytywane z przeglądarki co kilka sekund);
 *   GET  /kalendarz-kontakty/profil   — profil .mobileconfig dla iPhone'a, iPada i Maca;
 *   POST /kalendarz-kontakty/zakoncz  — usuń hasło aplikacji z sesji.
 * Bez przepisywania: /modules/cloudDav/router.php?_route=/kalendarz-kontakty. CSRF dla POST sprawdza Router.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/CloudDavController.php';

$r = new Router('/modules/cloudDav/router.php', 'path', rtrim(APP_URL, '/'));

$r->group(['auth'], function (Router $r) {
    $r->get('/kalendarz-kontakty',          [CloudDavController::class, 'index'])->name('dav.index');
    $r->post('/kalendarz-kontakty/polacz',  [CloudDavController::class, 'start'])->name('dav.start');
    $r->post('/kalendarz-kontakty/sprawdz', [CloudDavController::class, 'poll'])->name('dav.poll');
    $r->get('/kalendarz-kontakty/profil',   [CloudDavController::class, 'profile'])->name('dav.profile');
    $r->post('/kalendarz-kontakty/zakoncz', [CloudDavController::class, 'forget'])->name('dav.forget');
});

$r->dispatch();
