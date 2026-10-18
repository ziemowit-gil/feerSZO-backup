<?php
/**
 * karty30/ti/dydaktyk/msues.php — „Certyfikacja MSUES” w panelu dydaktyka: wejście routera modułu modules/ti_msues.
 *
 * Sesja i CSRF panelu dydaktyka (auth.php); trasy, logika i widoki w modules/ti_msues (router.php, lib/, logic/, view/).
 * Adresy: msues.php (pulpit kierownika), msues.php?_route=/moje (prowadzący), …/uslugi/{id}, …/oswiadczenia/{id}.
 */
require_once __DIR__ . '/auth.php';
define('MSUES_PANEL', true);
require dirname(__DIR__, 3) . '/modules/ti_msues/router.php';
