<?php
/**
 * karty30/ti/periods.php — ekran przeniesiony do panelu prowadzącego.
 *
 * Okresy nauczania zakłada i zamyka kierownik — ekran stoi teraz w panelu
 * (karty30/ti/dydaktyk/okresy.php), razem z protokołami, które decydują o zamknięciu.
 * Plik zostaje jako przekierowanie: stare zakładki i linki mają działać.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
header('Location: ' . rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/okresy.php', true, 302);
exit;
