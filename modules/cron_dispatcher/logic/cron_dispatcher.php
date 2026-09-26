<?php
/**
 * modules/cron_dispatcher/logic/cron_dispatcher.php — nadpisania i stan agentów CRON.
 *
 * Rejestr domyślny: logic/registry.php (cron_dispatcher_registry()).
 * Z panelu (admin/cron_dispatcher.php) admin zmienia per agent:
 *  - włączony / wyłączony,
 *  - interwał (sekundy) — także dla agentów z interwałem dynamicznym (funkcja),
 *  - okno godzinowe: domyślne z rejestru / całą dobę / własne [od, do);
 *    od > do = okno przez północ (np. [22, 6] = 22:00–06:00),
 *  - „Uruchom teraz” — najbliższy przebieg dyspozytora pomija okno i interwał.
 * Nadpisania: cron_agent_overrides (brak wiersza = wartości z rejestru),
 * stan (ostatnie uruchomienie, prośba o uruchomienie): cron_agent_state,
 * historia zmian: cron_agent_audit.
 *
 * Dyspozytor (cron/dispatcher.php) woła cron_dispatcher_effective() — każdy
 * błąd bazy kończy się powrotem do samego rejestru, żeby panel nie mógł
 * zatrzymać crona.
 */

require_once __DIR__ . '/registry.php';

const CRON_DISPATCHER_HEARTBEAT = '__dispatcher__';

/** Presety interwału w edytorze: sekundy → etykieta. */
const CRON_DISPATCHER_INTERVALS = [
    60 => 'co minutę', 120 => 'co 2 min', 300 => 'co 5 min', 600 => 'co 10 min', 900 => 'co 15 min',
    1800 => 'co 30 min', 3600 => 'co godzinę', 7200 => 'co 2 h', 21600 => 'co 6 h', 43200 => 'co 12 h',
    86400 => 'raz dziennie', 604800 => 'raz w tygodniu',
];

