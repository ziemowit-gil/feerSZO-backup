<?php
/**
 * modules/ti_licencje/logic/tiLicencjeSchema.php — Licencje kursantów TI: schemat.
 *
 * Rozszerza istniejące przypisania (k30_ti_client_licenses; katalog oprogramowania
 * k30_ti_licenses zostaje bez zmian) o: datę wydania, formę rozliczenia
 * (FAK / FAZ / DSC / GST) z referencją i notatką wewnętrzną, dane unieważnienia
 * oraz znacznik powiadomienia. Dziennik wysyłek powiadomień o przypisaniu:
 * k30_ti_license_notifications.
 *
 * Wiersze sprzed modułu mają billing_form = '' („do uzupełnienia”) — działają dalej,
 * a forma rozliczenia jest wymagana dopiero przy zapisie / edycji w module.
 * Brak daty wydania (NULL) znaczy: dzień przypisania (assigned_at).
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';

function ti_licencje_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    karty30_migrate();                                                  // tabele bazowe licencji
    if (szo_schema_current('ti_licencje_migrate', __FILE__)) return;    // raz na wersję pliku

    $pdo  = db();
    $have = array_column($pdo->query("PRAGMA table_info(k30_ti_client_licenses)")->fetchAll(), 'name');
    foreach ([
        'issued_at'     => 'DATE',                                       // data wydania (NULL = dzień przypisania)
        'billing_form'  => "TEXT NOT NULL DEFAULT '' CHECK (billing_form IN ('','FAK','FAZ','DSC','GST'))",
        'billing_ref'   => "TEXT NOT NULL DEFAULT ''",                   // nr faktury / kod rabatowy / referencja Goodstack
        'billing_note'  => "TEXT NOT NULL DEFAULT ''",                   // notatka wewnętrzna — kursant jej nie widzi
        'revoked_at'    => 'DATETIME',
        'revoked_by'    => 'INTEGER',
        'revoke_reason' => "TEXT NOT NULL DEFAULT ''",
        'notified_at'   => 'DATETIME',                                   // ostatnia udana wysyłka powiadomienia o przypisaniu
        // rozliczenie cykliczne: kwota miesięczna, dzień miesiąca i ewentualny rabat (wiadomość o rabacie w dniu przypisania)
        'monthly_amount' => 'REAL',                                      // cena katalogowa za miesiąc (NULL = bez rozliczenia cyklicznego)
        'billing_day'    => 'INTEGER',                                   // dzień miesiąca rozliczenia 1–31 (w krótszych miesiącach: ostatni dzień)
        'discount_type'  => "TEXT NOT NULL DEFAULT ''",                  // '' | pct | amt
        'discount_value' => 'REAL',
        'discount_note'  => "TEXT NOT NULL DEFAULT ''",                  // np. „rabat edukacyjny” — widoczna w wiadomości
    ] as $col => $ddl) {
        if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE k30_ti_client_licenses ADD COLUMN $col $ddl");
    }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_cl_lic_form ON k30_ti_client_licenses(billing_form)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_cl_lic_exp  ON k30_ti_client_licenses(expires_at)");

    // Dziennik prób powiadomienia (wysłano / błąd / pominięto + powód); adresat zamaskowany.
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_license_notifications (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        assignment_id  INTEGER NOT NULL REFERENCES k30_ti_client_licenses(id) ON DELETE CASCADE,
        channel        TEXT    NOT NULL CHECK (channel IN ('email','sms')),
        recipient_kind TEXT    NOT NULL DEFAULT 'kursant',               -- kursant | opiekun
        recipient      TEXT    NOT NULL DEFAULT '',                      -- zamaskowany adres / numer
        status         TEXT    NOT NULL DEFAULT 'sent',                  -- sent | failed | skipped
        detail         TEXT    NOT NULL DEFAULT '',                      -- błąd albo powód pominięcia
        created_by     INTEGER,
        created_at     DATETIME
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_lic_notif_asg ON k30_ti_license_notifications(assignment_id)");

    // Opcja konta „tylko rozliczenie licencji” (bez zajęć i rozliczeń lekcji; wymaga licencji z kwotą miesięczną)
    $accCols = array_column($pdo->query("PRAGMA table_info(k30_ti_student_accounts)")->fetchAll(), 'name');
    if ($accCols && !in_array('licenses_only', $accCols, true)) {
        $pdo->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN licenses_only INTEGER NOT NULL DEFAULT 0");
    }

    // Dziennik przypomnień o rozliczeniu miesięcznym — jedno na licencję, okres, kanał i adresata (dedup)
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_license_billing_msgs (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        assignment_id  INTEGER NOT NULL REFERENCES k30_ti_client_licenses(id) ON DELETE CASCADE,
        kind           TEXT    NOT NULL CHECK (kind IN ('start','reminder')),
        period         TEXT    NOT NULL DEFAULT '',                      -- RRRR-MM terminu rozliczenia (start: miesiąc przypisania)
        channel        TEXT    NOT NULL CHECK (channel IN ('email','sms')),
        recipient_kind TEXT    NOT NULL DEFAULT 'kursant',
        recipient      TEXT    NOT NULL DEFAULT '',
        status         TEXT    NOT NULL DEFAULT 'pending',               -- pending | sent | failed | skipped
        detail         TEXT    NOT NULL DEFAULT '',
        amount         REAL,
        created_at     DATETIME
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ti_lic_bm_once
                ON k30_ti_license_billing_msgs(assignment_id, kind, period, channel, recipient_kind) WHERE status IN ('pending','sent')");
}
