<?php
/**
 * karty30/ti/kursant/licencje/index.php — dawne wejście Panelu Licencji. Panel działa teraz niezależnie pod /licencje/ (licencje/index.php,
 * polecenie 2026-11-10). Ten plik tylko przekierowuje: zalogowany uczestnik TI dostaje jednorazowy link wejścia (?wejscie=…),
 * pozostali trafiają na /licencje/ z tym samym adresem trasy (np. stare linki z e-maili ?_route=/zmiana-ceny/…). POST: 307 (bez utraty danych).
 */
require_once dirname(__DIR__, 4) . '/config.php';
require_once dirname(__DIR__, 4) . '/includes/db.php';
require_once dirname(__DIR__, 4) . '/includes/functions.php';
require_once dirname(__DIR__, 4) . '/modules/ti_licencje_kursant/logic/LicencjePanelAuth.php';

$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Location: ' . LicencjePanelAuth::PATH . ($qs !== '' ? '?' . $qs : ''), true, 307); exit; }
if ($qs === '') {
    require_once dirname(__DIR__) . '/auth.php';
    $ti = student_current();
    if ($ti) {
        $t = LicencjePanelAuth::handoffCreate((int)$ti['client_id'], (int)$ti['id']);
        header('Location: ' . LicencjePanelAuth::PATH . '?wejscie=' . $t, true, 302);
        exit;
    }
}
header('Location: ' . LicencjePanelAuth::PATH . ($qs !== '' ? '?' . $qs : ''), true, 302);
exit;
