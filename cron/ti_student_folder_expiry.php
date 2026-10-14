<?php
/**
 * cron/ti_student_folder_expiry.php — przypomnienia e-mail o kończącej się ważności dokumentów w teczkach kursantów TI.
 *
 * Uruchamiany przez cron/dispatcher.php (agent ti_student_folder_expiry: okno 7:00–8:00, odstęp 23 h) albo ręcznie:
 *   php cron/ti_student_folder_expiry.php            # wysyłka (tylko gdy włączona w Panel dydaktyka → Dokumenty kursantów)
 *   php cron/ti_student_folder_expiry.php --dry-run  # kto dostałby przypomnienie (nic nie wysyła, działa też przy wyłączonych)
 * Reguły: modules/ti_student_folder/logic/studentFolder.php (TiStudentFolder::runExpiry). Jedno przypomnienie na dokument.
 */
if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/mail_queue.php';
require_once $base_dir . '/modules/ti_student_folder/logic/studentFolder.php';

$dry = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    else { fwrite(STDERR, "Nieznana opcja: {$a}\n"); exit(2); }
}

$ts  = fn() => '[' . date('Y-m-d H:i:s') . ']';
$set = TiStudentFolder::settings();
echo $ts() . ' Start: ti_student_folder_expiry' . ($dry ? ' (podgląd — bez wysyłki)' : '') . ", próg {$set['days']} dni\n";
if (!$set['enabled'] && !$dry) {
    echo $ts() . " Przypomnienia wyłączone — koniec.\n";
    exit(0);
}
$rows = TiStudentFolder::runExpiry($dry);
foreach ($rows as $r) {
    printf("  #%d konto %d — %s (do %s) → %s: %s\n", $r['file_id'], $r['account_id'], $r['title'], $r['valid_until'], $r['to'] ?: '—', $r['action']);
}
$sent = count(array_filter($rows, fn($r) => $r['action'] === 'send'));
echo $ts() . ' Koniec: dokumentów ' . count($rows) . ($dry ? ", do wysłania {$sent}" : ", wysłano {$sent}") . "\n";
