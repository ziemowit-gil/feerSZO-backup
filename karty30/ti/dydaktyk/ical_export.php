<?php
/**
 * karty30/ti/dydaktyk/ical_export.php — Eksport pliku .ics z planem
 * zajęć prowadzącego (jednorazowe pobranie, nie subskrypcja).
 *
 * Osobne od ical.php (prywatny kanał webcal:// z tokenem, do subskrypcji
 * w Kalendarzu Google/Apple/Outlook) — to zwykłe pobranie pliku z sesji
 * panelu, wystawione w Wydrukach. Zwykły prowadzący dostaje zawsze WŁASNY
 * plan; kierownik może wybrać dowolnego prowadzącego (jak w pozostałych
 * wydrukach „Plan zajęć prowadzącego").
 *
 * GET: ?instructor_id=N (tylko dla kierownika — u zwykłego prowadzącego ignorowane)
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

karty30_migrate();
$me       = dyd_require();
$uid      = (int)$me['user_id'];
$is_staff = dyd_is_staff();

$target_uid = $uid;
if ($is_staff && isset($_GET['instructor_id'])) {
    $target_uid = max(0, (int)$_GET['instructor_id']);
}
if (!$target_uid) { http_response_code(400); exit('Brak wskazanego prowadzącego.'); }

$u = db_one("SELECT name FROM users WHERE id=?", [$target_uid]);
if (!$u) { http_response_code(404); exit('Nie znaleziono prowadzącego.'); }

$org      = defined('ORG_NAME') ? ORG_NAME : 'TI';
$cal_name = trim(((string)$u['name'] ?: 'Prowadzący') . ' — lekcje ' . $org);
$ics      = k30_ti_instructor_calendar_ics($target_uid, $cal_name);

ti_print_log_add('ical_export', 'Eksport iCal — ' . (string)$u['name'], 0, 0, ['instructor_id' => $target_uid], $me);

$fname = 'plan-' . preg_replace('/[^a-z0-9]+/i', '-', (string)$u['name']) . '.ics';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-cache, no-store');
echo $ics;
