<?php
/**
 * karty30/ti/kursant/licencje/index.php — „Moje licencje” uczestnika (kursant.feer.org.pl/licencje/): wejście routera modules/ti_licencje_kursant.
 * Zastępuje zakładkę index.php?tab=licencje (tam przekierowanie tutaj).
 */
require_once dirname(__DIR__, 4) . '/config.php';
require_once dirname(__DIR__, 4) . '/includes/db.php';
require_once dirname(__DIR__, 4) . '/includes/functions.php';
require_once dirname(__DIR__, 4) . '/includes/karty30.php';
require_once dirname(__DIR__, 4) . '/includes/ti_messages.php';   // ti_account_log
require_once dirname(__DIR__) . '/auth.php';
if (!student_current()) { header('Location: ../login.php'); exit; }   // student_require() kieruje na login.php względnie — z /licencje/ to zły adres
define('LK_PANEL', true);
require dirname(__DIR__, 4) . '/modules/ti_licencje_kursant/router.php';
