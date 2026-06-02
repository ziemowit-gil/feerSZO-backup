<?php
// Panel przeniesiony — redirect
$qs = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ../saas-tenent/x/migrate_all.php' . $qs, true, 301);
exit;
