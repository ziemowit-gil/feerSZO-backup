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
 * Widoki: view/devicesSection.php (sekcja #urzadzenia), view/m365Status.php (karta w sekcji #uslugi) — dołącza tozsamosc/index.php.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/AccountSecurityController.php';

$r = new Router('/modules/account_security/router.php');
$r->group(['auth'], function (Router $r) {
    $r->post('/sesje/zakoncz', [AccountSecurityController::class, 'endSession'])->name('account_security.session_end');
    $r->post('/sesje/zakoncz-pozostale', [AccountSecurityController::class, 'endOtherSessions'])->name('account_security.sessions_end_others');
    $r->post('/urzadzenia/zapomnij', [AccountSecurityController::class, 'forgetDevice'])->name('account_security.device_forget');
    $r->post('/to-nie-ja', [AccountSecurityController::class, 'lockdown'])->name('account_security.lockdown');
    $r->post('/m365/sprawdz', [AccountSecurityController::class, 'checkM365'])->name('account_security.m365_check');
});
$r->dispatch();
