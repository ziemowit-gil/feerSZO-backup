<?php
/**
 * tasks/settings/teams.php — zespoły przeniesione do Struktury Organizacyjnej (org/teams.php), 2026-11-26.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
header('Location: ' . APP_URL . '/org/teams.php', true, 301);
exit;
