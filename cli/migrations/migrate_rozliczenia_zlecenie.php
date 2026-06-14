<?php
/**
 * Migracja: rejestr rozliczeń umów zlecenie (proces „Umowa do rozliczenia”).
 *
 * Tworzy tabelę zlecenie_rozliczenia — jeden rekord = jeden rachunek/rozliczenie
 * przypięte do umowy (contract_type, contract_id). Statusy: oczekuje, wyslane,
 * rozliczone, anulowane.
 *
 * Uruchom: php migrate_rozliczenia_zlecenie.php  (lub z panelu admin/migrations.php)
 *
 * Uwaga: includes/rozliczenia.php tworzy tę tabelę także automatycznie przy
 * pierwszym użyciu — ta migracja jest jawnym, idempotentnym odpowiednikiem.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

$sql = "CREATE TABLE IF NOT EXISTS zlecenie_rozliczenia (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_type TEXT NOT NULL DEFAULT 'zlecenie',
    contract_id   INTEGER NOT NULL,
    status        TEXT NOT NULL DEFAULT 'oczekuje',
    data_rachunku TEXT,
    okres         TEXT,
    kwota_brutto  REAL,
    liczba_godzin TEXT,
    uwagi         TEXT,
    created_by    INTEGER,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_by       INTEGER,
    sent_at       DATETIME,
    sent_to_email TEXT,
    mail_queue_id INTEGER,
    settled_by    INTEGER,
    settled_at    DATETIME
)";

try {
    db()->exec($sql);
    db()->exec("CREATE INDEX IF NOT EXISTS idx_rozl_contract ON zlecenie_rozliczenia(contract_type, contract_id)");
    echo "  ✓ Tabela zlecenie_rozliczenia — OK\n";
} catch (\Throwable $e) {
    echo "  ✗ Błąd: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nGotowe — rejestr rozliczeń umów zlecenie jest dostępny.\n";
