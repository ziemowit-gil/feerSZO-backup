<?php
/**
 * includes/pfron.php — Portal kursanta PFRON (2FA + izolacja dostępu).
 *
 * UWAGA: korzysta z ISTNIEJĄCEGO modelu PFRON (System B) z includes/karty30.php:
 *   - k30_pfron_contracts (client_id, contract_number, hours_limit, hours_used, valid_*…)
 *   - konsultacje = k30_schedules z billing_type='pfron' i pfron_contract_id.
 * Ten moduł NIE tworzy własnych tabel umów/rozliczeń — dodaje wyłącznie warstwę
 * bezpiecznego dostępu (2FA) i audyt. Nie modyfikuje danych PFRON.
 *
 * Prywatność / izolacja:
 *   - Dane PFRON nie są pokazywane w zwykłym widoku konta. Aby je zobaczyć w panelu,
 *     kursant potwierdza tożsamość 2FA: numer umowy PFRON + numer telefonu
 *     (telefon z karty beneficjenta powiązanego z umową).
 *   - Odblokowanie wyłącznie na czas sesji (TTL); potem ponowne 2FA. Throttling +
 *     log dostępu (k30_pfron_access_log).
 *
 * Wymaga aktywnej sesji (np. student_start()) — działa jako kafelek w panelu kursanta.
 */

const PFRON_UNLOCK_TTL   = 1800;  // 30 min ważności odblokowania
const PFRON_MAX_ATTEMPTS = 5;     // prób 2FA na okno
const PFRON_LOCK_SECONDS = 600;   // 10 min blokady po przekroczeniu

/**
 * Czy obsługa PFRON jest włączona w tej instalacji.
 * Domyślnie true — wyłącza się przez admin → Cennik → przełącznik PFRON.
 */
function k30_pfron_enabled(): bool {
    static $val = null;
    if ($val === null) $val = (org_setting('k30_pfron_enabled') !== '0');
    return $val;
}

/** Tworzy WYŁĄCZNIE tabelę audytu — nie dotyka tabel danych PFRON. */
function pfron_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS k30_pfron_access_log (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_id  INTEGER NOT NULL DEFAULT 0,
        result       TEXT    NOT NULL DEFAULT '',   -- ok|fail
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
 * 2FA: numer umowy PFRON + telefon (z karty beneficjenta powiązanego z umową).
 * Zwraca id umowy (k30_pfron_contracts.id) lub 0.
 */
function pfron_authenticate(string $contract_number, string $phone): int {
    $contract_number = trim($contract_number);
    $normPhone       = pfron_norm_phone($phone);
    if ($contract_number === '' || $normPhone === '') return 0;
    $row = db_one(
        "SELECT c.id, cl.phone
         FROM k30_pfron_contracts c
         JOIN k30_clients cl ON cl.id = c.client_id
         WHERE c.contract_number = ? AND c.status != 'cancelled'",
        [$contract_number]
    );
    if (!$row) return 0;
    if (pfron_norm_phone((string)($row['phone'] ?? '')) !== $normPhone) return 0;
    return (int)$row['id'];
}

// ── Odblokowanie w sesji ────────────────────────────────────────────────────
function pfron_unlock(int $contract_id): void {
    if (!isset($_SESSION['k30_pfron_unlocked'])) $_SESSION['k30_pfron_unlocked'] = [];
    $_SESSION['k30_pfron_unlocked'][$contract_id] = time();
    pfron_reset_throttle();
}
function pfron_lock_all(): void { unset($_SESSION['k30_pfron_unlocked']); }
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

/** Dane umowy PFRON (z istniejącego modelu). */
function pfron_contract_get(int $id): ?array {
    return db_one("SELECT * FROM k30_pfron_contracts WHERE id=?", [$id]) ?: null;
}

/** Pozostały limit godzin PFRON. */
function pfron_hours_left(array $contract): float {
    return max(0, (float)($contract['hours_limit'] ?? 0) - (float)($contract['hours_used'] ?? 0));
}

/**
 * Konsultacje rozliczane z PFRON dla danej umowy (z k30_schedules).
 * Zwraca pozycje od najnowszej.
 */
function pfron_consultations(int $contract_id): array {
    return db_all(
        "SELECT s.id, s.start_time, s.duration_minutes, s.status, s.description,
                s.billed_hours, s.free_hours, s.charged_hours, s.amount_due, s.pfron_status,
                COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS consultant_name
         FROM k30_schedules s
         LEFT JOIN users u ON u.id = s.assigned_to
         WHERE s.pfron_contract_id = ? AND s.status NOT IN ('cancelled','rejected')
         ORDER BY s.start_time DESC",
        [$contract_id]
    );
}
