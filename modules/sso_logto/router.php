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
 *   /sso/start                — GET: prośba po zalogowaniu „załóż konto logowania jednokrotnego i w chmurze” (raz na zalogowanie).
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
});

$r->group(['auth'], function (Router $r) {
    $r->get('/sso/start',  [SsoController::class, 'prompt'])->name('sso.prompt');   // prośba po zalogowaniu (lib/ssoPrompt.php)
    $r->post('/sso/konto', [SsoController::class, 'selfCreate'])->name('sso.selfCreate');
    $r->post('/sso/haslo', [SsoController::class, 'selfPassword'])->name('sso.selfPassword');
});

$r->dispatch();
