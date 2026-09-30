<?php
/**
 * cli/set_online_rates.php — jednorazowe ustawienie stawek zapisów (grupy online).
 *
 *   php cli/set_online_rates.php            podgląd (nic nie zapisuje)
 *   php cli/set_online_rates.php --apply    zapis stawek + wpis w audycie
 *   php cli/set_online_rates.php --apply --col=hourly_rate_online
 *       zapisuje w polu „stawka online” zapisu zamiast zwykłej stawki zapisu
 *
 * Grupy są wskazane kodem grupy (k30_ti_courses.group_code) i nazwiskiem kursanta.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Tylko CLI.\n"); }
$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/karty30.php';
require_once $base . '/modules/audit_logs/logic/audit_logs.php';

$apply = in_array('--apply', $argv, true);
$col = 'hourly_rate';
foreach ($argv as $a) if (str_starts_with($a, '--col=')) $col = substr($a, 6);
if (!in_array($col, ['hourly_rate', 'hourly_rate_online'], true)) exit("Niedozwolona kolumna: $col\n");

// [kod grupy, fragment nazwiska, stawka zł/h, opis]
$rates = [
    ['48926', 'Krynicki',   55.0, 'Informatyka online'],
    ['23926', 'Krynicki',   70.0, 'Angielski online'],
    ['43426', 'Wiśniewski', 55.0, 'Informatyka online'],
];

karty30_migrate();
audit_logs_migrate();
$errors = 0;
echo ($apply ? "ZAPIS" : "PODGLĄD (dodaj --apply, aby zapisać)") . " — pole: $col\n";
foreach ($rates as [$code, $surname, $rate, $label]) {
    $rows = db_all("SELECT e.id, e.client_id, e.course_id, e.$col AS cur, cl.name AS client_name, c.name AS course_name
                      FROM k30_ti_enrollments e
                      JOIN k30_clients cl ON cl.id=e.client_id
                      JOIN k30_ti_courses c ON c.id=e.course_id
                     WHERE c.group_code=? AND cl.name LIKE ? AND e.status='active'", [$code, '%' . $surname . '%']);
    if (count($rows) !== 1) { echo "  ! $label (grupa $code, $surname): znaleziono " . count($rows) . " zapisów — pomijam\n"; $errors++; continue; }
    $e = $rows[0];
    printf("  %-28s %-32s %6.2f → %6.2f zł/h (%s)\n", $e['client_name'], $e['course_name'], (float)$e['cur'], $rate, $label);
    if (!$apply) continue;
    db()->prepare("UPDATE k30_ti_enrollments SET $col=? WHERE id=?")->execute([$rate, (int)$e['id']]);
    audit_log('pricing.cli_rate_set', ['course_id' => (int)$e['course_id'], 'participant_id' => (int)$e['client_id'], 'field' => $col,
        'previous' => (float)$e['cur'], 'final' => $rate, 'reason' => $label, 'by' => 'cli/set_online_rates.php'], null);
}
echo $errors ? "Uwaga: $errors pozycji pominięto.\n" : "Gotowe.\n";
exit($errors ? 1 : 0);
