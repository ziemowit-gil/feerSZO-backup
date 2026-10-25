<?php
/**
 * modules/field_crypto/logic/fcColumns.php — rejestr kolumn szyfrowanych (czytany przez cli/fieldCrypto.php).
 *
 * Kolumnę dopisujemy tu DOPIERO wtedy, gdy każdy odczyt i zapis w kodzie przechodzi przez fcEncrypt / fcDecrypt
 * (a wyszukiwanie po niej przez indeks ślepy). Dopisanie kolumny, którą kod czyta wprost (np. WHERE pesel = ?, LIKE,
 * ORDER BY, JOIN), po `encrypt --apply` zepsułoby te miejsca — dlatego rejestr jest jawny, a nie „wszystkie PESEL-e”.
 *
 * Pole: table, column, kind (digits|email|text) = jak normalizować indeks ślepy, blind = kolumna z indeksem (albo null).
 */

require_once dirname(__DIR__) . '/lib/fieldCrypto.php';

function fcColumns(): array {
    return [
        ['table' => 'rodo_requests', 'column' => 'wnioskodawca', 'kind' => 'text',   'blind' => null],
        ['table' => 'rodo_requests', 'column' => 'email',        'kind' => 'email',  'blind' => 'email_idx'],
        ['table' => 'rodo_requests', 'column' => 'pesel',        'kind' => 'digits', 'blind' => 'pesel_idx'],
        ['table' => 'rodo_requests', 'column' => 'opis',         'kind' => 'text',   'blind' => null],
        ['table' => 'rodo_requests', 'column' => 'odpowiedz',    'kind' => 'text',   'blind' => null],
        ['table' => 'rodo_breaches', 'column' => 'opis',         'kind' => 'text',   'blind' => null],
        ['table' => 'rodo_breaches', 'column' => 'skutki',       'kind' => 'text',   'blind' => null],
    ];
}

function fcColumnExists(string $table, string $col): bool {
    try {
        foreach (db()->query("PRAGMA table_info(`{$table}`)")->fetchAll() as $c) if ($c['name'] === $col) return true;
    } catch (\Throwable $e) {}
    return false;
}

/** Zapisuje wartość kolumny zaszyfrowaną (i jej indeks ślepy, jeśli kolumna ma indeks). */
function fcWriteColumn(array $c, int $id, string $plain): void {
    $ctx = $c['table'] . '.' . $c['column'];
    $set = "`{$c['column']}` = ?";
    $p = [fcEncrypt($plain, $ctx)];
    if (!empty($c['blind'])) { $set .= ", `{$c['blind']}` = ?"; $p[] = fcBlind($plain, $ctx, $c['kind']); }
    $p[] = $id;
    db_exec("UPDATE `{$c['table']}` SET {$set} WHERE id = ?", $p);
}
