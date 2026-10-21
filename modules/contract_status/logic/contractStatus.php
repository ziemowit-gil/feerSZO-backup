<?php
/**
 * modules/contract_status/logic/contractStatus.php — administracyjne wycofanie / ustawienie DOWOLNEGO statusu umowy.
 *
 * Wspólne dla widoku admina (router.php) i konsoli (cli/contractStatus.php). Omija walidatory przejść
 * (przejścia ze słownika umowy_statusy, ContractStatusTransitionValidator, wymagane pliki powierzenia) — narzędzie awaryjne,
 * np. na omyłkowe „Zakończona” / „Anulowana”, z których w aplikacji nie ma wyjścia. Każda zmiana trafia
 * do dziennika umowy (contract_audit_log) z adnotacją, skąd przyszła (panel administratora / CLI).
 */

require_once dirname(__DIR__, 3) . '/includes/approval.php';   // log_contract_action()

/**
 * Umowy pasujące do numeru, nr rejestru albo id (id tylko przy podanym typie). $type '' / '-' = wszystkie typy.
 * @return array<int, array{0:string,1:array}> [[typ, wiersz], …]
 */
function contractStatusFind(string $type, string $ident, int $limit = 30): array {
    $ident = trim($ident);
    if ($ident === '') return [];
    $types = in_array($type, ['', '-'], true) ? array_keys(CONTRACT_TYPES) : [$type];
    $out = [];
    foreach ($types as $t) {
        if (!isset(CONTRACT_TYPES[$t])) continue;
        try {
            $rows = ctype_digit($ident) && count($types) === 1
                ? db_all('SELECT * FROM ' . table_for_type($t) . ' WHERE id=?', [(int)$ident])
                : db_all('SELECT * FROM ' . table_for_type($t) . ' WHERE numer_umowy=? OR nr_rejestru=? OR numer_umowy LIKE ? ORDER BY id DESC LIMIT ' . $limit,
                         [$ident, $ident, '%' . $ident . '%']);
        } catch (\Throwable $e) { $rows = []; }
        foreach ($rows as $r) $out[] = [$t, $r];
    }
    // dokładne trafienia numeru najpierw
    usort($out, fn($a, $b) => (($b[1]['numer_umowy'] ?? '') === $ident) <=> (($a[1]['numer_umowy'] ?? '') === $ident));
    return array_slice($out, 0, $limit);
}

function contractStatusRow(string $type, int $id): ?array {
    if (!isset(CONTRACT_TYPES[$type])) return null;
    try { return db_one('SELECT * FROM ' . table_for_type($type) . ' WHERE id=?', [$id]) ?: null; }
    catch (\Throwable $e) { return null; }
}

