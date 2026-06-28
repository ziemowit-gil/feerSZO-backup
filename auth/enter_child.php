<?php
/**
 * auth/enter_child.php — Opiekun wchodzi w kontekst swojego dziecka.
 *
 * Weryfikacja powiązania (users.guardian_user_id) odbywa się w ctx_enter_child().
 * Powrót banerem „Wróć" (auth/exit_context.php). POST + CSRF.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $child = (int)($_POST['child'] ?? 0);
    if (ctx_enter_child($child)) {
        header('Location: ' . APP_URL . '/panel/index.php'); exit;
    }
    flash_set('error', 'Nie udało się wejść na konto dziecka — brak powiązania lub konto nieaktywne.');
}

header('Location: ' . APP_URL . '/panel/index.php');
exit;
