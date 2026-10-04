<?php
/**
 * modules/ti_payment_reminders/logic/tiPaymentRemindersSchema.php — przypomnienia o braku wpłaty (TI): schemat.
 *
 * k30_ti_payment_reminders       — dziennik wysyłek (po jednym wierszu na odbiorcę, kanał i dzień): wysłano / błąd + kwota,
 *                                  zamaskowany adresat, dostawca SMS. Częściowy indeks unikalny na (kursant, odbiorca, kanał, dzień)
 *                                  dla statusów pending/sent chroni przed podwójną wysyłką (dwa równoległe przebiegi, ponowne
 *                                  „Uruchom teraz” z panelu CRON). Pominięcia NIE trafiają do dziennika — widać je w podglądzie
 *                                  na ekranie i w podsumowaniu przebiegu (audyt).
 * k30_ti_payment_reminder_items  — które należności objęło przypomnienie (do limitu „maks. przypomnień na należność”).
 * Ustawienia (ti_payrem_*) leżą w tabeli settings — patrz TiPaymentReminders::settings().
 */
function ti_payment_reminders_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('karty30_migrate')) karty30_migrate();                  // k30_ti_billing (due_date, payer_type, hours_only…)
    if (szo_schema_current('ti_payment_reminders_migrate', __FILE__)) return;   // raz na wersję pliku

    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_payment_reminders (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        run_date       TEXT    NOT NULL,
        client_id      INTEGER NOT NULL,
        recipient_kind TEXT    NOT NULL DEFAULT 'kursant' CHECK (recipient_kind IN ('kursant','opiekun')),
        channel        TEXT    NOT NULL CHECK (channel IN ('email','sms')),
        status         TEXT    NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','sent','failed')),
        reason         TEXT    NOT NULL DEFAULT '',
        recipient      TEXT    NOT NULL DEFAULT '',
        amount         REAL    NOT NULL DEFAULT 0,
        items          INTEGER NOT NULL DEFAULT 0,
        max_days       INTEGER NOT NULL DEFAULT 0,
        provider       TEXT    NOT NULL DEFAULT '',
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ti_payrem_once
                ON k30_ti_payment_reminders(client_id, recipient_kind, channel, run_date) WHERE status IN ('pending','sent')");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_payrem_client ON k30_ti_payment_reminders(client_id, channel, run_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_payrem_date   ON k30_ti_payment_reminders(run_date)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ti_payment_reminder_items (
        reminder_id INTEGER NOT NULL REFERENCES k30_ti_payment_reminders(id) ON DELETE CASCADE,
        billing_id  INTEGER NOT NULL,
        amount      REAL    NOT NULL DEFAULT 0,
        PRIMARY KEY (reminder_id, billing_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ti_payrem_items_billing ON k30_ti_payment_reminder_items(billing_id)");
}
