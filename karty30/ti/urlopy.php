<?php
/**
 * karty30/ti/urlopy.php — ekran przeniesiony do panelu prowadzącego.
 *
 * Urlopy/dostępność wszystkich prowadzących zarządza teraz kierownik w panelu
 * (karty30/ti/dydaktyk/urlopy.php).
 * Plik zostaje jako przekierowanie: stare zakładki i linki mają działać.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
header('Location: ' . rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/urlopy.php', true, 302);
exit;
