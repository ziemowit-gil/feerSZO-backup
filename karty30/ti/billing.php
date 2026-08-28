<?php
/**
 * karty30/ti/billing.php — ekran przeniesiony do panelu kierownika.
 *
 * Miesięczne rozliczenia kursantów prowadzi kierownik — ekran stoi teraz
 * w panelu dydaktyka (karty30/ti/dydaktyk/billing.php, nowe UI z sidebar).
 * Plik zostaje jako przekierowanie: stare zakładki i linki (w tym z filtrami
 * ?month=&year=&course_id=) mają działać.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/billing.php' . ($qs !== '' ? '?' . $qs : ''), true, 302);
exit;
