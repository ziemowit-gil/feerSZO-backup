<?php
/**
 * modules/sso_logto/router.php — logowanie jednokrotne (Logto) i chmura plików (Nextcloud / ownCloud na osobnym serwerze).
 *
 * Adresy (czyste, .htaccess: ^sso(/.*)?$ i ^ssoadmin(/.*)?$ → ten plik):
 *   /ssoadmin                 — administrator: ustawienia Logto, webhook, SMS dla Logto, chmura, testy połączeń;
 *   /ssoadmin/ustawienia      — POST zapis;  /ssoadmin/test, /ssoadmin/test-chmury — POST testy;
 *   /ssoadmin/osoba           — POST: konto / nowe hasło / chmura dla osoby z umowy (karta w tozsamosc/access.php);
 *   /ssoadmin/pliki           — POST: anuluj / ponów kopiowanie plików z OneDrive;
 *   /sso/konto, /sso/haslo    — POST: użytkownik sam zakłada konto / zmienia hasło (Tożsamość → Logowanie jednokrotne);
 *   /sso/start                — GET: prośba po zalogowaniu „załóż konto logowania jednokrotnego i w chmurze” (raz na zalogowanie);
 *   /sso/logowanie, /sso/powrot — GET bez logowania: logowanie do SZO przez Logto (OpenID Connect; /sso/powrot = Redirect URI).
 * Webhook i SMS dla Logto to osobne pliki: api/v1/logto_webhook.php, api/v1/logto_sms.php.
 * Migrator (/migracjaadmin/pliki) ma trasę w modules/dhosting_migrator/router.php.
 * Bez przepisywania: /modules/sso_logto/router.php?_route=/ssoadmin. CSRF dla POST sprawdza Router.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/SsoController.php';

$r = new Router('/modules/sso_logto/router.php', 'path', rtrim(APP_URL, '/'));

$r->group(['auth', 'role:admin'], function (Router $r) {
    $r->get('/ssoadmin',               [SsoController::class, 'index'])->name('sso.index');
    $r->post('/ssoadmin/ustawienia',   [SsoController::class, 'saveSettings'])->name('sso.settings');
    $r->post('/ssoadmin/test',         [SsoController::class, 'testLogto'])->name('sso.test');
    $r->post('/ssoadmin/test-chmury',  [SsoController::class, 'testCloud'])->name('sso.testCloud');
    $r->post('/ssoadmin/osoba',        [SsoController::class, 'personAction'])->name('sso.person');
    $r->post('/ssoadmin/pliki',        [SsoController::class, 'filesAction'])->name('sso.files');
    $r->post('/ssoadmin/loginy',       [SsoController::class, 'cloudRename'])->name('sso.cloudRename');   // loginy w chmurze z „_” → imie.nazwisko
    $r->post('/ssoadmin/nc-uid',       [SsoController::class, 'ncUidSync'])->name('sso.ncUid');            // login chmury w Logto (customData.nc_uid)
    $r->post('/ssoadmin/hasla',        [SsoController::class, 'passwordReset'])->name('sso.passwordReset'); // reset haseł w chmurze (NC) + logowaniu jednokrotnym
});

// Grupy w chmurze z SZO (polecenie 2026-11-14: „zarządzanie grupami tylko dla nowej roli Koordynatorzy IT”): administrator albo uprawnienie
// grupy_chmury (rola koordynator_it albo nadane osobie). Synchronizacja, okno „Problemy z przypisaniami”, ignorowanie ostrzeżeń, konto w chmurze.
$r->aliasMiddleware('grupy', function (string $arg = ''): void {
    if (can_write('grupy_chmury')) return;
    http_response_code(403);
    include dirname(__DIR__, 2) . '/includes/header.php';
    echo '<div class="container mt-5"><div class="alert alert-danger" role="alert">Grupami w chmurze zarządzają Koordynatorzy IT. Nie masz tego uprawnienia.</div></div>';
    include dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
});
$r->group(['auth', 'grupy'], function (Router $r) {
    $r->get('/ssoadmin/grupy',          [SsoController::class, 'groups'])->name('sso.groups');
    $r->post('/ssoadmin/zespoly',       [SsoController::class, 'teamsSync'])->name('sso.teamsSync');          // synchronizacja teraz albo podgląd
    $r->post('/ssoadmin/grupy/ignoruj', [SsoController::class, 'assignIgnore'])->name('sso.assignIgnore');    // ignoruj / przywróć ostrzeżenie
    $r->post('/ssoadmin/grupy/konto',   [SsoController::class, 'assignAccount'])->name('sso.assignAccount');  // konto w chmurze z okna problemów
});

$r->group(['auth'], function (Router $r) {
    $r->get('/sso/start',  [SsoController::class, 'prompt'])->name('sso.prompt');   // prośba po zalogowaniu (lib/ssoPrompt.php)
    $r->post('/sso/konto', [SsoController::class, 'selfCreate'])->name('sso.selfCreate');
    $r->post('/sso/haslo', [SsoController::class, 'selfPassword'])->name('sso.selfPassword');
});

// Logowanie do SZO przez Logto (OpenID Connect) — bez middleware 'auth': to jest właśnie logowanie (lib/LogtoOidc.php).
// /sso/powrot = Redirect URI aplikacji „Traditional web” w Logto.
$r->get('/sso/logowanie', [SsoController::class, 'loginStart'])->name('sso.login');
$r->get('/sso/powrot',    [SsoController::class, 'loginCallback'])->name('sso.callback');

$r->dispatch();
