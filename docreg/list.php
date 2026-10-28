<?php
/** docreg/list.php — przeniesione do EZD (ezd/rejestrDokumentow.php); stary adres zostaje dla zakładek. */
require_once dirname(__DIR__) . '/config.php';
header('Location: ' . rtrim(APP_URL, '/') . '/ezd/rejestrDokumentow.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