/** Historia zmian statusu z dziennika (najnowsze pierwsze): [[at, who, from, to, note], …]; from '' = nieznany. */
function contractStatusHistory(string $type, int $id): array {
    $hist = [];
    try {
        $rows = db_all("SELECT created_at, user_snapshot, note FROM contract_audit_log
                        WHERE contract_type=? AND contract_id=? ORDER BY id DESC LIMIT 300", [$type, $id]);
    } catch (\Throwable $e) { return []; }
    foreach ($rows as $h) {
        $note = (string)$h['note'];
        if (preg_match('/Zmiana statusu: (.+?) → (.+?)(?= \(|\.|$)/mu', $note, $m)
            || preg_match('/^Status: \[(.*?)\] → \[(.*?)\]/mu', $note, $m)) {
            $hist[] = ['at' => $h['created_at'], 'who' => $h['user_snapshot'], 'from' => trim($m[1]), 'to' => trim($m[2]), 'note' => $note];
        } elseif (preg_match('/^Status → (.+)$/mu', $note, $m)) {
            $hist[] = ['at' => $h['created_at'], 'who' => $h['user_snapshot'], 'from' => '', 'to' => trim($m[1]), 'note' => $note];
        }
    }
    return $hist;
}

/** Status sprzed ostatniej zmiany (do „Cofnij”) albo null, gdy dziennik tego nie mówi. */
function contractStatusUndoTarget(array $hist): ?string {
    $last = $hist[0] ?? null;
    return ($last && $last['from'] !== '' && isset(contractStatuses()[$last['from']])) ? $last['from'] : null;
}

/**
 * Ustawia status bez walidatorów i zapisuje w dzienniku. Zwraca poprzedni status.
 * $via — skąd zmiana („panel administratora” / „CLI”), $undo — czy to cofnięcie ostatniej zmiany.
 */
function contractStatusSet(string $type, array $row, string $to, string $reason, int $adminId, string $via, bool $undo = false): string {
    if (!isset(contractStatuses()[$to]) || $to === 'aneks') throw new \InvalidArgumentException('Nieznany status: ' . $to);
    $cur = (string)($row['status'] ?? '');
    if ($to === $cur) return $cur;
    $id  = (int)$row['id'];
    $upd = ['status' => $to];
    if (array_key_exists('updated_at', $row)) $upd['updated_at'] = date('Y-m-d H:i:s');
    // Wyjście z blokady — zdejmij flagę, żeby umowa nie wróciła do „zablokowana”
    if ($cur === 'zablokowana' && $to !== 'zablokowana' && array_key_exists('is_blocked', $row)) $upd['is_blocked'] = 0;
    db_update(table_for_type($type), $upd, $id);

    $admin = $adminId ? db_one('SELECT name FROM users WHERE id=?', [$adminId]) : null;
    log_contract_action($type, $id, $adminId, 'status_change',
        $via . ' (administrator' . ($admin ? ': ' . $admin['name'] : '') . '): Zmiana statusu: ' . $cur . ' → ' . $to
        . ($undo ? ' (cofnięcie)' : '') . ($reason !== '' ? '. Powód: ' . $reason : ''));
    return $cur;
}

/**
 * Rozpiska statusów umów (widok admina „Statusy umów”): wszystkie wpisy słownika umowy_statusy — także wyłączone —
 * z atrybutami, liczbą umów per typ i wartościami w danych spoza słownika.
 * @return array{rows: list<array>, types: array<string,string>, counts: array<string,array<string,int>>, unknown: array<string,array<string,int>>, dict: bool}
 */
function contractStatusMap(): array {
    $types = CONTRACT_TYPES + array_map(fn($x) => $x['label'], CONTRACT_REGISTRY_EXTRA);

    // liczba umów: typ → wartość statusu → n
    $counts = [];
    foreach (array_keys($types) as $t) {
        $table = registry_table($t);
        if (!$table) continue;
        try {
            foreach (db_all("SELECT COALESCE(status,'') AS s, COUNT(*) AS n FROM {$table} GROUP BY COALESCE(status,'')") as $r) $counts[$t][(string)$r['s']] = (int)$r['n'];
        } catch (\Throwable $e) { /* brak tabeli (moduł nieużywany) */ }
    }

    // wpisy słownika (z wyłączonymi; bez kosza) — odczyt bez serwisu, tylko do podglądu
    $entries = [];
    try {
        $entries = db_all("SELECT e.id, e.code, e.label, e.attributes, e.is_active, e.valid_from, e.valid_to
                             FROM dictionary_entries e JOIN dictionaries d ON d.id = e.dictionary_id
                            WHERE d.code = ? AND d.deleted_at IS NULL AND e.deleted_at IS NULL
                            ORDER BY e.sort_order, e.id", [CONTRACT_STATUS_DICT]);
    } catch (\Throwable $e) {}

    $rows = [];
    $today = date('Y-m-d');
    foreach ($entries as $e) {
        $attr  = json_decode((string)$e['attributes'], true) ?: [];
        $value = is_string($attr['wartosc'] ?? null) && trim($attr['wartosc']) !== '' ? trim($attr['wartosc']) : (string)$e['code'];
        $live  = (int)$e['is_active'] === 1 && (!$e['valid_from'] || $e['valid_from'] <= $today) && (!$e['valid_to'] || $e['valid_to'] >= $today);
        $rows[] = ['value' => $value, 'entry' => (string)$e['code'], 'entry_id' => (int)$e['id'], 'active' => $live]
                + _contractStatusNorm($value, ['label' => $e['label']] + $attr, _contractStatusDefault($value));
    }
    $dict = (bool)$rows;
    if (!$dict) foreach (contractStatuses() as $v => $s) $rows[] = ['value' => $v, 'entry' => contractStatusSlug($v), 'entry_id' => 0, 'active' => true] + $s;

    $known = array_column($rows, 'value');
    $unknown = [];
    foreach ($counts as $t => $byStatus) foreach ($byStatus as $s => $n) if (!in_array($s, $known, true)) $unknown[$t][$s] = $n;

    return ['rows' => $rows, 'types' => $types, 'counts' => $counts, 'unknown' => $unknown, 'dict' => $dict];
}
