<?php
/**
 * tozsamosc/rodo.php — przeniesione do zakładki „Rejestr czynności" w index.php.
 * Zostawione jako przekierowanie dla ewentualnych zapisanych linków.
 */
require_once dirname(__DIR__) . '/config.php';
header('Location: ' . APP_URL . '/tozsamosc/index.php#rejestr');
exit;
