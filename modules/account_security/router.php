<?php
/**
 * modules/account_security/router.php — bezpieczeństwo konta w Systemie Tożsamości (/tozsamosc/).
 *
 * Adresy (tryb query: router.php?_route=/…), dostęp: zalogowana osoba, wyłącznie własne konto:
 *   POST /sesje/zakoncz            — zakończ jedną sesję (id wiersza user_sessions)
 *   POST /sesje/zakoncz-pozostale  — wyloguj pozostałe urządzenia (+ opcjonalnie Microsoft 365)
 *   POST /urzadzenia/zapomnij      — zapomnij znane urządzenie (id wiersza trusted_logins)
 *   POST /to-nie-ja                — nierozpoznana aktywność: wyloguj wszędzie, zapomnij urządzenia, zmień hasło
 *   POST /m365/sprawdz             — odczyt stanu konta Microsoft 365 z Entra ID (bez zmian w Microsoft)
 *   POST /powiadomienia            — zapis ustawień powiadomień o bezpieczeństwie
 *   POST /powiadomienia/test       — powiadomienie testowe (raz na minutę)
 *   GET  /raport, /raport.csv      — raport bezpieczeństwa kont (administrator + IKA; wejście z menu: /tozsamosc/raport.php)
 * Widoki: view/devicesSection.php (sekcja #urzadzenia), view/m365Status.php (karta w sekcji #uslugi),
 * view/notifySettings.php (ustawienie w akordeonie #bezpieczenstwo) — dołącza tozsamosc/index.php.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/AccountSecurityController.php';
require_once __DIR__ . '/logic/AccountSecurityReportController.php';

$r = new Router('/modules/account_security/router.php');
$r->group(['auth'], function (Router $r) {
    $r->post('/sesje/zakoncz', [AccountSecurityController::class, 'endSession'])->name('account_security.session_end');
    $r->post('/sesje/zakoncz-pozostale', [AccountSecurityController::class, 'endOtherSessions'])->name('account_security.sessions_end_others');
    $r->post('/urzadzenia/zapomnij', [AccountSecurityController::class, 'forgetDevice'])->name('account_security.device_forget');
    $r->post('/to-nie-ja', [AccountSecurityController::class, 'lockdown'])->name('account_security.lockdown');
    $r->post('/m365/sprawdz', [AccountSecurityController::class, 'checkM365'])->name('account_security.m365_check');
    $r->post('/powiadomienia', [AccountSecurityController::class, 'saveNotify'])->name('account_security.notify_save');
    $r->post('/powiadomienia/test', [AccountSecurityController::class, 'testNotify'])->name('account_security.notify_test');
});
$r->group(['auth', 'role:admin'], function (Router $r) {
    $r->get('/raport', [AccountSecurityReportController::class, 'page'])->name('account_security.report');
    $r->get('/raport.csv', [AccountSecurityReportController::class, 'csv'])->name('account_security.report_csv');
});
$r->dispatch();
