<?php
/**
 * includes/crm_newsletter_schema.php
 * Samonaprawa schematu modułu newsletterów CRM (idempotentna, agnostyczna silnikowo).
 *
 * JEDNO źródło prawdy o kolumnach i tabelach edytora blokowego + trackingu zdarzeń.
 * Wzorowane na includes/letters_schema.php: żadnego PRAGMA table_info (tylko SQLite),
 * tylko `ALTER TABLE ADD COLUMN` per kolumna w try/catch — istniejąca kolumna rzuca
 * „duplicate", który ignorujemy. Bez DEFAULT na TEXT (restrykcja MySQL) — wartości
 * domyślne ustawia kod.
 *
 * CO DOKŁADA DO ISTNIEJĄCEGO MODUŁU KAMPANII:
 *   1. design_json — dokument bloków edytora (kampania + szablon). Renderowany
 *      serwerowo przez includes/crm_email_render.php, więc podgląd w edytorze
 *      i realna wysyłka pochodzą z tej samej implementacji.
 *   2. html_snapshot/text_snapshot — treść ZAMROŻONA w chwili startu wysyłki.
 *      Bez tego edycja szablonu nazajutrz unieważnia statystyki kampanii.
 *   3. crm_campaign_events — append-only rejestr zdarzeń. Dotąd istniały wyłącznie
 *      liczniki na crm_campaigns (opened_count itd.), więc nie dało się odpowiedzieć
 *      „kto kliknął", „w co kliknął" ani segmentować po historii interakcji.
 *   4. crm_campaign_links — kliknięcia po ID linku, nie po URL-u w query stringu.
 *      Stare crm/track/click.php?u=<pełny URL> było otwartym przekierowaniem.
 *   5. crm_suppressions — twarde odbicia i skargi obok istniejącej flagi
 *      crm_contacts.email_opt_out (flaga zostaje, to nie jest jej zamiennik).
 *   6. crm_segments — zapisane segmenty dynamiczne (filtr jako drzewo warunków).
 *
 * Wymaga wcześniejszego includes/db.php.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    try { $pdo = db(); } catch (\Throwable $e) { return; }

    // ── 1. Kolumny na istniejących tabelach ──────────────────────────────────
    $columns = [
        // Dokument bloków edytora — szablon (wzorzec) i kampania (konkretna wysyłka)
        "ALTER TABLE crm_templates ADD COLUMN design_json TEXT",
        "ALTER TABLE crm_campaigns ADD COLUMN design_json TEXT",
        // Zamrożona treść wysyłki
        "ALTER TABLE crm_campaigns ADD COLUMN html_snapshot TEXT",
        "ALTER TABLE crm_campaigns ADD COLUMN text_snapshot TEXT",
        // Tekst podglądu w skrzynce odbiorczej (preheader)
        "ALTER TABLE crm_campaigns ADD COLUMN preheader TEXT",
        // Filtr segmentu ad-hoc (segment_type='filter') i wskazanie zapisanego segmentu
        "ALTER TABLE crm_campaigns ADD COLUMN segment_filter TEXT",
        "ALTER TABLE crm_campaigns ADD COLUMN segment_id INTEGER",
        // Tempo wysyłki (wiadomości/min) — ochrona reputacji nadawcy
        "ALTER TABLE crm_campaigns ADD COLUMN throttle_per_minute INTEGER NOT NULL DEFAULT 600",
        // Moment startu wysyłki (osobno od sent_at = moment domknięcia)
        "ALTER TABLE crm_campaigns ADD COLUMN send_started_at DATETIME",
        // Liczba pominiętych odbiorców + powód (wykluczenie / brak zgody)
        "ALTER TABLE crm_campaigns ADD COLUMN skipped_count INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE crm_campaigns ADD COLUMN bounced_count INTEGER NOT NULL DEFAULT 0",
        // Odbiorca: zamrożone dane personalizacji + powód pominięcia
        "ALTER TABLE crm_campaign_recipients ADD COLUMN merge_data TEXT",
        "ALTER TABLE crm_campaign_recipients ADD COLUMN skip_reason TEXT",
    ];
    foreach ($columns as $sql) {
        try { $pdo->exec($sql); } catch (\Throwable $e) {}
    }

    // ── 2. Zdarzenia (append-only) ───────────────────────────────────────────
    // Nie ma tu UNIQUE na (recipient_id, type): otwarcie i kliknięcie mogą
    // wystąpić wielokrotnie i chcemy je liczyć. Unikalne otwarcia liczymy
    // przez COUNT(DISTINCT recipient_id) w zapytaniu, nie przez schemat.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_campaign_events (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id  INTEGER NOT NULL,
            recipient_id INTEGER,
            contact_id   INTEGER,
            type         TEXT    NOT NULL,
            link_id      INTEGER,
            ip           TEXT,
            user_agent   TEXT,
            detail       TEXT,
            occurred_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_camp_ev_camp ON crm_campaign_events(campaign_id, type)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_camp_ev_rcpt ON crm_campaign_events(recipient_id, type)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_camp_ev_cont ON crm_campaign_events(contact_id, occurred_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_camp_ev_link ON crm_campaign_events(link_id)");
    } catch (\Throwable $e) {}

    // ── 3. Linki kampanii ────────────────────────────────────────────────────
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_campaign_links (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL,
            url         TEXT    NOT NULL,
            label       TEXT,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_crm_camp_links_uq ON crm_campaign_links(campaign_id, url)");
    } catch (\Throwable $e) {}

    // ── 4. Lista wykluczeń ───────────────────────────────────────────────────
    // COLLATE NOCASE nie przechodzi na MySQL, więc normalizację adresu robimy
    // w kodzie (crm_suppression_add/_has zawsze mb_strtolower + trim).
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_suppressions (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            email       TEXT    NOT NULL,
            reason      TEXT    NOT NULL,
            campaign_id INTEGER,
            detail      TEXT,
            created_by  INTEGER,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_crm_suppr_email ON crm_suppressions(email)");
    } catch (\Throwable $e) {}

    // ── 5. Zapisane segmenty ─────────────────────────────────────────────────
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_segments (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            name         TEXT    NOT NULL,
            description  TEXT,
            filter_json  TEXT    NOT NULL,
            cached_count INTEGER,
            cached_at    DATETIME,
            created_by   INTEGER,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_crm_segments_name ON crm_segments(name)");
    } catch (\Throwable $e) {}

    // UWAGA: kolumny mail_queue.headers TU NIE MA celowo — schemat kolejki
    // mieszkał zawsze w includes/mail_queue.php i tam został dołożony. Dublowanie
    // definicji w dwóch plikach to dokładnie ten błąd, który opisuje
    // includes/letters_schema.php.
})();
