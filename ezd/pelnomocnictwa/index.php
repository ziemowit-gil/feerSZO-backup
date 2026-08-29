<?php
/**
 * Rejestr pełnomocnictw przeniesiony poza EZD — jedno źródło prawdy to
 * /pelnomocnictwa/ (samodzielny moduł, bez wymogu zakładania sprawy EZD).
 * Ta ścieżka zostaje jako przekierowanie dla istniejących odnośników/zakładek.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
header('Location: ' . APP_URL . '/pelnomocnictwa/index.php');
exit;
