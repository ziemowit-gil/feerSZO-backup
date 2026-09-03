<?php
/**
 * karty30/ti/notices.php — ekran przeniesiony do panelu prowadzącego.
 *
 * Komunikaty placówki zarządza teraz kierownik w panelu (zakładka Komunikaty,
 * karty30/ti/dydaktyk/index.php?tab=komunikaty) — ten sam ekran, na którym
 * prowadzący czytają i oznaczają komunikaty jako przeczytane.
 * Plik zostaje jako przekierowanie: stare zakładki i linki mają działać.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
header('Location: ' . rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/index.php?tab=komunikaty', true, 302);
exit;
