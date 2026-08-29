<?php
/**
 * karty30/ti/holidays.php — ekran przeniesiony do panelu prowadzącego.
 *
 * Kalendarz dni wolnych/przerw ustawia teraz kierownik w panelu
 * (karty30/ti/dydaktyk/dni_wolne.php) — zapis wpisu tam automatycznie odwołuje
 * zaplanowane lekcje w danym zakresie dat (czego ten ekran nie robił).
 * Plik zostaje jako przekierowanie: stare zakładki i linki mają działać.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
header('Location: ' . rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/dni_wolne.php', true, 302);
exit;
