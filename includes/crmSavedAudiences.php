<?php
/**
 * includes/crmSavedAudiences.php — zapisane listy adresatów wysyłki masowej.
 * Lista przechowuje KRYTERIA (grupy, tagi, zestawy umów), nie osoby — przy każdej wysyłce przelicza się na nowo.
 * Widoczność: własne oraz udostępnione (shared=1).
 */
function crm_audiences_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS crm_saved_audiences (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        criteria TEXT NOT NULL,
        shared INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT,
        updated_at TEXT
    )");
}

/** @return list<array{id:int,name:string,shared:int,mine:bool,criteria:array}> */
function crm_audiences_list(int $uid): array {
    crm_audiences_schema();
    $out = [];
    foreach (db_all("SELECT * FROM crm_saved_audiences WHERE shared=1 OR created_by=? ORDER BY name COLLATE NOCASE", [$uid]) as $r) {
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'shared' => (int)$r['shared'],
                  'mine' => (int)$r['created_by'] === $uid, 'criteria' => json_decode((string)$r['criteria'], true) ?: []];
    }
    return $out;
}

/** Czyści kryteria do znanych kluczy i typów. */
function crm_audience_criteria(array $in): array {
    $groups = array_values(array_unique(array_filter(array_map('intval', (array)($in['group_ids'] ?? [])))));
    $tags   = array_values(array_unique(array_filter(array_map('trim', is_array($in['tags'] ?? null) ? $in['tags'] : explode(',', (string)($in['tags'] ?? ''))))));
    $sets   = array_values(array_unique(array_filter(array_map('strval', (array)($in['contract_sets'] ?? [])),
                fn($s) => (bool)preg_match('/^[a-z_]+:(active|expired)$/', $s))));
    $cats   = array_values(array_unique(array_filter(array_map(fn($c) => preg_replace('/[^a-z0-9_]/', '', (string)$c), (array)($in['categories'] ?? [])))));
    return ['group_ids' => $groups, 'tags' => $tags, 'contract_sets' => $sets, 'categories' => $cats];
}

function crm_audience_save(int $uid, string $name, array $criteria, bool $shared): int {
    crm_audiences_schema();
    $name = trim($name);
    $c = crm_audience_criteria($criteria);
    $now = date('Y-m-d H:i:s');
    $existing = db_one("SELECT id FROM crm_saved_audiences WHERE created_by=? AND LOWER(name)=LOWER(?)", [$uid, $name]);
    if ($existing) {
        db()->prepare("UPDATE crm_saved_audiences SET criteria=?, shared=?, updated_at=? WHERE id=?")
            ->execute([json_encode($c, JSON_UNESCAPED_UNICODE), $shared ? 1 : 0, $now, (int)$existing['id']]);
        return (int)$existing['id'];
    }
    return (int)db_insert('crm_saved_audiences', ['name' => $name, 'criteria' => json_encode($c, JSON_UNESCAPED_UNICODE),
        'shared' => $shared ? 1 : 0, 'created_by' => $uid, 'created_at' => $now, 'updated_at' => $now]);
}

function crm_audience_delete(int $uid, int $id, bool $admin): bool {
    crm_audiences_schema();
    $r = db_one("SELECT created_by FROM crm_saved_audiences WHERE id=?", [$id]);
    if (!$r || ((int)$r['created_by'] !== $uid && !$admin)) return false;
    db()->prepare("DELETE FROM crm_saved_audiences WHERE id=?")->execute([$id]);
    return true;
}
