<?php
/**
 * cron/agents/tiLodgingChanges.php — powiadomienie o zmianie terminów szkoleń z zaznaczonym noclegiem kadry.
 *
 * Uruchamiany przez cron/dispatcher.php (agent ti_lodging_changes) albo ręcznie:
 *   php cron/agents/tiLodgingChanges.php             # wysyłka (gdy ustawiono adres)
 *   php cron/agents/tiLodgingChanges.php --dry-run   # tylko lista zmian, bez wysyłki i bez zapisu
 * Adres odbiorcy: org_setting ti_lodging_notify_email (ekran „Noclegi kadry”); pusty = wyłączone.
 * Po wysłaniu nowe terminy stają się stanem odniesienia (jedno powiadomienie na zmianę).
 */
if (php_sapi_name() !== 'cli' && !defined('APP_CLI')) {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}
$base_dir = dirname(__DIR__, 2);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/modules/ti_lodging/logic/lodging.php';

$dry = in_array('--dry-run', array_slice($argv ?? [], 1), true);
$ts  = fn() => '[' . date('Y-m-d H:i:s') . ']';
$to  = trim(org_setting('ti_lodging_notify_email'));
if (!$dry && ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL))) { echo $ts() . " Wyłączone (brak adresu ti_lodging_notify_email).\n"; exit(0); }

$names = [];
foreach (db_all("SELECT id, name FROM users") as $u) $names[(int)$u['id']] = (string)$u['name'];
$fmt = fn(array $r) => date('d.m.Y', strtotime($r[0])) . ($r[0] !== $r[1] ? ' – ' . date('d.m.Y', strtotime($r[1])) : '');
$ch = TiLodging::changed();
echo $ts() . ' Zmian: ' . count($ch) . ($dry ? " (podgląd)\n" : "\n");
if (!$ch) exit(0);

$lines = [];
foreach ($ch as $c) {
    $who = $names[$c['user_id']] ?? '—';
    $lines[] = h($who) . ', ' . h($c['course_name']) . ': było ' . h($fmt($c['old'])) . ', jest ' . ($c['new'] ? h($fmt($c['new'])) : 'brak lekcji stacjonarnych (nocleg usunięty)');
    echo $ts() . '   ' . strip_tags(end($lines)) . "\n";
}
if ($dry) exit(0);
if (!function_exists('mail_queue_add')) require_once $base_dir . '/includes/mail_queue.php';
$html = '<p>Zmieniły się terminy szkoleń stacjonarnych, dla których zaznaczono nocleg kadry:</p><ul><li>' . implode('</li><li>', $lines) . '</li></ul><p>Sprawdź rezerwacje w panelu: Noclegi kadry.</p>';
try {
    mail_queue_add($to, '', 'Noclegi kadry: zmiana terminów szkoleń', $html, '', 'ti_lodging', 0, '', false);
    foreach ($ch as $c) TiLodging::acceptChange($c['id'], $c['new']);
    echo $ts() . " Wysłano do {$to}.\n";
} catch (\Throwable $e) { echo $ts() . ' BŁĄD ' . $e->getMessage() . "\n"; exit(1); }
