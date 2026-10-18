<?php
/** Panel dydaktyka TI — wylogowanie. */
require_once __DIR__ . '/auth.php';
dyd_logout();
// Prosto na stronę logowania z ?stay=1: zakładka kadry bez tego od razu przekierowuje do Microsoft 365,
// który po cichu logował z powrotem (office_enter.php) — wylogowanie „nic nie robiło”.
header('Location: ../login.php?tab=dydaktyk&stay=1&out=1');
exit;
