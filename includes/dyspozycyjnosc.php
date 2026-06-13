<?php
/**
 * includes/dyspozycyjnosc.php
 *
 * Dyspozycyjność wolontariusza — konkretne sloty (data + godziny) oraz urlopy
 * (przedziały dat z formalną akceptacją opiekuna/admina).
 *
 * Uzupełnia zgrubne pola umowy `dostepnosc_dni` / `dostepnosc_pora`
 * (które pozostają jako szybkie podsumowanie). Sloty i urlopy żyją w osobnych
 * tabelach, bo są listami wpisów dodawanymi zarówno przez wolontariusza
 * (panel) jak i przez admina (edit.php / view.php).
 *
 * Wzorzec migracji: leniwe `dyspo_migrate()` wołane na górze stron — jak
 * notif_migrate(), rodo_migrate(), karty30_migrate().
 */

require_once __DIR__ . '/db.php';

// ── Statusy urlopu (wzór: APPROVAL_STATUSES w includes/approval.php) ──────────
const URLOP_STATUSES = [
    'oczekuje'     => ['label' => 'Oczekuje na akceptację', 'class' => 'warning', 'icon' => 'bi-hourglass-split'],
    'zaakceptowany'=> ['label' => 'Zaakceptowany',          'class' => 'success', 'icon' => 'bi-check-circle-fill'],
    'odrzucony'    => ['label' => 'Odrzucony',              'class' => 'danger',  'icon' => 'bi-x-circle-fill'],
];