function cron_dispatcher_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS cron_agent_overrides (
        name          VARCHAR(80) PRIMARY KEY,
        enabled       INTEGER,
        interval_sec  INTEGER,
        schedule_mode VARCHAR(10) NOT NULL DEFAULT 'default',
        schedule_from INTEGER,
        schedule_to   INTEGER,
        note          TEXT,
        updated_by    INTEGER,
        updated_at    DATETIME
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cron_agent_state (
        name             VARCHAR(80) PRIMARY KEY,
        last_run_at      INTEGER,
        last_trigger     VARCHAR(10),
        run_requested_at INTEGER,
        run_requested_by INTEGER
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cron_agent_audit (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       VARCHAR(80) NOT NULL,
        action     VARCHAR(20) NOT NULL,
        details    TEXT,
        user_id    INTEGER,
        user_name  VARCHAR(255),
        created_at DATETIME NOT NULL
    )");
}

/** @return array<string,array> nadpisania per agent */
function cron_dispatcher_overrides(): array {
    cron_dispatcher_migrate();
    $out = [];
    foreach (db()->query("SELECT * FROM cron_agent_overrides") as $r) $out[$r['name']] = $r;
    return $out;
}

/** @return array<string,array> stan per agent (+ heartbeat dyspozytora) */
function cron_dispatcher_state(): array {
    cron_dispatcher_migrate();
    $out = [];
    foreach (db()->query("SELECT * FROM cron_agent_state") as $r) $out[$r['name']] = $r;
    return $out;
}

/**
 * Rejestr z nałożonymi nadpisaniami. Dodatkowe klucze: enabled (bool),
 * run_requested (bool), overridden (bool), default (wpis z rejestru).
 * Brak 'schedule' = cała doba.
 */
function cron_dispatcher_effective(?array $registry = null): array {
    $registry ??= cron_dispatcher_registry();
    try {
        $ov = cron_dispatcher_overrides();
        $st = cron_dispatcher_state();
    } catch (\Throwable $e) {
        $ov = $st = [];
    }
    $out = [];
    foreach ($registry as $name => $cfg) {
        $o   = $ov[$name] ?? null;
        $eff = $cfg + ['enabled' => true, 'run_requested' => false, 'overridden' => false, 'default' => $cfg];
        if ($o) {
            $eff['overridden'] = true;
            if ($o['enabled'] !== null) $eff['enabled'] = (int)$o['enabled'] === 1;
            if ($o['interval_sec'] !== null && (int)$o['interval_sec'] >= 60) $eff['interval'] = (int)$o['interval_sec'];
            if ($o['schedule_mode'] === 'none') {
                unset($eff['schedule']);
            } elseif ($o['schedule_mode'] === 'custom' && $o['schedule_from'] !== null && $o['schedule_to'] !== null) {
                $eff['schedule'] = [(int)$o['schedule_from'], (int)$o['schedule_to']];
            }
        }
        $eff['run_requested'] = !empty($st[$name]['run_requested_at']);
        $out[$name] = $eff;
    }
    return $out;
}

/** Interwał jako liczba sekund (rozwiązuje interwał dynamiczny). */
function cron_dispatcher_interval_seconds(array $cfg): int {
    $i = $cfg['interval'];
    if (is_string($i) && is_callable($i)) {
        try { return (int)call_user_func($i); } catch (\Throwable $e) { return 0; }
    }
    return (int)$i;
}

/**
 * Czy godzina $hour mieści się w oknie agenta [od, do). Jedyne miejsce z tą
 * regułą — używa jej dyspozytor, szacowanie następnego startu i wykres.
 * od < do: zwykłe okno; od > do: przez północ (od..23 oraz 0..do-1); od == do: puste.
 */
function cron_dispatcher_in_window(array $cfg, int $hour): bool {
    if (!isset($cfg['schedule'])) return true;
    [$f, $t] = array_map('intval', $cfg['schedule']);
    return $f < $t ? ($hour >= $f && $hour < $t) : ($f > $t && ($hour >= $f || $hour < $t));
}

/** Godziny okna w kolejności od jego początku (dla okna przez północ: 22, 23, 0, 1…). */
function cron_dispatcher_window_hours(array $cfg): array {
    $start = isset($cfg['schedule']) ? (int)$cfg['schedule'][0] : 0;
    $out = [];
    for ($i = 0; $i < 24; $i++) {
        $h = ($start + $i) % 24;
        if (cron_dispatcher_in_window($cfg, $h)) $out[] = $h;
    }
    return $out;
}

/** Etykieta okna: „cała doba”, „08–18”, „22–06 (przez północ)”. */
function cron_dispatcher_window_label(?array $w): string {
    if ($w === null) return 'cała doba';
    [$f, $t] = array_map('intval', $w);
    return sprintf('%02d–%02d', $f, $t) . ($f > $t ? ' (przez północ)' : '');
}

/** Zapis uruchomienia przez dyspozytor (i zdjęcie prośby „Uruchom teraz”). Nie rzuca. */
function cron_dispatcher_mark_run(string $name, string $trigger): void {
    try {
        cron_dispatcher_migrate();
        db()->prepare("INSERT INTO cron_agent_state (name, last_run_at, last_trigger, run_requested_at, run_requested_by)
                       VALUES (?, ?, ?, NULL, NULL)
                       ON CONFLICT(name) DO UPDATE SET last_run_at = excluded.last_run_at,
                           last_trigger = excluded.last_trigger, run_requested_at = NULL, run_requested_by = NULL")
            ->execute([$name, time(), $trigger]);
    } catch (\Throwable $e) {}
}

/** Heartbeat dyspozytora — panel pokazuje, czy crontab w ogóle go uruchamia. */
function cron_dispatcher_heartbeat(): void {
    cron_dispatcher_mark_run(CRON_DISPATCHER_HEARTBEAT, 'auto');
}

/** Ostatnie uruchomienie: z bazy, a gdy brak — z pliku blokady (ten sam host i /tmp co CLI). */
function cron_dispatcher_last_run(string $name, array $state): ?int {
    if (!empty($state[$name]['last_run_at'])) return (int)$state[$name]['last_run_at'];
    $lock = sys_get_temp_dir() . '/umowy_cron_' . $name . '.last';
    return is_readable($lock) ? ((int)@file_get_contents($lock) ?: null) : null;
}

/**
 * Szacowany najbliższy start (unix) albo null (wyłączony). Uwzględnia okno
 * godzinowe: jeśli interwał minie poza oknem, start przesuwa się na początek okna.
 */
function cron_dispatcher_next_run(array $cfg, ?int $last): ?int {
    if (empty($cfg['enabled'])) return null;
    if (!empty($cfg['run_requested'])) return (int)(ceil(time() / 60) * 60);
    $interval = cron_dispatcher_interval_seconds($cfg);
    $t = max(time(), ($last ?? 0) + $interval);
    $t = (int)(ceil($t / 60) * 60);
    for ($i = 0; $i < 24 * 8; $i++) {  // max 8 dni do przodu
        if (cron_dispatcher_in_window($cfg, (int)date('G', $t))) return $t;
        $t = (int)strtotime(date('Y-m-d H:00:00', $t) . ' +1 hour');
    }
    return null;
}

/** Szacunkowa liczba uruchomień w każdej godzinie doby (wykres obciążenia). */
function cron_dispatcher_hour_load(array $cfg): array {
    $load = array_fill(0, 24, 0.0);
    if (empty($cfg['enabled'])) return $load;
    $interval = max(60, cron_dispatcher_interval_seconds($cfg));
    $hours = cron_dispatcher_window_hours($cfg);
    if (!$hours) return $load;
    if ($interval <= 3600) {
        foreach ($hours as $h) $load[$h] = 3600 / $interval;
    } else {
        // Rzadziej niż co godzinę: rozkład startów po oknie co $interval (od jego początku).
        $perDay = 86400 / $interval;
        if ($perDay < 1) { $load[$hours[0]] = $perDay; return $load; }
        $step = max(1, (int)round($interval / 3600));
        for ($k = 0; $k < count($hours); $k += $step) $load[$hours[$k]] += 1;
    }
    return $load;
}

/** Kategoria do grupowania (po prefiksie nazwy / pliku). */
function cron_dispatcher_category(string $name): string {
    static $map = [
        'mail' => 'Poczta i wysyłki', 'bulk' => 'Poczta i wysyłki', 'campaign' => 'Poczta i wysyłki', 'poczta' => 'Poczta i wysyłki',
        'crm' => 'CRM', 'ezd' => 'EZD', 'k30' => 'TI / Dydaktyka', 'ti' => 'TI / Dydaktyka', 'dydaktyk' => 'TI / Dydaktyka',
        'm365' => 'Microsoft 365', 'sync' => 'Synchronizacje', 'outlook' => 'Microsoft 365', 'sp' => 'Microsoft 365',
        'tasks' => 'Zadania', 'backup' => 'Kopie i monitoring', 'prod' => 'Kopie i monitoring',
        'contract' => 'Umowy', 'termination' => 'Umowy', 'pelnomocnictwa' => 'Umowy i dokumenty', 'guardian' => 'Wolontariat',
        'minor' => 'Wolontariat', 'volunteer' => 'Wolontariat', 'gdpr' => 'RODO', 'kdok' => 'Księgowość', 'edok' => 'Księgowość',
        'betterfly' => 'Księgowość', 'ext' => 'Materiały zewnętrzne',
    ];
    $first = strtolower((string)strtok($name, '_'));
    return $map[$first] ?? 'Inne';
}

function cron_dispatcher_interval_label(int $sec): string {
    if (isset(CRON_DISPATCHER_INTERVALS[$sec])) return CRON_DISPATCHER_INTERVALS[$sec];
    if ($sec % 86400 === 0) return 'co ' . ($sec / 86400) . ' dni';
    if ($sec % 3600 === 0)  return 'co ' . ($sec / 3600) . ' h';
    if ($sec % 60 === 0)    return 'co ' . ($sec / 60) . ' min';
    return 'co ' . $sec . ' s';
}

/**
 * Zapis nadpisania z panelu. $d: enabled (bool), interval_sec (int|null = domyślny),
 * schedule_mode (default|none|custom), schedule_from/to, note. Zwraca opis zmian (audyt).
 * Wiersz równy w całości domyślnym jest usuwany — „zmodyfikowany” zostaje czytelny.
 */
function cron_dispatcher_save(string $name, array $d, int $userId, string $userName): string {
    $reg = cron_dispatcher_registry();
    if (!isset($reg[$name])) throw new InvalidArgumentException("Nieznany agent: {$name}");
    $def = $reg[$name];

    $enabled = !empty($d['enabled']);
    $iv = $d['interval_sec'] ?? null;
    $iv = ($iv === null || $iv === '') ? null : (int)$iv;
    if ($iv !== null && ($iv < 60 || $iv > 2592000)) throw new InvalidArgumentException('Interwał: od 60 s do 30 dni.');
    if ($iv !== null && !is_callable($def['interval']) && $iv === (int)$def['interval']) $iv = null;

    $mode = (string)($d['schedule_mode'] ?? 'default');
    if (!in_array($mode, ['default', 'none', 'custom'], true)) $mode = 'default';
    $from = $to = null;
    if ($mode === 'custom') {
        $from = (int)($d['schedule_from'] ?? -1);
        $to   = (int)($d['schedule_to'] ?? -1);
        if ($to === 0) $to = 24;                       // „do północy” = 24
        if ($from < 0 || $from > 23 || $to < 1 || $to > 24 || $from === $to) {
            throw new InvalidArgumentException('Okno godzinowe: początek 0–23, koniec 1–24, różne od siebie. Początek później niż koniec = okno przez północ (np. 22–6).');
        }
        if ($from === 0 && $to === 24) { $mode = 'none'; $from = $to = null; }
        elseif (isset($def['schedule']) && [$from, $to] === [(int)$def['schedule'][0], (int)$def['schedule'][1]]) { $mode = 'default'; $from = $to = null; }
    }
    if ($mode === 'none' && !isset($def['schedule'])) $mode = 'default';
    $note = trim((string)($d['note'] ?? ''));

    $before = cron_dispatcher_effective()[$name];
    $isDefault = $enabled && $iv === null && $mode === 'default' && $note === '';
    if ($isDefault) {
        db()->prepare("DELETE FROM cron_agent_overrides WHERE name = ?")->execute([$name]);
    } else {
        db()->prepare("INSERT INTO cron_agent_overrides (name, enabled, interval_sec, schedule_mode, schedule_from, schedule_to, note, updated_by, updated_at)
                       VALUES (?,?,?,?,?,?,?,?,datetime('now','localtime'))
                       ON CONFLICT(name) DO UPDATE SET enabled = excluded.enabled, interval_sec = excluded.interval_sec,
                           schedule_mode = excluded.schedule_mode, schedule_from = excluded.schedule_from,
                           schedule_to = excluded.schedule_to, note = excluded.note,
                           updated_by = excluded.updated_by, updated_at = excluded.updated_at")
            ->execute([$name, $enabled ? 1 : 0, $iv, $mode, $from, $to, $note !== '' ? $note : null, $userId]);
    }
    $after = cron_dispatcher_effective()[$name];

    $desc = [];
    if ($before['enabled'] !== $after['enabled']) $desc[] = $after['enabled'] ? 'włączony' : 'WYŁĄCZONY';
    $bi = cron_dispatcher_interval_seconds($before); $ai = cron_dispatcher_interval_seconds($after);
    if ($bi !== $ai) $desc[] = 'interwał ' . cron_dispatcher_interval_label($bi) . ' → ' . cron_dispatcher_interval_label($ai);
    $w = fn($c) => cron_dispatcher_window_label($c['schedule'] ?? null);
    if ($w($before) !== $w($after)) $desc[] = 'okno ' . $w($before) . ' → ' . $w($after);
    if ($isDefault && $before['overridden']) $desc[] = 'przywrócono domyślne';
    $summary = $desc ? implode('; ', $desc) : 'bez zmian';
    if ($desc) cron_dispatcher_audit($name, $isDefault ? 'reset' : 'update', $summary, $userId, $userName);
    return $summary;
}

function cron_dispatcher_request_run(string $name, int $userId, string $userName): void {
    if (!isset(cron_dispatcher_registry()[$name])) throw new InvalidArgumentException("Nieznany agent: {$name}");
    cron_dispatcher_migrate();
    db()->prepare("INSERT INTO cron_agent_state (name, run_requested_at, run_requested_by) VALUES (?, ?, ?)
                   ON CONFLICT(name) DO UPDATE SET run_requested_at = excluded.run_requested_at, run_requested_by = excluded.run_requested_by")
        ->execute([$name, time(), $userId]);
    cron_dispatcher_audit($name, 'run_now', 'zlecono uruchomienie przy najbliższym przebiegu', $userId, $userName);
}

function cron_dispatcher_cancel_run(string $name): void {
    cron_dispatcher_migrate();
    db()->prepare("UPDATE cron_agent_state SET run_requested_at = NULL, run_requested_by = NULL WHERE name = ?")->execute([$name]);
}

function cron_dispatcher_audit(string $name, string $action, string $details, int $userId, string $userName): void {
    cron_dispatcher_migrate();
    db()->prepare("INSERT INTO cron_agent_audit (name, action, details, user_id, user_name, created_at) VALUES (?,?,?,?,?,datetime('now','localtime'))")
        ->execute([$name, $action, $details, $userId, $userName]);
}

function cron_dispatcher_audit_list(int $limit = 50): array {
    cron_dispatcher_migrate();
    return db_all("SELECT * FROM cron_agent_audit ORDER BY id DESC LIMIT " . max(1, $limit));
}

/** Ostatnie linie logu agenta (LOG_PATH/cron_{name}.log) albo null, gdy niedostępny. */
function cron_dispatcher_log_tail(string $name, int $lines = 80): ?string {
    if (!defined('LOG_PATH') || !preg_match('/^[a-z0-9_]+$/', $name)) return null;
    $f = rtrim(LOG_PATH, '/') . '/cron_' . $name . '.log';
    if (!is_readable($f)) return null;
    $size = filesize($f);
    $h = fopen($f, 'rb');
    if (!$h) return null;
    $chunk = min($size, 64 * 1024);
    fseek($h, -$chunk, SEEK_END);
    $data = (string)fread($h, $chunk);
    fclose($h);
    $all = preg_split('/\r?\n/', rtrim($data));
    return implode("\n", array_slice($all, -$lines));
}
