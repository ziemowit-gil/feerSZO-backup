<?php
/**
 * tozsamosc/ika_admin.php — Zarządzanie kodami IKA w chrome Systemu Tożsamości.
 */
require_once dirname(__DIR__) . '/config.php';
if (!defined('TZ_ADMIN_CHROME')) define('TZ_ADMIN_CHROME', true);
if (!defined('TZ_ADMIN_URL'))    define('TZ_ADMIN_URL',    APP_URL . '/tozsamosc/ika_admin.php');
require_once dirname(__DIR__) . '/admin/manage_cpc.php';
