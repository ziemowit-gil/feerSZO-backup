<?php
/**
 * Prywatny kanał iCal lekcji prowadzącego (dydaktyka) TI.
 *
 * Dostęp bez logowania — autoryzacja przez tajny token w adresie (uid + t).
 * Adres generuje panel dydaktyka; subskrybują go Kalendarz Google
 * („Dodaj z adresu URL"), Apple Calendar / Outlook (webcal://).
 * Każde zdarzenie ma tytuł w formie „Lekcja — kursant".
 *
 * URL: <APP_URL>/karty30/ti/dydaktyk/ical.php?uid=<user_id>&t=<token>
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';

karty30_migrate();

$uid = (int)($_GET['uid'] ?? 0);
$t   = (string)($_GET['t'] ?? '');

$row = $uid ? db_one("SELECT token FROM k30_ti_instructor_cal_tokens WHERE user_id=?", [$uid]) : null;
$tok = (string)($row['token'] ?? '');

if (!$uid || $tok === '' || !hash_equals($tok, $t)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nie znaleziono kalendarza.';
    exit;
}

$u        = db_one("SELECT name FROM users WHERE id=?", [$uid]) ?: [];
$org      = defined('ORG_NAME') ? ORG_NAME : 'TI';
$cal_name = trim(($u['name'] ?? 'Prowadzący') . ' — lekcje ' . $org);

$ics = k30_ti_instructor_calendar_ics($uid, $cal_name);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="lekcje-prowadzacego.ics"');
header('Cache-Control: private, max-age=900');
echo $ics;
