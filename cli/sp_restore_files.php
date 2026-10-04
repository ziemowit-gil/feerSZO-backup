<?php
/**
 * cli/sp_restore_files.php — pliki umów, których brak w uploads/: dopasowanie z SharePointem i przywrócenie hurtem.
 *
 * Użycie:
 *   php cli/sp_restore_files.php                    # podgląd: lista brakujących i gdzie leżą na SharePoincie (nic nie zapisuje)
 *   php cli/sp_restore_files.php --apply            # przywraca pliki dopasowane jednoznacznie
 *   opcje: --type=wolontariat  (tylko ten rodzaj umowy)   --limit=100  (maks. plików w jednym przebiegu, domyślnie 200)
 * Niejednoznaczne (kilka plików o tej nazwie) i niezanalezione zostają na liście — te rozstrzyga admin/sp_contract_files.php.
 * Bezpieczne do wielokrotnego uruchamiania: istniejących plików nie nadpisuje.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Ten skrypt można uruchomić tylko z CLI.\n"); }

$base = dirname(__DIR__);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/m365.php';
require_once $base . '/modules/sp_files/logic/spFiles.php';

$apply = in_array('--apply', $argv, true);
$type  = ''; $limit = 200;
foreach ($argv as $a) {
    if (str_starts_with($a, '--type='))  $type  = substr($a, 7);
    if (str_starts_with($a, '--limit=')) $limit = max(1, (int)substr($a, 8));
}

if (!SpFiles::canRestore()) { fwrite(STDERR, "SharePoint jest wyłączony lub niekompletnie skonfigurowany (Administracja → SharePoint).\n"); exit(2); }

$by = [];
foreach (SpFiles::missing() as $r) {
    if ($type !== '' && $r['type'] !== $type) continue;
    $by[$r['rel']][] = $r;
}
ksort($by);
echo "Brakujących plików na serwerze: " . count($by) . ($type !== '' ? " (rodzaj: $type)" : '') . ($apply ? " — tryb: PRZYWRACANIE\n" : " — tryb: podgląd (dodaj --apply, by przywrócić)\n");

$count = ['restored' => 0, 'found' => 0, 'ambiguous' => 0, 'missing' => 0, 'error' => 0, 'exists' => 0];
$n = 0;
foreach ($by as $rel => $refs) {
    if (++$n > $limit) { echo "… pominięto resztę (limit $limit; uruchom ponownie).\n"; break; }
    $r = $apply ? SpFiles::restore($rel) : SpFiles::locate($rel);
    $count[$r['status']] = ($count[$r['status']] ?? 0) + 1;
    $detail = match ($r['status']) {
        'restored'  => 'przywrócono ← ' . $r['item']['path'] . ' (' . $r['method'] . ', ' . ($r['bytes'] ?? 0) . ' B)',
        'found'     => 'znaleziono: ' . $r['item']['path'] . ' (' . $r['method'] . ')',
        'ambiguous' => $r['message'] . ' ' . implode(' | ', array_map(fn($c) => $c['path'], $r['candidates'])),
        'error'     => 'BŁĄD: ' . $r['message'],
        'exists'    => 'jest już na serwerze',
        default     => 'nie znaleziono na SharePoincie',
    };
    printf("  %-70s %s\n", $rel, $detail);
}
echo "\nPodsumowanie: " . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($count), $count)) . "\n";
