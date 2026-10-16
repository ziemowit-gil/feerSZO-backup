<?php
/**
 * modules/log_control/logic/logControlReader.php — odczyt i klasyfikacja plików dzienników.
 *
 * Źródła (logControlLogFiles):
 *   - dziennik błędów PHP (ini error_log),
 *   - <aplikacja>/logs/*.log,
 *   - LOG_PATH/*.log (tam dyspozytor CRON pisze cron_<agent>.log), jeśli zdefiniowane,
 *   - /var/log/umowy_cron.log (wyjście dyspozytora z crontaba),
 *   - dodatkowe ścieżki z ustawienia itc_log_files (po jednej w linii).
 *
 * Czytany jest tylko ogon pliku (itc_log_max_bytes) — duże dzienniki nie
 * blokują przebiegu. Linia bez znacznika czasu dziedziczy czas poprzedniej
 * (stack trace), a gdy go brak — mtime pliku.
 */

/** @return array<int,array{path:string,kind:string,label:string}> */
function logControlLogFiles(array $settings): array {
    $root = dirname(__DIR__, 3);
    $cand = [];
    $php = (string)ini_get('error_log');
    if ($php !== '' && $php !== 'syslog') $cand[] = [$php, 'php', 'PHP error_log'];
    foreach (glob($root . '/logs/*.log') ?: [] as $f) $cand[] = [$f, 'app', 'logs/' . basename($f)];
    if (defined('LOG_PATH') && is_dir(LOG_PATH)) {
        foreach (glob(rtrim(LOG_PATH, '/') . '/*.log') ?: [] as $f) {
            $cand[] = [$f, str_starts_with(basename($f), 'cron_') ? 'cron' : 'app', basename($f)];
        }
    }
    $cand[] = ['/var/log/umowy_cron.log', 'cron', 'umowy_cron.log'];
    foreach (preg_split('/\R/', (string)($settings['log_files'] ?? '')) as $f) {
        $f = trim($f);
        if ($f !== '') $cand[] = [$f, 'app', basename($f)];
    }
    $out = [];
    foreach ($cand as [$path, $kind, $label]) {
        if (!@is_file($path) || !@is_readable($path)) continue;
        $real = realpath($path) ?: $path;
        if (isset($out[$real])) continue;
        // error_log PHP bywa jednym z plików w logs/ — rodzaj „php” ma pierwszeństwo
        $out[$real] = ['path' => $real, 'kind' => $kind, 'label' => $label];
    }
    return array_values($out);
}

