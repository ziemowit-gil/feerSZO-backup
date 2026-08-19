<?php
/**
 * includes/timesheets.php — ewidencja godzin wolontariatu.
 * Inicjalizuje tabelę i dostarcza helpery.
 */

// ── Schema ────────────────────────────────────────────────────────────────────
try {
    db()->exec("CREATE TABLE IF NOT EXISTS timesheets (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_id INTEGER NOT NULL,
        user_id     INTEGER NOT NULL,
        rok         INTEGER NOT NULL,
        miesiac     INTEGER NOT NULL,
        godziny     REAL    NOT NULL DEFAULT 0,
        opis        TEXT,
        status      TEXT    NOT NULL DEFAULT 'szkic',
        uwagi_admin TEXT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT NULL
    )");
} catch (\Throwable $e) {}

// Dodaj contract_type (wolontariat / zlecenie / inne) — idempotentnie
try { db()->exec("ALTER TABLE timesheets ADD COLUMN contract_type TEXT NOT NULL DEFAULT 'wolontariat'"); } catch (\Throwable $e) {}
// Zastąp stary indeks (bez contract_type) nowym — bezpieczne, jeśli stary istnieje i nowy już jest
try { db()->exec("DROP INDEX IF EXISTS idx_timesheets_contract_month"); } catch (\Throwable $e) {}
try {
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS
        idx_timesheets_type_contract_month ON timesheets(contract_type, contract_id, rok, miesiac)");
} catch (\Throwable $e) {}

// ── Constants ─────────────────────────────────────────────────────────────────
const TS_STATUS = [
    'szkic'       => ['label' => 'Szkic',       'class' => 'secondary'],
    'złożone'     => ['label' => 'Złożone',      'class' => 'primary'],
    'zatwierdzone'=> ['label' => 'Zatwierdzone', 'class' => 'success'],
    'odrzucone'   => ['label' => 'Odrzucone',    'class' => 'danger'],
];

const MIESIAC_PL = [
    1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',
    7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień',
];

// ── Helpers ───────────────────────────────────────────────────────────────────

function ts_badge(string $status): string {
    $s = TS_STATUS[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . h($s['label']) . '</span>';
}

function ts_month_label(int $rok, int $miesiac): string {
    return (MIESIAC_PL[$miesiac] ?? '?') . ' ' . $rok;
}

/**
 * Zwraca listę timesheetów dla danego użytkownika (po jego umowach wolontariackich).
 */
function ts_user_timesheets(int $user_id): array {
    $email  = db_one("SELECT email FROM users WHERE id=?", [$user_id])['email'] ?? '';
    $ms_id  = db_one("SELECT microsoft_id FROM users WHERE id=?", [$user_id])['microsoft_id'] ?? '';
    if (!$email && !$ms_id) return [];

    $conds = []; $params = [];
    if ($ms_id)  { $conds[] = 'm365_user_id=?'; $params[] = $ms_id; }
    if ($email)  { $conds[] = 'm365_login=?';   $params[] = $email; $conds[] = 'email=?'; $params[] = $email; }
    if (!$conds) return [];

    $contracts = db_all(
        "SELECT id, numer_umowy, imie_nazwisko FROM umowy_wolontariat
         WHERE (" . implode(' OR ', $conds) . ")",
        $params
    );
    if (!$contracts) return [];

    $ids = array_column($contracts, 'id');
    $map = array_column($contracts, null, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $rows = db_all(
        "SELECT t.*, u.numer_umowy, u.imie_nazwisko
         FROM timesheets t
         JOIN umowy_wolontariat u ON u.id = t.contract_id
         WHERE t.contract_id IN ({$in})
         ORDER BY t.rok DESC, t.miesiac DESC, t.id DESC",
        $ids
    );
    return $rows;
}

/**
 * Zwraca umowy wolontariackie danego użytkownika (aktywne, do wyboru w formularzu).
 */
function ts_user_contracts(int $user_id): array {
    $email  = db_one("SELECT email FROM users WHERE id=?", [$user_id])['email'] ?? '';
    $ms_id  = db_one("SELECT microsoft_id FROM users WHERE id=?", [$user_id])['microsoft_id'] ?? '';
    if (!$email && !$ms_id) return [];

    $conds = []; $params = [];
    if ($ms_id) { $conds[] = 'm365_user_id=?'; $params[] = $ms_id; }
    if ($email) { $conds[] = 'm365_login=?'; $params[] = $email;
                  $conds[] = 'email=?';      $params[] = $email; }
    if (!$conds) return [];

    return db_all(
        "SELECT id, numer_umowy, imie_nazwisko, status, data_zawarcia, data_zakonczenia
         FROM umowy_wolontariat
         WHERE (" . implode(' OR ', $conds) . ")
           AND status NOT IN ('anulowana','rozwiązana')
         ORDER BY data_zawarcia DESC",
        $params
    );
}

/**
 * Pobiera miesięczne podsumowanie godzin dla jednej umowy (dla widoku admina/szczegółów).
 */
function ts_contract_summary(int $contract_id, string $type = 'wolontariat'): array {
    return db_all(
        "SELECT id, contract_type, rok, miesiac, godziny, status, opis, uwagi_admin
         FROM timesheets
         WHERE contract_type=? AND contract_id=?
         ORDER BY rok DESC, miesiac DESC",
        [$type, $contract_id]
    );
}

/**
 * Suma zatwierdzonych godzin dla umowy.
 */
function ts_total_approved(int $contract_id, string $type = 'wolontariat'): float {
    $r = db_one(
        "SELECT COALESCE(SUM(godziny),0) AS total FROM timesheets
         WHERE contract_type=? AND contract_id=? AND status='zatwierdzone'",
        [$type, $contract_id]
    );
    return (float)($r['total'] ?? 0);
}

/**
 * Łączna suma godzin (wszystkie statusy) dla umowy.
 */
function ts_total_all(int $contract_id, string $type = 'wolontariat'): float {
    $r = db_one(
        "SELECT COALESCE(SUM(godziny),0) AS total FROM timesheets
         WHERE contract_type=? AND contract_id=?",
        [$type, $contract_id]
    );
    return (float)($r['total'] ?? 0);
}

/**
 * Liczba wpisów oczekujących na zatwierdzenie (admin badge).
 */
function ts_pending_count(): int {
    try {
        $r = db_one("SELECT COUNT(*) AS c FROM timesheets WHERE status='złożone'");
        return (int)($r['c'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}
