<?php
/**
 * includes/wsparcie_ou.php — Ewidencja wsparcia zewnętrznego OU.
 *
 * Rejestr godzin wsparcia świadczonego przez podmioty zewnętrzne (identyfikowane
 * po KRS) w rozbiciu na miesiące, z prostą ścieżką zatwierdzenia/odrzucenia.
 * Dane podmiotu pobierane z rejestru KRS (includes/krs.php) i zapisywane jako
 * snapshot na wpisie.
 */

// ── Auto-migracja ─────────────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS wsparcie_ou (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        miesiac          TEXT    NOT NULL DEFAULT '',
        podmiot_krs      TEXT    NOT NULL DEFAULT '',
        podmiot_nazwa    TEXT    NOT NULL DEFAULT '',
        podmiot_nip      TEXT    NOT NULL DEFAULT '',
        podmiot_regon    TEXT    NOT NULL DEFAULT '',
        podmiot_adres    TEXT    NOT NULL DEFAULT '',
        liczba_godzin    REAL    NOT NULL DEFAULT 0,
        status           TEXT    NOT NULL DEFAULT 'oczekuje',
        powod_odrzucenia TEXT    NOT NULL DEFAULT '',
        created_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        decided_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
        decided_at       DATETIME,
        updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    try { db()->exec("CREATE INDEX IF NOT EXISTS idx_wsparcie_ou_mies ON wsparcie_ou(miesiac, status)"); } catch (\Throwable $e) {}
    try { db()->exec("CREATE INDEX IF NOT EXISTS idx_wsparcie_ou_krs  ON wsparcie_ou(podmiot_krs)"); } catch (\Throwable $e) {}
})();

// ── Stałe ──────────────────────────────────────────────────────────────────
const WSPARCIE_OU_STATUSES = [
    'oczekuje'     => ['label' => 'Oczekuje',     'class' => 'warning'],
    'zatwierdzony' => ['label' => 'Zatwierdzony', 'class' => 'success'],
    'odrzucony'    => ['label' => 'Odrzucony',    'class' => 'danger'],
];

/** Domyślny miesiąc ewidencji = poprzedni (zamknięty) miesiąc, format YYYY-MM. */
function wsparcie_ou_default_month(): string {
    return date('Y-m', strtotime('first day of last month'));
}

function wsparcie_ou_status_badge(string $st): string {
    $m = WSPARCIE_OU_STATUSES[$st] ?? ['label' => $st, 'class' => 'secondary'];
    return '<span class="badge bg-' . $m['class'] . '">' . h($m['label']) . '</span>';
}

// ── Zapytania ────────────────────────────────────────────────────────────────

function wsparcie_ou_get(int $id): ?array {
    return db_one(
        "SELECT w.*, u.name AS created_name, d.name AS decided_name
         FROM wsparcie_ou w
         LEFT JOIN users u ON u.id = w.created_by
         LEFT JOIN users d ON d.id = w.decided_by
         WHERE w.id = ?", [$id]
    );
}

function wsparcie_ou_all(array $f = []): array {
    $w = []; $p = [];
    if (!empty($f['miesiac'])) { $w[] = "w.miesiac = ?"; $p[] = $f['miesiac']; }
    if (!empty($f['status']))  { $w[] = "w.status = ?";  $p[] = $f['status']; }
    if (!empty($f['q'])) {
        $w[] = "(LOWER(w.podmiot_nazwa) LIKE ? OR w.podmiot_krs LIKE ? OR w.podmiot_nip LIKE ?)";
        $like = '%' . strtolower($f['q']) . '%';
        $p[] = $like; $p[] = '%' . $f['q'] . '%'; $p[] = '%' . $f['q'] . '%';
    }
    $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
    return db_all(
        "SELECT w.*, u.name AS created_name, d.name AS decided_name
         FROM wsparcie_ou w
         LEFT JOIN users u ON u.id = w.created_by
         LEFT JOIN users d ON d.id = w.decided_by
         $where
         ORDER BY w.miesiac DESC, w.podmiot_nazwa", $p
    );
}

function wsparcie_ou_months(): array {
    return array_column(db_all("SELECT DISTINCT miesiac FROM wsparcie_ou ORDER BY miesiac DESC"), 'miesiac');
}

function wsparcie_ou_create(array $d, int $user_id): int {
    $mies = preg_match('/^\d{4}-\d{2}$/', (string)($d['miesiac'] ?? '')) ? $d['miesiac'] : wsparcie_ou_default_month();
    db()->prepare(
        "INSERT INTO wsparcie_ou
           (miesiac, podmiot_krs, podmiot_nazwa, podmiot_nip, podmiot_regon, podmiot_adres, liczba_godzin, status, created_by)
         VALUES (:mies,:krs,:nazwa,:nip,:regon,:adres,:godz,'oczekuje',:by)"
    )->execute([
        ':mies'  => $mies,
        ':krs'   => preg_replace('/\D/', '', (string)($d['podmiot_krs'] ?? '')),
        ':nazwa' => trim((string)($d['podmiot_nazwa'] ?? '')),
        ':nip'   => preg_replace('/\D/', '', (string)($d['podmiot_nip'] ?? '')),
        ':regon' => trim((string)($d['podmiot_regon'] ?? '')),
        ':adres' => trim((string)($d['podmiot_adres'] ?? '')),
        ':godz'  => max(0, (float)str_replace(',', '.', (string)($d['liczba_godzin'] ?? '0'))),
        ':by'    => $user_id,
    ]);
    return (int)db()->lastInsertId();
}

