<?php
/**
 * vpn/config.php — Pobranie własnej konfiguracji VPN (tylko aktywny dostęp).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/vpn.php';

require_login();
require_module_enabled('vpn_enabled', 'Moduł VPN');

$uid = (int)current_user()['id'];
$cur = vpn_current_for_user($uid);
if (!$cur || $cur['status'] !== 'aktywny' || trim((string)$cur['vpn_config']) === '') {
    http_response_code(404); echo 'Brak aktywnej konfiguracji VPN.'; exit;
}

// Rozszerzenie: .conf (WireGuard) domyślnie; .ovpn gdy treść wygląda na OpenVPN.
$ext  = (stripos($cur['vpn_config'], 'client') !== false && stripos($cur['vpn_config'], 'remote ') !== false) ? 'ovpn' : 'conf';
$fname = 'vpn-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $cur['vpn_username'] ?: ('user' . $uid)) . '.' . $ext;

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('X-Content-Type-Options: nosniff');
echo $cur['vpn_config'];
exit;
