<?php
/**
 * auth/exit_context.php — Powrót do własnego konta (wyjście z kontekstu).
 *
 * Czyści aktywny kontekst pracy: admina (wcielenie / podgląd roli) ORAZ opiekuna
 * (konto dziecka). Decyduje prawdziwy użytkownik z sesji ($_SESSION['user']),
 * więc działa nawet gdy current_user() jest aktualnie „wcielony".
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

require_login();

// Wyjście dozwolone zawsze, gdy istnieje aktywny kontekst (admin lub opiekun).
if (!empty($_SESSION['ctx'])) {
    ctx_exit();
}

// portal.php sam skieruje: admina do portalu, rodzica (viewer) do panelu.
header('Location: ' . APP_URL . '/portal.php');
exit;
