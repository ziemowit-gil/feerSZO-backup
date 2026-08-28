<?php
/**
 * karty30/ti/raporty.php — ekran przeniesiony do panelu kierownika (nowe UI).
 *
 * Raporty TI i sprawozdanie WUP prowadzi kierownik — strona stoi teraz
 * w panelu (karty30/ti/dydaktyk/raporty.php). Plik zostaje jako
 * przekierowanie: stare zakładki i linki mają działać.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
header('Location: ' . rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/raporty.php', true, 302);
exit;
