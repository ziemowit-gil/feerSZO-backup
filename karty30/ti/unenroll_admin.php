<?php
/**
 * karty30/ti/unenroll_admin.php — ekran przeniesiony do panelu prowadzącego.
 *
 * Zatwierdzanie wniosków wypisania (małoletni) obsługuje teraz kierownik
 * w panelu (karta „Oczekujące wnioski wypisania” + historia w
 * karty30/ti/dydaktyk/klienci.php). Bezpośrednie wypisanie kursanta bez
 * wniosku (force-unenroll) robi się w zakładce Uczestnicy/Kurs w panelu.
 * Plik zostaje jako przekierowanie: stare zakładki i linki mają działać.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
header('Location: ' . rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/klienci.php', true, 302);
exit;