function urlop_status_badge(string $status): string {
    $s = URLOP_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary', 'icon' => 'bi-dash-circle'];
    return '<span class="badge bg-' . $s['class'] . '"><i class="bi ' . $s['icon'] . ' me-1"></i>'
         . htmlspecialchars($s['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
}

// ── Migracja ──────────────────────────────────────────────────────────────────
function dyspo_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("
        CREATE TABLE IF NOT EXISTS wol_dyspozycje (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_id INTEGER NOT NULL,
            data        TEXT NOT NULL,
            czas_od     TEXT NOT NULL,
            czas_do     TEXT NOT NULL,
            notatka     TEXT,
            source      TEXT NOT NULL DEFAULT 'wolontariusz',
            created_by  INTEGER,
            created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_wol_dyspo_contract ON wol_dyspozycje(contract_id)");

    db()->exec("
        CREATE TABLE IF NOT EXISTS wol_urlopy (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_id   INTEGER NOT NULL,
            data_od       TEXT NOT NULL,
            data_do       TEXT NOT NULL,
            powod         TEXT,
            status        TEXT NOT NULL DEFAULT 'oczekuje',
            decided_by    INTEGER,
            decided_at    TEXT,
            decision_note TEXT,
            source        TEXT NOT NULL DEFAULT 'wolontariusz',
            created_by    INTEGER,
            created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_wol_urlop_contract ON wol_urlopy(contract_id)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_wol_urlop_status ON wol_urlopy(status)");
}

// ── Sloty dostępności ───────────────────────────────────────────────────────
function dyspo_slots(int $contract_id): array {
    dyspo_migrate();
    return db_all(
        "SELECT * FROM wol_dyspozycje WHERE contract_id=? ORDER BY data ASC, czas_od ASC",
        [$contract_id]
    );
}

/** Dodaje slot. $d: data, czas_od, czas_do, notatka. Zwraca id lub 0 przy błędnych danych. */
function dyspo_add_slot(int $contract_id, array $d, string $source, ?int $uid): int {
    dyspo_migrate();
    $data    = trim($d['data'] ?? '');
    $czas_od = trim($d['czas_od'] ?? '');
    $czas_do = trim($d['czas_do'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) return 0;
    if (!preg_match('/^\d{2}:\d{2}$/', $czas_od) || !preg_match('/^\d{2}:\d{2}$/', $czas_do)) return 0;
    if ($czas_do <= $czas_od) return 0;
    return db_insert('wol_dyspozycje', [
        'contract_id' => $contract_id,
        'data'        => $data,
        'czas_od'     => $czas_od,
        'czas_do'     => $czas_do,
        'notatka'     => trim($d['notatka'] ?? '') ?: null,
        'source'      => $source === 'admin' ? 'admin' : 'wolontariusz',
        'created_by'  => $uid,
    ]);
}

function dyspo_delete_slot(int $id, int $contract_id): void {
    dyspo_migrate();
    db()->prepare("DELETE FROM wol_dyspozycje WHERE id=? AND contract_id=?")->execute([$id, $contract_id]);
}

// ── Urlopy ──────────────────────────────────────────────────────────────────
function urlop_list(int $contract_id): array {
    dyspo_migrate();
    return db_all(
        "SELECT u.*, d.name AS decided_by_name
         FROM wol_urlopy u
         LEFT JOIN users d ON d.id = u.decided_by
         WHERE u.contract_id=?
         ORDER BY u.data_od DESC, u.id DESC",
        [$contract_id]
    );
}

/** Dodaje urlop (status 'oczekuje'). $d: data_od, data_do, powod. Zwraca id lub 0. */
function urlop_add(int $contract_id, array $d, string $source, ?int $uid): int {
    dyspo_migrate();
    $od = trim($d['data_od'] ?? '');
    $do = trim($d['data_do'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $od) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $do)) return 0;
    if ($do < $od) return 0;
    return db_insert('wol_urlopy', [
        'contract_id' => $contract_id,
        'data_od'     => $od,
        'data_do'     => $do,
        'powod'       => trim($d['powod'] ?? '') ?: null,
        'status'      => 'oczekuje',
        'source'      => $source === 'admin' ? 'admin' : 'wolontariusz',
        'created_by'  => $uid,
    ]);
}

/** Formalna decyzja opiekuna/admina. $decision: 'zaakceptowany' | 'odrzucony'. */
function urlop_decide(int $id, string $decision, string $note, ?int $uid): bool {
    dyspo_migrate();
    if (!in_array($decision, ['zaakceptowany', 'odrzucony'], true)) return false;
    $row = db_one("SELECT id FROM wol_urlopy WHERE id=?", [$id]);
    if (!$row) return false;
    db()->prepare(
        "UPDATE wol_urlopy SET status=?, decided_by=?, decided_at=datetime('now','localtime'), decision_note=? WHERE id=?"
    )->execute([$decision, $uid, ($note !== '' ? $note : null), $id]);
    return true;
}

function urlop_delete(int $id, int $contract_id): void {
    dyspo_migrate();
    db()->prepare("DELETE FROM wol_urlopy WHERE id=? AND contract_id=?")->execute([$id, $contract_id]);
}

/** Liczba urlopów oczekujących na akceptację (badge admina). */
function urlop_pending_count(): int {
    dyspo_migrate();
    try {
        return (int)(db_one("SELECT COUNT(*) AS c FROM wol_urlopy WHERE status='oczekuje'")['c'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Znajduje umowę wolontariatu należącą do zalogowanego użytkownika
 * (po email / m365_login / m365_user_id). Logika wyjęta z panel_contracts()
 * w panel/index.php — używana przez panel samoobsługowy i endpoint akcji,
 * żeby autoryzować właściciela.
 *
 * Zwraca wiersz umowy albo null.
 */
function dyspo_contract_for_user(array $user): ?array {
    $email = trim($user['email'] ?? '');
    $ms_id = trim($user['microsoft_id'] ?? '');
    if ($email === '' && $ms_id === '') return null;

    $conds = []; $params = [];
    if ($ms_id !== '') { $conds[] = 'm365_user_id = ?'; $params[] = $ms_id; }
    if ($email !== '') {
        $conds[] = 'm365_login = ?'; $params[] = $email;
        $conds[] = 'email = ?';      $params[] = $email;
    }
    if (!$conds) return null;

    // Preferuj umowę aktywną/najnowszą.
    return db_one(
        "SELECT * FROM umowy_wolontariat
         WHERE (" . implode(' OR ', $conds) . ")
         ORDER BY (status IN ('podpisana','w realizacji','obowiązująca','aktywna')) DESC,
                  COALESCE(data_zawarcia,'') DESC, id DESC
         LIMIT 1",
        $params
    );
}
