<?php
/**
 * crm/login.php — wejście do CRM (także /logowanieCRM, /crm-login i alias crm.feer.org.pl).
 *
 * OD 14.11.2026 CRM NIE MA WŁASNEGO LOGOWANIA. Wcześniej był to ekran „wyłącznie
 * Microsoft 365” (od razu przekierowanie do login.microsoftonline.com). Fundacja
 * odchodzi od M365 (poczta → dPoczta 13.11.2026, logowanie jednokrotne → Logto),
 * a od 13.11 Microsoft wpuszcza do SZO już tylko administratorów
 * (account_is_office_only()) — reszta zespołu nie miała jak wejść do CRM.
 *
 * Teraz do CRM wchodzi się sesją SZO:
 *  - zalogowany w SZO → prosto do CRM (uprawnienia sprawdza sam CRM: crm_require(),
 *    role, crm_user, portal_scope=crm_only — bez zmian);
 *  - niezalogowany → zwykłe logowanie SZO (/auth/login.php: e-mail i hasło, Logto,
 *    Microsoft tylko dla administratorów) z ?redirect= z powrotem do CRM.
 *
 * Alias crm.feer.org.pl: ciasteczko sesji jest przypięte do hosta, a logowanie SZO
 * (i powroty z Logto / Microsoft) odbywa się na hoście SZO. Dlatego z aliasu prowadzimy
 * na CRM na hoście SZO (szo.…/crm/…, crm_szo_main_base()) — tam sesja SZO istnieje albo require_login()
 * odeśle na logowanie i z powrotem. Ta sama zasada w require_login() (includes/auth.php).
 *
 * Ten plik celowo nic nie wyświetla: brak formularza = brak drugiej, osobnej ścieżki
 * uwierzytelniania do utrzymania (WCAG i bezpieczeństwo pilnuje jeden ekran SZO).
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

auth_start();

$crm_alias = crm_alias_base_url();   // "https://crm.feer.org.pl" albo null

// Dokąd w CRM po zalogowaniu — ścieżka "/crm/…" (tylko adresy CRM, inaczej pulpit CRM)
$path = crm_szo_target_path((string)($_GET['redirect'] ?? ''));

if (current_user()) {
    // Sesja na tym hoście już jest — zostajemy na nim
    header('Location: ' . ($crm_alias ?? APP_URL) . $path);
    exit;
}

if ($crm_alias !== null) {
    // Alias bez sesji → CRM na głównym hoście SZO (tam require_login() poprowadzi na logowanie i z powrotem).
    // NIE APP_URL: na mydevil APP_URL = host żądania, czyli znów crm.* → pętla przekierowań.
    header('Location: ' . crm_szo_main_base() . $path);
    exit;
}

header('Location: ' . APP_URL . '/auth/login.php?redirect=' . urlencode(APP_URL . $path));
exit;
