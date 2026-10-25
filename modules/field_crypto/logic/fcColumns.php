<?php
/**
 * modules/field_crypto/logic/fcColumns.php — rejestr kolumn szyfrowanych (czyta go warstwa db() i cli/fieldCrypto.php).
 *
 * Dwa rodzaje wpisów:
 *   • jawne (bez 'auto')  — kod sam woła fcEncrypt/fcDecrypt (moduł rodo_register);
 *   • przezroczyste ('auto' => true) — szyfruje je warstwa db() (modules/field_crypto/lib/fcDb.php): kod zapisuje i czyta
 *     jawnie, a SZUKANIE po nich musi iść przez fcMatchPesel()/fcMatchLast5() zamiast WHERE pesel = ? / LIKE / SUBSTR.
 *
 * Kolumnę przezroczystą dopisujemy DOPIERO po przejrzeniu wszystkich zapytań SQL, które jej używają w warunkach
 * (grep po nazwie kolumny) — odczyty i zapisy nie wymagają zmian, wyszukiwania tak.
 *
 * Pola: table, column, kind (digits|email|text) = normalizacja indeksu ślepego, blind = kolumna indeksu (albo null),
 *       l5 = true dodaje indeks ostatnich 5 cyfr ({column}_l5), auto = przezroczyste.
 */

require_once dirname(__DIR__) . '/lib/fieldCrypto.php';

function fcColumns(): array {
    return [
        // — jawne (rodo_register) —
        ['table' => 'rodo_requests', 'column' => 'wnioskodawca', 'kind' => 'text',   'blind' => null],
        ['table' => 'rodo_requests', 'column' => 'email',        'kind' => 'email',  'blind' => 'email_idx'],
        ['table' => 'rodo_requests', 'column' => 'pesel',        'kind' => 'digits', 'blind' => 'pesel_idx'],
        ['table' => 'rodo_requests', 'column' => 'opis',         'kind' => 'text',   'blind' => null],
        ['table' => 'rodo_requests', 'column' => 'odpowiedz',    'kind' => 'text',   'blind' => null],
        ['table' => 'rodo_breaches', 'column' => 'opis',         'kind' => 'text',   'blind' => null],
        ['table' => 'rodo_breaches', 'column' => 'skutki',       'kind' => 'text',   'blind' => null],
        // — przezroczyste (warstwa db()): PESEL; dopisywane tabela po tabeli —
        ['table' => 'donations', 'column' => 'donor_pesel', 'kind' => 'digits', 'blind' => 'donor_pesel_idx', 'auto' => true],
        ['table' => 'pelnomocnictwa', 'column' => 'pelnomocnik_pesel', 'kind' => 'digits', 'blind' => 'pelnomocnik_pesel_idx', 'auto' => true],
        ['table' => 'skierowania_badania', 'column' => 'pesel', 'kind' => 'digits', 'blind' => 'pesel_idx', 'auto' => true],
        ['table' => 'rodo_authorizations', 'column' => 'person_pesel', 'kind' => 'digits', 'blind' => 'person_pesel_idx', 'auto' => true],
        ['table' => 'k30_clients', 'column' => 'pesel', 'kind' => 'digits', 'blind' => 'pesel_idx', 'auto' => true],
        ['table' => 'crm_contacts', 'column' => 'pesel', 'kind' => 'digits', 'blind' => 'pesel_idx', 'auto' => true],
        ['table' => 'umowy_wolontariat', 'column' => 'pesel', 'kind' => 'digits', 'blind' => 'pesel_idx', 'l5' => true, 'auto' => true],
        ['table' => 'umowy_zlecenie', 'column' => 'pesel', 'kind' => 'digits', 'blind' => 'pesel_idx', 'l5' => true, 'auto' => true],
        ['table' => 'umowy_dzielo', 'column' => 'pesel', 'kind' => 'digits', 'blind' => 'pesel_idx', 'l5' => true, 'auto' => true],
        ['table' => 'umowy_praca', 'column' => 'pesel', 'kind' => 'digits', 'blind' => 'pesel_idx', 'l5' => true, 'auto' => true],
        ['table' => 'onboarding_volunteers', 'column' => 'pesel', 'kind' => 'digits', 'blind' => 'pesel_idx', 'auto' => true],
        ['table' => 'rodo_deletion_log', 'column' => 'auth_pesel', 'kind' => 'digits', 'blind' => 'auth_pesel_idx', 'auto' => true],
    ];
}

function fcColumnExists(string $table, string $col): bool {
    try {
        foreach (db()->query("PRAGMA table_info(`{$table}`)")->fetchAll() as $c) if ($c['name'] === $col) return true;
    } catch (\Throwable $e) {}
    return false;
}

/** Zapisuje wartość kolumny zaszyfrowaną razem z indeksami ślepymi (pełnym i ostatnich 5 cyfr). */
function fcWriteColumn(array $c, int $id, string $plain): void {
    $ctx = $c['table'] . '.' . $c['column'];
    $set = "`{$c['column']}` = ?";
    $p = [fcEncrypt($plain, $ctx)];
    if (!empty($c['blind'])) { $set .= ", `{$c['blind']}` = ?"; $p[] = fcBlind($plain, $ctx, $c['kind']); }
    if (!empty($c['l5'])) {
        $d = preg_replace('/\D/', '', $plain);
        $set .= ", `{$c['column']}_l5` = ?";
        $p[] = strlen($d) >= 5 ? fcBlind(substr($d, -5), $ctx . '#l5', 'digits') : '';
    }
    $p[] = $id;
    db_exec("UPDATE `{$c['table']}` SET {$set} WHERE id = ?", $p);
}
