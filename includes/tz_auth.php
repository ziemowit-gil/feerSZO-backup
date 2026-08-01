<?php
/**
 * tz_auth.php — Scentralizowany IAM: poziomy zaufania sesji, step-up challenge.
 *
 * Poziomy uwierzytelnienia (tz_auth_level):
 *   0 — anonimowy
 *   1 — hasło (ustawiane przez login_user())
 *   2 — hasło + MFA (TOTP / SMS / backup code)
 *   3 — hasło + klucz sprzętowy / WebAuthn / biometria
 *
 * Użycie w modułach:
 *   require_once '.../includes/tz_auth.php';
 *   tz_require_level(2);          // gwarantuje MFA w tej sesji
 *   tz_require_level(3, $url);    // gwarantuje klucz sprzętowy, powrót na $url
 */

define('TZ_LEVEL_PASS',     1);
define('TZ_LEVEL_MFA',      2);
define('TZ_LEVEL_HARDWARE', 3);

const TZ_LEVEL_TIMEOUT = [
    1 => 28800,  // 8 h  — samo hasło
    2 => 3600,   // 1 h  — MFA (TOTP / SMS)
    3 => 1800,   // 30 min — klucz sprzętowy (wyższe zaufanie = krótszy okno)
];

const TZ_LEVEL_LABEL = [
    1 => 'Hasło',
    2 => 'Hasło + MFA',
    3 => 'Hasło + Klucz sprzętowy',
];

// ── Odczyt / nadanie poziomu ─────────────────────────────────────────────────

/**
 * Bieżący poziom uwierzytelnienia tej sesji.
 * Zwraca 0 gdy niezalogowany, min. 1 gdy zalogowany hasłem.
 */
function tz_auth_level(): int {
    if (!isset($_SESSION['user'])) return 0;
    return (int)($_SESSION['tz_auth_level'] ?? 1);
}

/**
 * Nadaje poziom zaufania sesji po udanej weryfikacji.
 * Regeneruje ID sesji — ochrona przed Session Fixation.
 * Można tylko podnosić poziom, nigdy obniżać.
 */
function tz_grant_level(int $level): void {
    if (!function_exists('auth_start')) {
        require_once __DIR__ . '/auth.php';
    }
    auth_start();
    $current = tz_auth_level();
    if ($level > $current) {
        $_SESSION['tz_auth_level']      = $level;
        $_SESSION['tz_auth_granted_at'] = time();
        session_regenerate_id(true);
    }
}

/**
 * Inicjuje poziom 1 przy logowaniu (wywoływane przez login_user).
 * Nie regeneruje sesji — login_user() już to robi.
 */
function tz_init_on_login(): void {
    $_SESSION['tz_auth_level']      = 1;
    $_SESSION['tz_auth_granted_at'] = time();
    unset($_SESSION['tz_used_nonces'], $_SESSION['tz_pending_challenge']);
}

// ── Timeout sesji per poziom ─────────────────────────────────────────────────

/**
 * Sprawdza timeout sesji per poziom.
 * Przekroczony timeout poziomu 2/3 → degradacja do 1 (nie wylogowanie).
 * Wywołuj z require_login() lub bezpośrednio.
 */
function tz_session_timeout_check(): void {
    if (!isset($_SESSION['user'])) return;
    $level   = tz_auth_level();
    if ($level <= 1) return;
    $granted = (int)($_SESSION['tz_auth_granted_at'] ?? 0);
    if ($granted === 0) return;
    $timeout = TZ_LEVEL_TIMEOUT[$level] ?? TZ_LEVEL_TIMEOUT[1];
    if ((time() - $granted) > $timeout) {
        $_SESSION['tz_auth_level']      = 1;
        $_SESSION['tz_auth_granted_at'] = time();
    }
}

// ── Walidacja URL powrotnego ─────────────────────────────────────────────────

/**
 * Same-origin + bezpieczny schemat. Fallback → tozsamosc/index.php.
 */
function tz_validate_return_url(string $url): string {
    if (!defined('APP_URL') || $url === '') {
        return (defined('APP_URL') ? APP_URL : '') . '/tozsamosc/index.php';
    }
    // Wymagaj prefiksu APP_URL
    if (!str_starts_with($url, APP_URL . '/')) {
        return APP_URL . '/tozsamosc/index.php';
    }
    $parsed = parse_url($url);
    if (!in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
        return APP_URL . '/tozsamosc/index.php';
    }
    return $url;
}

// ── Signed challenge token ────────────────────────────────────────────────────

/**
 * Generuje HMAC-SHA256 signed challenge (ważny 5 min, jednorazowy nonce).
 * Wymaga zalogowanego użytkownika (current_user()).
 */
