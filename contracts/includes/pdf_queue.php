<?php
/**
 * contracts/includes/pdf_queue.php
 * Kolejka PDF do wydruku — dla użytkowników bez drukarki.
 */

function pdf_queue_ensure_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS pdf_do_druku (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_type TEXT    NOT NULL,
        contract_id   INTEGER NOT NULL,
        numer_umowy   TEXT    NOT NULL DEFAULT '',
        osoba         TEXT    NOT NULL DEFAULT '',
        user_id       INTEGER NOT NULL,
        created_at    TEXT    NOT NULL,
        done_at       TEXT    DEFAULT NULL
    )");
    try {
        db()->exec("CREATE INDEX IF NOT EXISTS idx_pdf_queue_user ON pdf_do_druku(user_id, done_at)");
    } catch (\Throwable $_e) {}
}

function pdf_queue_add(string $type, int $contract_id, string $numer, string $osoba, int $user_id): void {
    pdf_queue_ensure_table();
    db_insert('pdf_do_druku', [
        'contract_type' => $type,
        'contract_id'   => $contract_id,
        'numer_umowy'   => $numer,
        'osoba'         => $osoba,
        'user_id'       => $user_id,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
}

function pdf_queue_count_pending(int $user_id): int {
    pdf_queue_ensure_table();
    return (int)(db_one(
        "SELECT COUNT(*) AS c FROM pdf_do_druku WHERE user_id = ? AND done_at IS NULL",
        [$user_id]
    )['c'] ?? 0);
}