/** Znacznik czasu z początku linii (różne formaty) albo null. */
function logControlParseTs(string $line): ?int {
    if (preg_match('/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([A-Za-z_\/+\-0-9]+))?\]/', $line, $m)) {
        $t = strtotime($m[1] . (isset($m[2]) ? ' ' . $m[2] : ''));
        return $t ?: null;
    }
    if (preg_match('/^\[?(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $line, $m)) {
        $t = strtotime($m[1] . ' ' . $m[2]);
        return $t ?: null;
    }
    if (preg_match('/^\[[A-Z][a-z]{2} ([A-Z][a-z]{2} +\d{1,2} \d{2}:\d{2}:\d{2})(?:\.\d+)? (\d{4})\]/', $line, $m)) {
        $t = strtotime($m[1] . ' ' . $m[2]);
        return $t ?: null;
    }
    return null;
}

/** Poziom linii: fatal | error | warning | notice | info. */
function logControlClassify(string $line): string {
    if (preg_match('/PHP (Fatal error|Parse error)|Uncaught |Allowed memory size|Maximum execution time|Segmentation fault|\b(FATAL|CRITICAL|EMERGENCY)\b/', $line)) return 'fatal';
    if (preg_match('/PHP (Recoverable fatal )?[Ee]rror|\bERROR\b|\[error\]|\[FAIL\]|\bFAILED\b|SQLSTATE|\b[A-Za-z]*Exception\b|\bBŁĄD\b|\bBłąd\b/u', $line)) return 'error';
    if (preg_match('/PHP Warning|\bWARN(ING)?\b|\[warn|\[ALERT\]/', $line)) return 'warning';
    if (preg_match('/PHP (Notice|Deprecated)|\bDeprecated:/', $line)) return 'notice';
    return 'info';
}

/** Czy linia wygląda na zdarzenie bezpieczeństwa (odmowa dostępu, CSRF…). */
function logControlIsSecurity(string $line): bool {
    return (bool)preg_match('/\b(HTTP\/\d(\.\d)? )?(401|403)\b.*(denied|forbidden|unauthori[sz]ed)|Forbidden|Unauthori[sz]ed|CSRF|brute.?force|Access denied|Permission denied|odmowa dostępu/iu', $line);
}

/** Znormalizowana sygnatura komunikatu (grupowanie powtórzeń). */
function logControlSignature(string $line): string {
    $s = preg_replace('/^\[[^\]]{8,40}\]\s*/', '', $line);
    $s = preg_replace('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}\S*\s*/', '', $s);
    $s = preg_replace('/\b[0-9a-f]{12,}\b/i', '#', $s);
    $s = preg_replace('/(["\']).{0,80}?\1/', '"…"', $s);
    $s = preg_replace('/\d+/', 'N', $s);
    $s = preg_replace('/\s+/', ' ', trim($s));
    return mb_substr($s, 0, 180);
}

/**
 * Czyta ogon pliku i zwraca zdarzenia od $since.
 * $windowFrom — od kiedy liczyć linie jako „przetworzone” (domyślnie $since).
 * @return array{path:string,label:string,kind:string,bytes:int,truncated:bool,lines:int,entries:array,error:?string}
 */
function logControlReadFile(array $file, int $since, int $maxBytes, ?int $windowFrom = null): array {
    $windowFrom ??= $since;
    $out = ['path' => $file['path'], 'label' => $file['label'], 'kind' => $file['kind'],
            'bytes' => 0, 'truncated' => false, 'lines' => 0, 'entries' => [], 'error' => null];
    $size = @filesize($file['path']);
    $mtime = (int)@filemtime($file['path']);
    if ($size === false) { $out['error'] = 'nie można odczytać rozmiaru'; return $out; }
    if ($mtime && $mtime < $since) return $out;     // nic nowego w oknie
    $fh = @fopen($file['path'], 'rb');
    if (!$fh) { $out['error'] = 'brak dostępu do odczytu'; return $out; }
    $maxBytes = max(65536, $maxBytes);
    if ($size > $maxBytes) {
        fseek($fh, $size - $maxBytes);
        fgets($fh);                                   // niepełna pierwsza linia
        $out['truncated'] = true;
    }
    $lastTs = null;
    while (($line = fgets($fh)) !== false) {
        $out['bytes'] += strlen($line);
        $line = rtrim($line, "\r\n");
        if ($line === '') continue;
        $line = mb_convert_encoding($line, 'UTF-8', 'UTF-8');
        $ts = logControlParseTs($line);
        if ($ts !== null) $lastTs = $ts;
        $t = $ts ?? $lastTs ?? $mtime;
        if ($t < $since) continue;
        if ($t >= $windowFrom) $out['lines']++;   // zdarzenia w oknie analizy
        $level = logControlClassify($line);
        $sec = logControlIsSecurity($line);
        if ($level === 'info' && !$sec) continue;
        // kontynuacja stack trace (bez własnego czasu) — nie liczymy jako osobny błąd
        if ($ts === null && preg_match('/^(#\d+ |Stack trace:|\s+thrown in )/', $line)) continue;
        $out['entries'][] = ['ts' => $t, 'level' => $level, 'security' => $sec, 'msg' => mb_substr($line, 0, 500)];
    }
    fclose($fh);
    return $out;
}

/**
 * Wszystkie pliki odczytane raz na przebieg (wspólne dla checków przez kontekst).
 * Zakres: dłuższe z okna analizy i bazy do wykrywania skoków.
 */
function logControlReadAll(ItControlContext $ctx): array {
    return $ctx->remember('log_files', function () use ($ctx) {
        $since = min($ctx->windowStart(), $ctx->now - max(1, (int)$ctx->setting('spike_baseline_days')) * 86400);
        $out = [];
        foreach (logControlLogFiles($ctx->settings) as $f) {
            $out[] = logControlReadFile($f, $since, (int)$ctx->setting('log_max_bytes'), $ctx->windowStart());
        }
        return $out;
    });
}

/**
 * Zestawienie wpisów z okna analizy dla plików danego rodzaju.
 * @return array{files:int,lines:int,counts:array<string,int>,security:int,signatures:array,per_file:array}
 */
function logControlSummarize(ItControlContext $ctx, array $kinds): array {
    $from = $ctx->windowStart();
    $sum = ['files' => 0, 'lines' => 0, 'counts' => ['fatal' => 0, 'error' => 0, 'warning' => 0, 'notice' => 0],
            'security' => 0, 'signatures' => [], 'per_file' => [], 'errors' => []];
    foreach (logControlReadAll($ctx) as $f) {
        if (!in_array($f['kind'], $kinds, true)) continue;
        $sum['files']++;
        if ($f['error']) $sum['errors'][] = $f['label'] . ': ' . $f['error'];
        $pf = ['label' => $f['label'], 'fatal' => 0, 'error' => 0, 'warning' => 0, 'truncated' => $f['truncated']];
        foreach ($f['entries'] as $e) {
            if ($e['ts'] < $from) continue;
            if (isset($sum['counts'][$e['level']])) $sum['counts'][$e['level']]++;
            if (isset($pf[$e['level']])) $pf[$e['level']]++;
            if ($e['security']) $sum['security']++;
            if ($e['level'] === 'info' || $e['level'] === 'notice') continue;
            $sig = logControlSignature($e['msg']);
            $k = $e['level'] . '|' . $sig;
            if (!isset($sum['signatures'][$k])) $sum['signatures'][$k] = ['level' => $e['level'], 'sig' => $sig, 'count' => 0, 'last' => 0, 'example' => $e['msg'], 'file' => $f['label']];
            $sum['signatures'][$k]['count']++;
            $sum['signatures'][$k]['last'] = max($sum['signatures'][$k]['last'], $e['ts']);
        }
        $sum['lines'] += $f['lines'];          // wszystkie linie w oknie = przetworzone zdarzenia
        $sum['per_file'][] = $pf;
    }
    $order = ['fatal' => 0, 'error' => 1, 'warning' => 2];
    uasort($sum['signatures'], fn($a, $b) => [$order[$a['level']] ?? 9, -$a['count']] <=> [$order[$b['level']] ?? 9, -$b['count']]);
    $sum['signatures'] = array_values($sum['signatures']);
    return $sum;
}
