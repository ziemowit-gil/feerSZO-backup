<?php
/**
 * karty30/ti/zetony.php — ekran przeniesiony do panelu prowadzącego.
 *
 * Żetony rozdaje kierownik — ekran stoi teraz w panelu
 * (karty30/ti/dydaktyk/zetony.php).
 * Plik zostaje jako przekierowanie: stare zakładki i linki mają działać.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
header('Location: ' . rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/zetony.php', true, 302);
exit;
