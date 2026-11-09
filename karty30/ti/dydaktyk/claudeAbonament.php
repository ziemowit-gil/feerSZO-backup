<?php
/**
 * karty30/ti/dydaktyk/claudeAbonament.php — „Abonament Claude” w panelu dydaktyka: wejście routera modułu modules/claude_billing
 * (sesja i CSRF panelu; kadra zarządzająca). Adresy: claudeAbonament.php (pulpit), ?_route=/abonament/{id}, ?_route=/test (test całego przebiegu).
 */
require_once __DIR__ . '/auth.php';
define('CB_PANEL', true);
require dirname(__DIR__, 3) . '/modules/claude_billing/router.php';
