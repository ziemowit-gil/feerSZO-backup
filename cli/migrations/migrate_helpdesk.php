<?php
/**
 * Migracja: Helpdesk — przekazanie do firmy zewnętrznej, publiczny mikropanel,
 *           SLA oraz łączenie (scalanie) zgłoszeń.
 *
 * Dodaje do helpdesk_tickets kolumny:
 *   ext_vendor, ext_ref, ext_reason, ext_handed_at  — przekazanie do firmy zewn.
 *   access_token                                     — token publicznego mikropanelu
 *   first_response_at                                — SLA (pierwsza odpowiedź)
 *   merged_into                                      — łączenie zgłoszeń
 * oraz unikalny indeks idx_hd_token na access_token.
 *
 * Idempotentna. Uruchom: php cli/migrations/migrate_helpdesk.php
 * (lub z panelu admin/migrations.php).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

echo "\n=== Migracja: Helpdesk (firma zewn. / mikropanel / SLA / łączenie) ===\n\n";

// Tabela helpdesku powstaje leniwie przy 1. użyciu modułu.
$exists = false;
try {
    $exists = (bool)db()->query(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name='helpdesk_tickets'"
    )->fetchColumn();
} catch (\Throwable $e) { $exists = false; }

if (!$exists) {
    echo "  · Tabela helpdesk_tickets nie istnieje — moduł utworzy schemat z kompletem kolumn przy pierwszym użyciu. Pomijam.\n\n";
    exit(0);
}

$existing = [];
foreach (db_all("PRAGMA table_info(helpdesk_tickets)") as $col) {
    $existing[] = $col['name'];
}

$columns = [
    'ext_vendor'        => 'TEXT',
    'ext_ref'           => 'TEXT',
    'ext_reason'        => 'TEXT',
    'ext_handed_at'     => 'DATETIME',
    'access_token'      => 'TEXT',
    'first_response_at' => 'DATETIME',
    'merged_into'       => 'INTEGER',
];

$err = 0;
foreach ($columns as $col => $def) {
    if (in_array($col, $existing, true)) {
        echo "  · Kolumna już istnieje: {$col}\n";
        continue;
    }
    try {
        db()->exec("ALTER TABLE helpdesk_tickets ADD COLUMN {$col} {$def}");
        echo "  ✓ Dodano kolumnę: {$col}\n";
    } catch (\Throwable $e) {
        echo "  ✗ Błąd przy {$col}: " . $e->getMessage() . "\n";
        $err++;
    }
}

try {
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_hd_token ON helpdesk_tickets(access_token)");
    echo "  ✓ Indeks idx_hd_token\n";
} catch (\Throwable $e) {
    echo "  ✗ Błąd indeksu idx_hd_token: " . $e->getMessage() . "\n";
    $err++;
}

echo "\n" . ($err ? "Zakończono z błędami ({$err}).\n" : "Gotowe.\n");
exit($err ? 1 : 0);
