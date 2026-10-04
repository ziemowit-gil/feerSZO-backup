<?php
/**
 * cli/sp_push_files.php — pliki umów i pism, które są na serwerze, a nie mają kopii na SharePoincie: wysyłka hurtem.
 *
 * Uzupełnia to, co nie poszło przy uploadzie: pisma sprzed synchronizacji pism, pliki wgrane przy wyłączonym albo
 * niedostępnym SharePoincie. Kopia na SharePoincie to źródło, z którego cli/sp_restore_files.php i contracts/file.php
 * odzyskują plik, gdy zniknie z uploads/.
 *
 * Użycie:
 *   php cli/sp_push_files.php                      # podgląd: które pliki nie mają kopii na SharePoincie (nic nie wysyła)
 *   php cli/sp_push_files.php --apply              # wysyła brakujące
 *   opcje: --kind=pismo|umowa|aneks|dokument|wniosek   (tylko ten rodzaj)
 *          --since=30    (tylko pliki z datą w nazwie RRRRMMDD_… z ostatnich N dni; bez daty w nazwie — zawsze)
 *          --limit=100   (maks. plików w przebiegu, domyślnie 200)
 * Plik, który SharePoint już ma (ta sama ścieżka albo ta sama nazwa gdziekolwiek w bibliotece), jest pomijany;
 * niczego na SharePoincie nie nadpisuje. Bezpieczne do wielokrotnego uruchamiania — nadaje się do crona
 * (np. --since=7 raz dziennie).
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
$kind  = ''; $since = 0; $limit = 200;
foreach ($argv as $a) {
    if (str_starts_with($a, '--kind='))  $kind  = substr($a, 7);
    if (str_starts_with($a, '--since=')) $since = max(0, (int)substr($a, 8));
    if (str_starts_with($a, '--limit=')) $limit = max(1, (int)substr($a, 8));
}
if ($kind !== '' && !isset(SpFiles::KINDS[$kind])) { fwrite(STDERR, "Nieznany rodzaj „{$kind}”. Dozwolone: " . implode(', ', array_keys(SpFiles::KINDS)) . ".\n"); exit(1); }
if (!SpFiles::canRestore()) { fwrite(STDERR, "SharePoint jest wyłączony lub niekompletnie skonfigurowany (Administracja → SharePoint).\n"); exit(2); }

$refs = SpFiles::localReferences(['kind' => $kind, 'since' => $since]);
echo "Plików na serwerze do sprawdzenia: " . count($refs)
   . ($kind !== '' ? " (rodzaj: $kind)" : '') . ($since ? " (z ostatnich $since dni)" : '')
   . ($apply ? " — tryb: WYSYŁKA\n" : " — tryb: podgląd (dodaj --apply, by wysłać)\n");

$count = ['pushed' => 0, 'missing' => 0, 'present' => 0, 'error' => 0];
$n = 0;
foreach ($refs as $r) {
    if (++$n > $limit) { echo "… pominięto resztę (limit $limit; uruchom ponownie).\n"; break; }
    $rel = $r['rel'];
    if ($apply) {
        $res = SpFiles::push($rel);
        $key = $res['status'];
        $detail = match ($key) {
            'pushed'  => 'wysłano (' . ($res['bytes'] ?? 0) . ' B) → ' . $res['item']['path'],
            'present' => 'już jest na SharePoincie',
            'no_local' => 'brak pliku na serwerze',
            default   => 'BŁĄD: ' . $res['message'],
        };
        usleep(200000);                       // łagodnie dla limitów Graph (429)
    } else {
        $res = SpFiles::locate($rel);         // podgląd: tylko sprawdza, niczego nie wysyła
        $key = match ($res['status']) { 'found', 'ambiguous' => 'present', 'missing' => 'missing', default => 'error' };
        $detail = match ($key) {
            'present' => 'już jest na SharePoincie' . ($res['item'] ? ' (' . $res['item']['path'] . ')' : ''),
            'missing' => 'BRAK na SharePoincie — zostanie wysłany',
            default   => 'BŁĄD: ' . $res['message'],
        };
    }
    $count[$key] = ($count[$key] ?? 0) + 1;
    printf("  %-62s [%s] %s\n", $rel, $r['kind'], $detail);
}
echo "\nPodsumowanie: " . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($count), $count)) . "\n";
exit($count['error'] > 0 ? 1 : 0);
