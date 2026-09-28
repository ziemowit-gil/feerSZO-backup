<?php
/**
 * modules/selfrepairDB/logic/selfRepairDB.php — samonaprawa bazy.
 *
 * ZASADA PROJEKTU (od 2026-09-29): każda JEDNORAZOWA migracja (przemapowanie
 * danych, uzupełnienie wstecz, dopisek do komunikatu…) trafia do rejestru
 * modules/selfrepairDB/logic/migrations.php — nie do *_migrate() modułów.
 * *_migrate() trzymają tylko idempotentny schemat (CREATE/ALTER).
 *
 * Dwa mechanizmy:
 *
 * 1) Bramka schematu — szo_schema_current($nazwa, __FILE__):
 *    *_migrate() wykonuje się RAZ na wersję pliku (mtime + rozmiar zapisane
 *    w settings jako 'schema:<nazwa>'), a nie przy każdym żądaniu. Wcześniej
 *    panel TI wysyłał ~450 instrukcji DDL na KAŻDE wejście (272 kończyły się
 *    „duplicate column”) — każda sięga po blokadę zapisu SQLite, więc przy
 *    kilku osobach naraz strony czekały na siebie. Zmiana pliku (git pull) →
 *    migracja wykona się raz ponownie; znacznik stawiany na końcu żądania,
 *    tylko bez błędu krytycznego.
 *
 * 2) Migracje jednorazowe — rejestr w migrations.php, uruchamiane RAZ NA
 *    ZAWSZE (settings 'selfrepair:<id>'), na końcu żądania (schemat już
 *    istnieje), każda w osobnym try; znacznik tylko po sukcesie, więc
 *    nieudana spróbuje ponownie przy następnym żądaniu.
 *
 * szo_schema_reset() (cli/migrate.php, upgrade.php) wymusza pełną migrację
 * schematu; migracji jednorazowych nie kasuje — te z definicji już się nie
 * powtarzają. Awaryjnie: define('SZO_SCHEMA_ALWAYS', true) wyłącza bramkę.
 */

/** Znaczniki 'schema:%' i 'selfrepair:%' — jedno zapytanie na żądanie. */
function &_szo_selfrepair_marks(): array {
    static $marks = null;
    if ($marks === null) {
        $marks = [];
        try {
            foreach (db()->query("SELECT key_, value FROM settings WHERE key_ LIKE 'schema:%' OR key_ LIKE 'selfrepair:%'")->fetchAll() as $r) {
                $marks[(string)$r['key_']] = (string)$r['value'];
            }
        } catch (\Throwable $e) { /* brak tabeli settings (instalacja) — wszystko do wykonania */ }
    }
    return $marks;
}

function _szo_selfrepair_set(string $key, string $value): void {
    try { db()->prepare("REPLACE INTO settings (key_, value) VALUES (?, ?)")->execute([$key, $value]); } catch (\Throwable $e) {}
}

function _szo_request_failed(): bool {
    $err = error_get_last();
    return $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
}

/**
 * Bramka schematu. Na początku *_migrate():
 *     if (szo_schema_current('nazwa_migrate', __FILE__)) return;
 */
function szo_schema_current(string $name, string $file): bool {
    szo_selfrepair_schedule();                                   // jednorazowe — na końcu żądania
    if (defined('SZO_SCHEMA_ALWAYS')) return false;
    $marks = &_szo_selfrepair_marks();
    $sig = (string)@filemtime($file) . ':' . (string)@filesize($file);
    $key = 'schema:' . $name;
    if (($marks[$key] ?? '') === $sig) return true;
    $marks[$key] = $sig;                                         // w tym żądaniu już nie pytaj
    register_shutdown_function(static function () use ($key, $sig): void {
        if (!_szo_request_failed()) _szo_selfrepair_set($key, $sig);
    });
    return false;
}

/** Kasuje znaczniki bramki schematu — następne żądanie wykona wszystkie *_migrate(). */
function szo_schema_reset(): void {
    try { db()->exec("DELETE FROM settings WHERE key_ LIKE 'schema:%'"); } catch (\Throwable $e) {}
    $marks = &_szo_selfrepair_marks();
    foreach (array_keys($marks) as $k) if (str_starts_with($k, 'schema:')) unset($marks[$k]);
}

/** Planuje wykonanie zaległych migracji jednorazowych na końcu żądania (raz). */
function szo_selfrepair_schedule(): void {
    static $scheduled = false;
    if ($scheduled || defined('SZO_SELFREPAIR_OFF')) return;
    $scheduled = true;
    register_shutdown_function(static function (): void {
        if (!_szo_request_failed()) szo_selfrepair_run();
    });
}

/**
 * Wykonuje zaległe migracje jednorazowe z rejestru. Zwraca [id => 'ok'|'błąd: …']
 * dla uruchomionych w tym wywołaniu (CLI: cli/migrate.php wypisuje wynik).
 */
function szo_selfrepair_run(): array {
    static $running = false;
    if ($running) return [];
    $running = true;
    $out = [];
    try {
        $registry = require __DIR__ . '/migrations.php';
        $marks = &_szo_selfrepair_marks();
        foreach ($registry as $id => $m) {
            $key = 'selfrepair:' . $id;
            if (isset($marks[$key])) continue;
            try {
                ($m['run'])();
                $marks[$key] = date('Y-m-d H:i:s');
                _szo_selfrepair_set($key, $marks[$key]);
                $out[$id] = 'ok';
            } catch (\Throwable $e) {
                $out[$id] = 'błąd: ' . $e->getMessage();           // bez znacznika — ponowi się
            }
        }
    } catch (\Throwable $e) {
        $out['_rejestr'] = 'błąd: ' . $e->getMessage();
    } finally {
        $running = false;
    }
    return $out;
}
