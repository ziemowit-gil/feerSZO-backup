<?php
/**
 * karty30/ti/wniosekDop.php — publiczny podpis elektroniczny wniosków DOP1 / DOP2 (bez logowania, po tokenie z e-maila / SMS):
 *   ?_route=/potwierdz/{token}
 * Logika i widoki: modules/ti_dop (router.php, logic/DopPublicController.php, view/public/).
 */
define('DOP_PUBLIC', true);
require dirname(__DIR__, 2) . '/modules/ti_dop/router.php';
