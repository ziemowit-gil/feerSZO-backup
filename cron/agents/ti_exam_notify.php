<?php
/**
 * cron/agents/ti_exam_notify.php — powiadomienia e-mail + SMS dla kursantów o egzaminach Equi Exams.
 *
 * Co godzinę w oknie 8:00–20:00 (dyspozytor: agent ti_exam_notify) sprawdza egzaminy, które:
 *   • zostały udostępnione (is_active=1, open_at pusty albo minął)            → powiadomienie „open”,
 *   • zamykają się w ciągu najbliższych 24 h (close_at)                        → powiadomienie „closing”.
 * Każde powiadomienie dla pary (egzamin, kursant, rodzaj, kanał) idzie TYLKO RAZ — rejestr k30_ti_exam_notifications,
 * więc ponowne uruchomienie niczego nie dubluje. Reguły odbiorców i treści: modules/ti_exam_notify/logic/tiExamNotify.php.
 *
 * Włącznik: Panel dydaktyka → Egzaminy → „Powiadomienia o egzaminach” (org_setting ti_exam_notify_enabled; domyślnie wyłączone).
 *
 * Użycie:
 *   php cron/agents/ti_exam_notify.php             # wysyłka (gdy włączone)
 *   php cron/agents/ti_exam_notify.php --dry-run   # kto dostałby powiadomienie — nic nie wysyła i nic nie zapisuje
 */
if (php_sapi_name() !== 'cli' && !defined('APP_CLI')) {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__, 2);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/karty30.php';
require_once $base_dir . '/modules/ti_exam_notify/logic/tiExamNotify.php';

$dry = in_array('--dry-run', $argv ?? [], true);
$ts  = fn() => '[' . date('Y-m-d H:i:s') . ']';
echo $ts() . " Start: ti_exam_notify" . ($dry ? ' (podgląd — bez wysyłki)' : '') . "\n";

$res = ti_exam_notify_run(['dry_run' => $dry, 'force' => $dry]);

if ($res['note'] !== '') { echo $ts() . ' ' . $res['note'] . "\n"; exit(0); }

echo $ts() . " Egzaminy: otwarte {$res['exams']['open']}, zamykane w 24 h {$res['exams']['closing']}; "
   . ($dry ? 'do wysłania' : 'wysłano') . ": e-mail {$res['sent']['mail']}, SMS {$res['sent']['sms']}\n";
foreach ($res['skipped'] as $reason => $n) echo $ts() . "   pominięto ({$n}): {$reason}\n";
foreach ($res['errors'] as $err) echo $ts() . " BŁĄD {$err}\n";
echo $ts() . " Koniec.\n";
