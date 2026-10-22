<?php
/**
 * modules/noclegi/logic/noclegi.php — wspólny loader modułu Noclegi (organizacja noclegów dla dydaktyków,
 * wolontariuszy i członków Fundacji). Cztery filary: zgłoszenia, rooming i logistyka, finanse i granty, self-service.
 * Rozszerza (nie dubluje) modules/ti_lodging — import flag „Noclegi kadry” przez noclegiTiSync.php.
 */
$_nocl_root = dirname(__DIR__, 3);
if (!function_exists('current_user')) require_once $_nocl_root . '/includes/auth.php';
require_once $_nocl_root . '/includes/functions.php';
$_nocl_lib = dirname(__DIR__) . '/lib/';
require_once $_nocl_lib . 'noclegiSchema.php';
require_once $_nocl_lib . 'noclegiDict.php';
require_once $_nocl_lib . 'noclegiRoles.php';
require_once $_nocl_lib . 'noclegiRequests.php';
require_once $_nocl_lib . 'noclegiRooming.php';
require_once $_nocl_lib . 'noclegiFinance.php';
require_once $_nocl_lib . 'noclegiInstruction.php';
noclMigrate();
