<?php
/**
 * modules/seeders/lib/seedersMeta.php — ustawienia seederów nadawane przez administratora
 * (Admin › Seedery › Zarządzanie, /seeder-test/zarzadzanie) i statystyka uruchomień.
 *
 * Rejestr (seedersRegistry.php) zostaje źródłem prawdy o skryptach, polach i akcjach; tabela
 * seeders_meta tylko nadpisuje to, co widzi człowiek: nazwę, grupę, kolejność, ukrycie oraz
 * dokłada opis administratora („do czego, kiedy, jak posprzątać”). Pusty wpis = wartości z rejestru.
 */

function seedersMetaMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS seeders_meta (
            seeder_key    VARCHAR(64) PRIMARY KEY,
            title         TEXT,
            group_name    TEXT,
            note          TEXT,
            sort          INTEGER,
            hidden        INTEGER NOT NULL DEFAULT 0,
            updated_by    INTEGER,
            updated_at    DATETIME,
            run_count     INTEGER NOT NULL DEFAULT 0,
            last_run_at   DATETIME,
            last_run_by   INTEGER,
            last_run_code INTEGER,
            last_run_action TEXT
        )");
    } catch (\Throwable $e) { /* już jest */ }
}

/** Wszystkie wpisy: klucz seedera => wiersz. */
function seedersMetaAll(): array
{
    seedersMetaMigrate();
    $out = [];
    try {
        foreach (db_all("SELECT m.*, u1.name AS updated_by_name, u2.name AS last_run_by_name
                           FROM seeders_meta m
                           LEFT JOIN users u1 ON u1.id = m.updated_by
                           LEFT JOIN users u2 ON u2.id = m.last_run_by") as $r) {
            $out[$r['seeder_key']] = $r;
        }
    } catch (\Throwable $e) {}
    return $out;
}

/** Wstawia pusty wiersz dla klucza, jeśli go nie ma (upsert bez składni zależnej od bazy). */
function _seedersMetaEnsure(string $key): void
{
    seedersMetaMigrate();
    if (!db_one("SELECT seeder_key FROM seeders_meta WHERE seeder_key=?", [$key])) {
        db()->prepare("INSERT INTO seeders_meta (seeder_key) VALUES (?)")->execute([$key]);
    }
}

/** Zapis ustawień administratora. Puste pola = wartość z rejestru. */
function seedersMetaSave(string $key, array $in, int $uid): void
{
    _seedersMetaEnsure($key);
    $clean = fn($v, int $max) => ($v = trim((string)$v)) === '' ? null : mb_substr($v, 0, $max, 'UTF-8');
    $sort  = trim((string)($in['sort'] ?? ''));
    db()->prepare("UPDATE seeders_meta SET title=?, group_name=?, note=?, sort=?, hidden=?, updated_by=?, updated_at=?
                    WHERE seeder_key=?")
        ->execute([
            $clean($in['title'] ?? '', 120),
            $clean($in['group_name'] ?? '', 60),
            $clean($in['note'] ?? '', 4000),
            $sort === '' ? null : (int)$sort,
            !empty($in['hidden']) ? 1 : 0,
            $uid, date('Y-m-d H:i:s'), $key,
        ]);
}

/** Przywraca wartości z rejestru (opis administratora i statystyka uruchomień zostają). */
function seedersMetaReset(string $key, int $uid): void
{
    _seedersMetaEnsure($key);
    db()->prepare("UPDATE seeders_meta SET title=NULL, group_name=NULL, sort=NULL, hidden=0, updated_by=?, updated_at=?
                    WHERE seeder_key=?")->execute([$uid, date('Y-m-d H:i:s'), $key]);
}

/** Odnotowanie uruchomienia (licznik, kto, kiedy, kod wyjścia). */
function seedersMetaRecordRun(string $key, string $actionLabel, ?int $code, int $uid): void
{
    try {
        _seedersMetaEnsure($key);
        db()->prepare("UPDATE seeders_meta SET run_count=run_count+1, last_run_at=?, last_run_by=?, last_run_code=?, last_run_action=?
                        WHERE seeder_key=?")->execute([date('Y-m-d H:i:s'), $uid ?: null, $code, $actionLabel, $key]);
    } catch (\Throwable $e) { /* statystyka nie może blokować uruchomienia */ }
}

/**
 * Rejestr z nałożonymi ustawieniami: title/group nadpisane, 'note' (opis administratora),
 * 'hidden', 'meta' (cały wiersz), 'reg' (oryginalne title/group z rejestru). Kolejność: grupa
 * w kolejności pierwszego wystąpienia, w grupie wg 'sort' (puste na końcu), potem jak w rejestrze.
 */
function seedersApplyMeta(array $registry, bool $withHidden = false): array
{
    $meta = seedersMetaAll();
    $out = []; $i = 0;
    foreach ($registry as $key => $e) {
        $m = $meta[$key] ?? [];
        $e['reg']    = ['title' => $e['title'], 'group' => $e['group']];
        $e['title']  = ($m['title'] ?? '') !== '' ? $m['title'] : $e['title'];
        $e['group']  = ($m['group_name'] ?? '') !== '' ? $m['group_name'] : $e['group'];
        $e['note']   = (string)($m['note'] ?? '');
        $e['hidden'] = !empty($m['hidden']);
        $e['meta']   = $m;
        $e['_ord']   = [$m['sort'] ?? null, $i++];
        if ($e['hidden'] && !$withHidden) continue;
        $out[$key] = $e;
    }
    $groupOrder = array_flip(array_values(array_unique(array_column($out, 'group'))));
    uasort($out, function ($a, $b) use ($groupOrder) {
        $g = $groupOrder[$a['group']] <=> $groupOrder[$b['group']];
        if ($g) return $g;
        [$sa, $ia] = $a['_ord']; [$sb, $ib] = $b['_ord'];
        if ($sa !== $sb) return $sa === null ? 1 : ($sb === null ? -1 : (int)$sa <=> (int)$sb);
        return $ia <=> $ib;
    });
    return $out;
}
