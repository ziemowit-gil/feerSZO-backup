<?php
/**
 * includes/ti_blackout.php — Okresowe wyłączenia w TI (przerwy techniczne).
 *
 * Pozwala zaplanować okno czasowe, w którym wyłączony jest:
 *   • panel dydaktyka  (scope 'dydaktyk'),
 *   • dziennik ocen    (scope 'dziennik')  — dla prowadzących, kursantów i opiekunów,
 * z komentarzem wyjaśniającym, np. „Trwają przygotowania do nowego roku dydaktycznego”.
 *
 * Okno działa samo: włącza się i wyłącza po datach, bez ręcznego przestawiania.
 * Istniejący ręczny przełącznik panelu (org_setting dyd_panel_enabled) zostaje
 * — dyd_panel_is_enabled() bierze pod uwagę oba mechanizmy.
 *
 * Administratorzy i pracownicy D3 zawsze mają dostęp (to oni prowadzą prace),
 * widzą tylko baner informacyjny — dzięki temu można przygotować nowy rok
 * przy zamkniętym dzienniku.
 *
 * Osobne od okresów nauczania ([[project_ti_periods]]) — te opisują kalendarz
 * dydaktyczny, a nie dostępność systemu.
 */

if (!defined('TI_BLACKOUT_SCOPES')) {
    define('TI_BLACKOUT_SCOPES', [
        'dydaktyk' => ['label' => 'Panel dydaktyka',            'icon' => 'bi-easel2',          'col' => 'block_dydaktyk'],
        'dziennik' => ['label' => 'Dziennik ocen (e-dziennik)', 'icon' => 'bi-journal-bookmark', 'col' => 'block_dziennik'],
    ]);
}

/** Domyślny komunikat, gdy okno nie ma własnego. */
const TI_BLACKOUT_DEFAULT_MSG = 'Trwają prace techniczne — ta część systemu jest chwilowo niedostępna.';

/** Samonaprawa schematu — wołana z każdego punktu wejścia. */
function ti_blackout_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_blackouts (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            title           TEXT    NOT NULL DEFAULT '',
            message         TEXT    NOT NULL DEFAULT '',
            starts_at       TEXT    NOT NULL,
            ends_at         TEXT    NOT NULL,
            block_dydaktyk  INTEGER NOT NULL DEFAULT 0,
            block_dziennik  INTEGER NOT NULL DEFAULT 0,
            is_active       INTEGER NOT NULL DEFAULT 1,
            created_by      INTEGER,
            created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_blackouts_range ON k30_ti_blackouts(starts_at, ends_at)");
    } catch (\Throwable $e) {}
}

/** Kolumna flagi dla zakresu ('' gdy zakres nieznany). */
function ti_blackout_scope_col(string $scope): string {
    return (string)(TI_BLACKOUT_SCOPES[$scope]['col'] ?? '');
}

/** Etykieta zakresu. */
function ti_blackout_scope_label(string $scope): string {
    return (string)(TI_BLACKOUT_SCOPES[$scope]['label'] ?? $scope);
}

/**
 * Aktywne okno wyłączenia dla zakresu — albo null.
 * Cache w obrębie requestu: bramka bywa wołana wielokrotnie na stronę.
 */
function ti_blackout_active(string $scope): ?array {
    static $cache = [];
    $col = ti_blackout_scope_col($scope);
    if ($col === '') return null;
    if (array_key_exists($scope, $cache)) return $cache[$scope];

    ti_blackout_migrate();
    $now = date('Y-m-d H:i:s');
    try {
        $row = db_one(
            "SELECT * FROM k30_ti_blackouts
              WHERE is_active=1 AND {$col}=1 AND starts_at <= ? AND ends_at >= ?
              ORDER BY ends_at DESC LIMIT 1",
            [$now, $now]
        );
    } catch (\Throwable $e) { $row = null; }
    return $cache[$scope] = $row;
}

/** Najbliższe przyszłe okno dla zakresu (do zapowiedzi) — albo null. */
function ti_blackout_next(string $scope): ?array {
    $col = ti_blackout_scope_col($scope);
    if ($col === '') return null;
    ti_blackout_migrate();
    try {
        return db_one(
            "SELECT * FROM k30_ti_blackouts
              WHERE is_active=1 AND {$col}=1 AND starts_at > ?
              ORDER BY starts_at LIMIT 1",
            [date('Y-m-d H:i:s')]
        );
    } catch (\Throwable $e) { return null; }
}

/** Komunikat okna (własny albo domyślny). */
function ti_blackout_message(?array $win): string {
    if (!$win) return TI_BLACKOUT_DEFAULT_MSG;
    $m = trim((string)($win['message'] ?? ''));
    return $m !== '' ? $m : TI_BLACKOUT_DEFAULT_MSG;
}

/** Zakres okna jako czytelny tekst, np. „od 1.07.2026, 8:00 do 31.08.2026, 23:59”. */
function ti_blackout_range_text(array $win): string {
    $f = strtotime((string)$win['starts_at']);
    $t = strtotime((string)$win['ends_at']);
    if (!$f || !$t) return '';
    return 'od ' . date('j.m.Y, G:i', $f) . ' do ' . date('j.m.Y, G:i', $t);
}

