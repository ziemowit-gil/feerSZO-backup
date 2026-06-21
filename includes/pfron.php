<?php
/**
 * includes/pfron.php — Moduł PFRON (rozliczenia konsultacji) z izolacją danych.
 *
 * Bezpieczeństwo / prywatność:
 *   - Dane PFRON żyją w ODRĘBNYCH tabelach (k30_pfron_*), bez klucza obcego do
 *     konta kursanta/użytkownika — nie są częścią profilu głównego.
 *   - Dostęp tylko po 2FA: nr telefonu + nr umowy PFRON (oba muszą pasować).
 *   - Odblokowanie jest WYŁĄCZNIE na czas sesji (krótki TTL); po nim wymagane
 *     ponowne uwierzytelnienie. Każdy dostęp jest logowany.
 *   - Throttling prób (ochrona przed zgadywaniem nr umowy).
 *
 * Wymaga aktywnej sesji (student_start()) — moduł działa jako kafelek w panelu kursanta.
 */

const PFRON_UNLOCK_TTL   = 1800;  // 30 min
const PFRON_MAX_ATTEMPTS = 5;     // prób na okno
const PFRON_LOCK_SECONDS = 600;   // 10 min blokady po przekroczeniu

function pfron_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    // Umowy PFRON — bez FK do konta (izolacja). client_name to tylko etykieta.
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pfron_contracts (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_no  TEXT    NOT NULL,                 -- nr umowy PFRON (część 2FA)
        phone        TEXT    NOT NULL DEFAULT '',       -- telefon (część 2FA)
        client_name  TEXT    NOT NULL DEFAULT '',       -- etykieta (nie jest powiązaniem konta)
        note         TEXT    NOT NULL DEFAULT '',
        is_active    INTEGER NOT NULL DEFAULT 1,
        created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(contract_no)
    )");
    // Rozliczenia PFRON w ramach konsultacji
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pfron_settlements (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_id  INTEGER NOT NULL REFERENCES k30_pfron_contracts(id) ON DELETE CASCADE,
        period       TEXT    NOT NULL DEFAULT '',       -- np. 'czerwiec 2026' lub zakres
        title        TEXT    NOT NULL DEFAULT '',       -- nazwa konsultacji/pozycji
        amount       REAL    NOT NULL DEFAULT 0,
        status       TEXT    NOT NULL DEFAULT 'rozliczone', -- rozliczone|oczekuje|odrzucone
        note         TEXT    NOT NULL DEFAULT '',
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pfron_settle ON k30_pfron_settlements(contract_id)");
    // Log dostępu (audyt prywatności)
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_pfron_access_log (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_id  INTEGER NOT NULL DEFAULT 0,
        result       TEXT    NOT NULL DEFAULT '',        -- ok|fail
        ip           TEXT    NOT NULL DEFAULT '',
        accessed_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

/** Normalizacja telefonu (reużycie z modułu SMS, fallback: same cyfry). */
function pfron_norm_phone(string $p): string {
    if (function_exists('sms_normalize_phone')) {
        $n = sms_normalize_phone($p);
        if ($n !== '') return $n;
    }
    return preg_replace('/\D+/', '', $p);
}

/** Log dostępu do danych PFRON. */
function pfron_log(int $contract_id, string $result): void {
    pfron_migrate();
    try {
        db_insert('k30_pfron_access_log', [
            'contract_id' => $contract_id,
            'result'      => $result,
            'ip'          => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    } catch (\Throwable $e) {}
}

// ── Throttling (per sesja) ────────────────────────────────────────────────────
function pfron_is_locked(): bool {
    $o = $_SESSION['k30_pfron_throttle'] ?? null;
    return $o && ($o['count'] ?? 0) >= PFRON_MAX_ATTEMPTS && (time() - ($o['ts'] ?? 0)) < PFRON_LOCK_SECONDS;
}
function pfron_register_fail(): void {
    $o = $_SESSION['k30_pfron_throttle'] ?? ['count' => 0, 'ts' => time()];
    if ((time() - ($o['ts'] ?? 0)) >= PFRON_LOCK_SECONDS) $o = ['count' => 0, 'ts' => time()];
    $o['count'] = (int)($o['count'] ?? 0) + 1;
    $o['ts']    = time();
    $_SESSION['k30_pfron_throttle'] = $o;
}
function pfron_reset_throttle(): void { unset($_SESSION['k30_pfron_throttle']); }

/**
 * 2FA: weryfikuje nr umowy + telefon. Zwraca id umowy lub 0.
 * Oba elementy muszą pasować do aktywnej umowy.
 */
function pfron_authenticate(string $contract_no, string $phone): int {
    pfron_migrate();
    $contract_no = trim($contract_no);
    $normPhone   = pfron_norm_phone($phone);
    if ($contract_no === '' || $normPhone === '') return 0;
    $row = db_one("SELECT id, phone FROM k30_pfron_contracts WHERE contract_no=? AND is_active=1", [$contract_no]);
    if (!$row) return 0;
    if (pfron_norm_phone((string)$row['phone']) !== $normPhone) return 0;
    return (int)$row['id'];
}

// ── Odblokowanie w sesji ────────────────────────────────────────────────────
function pfron_unlock(int $contract_id): void {
    if (!isset($_SESSION['k30_pfron_unlocked'])) $_SESSION['k30_pfron_unlocked'] = [];
    $_SESSION['k30_pfron_unlocked'][$contract_id] = time();
    pfron_reset_throttle();
}
function pfron_lock_all(): void { unset($_SESSION['k30_pfron_unlocked']); }

/** Lista odblokowanych (ważnych w ramach TTL) umów PFRON w tej sesji. */
function pfron_unlocked_ids(): array {
    $out = [];
    foreach (($_SESSION['k30_pfron_unlocked'] ?? []) as $cid => $ts) {
        if (time() - (int)$ts < PFRON_UNLOCK_TTL) $out[] = (int)$cid;
    }
    return $out;
}
function pfron_is_unlocked(int $contract_id): bool {
    return in_array($contract_id, pfron_unlocked_ids(), true);
}

/** Dane umowy (bez wrażliwego telefonu w wyniku). */
function pfron_contract_get(int $id): ?array {
    pfron_migrate();
    return db_one("SELECT id, contract_no, client_name, note, is_active FROM k30_pfron_contracts WHERE id=?", [$id]) ?: null;
}

/** Rozliczenia danej umowy PFRON. */
function pfron_settlements(int $contract_id): array {
    pfron_migrate();
    return db_all("SELECT * FROM k30_pfron_settlements WHERE contract_id=? ORDER BY id DESC", [$contract_id]);
}

// ── Helpery admina ────────────────────────────────────────────────────────────
function pfron_contracts_all(): array {
    pfron_migrate();
    return db_all(
        "SELECT c.*, (SELECT COUNT(*) FROM k30_pfron_settlements s WHERE s.contract_id=c.id) AS settle_count
         FROM k30_pfron_contracts c ORDER BY c.is_active DESC, c.client_name, c.contract_no"
    );
}
