<?php
/**
 * karty30/ti/kursant/accounts.php — ekran przeniesiony do panelu prowadzącego.
 *
 * Zarządzanie kontami kursantów (login/hasło, konto rodzica, konto Microsoft,
 * osoby upoważnione) prowadzi teraz kierownik w panelu
 * (karty30/ti/dydaktyk/konta.php).
 * Plik zostaje jako przekierowanie: stare zakładki i linki mają działać.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';

$target = rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/konta.php';
$qs = [];
if (!empty($_GET['guardian'])) $qs['guardian'] = (int)$_GET['guardian'];
if (!empty($_GET['authp']))    $qs['authp']    = (int)$_GET['authp'];
if (!empty($_GET['new_authp'])) $qs['new_authp'] = 1;
if ($qs) $target .= '?' . http_build_query($qs);

header('Location: ' . $target, true, 302);
exit;
