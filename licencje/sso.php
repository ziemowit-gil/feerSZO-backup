<?php
/**
 * licencje/sso.php — logowanie Administratora Licencji do Panelu Licencji przez SZO albo Microsoft 365 (polecenie 2026-11-10).
 *
 * Działa na hoście SZO (APP_URL), bo tam jest sesja SZO: wymaga zalogowania (require_login — formularz SZO z 2FA i kluczami;
 * przycisk „Microsoft 365” startuje z auth/ms365.php?redirect=…/licencje/sso.php). Dostęp: rola admin albo uprawnienie
 * „licencje_admin” (Administracja › Użytkownicy › Moduły). Potem jednorazowy link ?wejscie_admin=… (2 minuty) do Panelu Licencji
 * na jego adresie (kursant.feer.org.pl/licencje/) — panel ma własną sesję i nie czyta sesji SZO.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// zawsze na hoście SZO (sesja SZO jest tylko tam)
$appHost = strtolower((string)parse_url((string)APP_URL, PHP_URL_HOST));
$here = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if ($appHost !== '' && $here !== '' && preg_replace('/:\d+$/', '', $here) !== $appHost) {
    header('Location: ' . rtrim((string)APP_URL, '/') . '/licencje/sso.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 302);
    exit;
}
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/permissions.php';
require_once dirname(__DIR__) . '/modules/ti_licencje_kursant/logic/LicencjePanelAuth.php';

require_login();
$u = current_user() ?? [];
$via = ($_GET['via'] ?? '') === 'ms365' ? 'Microsoft 365' : 'SZO';
if (!can_read('licencje_admin')) {
    http_response_code(403);
    $name = h((string)($u['name'] ?? $u['username'] ?? ''));
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Brak dostępu — Panel Licencji</title>'
       . '<style>body{font-family:system-ui,sans-serif;max-width:560px;margin:60px auto;padding:0 16px;color:#1f2937;background:#fff}a{color:#0f5f99}</style></head><body>'
       . '<h1>Brak dostępu do Panelu Licencji</h1><p>Jesteś zalogowany w SZO jako <b>' . $name . '</b>, ale nie masz roli <b>Administrator Licencji</b>.</p>'
       . '<p>Nadaje ją administrator SZO: Administracja › Użytkownicy › Moduły › „Administrator Licencji”.</p>'
       . '<p><a href="' . h(LicencjePanelAuth::publicUrl()) . '">Wróć do Panelu Licencji</a></p></body></html>';
    exit;
}
$t = LicencjePanelAuth::adminHandoffCreate((int)($u['id'] ?? 0), (string)($u['name'] ?? $u['username'] ?? 'administrator'), $via);
try {
    require_once dirname(__DIR__) . '/modules/claude_billing/logic/claudeBilling.php';
    ClaudeBilling::event(null, null, 'lic_admin_login', 'Logowanie Administratora Licencji do Panelu Licencji (' . $via . '): ' . ($u['name'] ?? $u['username'] ?? '') . ', IP ' . ($_SERVER['REMOTE_ADDR'] ?? '') . '.', (string)($u['name'] ?? ''));
} catch (\Throwable $e) {}
header('Location: ' . LicencjePanelAuth::publicUrl() . '?wejscie_admin=' . $t, true, 302);
exit;
