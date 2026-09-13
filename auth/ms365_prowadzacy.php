<?php
/**
 * auth/ms365_prowadzacy.php — logowanie prowadzącego przez Microsoft 365,
 * z powrotem do nowego (beta) panelu prowadzącego w kursantApp/newUI.
 *
 * Start OAuth z sentinelem '__prowadzacy_ms365__' w redirect_after; callback
 * w auth/microsoft.php rozpoznaje ten sentinel i — tak jak dotychczasowy
 * most karty30/ti/dydaktyk/office_enter.php do klasycznego panelu — dopasowuje
 * konto w tabeli users, sprawdza uprawnienia dydaktyka (dyd_profile_from_user())
 * i przekierowuje do kursantApp z jednorazowym tokenem typu 'dyd'
 * (k30_imp_token_create, ten sam mechanizm co logowanie kursanta przez M365
 * i impersonacja admina).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$prowadzacy_login_url = rtrim(KURSANT_NEW_UI_URL, '/') . '/logowanie-prowadzacy';

if (!function_exists('ms_login_available') || !ms_login_available()) {
    header('Location: ' . $prowadzacy_login_url . '?m365_error=unavailable');
    exit;
}

header('Location: ' . ms_auth_url('__prowadzacy_ms365__'));
exit;
