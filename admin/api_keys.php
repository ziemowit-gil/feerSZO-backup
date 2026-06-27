<?php
/**
 * admin/api_keys.php — Zarządzanie kluczami API zostało scalone z hubem
 * „Zarządzaj API" (admin/api_manage.php → zakładka „Klucze API").
 * Ten adres pozostaje jako przekierowanie dla istniejących linków/zakładek.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');

header('Location: ' . APP_URL . '/admin/api_manage.php?tab=api', true, 301);
exit;
