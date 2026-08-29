<?php
/**
 * Rejestr pełnomocnictw przeniesiony poza EZD — patrz ezd/pelnomocnictwa/index.php.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
header('Location: ' . APP_URL . '/pelnomocnictwa/print.php');
exit;
