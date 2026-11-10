<?php
/**
 * karty30/ti/dydaktyk/panelLicencji.php — wejście kierownika (i zastępcy) do części Administratora Licencji w Panelu Licencji
 * (licencje, ceny, wnioski AM — polecenia 2026-11-10: licencje zniknęły z panelu kierownika, ceny ustala admin albo kierownik).
 * Jednorazowy link ?wejscie_admin=… (2 minuty) — działa też dla kont tylko panelowych (bez logowania SZO).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_licencje_kursant/logic/LicencjePanelAuth.php';
$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); exit('Panel Licencji jest dostępny dla kierownika, zastępcy i administratora.'); }
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // token zapisujemy w bazie, sesja panelu dydaktyka zostaje bez zmian
$t = LicencjePanelAuth::adminHandoffCreate((int)$me['user_id'], (string)($me['name'] ?? 'kierownik'), 'panel kierownika');
try {
    require_once dirname(__DIR__, 3) . '/modules/claude_billing/logic/claudeBilling.php';
    ClaudeBilling::event(null, null, 'lic_admin_login', 'Wejście do Panelu Licencji z panelu kierownika: ' . ($me['name'] ?? '') . ', IP ' . ($_SERVER['REMOTE_ADDR'] ?? '') . '.', (string)($me['name'] ?? ''));
} catch (\Throwable $e) {}
header('Location: ' . LicencjePanelAuth::publicUrl() . '?wejscie_admin=' . $t, true, 302);
exit;
