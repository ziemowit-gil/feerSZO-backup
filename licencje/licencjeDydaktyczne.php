<?php
/** licencje/licencjeDydaktyczne.php — Licencje AM5 / AM7, operacje AM11–AM24 i korekty AMSK w Panelu Licencji (Administrator Licencji). */
require __DIR__ . '/_mount.php';
define('LD_PANEL', true);
define('LD_BASE', '/licencje/licencjeDydaktyczne.php');
require dirname(__DIR__) . '/modules/ti_licencje_dydaktyczne/router.php';
