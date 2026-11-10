<?php
/** licencje/konta.php — odnośniki „Kartoteka uczestnika” z ekranów licencji: w Panelu Licencji prowadzą do wyszukiwarki administratora. */
require __DIR__ . '/_mount.php';
$sid = (int)($_GET['selected'] ?? 0);
$c = $sid ? db_one("SELECT c.name FROM k30_ti_student_accounts a JOIN k30_clients c ON c.id=a.client_id WHERE a.id=?", [$sid]) : null;
header('Location: ' . LicencjePanelAuth::PATH . '?_route=' . rawurlencode('/admin') . ($c ? '&q=' . rawurlencode((string)$c['name']) : ''), true, 302);
exit;
