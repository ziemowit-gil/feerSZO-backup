<?php
/**
 * karty30/ti/dydaktyk/nabor.php — Nabór do grup TI w panelu dydaktyka: wejście routera modułu TI.
 *
 * Sesja i CSRF panelu dydaktyka (auth.php), trasy i logika w modules/ti (router.php, logic/nabor, view/nabor).
 * Adresy: nabor.php (zgłoszenia, kierownik), nabor.php?_route=/grupy, nabor.php?_route=/prowadzacy (prowadzący).
 */
require_once __DIR__ . '/auth.php';
define('TI_ROUTER_PANEL', true);
require dirname(__DIR__, 3) . '/modules/ti/router.php';
