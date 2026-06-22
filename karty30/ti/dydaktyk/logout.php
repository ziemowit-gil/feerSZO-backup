<?php
/** Panel dydaktyka TI — wylogowanie. */
require_once __DIR__ . '/auth.php';
dyd_logout();
header('Location: login.php');
exit;
