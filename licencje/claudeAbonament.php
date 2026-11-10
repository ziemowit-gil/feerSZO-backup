<?php
/** licencje/claudeAbonament.php — Płatne licencje (Claude, NordVPN, Office A3/A5) w Panelu Licencji (Administrator Licencji). */
require __DIR__ . '/_mount.php';
define('CB_PANEL', true);
define('CB_BASE', '/licencje/claudeAbonament.php');
require dirname(__DIR__) . '/modules/claude_billing/router.php';
