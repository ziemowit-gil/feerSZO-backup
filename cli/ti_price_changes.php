<?php
/**
 * cli/ti_price_changes.php — podgląd i testowanie zmian cen zajęć TI
 * (includes/ti_price_changes.php).
 *
 *   php cli/ti_price_changes.php list [--all]
 *       Zmiany cen (domyślnie aktywne).
 *
 *   php cli/ti_price_changes.php show --client=ID --month=RRRR-MM [--course=ID]
 *       Rozliczenie miesiąca kursanta: per grupa kwota BAZOWA (bez zmian cen)
 *       vs PO ZMIANACH, rozbicie stawek i stawka każdej lekcji.
 *
 *   php cli/ti_price_changes.php simulate --course=ID --from=RRRR-MM-DD [--to=RRRR-MM-DD]
 *        (--percent=N | --amount=N) [--client=ID] [--month=RRRR-MM]
 *       Dodaje zmianę W TRANSAKCJI, pokazuje przed/po dla kursantów grupy
 *       (i rozliczenia, które przeliczyłby rebill), po czym WYCOFUJE — nic
 *       nie zostaje w bazie i nie wychodzą e-maile.
 *
 *   php cli/ti_price_changes.php selftest
 *       Automatyczny test na prawdziwych danych, w wycofywanej transakcji:
 *       zmiana od środka miesiąca zmienia stawkę TYLKO lekcji od tej daty.
 *       Kod wyjścia 0 = OK, 1 = błąd, 2 = brak danych do testu.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Tylko CLI.\n"); }

$base = dirname(__DIR__);
define('BOOTSTRAP_CHECKED', true);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/karty30.php';
require_once $base . '/includes/ti_payments.php';
require_once $base . '/includes/ti_price_changes.php';
karty30_migrate();
ti_payments_migrate();
ti_price_changes_migrate();

$cmd  = $argv[1] ?? 'help';
$opt  = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z_]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? '1';
}
$zl  = fn($v) => number_format((float)$v, 2, ',', ' ');
$out = fn(string $s = '') => fwrite(STDOUT, $s . "\n");
$die = function (string $s, int $code = 1) { fwrite(STDERR, $s . "\n"); exit($code); };

/** Rozliczenie z wyłączonymi zmianami cen (baza porównania). */
$calc_base = function (int $client, int $m, int $y, int $course): array {
    db()->beginTransaction();
    try {
        db()->exec("UPDATE k30_ti_price_changes SET status='__cli_off' WHERE status='active'");
        ti_price_change_cache_clear();
        return k30_ti_calculate_billing($client, $m, $y, $course);
    } finally {
        db()->rollBack();
        ti_price_change_cache_clear();
    }
};

$print_calc = function (array $calc, ?array $base = null) use ($out, $zl) {
    $bmap = [];
    foreach (($base['courses'] ?? []) as $c) $bmap[(int)$c['course_id']] = $c;
    foreach ($calc['courses'] as $c) {
        $b = $bmap[(int)$c['course_id']] ?? null;
        $out(sprintf('  ▸ %s (#%d) — %s', $c['course_name'], $c['course_id'], $c['hourly'] ? 'godzinowo' : 'ryczałt'));
        $out(sprintf('      godz.: %s   kwota: %s zł%s', rtrim(rtrim(number_format($c['hours_billed'], 2, ',', ''), '0'), ','),
            $zl($c['amount']), $b !== null ? '   (bazowo: ' . $zl($b['amount']) . ' zł, różnica ' . $zl($c['amount'] - $b['amount']) . ' zł)' : ''));
        if ($c['price_change_ids']) $out('      zmiany cen: #' . implode(', #', $c['price_change_ids']));
        if ($c['hourly'] && $c['rate_parts']) {
            foreach ($c['rate_parts'] as $p) $out(sprintf('      stawka %s zł/h × %s h = %s zł', $zl($p['rate']), rtrim(rtrim(number_format($p['hours'], 2, ',', ''), '0'), ','), $zl($p['amount'])));
            foreach ($c['rate_by_date'] as $d => $r) $out(sprintf('        %s  %s zł/h', $d, $zl($r)));
        }
    }
    $out(sprintf('  RAZEM: %s zł%s', $zl($calc['amount']), $base ? ' (bazowo ' . $zl($base['amount']) . ' zł)' : ''));
};

