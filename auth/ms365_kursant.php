<?php
/**
 * auth/ms365_kursant.php — logowanie kursanta przez Microsoft 365, z powrotem
 * do nowego panelu kursanta (kursantApp / newUI).
 *
 * Start OAuth z sentinelem '__kursant_ms365__' w redirect_after; callback w
 * auth/microsoft.php rozpoznaje ten sentinel i — zamiast logować do głównej
 * sesji SZO (tabela users) — dopasowuje konto po ms_upn/ms_user_id w
 * k30_ti_student_accounts, po czym przekierowuje do kursantApp z jednorazowym
 * tokenem (ten sam mechanizm co impersonacja admina, zob. k30_imp_token_create
 * w includes/karty30.php i action=impersonate_exchange w api/v1/kursant_student.php).
 *
 * Zakłada, że konta M365 kursantów (includes/ti_online.php, tenant szkoleniowy)
 * są w tym samym tenancie co logowanie M365 personelu (brak ti_m365 use_own_tenant) —
 * w przeciwnym razie identyfikatory Microsoft się nie dopasują.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$kursant_login_url = rtrim(KURSANT_NEW_UI_URL, '/') . '/login';

if (!function_exists('ms_login_available') || !ms_login_available()) {
    header('Location: ' . $kursant_login_url . '?m365_error=unavailable');
    exit;
}

header('Location: ' . ms_auth_url('__kursant_ms365__'));
exit;
