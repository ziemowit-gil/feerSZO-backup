<?php
/**
 * licencje/_mount.php — zarządzanie licencjami w części Administratora Licencji Panelu Licencji (polecenie 2026-11-10:
 * „wszystko, co związane z licencjami, znika z panelu kierownika”). Te same moduły co dawniej w panelu dydaktyka
 * (modules/ti_licencje_dydaktyczne — AM5/AM7, operacje AM11–AM24, korekty AMSK; modules/claude_billing — Płatne licencje;
 * modules/license_pool — Pula licencji Fundacji; karty30/ti/dydaktyk/licencjeKursantow.php — programy i klucze) działają
 * pod /licencje/<ten sam plik>.php: logowanie Administratora Licencji (LicencjePanelAuth::admin, SZO / Microsoft 365),
 * sesja i CSRF Panelu Licencji. Moduły wołają tylko kilka funkcji panelu dydaktyka — tu ich zamienniki (dyd_require, dyd_token…).
 * Względne adresy ../assets/ zamieniamy na /karty30/ti/assets/ (bufor wyjścia).
 */
define('LICENCE_ADMIN_MOUNT', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';      // auth_start() dla flash_set/flash_get — przy aktywnej sesji panelu nic nie zmienia
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/modules/ti_licencje_kursant/logic/LicencjePanelAuth.php';
require_once dirname(__DIR__) . '/modules/router/lib/Router.php';

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
LicencjePanelAuth::start();
$GLOBALS['LA_ADMIN'] = LicencjePanelAuth::admin();
if (!$GLOBALS['LA_ADMIN']) { header('Location: ' . LicencjePanelAuth::PATH . '?_route=' . rawurlencode('/logowanie'), true, 302); exit; }

// ── Zamienniki funkcji panelu dydaktyka (tożsamość = Administrator Licencji z SZO) ──
if (!function_exists('dyd_require')) {
    function dyd_require(): array
    {
        $a = LicencjePanelAuth::admin();
        if (!$a) { header('Location: ' . LicencjePanelAuth::PATH, true, 302); exit; }
        return ['user_id' => (int)$a['uid'], 'name' => (string)$a['name'], 'role' => 'admin', 'is_staff' => true, 'panel_role' => 'kierownik', 'licence_admin' => true];
    }
    function dyd_is_staff(): bool { return LicencjePanelAuth::admin() !== null; }
    function dyd_is_admin(): bool { return LicencjePanelAuth::admin() !== null; }
    function dyd_mode(): string { return 'kursy'; }
    function dyd_can_analytics(?array $s = null): bool { return false; }
    function dyd_token(): string { return LicencjePanelAuth::token(); }
    function dyd_token_check(): void { LicencjePanelAuth::check(); }
}

/** Adres strony administratora w Panelu Licencji (ten sam katalog). */
function la_url(string $file, array $q = []): string { return LicencjePanelAuth::PATH . $file . ($q ? '?' . http_build_query($q) : ''); }

ob_start(function (string $html): string {
    return str_replace(['href="../assets/', 'src="../assets/', "href='../assets/", "src='../assets/"],
                       ['href="/karty30/ti/assets/', 'src="/karty30/ti/assets/', "href='/karty30/ti/assets/", "src='/karty30/ti/assets/"], $html);
});
