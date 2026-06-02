<?php
/**
 * Autoryzacja kursantów TI — osobny system sesji, bez dostępu do reszty aplikacji.
 */

const STUDENT_SESSION_KEY = 'k30_ti_student';
const STUDENT_SESSION_TTL = 3600 * 8; // 8h

function student_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('k30_student');
        session_start();
    }
}

function student_current(): ?array {
    student_start();
    $s = $_SESSION[STUDENT_SESSION_KEY] ?? null;
    if (!$s) return null;
    if ((time() - ($s['ts'] ?? 0)) > STUDENT_SESSION_TTL) {
        unset($_SESSION[STUDENT_SESSION_KEY]);
        return null;
    }
    return $s;
}

function student_login_user(array $account): void {
    student_start();
    $_SESSION[STUDENT_SESSION_KEY] = [
        'id'        => (int)$account['id'],
        'client_id' => (int)$account['client_id'],
        'login'     => $account['login'],
        'ts'        => time(),
    ];
}

function student_logout(): void {
    student_start();
    unset($_SESSION[STUDENT_SESSION_KEY]);
    session_destroy();
}

function student_require(): array {
    $s = student_current();
    if (!$s) {
        header('Location: ' . APP_URL . '/karty30/ti/kursant/login.php');
        exit;
    }
    return $s;
}
