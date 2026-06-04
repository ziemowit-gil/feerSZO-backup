<?php
/**
 * includes/rodo.php — Moduł RODO
 * Upoważnienia do przetwarzania danych + rejestr + szkolenia
 */

// ── Predefiniowane zakresy § 2 ────────────────────────────────────────────────
const RODO_SCOPE_ITEMS = [
    'podopieczni' => 'obsługi podopiecznych i beneficjentów organizacji',
    'ewidencja'   => 'prowadzenia ewidencji zgłoszeń, wniosków i dokumentacji',
    'projekty'    => 'realizacji projektów i programów statutowych',
    'wolontariat' => 'organizacji i koordynacji wolontariatu',
    'kontrahenci' => 'obsługi kontrahentów, partnerów i darczyńców',
    'media'       => 'prowadzenia komunikacji i mediów społecznościowych',
    'it'          => 'administrowania systemami informatycznymi organizacji',
    'ksiegowosc'  => 'prowadzenia rozliczeń i dokumentacji finansowej',
    'rekrutacja'  => 'procesu rekrutacji, selekcji i onboardingu',
];

// Tematy szkoleń
const RODO_TRAINING_TOPICS = [
    'przepisy_rodo' => 'Przepisy RODO i krajowe przepisy o ochronie danych',
    'polityka'      => 'Wewnętrzna polityka bezpieczeństwa danych',
    'prawa_osob'    => 'Prawa osób, których dane dotyczą',
    'incydenty'     => 'Postępowanie w przypadku naruszenia ochrony danych',
    'it_bezp'       => 'Zasady bezpiecznego korzystania z systemów IT',
    'tajemnica'     => 'Obowiązek zachowania tajemnicy danych',
];