function tz_make_challenge(int $min_level, string $return_url, string $context = ''): string {
    if (!function_exists('current_user')) {
        require_once __DIR__ . '/auth.php';
    }
    $user = current_user();
    if (!$user) throw new \LogicException('tz_make_challenge: brak zalogowanego użytkownika');

    $return_url = tz_validate_return_url($return_url);
    $payload = json_encode([
        'uid'     => (int)$user['id'],
        'level'   => $min_level,
        'ret'     => $return_url,
        'ctx'     => $context,
        'nonce'   => bin2hex(random_bytes(8)),
        'exp'     => time() + 300,
    ]);
    $b64 = rtrim(base64_encode($payload), '=');
    $sig = hash_hmac('sha256', $b64, APP_KEY);
    return $b64 . '.' . $sig;
}

/**
 * Weryfikuje signed challenge token.
 * Rzuca RuntimeException gdy token jest nieprawidłowy, wygasły lub zużyty.
 * Rejestruje nonce — ochrona przed replay attack.
 */
function tz_verify_challenge(string $token): array {
    if (!function_exists('current_user')) {
        require_once __DIR__ . '/auth.php';
    }
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        throw new \RuntimeException('Zniekształcony token challenge');
    }
    [$b64, $sig] = $parts;

    $expected = hash_hmac('sha256', $b64, APP_KEY);
    if (!hash_equals($expected, $sig)) {
        throw new \RuntimeException('Nieprawidłowa sygnatura tokenu');
    }
    $payload = json_decode(base64_decode($b64), true);
    if (!is_array($payload)) {
        throw new \RuntimeException('Nieprawidłowy payload tokenu');
    }
    if (($payload['exp'] ?? 0) < time()) {
        throw new \RuntimeException('Token challenge wygasł. Spróbuj ponownie.');
    }

    $user = current_user();
    $uid  = (int)($payload['uid'] ?? 0);
    if (!$user || $uid !== (int)$user['id']) {
        throw new \RuntimeException('Token powiązany z innym kontem');
    }

    // Replay protection
    $used  = $_SESSION['tz_used_nonces'] ?? [];
    $nonce = $payload['nonce'] ?? '';
    if ($nonce === '' || in_array($nonce, $used, true)) {
        throw new \RuntimeException('Token challenge już został użyty');
    }
    $used[] = $nonce;
    $_SESSION['tz_used_nonces'] = array_slice($used, -30);

    return $payload;
}

// ── Egzekwowanie poziomu (główne API) ────────────────────────────────────────

/**
 * Egzekwuje minimalny poziom uwierzytelnienia w bieżącej sesji.
 * Jeśli poziom niewystarczający → generuje challenge → redirect do step_up.php.
 *
 * @param int $min_level  Wymagany poziom (2 = MFA, 3 = WebAuthn)
 * @param string|null $return_url  URL powrotu po weryfikacji (domyślnie bieżąca strona)
 * @param string $context  Nazwa modułu dla UI step_up (np. "EZD", "Admin")
 */
function tz_require_level(int $min_level, ?string $return_url = null, string $context = ''): void {
    if (!function_exists('auth_start')) {
        require_once __DIR__ . '/auth.php';
    }
    auth_start();
    tz_session_timeout_check();

    if (tz_auth_level() >= $min_level) return;

    $user = current_user();
    if (!$user) {
        require_login();
        return;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $return_url ??= APP_URL . $uri;
    $return_url = tz_validate_return_url($return_url);

    if ($context === '') {
        $context = tz_module_label_from_url($return_url);
    }

    $token = tz_make_challenge($min_level, $return_url, $context);
    header('Location: ' . APP_URL . '/tozsamosc/step_up.php?t=' . urlencode($token));
    exit;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Rozpoznaje moduł z URL powrotnego — wyświetlany na stronie step_up.
 */
function tz_module_label_from_url(string $url): string {
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $base = defined('APP_URL') ? (parse_url(APP_URL, PHP_URL_PATH) ?? '') : '';
    if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $map = [
        '/ezd/'         => 'EZD Wirtualne biurko',
        '/crm/'         => 'CRM',
        '/admin/'       => 'Panel administracyjny',
        '/ksiegowosc/'  => 'Moduł finansowy',
        '/karty30/'     => 'Karty 30',
        '/panel/'       => 'Panel wolontariusza',
        '/tozsamosc/'   => 'System Tożsamości',
        '/obiegi/'      => 'Obiegi',
        '/vpn/'         => 'VPN',
        '/helpdesk/'    => 'Helpdesk IT',
    ];
    foreach ($map as $prefix => $label) {
        if (str_starts_with($path, $prefix) || $path === rtrim($prefix, '/')) {
            return $label;
        }
    }
    return 'moduł';
}

/**
 * Etykieta wymaganego poziomu dla UI.
 */
function tz_level_label(int $level): string {
    return TZ_LEVEL_LABEL[$level] ?? "Poziom $level";
}

/**
 * Maskuje numer telefonu: +48 ••• ••• 200.
 */
function tz_mask_phone(string $p): string {
    $d = preg_replace('/\D/', '', $p);
    if (strlen($d) < 3) return $p;
    return '+' . substr($d, 0, max(0, strlen($d) - 9)) . ' ••• ••• ' . substr($d, -3);
}
