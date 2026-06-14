<?php
/**
 * includes/rozliczenia.php
 * Model rozliczeń umów zlecenie (proces „Umowa do rozliczenia”).
 *
 * Jedna umowa może mieć wiele rachunków/rozliczeń w czasie — każde jest osobnym
 * śledzonym rekordem (wzorzec: includes/certificates.php). Historia zdarzeń trafia
 * do contract_audit_log przez log_contract_action().
 *
 * Wymaga wcześniejszego załadowania includes/db.php.
 */

// ── Auto-migracja (tworzy tabelę + dokłada kolumny) ─────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS zlecenie_rozliczenia (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_type TEXT NOT NULL DEFAULT 'zlecenie',
            contract_id   INTEGER NOT NULL,
            status        TEXT NOT NULL DEFAULT 'oczekuje',
            data_rachunku TEXT,
            okres         TEXT,
            kwota_brutto  REAL,
            liczba_godzin TEXT,
            powod         TEXT,
            nie_wysylac   INTEGER DEFAULT 0,
            uwagi         TEXT,
            created_by    INTEGER,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            sent_by       INTEGER,
            sent_at       DATETIME,
            sent_to_email TEXT,
            mail_queue_id INTEGER,
            settled_by    INTEGER,
            settled_at    DATETIME
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_rozl_contract ON zlecenie_rozliczenia(contract_type, contract_id)");
        // Dokładanie kolumn do istniejących tabel
        try { db()->exec("ALTER TABLE zlecenie_rozliczenia ADD COLUMN powod TEXT"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE zlecenie_rozliczenia ADD COLUMN nie_wysylac INTEGER DEFAULT 0"); } catch (\Throwable $e) {}
    } catch (\Throwable $e) {
        error_log('[rozliczenia migrate] ' . $e->getMessage());
    }
})();

const ROZLICZENIE_STATUSES = [
    'oczekuje'   => ['label' => 'Oczekuje',                 'class' => 'warning'],
    'wyslane'    => ['label' => 'Wysłane do księgowego',    'class' => 'info'],
    'rozliczone' => ['label' => 'Rozliczone',               'class' => 'success'],
    'anulowane'  => ['label' => 'Anulowane',                'class' => 'secondary'],
];

function rozliczenie_status_badge(string $status): string {
    $s = ROZLICZENIE_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

/** Lista rozliczeń dla umowy (najnowsze pierwsze). */
function get_rozliczenia(string $type, int $id): array {
    return db_all(
        "SELECT r.*, c.name AS created_by_name, s.name AS sent_by_name, t.name AS settled_by_name
         FROM zlecenie_rozliczenia r
         LEFT JOIN users c ON c.id = r.created_by
         LEFT JOIN users s ON s.id = r.sent_by
         LEFT JOIN users t ON t.id = r.settled_by
         WHERE r.contract_type = ? AND r.contract_id = ?
         ORDER BY r.id DESC",
        [$type, $id]
    );
}

function get_rozliczenie(int $rid): ?array {
    return db_one(
        "SELECT r.*, c.name AS created_by_name, s.name AS sent_by_name, t.name AS settled_by_name
         FROM zlecenie_rozliczenia r
         LEFT JOIN users c ON c.id = r.created_by
         LEFT JOIN users s ON s.id = r.sent_by
         LEFT JOIN users t ON t.id = r.settled_by
         WHERE r.id = ?",
        [$rid]
    );
}

function get_rozliczenia_count(string $type, int $id): int {
    try {
        return (int)(db_one(
            "SELECT COUNT(*) AS c FROM zlecenie_rozliczenia WHERE contract_type=? AND contract_id=?",
            [$type, $id]
        )['c'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

/** Tworzy rekord rozliczenia. $data: contract_id + data_rachunku/okres/kwota_brutto/liczba_godzin/uwagi. */
function create_rozliczenie(array $data, ?int $user_id, string $type = 'zlecenie'): int {
    return db_insert('zlecenie_rozliczenia', [
        'contract_type' => $type,
        'contract_id'   => (int)($data['contract_id'] ?? 0),
        'status'        => 'oczekuje',
        'data_rachunku' => $data['data_rachunku'] ?? null,
        'okres'         => $data['okres'] ?? null,
        'kwota_brutto'  => ($data['kwota_brutto'] ?? '') === '' ? null : (float)$data['kwota_brutto'],
        'liczba_godzin' => $data['liczba_godzin'] ?? null,
        'powod'         => $data['powod'] ?? null,
        'nie_wysylac'   => !empty($data['nie_wysylac']) ? 1 : 0,
        'uwagi'         => $data['uwagi'] ?? null,
        'created_by'    => $user_id,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
}

function update_rozliczenie(int $rid, array $data): void {
    db_update('zlecenie_rozliczenia', $data, $rid);
}

/** Oznacza rozliczenie jako wysłane do księgowego. */
function rozliczenie_mark_sent(int $rid, ?int $user_id, string $email, ?int $mail_id): void {
    db_update('zlecenie_rozliczenia', [
        'status'        => 'wyslane',
        'sent_by'       => $user_id,
        'sent_at'       => date('Y-m-d H:i:s'),
        'sent_to_email' => $email,
        'mail_queue_id' => $mail_id,
    ], $rid);
}

/** Oznacza rozliczenie jako rozliczone. */
function rozliczenie_mark_settled(int $rid, ?int $user_id): void {
    db_update('zlecenie_rozliczenia', [
        'status'     => 'rozliczone',
        'settled_by' => $user_id,
        'settled_at' => date('Y-m-d H:i:s'),
    ], $rid);
}

/**
 * Mapuje rekord rozliczenia + dane umowy na tablicę kluczy czytanych przez
 * ksiegowy_rachunek_email_text(): imie_nazwisko, data_zawarcia, data_rachunku,
 * okres_rachunku, wynagrodzenie_brutto, liczba_godzin_planowana.
 */
function rozliczenie_email_row(array $contract, array $rozl): array {
    return [
        'imie_nazwisko'           => $contract['imie_nazwisko'] ?? '',
        'data_zawarcia'           => $contract['data_zawarcia'] ?? '',
        'data_rachunku'           => $rozl['data_rachunku'] ?? '',
        'okres_rachunku'          => $rozl['okres'] ?? '',
        'wynagrodzenie_brutto'    => $rozl['kwota_brutto'] ?? null,
        'liczba_godzin_planowana' => $rozl['liczba_godzin'] ?? '',
        'powod'                   => $rozl['powod'] ?? '',
    ];
}
