<?php
/**
 * karty30/ti/licencja.php — publiczny portal licencji AM5 / AM7 (bez logowania, dostęp po tokenie z e-maila / SMS):
 *   ?_route=/decyzja/{token}       licencjodawca AM5 — decyzja o udzieleniu licencji,
 *   ?_route=/wniosek/{token}       kursant AM7 — podpis wniosku o dostęp do Office 365,
 *   ?_route=/oswiadczenie/{token}  licencjobiorca — dane i oświadczenia AM5/2–3 albo AM7/4–5.
 * Logika i widoki: modules/ti_licencje_dydaktyczne (router.php, logic/LicensePublicController.php, view/public/).
 */
define('LD_PUBLIC', true);
require dirname(__DIR__, 2) . '/modules/ti_licencje_dydaktyczne/router.php';
