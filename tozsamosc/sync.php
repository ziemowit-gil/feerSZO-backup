<?php
/**
 * tozsamosc/sync.php — Synchronizacja użytkowników w chrome Systemu Tożsamości.
 */
require_once dirname(__DIR__) . '/config.php';
if (!defined('TZ_ADMIN_CHROME')) define('TZ_ADMIN_CHROME', true);
if (!defined('TZ_ADMIN_URL'))    define('TZ_ADMIN_URL',    APP_URL . '/tozsamosc/sync.php');
require_once dirname(__DIR__) . '/admin/user_sync.php';
