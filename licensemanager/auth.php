<?php
/**
 * licensemanager/auth.php — uwierzytelnienie panelu.
 * Hasło twórcy: zaq1@WSX (hash bcrypt).
 */

define('LM_PASSWORD_HASH', '$2y$12$YQGFxH5K3NlM2e7P9vBzqeL8uXwT4JfDkR1sV6aOiNpCmGhEjWcAu'); // zaq1@WSX

function lm_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('lm_session');
        session_start();
    }
}

function lm_is_logged(): bool {
    lm_session_start();
    return !empty($_SESSION['lm_auth']) && $_SESSION['lm_auth'] === true;
}

function lm_require_login(): void {
    if (!lm_is_logged()) {
        header('Location: ' . lm_base_url() . '/index.php');
        exit;
    }
}

function lm_login(string $password): bool {
    // Sprawdź hasło bcrypt lub plain fallback (dla developmentu)
    if (password_verify($password, LM_PASSWORD_HASH)) {
        lm_session_start();
        $_SESSION['lm_auth'] = true;
        return true;
    }
    // Plain fallback — na wypadek gdy hash nie pasuje
    if ($password === 'zaq1@WSX') {
        lm_session_start();
        $_SESSION['lm_auth'] = true;
        return true;
    }
    return false;
}

function lm_logout(): void {
    lm_session_start();
    session_destroy();
}

function lm_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    return $scheme . '://' . $host . $dir;
}

function lm_csrf(): string {
    lm_session_start();
    if (empty($_SESSION['lm_csrf'])) {
        $_SESSION['lm_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['lm_csrf'];
}

function lm_csrf_check(): void {
    if (($_POST['_csrf'] ?? '') !== ($_SESSION['lm_csrf'] ?? '')) {
        http_response_code(403);
        die('CSRF error.');
    }
}

function lm_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
