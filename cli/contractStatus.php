#!/usr/bin/env php
<?php
/**
 * cli/contractStatus.php — administracyjne cofnięcie / ustawienie DOWOLNEGO statusu umowy z konsoli.
 *
 * Omija walidatory przejść (STATUS_TRANSITIONS, ContractStatusTransitionValidator, wymagane pliki powierzenia)
 * — to narzędzie awaryjne dla administratora serwera (np. omyłkowe „Zakończona” / „Anulowana”, z których
 * w aplikacji nie ma wyjścia). Każda zmiana trafia do dziennika umowy (contract_audit_log) z adnotacją „CLI”.
 *
 * Użycie:
 *   php cli/contractStatus.php <typ> <numer|id>                         # podgląd: status + historia zmian statusu
 *   php cli/contractStatus.php <typ> <numer|id> --undo --yes            # cofnij ostatnią zmianę statusu
 *   php cli/contractStatus.php <typ> <numer|id> --to=projekt --yes      # ustaw dowolny status
 *   <typ> = zlecenie | wolontariat | powierzenie | … albo „-” (szukaj numeru we wszystkich typach)
 *   opcje: --reason="…"        powód (do dziennika)
 *          --as=email          administrator wykonujący zmianę (musi mieć rolę admin; trafia do dziennika)
 *          --db=/sciezka.db    inna baza niż z config.php (np. kopia do próby)
 * Bez --yes nic nie zapisuje (tylko pokazuje, co by zrobił).
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }

$args = array_slice($argv, 1);
$opt  = fn(string $k) => array_values(array_filter(array_map(fn($a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : null, $args), fn($v) => $v !== null))[0] ?? null;
$yes  = in_array('--yes', $args, true);
$undo = in_array('--undo', $args, true);
$to   = $opt('to');
$why  = (string)($opt('reason') ?? '');
$as   = $opt('as');
$dbOpt = $opt('db');
$pos  = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
[$type, $ident] = array_pad($pos, 2, '');

if ($type === '' || $ident === '') {
    fwrite(STDERR, "Użycie: php cli/contractStatus.php <typ|-> <numer|id> [--undo|--to=status] [--reason=…] [--as=email] [--yes]\n");
    exit(2);
}
if ($dbOpt !== null) {
    if (!is_file($dbOpt)) { fwrite(STDERR, "Brak pliku bazy: {$dbOpt}\n"); exit(2); }
    define('DB_PATH', $dbOpt);
}
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/contract_status/logic/contractStatus.php';   // wspólne z widokiem admina

// ── Umowa ─────────────────────────────────────────────────────────────────────
if ($type !== '-' && !isset(CONTRACT_TYPES[$type])) {
    fwrite(STDERR, "Nieznany typ umowy: {$type}. Dostępne: " . implode(', ', array_keys(CONTRACT_TYPES)) . "\n"); exit(2);
}
$found = array_values(array_filter(contractStatusFind($type, $ident),
    fn($f) => (string)$f[1]['numer_umowy'] === $ident || (string)($f[1]['nr_rejestru'] ?? '') === $ident || (ctype_digit($ident) && (int)$f[1]['id'] === (int)$ident)));
if (!$found)          { fwrite(STDERR, "Nie znaleziono umowy: {$ident}\n"); exit(1); }
if (count($found) > 1) {
    fwrite(STDERR, "Numer {$ident} pasuje do kilku umów — podaj typ:\n");
    foreach ($found as [$t, $r]) fwrite(STDERR, "  {$t} #{$r['id']} ({$r['status']})\n");
    exit(1);
}
[$type, $row] = $found[0];
$id  = (int)$row['id'];
$cur = (string)($row['status'] ?? '');
$lbl = fn(string $s) => (STATUS_LABELS[$s]['label'] ?? $s) . " [{$s}]";

echo "Umowa:  " . (CONTRACT_TYPES[$type] ?? $type) . " #{$id}  {$row['numer_umowy']}\n";
echo "Status: " . $lbl($cur) . "\n";

// ── Historia zmian statusu (z dziennika) ──────────────────────────────────────
$hist = contractStatusHistory($type, $id);
echo "\nOstatnie zmiany statusu:\n";
if (!$hist) echo "  (brak w dzienniku)\n";
foreach (array_slice($hist, 0, 10) as $h) echo "  {$h['at']}  {$h['who']}:  " . ($h['from'] !== '' ? $h['from'] : '?') . " → {$h['to']}\n";

if (!$undo && $to === null) exit(0);

// ── Docelowy status ───────────────────────────────────────────────────────────
if ($undo) {
    $last = $hist[0] ?? null;
    if (!$last || $last['from'] === '') {
        fwrite(STDERR, "\nNie da się ustalić poprzedniego statusu z dziennika — użyj --to=<status>.\n"); exit(1);
    }
    if ($last['to'] !== $cur) fwrite(STDERR, "\nUwaga: ostatni wpis dziennika kończy się na „{$last['to']}”, a umowa ma „{$cur}”.\n");
    $to = $last['from'];
}
if (!isset(STATUS_LABELS[$to])) {
    fwrite(STDERR, "\nNieznany status: {$to}. Dostępne: " . implode(', ', array_keys(STATUS_LABELS)) . "\n"); exit(2);
}
if ($to === $cur) { echo "\nUmowa ma już status " . $lbl($to) . " — nic do zrobienia.\n"; exit(0); }

$admin = null;
if ($as !== null) {
    $admin = db_one("SELECT id, name, role FROM users WHERE email=?", [$as]);
    if (!$admin || ($admin['role'] ?? '') !== 'admin') { fwrite(STDERR, "\n{$as} nie jest administratorem.\n"); exit(1); }
}

echo "\nZmiana: " . $lbl($cur) . "  →  " . $lbl($to) . "\n";
if (!$yes) { echo "Podgląd — dopisz --yes, aby zapisać.\n"; exit(0); }

contractStatusSet($type, $row, $to, $why, (int)($admin['id'] ?? 0), 'CLI', $undo);

echo "Zapisano. Status: " . $lbl($to) . "\n";
exit(0);
