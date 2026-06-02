<?php
/**
 * POST endpoint — marks a dekretacja as complete.
 * Returns to the sprawa view or to the EZD dashboard.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł kancelarii');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location:'.APP_URL.'/ezd/index.php'); exit;
}
csrf_check();

$id      = (int)($_POST['id'] ?? 0);
$user_id = (int)current_user()['id'];

// Sprawdź czy dekretacja należy do wykonawcy lub jest admin
$dekr = db_one(
    "SELECT d.*, s.id AS sid FROM ezd_dekretacje d LEFT JOIN ezd_sprawy s ON s.id=d.sprawa_id WHERE d.id=?",
    [$id]
);

if (!$dekr) {
    flash_set('error','Dekretacja nie istnieje.');
} elseif ($dekr['wykonawca_id'] != $user_id && !is_admin()) {
    flash_set('error','Brak uprawnień do zamknięcia tej dekretacji.');
} else {
    ezd_dekretacja_complete($id, $user_id);
    flash_set('success','Zadanie oznaczone jako wykonane.');
}

// Wróć do sprawy lub dashboardu
$back = isset($dekr['sid']) && $dekr['sid']
    ? APP_URL . '/ezd/sprawy/view.php?id=' . $dekr['sid']
    : APP_URL . '/ezd/index.php';

$referer = $_SERVER['HTTP_REFERER'] ?? '';
// Prefer referer if it's a local URL
if ($referer && str_starts_with($referer, APP_URL)) {
    header('Location:' . $referer); exit;
}
header('Location:' . $back); exit;
