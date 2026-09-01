<?php
/**
 * karty30/ti/dydaktyk/raporty.php — ekran scalony z Wydrukami (2026-09-01).
 *
 * Raporty (frekwencja/rozliczenia, WUP, Kreator Raportów) i Wydruki żyły jako
 * dwie osobne strony kierownika — na życzenie użytkownika połączone w jedno
 * miejsce (wydruki.php). Ten plik zostaje jako przekierowanie: stare zakładki
 * i linki mają działać (wzorzec żetony/okresy/billing).
 */
header('Location: wydruki.php#raporty-ti', true, 302);
exit;
