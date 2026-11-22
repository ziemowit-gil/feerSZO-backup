<?php
/**
 * REST API — CRM dla aplikacji CRM (Nuxt, crmApp/) w imieniu zalogowanego użytkownika.
 * Trasy i zasady: modules/crmApi/router.php. Przykład: GET /api/v1/crmApp.php?_route=/contacts&q=kowalski
 * (Authorization: Bearer <token z logowania kontem SZO, scope crm:read / crm:write>).
 */
declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/crmApi/router.php';
