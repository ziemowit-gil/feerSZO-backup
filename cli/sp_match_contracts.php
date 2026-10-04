<?php
/**
 * cli/sp_match_contracts.php — skany z SharePointa → umowy bez pliku: podgląd propozycji dopasowania (NICZEGO nie przypisuje).
 *
 * Użycie:
 *   php cli/sp_match_contracts.php                   # propozycje z ostatniego indeksu (średnia i wysoka pewność)
 *   php cli/sp_match_contracts.php --reindex         # najpierw przejrzyj bibliotekę SharePoint i odśwież indeks (może potrwać)
 *   opcje: --type=wolontariat   (tylko ten rodzaj umowy)
 *          --scope=empty|lost|all  (umowy bez pliku | z utraconym plikiem | jedne i drugie; domyślnie empty)
 *          --min=high|medium|low   (najniższa pokazywana pewność; domyślnie medium)
 *          --limit=50              (maks. umów na wydruku; domyślnie wszystkie)
 *          --json                  (wynik maszynowy zamiast tabeli)
 * Przypisanie pliku do umowy robi administrator po obejrzeniu propozycji: Administracja → „Skany z SharePointa” (admin/sp_contract_match.php).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Ten skrypt można uruchomić tylko z CLI.\n"); }

$base = dirname(__DIR__);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/m365.php';
require_once $base . '/modules/sp_files/logic/spMatcher.php';

$opt = ['type' => '', 'scope' => 'empty', 'min' => 'medium', 'limit' => 0];
foreach ($argv as $a) {
    foreach (['type', 'scope', 'min'] as $k) if (str_starts_with($a, "--{$k}=")) $opt[$k] = substr($a, strlen($k) + 3);
    if (str_starts_with($a, '--limit=')) $opt['limit'] = max(0, (int)substr($a, 8));
}
$reindex = in_array('--reindex', $argv, true);
$json    = in_array('--json', $argv, true);
if (!in_array($opt['scope'], ['empty', 'lost', 'all'], true) || !in_array($opt['min'], ['high', 'medium', 'low'], true)) {
    fwrite(STDERR, "Niepoprawna wartość --scope (empty|lost|all) lub --min (high|medium|low).\n");
    exit(2);
}
if ($opt['type'] !== '' && !isset(CONTRACT_TYPES[$opt['type']])) { fwrite(STDERR, "Nieznany rodzaj umowy w --type.\n"); exit(2); }

if ($reindex) {
    if (!SpFiles::canRestore()) { fwrite(STDERR, "SharePoint jest wyłączony lub niekompletnie skonfigurowany (Administracja → SharePoint).\n"); exit(2); }
    fwrite(STDERR, "Przeglądam bibliotekę SharePoint…\n");
    $res = SpIndex::refresh(60, true);
    while ($res['state'] === 'running') {
        fwrite(STDERR, sprintf("  przejrzano %d folderów, %d plików, w kolejce %d\n", $res['folders'], $res['files'], $res['queue']));
        $res = SpIndex::refresh(60);
    }
    fwrite(STDERR, match ($res['state']) {
        'complete' => sprintf("Indeks gotowy: %d plików w %d folderach.\n", $res['files'], $res['folders']),
        'partial'  => "Indeks niepełny: {$res['message']}\n",
        default    => "Indeksowanie nie powiodło się ({$res['state']}): {$res['message']}\n",
    });
    if (!in_array($res['state'], ['complete', 'partial'], true)) exit(1);
}

$st = SpIndex::status();
if ($st['files'] === 0 && $st['app_files'] === 0) {
    fwrite(STDERR, "Indeks plików SharePointa jest pusty — uruchom z opcją --reindex.\n");
    exit(2);
}

$plan = SpMatcher::proposals(['scope' => $opt['scope'], 'type' => $opt['type'], 'min' => $opt['min']]);
$rows = $opt['limit'] > 0 ? array_slice($plan['rows'], 0, $opt['limit']) : $plan['rows'];

if ($json) {
    echo json_encode(['stats' => $plan['stats'], 'rows' => $rows, 'unmatched' => $plan['unmatched']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$lv = SpMatcher::LEVEL_LABELS;
echo sprintf("Indeks: %d plików (stan na %s). Umów do uzupełnienia: %d, z propozycją: %d, bez propozycji: %d.\n\n",
    $st['files'], $st['last']['finished_at'] ?? '—', $plan['stats']['targets'], $plan['stats']['with_proposals'], count($plan['unmatched']));
foreach ($rows as $row) {
    $c = $row['contract'];
    echo sprintf("%s  %s  %s%s\n", $c['number'] !== '' ? $c['number'] : 'umowa #' . $c['id'], CONTRACT_TYPES[$c['type']] ?? $c['type'],
        implode(', ', $c['names']), $c['lost'] ? '  [utracony plik: ' . $c['current'] . ']' : '');
    foreach ($row['candidates'] as $cd) {
        $sz = (int)$cd['file']['size'];
        echo sprintf("    [%s %3d] %s  (%s)\n", $lv[$cd['level']], $cd['score'], $cd['file']['path'], $sz >= 1048576 ? round($sz / 1048576, 1) . ' MB' : ($sz >= 1024 ? round($sz / 1024) . ' KB' : $sz . ' B'));
        foreach ($cd['evidence'] as $e) echo "             + $e\n";
        foreach ($cd['warnings'] as $w) echo "             ! $w\n";
    }
}
if ($plan['unmatched']) {
    echo "\nBez propozycji (" . count($plan['unmatched']) . "):\n";
    foreach ($plan['unmatched'] as $u) echo sprintf("  %-22s %-14s %s%s\n", $u['number'] !== '' ? $u['number'] : '#' . $u['id'], $u['type'], implode(', ', $u['names']), $u['hidden'] ? "  (ukryte słabsze dopasowania: {$u['hidden']} — --min=low)" : '');
}
echo "\nPrzypisanie plików: Administracja → „Skany z SharePointa” (admin/sp_contract_match.php).\n";
