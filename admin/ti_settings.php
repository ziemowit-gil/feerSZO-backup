<?php
/**
 * admin/ti_settings.php — ekran przeniesiony do panelu prowadzącego.
 *
 * Dostępność panelu dydaktyka i dziennika (przełącznik + zaplanowane wyłączenia)
 * planuje kierownik — ekran stoi teraz w panelu (karty30/ti/dydaktyk/wylaczenia.php).
 * Plik zostaje jako przekierowanie: stare zakładki i linki mają działać.
 */
require_once dirname(__DIR__) . '/config.php';
header('Location: ' . rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/wylaczenia.php', true, 302);
exit;
