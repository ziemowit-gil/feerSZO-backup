<?php
/**
 * Prywatny kanał iCal lekcji kursanta TI.
 *
 * Dostęp bez logowania — autoryzacja przez tajny token w adresie
 * (id + t). Adres generuje panel kursanta; subskrybują go Kalendarz
 * Google („Dodaj z adresu URL"), Apple Calendar / Outlook (webcal://).
 *
 * URL: <APP_URL>/karty30/ti/kursant/ical.php?id=<account_id>&t=<token>
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';

karty30_migrate();

$id = (int)($_GET['id'] ?? 0);
$t  = (string)($_GET['t'] ?? '');

$acc = $id ? db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$id]) : null;

if (!$acc
    || empty($acc['is_active'])
    || (string)($acc['calendar_token'] ?? '') === ''
    || !hash_equals((string)$acc['calendar_token'], $t)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nie znaleziono kalendarza.';
    exit;
}

$client   = db_one("SELECT name FROM k30_clients WHERE id=?", [$acc['client_id']]) ?: [];
$org      = defined('ORG_NAME') ? ORG_NAME : 'TI';
$cal_name = trim(($client['name'] ?? 'Kursant') . ' — lekcje ' . $org);

$ics = k30_ti_calendar_ics((int)$acc['client_id'], $cal_name);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="lekcje.ics"');
header('Cache-Control: private, max-age=900');
echo $ics;
