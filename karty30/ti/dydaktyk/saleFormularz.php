<?php
/**
 * karty30/ti/dydaktyk/saleFormularz.php — Formularze rezerwacji sal (sale TI ↔ SRS): wejście routera modules/ti_room_srs.
 *
 * Adresy: saleFormularz.php (grupy z salą w okresie), ?_route=/formularz&course=&room=&from=&to= (formularz),
 * ?_route=/sale (przypisanie sal TI do zasobów SRS i opcji wzoru operatora).
 */
require_once __DIR__ . '/auth.php';
define('TI_ROOM_SRS_PANEL', true);
require dirname(__DIR__, 3) . '/modules/ti_room_srs/router.php';
