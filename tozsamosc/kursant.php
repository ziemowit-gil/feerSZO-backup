<?php
/**
 * tozsamosc/kursant.php — dawna strona logowania uczestnika w module Tożsamość.
 * Logowanie jest jedno dla całego TI (karty30/ti/login.php, modules/ti_login): ta sama logika — pierwsze logowanie
 * kodem SMS (tozsamosc/kursant_kod.php), obrazek bezpieczeństwa, klucz sprzętowy. Stary adres przekierowuje;
 * POST kodem 307, żeby formularz z zapisanej strony nadal logował.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/modules/ti_panel_urls/lib/tiPanelUrl.php';
header('Location: ' . tiPanelUrl('ti', 'login.php'), true, $_SERVER['REQUEST_METHOD'] === 'POST' ? 307 : 302);
exit;
