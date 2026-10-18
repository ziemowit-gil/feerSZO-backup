<?php
/**
 * modules/contract_status/logic/contractStatus.php — administracyjne wycofanie / ustawienie DOWOLNEGO statusu umowy.
 *
 * Wspólne dla widoku admina (router.php) i konsoli (cli/contractStatus.php). Omija walidatory przejść
 * (STATUS_TRANSITIONS, ContractStatusTransitionValidator, wymagane pliki powierzenia) — narzędzie awaryjne,
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

function contractStatusLabel(string $s): string {
    return STATUS_LABELS[$s]['label'] ?? ($s !== '' ? $s : '—');
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
    return ($last && $last['from'] !== '' && isset(STATUS_LABELS[$last['from']])) ? $last['from'] : null;
}

/**
 * Ustawia status bez walidatorów i zapisuje w dzienniku. Zwraca poprzedni status.
 * $via — skąd zmiana („panel administratora” / „CLI”), $undo — czy to cofnięcie ostatniej zmiany.
 */
function contractStatusSet(string $type, array $row, string $to, string $reason, int $adminId, string $via, bool $undo = false): string {
    if (!isset(STATUS_LABELS[$to])) throw new \InvalidArgumentException('Nieznany status: ' . $to);
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
