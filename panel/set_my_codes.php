<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

require_login();

// Przenieś na bramkę IKA w trybie setup
header('Location: ' . APP_URL . '/contracts/ika_gate.php?mode=setup&to=' . urlencode(APP_URL . '/index.php'));
exit;