/** Wszystkie okna: trwające i przyszłe najpierw, potem zakończone. */
function ti_blackout_list(): array {
    ti_blackout_migrate();
    return db_all(
        "SELECT * FROM k30_ti_blackouts ORDER BY (ends_at < datetime('now')), starts_at DESC"
    );
}

function ti_blackout_get(int $id): ?array {
    ti_blackout_migrate();
    return $id ? db_one("SELECT * FROM k30_ti_blackouts WHERE id=?", [$id]) : null;
}

/**
 * Zapis okna. Zwraca id.
 * Daty przyjmuje w formacie z input[type=datetime-local] (Y-m-dTH:i) albo Y-m-d H:i(:s).
 *
 * @throws RuntimeException gdy zakres jest niepoprawny albo nie wskazano, co wyłączyć.
 */
function ti_blackout_save(array $d, ?int $id = null, ?int $by = null): int {
    ti_blackout_migrate();
    $from = ti_blackout_norm_dt((string)($d['starts_at'] ?? ''), '00:00:00');
    $to   = ti_blackout_norm_dt((string)($d['ends_at']   ?? ''), '23:59:59');
    if ($from === '' || $to === '') throw new \RuntimeException('Podaj datę początku i końca okresu.');
    if ($to <= $from)               throw new \RuntimeException('Koniec okresu musi być późniejszy niż początek.');

    $fields = [
        'title'          => trim((string)($d['title'] ?? '')),
        'message'        => trim((string)($d['message'] ?? '')),
        'starts_at'      => $from,
        'ends_at'        => $to,
        'block_dydaktyk' => !empty($d['block_dydaktyk']) ? 1 : 0,
        'block_dziennik' => !empty($d['block_dziennik']) ? 1 : 0,
        'is_active'      => !empty($d['is_active']) ? 1 : 0,
    ];
    if (!$fields['block_dydaktyk'] && !$fields['block_dziennik']) {
        throw new \RuntimeException('Wskaż, co ma być wyłączone: panel dydaktyka, dziennik albo oba.');
    }
    if ($id) {
        db_update('k30_ti_blackouts', $fields, $id);
        return $id;
    }
    $fields['created_by'] = $by;
    return db_insert('k30_ti_blackouts', $fields);
}

/** Data z formularza → 'Y-m-d H:i:s'; przy samej dacie dokłada godzinę $default. */
function ti_blackout_norm_dt(string $v, string $default_time): string {
    $v = trim(str_replace('T', ' ', $v));
    if ($v === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v))            return $v . ' ' . $default_time;
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) return $v . ':00';
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return $v;
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i:s', $ts) : '';
}

function ti_blackout_delete(int $id): void {
    ti_blackout_migrate();
    if ($id) db_exec("DELETE FROM k30_ti_blackouts WHERE id=?", [$id]);
}

/**
 * Czy bieżący użytkownik pomija wyłączenie (admin / pracownik D3 — prowadzą prace).
 * Poza sesją panelową (kursant, opiekun) zawsze false.
 */
function ti_blackout_bypass(): bool {
    if (function_exists('is_admin') && is_admin())              return true;
    if (function_exists('can_write') && can_write('karty30'))   return true;
    return false;
}

/**
 * Gotowy HTML baneru o wyłączeniu (dla stron, które nie blokują dostępu,
 * np. dziennik po stronie administracji). Puste, gdy nic nie obowiązuje.
 */
function ti_blackout_banner_html(string $scope): string {
    $win = ti_blackout_active($scope);
    if (!$win) {
        $next = ti_blackout_next($scope);
        if (!$next) return '';
        return '<div class="alert alert-info d-flex align-items-start gap-2" role="status">'
            . '<i class="bi bi-calendar-event mt-1" aria-hidden="true"></i><div>'
            . '<strong>Zaplanowane wyłączenie: ' . htmlspecialchars(ti_blackout_scope_label($scope), ENT_QUOTES, 'UTF-8') . '</strong> '
            . htmlspecialchars(ti_blackout_range_text($next), ENT_QUOTES, 'UTF-8') . '.<br>'
            . htmlspecialchars(ti_blackout_message($next), ENT_QUOTES, 'UTF-8')
            . '</div></div>';
    }
    return '<div class="alert alert-warning d-flex align-items-start gap-2" role="status">'
        . '<i class="bi bi-cone-striped mt-1" aria-hidden="true"></i><div>'
        . '<strong>' . htmlspecialchars(ti_blackout_scope_label($scope), ENT_QUOTES, 'UTF-8')
        . ' jest wyłączony dla prowadzących, kursantów i opiekunów</strong> '
        . htmlspecialchars(ti_blackout_range_text($win), ENT_QUOTES, 'UTF-8') . '.<br>'
        . htmlspecialchars(ti_blackout_message($win), ENT_QUOTES, 'UTF-8')
        . '<div class="small text-body-secondary mt-1">Administracja i pracownicy D3 pracują normalnie — to okno służy przygotowaniom.</div>'
        . '</div></div>';
}
