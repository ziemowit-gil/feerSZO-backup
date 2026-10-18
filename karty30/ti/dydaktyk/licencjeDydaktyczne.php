<?php
/**
 * karty30/ti/dydaktyk/licencjeDydaktyczne.php — „Licencje dydaktyczne (AM5) i Office 365 (AM7)” w panelu dydaktyka:
 * wejście routera modułu modules/ti_licencje_dydaktyczne (sesja i CSRF panelu; tylko kadra zarządzająca).
 * Adresy: licencjeDydaktyczne.php (pulpit), ?_route=/nowy, /nowy-am7, /licencje/{id}, /wzor/AM7-2 …
 */
require_once __DIR__ . '/auth.php';
define('LD_PANEL', true);
require dirname(__DIR__, 3) . '/modules/ti_licencje_dydaktyczne/router.php';
