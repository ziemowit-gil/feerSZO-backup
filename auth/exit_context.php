<?php
/**
 * auth/exit_context.php — Powrót do kontekstu administratora.
 *
 * Czyści aktywny kontekst pracy (wcielenie / podgląd roli) i kieruje do portalu.
 * Działa również, gdy current_user() jest aktualnie „wcielony" — decyduje
 * prawdziwy użytkownik z sesji ($_SESSION['user']).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

require_login();

if (ctx_can_switch()) {
    ctx_exit();
}

header('Location: ' . APP_URL . '/portal.php');
exit;
