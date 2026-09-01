<?php
/**
 * karty30/ti/raporty.php — ekran przeniesiony do panelu kierownika (nowe UI).
 *
 * Raporty TI i sprawozdanie WUP prowadzi kierownik — strona stoi teraz
 * w panelu, scalona z Wydrukami (karty30/ti/dydaktyk/wydruki.php). Plik
 * zostaje jako przekierowanie: stare zakładki i linki mają działać.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
header('Location: ' . rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/wydruki.php#raporty-ti', true, 302);
exit;
