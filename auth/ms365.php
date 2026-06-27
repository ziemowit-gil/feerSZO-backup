<?php
/**
 * auth/ms365.php — Bezpośrednie wejście logowania Microsoft 365.
 *
 * Przekierowuje od razu do logowania MS365 (Azure AD), z pominięciem ekranu
 * wyboru metody. Używane m.in. przez alternatywny adres:
 *   https://szo-logowanie365.feer.org.pl/  → (rewrite w .htaccess) → ten plik.
 *
 * Stan OAuth jest zapisywany w sesji ORAZ w tabeli oauth_states (backup),
 * więc logowanie działa nawet gdy callback trafi na inny host niż start.
 *
 * Parametr ?redirect=<url> (musi być w obrębie APP_URL) — dokąd wrócić po
 * zalogowaniu. Domyślnie portal systemu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$APP = rtrim(APP_URL, '/');

// Cel po zalogowaniu — tylko adresy w obrębie aplikacji.
$raw      = $_GET['redirect'] ?? '';
$redirect = ($raw && str_starts_with($raw, $APP . '/')) ? $raw : $APP . '/portal.php';

// Już zalogowany → prosto do celu.
if (function_exists('current_user') && current_user()) {
    header('Location: ' . $redirect);
    exit;
}

// MS365 niedostępne (brak konfiguracji tenanta) → klasyczny ekran logowania.
if (!function_exists('ms_login_available') || !ms_login_available()) {
    header('Location: ' . $APP . '/auth/login.php?redirect=' . urlencode($redirect));
    exit;
}

// Start OAuth — przekieruj do Microsoft.
header('Location: ' . ms_auth_url($redirect));
exit;