switch ($cmd) {

case 'list':
    $rows = db_all(
        "SELECT pc.*, c.name AS course_name, cl.name AS client_name FROM k30_ti_price_changes pc
         JOIN k30_ti_courses c ON c.id=pc.course_id LEFT JOIN k30_clients cl ON cl.id=pc.client_id
         " . (isset($opt['all']) ? '' : "WHERE pc.status='active'") . " ORDER BY pc.date_from DESC, pc.id DESC"
    );
    if (!$rows) { $out('Brak zmian cen.'); break; }
    foreach ($rows as $r) {
        $out(sprintf('#%-4d %-9s %-8s %s — %s | %s → %s%s | %s',
            $r['id'], $r['status'], $r['scope'] === 'client' ? 'indyw.' : 'grupa',
            $r['course_name'] . ($r['client_name'] ? ' / ' . $r['client_name'] : ''),
            ti_price_change_value_label($r['change_type'], (float)$r['change_value']),
            $r['date_from'], $r['date_to'] ?: 'bezterminowo',
            $r['notified_at'] ? ' | powiadomiono ' . $r['notified_count'] : '',
            $r['reason']));
    }
    break;

case 'show':
    $client = (int)($opt['client'] ?? 0);
    if (!$client || !preg_match('/^(\d{4})-(\d{2})$/', $opt['month'] ?? '', $mm)) $die('Użycie: show --client=ID --month=RRRR-MM [--course=ID]');
    $course = (int)($opt['course'] ?? 0);
    $calc = k30_ti_calculate_billing($client, (int)$mm[2], (int)$mm[1], $course);
    $base = $calc_base($client, (int)$mm[2], (int)$mm[1], $course);
    $out("Rozliczenie kursanta #{$client} za {$mm[0]}" . ($course ? " (grupa #{$course})" : ''));
    $print_calc($calc, $base);
    $b = db_one("SELECT amount, status, invoice_no FROM k30_ti_billing WHERE client_id=? AND month=? AND year=? AND course_id=?",
                [$client, (int)$mm[2], (int)$mm[1], $course]);
    if ($b) $out(sprintf('  wystawione w bazie: %s zł, status %s%s%s', $zl($b['amount']), $b['status'],
        $b['invoice_no'] ? ', FV ' . $b['invoice_no'] : '',
        abs((float)$b['amount'] - (float)$calc['amount']) >= 0.005 ? '  ⚠ RÓŻNI SIĘ od przeliczenia' : ''));
    break;

case 'simulate':
    $course = (int)($opt['course'] ?? 0);
    $from   = (string)($opt['from'] ?? '');
    if (!$course || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || (!isset($opt['percent']) && !isset($opt['amount']))) {
        $die('Użycie: simulate --course=ID --from=RRRR-MM-DD [--to=…] (--percent=N | --amount=N) [--client=ID] [--month=RRRR-MM]');
    }
    $client = (int)($opt['client'] ?? 0);
    $month  = $opt['month'] ?? substr($from, 0, 7);
    [$y, $m] = array_map('intval', explode('-', $month));
    $clients = $client ? [$client] : array_map('intval', array_column(
        db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$course]), 'client_id'));

    db()->beginTransaction();
    try {
        $before = [];
        foreach ($clients as $c) $before[$c] = k30_ti_calculate_billing($c, $m, $y, $course);
        $pc = db_insert('k30_ti_price_changes', [
            'scope' => $client ? 'client' : 'course', 'course_id' => $course, 'client_id' => $client ?: null,
            'change_type' => isset($opt['percent']) ? 'percent' : 'amount',
            'change_value' => (float)str_replace(',', '.', $opt['percent'] ?? $opt['amount']),
            'date_from' => $from, 'date_to' => $opt['to'] ?? null, 'reason' => 'CLI simulate',
        ]);
        ti_price_change_cache_clear();
        $out("SYMULACJA zmiany #{$pc} (zostanie wycofana) — grupa #{$course}, miesiąc {$month}");
        foreach ($clients as $c) {
            $name = db_one("SELECT name FROM k30_clients WHERE id=?", [$c])['name'] ?? "#{$c}";
            $out("— {$name} (#{$c})");
            $print_calc(k30_ti_calculate_billing($c, $m, $y, $course), $before[$c]);
        }
        $rb = ti_price_change_rebill($pc);
        $out('Rebill: przeliczyłby ' . count($rb['updated']) . ', do ręcznej korekty ' . count($rb['manual']) . '.' . ti_price_change_rebill_msg($rb));
    } finally {
        db()->rollBack();
        ti_price_change_cache_clear();
        $out('Wycofano — baza bez zmian.');
    }
    break;

