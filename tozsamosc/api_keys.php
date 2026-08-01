<?php
/**
 * tozsamosc/api_keys.php — Klucze API w chrome Systemu Tożsamości.
 *
 * admin/api_keys.php jest adresem przekierowującym do admin/api_manage.php
 * (zakładka Klucze API). Ten wrapper utrzymuje ten sam cel docelowy.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');
header('Location: ' . APP_URL . '/admin/api_manage.php?tab=api', true, 301);
exit;
