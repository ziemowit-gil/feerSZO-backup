<?php
/**
 * Migracja: kreator onboardingowy dla wolontariuszy
 * Tworzy tabelę onboarding_volunteers i ustawienia domyślne.
 * Bezpieczne do wielokrotnego uruchamiania (idempotentne).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = db();

// ── 1. Tabela onboarding_volunteers ──────────────────────────────────────────
$db->exec("
CREATE TABLE IF NOT EXISTS onboarding_volunteers (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    session_token        TEXT UNIQUE,
    status               TEXT    NOT NULL DEFAULT 'new',

    imie_nazwisko        TEXT    NOT NULL DEFAULT '',
    pesel                TEXT    NOT NULL DEFAULT '',
    data_urodzenia       TEXT    NOT NULL DEFAULT '',
    adres                TEXT    NOT NULL DEFAULT '',
    telefon              TEXT    NOT NULL DEFAULT '',
    email                TEXT    NOT NULL DEFAULT '',

    phone_verified       INTEGER NOT NULL DEFAULT 0,
    phone_code           TEXT,
    phone_code_expires   TEXT,

    email_verified       INTEGER NOT NULL DEFAULT 0,
    email_token          TEXT,
    email_token_expires  TEXT,

    klauzula_accepted    INTEGER NOT NULL DEFAULT 0,
    klauzula_version     TEXT    NOT NULL DEFAULT '',

    admin_note           TEXT    NOT NULL DEFAULT '',

    ip_address           TEXT    NOT NULL DEFAULT '',
    user_agent           TEXT    NOT NULL DEFAULT '',
    created_at           DATETIME         DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME         DEFAULT CURRENT_TIMESTAMP
)
");
echo "✓ Tabela onboarding_volunteers — OK\n";

// ── 2. Ustawienia domyślne ────────────────────────────────────────────────────
$defaults = [
    'onboarding_enabled'        => '0',
    'onboarding_title'          => 'Kwestionariusz wolontariusza',
    'onboarding_intro'          => '',
    'onboarding_klauzula'       => '',
    'onboarding_require_sms'    => '1',
    'onboarding_require_email'  => '1',
    'onboarding_notify_email'   => '',
];

$ins = $db->prepare("INSERT OR IGNORE INTO settings (key_, value) VALUES (?, ?)");
foreach ($defaults as $k => $v) {
    $ins->execute([$k, $v]);
    echo "  setting $k — " . ($ins->rowCount() ? 'dodano' : 'już istnieje') . "\n";
}

echo "\nMigracja onboarding zakończona pomyślnie.\n";