// ── Migracja ──────────────────────────────────────────────────────────────────
function rodo_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo  = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS rodo_authorizations (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_type   TEXT    NOT NULL DEFAULT 'wolontariat',
        contract_id     INTEGER,
        number          TEXT    NOT NULL UNIQUE,
        person_name     TEXT    NOT NULL,
        person_pesel    TEXT,
        org_name        TEXT    NOT NULL DEFAULT '',
        org_address     TEXT    NOT NULL DEFAULT '',
        org_nip         TEXT    NOT NULL DEFAULT '',
        scope_items     TEXT    NOT NULL DEFAULT '[]',
        scope_custom    TEXT,
        authorized_from DATE    NOT NULL,
        authorized_until DATE,
        contract_number TEXT,
        contract_date   DATE,
        status          TEXT    NOT NULL DEFAULT 'aktywne',
        signed_by_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        signed_by_name  TEXT,
        signed_at       DATE,
        vol_signed_at   DATE,
        training_done   INTEGER NOT NULL DEFAULT 0,
        notes           TEXT,
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rodo_contract ON rodo_authorizations(contract_type,contract_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rodo_status   ON rodo_authorizations(status)");

    // Pliki podpisanych dokumentów — idempotentne
    try { $pdo->exec("ALTER TABLE rodo_authorizations ADD COLUMN signed_doc_path     TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE rodo_authorizations ADD COLUMN vol_signed_doc_path TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE rodo_authorizations ADD COLUMN revoke_doc_path     TEXT"); } catch (\Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS rodo_trainings (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        authorization_id INTEGER NOT NULL REFERENCES rodo_authorizations(id) ON DELETE CASCADE,
        training_date    DATE    NOT NULL,
        topics           TEXT    NOT NULL DEFAULT '[]',
        trainer_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
        trainer_name     TEXT    NOT NULL DEFAULT '',
        notes            TEXT,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rodo_train ON rodo_trainings(authorization_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rodo_revocations (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        authorization_id INTEGER NOT NULL REFERENCES rodo_authorizations(id) ON DELETE CASCADE,
        revoked_by_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        revoked_by_name  TEXT    NOT NULL DEFAULT '',
        revoked_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        reason           TEXT
    )");

    // Log usunięć (audit trail — dane osobowe muszą być usuwane ale usunięcie musi być odnotowane)
    $pdo->exec("CREATE TABLE IF NOT EXISTS rodo_deletion_log (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        auth_number     TEXT    NOT NULL,
        auth_person     TEXT    NOT NULL,
        auth_pesel      TEXT,
        auth_contract   TEXT,
        reason          TEXT,
        deleted_by_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        deleted_by_name TEXT    NOT NULL DEFAULT '',
        deleted_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Auto-update org_name w settings → pełna nazwa z konstant ORG_NAME jeśli krótsza
    if (defined('ORG_NAME') && ORG_NAME !== '') {
        try {
            $stored = db_one("SELECT value FROM settings WHERE key_='org_name'");
            $current = $stored['value'] ?? '';
            if (strlen(ORG_NAME) > strlen($current)) {
                if ($stored) {
                    $pdo->prepare("UPDATE settings SET value=? WHERE key_='org_name'")->execute([ORG_NAME]);
                } else {
                    $pdo->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute(['org_name', ORG_NAME]);
                }
            }
        } catch (\Throwable $e) {}
    }
}

// ── Numer upoważnienia ────────────────────────────────────────────────────────
/**
 * Generuje numer upoważnienia.
 * Jeśli podano numer umowy: [nr_umowy]/RODO (+ sufiks gdy istnieje kilka)
 * Jeśli nie podano: RODO/RRRR/NNNN
 */
function rodo_next_number(string $contract_number = ''): string {
    $contract_number = trim($contract_number);
    if ($contract_number !== '') {
        // Format pochodny od numeru umowy
        $base = $contract_number . '/RODO';
        $exists = db_one("SELECT COUNT(*) AS c FROM rodo_authorizations WHERE number LIKE ?", [$base . '%']);
        $cnt = (int)($exists['c'] ?? 0);
        return $cnt === 0 ? $base : $base . '-' . ($cnt + 1);
    }
    // Bez umowy — sekwencyjny
    $year = date('Y');
    $last = db_one(
        "SELECT number FROM rodo_authorizations WHERE number LIKE ? ORDER BY id DESC LIMIT 1",
        ["RODO/{$year}/%"]
    );
    if ($last) {
        $parts = explode('/', $last['number']);
        $seq   = (int)($parts[2] ?? 0) + 1;
    } else {
        $seq = 1;
    }
    return "RODO/{$year}/" . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── Walidacja okresu upoważnienia ─────────────────────────────────────────────
/**
 * Dla wolontariatu: authorized_until nie może przekroczyć data_zakonczenia umowy.
 * Zwraca null gdy OK, string z komunikatem błędu gdy naruszenie.
 */
function rodo_validate_period(string $contract_type, int $contract_id, string $authorized_until): ?string {
    if ($contract_type !== 'wolontariat' || !$contract_id || !$authorized_until) return null;
    try {
        $c = db_one("SELECT data_zakonczenia, bezterminowa FROM umowy_wolontariat WHERE id=?", [$contract_id]);
        if (!$c) return null;
        if (!empty($c['bezterminowa'])) return null; // bezterminowa — bez ograniczenia
        $max = $c['data_zakonczenia'] ?? '';
        if (!$max) return null;
        if ($authorized_until > $max) {
            return 'Upoważnienie RODO nie może być dłuższe niż porozumienie wolontariackie '
                 . '(max. ' . date('d.m.Y', strtotime($max)) . ').';
        }
    } catch (\Throwable $e) {}
    return null;
}

// ── Dane organizacji ──────────────────────────────────────────────────────────
function rodo_org_data(): array {
    $stored = org_setting('org_name');
    $const  = defined('ORG_NAME') ? ORG_NAME : '';
    // Preferuj pełną nazwę — jeśli stała jest dłuższa niż zapisana w settings, użyj stałej
    $name = (strlen($const) > strlen($stored)) ? $const : ($stored ?: $const);
    return [
        'name'    => $name,
        'address' => org_setting('org_adres') ?: '',
        'city'    => org_setting('org_miejscowosc') ?: '',
        'nip'     => org_setting('org_nip') ?: '',
        'krs'     => org_setting('org_krs') ?: '',
    ];
}

// ── Statusy ───────────────────────────────────────────────────────────────────
function rodo_status_badge(string $status): string {
    $map = [
        'aktywne'  => ['bg-success',   'Aktywne'],
        'cofnięte' => ['bg-danger',    'Odwołane'],
        'wygasłe'  => ['bg-secondary', 'Wygasłe'],
    ];
    [$cls, $lbl] = $map[$status] ?? ['bg-light text-dark border', $status];
    return "<span class=\"badge {$cls}\">" . h($lbl) . '</span>';
}

// ── Auto-cofnięcie przy zamkniętych umowach ───────────────────────────────────
function rodo_auto_expire(): void {
    try {
        $active = db_all(
            "SELECT r.id, r.contract_type, r.contract_id
             FROM rodo_authorizations r
             WHERE r.status = 'aktywne' AND r.contract_id IS NOT NULL"
        );
        $today = date('Y-m-d');
        foreach ($active as $a) {
            $table  = 'umowy_' . $a['contract_type'];
            $end_col = $a['contract_type'] === 'dzielo' ? 'termin_oddania' : 'data_zakonczenia';
            try {
                $c = db_one("SELECT status, {$end_col} AS data_koniec FROM {$table} WHERE id=?", [(int)$a['contract_id']]);
            } catch (\Throwable $e) { continue; }
            if (!$c) continue;
            $ended_statuses = ['zakończona','rozwiązana','anulowana'];
            $date_passed    = !empty($c['data_koniec']) && $c['data_koniec'] < $today;
            if (in_array($c['status'], $ended_statuses) || $date_passed) {
                db()->prepare("UPDATE rodo_authorizations SET status='wygasłe', updated_at=? WHERE id=?")
                    ->execute([date('Y-m-d H:i:s'), $a['id']]);
            }
        }
    } catch (\Throwable $e) {}
}
