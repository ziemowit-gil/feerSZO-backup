<?php
/**
 * Rejestr pełnomocnictw — jedyne źródło prawdy w SZO (samodzielny moduł, poza EZD,
 * bez wymogu zakładania sprawy/koszulki). Pola wzorowane na dawnym rejestrze EZD
 * opartym o klasę JRWA 013 (ezd/pelnomocnictwa/ przekierowuje tu), prowadzony jako
 * jedna, prosta tabela w stylu rejestru byłych osób (includes/byli.php).
 *
 * Status (Ważne / Wygasłe / Odwołane) jest wyliczany z dat, nie przechowywany.
 */

// ── Auto-migracja ─────────────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS pelnomocnictwa (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        numer           TEXT    NOT NULL DEFAULT '',
        mocodawca       TEXT    NOT NULL DEFAULT '',
        pelnomocnik     TEXT    NOT NULL DEFAULT '',
        zakres          TEXT    NOT NULL DEFAULT '',
        forma           TEXT    NOT NULL DEFAULT '',
        data_udzielenia DATE,
        data_waznosci   DATE,
        data_odwolania  DATE,
        uwagi           TEXT    NOT NULL DEFAULT '',
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    try { db()->exec("CREATE INDEX IF NOT EXISTS idx_pelnomocnictwa_numer ON pelnomocnictwa(numer)"); } catch (\Throwable $e) {}
})();

// ── Status wyliczany z dat ─────────────────────────────────────────────────────

/** @return string 'odwolane'|'wygasle'|'wazne' */
function pelnomocnictwo_status(array $row): string {
    $today = date('Y-m-d');
    if (!empty($row['data_odwolania']) && $row['data_odwolania'] <= $today) return 'odwolane';
    if (!empty($row['data_waznosci']) && $row['data_waznosci'] < $today)   return 'wygasle';
    return 'wazne';
}

/** @return array{0:string,1:string} [etykieta, klasa koloru bootstrap] */
function pelnomocnictwo_status_label(string $status): array {
    return match ($status) {
        'odwolane' => ['Odwołane', 'secondary'],
        'wygasle'  => ['Wygasłe',  'danger'],
        default    => ['Ważne',    'success'],
    };
}

function pelnomocnictwa_statuses(): array {
    return ['wazne' => 'Ważne', 'wygasle' => 'Wygasłe', 'odwolane' => 'Odwołane'];
}

// ── Numeracja ──────────────────────────────────────────────────────────────────

/** Auto-numer w formacie P/{nr:04d}/{rok} — licznik roczny wg roku utworzenia wpisu. */
function pelnomocnictwa_suggest_numer(?int $year = null): string {
    $year = $year ?: (int)date('Y');
    $cnt = (int)(db_one(
        "SELECT COUNT(*) AS cnt FROM pelnomocnictwa WHERE strftime('%Y', created_at) = ?",
        [(string)$year]
    )['cnt'] ?? 0);
    return sprintf('P/%04d/%d', $cnt + 1, $year);
}

// ── CRUD ──────────────────────────────────────────────────────────────────────

/**
 * @param array{q?:string,status?:string,rok?:int} $f
 */
function pelnomocnictwa_all(array $f = []): array {
    $where  = ['1=1'];
    $params = [];

    $q = trim($f['q'] ?? '');
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(p.numer LIKE ? OR p.mocodawca LIKE ? OR p.pelnomocnik LIKE ? OR p.zakres LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($f['rok'])) {
        $where[] = "strftime('%Y', p.created_at) = ?";
        $params[] = (string)(int)$f['rok'];
    }

    $rows = db_all(
        "SELECT p.*, u.name AS creator_name FROM pelnomocnictwa p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE " . implode(' AND ', $where) . "
         ORDER BY p.data_udzielenia DESC, p.id DESC",
        $params
    );

    $status = trim($f['status'] ?? '');
    if ($status !== '') {
        $rows = array_values(array_filter($rows, fn($r) => pelnomocnictwo_status($r) === $status));
    }
    return $rows;
}

function pelnomocnictwo_get(int $id): ?array {
    return db_one("SELECT * FROM pelnomocnictwa WHERE id=?", [$id]);
}

/** Zapis (utworzenie lub aktualizacja). Zwraca id wpisu. */
function pelnomocnictwo_save(int $id, array $d, ?int $user_id): int {
    $mocodawca   = trim($d['mocodawca'] ?? '');
    $pelnomocnik = trim($d['pelnomocnik'] ?? '');
    if ($mocodawca === '')   throw new \RuntimeException('Mocodawca jest wymagany.');
    if ($pelnomocnik === '') throw new \RuntimeException('Pełnomocnik jest wymagany.');

    $zakres          = trim($d['zakres'] ?? '');
    $forma           = trim($d['forma'] ?? '');
    $data_udzielenia = ($d['data_udzielenia'] ?? '') ?: null;
    $data_waznosci   = ($d['data_waznosci'] ?? '') ?: null;
    $data_odwolania  = ($d['data_odwolania'] ?? '') ?: null;
    $uwagi           = trim($d['uwagi'] ?? '');

    if ($id) {
        $numer = trim($d['numer'] ?? '');
        if ($numer === '') {
            $existing = pelnomocnictwo_get($id);
            if (!$existing) throw new \RuntimeException('Wpis nie istnieje.');
            $numer = $existing['numer'];
        }
        db()->prepare(
            "UPDATE pelnomocnictwa SET numer=?,mocodawca=?,pelnomocnik=?,zakres=?,forma=?,
             data_udzielenia=?,data_waznosci=?,data_odwolania=?,uwagi=?,updated_at=datetime('now') WHERE id=?"
        )->execute([$numer, $mocodawca, $pelnomocnik, $zakres, $forma, $data_udzielenia, $data_waznosci, $data_odwolania, $uwagi, $id]);
        return $id;
    }

    $numer = trim($d['numer'] ?? '') ?: pelnomocnictwa_suggest_numer();
    db()->prepare(
        "INSERT INTO pelnomocnictwa (numer,mocodawca,pelnomocnik,zakres,forma,data_udzielenia,data_waznosci,data_odwolania,uwagi,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    )->execute([$numer, $mocodawca, $pelnomocnik, $zakres, $forma, $data_udzielenia, $data_waznosci, $data_odwolania, $uwagi, $user_id]);
    return (int)db()->lastInsertId();
}

function pelnomocnictwo_delete(int $id): void {
    db()->prepare("DELETE FROM pelnomocnictwa WHERE id=?")->execute([$id]);
}

/** Statystyki do widżetu/dashboardu: liczba wg statusu. */
function pelnomocnictwa_stats(): array {
    $out = ['wazne' => 0, 'wygasle' => 0, 'odwolane' => 0, 'total' => 0];
    foreach (db_all("SELECT data_waznosci, data_odwolania FROM pelnomocnictwa") as $r) {
        $out[pelnomocnictwo_status($r)]++;
        $out['total']++;
    }
    return $out;
}
