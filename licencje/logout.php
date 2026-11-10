<?php
/** licencje/logout.php — wylogowanie Administratora Licencji (link „Wyloguj” w nagłówku ekranów licencji). */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/ti_licencje_kursant/logic/LicencjePanelAuth.php';
LicencjePanelAuth::adminLogout();
header('Location: ' . LicencjePanelAuth::PATH, true, 302);
exit;
