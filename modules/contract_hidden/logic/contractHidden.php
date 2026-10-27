<?php
/**
 * modules/contract_hidden/logic/contractHidden.php — flaga „ukryta” na umowie (kolumna `ukryta` w tabelach umów).
 *
 * Umowa ukryta jest niewidoczna dla PERSONELU i SYSTEMU: nie wchodzi do list, rejestrów, liczników, statystyk, eksportów i powiadomień
 * zbiorczych (wszędzie tam, gdzie kod wylicza umowy wielu osób), a jej numer nie rusza numeracji RU. Właściciel (konto powiązane
 * e-mailem z umową) widzi ją normalnie w swoim panelu — po to ją mamy: do testowania panelu na koncie z prawdziwą umową bez wpływu
 * na dane organizacji. Pierwsze zastosowanie: konto testowe test@local (cli/seedPanelTest.php).
 *
 * Użycie w zapytaniach zbiorczych:   "SELECT … FROM umowy_wolontariat c WHERE 1=1" . contractHiddenSql('umowy_wolontariat', 'c')
 * contractHiddenSql() zwraca '' dla tabeli bez kolumny `ukryta` (nic się nie psuje przed migracją); zapytania „moje umowy” właściciela
 * NIE używają filtra. Kolumnę zakłada contractHiddenEnsure() (wołane przez seeder i contractSetHidden()).
 */

/** Czy tabela ma kolumnę `ukryta` (cache na żądanie). */
function contractHiddenHasColumn(string $table, bool $refresh = false): bool
{
    static $cache = [];
    if (!preg_match('/^[a-z0-9_]+$/', $table)) return false;
    if ($refresh || !array_key_exists($table, $cache)) {
        $cache[$table] = false;
        try {
            foreach (db()->query("PRAGMA table_info(`{$table}`)")->fetchAll() as $c) {
                if (($c['name'] ?? '') === 'ukryta') { $cache[$table] = true; break; }
            }
        } catch (\Throwable $e) {}
    }
    return $cache[$table];
}

/**
 * Fragment SQL wykluczający umowy ukryte, do doklejenia do WHERE (zaczyna się od „ AND”). $alias = alias tabeli w zapytaniu.
 * '' gdy tabela nie ma kolumny — bezpieczne przed migracją.
 */
function contractHiddenSql(string $table, string $alias = ''): string
{
    if (!contractHiddenHasColumn($table)) return '';
    $col = ($alias !== '' ? $alias . '.' : '') . 'ukryta';
    return " AND COALESCE({$col},0)=0";
}

/** Zakłada kolumnę `ukryta` w tabeli umów danego typu (idempotentnie). @return bool czy kolumna jest po wywołaniu */
function contractHiddenEnsure(string $table): bool
{
    if (!preg_match('/^[a-z0-9_]+$/', $table)) return false;
    if (contractHiddenHasColumn($table)) return true;
    try {
        db()->exec("ALTER TABLE `{$table}` ADD COLUMN ukryta INTEGER NOT NULL DEFAULT 0");
    } catch (\Throwable $e) { /* wyścig z drugim żądaniem — sprawdzimy niżej */ }
    return contractHiddenHasColumn($table, true);   // odśwież cache po ALTER — kolejne contractHiddenSql() w tym żądaniu już filtruje
}

/** Ustawia/zdejmuje flagę na umowie (typ z rejestru: CONTRACT_TYPES). Zapis w audit_logs. */
function contractSetHidden(string $type, int $id, bool $hidden, ?int $userId = null): void
{
    $table = table_for_type($type);
    if (!contractHiddenEnsure($table)) throw new \RuntimeException('Nie udało się założyć kolumny ukrycia w tabeli ' . $table . '.');
    db()->prepare("UPDATE `{$table}` SET ukryta=? WHERE id=?")->execute([$hidden ? 1 : 0, $id]);
    if (!function_exists('audit_log')) require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
    audit_log($hidden ? 'contract.hide' : 'contract.unhide', ['type' => $type, 'id' => $id], $userId ?? (PHP_SAPI === 'cli' ? 0 : null));
}
