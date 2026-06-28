<?php
/**
 * auth/awaryjne.php — Osobny adres logowania awaryjnego.
 *
 * Gdy logowanie przez Microsoft 365 (Office) nie działa, użytkownik wchodzi pod
 * ten adres i loguje się loginem (e-mail) + hasłem awaryjnym ustawionym wcześniej
 * przez /auth/convert_account.php. Kieruje wprost do formularza lokalnego
 * (widok „priv"), z banerem trybu awaryjnego. Cały proces logowania (anty-brute,
 * wyjątek allow_local_fallback dla kont Office, 2FA) obsługuje auth/login.php.
 *
 * Można podpiąć krótki adres przez rewrite w .htaccess (jak ms365.php).
 */
require_once dirname(__DIR__) . '/config.php';

$qs = 'view=priv&awaryjne=1';
if (!empty($_GET['redirect'])) {
    $qs .= '&redirect=' . urlencode($_GET['redirect']);
}
header('Location: ' . rtrim(APP_URL, '/') . '/auth/login.php?' . $qs);
exit;
