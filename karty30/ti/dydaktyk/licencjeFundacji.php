<?php
/**
 * karty30/ti/dydaktyk/licencjeFundacji.php — „Pula licencji Fundacji” w panelu dydaktyka:
 * wejście routera modułu modules/license_pool (wspólny z IT SZO; sesja i CSRF panelu; kadra zarządzająca).
 */
require_once __DIR__ . '/auth.php';
define('LP_CONTEXT', 'ti');
require dirname(__DIR__, 3) . '/modules/license_pool/router.php';
