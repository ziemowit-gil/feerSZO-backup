<?php
// Nowa umowa SZK — ten sam formularz co edycja (edit.php).
require_once dirname(dirname(__DIR__)) . '/config.php';
header('Location: ' . APP_URL . '/contracts/szk/edit.php' . ($_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
exit;