function wsparcie_ou_update(int $id, array $d, int $user_id): void {
    $mies = preg_match('/^\d{4}-\d{2}$/', (string)($d['miesiac'] ?? '')) ? $d['miesiac'] : wsparcie_ou_default_month();
    db()->prepare(
        "UPDATE wsparcie_ou SET
           miesiac=:mies, podmiot_krs=:krs, podmiot_nazwa=:nazwa, podmiot_nip=:nip,
           podmiot_regon=:regon, podmiot_adres=:adres, liczba_godzin=:godz, updated_at=CURRENT_TIMESTAMP
         WHERE id=:id"
    )->execute([
        ':mies'  => $mies,
        ':krs'   => preg_replace('/\D/', '', (string)($d['podmiot_krs'] ?? '')),
        ':nazwa' => trim((string)($d['podmiot_nazwa'] ?? '')),
        ':nip'   => preg_replace('/\D/', '', (string)($d['podmiot_nip'] ?? '')),
        ':regon' => trim((string)($d['podmiot_regon'] ?? '')),
        ':adres' => trim((string)($d['podmiot_adres'] ?? '')),
        ':godz'  => max(0, (float)str_replace(',', '.', (string)($d['liczba_godzin'] ?? '0'))),
        ':id'    => $id,
    ]);
}

/** Zatwierdzenie / odrzucenie wpisu. Powód wymagany przy odrzuceniu. */
function wsparcie_ou_set_status(int $id, string $status, string $reason, int $user_id): void {
    if (!array_key_exists($status, WSPARCIE_OU_STATUSES)) {
        throw new \RuntimeException('Nieprawidłowy status.');
    }
    if ($status === 'odrzucony' && trim($reason) === '') {
        throw new \RuntimeException('Podaj powód odrzucenia.');
    }
    db()->prepare(
        "UPDATE wsparcie_ou SET status=:st, powod_odrzucenia=:reason,
                decided_by=:by, decided_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
         WHERE id=:id"
    )->execute([
        ':st'     => $status,
        ':reason' => $status === 'odrzucony' ? trim($reason) : '',
        ':by'     => $user_id,
        ':id'     => $id,
    ]);
}

function wsparcie_ou_delete(int $id, int $user_id): void {
    db()->prepare("DELETE FROM wsparcie_ou WHERE id=?")->execute([$id]);
}

function wsparcie_ou_stats(?string $miesiac = null): array {
    $w = []; $p = [];
    if ($miesiac) { $w[] = "miesiac=?"; $p[] = $miesiac; }
    $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
    $row = db_one(
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM(liczba_godzin),0) AS godz_all,
                COALESCE(SUM(CASE WHEN status='zatwierdzony' THEN liczba_godzin ELSE 0 END),0) AS godz_ok,
                COALESCE(SUM(CASE WHEN status='oczekuje' THEN 1 ELSE 0 END),0) AS oczekuje,
                COALESCE(SUM(CASE WHEN status='zatwierdzony' THEN 1 ELSE 0 END),0) AS zatw,
                COALESCE(SUM(CASE WHEN status='odrzucony' THEN 1 ELSE 0 END),0) AS odrz
         FROM wsparcie_ou $where", $p
    ) ?: [];
    return [
        'cnt'      => (int)($row['cnt'] ?? 0),
        'godz_all' => (float)($row['godz_all'] ?? 0),
        'godz_ok'  => (float)($row['godz_ok'] ?? 0),
        'oczekuje' => (int)($row['oczekuje'] ?? 0),
        'zatw'     => (int)($row['zatw'] ?? 0),
        'odrz'     => (int)($row['odrz'] ?? 0),
    ];
}

/** Liczba wpisów oczekujących (badge w menu). */
function wsparcie_ou_pending_count(): int {
    return (int)(db_one("SELECT COUNT(*) AS c FROM wsparcie_ou WHERE status='oczekuje'")['c'] ?? 0);
}

/**
 * Godziny wsparcia tego samego podmiotu za poprzedni miesiąc (suma wpisów).
 * Dopasowanie po KRS (gdy jest), w innym wypadku po nazwie.
 * @return array{prev:string, hours:?float} hours=null gdy brak wpisu za poprzedni miesiąc
 */
function wsparcie_ou_prev_month_hours(string $krs, string $nazwa, string $miesiac): array {
    if (!preg_match('/^\d{4}-\d{2}$/', $miesiac)) return ['prev' => '', 'hours' => null];
    $prev = date('Y-m', strtotime($miesiac . '-01 -1 month'));
    $krs  = preg_replace('/\D/', '', $krs);
    if ($krs !== '') {
        $r = db_one("SELECT COALESCE(SUM(liczba_godzin),0) AS g, COUNT(*) AS c FROM wsparcie_ou WHERE miesiac=? AND podmiot_krs=?", [$prev, $krs]);
    } else {
        $r = db_one("SELECT COALESCE(SUM(liczba_godzin),0) AS g, COUNT(*) AS c FROM wsparcie_ou WHERE miesiac=? AND podmiot_nazwa=?", [$prev, trim($nazwa)]);
    }
    return ['prev' => $prev, 'hours' => ($r && (int)$r['c'] > 0) ? (float)$r['g'] : null];
}
