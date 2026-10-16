<?php
/**
 * modules/it_control/logic/itControlRunner.php — wykonanie zestawu sprawdzeń.
 *
 * itControlRunSuite() uruchamia wszystkie włączone sprawdzenia zestawu,
 * agreguje status (najgorszy wygrywa; SKIPPED nie liczy się), zapisuje
 * przebieg do historii (chyba że tryb próbny) i zwraca go jako tablicę
 * w tym samym kształcie co itControlLoadRun().
 */

require_once __DIR__ . '/itControlSettings.php';
require_once __DIR__ . '/itControlCheck.php';
require_once __DIR__ . '/itControlRegistry.php';
require_once __DIR__ . '/itControlStore.php';

function itControlRunSuite(string $suite, ItControlContext $ctx, string $trigger = 'manual', ?int $userId = null): array {
    $disabled = itControlDisabledChecks($ctx->settings);
    $started  = date('Y-m-d H:i:s', $ctx->now);
    $t0 = hrtime(true);
    $results = [];
    foreach (ItControlRegistry::all($suite) as $id => $check) {
        if (in_array($id, $disabled, true)) {
            $r = (new ItControlResult($id, $check->title()))->skip('Wyłączone w ustawieniach.');
        } else {
            $r = $check->run($ctx);
        }
        $results[] = $r->toArray();
    }
    $statuses = array_column($results, 'status');
    $status = itControlWorstStatus($statuses);
    if ($status === 'SKIPPED') $status = 'PASS';
    $critical = 0;
    foreach ($results as $r) foreach ($r['findings'] as $f) if ($f['severity'] === 'critical') $critical++;

    $run = [
        'id'               => null,
        'suite'            => $suite,
        'trigger'          => $trigger,
        'status'           => $status,
        'events_processed' => array_sum(array_column($results, 'events_processed')),
        'critical_count'   => $critical,
        'warning_count'    => count(array_filter($statuses, fn($s) => $s === 'WARNING')),
        'fail_count'       => count(array_filter($statuses, fn($s) => $s === 'FAIL')),
        'started_at'       => $started,
        'finished_at'      => date('Y-m-d H:i:s'),
        'duration_ms'      => (int)((hrtime(true) - $t0) / 1e6),
        'summary'          => ['checks' => count($results), 'window_hours' => (int)$ctx->setting('log_window_hours'), 'dry_run' => $ctx->dryRun],
        'results'          => $results,
        'created_by'       => $userId,
    ];
    if (!$ctx->dryRun) $run['id'] = itControlSaveRun($run);
    return $run;
}

/** Wszystkie rekomendacje przebiegu (bez powtórzeń), najpierw z wyników FAIL. */
function itControlRunRecommendations(array $run): array {
    $out = [];
    $sorted = $run['results'];
    usort($sorted, fn($a, $b) => (ITC_STATUS_WEIGHT[$b['status']] ?? 0) <=> (ITC_STATUS_WEIGHT[$a['status']] ?? 0));
    foreach ($sorted as $r) foreach ($r['recommendations'] as $rec) $out[$rec] = true;
    return array_keys($out);
}

/** Znaleziska o podanych ważnościach (z id sprawdzenia). */
function itControlRunFindings(array $run, array $severities = ['critical', 'high']): array {
    $out = [];
    foreach ($run['results'] as $r) foreach ($r['findings'] as $f) {
        if (in_array($f['severity'], $severities, true)) $out[] = $f + ['check_id' => $r['check_id'], 'check_title' => $r['title']];
    }
    return $out;
}

/**
 * Stan całościowy: najgorszy z ostatnich przebiegów obu zestawów.
 * @return array{status:string, runs:array<string,?array>}
 */
function itControlOverall(): array {
    $runs = [];
    foreach (array_keys(ITC_SUITES) as $s) $runs[$s] = itControlLatestRun($s);
    $st = array_map(fn($r) => $r['status'] ?? 'SKIPPED', array_filter($runs));
    return ['status' => $st ? itControlWorstStatus($st) : 'SKIPPED', 'runs' => $runs];
}
