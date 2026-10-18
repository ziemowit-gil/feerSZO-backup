<?php
/**
 * karty30/ti/dydaktyk/wnioskiDop.php — „Dokumenty rozliczeniowe uczestnika” w panelu dydaktyka (Finanse): DOP1, DOP2, DOP5, ROP, PRZ-P, PRZ, RKO.
 * Wejście routera modułu modules/ti_dop (sesja i CSRF panelu; tylko kadra zarządzająca).
 */
require_once __DIR__ . '/auth.php';
define('DOP_PANEL', true);
require dirname(__DIR__, 3) . '/modules/ti_dop/router.php';