case 'selftest':
    // Grupa godzinowa z odbytymi lekcjami kursanta w ≥2 różnych dniach tego samego miesiąca
    $cand = db_all(
        "SELECT a.client_id, s.course_id, strftime('%Y-%m', s.lesson_date) AS ym,
                MIN(s.lesson_date) AS d1, MAX(s.lesson_date) AS d2
           FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id=a.session_id
          WHERE a.attended=1 AND COALESCE(a.cancelled,0)=0 AND s.status IN ('held','individual_change','remote_material')
          GROUP BY a.client_id, s.course_id, ym HAVING COUNT(DISTINCT s.lesson_date) >= 2
          ORDER BY ym DESC LIMIT 50"
    );
    $pick = null;
    foreach ($cand as $c) {
        $calc = k30_ti_calculate_billing((int)$c['client_id'], (int)substr($c['ym'], 5), (int)substr($c['ym'], 0, 4), (int)$c['course_id']);
        if (!empty($calc['courses'][0]['hourly']) && (float)$calc['courses'][0]['hourly_rate'] > 0) { $pick = $c; break; }
    }
    if (!$pick) $die('Brak danych do testu (grupa godzinowa z ≥2 dniami lekcji kursanta w jednym miesiącu).', 2);

    $cl = (int)$pick['client_id']; $co = (int)$pick['course_id'];
    $y = (int)substr($pick['ym'], 0, 4); $m = (int)substr($pick['ym'], 5);
    $fail = 0;
    $check = function (string $name, bool $ok) use (&$fail, $out) { $out(($ok ? '  ✔ ' : '  ✘ ') . $name); if (!$ok) $fail++; };

    db()->beginTransaction();
    try {
        db()->exec("UPDATE k30_ti_price_changes SET status='__cli_off' WHERE status='active'");
        ti_price_change_cache_clear();
        $base  = k30_ti_calculate_billing($cl, $m, $y, $co)['courses'][0];
        $rate0 = (float)$base['hourly_rate'];
        $dates = array_keys($base['rate_by_date']);
        sort($dates);
        $split = $dates[(int)floor(count($dates) / 2)];
        $out("Test: kursant #{$cl}, grupa #{$co}, {$pick['ym']}, stawka bazowa {$rate0}, zmiana +50% od {$split}");

        db_insert('k30_ti_price_changes', ['scope' => 'course', 'course_id' => $co, 'change_type' => 'percent',
            'change_value' => 50, 'date_from' => $split, 'reason' => 'CLI selftest']);
        ti_price_change_cache_clear();
        $after = k30_ti_calculate_billing($cl, $m, $y, $co)['courses'][0];

        $okBefore = true; $okAfter = true;
        foreach ($after['rate_by_date'] as $d => $r) {
            if ($d < $split && abs($r - $rate0) > 0.001) $okBefore = false;
            if ($d >= $split && abs($r - round($rate0 * 1.5, 2)) > 0.001) $okAfter = false;
        }
        $check('lekcje przed datą zmiany po starej stawce', $okBefore);
        $check('lekcje od daty zmiany po nowej stawce (+50%)', $okAfter);
        $check('dwie stawki w rozbiciu (rate_parts)', count($after['rate_parts']) === 2);
        $sum = array_sum(array_column($after['rate_parts'], 'amount'));
        $check('suma pozycji = kwota rozliczenia', abs($sum - (float)$after['amount']) < 0.01);
        $fv = k30_ti_billing_fv_positions(['client_id' => $cl, 'month' => $m, 'year' => $y, 'course_id' => $co]);
        $check('pozycje FV rozbite per stawka', count($fv) === 2
            && abs(array_sum(array_column($fv, 'value')) - (float)$after['amount']) < 0.01);

        // Ryczałt / prognoza: zmiana od 1. dnia następnego miesiąca nie rusza tego miesiąca
        db()->exec("UPDATE k30_ti_price_changes SET date_from='" . date('Y-m-01', strtotime(sprintf('%04d-%02d-01', $y, $m) . ' +1 month')) . "' WHERE reason='CLI selftest'");
        ti_price_change_cache_clear();
        $later = k30_ti_calculate_billing($cl, $m, $y, $co)['courses'][0];
        $check('zmiana od następnego miesiąca nie zmienia bieżącego', abs((float)$later['amount'] - (float)$base['amount']) < 0.01);
    } finally {
        db()->rollBack();
        ti_price_change_cache_clear();
    }
    $out($fail ? "BŁĘDY: {$fail}" : 'OK — wszystkie testy przeszły (transakcja wycofana).');
    exit($fail ? 1 : 0);

default:
    $out("Użycie: php cli/ti_price_changes.php list|show|simulate|selftest — szczegóły w nagłówku pliku.");
}
