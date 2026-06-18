<?php
/**
 * Rejestr byłych osób (byłych współpracowników).
 * Pola: imię, nazwisko, miasto, daty współpracy, powód odejścia, uwagi.
 * Powód odejścia i uwagi mogą być oznaczone jako wrażliwe — widoczne tylko dla zarządu.
 */

// ── Auto-migracja ─────────────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS byli_osoby (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        imie           TEXT    NOT NULL DEFAULT '',
        nazwisko       TEXT    NOT NULL DEFAULT '',
        miasto         TEXT    NOT NULL DEFAULT '',
        data_od        DATE,
        data_do        DATE,
        powod_odejscia TEXT    NOT NULL DEFAULT '',
        uwagi          TEXT    NOT NULL DEFAULT '',
        wrazliwe       INTEGER NOT NULL DEFAULT 0,
        created_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    try { db()->exec("CREATE INDEX IF NOT EXISTS idx_byli_nazwisko ON byli_osoby(nazwisko, imie)"); } catch (\Throwable $e) {}
})();

// ── Dostęp zarządu (do danych wrażliwych) ─────────────────────────────────────

if (!function_exists('is_zarzad')) {
    /**
     * Zarząd = administratorzy + osoby wskazane w ustawieniu 'zarzad_user_ids' (CSV id).
     */
    function is_zarzad(): bool {
        if (function_exists('is_admin') && is_admin()) return true;
        $u = current_user();
        if (!$u) return false;
        $ids = array_filter(array_map('intval', explode(',', (string)org_setting('zarzad_user_ids'))));
        return in_array((int)$u['id'], $ids, true);
    }
}

function zarzad_user_ids(): array {
    return array_values(array_filter(array_map('intval', explode(',', (string)org_setting('zarzad_user_ids')))));
}

function zarzad_set_user_ids(array $ids): void {
    $clean = implode(',', array_values(array_unique(array_filter(array_map('intval', $ids)))));
    org_setting_set('zarzad_user_ids', $clean);
}

// ── CRUD ──────────────────────────────────────────────────────────────────────

function byli_all(string $q = ''): array {
    if ($q !== '') {
        $like = '%' . $q . '%';
        return db_all(
            "SELECT b.*, u.name AS creator_name FROM byli_osoby b
             LEFT JOIN users u ON u.id=b.created_by
             WHERE b.imie LIKE ? OR b.nazwisko LIKE ? OR b.miasto LIKE ?
             ORDER BY b.nazwisko, b.imie",
            [$like, $like, $like]
        );
    }
    return db_all(
        "SELECT b.*, u.name AS creator_name FROM byli_osoby b
         LEFT JOIN users u ON u.id=b.created_by
         ORDER BY b.nazwisko, b.imie"
    );
}

function byli_get(int $id): ?array {
    return db_one("SELECT * FROM byli_osoby WHERE id=?", [$id]);
}

/**
 * Zapis (utworzenie lub aktualizacja).
 * $can_sensitive — czy bieżący użytkownik (zarząd) może edytować pola wrażliwe.
 * Gdy false i rekord jest wrażliwy, pola powod/uwagi/wrazliwe pozostają bez zmian.
 */
function byli_save(int $id, array $d, int $user_id, bool $can_sensitive): int {
    $imie     = trim($d['imie'] ?? '');
    $nazwisko = trim($d['nazwisko'] ?? '');
    if ($nazwisko === '') throw new \RuntimeException('Nazwisko jest wymagane.');

    $miasto  = trim($d['miasto'] ?? '');
    $data_od = ($d['data_od'] ?? '') ?: null;
    $data_do = ($d['data_do'] ?? '') ?: null;

    if ($id) {
        $existing = byli_get($id);
        if (!$existing) throw new \RuntimeException('Rekord nie istnieje.');
        // Pola wrażliwe — tylko zarząd; w przeciwnym razie zachowaj dotychczasowe
        if ($can_sensitive) {
            $powod    = trim($d['powod_odejscia'] ?? '');
            $uwagi    = trim($d['uwagi'] ?? '');
            $wrazliwe = !empty($d['wrazliwe']) ? 1 : 0;
        } else {
            $powod    = $existing['powod_odejscia'];
            $uwagi    = $existing['uwagi'];
            $wrazliwe = (int)$existing['wrazliwe'];
        }
        db()->prepare(
            "UPDATE byli_osoby SET imie=?,nazwisko=?,miasto=?,data_od=?,data_do=?,
             powod_odejscia=?,uwagi=?,wrazliwe=?,updated_at=datetime('now') WHERE id=?"
        )->execute([$imie, $nazwisko, $miasto, $data_od, $data_do, $powod, $uwagi, $wrazliwe, $id]);
        return $id;
    }

    // Nowy rekord — autor wpisuje pola wrażliwe (sam je podał)
    $powod    = trim($d['powod_odejscia'] ?? '');
    $uwagi    = trim($d['uwagi'] ?? '');
    $wrazliwe = !empty($d['wrazliwe']) ? 1 : 0;
    db()->prepare(
        "INSERT INTO byli_osoby (imie,nazwisko,miasto,data_od,data_do,powod_odejscia,uwagi,wrazliwe,created_by)
         VALUES (?,?,?,?,?,?,?,?,?)"
    )->execute([$imie, $nazwisko, $miasto, $data_od, $data_do, $powod, $uwagi, $wrazliwe, $user_id]);
    return (int)db()->lastInsertId();
}

function byli_delete(int $id): void {
    db()->prepare("DELETE FROM byli_osoby WHERE id=?")->execute([$id]);
}

/** Czy bieżący użytkownik widzi pola wrażliwe danego rekordu. */
function byli_can_see_sensitive(array $row): bool {
    return empty($row['wrazliwe']) || is_zarzad();
}

/**
 * Dopasowanie po imieniu i nazwisku — używane przy zawieraniu umowy, by ostrzec,
 * że osoba figuruje w rejestrze byłych współpracowników.
 *
 * Porównanie odbywa się w PHP (mb_strtolower), aby poprawnie obsłużyć polskie znaki
 * (SQLite LOWER/LIKE nie składa diakrytyków). Rejestr jest niewielki, więc skanujemy całość.
 *
 * Trafienie, gdy wpisana nazwa:
 *   • jest dokładnie „Imię Nazwisko" lub „Nazwisko Imię", albo
 *   • zawiera nazwisko (i imię, jeśli wpisane w rejestrze) — np. „Jan Adam Kowalski".
 *
 * @return array<int,array> surowe wiersze byli_osoby
 */
function byli_match_name(string $name): array {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if (function_exists('mb_strlen') ? mb_strlen($name) < 3 : strlen($name) < 3) return [];

    $lower  = static fn(string $s): string =>
        function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $needle = $lower($name);

    $out = [];
    foreach (db_all("SELECT * FROM byli_osoby") as $r) {
        $imie = $lower(trim((string)$r['imie']));
        $nazw = $lower(trim((string)$r['nazwisko']));
        if ($nazw === '') continue;

        $full1 = trim($imie . ' ' . $nazw);
        $full2 = trim($nazw . ' ' . $imie);

        $hit = ($needle === $full1 || $needle === $full2);
        if (!$hit) {
            $hasNazw = strpos($needle, $nazw) !== false;
            $hasImie = ($imie === '' || strpos($needle, $imie) !== false);
            $hit = $hasNazw && $hasImie;
        }
        if ($hit) $out[] = $r;
    }
    return $out;
}
