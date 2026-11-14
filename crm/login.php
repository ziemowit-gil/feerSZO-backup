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
 * (i powroty z Logto / Microsoft) odbywa się na APP_URL. Dlatego z aliasu prowadzimy
 * na CRM pod APP_URL (szo.…/crm/…) — tam sesja SZO istnieje albo require_login()
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

// Dokąd w CRM po zalogowaniu — tylko adresy CRM (APP_URL/crm/… albo alias crm.*), inaczej pulpit CRM
$target = crm_szo_target_url((string)($_GET['redirect'] ?? ''));

if (current_user()) {
    // Sesja na tym hoście już jest — zostajemy na nim
    header('Location: ' . ($crm_alias !== null ? $crm_alias . substr($target, strlen(APP_URL)) : $target));
    exit;
}

if ($crm_alias !== null) {
    // Alias bez sesji → CRM w domenie SZO (tam require_login() poprowadzi na logowanie i z powrotem)
    header('Location: ' . $target);
    exit;
}

header('Location: ' . APP_URL . '/auth/login.php?redirect=' . urlencode($target));
exit;
