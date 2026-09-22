<?php
/**
 * auth/redmine_callback.php — odbiór kodu autoryzacji OAuth z Redmine.
 * Wymienia code na tokeny, zapisuje je dla użytkownika i wraca na stronę konta.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/redmine.php';

require_login();
$uid  = (int)(current_user()['id'] ?? 0);
$back = APP_URL . '/auth/redmine_account.php';

if (!empty($_GET['error'])) {
    flash_set('danger', 'Autoryzacja odrzucona: ' . h((string)$_GET['error']));
    header('Location: ' . $back); exit;
}

$code  = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');
$saved = (string)($_SESSION['redmine_oauth_state'] ?? '');
unset($_SESSION['redmine_oauth_state']);

if ($code === '' || $state === '' || $saved === '' || !hash_equals($saved, $state)) {
    flash_set('danger', 'Nieprawidłowa odpowiedź autoryzacji (state).');
    header('Location: ' . $back); exit;
}

try {
    $tok = redmine_oauth_token_request([
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'redirect_uri' => redmine_oauth_redirect_uri(),
    ]);
    redmine_oauth_store($uid, $tok);
    flash_set('success', 'Połączono Twoje konto z Redmine.');
} catch (\Throwable $e) {
    error_log('[redmine] oauth callback uid ' . $uid . ': ' . $e->getMessage());
    flash_set('danger', 'Nie udało się połączyć z Redmine: ' . h($e->getMessage()));
}
header('Location: ' . $back);
exit;
