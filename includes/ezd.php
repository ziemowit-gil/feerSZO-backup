<?php
/**
 * Moduł EZD / Wirtualne biurko — helpery DB + auto-migracja.
 *
 * Hierarchia: Teczka (JRWA) → Sprawa → Pismo / Umowa
 *             Każdy poziom: Załączniki, Dekretacje, Log
 */

/**
 * Bramka dostępu do modułu EZD — wymagana na każdej stronie /ezd/*.
 * Zgodnie z macierzą uprawnień (admin/ezd_access_matrix.php) dostęp do modułu
 * ma tylko rola z can_read/can_write('ezd') lub administrator; użytkownik bez
 * takiego uprawnienia (np. zwykły viewer bez roli EZD) nie wchodzi wcale.
 */
function ezd_require_access(): void {
    if (can_read('ezd') || can_write('ezd')) return;
    flash_set('error', 'Nie masz dostępu do modułu Wirtualne biurko.');
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

// ── Auto-migracja ─────────────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo  = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_jrwa (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        symbol      TEXT    NOT NULL UNIQUE,
        title       TEXT    NOT NULL,
        kat_arch    TEXT    NOT NULL DEFAULT 'B10',
        description TEXT    NOT NULL DEFAULT '',
        parent_id   INTEGER REFERENCES ezd_jrwa(id) ON DELETE SET NULL,
        sort_order  INTEGER NOT NULL DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_teczki (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        jrwa_id     INTEGER REFERENCES ezd_jrwa(id) ON DELETE SET NULL,
        symbol      TEXT    NOT NULL,
        title       TEXT    NOT NULL,
        rok         INTEGER NOT NULL,
        status      TEXT    NOT NULL DEFAULT 'open',
        owner_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        closed_at   DATETIME
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_sprawy (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        teczka_id   INTEGER NOT NULL REFERENCES ezd_teczki(id) ON DELETE RESTRICT,
        znak_sprawy TEXT    NOT NULL UNIQUE,
        numer       INTEGER NOT NULL,
        title       TEXT    NOT NULL,
        description TEXT    NOT NULL DEFAULT '',
        status      TEXT    NOT NULL DEFAULT 'open',
        priority    TEXT    NOT NULL DEFAULT 'normal',
        owner_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        deadline    DATE,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        closed_at   DATETIME
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_pisma (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        sprawa_id   INTEGER NOT NULL REFERENCES ezd_sprawy(id) ON DELETE CASCADE,
        sygnatura   TEXT    NOT NULL,
        kierunek    TEXT    NOT NULL DEFAULT 'przychodzace',
        title       TEXT    NOT NULL,
        tresc       TEXT    NOT NULL DEFAULT '',
        nadawca     TEXT    NOT NULL DEFAULT '',
        odbiorca    TEXT    NOT NULL DEFAULT '',
        data_pisma  DATE,
        data_wplywu DATE,
        data_wysylki DATE,
        status      TEXT    NOT NULL DEFAULT 'nowe',
        owner_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_umowy (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        sprawa_id       INTEGER NOT NULL REFERENCES ezd_sprawy(id) ON DELETE CASCADE,
        sygnatura       TEXT    NOT NULL,
        title           TEXT    NOT NULL,
        typ             TEXT    NOT NULL DEFAULT 'umowa',
        strona          TEXT    NOT NULL DEFAULT '',
        wartosc         REAL,
        waluta          TEXT    NOT NULL DEFAULT 'PLN',
        data_zawarcia   DATE,
        data_od         DATE,
        data_do         DATE,
        warunki_platnosci TEXT  NOT NULL DEFAULT '',
        status          TEXT    NOT NULL DEFAULT 'projekt',
        owner_id        INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        ref_type        TEXT,
        ref_id          INTEGER
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_dekretacje (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        sprawa_id       INTEGER REFERENCES ezd_sprawy(id) ON DELETE CASCADE,
        pismo_id        INTEGER REFERENCES ezd_pisma(id)  ON DELETE CASCADE,
        umowa_id        INTEGER REFERENCES ezd_umowy(id)  ON DELETE CASCADE,
        zlecajacy_id    INTEGER NOT NULL REFERENCES users(id),
        wykonawca_id    INTEGER NOT NULL REFERENCES users(id),
        dyspozycja      TEXT    NOT NULL DEFAULT 'do_zalat',
        tresc           TEXT    NOT NULL DEFAULT '',
        deadline        DATE,
        status          TEXT    NOT NULL DEFAULT 'oczekuje',
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        completed_at    DATETIME
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_zalaczniki (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        sprawa_id       INTEGER REFERENCES ezd_sprawy(id) ON DELETE CASCADE,
        pismo_id        INTEGER REFERENCES ezd_pisma(id)  ON DELETE CASCADE,
        umowa_id        INTEGER REFERENCES ezd_umowy(id)  ON DELETE CASCADE,
        filename        TEXT    NOT NULL,
        original_name   TEXT    NOT NULL,
        mime_type       TEXT    NOT NULL DEFAULT '',
        file_size       INTEGER NOT NULL DEFAULT 0,
        wersja          INTEGER NOT NULL DEFAULT 1,
        prev_id         INTEGER REFERENCES ezd_zalaczniki(id) ON DELETE SET NULL,
        uploaded_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        uploaded_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_log (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        teczka_id       INTEGER REFERENCES ezd_teczki(id)  ON DELETE SET NULL,
        sprawa_id       INTEGER REFERENCES ezd_sprawy(id)  ON DELETE SET NULL,
        pismo_id        INTEGER REFERENCES ezd_pisma(id)   ON DELETE SET NULL,
        umowa_id        INTEGER REFERENCES ezd_umowy(id)   ON DELETE SET NULL,
        user_id         INTEGER NOT NULL,
        action          TEXT    NOT NULL,
        details         TEXT    NOT NULL DEFAULT '',
        ip              TEXT    NOT NULL DEFAULT '',
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Rejestr Przesyłek Wpływających (RPW) / dziennik podawczy + koszulka
    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_rpw (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        rpw_nr        INTEGER NOT NULL,
        rok           INTEGER NOT NULL,
        data_wplywu   DATE    NOT NULL,
        typ           TEXT    NOT NULL DEFAULT 'list',
        nadawca       TEXT    NOT NULL DEFAULT '',
        znak_obcy     TEXT    NOT NULL DEFAULT '',
        opis          TEXT    NOT NULL DEFAULT '',
        uwagi         TEXT    NOT NULL DEFAULT '',
        status        TEXT    NOT NULL DEFAULT 'nowa',
        sprawa_id     INTEGER REFERENCES ezd_sprawy(id) ON DELETE SET NULL,
        pismo_id      INTEGER REFERENCES ezd_pisma(id)  ON DELETE SET NULL,
        przekazano_do INTEGER REFERENCES users(id) ON DELETE SET NULL,
        scan_file     TEXT    NOT NULL DEFAULT '',
        scan_name     TEXT    NOT NULL DEFAULT '',
        scan_mime     TEXT    NOT NULL DEFAULT '',
        scan_size     INTEGER NOT NULL DEFAULT 0,
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Dedup powiadomień o terminach (cron ezd_deadline_reminder.php)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_reminder_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        ref_type   TEXT    NOT NULL,
        ref_id     INTEGER NOT NULL,
        kind       TEXT    NOT NULL,
        sent_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(ref_type, ref_id, kind)
    )");

    // Notatki w sprawie
    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_notatki (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        sprawa_id   INTEGER NOT NULL REFERENCES ezd_sprawy(id) ON DELETE CASCADE,
        tresc       TEXT    NOT NULL,
        pinned      INTEGER NOT NULL DEFAULT 0,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Dokumenty wewnętrzne sprawy (notatki służbowe, opinie, protokoły, projekty pism…)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_dokumenty (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        sprawa_id   INTEGER NOT NULL REFERENCES ezd_sprawy(id) ON DELETE CASCADE,
        sygnatura   TEXT    NOT NULL,
        rodzaj      TEXT    NOT NULL DEFAULT 'notatka_sluzbowa',
        title       TEXT    NOT NULL,
        tresc       TEXT    NOT NULL DEFAULT '',
        status      TEXT    NOT NULL DEFAULT 'projekt',
        owner_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Rejestr pełnomocnictw — metadane spraw z JRWA „013" (1:1 ze sprawą)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_pelnomocnictwa (
        sprawa_id       INTEGER PRIMARY KEY REFERENCES ezd_sprawy(id) ON DELETE CASCADE,
        numer           TEXT    NOT NULL DEFAULT '',
        mocodawca       TEXT    NOT NULL DEFAULT '',
        pelnomocnik     TEXT    NOT NULL DEFAULT '',
        zakres          TEXT    NOT NULL DEFAULT '',
        data_udzielenia DATE,
        data_waznosci   DATE,
        data_odwolania  DATE,
        uwagi           TEXT    NOT NULL DEFAULT '',
        updated_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Grupy plików w sprawie
    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_grupy_plikow (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        sprawa_id   INTEGER NOT NULL REFERENCES ezd_sprawy(id) ON DELETE CASCADE,
        nazwa       TEXT    NOT NULL,
        sort_order  INTEGER NOT NULL DEFAULT 0,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Protokół przerejestrowania spraw do Nowego JRWA (asystent AI)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_przerejestrowania (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        sprawa_id   INTEGER REFERENCES ezd_sprawy(id) ON DELETE SET NULL,
        stary_znak  TEXT    NOT NULL DEFAULT '',
        nowy_znak   TEXT    NOT NULL,
        kod_jrwa    TEXT    NOT NULL,
        forma       TEXT    NOT NULL DEFAULT '',
        opis        TEXT    NOT NULL DEFAULT '',
        adnotacja_stara TEXT NOT NULL DEFAULT '',
        adnotacja_nowa  TEXT NOT NULL DEFAULT '',
        instrukcja  TEXT    NOT NULL DEFAULT '',
        zastosowano INTEGER NOT NULL DEFAULT 0,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Kolumny dokładane idempotentnie (istniejące wdrożenia utworzyły tabelę bez nich)
    try { $pdo->exec("ALTER TABLE ezd_przerejestrowania ADD COLUMN sprawa_id   INTEGER"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_przerejestrowania ADD COLUMN zastosowano INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

    // Definicje workflow (BPM) per JRWA — kroki jako JSON
    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_workflows (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        jrwa_id     INTEGER UNIQUE REFERENCES ezd_jrwa(id) ON DELETE CASCADE,
        name        TEXT    NOT NULL DEFAULT '',
        steps       TEXT    NOT NULL DEFAULT '[]',
        updated_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Współdzielenie spraw — dostęp dodatkowy ponad rolę/właściciela
    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_sprawa_users (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        sprawa_id   INTEGER NOT NULL REFERENCES ezd_sprawy(id) ON DELETE CASCADE,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        uprawnienie TEXT    NOT NULL DEFAULT 'odczyt',
        added_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        added_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(sprawa_id, user_id)
    )");

    // Przekazanie dostępu do konkretnych plików (niezależnie od dostępu do całej sprawy)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_zalacznik_access (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        zalacznik_id INTEGER NOT NULL REFERENCES ezd_zalaczniki(id) ON DELETE CASCADE,
        user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        granted_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        granted_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        note         TEXT    NOT NULL DEFAULT '',
        UNIQUE(zalacznik_id, user_id)
    )");

    // Archiwum zakładowe — spisy zdawczo-odbiorcze i protokoły brakowania
    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_arch_spisy (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        typ         TEXT    NOT NULL DEFAULT 'zdawczo_odbiorczy',
        nr          INTEGER NOT NULL,
        rok         INTEGER NOT NULL,
        tytul       TEXT    NOT NULL DEFAULT '',
        komorka     TEXT    NOT NULL DEFAULT '',
        status      TEXT    NOT NULL DEFAULT 'projekt',
        uwagi       TEXT    NOT NULL DEFAULT '',
        zgoda_ap    TEXT    NOT NULL DEFAULT '',
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        approved_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        approved_at DATETIME,
        realized_at DATETIME
    )");
    // Pozycje spisu — snapshot metryki segregatora (przetrwa brakowanie/usunięcie teczki)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_arch_pozycje (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        spis_id       INTEGER NOT NULL REFERENCES ezd_arch_spisy(id) ON DELETE CASCADE,
        teczka_id     INTEGER REFERENCES ezd_teczki(id) ON DELETE SET NULL,
        lp            INTEGER NOT NULL DEFAULT 0,
        znak          TEXT    NOT NULL DEFAULT '',
        tytul         TEXT    NOT NULL DEFAULT '',
        rok_od        INTEGER,
        rok_do        INTEGER,
        kat_arch      TEXT    NOT NULL DEFAULT '',
        liczba_teczek INTEGER NOT NULL DEFAULT 1,
        rok_brakowania INTEGER,
        uwagi         TEXT    NOT NULL DEFAULT '',
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Szablony pism / dokumentów — korespondencja seryjna (mail merge)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_szablony (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        nazwa         TEXT    NOT NULL,
        kategoria     TEXT    NOT NULL DEFAULT 'pismo',
        kierunek      TEXT    NOT NULL DEFAULT 'wychodzace',
        rodzaj_medium TEXT    NOT NULL DEFAULT 'papier',
        tytul_wzor    TEXT    NOT NULL DEFAULT '',
        tresc_wzor    TEXT    NOT NULL DEFAULT '',
        opis          TEXT    NOT NULL DEFAULT '',
        aktywny       INTEGER NOT NULL DEFAULT 1,
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Kolumny dokładane do istniejących tabel (idempotentnie)
    foreach ([
        "ALTER TABLE ezd_sprawy     ADD COLUMN parent_id   INTEGER REFERENCES ezd_sprawy(id) ON DELETE SET NULL",
        "ALTER TABLE ezd_sprawy     ADD COLUMN ciagla      INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE ezd_sprawy     ADD COLUMN etap        TEXT    NOT NULL DEFAULT 'wszczeta'",
        "ALTER TABLE ezd_zalaczniki ADD COLUMN dokument_id INTEGER REFERENCES ezd_dokumenty(id) ON DELETE CASCADE",
        "ALTER TABLE ezd_zalaczniki ADD COLUMN grupa_id    INTEGER REFERENCES ezd_grupy_plikow(id) ON DELETE SET NULL",
        "ALTER TABLE ezd_pisma      ADD COLUMN rodzaj_medium TEXT  NOT NULL DEFAULT 'papier'",
        "ALTER TABLE ezd_sprawy     ADD COLUMN ref_type     TEXT",
        "ALTER TABLE ezd_sprawy     ADD COLUMN ref_id       INTEGER",
        "ALTER TABLE ezd_zalaczniki ADD COLUMN sp_web_url   TEXT",
        "ALTER TABLE ezd_zalaczniki ADD COLUMN sp_synced_at DATETIME",
        "ALTER TABLE ezd_zalaczniki ADD COLUMN sp_drive_id  TEXT",
        "ALTER TABLE ezd_zalaczniki ADD COLUMN sp_item_id   TEXT",
        "ALTER TABLE ezd_zalaczniki ADD COLUMN converted_from_id INTEGER REFERENCES ezd_zalaczniki(id) ON DELETE SET NULL",
        "ALTER TABLE ezd_teczki     ADD COLUMN arch_status    TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE ezd_teczki     ADD COLUMN arch_spis_id   INTEGER REFERENCES ezd_arch_spisy(id) ON DELETE SET NULL",
        "ALTER TABLE ezd_teczki     ADD COLUMN rok_brakowania INTEGER",
        "ALTER TABLE ezd_teczki     ADD COLUMN arch_at        DATETIME",
        "ALTER TABLE ezd_rpw        ADD COLUMN przekazano_unit_id INTEGER",
        "ALTER TABLE ezd_sprawy     ADD COLUMN hidden_at         DATETIME",
        "ALTER TABLE ezd_sprawy     ADD COLUMN hidden_by         INTEGER REFERENCES users(id) ON DELETE SET NULL",
    ] as $alter) {
        try { $pdo->exec($alter); } catch (\Throwable $e) { /* kolumna już istnieje */ }
    }

    // Indeksy wydajnościowe
    foreach ([
        "CREATE INDEX IF NOT EXISTS idx_ezd_sprawy_teczka  ON ezd_sprawy(teczka_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_pisma_sprawa   ON ezd_pisma(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_umowy_sprawa   ON ezd_umowy(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_dekr_sprawa    ON ezd_dekretacje(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_dekr_wyk       ON ezd_dekretacje(wykonawca_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_zal_sprawa     ON ezd_zalaczniki(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_log_sprawa     ON ezd_log(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpw_rok        ON ezd_rpw(rok, rpw_nr)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpw_status     ON ezd_rpw(status)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpw_sprawa     ON ezd_rpw(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_notatki_sprawa ON ezd_notatki(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_dok_sprawa     ON ezd_dokumenty(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_zal_dokument   ON ezd_zalaczniki(dokument_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_zal_grupa      ON ezd_zalaczniki(grupa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_grupy_sprawa   ON ezd_grupy_plikow(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_sprawy_parent  ON ezd_sprawy(parent_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_sprawa_users_s ON ezd_sprawa_users(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_sprawa_users_u ON ezd_sprawa_users(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_zal_access_zal ON ezd_zalacznik_access(zalacznik_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_zal_access_usr ON ezd_zalacznik_access(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_sprawy_ref     ON ezd_sprawy(ref_type, ref_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_arch_poz_spis  ON ezd_arch_pozycje(spis_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_arch_poz_tecz  ON ezd_arch_pozycje(teczka_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_teczki_arch    ON ezd_teczki(arch_status, rok_brakowania)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_szablony_akt   ON ezd_szablony(aktywny, kategoria)",
    ] as $idx) {
        try { $pdo->exec($idx); } catch (\Throwable $e) {}
    }

    // ── Seed / rozbudowa wykazu JRWA ─────────────────────────────────────────
    // Pełna, hierarchiczna struktura numeryczna (0–5) z podklasami i kategoriami
    // archiwalnymi. Węzły strukturalne (grupy i klasy mające podklasy) mają pustą
    // kat. arch. — dokumentuje się w klasach końcowych. Wstawianie jest idempotentne
    // po symbolu (bezpieczne dla istniejących wdrożeń). Guard: obecność symbolu '545'
    // oznacza, że nowy wykaz już zaseedowano → pomijamy przy kolejnych żądaniach.
    $hasNewJrwa = $pdo->query("SELECT 1 FROM ezd_jrwa WHERE symbol='545' LIMIT 1")->fetchColumn();
    if (!$hasNewJrwa) {
        // [symbol, tytuł, kat_arch, opis, [podklasy]]
        $jrwaCatalog = [
            ['0', 'Zarządzanie, organy statutowe i organizacja', '', '', [
                ['00', 'Akta ustrojowe, rejestracja i status prawny', '', '', [
                    ['001', 'Statut', 'A', '', []],
                    ['002', 'KRS, NIP, REGON', 'A', '', []],
                    ['005', 'Status OPP', 'A', 'Organizacja pożytku publicznego', []],
                ]],
                ['01', 'Działalność organów kolegialnych i nadzorczych', '', '', [
                    ['011', 'Posiedzenia Zarządu', 'A', '', []],
                    ['012', 'Uchwały Zarządu', 'A', '', []],
                    ['014', 'Uchwały Rady', 'A', '', []],
                ]],
                ['02', 'Organizacja wewnętrzna i zarządzanie', '', '', [
                    ['021', 'Regulaminy', 'A', '', []],
                    ['022', 'Zarządzenia', 'A', '', []],
                    ['025', 'Księga procedur', 'A', '', []],
                ]],
                ['03', 'Pełnomocnictwa, upoważnienia i reprezentacja', '', '', [
                    ['031', 'Pełnomocnictwa', 'B10', '', []],
                    ['033', 'Rejestr pełnomocnictw', 'B10', '', []],
                ]],
                ['04', 'Kontrole zewnętrzne i audyty', '', '', [
                    ['041', 'Kontrole ministerstwa', 'A', '', []],
                    ['043', 'Audyty zewnętrzne', 'BE10', '', []],
                ]],
                ['05', 'Planowanie strategiczne i rozwój', 'A', '', []],
            ]],
            ['1', 'Sprawy kadrowe, zatrudnienie i BHP', '', '', [
                ['10', 'Rekrutacja i nawiązanie stosunku pracy', '', '', [
                    ['102', 'Akta osobowe', 'B50', '', []],
                    ['103', 'Umowy o pracę', 'B50', '', []],
                ]],
                ['11', 'Umowy cywilnoprawne i B2B', '', '', [
                    ['111', 'Umowy zlecenia', 'B10', '', []],
                    ['112', 'Umowy o dzieło', 'B10', '', []],
                    ['113', 'Umowy B2B', 'B10', '', []],
                ]],
                ['12', 'Ewidencja czasu pracy i płace', '', '', [
                    ['121', 'Karty czasu pracy', 'B10', '', []],
                    ['123', 'Listy płac', 'B50', '', []],
                    ['124', 'ZUS', 'B50', '', []],
                ]],
                ['13', 'Bezpieczeństwo i Higiena Pracy (BHP)', '', '', [
                    ['131', 'Szkolenia BHP', 'B10', '', []],
                    ['133', 'Wypadki', 'B10', '', []],
                ]],
                ['14', 'Wolontariat, staże i praktyki', '', '', [
                    ['141', 'Porozumienia o świadczeniu świadczeń wolontariackich', 'B10', 'Wolontariat długoterminowy / pisemny', []],
                    ['142', 'Wolontariat akcyjny i krótkoterminowy (WOL)', 'B5', 'Bez umów pisemnych: oświadczenia, listy obecności, zgody rodziców', []],
                    ['143', 'Umowy o staże i praktyki', 'B10', '', []],
                    ['144', 'Rejestr wolontariuszy i zaświadczenia', 'B10', '', []],
                ]],
                ['15', 'Podnoszenie kwalifikacji i sprawy socjalne', 'B10', '', []],
            ]],
            ['2', 'Finanse, księgowość i majątek', '', '', [
                ['20', 'Organizacja finansowo-księgowa', '', '', [
                    ['201', 'Polityka rachunkowości', 'B10', '', []],
                ]],
                ['21', 'Dokumentacja i dowody księgowe', '', '', [
                    ['211', 'Faktury kosztowe', 'B5', '', []],
                    ['212', 'Faktury sprzedażowe', 'B5', '', []],
                    ['214', 'Wyciągi bankowe', 'B5', '', []],
                ]],
                ['22', 'Rozliczenia podatkowe i budżetowanie', '', '', [
                    ['221', 'CIT', 'B5', '', []],
                    ['222', 'PIT', 'B5', '', []],
                    ['224', 'Budżety', 'B10', '', []],
                ]],
                ['23', 'Sprawozdawczość finansowa i merytoryczna', '', '', [
                    ['231', 'Roczne sprawozdania finansowe', 'A', '', []],
                    ['232', 'Sprawozdania merytoryczne do ministerstwa', 'A', '', []],
                ]],
                ['24', 'Zarządzanie majątkiem i inwentaryzacja', '', '', [
                    ['241', 'Środki trwałe', 'B10', '', []],
                    ['242', 'Wyposażenie', 'B5', '', []],
                ]],
            ]],
            ['3', 'Działalność statutowa i projekty', '', 'Klasa czysta — bez podziału strukturalnego. Podklasy (3.x) nadaje się ręcznie per projekt / akcja / grant, a kategorię archiwalną ustala indywidualnie przy zakładaniu.', []],
            ['4', 'Komunikacja, PR i współpraca zewnętrzna', '', '', [
                ['40', 'Relacje z mediami i wizerunek publiczny', '', '', [
                    ['401', 'Informacje prasowe', 'B5', '', []],
                    ['403', 'Księga Znaku', 'A', '', []],
                ]],
                ['41', 'Narzędzia komunikacji i materiały promocyjne', '', '', [
                    ['411', 'Strona WWW', 'B5', '', []],
                    ['412', 'Social media', 'B5', '', []],
                    ['413', 'Archiwum foto/wideo', 'BE10', '', []],
                ]],
                ['42', 'Współpraca instytucjonalna i partnerstwa', '', '', [
                    ['421', 'Listy intencyjne', 'B10', '', []],
                    ['422', 'Partnerstwa', 'B10', '', []],
                    ['423', 'Umowy sponsorskie', 'B10', '', []],
                ]],
                ['43', 'Organizacja wydarzeń i konferencji', '', '', [
                    ['431', 'Plany i agendy wydarzeń', 'B5', '', []],
                    ['432', 'Umowy z prelegentami i cateringiem', 'B5', '', []],
                ]],
                ['44', 'Patronaty', '', '', [
                    ['441', 'Patronaty udzielone przez Fundację', 'B10', 'Korespondencja i umowy dotyczące objęcia patronatem wydarzeń lub inicjatyw zewnętrznych', []],
                    ['442', 'Patronaty honorowe i nagrody', 'B10', 'Patronaty instytucjonalne i wyróżnienia honorowe przyznane Fundacji', []],
                ]],
            ]],
            ['5', 'Administracja, IT, RODO i Systemy AI', '', '', [
                ['50', 'Obsługa kancelaryjna i bieżąca administracja', '', '', [
                    ['501', 'Dziennik przychodzący', 'B10', '', []],
                    ['502', 'Dziennik wychodzący', 'B10', '', []],
                    ['504', 'Najem lokalu', 'B10', '', []],
                ]],
                ['51', 'Infrastruktura IT i zarządzanie systemami', '', '', [
                    ['511', 'Sprzęt', 'B5', '', []],
                    ['512', 'Licencje / SaaS', 'B5', '', []],
                    ['513', 'Domeny i hosting', 'B10', '', []],
                ]],
                ['52', 'Ochrona Danych Osobowych (RODO)', '', '', [
                    ['521', 'Polityka ochrony danych', 'B10', '', []],
                    ['522', 'Rejestr czynności przetwarzania (RCP)', 'B10', '', []],
                    ['523', 'Upoważnienia do przetwarzania', 'B10', '', []],
                    ['524', 'Umowy powierzenia', 'B10', '', []],
                ]],
                ['53', 'Zarządzanie dokumentacją i archiwum zakładowe', '', '', [
                    ['532', 'Spisy zdawczo-odbiorcze', 'A', '', []],
                    ['533', 'Brakowanie akt', 'A', '', []],
                ]],
                ['54', 'Systemy Sztucznej Inteligencji (AI) i Automatyzacje', '', '', [
                    ['541', 'Polityka AI Governance', 'B10', '', []],
                    ['542', 'Rejestr systemów i ryzyk AI', 'B10', '', []],
                    ['543', 'Umowy i DPA z dostawcami LLM', 'B10', '', []],
                    ['544', 'Prompty systemowe i bazy wiedzy RAG', 'B5', '', []],
                    ['545', 'Skrypty integracyjne kategoryzacji dokumentów', 'B5', '', []],
                ]],
            ]],
        ];

        $insJrwa  = $pdo->prepare("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order,parent_id) VALUES (?,?,?,?,?,?)");
        $findJrwa = $pdo->prepare("SELECT id FROM ezd_jrwa WHERE symbol=?");
        $ordJrwa  = 0;
        $seedJrwa = function (array $nodes, ?int $parentId) use (&$seedJrwa, $pdo, $insJrwa, $findJrwa, &$ordJrwa) {
            foreach ($nodes as [$sym, $tit, $kat, $desc, $kids]) {
                $ordJrwa += 10;
                $findJrwa->execute([$sym]);
                $existing = $findJrwa->fetchColumn();
                if ($existing !== false) {
                    $nodeId = (int)$existing;             // już istnieje — nie duplikuj, użyj do podpięcia dzieci
                } else {
                    try { $insJrwa->execute([$sym, $tit, $kat, $desc, $ordJrwa, $parentId]); } catch (\Throwable $e) {}
                    $nodeId = (int)$pdo->lastInsertId();
                }
                if ($kids) $seedJrwa($kids, $nodeId);
            }
        };
        $seedJrwa($jrwaCatalog, null);

        // Wycofanie dawnego, ubogiego seedu symbolicznego (ORG/FIN/…): usuń tylko klasy
        // nieużywane (brak powiązanych teczek/workflow). Używane zostają — z adnotacją
        // i zepchnięte na koniec wykazu — aby nie zerwać klasyfikacji istniejących teczek.
        foreach (['ORG','FIN','KAD','WOL','PRM','ZAM','KOR','PR','IT'] as $legacySym) {
            try {
                $findJrwa->execute([$legacySym]);
                $lid = $findJrwa->fetchColumn();
                if ($lid === false) continue;
                $lid  = (int)$lid;
                $refT = (int)$pdo->query("SELECT COUNT(*) FROM ezd_teczki WHERE jrwa_id={$lid}")->fetchColumn();
                $refW = (int)$pdo->query("SELECT COUNT(*) FROM ezd_workflows WHERE jrwa_id={$lid}")->fetchColumn();
                if ($refT === 0 && $refW === 0) {
                    $pdo->prepare("DELETE FROM ezd_jrwa WHERE id=?")->execute([$lid]);
                } else {
                    $pdo->prepare(
                        "UPDATE ezd_jrwa SET sort_order=9500,
                         description=TRIM(COALESCE(description,'') || ' [Klasa wycofana — używać nowego wykazu numerycznego]')
                         WHERE id=?"
                    )->execute([$lid]);
                }
            } catch (\Throwable $e) {}
        }
    }

    // Klasa przejściowa „papierowa" — dokładana idempotentnie, także w istniejących
    // wdrożeniach (seed wyżej działa tylko na pustej tabeli). Symbol celowo nietypowy
    // („0-PAP"), aby odróżniał się od zwykłych haseł i wyróżniał się w wykazie.
    try {
        $hasPap = $pdo->query("SELECT 1 FROM ezd_jrwa WHERE symbol='0-PAP'")->fetchColumn();
        if (!$hasPap) {
            $pdo->prepare("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order) VALUES (?,?,?,?,?)")
                ->execute([
                    '0-PAP',
                    'Sprawy i projekty wszczęte w trybie papierowym (przed wdrożeniem EZD)',
                    'BE10',
                    'Klasa przejściowa. Akta spraw/projektów rozpoczętych w systemie tradycyjnym (papierowym) i kontynuowanych po wdrożeniu EZD — prowadzone dwutorowo: oryginał papierowy pozostaje w teczce aktowej, a w systemie rejestruje się metrykę i odsyłacz. Podlega ekspertyzie archiwum państwowego.',
                    999,
                ]);
        }
    } catch (\Throwable $e) {}

    // Klasa „44 Patronaty" — dodawana idempotentnie do istniejących instalacji.
    try {
        if (!$pdo->query("SELECT 1 FROM ezd_jrwa WHERE symbol='44' LIMIT 1")->fetchColumn()) {
            $parent4 = $pdo->query("SELECT id FROM ezd_jrwa WHERE symbol='4' LIMIT 1")->fetchColumn();
            $pdo->prepare("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order,parent_id) VALUES (?,?,?,?,?,?)")
                ->execute(['44', 'Patronaty', '', '', 445, $parent4 ?: null]);
            $id44 = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order,parent_id) VALUES (?,?,?,?,?,?)")
                ->execute(['441', 'Patronaty udzielone przez Fundację', 'B10', 'Korespondencja i umowy dotyczące objęcia patronatem wydarzeń lub inicjatyw zewnętrznych', 4451, $id44]);
            $pdo->prepare("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order,parent_id) VALUES (?,?,?,?,?,?)")
                ->execute(['442', 'Patronaty honorowe i nagrody', 'B10', 'Patronaty instytucjonalne i wyróżnienia honorowe przyznane Fundacji', 4452, $id44]);
        }
    } catch (\Throwable $e) {}
})();

// ── Stałe ────────────────────────────────────────────────────────────────────

/**
 * Tryb „mini" EZD — uproszczony rejestr spraw i dokumentów bez
 * formalnych elementów postępowania (metryka, obieg/workflow BPM). Włączany
 * w Ustawieniach EZD (ustawienie org `ezd_mini`).
 */
function ezd_mini(): bool {
    return (string) org_setting('ezd_mini') === '1';
}

const EZD_UPLOAD_SUBDIR = 'ezd/';
const EZD_ALLOWED_EXT   = ['pdf','doc','docx','xls','xlsx','odt','ods','pptx','png','jpg','jpeg','gif','zip','txt','csv','eml','msg'];
const EZD_MAX_SIZE      = 25 * 1024 * 1024; // 25 MB
const EZD_OFFICE_ONLINE_EXT = ['doc','docx','xls','xlsx']; // rozszerzenia otwierane w Office Online (Word/Excel)
const EZD_PDF_CONVERTIBLE_EXT = ['doc','docx','xls','xlsx']; // rozszerzenia z możliwością konwersji na PDF

const EZD_STATUSES_SPRAWA = [
    'open'        => ['label' => 'Otwarta',      'class' => 'success'],
    'in_progress' => ['label' => 'W toku',       'class' => 'primary'],
    'suspended'   => ['label' => 'Zawieszona',   'class' => 'warning'],
    'closed'      => ['label' => 'Zamknięta',    'class' => 'secondary'],
];
const EZD_PRIORITIES = [
    'low'    => ['label' => 'Niski',    'class' => 'secondary'],
    'normal' => ['label' => 'Normalny', 'class' => 'primary'],
    'high'   => ['label' => 'Wysoki',   'class' => 'warning'],
    'urgent' => ['label' => 'Pilny',    'class' => 'danger'],
];

/**
 * Etapy obiegu sprawy (workflow BPM) — uporządkowany proces kancelaryjny.
 * order = pozycja na ścieżce; dyspozycja = dyspozycja dekretacji sugerująca ten etap.
 */
const EZD_ETAPY = [
    'wszczeta'   => ['label' => 'Wszczęcie',    'icon' => 'bi-folder-plus',        'class' => 'secondary', 'order' => 1],
    'dekretacja' => ['label' => 'Dekretacja',   'icon' => 'bi-person-lines-fill',  'class' => 'info',      'order' => 2, 'dyspozycja' => 'do_zalat'],
    'realizacja' => ['label' => 'Realizacja',   'icon' => 'bi-gear',               'class' => 'primary',   'order' => 3, 'dyspozycja' => 'do_realizacji'],
    'akceptacja' => ['label' => 'Akceptacja',   'icon' => 'bi-check2-square',      'class' => 'warning',   'order' => 4, 'dyspozycja' => 'do_akcept'],
    'podpis'     => ['label' => 'Podpis',       'icon' => 'bi-pen',                'class' => 'warning',   'order' => 5, 'dyspozycja' => 'do_podpisu'],
    'wysylka'    => ['label' => 'Wysyłka',      'icon' => 'bi-send',               'class' => 'info',      'order' => 6],
    'zakonczona' => ['label' => 'Zakończenie',  'icon' => 'bi-check-circle-fill',  'class' => 'success',   'order' => 7],
];
const EZD_KIERUNKI = [
    'przychodzace' => ['label' => 'Przychodzące', 'icon' => 'bi-box-arrow-in-down-left', 'class' => 'info'],
    'wychodzace'   => ['label' => 'Wychodzące',   'icon' => 'bi-box-arrow-up-right',     'class' => 'primary'],
    'wewnetrzne'   => ['label' => 'Wewnętrzne',   'icon' => 'bi-arrow-left-right',       'class' => 'secondary'],
];
// Rodzaj medium pisma — rozróżnienie korespondencji papierowej od elektronicznej
const EZD_MEDIA = [
    'papier' => ['label' => 'Papierowe',        'icon' => 'bi-file-earmark-text'],
    'email'  => ['label' => 'E-mail',           'icon' => 'bi-at'],
    'epuap'  => ['label' => 'e-Doręczenia',         'icon' => 'bi-mailbox2'],
    'faks'   => ['label' => 'Faks',             'icon' => 'bi-printer'],
    'inne'   => ['label' => 'Inne',             'icon' => 'bi-question-circle'],
];
const EZD_DYSPOZYCJE = [
    'do_zalat'   => 'Do załatwienia',
    'do_akcept'  => 'Do akceptacji',
    'do_wiadom'  => 'Do wiadomości',
    'do_podpisu' => 'Do podpisu',
    'do_realizacji' => 'Do realizacji',
];
const EZD_UMOWA_TYPY = [
    'umowa'        => 'Umowa',
    'aneks'        => 'Aneks',
    'porozumienie' => 'Porozumienie',
    'zlecenie'     => 'Zlecenie',
    'ugoda'        => 'Ugoda',
    'inne'         => 'Inne',
];

// RPW — dziennik podawczy
const EZD_RPW_TYPY = [
    'list'      => ['label' => 'List zwykły',     'icon' => 'bi-envelope'],
    'polecony'  => ['label' => 'List polecony',   'icon' => 'bi-envelope-paper'],
    'paczka'    => ['label' => 'Paczka',          'icon' => 'bi-box-seam'],
    'email'     => ['label' => 'E-mail',          'icon' => 'bi-at'],
    'epuap'     => ['label' => 'e-Doręczenia',         'icon' => 'bi-mailbox2'],
    'fax'       => ['label' => 'Faks',            'icon' => 'bi-printer'],
    'osobiscie' => ['label' => 'Złożone osobiście', 'icon' => 'bi-person-walking'],
    'inne'      => ['label' => 'Inne',            'icon' => 'bi-question-circle'],
];
const EZD_RPW_STATUSES = [
    'nowa'      => ['label' => 'Nowa (koszulka)', 'class' => 'info'],
    'przekazana'=> ['label' => 'Przekazana',      'class' => 'primary'],
    'w_sprawie' => ['label' => 'W sprawie',       'class' => 'success'],
    'odrzucona' => ['label' => 'Odrzucona',       'class' => 'secondary'],
];

// Dokumenty wewnętrzne sprawy
const EZD_DOK_RODZAJE = [
    'notatka_sluzbowa' => 'Notatka służbowa',
    'opinia'           => 'Opinia',
    'protokol'         => 'Protokół',
    'decyzja'          => 'Decyzja / postanowienie',
    'projekt_pisma'    => 'Projekt pisma',
    'raport'           => 'Raport / sprawozdanie',
    'inne'             => 'Inny dokument',
];
const EZD_DOK_STATUSY = [
    'projekt'     => ['label' => 'Projekt',     'class' => 'secondary'],
    'zatwierdzony'=> ['label' => 'Zatwierdzony','class' => 'success'],
    'archiwalny'  => ['label' => 'Archiwalny',  'class' => 'dark'],
];

// ── JRWA ─────────────────────────────────────────────────────────────────────

const EZD_KAT_ARCH = ['A','B5','B10','B25','B50','Bc','BE5','BE10'];

function ezd_jrwa_all(): array {
    return db_all(
        "SELECT j.*, (SELECT COUNT(*) FROM ezd_teczki t WHERE t.jrwa_id=j.id) AS teczki_count
         FROM ezd_jrwa j ORDER BY j.sort_order, j.symbol"
    );
}

function ezd_jrwa_get(int $id): ?array {
    return db_one("SELECT * FROM ezd_jrwa WHERE id=?", [$id]);
}

function ezd_jrwa_create(array $d, int $user_id): int {
    db()->prepare(
        "INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order)
         VALUES (:sym,:tit,:kat,:desc,:ord)"
    )->execute([
        ':sym'  => strtoupper(trim($d['symbol'])),
        ':tit'  => trim($d['title']),
        ':kat'  => in_array($d['kat_arch'] ?? '', EZD_KAT_ARCH, true) ? $d['kat_arch'] : 'B10',
        ':desc' => trim($d['description'] ?? ''),
        ':ord'  => (int)($d['sort_order'] ?? 0),
    ]);
    $id = (int)db()->lastInsertId();
    ezd_log(null, null, null, null, $user_id, 'jrwa_create', 'Dodano hasło JRWA: ' . strtoupper(trim($d['symbol'])));
    return $id;
}

function ezd_jrwa_update(int $id, array $d, int $user_id): void {
    db()->prepare(
        "UPDATE ezd_jrwa SET symbol=:sym,title=:tit,kat_arch=:kat,description=:desc,sort_order=:ord WHERE id=:id"
    )->execute([
        ':sym'  => strtoupper(trim($d['symbol'])),
        ':tit'  => trim($d['title']),
        ':kat'  => in_array($d['kat_arch'] ?? '', EZD_KAT_ARCH, true) ? $d['kat_arch'] : 'B10',
        ':desc' => trim($d['description'] ?? ''),
        ':ord'  => (int)($d['sort_order'] ?? 0),
        ':id'   => $id,
    ]);
    ezd_log(null, null, null, null, $user_id, 'jrwa_update', 'Edytowano hasło JRWA #' . $id);
}

function ezd_jrwa_delete(int $id, int $user_id): void {
    $cnt = (int)(db_one("SELECT COUNT(*) AS c FROM ezd_teczki WHERE jrwa_id=?", [$id])['c'] ?? 0);
    if ($cnt > 0) throw new \RuntimeException("Nie można usunąć — hasło jest używane w $cnt teczce/-ach.");
    $j = ezd_jrwa_get($id);
    db()->prepare("DELETE FROM ezd_jrwa WHERE id=?")->execute([$id]);
    ezd_log(null, null, null, null, $user_id, 'jrwa_delete', 'Usunięto hasło JRWA: ' . ($j['symbol'] ?? $id));
}

// ── Teczki ───────────────────────────────────────────────────────────────────

function ezd_teczka_get(int $id): ?array {
    return db_one(
        "SELECT t.*, j.symbol AS jrwa_symbol, j.title AS jrwa_title, j.kat_arch,
                u.name AS owner_name
         FROM ezd_teczki t
         LEFT JOIN ezd_jrwa j ON j.id = t.jrwa_id
         LEFT JOIN users    u ON u.id = t.owner_id
         WHERE t.id=?", [$id]
    );
}

function ezd_teczki_all(string $status = ''): array {
    $where  = $status ? "WHERE t.status=?" : "";
    $params = $status ? [$status] : [];
    return db_all(
        "SELECT t.*, j.symbol AS jrwa_symbol, j.title AS jrwa_title,
                u.name AS owner_name,
                (SELECT COUNT(*) FROM ezd_sprawy s WHERE s.teczka_id=t.id AND s.status!='closed') AS open_cases,
                (SELECT COUNT(*) FROM ezd_sprawy s WHERE s.teczka_id=t.id) AS total_cases
         FROM ezd_teczki t
         LEFT JOIN ezd_jrwa j ON j.id = t.jrwa_id
         LEFT JOIN users    u ON u.id = t.owner_id
         $where
         ORDER BY t.rok DESC, t.symbol",
        $params
    );
}

/** Formatuje wiersz teczki (ezd_teczka_get/ezd_teczki_all) do publicznej odpowiedzi REST API. */
function ezd_api_teczka(array $r): array {
    return [
        'id'          => (int)$r['id'],
        'symbol'      => $r['symbol'],
        'title'       => $r['title'],
        'rok'         => (int)$r['rok'],
        'status'      => $r['status'],
        'jrwa_symbol' => $r['jrwa_symbol'] ?? null,
        'jrwa_title'  => $r['jrwa_title'] ?? null,
        'kat_arch'    => $r['kat_arch'] ?? null,
        'owner_id'    => $r['owner_id'] !== null ? (int)$r['owner_id'] : null,
        'owner_name'  => $r['owner_name'] ?? null,
        'open_cases'  => array_key_exists('open_cases', $r) ? (int)$r['open_cases'] : null,
        'total_cases' => array_key_exists('total_cases', $r) ? (int)$r['total_cases'] : null,
        'created_at'  => $r['created_at'],
        'closed_at'   => $r['closed_at'],
    ];
}

function ezd_teczka_create(array $d, int $user_id): int {
    $pdo = db();
    $pdo->prepare(
        "INSERT INTO ezd_teczki (jrwa_id,symbol,title,rok,owner_id,created_by)
         VALUES (:jrwa_id,:symbol,:title,:rok,:owner_id,:user_id)"
    )->execute([
        ':jrwa_id'  => $d['jrwa_id'] ?: null,
        ':symbol'   => strtoupper(trim($d['symbol'])),
        ':title'    => trim($d['title']),
        ':rok'      => (int)($d['rok'] ?? date('Y')),
        ':owner_id' => $d['owner_id'] ?: null,
        ':user_id'  => $user_id,
    ]);
    $id = (int)$pdo->lastInsertId();
    ezd_log($id, null, null, null, $user_id, 'teczka_create', 'Utworzono teczkę: ' . $d['title']);
    return $id;
}

function ezd_teczka_update(int $id, array $d, int $user_id): void {
    db()->prepare(
        "UPDATE ezd_teczki SET jrwa_id=:jrwa_id,symbol=:symbol,title=:title,
         rok=:rok,owner_id=:owner_id,status=:status WHERE id=:id"
    )->execute([
        ':jrwa_id'  => $d['jrwa_id'] ?: null,
        ':symbol'   => strtoupper(trim($d['symbol'])),
        ':title'    => trim($d['title']),
        ':rok'      => (int)$d['rok'],
        ':owner_id' => $d['owner_id'] ?: null,
        ':status'   => $d['status'] ?? 'open',
        ':id'       => $id,
    ]);
    if (($d['status'] ?? '') === 'closed') {
        db()->prepare("UPDATE ezd_teczki SET closed_at=datetime('now') WHERE id=? AND closed_at IS NULL")->execute([$id]);
    }
    ezd_log($id, null, null, null, $user_id, 'teczka_update', 'Edytowano teczkę #' . $id);
}

// ── Sprawy ───────────────────────────────────────────────────────────────────

function ezd_sprawa_get(int $id): ?array {
    return db_one(
        "SELECT s.*, t.symbol AS teczka_symbol, t.title AS teczka_title, t.rok AS teczka_rok,
                t.jrwa_id AS jrwa_id,
                u.name AS owner_name, c.name AS creator_name,
                p.znak_sprawy AS parent_znak, p.title AS parent_title
         FROM ezd_sprawy s
         JOIN ezd_teczki t ON t.id = s.teczka_id
         LEFT JOIN users u ON u.id = s.owner_id
         LEFT JOIN users c ON c.id = s.created_by
         LEFT JOIN ezd_sprawy p ON p.id = s.parent_id
         WHERE s.id=?", [$id]
    );
}

function ezd_podsprawy_by_parent(int $parent_id): array {
    return db_all(
        "SELECT s.*, u.name AS owner_name FROM ezd_sprawy s
         LEFT JOIN users u ON u.id=s.owner_id
         WHERE s.parent_id=? ORDER BY s.numer", [$parent_id]
    );
}

function ezd_sprawy_by_teczka(int $teczka_id): array {
    return db_all(
        "SELECT s.*, u.name AS owner_name
         FROM ezd_sprawy s LEFT JOIN users u ON u.id=s.owner_id
         WHERE s.teczka_id=?
         ORDER BY s.numer DESC", [$teczka_id]
    );
}

/**
 * @param int|null $viewer_id Gdy podane i użytkownik NIE ma ogólnej roli z odczytem do EZD
 *                             (can_read('ezd')) ani nie jest adminem — lista zawęża się do
 *                             spraw, w których jest właścicielem/twórcą lub ma współdzielenie.
 */
function ezd_sprawy_all(array $f = [], ?int $viewer_id = null): array {
    $where = ["1=1"]; $params = [];
    if (!empty($f['status']))    { $where[] = "s.status=?";     $params[] = $f['status']; }
    if (!empty($f['priority']))  { $where[] = "s.priority=?";   $params[] = $f['priority']; }
    if (!empty($f['teczka_id'])) { $where[] = "s.teczka_id=?";  $params[] = (int)$f['teczka_id']; }
    if (!empty($f['owner_id']))  { $where[] = "s.owner_id=?";   $params[] = (int)$f['owner_id']; }
    if (!empty($f['q']))         {
        // Szukanie po: tytule koszulki, numerze koszulki (znak), numerze/tytule dokumentu (pisma w koszulce)
        $where[] = "(s.title LIKE ? OR s.znak_sprawy LIKE ?
                     OR EXISTS (SELECT 1 FROM ezd_pisma p WHERE p.sprawa_id=s.id AND (p.sygnatura LIKE ? OR p.title LIKE ?)))";
        $q = '%'.$f['q'].'%'; $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q;
    }
    if (!empty($f['deadline_od'])) { $where[] = "s.deadline>=?"; $params[] = $f['deadline_od']; }
    if (!empty($f['deadline_do'])) { $where[] = "s.deadline<=?"; $params[] = $f['deadline_do']; }
    if (!empty($f['hide_ciagla'])) { $where[] = "COALESCE(s.ciagla,0)=0"; }
    if (!empty($f['hide_old']))    { $where[] = "(s.updated_at >= datetime('now','-14 days') OR COALESCE(s.ciagla,0)=1)"; }
    // Ukryte koszulki: pomijane na liście domyślnie; przy wyszukiwaniu (q) i show_hidden pokazywane
    if (empty($f['q']) && empty($f['show_hidden'])) { $where[] = "s.hidden_at IS NULL"; }
    if (!empty($f['mine_or_shared']) && $viewer_id) {
        $where[] = "(s.owner_id=? OR s.created_by=? OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))";
        array_push($params, $viewer_id, $viewer_id, $viewer_id);
    }
    if ($viewer_id !== null && !is_admin() && !can_read('ezd')) {
        $where[] = "(s.owner_id=? OR s.created_by=? OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))";
        array_push($params, $viewer_id, $viewer_id, $viewer_id);
    }
    return db_all(
        "SELECT s.*, t.symbol AS teczka_symbol, t.title AS teczka_title, u.name AS owner_name,
                dk.wykonawca_id AS dekr_wykonawca_id, dw.name AS dekr_wykonawca_name,
                dk.created_at AS dekr_since, dk.deadline AS dekr_deadline
         FROM ezd_sprawy s
         JOIN ezd_teczki t ON t.id = s.teczka_id
         LEFT JOIN users u ON u.id = s.owner_id
         LEFT JOIN ezd_dekretacje dk ON dk.id = (
             SELECT d2.id FROM ezd_dekretacje d2
             WHERE d2.sprawa_id = s.id AND d2.status='oczekuje'
             ORDER BY d2.created_at DESC, d2.id DESC LIMIT 1
         )
         LEFT JOIN users dw ON dw.id = dk.wykonawca_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY s.title ASC
         LIMIT 200",
        $params
    );
}

/** Formatuje wiersz sprawy (ezd_sprawa_get/ezd_sprawy_all) do publicznej odpowiedzi REST API. */
function ezd_api_sprawa(array $r): array {
    return [
        'id'           => (int)$r['id'],
        'teczka_id'    => (int)$r['teczka_id'],
        'teczka_symbol'=> $r['teczka_symbol'] ?? null,
        'teczka_title' => $r['teczka_title'] ?? null,
        'znak_sprawy'  => $r['znak_sprawy'],
        'numer'        => $r['numer'] ?? null,
        'title'        => $r['title'],
        'description'  => $r['description'],
        'status'       => $r['status'],
        'status_label' => EZD_STATUSES_SPRAWA[$r['status']]['label'] ?? $r['status'],
        'priority'     => $r['priority'],
        'priority_label'=> EZD_PRIORITIES[$r['priority']]['label'] ?? $r['priority'],
        'etap'         => $r['etap'] ?? null,
        'etap_label'   => EZD_ETAPY[$r['etap'] ?? '']['label'] ?? null,
        'ciagla'       => (bool)($r['ciagla'] ?? false),
        'owner_id'     => $r['owner_id'] !== null ? (int)$r['owner_id'] : null,
        'owner_name'   => $r['owner_name'] ?? null,
        'parent_id'    => $r['parent_id'] !== null && $r['parent_id'] !== '' ? (int)$r['parent_id'] : null,
        'deadline'     => $r['deadline'],
        'created_by'   => $r['created_by'] !== null ? (int)$r['created_by'] : null,
        'created_at'   => $r['created_at'],
        'updated_at'   => $r['updated_at'],
        'closed_at'    => $r['closed_at'],
    ];
}

function ezd_sprawa_create(array $d, int $user_id): int {
    $teczka = ezd_teczka_get((int)$d['teczka_id']);
    if (!$teczka) throw new \RuntimeException('Segregator nie istnieje.');
    if ($teczka['status'] === 'closed') throw new \RuntimeException('Segregator jest zamknięty.');

    $rok   = (int)date('Y');
    $numer = _ezd_next_numer((int)$d['teczka_id'], $rok);
    $znak  = strtoupper($teczka['symbol']) . '.' . $numer . '.' . $rok;

    $parent_id = !empty($d['parent_id']) ? (int)$d['parent_id'] : null;
    if ($parent_id) {
        $parent = ezd_sprawa_get($parent_id);
        if (!$parent || (int)$parent['teczka_id'] !== (int)$d['teczka_id']) {
            throw new \RuntimeException('Podkoszulka musi należeć do tego samego segregatora co koszulka nadrzędna.');
        }
    }

    $ciagla = !empty($d['ciagla']) ? 1 : 0;
    db()->prepare(
        "INSERT INTO ezd_sprawy (teczka_id,parent_id,znak_sprawy,numer,title,description,status,priority,owner_id,deadline,ciagla,created_by,updated_at,ref_type,ref_id)
         VALUES (:tid,:pid,:znak,:num,:title,:desc,:status,:prio,:owner,:deadline,:ciagla,:uid,datetime('now'),:rt,:ri)"
    )->execute([
        ':tid'      => (int)$d['teczka_id'],
        ':pid'      => $parent_id,
        ':znak'     => $znak,
        ':num'      => $numer,
        ':title'    => trim($d['title']),
        ':desc'     => trim($d['description'] ?? ''),
        ':status'   => $d['status']   ?? 'open',
        ':prio'     => $d['priority'] ?? 'normal',
        ':owner'    => $d['owner_id'] ?: null,
        ':deadline' => $ciagla ? null : (($d['deadline'] ?? '') ?: null),
        ':ciagla'   => $ciagla,
        ':uid'      => $user_id,
        ':rt'       => $d['ref_type'] ?: null,
        ':ri'       => $d['ref_id']   ?: null,
    ]);
    $id = (int)db()->lastInsertId();
    ezd_log(null, $id, null, null, $user_id, 'sprawa_create',
            ($parent_id ? 'Otwarto podsprawę ' : 'Otwarto sprawę ') . "$znak: {$d['title']}");
    return $id;
}

/** Sprawy powiązane z rekordem innego modułu (np. zgłoszeniem helpdesku). */
function ezd_sprawy_by_ref(string $ref_type, int $ref_id): array {
    return db_all(
        "SELECT s.*, t.symbol AS teczka_symbol FROM ezd_sprawy s
         JOIN ezd_teczki t ON t.id = s.teczka_id
         WHERE s.ref_type=? AND s.ref_id=? ORDER BY s.id DESC",
        [$ref_type, $ref_id]
    );
}

function ezd_sprawa_update(int $id, array $d, int $user_id): void {
    $sprawa = ezd_sprawa_get($id);
    if (!$sprawa) return;

    // Blokada zamkniętej sprawy dla nie-adminów
    if ($sprawa['status'] === 'closed' && !is_admin()) {
        throw new \RuntimeException('Koszulka jest zamknięta. Skontaktuj się z administratorem.');
    }

    $ciagla   = array_key_exists('ciagla', $d) ? (!empty($d['ciagla']) ? 1 : 0) : (int)($sprawa['ciagla'] ?? 0);
    $new_stat = $d['status'] ?? $sprawa['status'];
    // Sprawa ciągła nie może być zamknięta przez zwykły zapis — pozostaje otwarta
    if ($ciagla && $new_stat === 'closed') $new_stat = 'open';
    $closing = $new_stat === 'closed' && $sprawa['status'] !== 'closed';

    db()->prepare(
        "UPDATE ezd_sprawy SET teczka_id=:tid,title=:title,description=:desc,
         status=:status,priority=:prio,owner_id=:owner,deadline=:deadline,ciagla=:ciagla,
         updated_at=datetime('now') WHERE id=:id"
    )->execute([
        ':tid'      => (int)($d['teczka_id'] ?? $sprawa['teczka_id']),
        ':title'    => trim($d['title']),
        ':desc'     => trim($d['description'] ?? ''),
        ':status'   => $new_stat,
        ':prio'     => $d['priority'] ?? $sprawa['priority'],
        ':owner'    => $d['owner_id'] ?: null,
        ':deadline' => $ciagla ? null : (($d['deadline'] ?? '') ?: null),
        ':ciagla'   => $ciagla,
        ':id'       => $id,
    ]);
    if ($closing) {
        db()->prepare("UPDATE ezd_sprawy SET closed_at=datetime('now') WHERE id=?")->execute([$id]);
        // Zamknij otwarte dekretacje
        db()->prepare("UPDATE ezd_dekretacje SET status='zakonczone',completed_at=datetime('now') WHERE sprawa_id=? AND status='oczekuje'")->execute([$id]);
    }
    ezd_log(null, $id, null, null, $user_id, 'sprawa_update', 'Edytowano sprawę #' . $id);
}

const EZD_SPRAWA_UPRAWNIENIA = [
    'odczyt' => 'Odczyt',
    'edycja' => 'Odczyt i edycja',
];

/** Lista osób, z którymi współdzielona jest sprawa (poza właścicielem/rolą). */
function ezd_sprawa_share_list(int $sprawa_id): array {
    return db_all(
        "SELECT su.*, u.name AS user_name, b.name AS added_by_name
         FROM ezd_sprawa_users su
         JOIN users u ON u.id = su.user_id
         LEFT JOIN users b ON b.id = su.added_by
         WHERE su.sprawa_id=? ORDER BY u.name", [$sprawa_id]
    );
}

/** Uprawnienie danego użytkownika ze współdzielenia (bez uwzględnienia roli/właściciela) lub null. */
function ezd_sprawa_share_get(int $sprawa_id, int $user_id): ?string {
    $r = db_one("SELECT uprawnienie FROM ezd_sprawa_users WHERE sprawa_id=? AND user_id=?", [$sprawa_id, $user_id]);
    return $r['uprawnienie'] ?? null;
}

function ezd_sprawa_share_add(int $sprawa_id, int $user_id, string $uprawnienie, int $by_user_id): void {
    if (!array_key_exists($uprawnienie, EZD_SPRAWA_UPRAWNIENIA)) $uprawnienie = 'odczyt';
    db()->prepare(
        "INSERT OR REPLACE INTO ezd_sprawa_users (sprawa_id,user_id,uprawnienie,added_by,added_at)
         VALUES (?,?,?,?,datetime('now'))"
    )->execute([$sprawa_id, $user_id, $uprawnienie, $by_user_id]);
    $u = db_one("SELECT name FROM users WHERE id=?", [$user_id]);
    ezd_log(null, $sprawa_id, null, null, $by_user_id, 'sprawa_share_add',
            'Udostępniono sprawę: ' . ($u['name'] ?? $user_id) . ' (' . EZD_SPRAWA_UPRAWNIENIA[$uprawnienie] . ')');
}

function ezd_sprawa_share_remove(int $sprawa_id, int $user_id, int $by_user_id): void {
    db()->prepare("DELETE FROM ezd_sprawa_users WHERE sprawa_id=? AND user_id=?")->execute([$sprawa_id, $user_id]);
    $u = db_one("SELECT name FROM users WHERE id=?", [$user_id]);
    ezd_log(null, $sprawa_id, null, null, $by_user_id, 'sprawa_share_del',
            'Odebrano współdzielenie sprawy: ' . ($u['name'] ?? $user_id));
}

/**
 * Efektywny dostęp danego użytkownika do sprawy: 'write' | 'read' | null (brak dostępu).
 * Kolejność: admin/rola z zapisem do EZD i właściciel/twórca → write; jawne współdzielenie →
 * wg uprawnienia; rola z odczytem do EZD → read; w przeciwnym razie brak dostępu.
 */
function ezd_sprawa_access(array $sprawa, int $user_id): ?string {
    if (is_admin() || can_write('ezd')) return 'write';
    if ((int)($sprawa['owner_id'] ?? 0) === $user_id || (int)($sprawa['created_by'] ?? 0) === $user_id) return 'write';
    $share = ezd_sprawa_share_get((int)$sprawa['id'], $user_id);
    if ($share === 'edycja') return 'write';
    if ($share === 'odczyt') return 'read';
    if (can_read('ezd')) return 'read';
    return null;
}

/** Może zarządzać listą współdzielenia (nie mylić z dostępem do treści sprawy). */
function ezd_sprawa_can_manage_share(array $sprawa, int $user_id): bool {
    return is_admin() || can_write('ezd')
        || (int)($sprawa['owner_id'] ?? 0) === $user_id
        || (int)($sprawa['created_by'] ?? 0) === $user_id;
}

function _ezd_next_numer(int $teczka_id, int $rok): int {
    $r = db_one(
        "SELECT MAX(numer) AS m FROM ezd_sprawy WHERE teczka_id=? AND numer IS NOT NULL",
        [$teczka_id]
    );
    return ($r['m'] ?? 0) + 1;
}

/**
 * Faktyczne przerejestrowanie koszulki: przenosi sprawę do WSKAZANEGO miejsca
 * i generuje NOWY znak sprawy wg systemowego schematu SYMBOL.numer.rok.
 * Zapisuje audyt (ezd_log). Operacja zmienia unikalny znak — nieodwracalna.
 *
 * Cel wskazuje wywołujący ($target):
 *   ['teczka_id'=>N]  → przenieś do istniejącego (otwartego) segregatora N,
 *   ['jrwa'=>'142']   → do segregatora tej klasy JRWA dla roku (utwórz, jeśli brak).
 * (Dla zgodności wstecznej dopuszczony też string z symbolem JRWA.)
 *
 * Blokuje sprawy z hierarchią (podsprawy / podkoszulki), bo znak i teczka są
 * współdzielone z rodzicem — takie przypadki wymagają ręcznej decyzji.
 *
 * @param array{teczka_id?:int, jrwa?:string}|string $target
 * @return array{ok:bool, error?:string, old_znak?:string, new_znak?:string, teczka_id?:int, created_teczka?:bool}
 */
function ezd_sprawa_reregister(int $sprawa_id, $target, int $user_id): array {
    $sprawa = ezd_sprawa_get($sprawa_id);
    if (!$sprawa) return ['ok' => false, 'error' => 'Koszulka nie istnieje.'];

    // Hierarchia — nie ruszamy automatycznie (znak/teczka współdzielone z rodzicem)
    if (!empty($sprawa['parent_id'])) {
        return ['ok' => false, 'error' => 'To podkoszulka — przerejestruj koszulkę nadrzędną (znak dziedziczy segregator).'];
    }
    if (db_one("SELECT 1 FROM ezd_sprawy WHERE parent_id=? LIMIT 1", [$sprawa_id])) {
        return ['ok' => false, 'error' => 'Koszulka ma podkoszulki — przenieś je ręcznie; automatyczne przerejestrowanie zablokowane.'];
    }

    if (is_string($target)) $target = ['jrwa' => $target];
    $rok = (int)date('Y');
    $created_teczka = false;

    if (!empty($target['teczka_id'])) {
        // Cel wskazany wprost: istniejący segregator
        $teczka = ezd_teczka_get((int)$target['teczka_id']);
        if (!$teczka)                       return ['ok' => false, 'error' => 'Wskazany segregator nie istnieje.'];
        if (($teczka['status'] ?? '') === 'closed') return ['ok' => false, 'error' => 'Wskazany segregator jest zamknięty.'];
    } else {
        // Cel przez klasę JRWA: istniejący otwarty segregator dla roku, albo nowy
        $kod  = trim((string)($target['jrwa'] ?? ''));
        $jrwa = $kod !== '' ? db_one("SELECT * FROM ezd_jrwa WHERE symbol=?", [$kod]) : null;
        if (!$jrwa) {
            return ['ok' => false, 'error' => "Klasa JRWA „{$kod}” nie istnieje w wykazie — wybierz istniejący segregator albo dodaj klasę w administracji JRWA."];
        }
        $teczka = db_one(
            "SELECT * FROM ezd_teczki WHERE jrwa_id=? AND rok=? AND status='open' ORDER BY id LIMIT 1",
            [(int)$jrwa['id'], $rok]
        );
        if (!$teczka) {
            $tid = ezd_teczka_create([
                'jrwa_id'  => (int)$jrwa['id'],
                'symbol'   => $jrwa['symbol'],
                'title'    => $jrwa['title'] . ' ' . $rok,
                'rok'      => $rok,
                'owner_id' => $sprawa['owner_id'] ?: null,
            ], $user_id);
            $teczka = ezd_teczka_get($tid);
            $created_teczka = true;
        }
    }

    if ((int)$teczka['id'] === (int)$sprawa['teczka_id']) {
        return ['ok' => false, 'error' => 'Koszulka jest już w tym segregatorze — brak zmian.'];
    }

    $rok = (int)($teczka['rok'] ?? $rok); // numeruj w roczniku docelowego segregatora
    $kod = $teczka['symbol'];

    // Nowy znak wg schematu systemowego; zabezpieczenie unikalności (UNIQUE na znak_sprawy)
    $old_znak = (string)$sprawa['znak_sprawy'];
    $numer    = _ezd_next_numer((int)$teczka['id'], $rok);
    $sym      = strtoupper($teczka['symbol']);
    do {
        $new_znak = $sym . '.' . $numer . '.' . $rok;
        $clash = db_one("SELECT 1 FROM ezd_sprawy WHERE znak_sprawy=?", [$new_znak]);
        if ($clash) $numer++;
    } while ($clash);

    db()->prepare(
        "UPDATE ezd_sprawy SET teczka_id=?, numer=?, znak_sprawy=?, updated_at=datetime('now') WHERE id=?"
    )->execute([(int)$teczka['id'], $numer, $new_znak, $sprawa_id]);

    ezd_log((int)$teczka['id'], $sprawa_id, null, null, $user_id, 'przerejestrowanie',
        'Przerejestrowano: ' . $old_znak . ' → ' . $new_znak . ' (JRWA ' . $kod . ')');

    return [
        'ok' => true, 'old_znak' => $old_znak, 'new_znak' => $new_znak,
        'teczka_id' => (int)$teczka['id'], 'created_teczka' => $created_teczka,
    ];
}

// ── Workflow BPM — etapy obiegu sprawy (konfigurowalne per JRWA) ─────────────

/** Domyślna ścieżka (gdy JRWA nie ma własnego workflow) — z EZD_ETAPY. */
function ezd_workflow_default_steps(): array {
    $steps = [];
    foreach (EZD_ETAPY as $k => $m) {
        $steps[] = ['key' => $k, 'label' => $m['label'], 'class' => $m['class'], 'icon' => $m['icon'], 'dyspozycja' => $m['dyspozycja'] ?? '', 'sla_days' => 0];
    }
    return $steps;
}

function ezd_workflow_get(?int $jrwa_id): ?array {
    if (!$jrwa_id) return null;
    return db_one("SELECT * FROM ezd_workflows WHERE jrwa_id=?", [$jrwa_id]);
}

/** Zwraca kroki workflow dla JRWA (własne lub domyślne). */
function ezd_workflow_steps(?int $jrwa_id): array {
    $w = ezd_workflow_get($jrwa_id);
    if ($w && !empty($w['steps'])) {
        $arr = json_decode($w['steps'], true);
        if (is_array($arr) && $arr) return ezd_workflow_normalize($arr);
    }
    return ezd_workflow_default_steps();
}

/** Normalizuje kroki: zapewnia key/label/class/icon/dyspozycja/sla_days. */
function ezd_workflow_normalize(array $steps): array {
    $out = []; $i = 0;
    foreach ($steps as $s) {
        $label = trim((string)($s['label'] ?? ''));
        if ($label === '') continue;
        $key = trim((string)($s['key'] ?? ''));
        if ($key === '') $key = 'k' . (++$i) . '_' . preg_replace('/[^a-z0-9]+/', '', strtolower(_ezd_ascii($label)));
        $out[] = [
            'key'        => $key,
            'label'      => $label,
            'class'      => in_array($s['class'] ?? '', ['secondary','primary','info','warning','success','danger','dark'], true) ? $s['class'] : 'secondary',
            'icon'       => trim((string)($s['icon'] ?? '')) ?: 'bi-record-circle',
            'dyspozycja' => array_key_exists($s['dyspozycja'] ?? '', EZD_DYSPOZYCJE) ? $s['dyspozycja'] : '',
            'sla_days'   => max(0, (int)($s['sla_days'] ?? 0)),
        ];
    }
    return $out;
}

function _ezd_ascii(string $s): string {
    $from = ['ą','ć','ę','ł','ń','ó','ś','ź','ż','Ą','Ć','Ę','Ł','Ń','Ó','Ś','Ź','Ż'];
    $to   = ['a','c','e','l','n','o','s','z','z','a','c','e','l','n','o','s','z','z'];
    return str_replace($from, $to, $s);
}

/** Kroki workflow właściwe dla danej sprawy (po JRWA jej teczki). */
function ezd_sprawa_workflow(array $sprawa): array {
    $jrwa_id = isset($sprawa['jrwa_id']) ? (int)$sprawa['jrwa_id'] : 0;
    if (!$jrwa_id && !empty($sprawa['teczka_id'])) {
        $t = db_one("SELECT jrwa_id FROM ezd_teczki WHERE id=?", [(int)$sprawa['teczka_id']]);
        $jrwa_id = (int)($t['jrwa_id'] ?? 0);
    }
    return ezd_workflow_steps($jrwa_id ?: null);
}

function ezd_workflow_save(int $jrwa_id, string $name, array $steps, int $user_id): void {
    $steps = ezd_workflow_normalize($steps);
    if (!$steps) throw new \RuntimeException('Workflow musi mieć co najmniej jeden etap.');
    $json = json_encode(array_values($steps), JSON_UNESCAPED_UNICODE);
    $exists = db_one("SELECT id FROM ezd_workflows WHERE jrwa_id=?", [$jrwa_id]);
    if ($exists) {
        db()->prepare("UPDATE ezd_workflows SET name=?, steps=?, updated_by=?, updated_at=datetime('now') WHERE jrwa_id=?")
            ->execute([$name, $json, $user_id, $jrwa_id]);
    } else {
        db()->prepare("INSERT INTO ezd_workflows (jrwa_id,name,steps,updated_by) VALUES (?,?,?,?)")
            ->execute([$jrwa_id, $name, $json, $user_id]);
    }
    ezd_log(null, null, null, null, $user_id, 'workflow_save', 'Zapisano workflow JRWA #' . $jrwa_id);
}

function ezd_workflow_delete(int $jrwa_id, int $user_id): void {
    db()->prepare("DELETE FROM ezd_workflows WHERE jrwa_id=?")->execute([$jrwa_id]);
    ezd_log(null, null, null, null, $user_id, 'workflow_delete', 'Przywrócono domyślny workflow JRWA #' . $jrwa_id);
}

/** Globalna mapa key→meta (domyślne + wszystkie własne) dla etykiet w listach. */
function ezd_etap_label_map(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (ezd_workflow_default_steps() as $s) $map[$s['key']] = $s;
    try {
        foreach (db_all("SELECT steps FROM ezd_workflows") as $w) {
            $arr = json_decode($w['steps'], true);
            if (is_array($arr)) foreach ($arr as $s) { if (!empty($s['key'])) $map[$s['key']] = $s; }
        }
    } catch (\Throwable $e) {}
    return $map;
}

function ezd_etap_meta(string $etap): array {
    $map = ezd_etap_label_map();
    return $map[$etap] ?? ['label' => $etap, 'icon' => 'bi-record-circle', 'class' => 'secondary'];
}

function ezd_etap_badge(?string $etap): string {
    $etap = $etap ?: 'wszczeta';
    $m = ezd_etap_meta($etap);
    return '<span class="badge bg-' . $m['class'] . ' bg-opacity-15 text-' . $m['class'] . ' border border-' . $m['class'] . '" style="font-size:.65rem"><i class="bi ' . ($m['icon'] ?? 'bi-record-circle') . ' me-1"></i>' . h($m['label']) . '</span>';
}

/**
 * Ustawia etap obiegu sprawy wg workflow właściwego dla jej JRWA (walidacja + log).
 * Ostatni krok zamyka sprawę (o ile nie ciągła); cofnięcie z ostatniego reotwiera;
 * ruszenie z pierwszego kroku → status w toku.
 */
function ezd_sprawa_set_etap(int $id, string $etap, int $user_id): void {
    $s = ezd_sprawa_get($id);
    if (!$s) return;
    if ($s['status'] === 'closed' && !is_admin()) throw new \RuntimeException('Koszulka jest zamknięta.');

    $steps = ezd_sprawa_workflow($s);
    $keys  = array_column($steps, 'key');
    if (!in_array($etap, $keys, true)) throw new \RuntimeException('Nieznany etap w obiegu tej sprawy.');

    $first = $keys[0] ?? null;
    $last  = end($keys) ?: null;
    $from  = $s['etap'] ?: $first;
    if ($from === $etap) return;

    $labels = ezd_etap_label_map();
    $from_lbl = $labels[$from]['label'] ?? $from;
    $etap_lbl = $labels[$etap]['label'] ?? $etap;

    db()->prepare("UPDATE ezd_sprawy SET etap=?, updated_at=datetime('now') WHERE id=?")->execute([$etap, $id]);

    if ($etap === $last && empty($s['ciagla']) && $s['status'] !== 'closed') {
        // Ostatni krok → zamknięcie sprawy
        db()->prepare("UPDATE ezd_sprawy SET status='closed', closed_at=datetime('now') WHERE id=?")->execute([$id]);
        db()->prepare("UPDATE ezd_dekretacje SET status='zakonczone',completed_at=datetime('now') WHERE sprawa_id=? AND status='oczekuje'")->execute([$id]);
    } elseif ($s['status'] === 'closed' && $etap !== $last) {
        // Cofnięcie z zamknięcia → ponowne otwarcie
        db()->prepare("UPDATE ezd_sprawy SET status='in_progress', closed_at=NULL WHERE id=?")->execute([$id]);
    } elseif ($s['status'] === 'open' && $etap !== $first) {
        // Ruszenie obiegu poza krok startowy → w toku
        db()->prepare("UPDATE ezd_sprawy SET status='in_progress' WHERE id=?")->execute([$id]);
    }

    ezd_log(null, $id, null, null, $user_id, 'etap_change', 'Etap obiegu: ' . $from_lbl . ' → ' . $etap_lbl);
}

/** Generuje BPMN 2.0 XML z liniowej ścieżki kroków (start → zadania → koniec). */
function ezd_workflow_to_bpmn(array $steps, string $name): string {
    $steps = ezd_workflow_normalize($steps);
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $pid = 'Process_jrwa';
    $flowEls = []; $shapes = []; $edges = [];
    $x = 160; $y = 120; $gap = 150; $taskW = 110; $taskH = 70;

    // Start event
    $nodes = [];
    $nodes[] = ['id' => 'StartEvent_1', 'type' => 'start', 'name' => 'Start', 'w' => 36, 'h' => 36];
    foreach ($steps as $i => $st) $nodes[] = ['id' => 'Task_' . $i, 'type' => 'task', 'name' => $st['label'], 'w' => $taskW, 'h' => $taskH];
    $nodes[] = ['id' => 'EndEvent_1', 'type' => 'end', 'name' => 'Koniec', 'w' => 36, 'h' => 36];

    $defs = '';
    foreach ($nodes as $n) {
        if ($n['type'] === 'start') $defs .= '    <bpmn:startEvent id="' . $n['id'] . '" name="' . $esc($n['name']) . '" />' . "\n";
        elseif ($n['type'] === 'end') $defs .= '    <bpmn:endEvent id="' . $n['id'] . '" name="' . $esc($n['name']) . '" />' . "\n";
        else $defs .= '    <bpmn:task id="' . $n['id'] . '" name="' . $esc($n['name']) . '" />' . "\n";
    }
    // Sequence flows
    $flows = '';
    for ($i = 0; $i < count($nodes) - 1; $i++) {
        $fid = 'Flow_' . $i;
        $flows .= '    <bpmn:sequenceFlow id="' . $fid . '" sourceRef="' . $nodes[$i]['id'] . '" targetRef="' . $nodes[$i + 1]['id'] . '" />' . "\n";
    }

    // Diagram (DI)
    $di = '';
    $cx = $x;
    $pos = [];
    foreach ($nodes as $n) {
        $cy = $y + (($taskH - $n['h']) / 2);
        $pos[$n['id']] = ['x' => $cx, 'y' => $cy, 'w' => $n['w'], 'h' => $n['h'], 'cx' => $cx + $n['w'] / 2, 'cy' => $y + $taskH / 2];
        $di .= '      <bpmndi:BPMNShape id="' . $n['id'] . '_di" bpmnElement="' . $n['id'] . '">' . "\n"
             . '        <dc:Bounds x="' . (int)$cx . '" y="' . (int)$cy . '" width="' . $n['w'] . '" height="' . $n['h'] . '" />' . "\n"
             . '      </bpmndi:BPMNShape>' . "\n";
        $cx += $n['w'] + $gap;
    }
    for ($i = 0; $i < count($nodes) - 1; $i++) {
        $a = $pos[$nodes[$i]['id']]; $b = $pos[$nodes[$i + 1]['id']];
        $di .= '      <bpmndi:BPMNEdge id="Flow_' . $i . '_di" bpmnElement="Flow_' . $i . '">' . "\n"
             . '        <di:waypoint x="' . (int)($a['x'] + $a['w']) . '" y="' . (int)$a['cy'] . '" />' . "\n"
             . '        <di:waypoint x="' . (int)$b['x'] . '" y="' . (int)$b['cy'] . '" />' . "\n"
             . '      </bpmndi:BPMNEdge>' . "\n";
    }

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" '
        . 'xmlns:bpmndi="http://www.omg.org/spec/BPMN/20100524/DI" '
        . 'xmlns:dc="http://www.omg.org/spec/DD/20100524/DC" '
        . 'xmlns:di="http://www.omg.org/spec/DD/20100524/DI" '
        . 'id="Definitions_ezd" targetNamespace="http://feer.org.pl/ezd">' . "\n"
        . '  <bpmn:process id="' . $pid . '" name="' . $esc($name) . '" isExecutable="false">' . "\n"
        . $defs . $flows
        . '  </bpmn:process>' . "\n"
        . '  <bpmndi:BPMNDiagram id="Diagram_1">' . "\n"
        . '    <bpmndi:BPMNPlane id="Plane_1" bpmnElement="' . $pid . '">' . "\n"
        . $di
        . '    </bpmndi:BPMNPlane>' . "\n"
        . '  </bpmndi:BPMNDiagram>' . "\n"
        . '</bpmn:definitions>' . "\n";
}

// ── Pisma ────────────────────────────────────────────────────────────────────

function ezd_pismo_get(int $id): ?array {
    return db_one(
        "SELECT p.*, s.znak_sprawy, s.title AS sprawa_title, s.status AS sprawa_status,
                u.name AS owner_name, c.name AS creator_name
         FROM ezd_pisma p
         JOIN ezd_sprawy s ON s.id = p.sprawa_id
         LEFT JOIN users u ON u.id = p.owner_id
         LEFT JOIN users c ON c.id = p.created_by
         WHERE p.id=?", [$id]
    );
}

function ezd_pisma_by_sprawa(int $sprawa_id): array {
    return db_all(
        "SELECT p.*, u.name AS owner_name FROM ezd_pisma p
         LEFT JOIN users u ON u.id=p.owner_id
         WHERE p.sprawa_id=? ORDER BY p.created_at", [$sprawa_id]
    );
}

/** Formatuje wiersz pisma (ezd_pismo_get/ezd_pisma_by_sprawa) do publicznej odpowiedzi REST API. */
function ezd_api_pismo(array $r): array {
    return [
        'id'                  => (int)$r['id'],
        'sprawa_id'           => (int)$r['sprawa_id'],
        'znak_sprawy'         => $r['znak_sprawy'] ?? null,
        'sygnatura'           => $r['sygnatura'],
        'kierunek'            => $r['kierunek'],
        'kierunek_label'      => EZD_KIERUNKI[$r['kierunek']]['label'] ?? $r['kierunek'],
        'title'               => $r['title'],
        'tresc'               => $r['tresc'],
        'nadawca'             => $r['nadawca'],
        'odbiorca'            => $r['odbiorca'],
        'data_pisma'          => $r['data_pisma'],
        'data_wplywu'         => $r['data_wplywu'],
        'data_wysylki'        => $r['data_wysylki'],
        'status'              => $r['status'],
        'rodzaj_medium'       => $r['rodzaj_medium'] ?? null,
        'rodzaj_medium_label' => EZD_MEDIA[$r['rodzaj_medium'] ?? '']['label'] ?? null,
        'owner_id'            => $r['owner_id'] !== null ? (int)$r['owner_id'] : null,
        'owner_name'          => $r['owner_name'] ?? null,
        'created_by'          => $r['created_by'] !== null ? (int)$r['created_by'] : null,
        'created_at'          => $r['created_at'],
        'updated_at'          => $r['updated_at'],
    ];
}

function ezd_pismo_create(array $d, int $user_id): int {
    $sprawa   = ezd_sprawa_get((int)$d['sprawa_id']);
    if (!$sprawa) throw new \RuntimeException('Koszulka nie istnieje.');
    _ezd_check_sprawa_open($sprawa);

    $sygnatura = _ezd_next_sygnatura_pisma((int)$d['sprawa_id'], $sprawa['znak_sprawy']);
    $medium    = array_key_exists($d['rodzaj_medium'] ?? '', EZD_MEDIA) ? $d['rodzaj_medium'] : 'papier';
    db()->prepare(
        "INSERT INTO ezd_pisma (sprawa_id,sygnatura,kierunek,title,tresc,nadawca,odbiorca,
         data_pisma,data_wplywu,data_wysylki,status,owner_id,rodzaj_medium,created_by,updated_at)
         VALUES (:sid,:sygn,:kier,:title,:tresc,:nad,:odb,:dp,:dw,:dy,:status,:owner,:medium,:uid,datetime('now'))"
    )->execute([
        ':sid'    => (int)$d['sprawa_id'],
        ':sygn'   => $sygnatura,
        ':kier'   => $d['kierunek'] ?? 'przychodzace',
        ':title'  => trim($d['title']),
        ':tresc'  => $d['tresc']    ?? '',
        ':nad'    => $d['nadawca']  ?? '',
        ':odb'    => $d['odbiorca'] ?? '',
        ':dp'     => ($d['data_pisma']   ?? '') ?: null,
        ':dw'     => ($d['data_wplywu']  ?? '') ?: null,
        ':dy'     => ($d['data_wysylki'] ?? '') ?: null,
        ':status' => $d['status']   ?? 'nowe',
        ':owner'  => ($d['owner_id'] ?? '') ?: null,
        ':medium' => $medium,
        ':uid'    => $user_id,
    ]);
    $id = (int)db()->lastInsertId();
    db()->prepare("UPDATE ezd_sprawy SET updated_at=datetime('now') WHERE id=?")->execute([$d['sprawa_id']]);
    ezd_log(null, (int)$d['sprawa_id'], $id, null, $user_id, 'pismo_create', "Dodano pismo $sygnatura");

    // Auto-rejestracja wychodzących jako korespondencja wychodząca
    if (($d['kierunek'] ?? '') === 'wychodzace' && function_exists('module_enabled') && module_enabled('correspondence_enabled')) {
        if (!function_exists('corr_create') && is_file(__DIR__ . '/correspondence.php')) {
            require_once __DIR__ . '/correspondence.php';
        }
        if (function_exists('corr_create')) {
            $corr_id = corr_create([
                'direction'    => 'outgoing',
                'number'       => $sygnatura,
                'subject'      => trim($d['title']),
                'correspondent'=> $d['odbiorca'] ?? '',
                'date'         => ($d['data_wysylki'] ?? '') ?: ($d['data_pisma'] ?? '') ?: date('Y-m-d'),
                'status'       => 'new',
                'medium'       => in_array($medium, ['email','epuap','faks','inne'], true) ? $medium : 'papier',
                'ezd_pismo_id' => $id,
            ], $user_id);
            db()->prepare("UPDATE correspondence SET ezd_pismo_id=? WHERE id=?")->execute([$id, $corr_id]);
            ezd_log(null, (int)$d['sprawa_id'], $id, null, $user_id, 'corr_auto_linked',
                'Auto-rejestracja w korespondencji #' . $corr_id);
        }
    }

    return $id;
}

function ezd_pismo_update(int $id, array $d, int $user_id): void {
    $p = ezd_pismo_get($id);
    if (!$p) return;
    _ezd_check_sprawa_open(['status' => $p['sprawa_status']]);
    $medium = array_key_exists($d['rodzaj_medium'] ?? '', EZD_MEDIA) ? $d['rodzaj_medium'] : $p['rodzaj_medium'];
    db()->prepare(
        "UPDATE ezd_pisma SET kierunek=:k,title=:t,tresc=:tr,nadawca=:n,odbiorca=:o,
         data_pisma=:dp,data_wplywu=:dw,data_wysylki=:dy,status=:s,owner_id=:ow,rodzaj_medium=:med,
         updated_at=datetime('now') WHERE id=:id"
    )->execute([
        ':k' => $d['kierunek'] ?? $p['kierunek'], ':t'  => trim($d['title']),
        ':tr'=> $d['tresc']    ?? '',              ':n'  => $d['nadawca']  ?? '',
        ':o' => $d['odbiorca'] ?? '',              ':dp' => $d['data_pisma']   ?: null,
        ':dw'=> $d['data_wplywu'] ?: null,         ':dy' => $d['data_wysylki'] ?: null,
        ':s' => $d['status']   ?? $p['status'],   ':ow' => $d['owner_id'] ?: null,
        ':med' => $medium,
        ':id'=> $id,
    ]);
    ezd_log(null, (int)$p['sprawa_id'], $id, null, $user_id, 'pismo_update', 'Edytowano pismo #' . $id);
}

function _ezd_next_sygnatura_pisma(int $sprawa_id, string $znak): string {
    $c = db_one("SELECT COUNT(*) AS c FROM ezd_pisma WHERE sprawa_id=?", [$sprawa_id])['c'] ?? 0;
    return $znak . '.P' . ($c + 1);
}

// ── Umowy EZD ────────────────────────────────────────────────────────────────

function ezd_umowa_get(int $id): ?array {
    return db_one(
        "SELECT u.*, s.znak_sprawy, s.title AS sprawa_title, s.status AS sprawa_status,
                ow.name AS owner_name, c.name AS creator_name
         FROM ezd_umowy u
         JOIN ezd_sprawy s ON s.id = u.sprawa_id
         LEFT JOIN users ow ON ow.id = u.owner_id
         LEFT JOIN users c  ON c.id  = u.created_by
         WHERE u.id=?", [$id]
    );
}

function ezd_umowy_by_sprawa(int $sprawa_id): array {
    return db_all(
        "SELECT u.*, ow.name AS owner_name FROM ezd_umowy u
         LEFT JOIN users ow ON ow.id=u.owner_id
         WHERE u.sprawa_id=? ORDER BY u.created_at", [$sprawa_id]
    );
}

function ezd_umowa_create(array $d, int $user_id): int {
    $sprawa = ezd_sprawa_get((int)$d['sprawa_id']);
    if (!$sprawa) throw new \RuntimeException('Koszulka nie istnieje.');
    _ezd_check_sprawa_open($sprawa);

    $sygnatura = _ezd_next_sygnatura_umowy((int)$d['sprawa_id'], $sprawa['znak_sprawy']);
    db()->prepare(
        "INSERT INTO ezd_umowy (sprawa_id,sygnatura,title,typ,strona,wartosc,waluta,
         data_zawarcia,data_od,data_do,warunki_platnosci,status,owner_id,created_by,updated_at,ref_type,ref_id)
         VALUES (:sid,:sygn,:title,:typ,:str,:war,:wal,:dz,:do_,:dd,:wp,:status,:own,:uid,datetime('now'),:rt,:ri)"
    )->execute([
        ':sid'   => (int)$d['sprawa_id'],   ':sygn' => $sygnatura,
        ':title' => trim($d['title']),       ':typ'  => $d['typ']    ?? 'umowa',
        ':str'   => $d['strona']   ?? '',    ':war'  => $d['wartosc'] !== '' ? (float)$d['wartosc'] : null,
        ':wal'   => $d['waluta']   ?? 'PLN', ':dz'   => $d['data_zawarcia'] ?: null,
        ':do_'   => $d['data_od']  ?: null,  ':dd'   => $d['data_do']       ?: null,
        ':wp'    => $d['warunki_platnosci'] ?? '',
        ':status'=> $d['status']   ?? 'projekt',
        ':own'   => $d['owner_id'] ?: null,  ':uid'  => $user_id,
        ':rt'    => $d['ref_type'] ?: null,  ':ri'   => $d['ref_id'] ?: null,
    ]);
    $id = (int)db()->lastInsertId();
    db()->prepare("UPDATE ezd_sprawy SET updated_at=datetime('now') WHERE id=?")->execute([$d['sprawa_id']]);
    ezd_log(null, (int)$d['sprawa_id'], null, $id, $user_id, 'umowa_create', "Dodano umowę $sygnatura");
    return $id;
}

function ezd_umowa_update(int $id, array $d, int $user_id): void {
    $u = ezd_umowa_get($id);
    if (!$u) return;
    if ($u['sprawa_status'] === 'closed' && !is_admin()) {
        throw new \RuntimeException('Koszulka jest zamknięta — edycja zablokowana.');
    }
    db()->prepare(
        "UPDATE ezd_umowy SET title=:t,typ=:typ,strona=:str,wartosc=:war,waluta=:wal,
         data_zawarcia=:dz,data_od=:do_,data_do=:dd,warunki_platnosci=:wp,
         status=:status,owner_id=:own,updated_at=datetime('now') WHERE id=:id"
    )->execute([
        ':t'   => trim($d['title']),         ':typ' => $d['typ']    ?? $u['typ'],
        ':str' => $d['strona']   ?? '',      ':war' => $d['wartosc'] !== '' ? (float)$d['wartosc'] : null,
        ':wal' => $d['waluta']   ?? 'PLN',   ':dz'  => $d['data_zawarcia']  ?: null,
        ':do_' => $d['data_od']  ?: null,    ':dd'  => $d['data_do']         ?: null,
        ':wp'  => $d['warunki_platnosci'] ?? '',
        ':status' => $d['status'] ?? $u['status'],
        ':own' => $d['owner_id'] ?: null,   ':id'  => $id,
    ]);
    ezd_log(null, (int)$u['sprawa_id'], null, $id, $user_id, 'umowa_update', 'Edytowano umowę #' . $id);
}

function _ezd_next_sygnatura_umowy(int $sprawa_id, string $znak): string {
    $c = db_one("SELECT COUNT(*) AS c FROM ezd_umowy WHERE sprawa_id=?", [$sprawa_id])['c'] ?? 0;
    return $znak . '.U.' . ($c + 1);
}

// ── RPW / Dziennik podawczy / Koszulka ───────────────────────────────────────

const EZD_RPW_SUBDIR = 'ezd/rpw/';

function _ezd_next_rpw(int $rok): int {
    $r = db_one("SELECT MAX(rpw_nr) AS m FROM ezd_rpw WHERE rok=?", [$rok]);
    return (int)($r['m'] ?? 0) + 1;
}

function ezd_rpw_label(array $r): string {
    return 'RPW ' . $r['rpw_nr'] . '/' . $r['rok'];
}

/** Aktywne jednostki organizacyjne do wyboru w dzienniku podawczym.
 *  Defensywnie — pusta lista, gdy moduł „Struktura organizacyjna" nieobecny. */
function ezd_org_units(): array {
    try {
        return db_all("SELECT id, code, name, short_name FROM org_units WHERE status='active' ORDER BY sort_order, name");
    } catch (\Throwable $e) { return []; }
}

/** Etykieta jednostki: „KOD — Nazwa". */
function ezd_unit_label(array $u): string {
    $n = trim((string)($u['name'] ?? ''));
    return !empty($u['code']) ? trim($u['code'] . ' — ' . $n) : $n;
}

/** Mapa id => nazwa jednostki (defensywnie). */
function _ezd_unit_names(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    try {
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (db_all("SELECT id, name FROM org_units WHERE id IN ($in)", $ids) as $u) {
            $out[(int)$u['id']] = $u['name'];
        }
        return $out;
    } catch (\Throwable $e) { return []; }
}

function ezd_rpw_get(int $id): ?array {
    $r = db_one(
        "SELECT r.*, s.znak_sprawy, s.title AS sprawa_title,
                p.title AS pismo_title, p.sygnatura AS pismo_sygnatura,
                c.name AS creator_name
         FROM ezd_rpw r
         LEFT JOIN ezd_sprawy s ON s.id = r.sprawa_id
         LEFT JOIN ezd_pisma  p ON p.id = r.pismo_id
         LEFT JOIN users      c ON c.id = r.created_by
         WHERE r.id=?", [$id]
    );
    if ($r) {
        $nm = _ezd_unit_names([$r['przekazano_unit_id'] ?? 0]);
        $r['przekazano_unit_name'] = $nm[(int)($r['przekazano_unit_id'] ?? 0)] ?? '';
    }
    return $r;
}

function ezd_rpw_all(array $f = []): array {
    $where = ["1=1"]; $params = [];
    if (($f['rok'] ?? '') !== '')    { $where[] = "r.rok=?";    $params[] = (int)$f['rok']; }
    if (!empty($f['status']))        { $where[] = "r.status=?"; $params[] = $f['status']; }
    if (!empty($f['typ']))           { $where[] = "r.typ=?";    $params[] = $f['typ']; }
    if (!empty($f['q'])) {
        $where[] = "(r.opis LIKE ? OR r.nadawca LIKE ? OR r.znak_obcy LIKE ?)";
        $q = '%'.$f['q'].'%'; $params[] = $q; $params[] = $q; $params[] = $q;
    }
    $rows = db_all(
        "SELECT r.*, s.znak_sprawy
         FROM ezd_rpw r
         LEFT JOIN ezd_sprawy s ON s.id = r.sprawa_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY r.rok DESC, r.rpw_nr DESC
         LIMIT 500",
        $params
    );
    $names = _ezd_unit_names(array_column($rows, 'przekazano_unit_id'));
    foreach ($rows as &$r) {
        $r['przekazano_unit_name'] = $names[(int)($r['przekazano_unit_id'] ?? 0)] ?? '';
    }
    unset($r);
    return $rows;
}

/** @return array{id:int,rpw_nr:int,rok:int} */
function ezd_rpw_create(array $d, int $user_id): array {
    $data = $d['data_wplywu'] ?: date('Y-m-d');
    $rok  = (int)substr($data, 0, 4) ?: (int)date('Y');
    $nr   = _ezd_next_rpw($rok);
    db()->prepare(
        "INSERT INTO ezd_rpw (rpw_nr,rok,data_wplywu,typ,nadawca,znak_obcy,opis,uwagi,status,przekazano_unit_id,created_by)
         VALUES (:nr,:rok,:dw,:typ,:nad,:zo,:opis,:uw,:st,:pu,:uid)"
    )->execute([
        ':nr'  => $nr, ':rok' => $rok, ':dw' => $data,
        ':typ' => $d['typ'] ?? 'list',
        ':nad' => trim($d['nadawca'] ?? ''),
        ':zo'  => trim($d['znak_obcy'] ?? ''),
        ':opis'=> trim($d['opis'] ?? ''),
        ':uw'  => trim($d['uwagi'] ?? ''),
        ':st'  => !empty($d['przekazano_unit_id']) ? 'przekazana' : 'nowa',
        ':pu'  => ((int)($d['przekazano_unit_id'] ?? 0)) ?: null,
        ':uid' => $user_id,
    ]);
    $id = (int)db()->lastInsertId();
    ezd_log(null, null, null, null, $user_id, 'rpw_create', "Zarejestrowano RPW $nr/$rok: " . mb_substr($d['opis'] ?? '', 0, 60));
    return ['id' => $id, 'rpw_nr' => $nr, 'rok' => $rok];
}

function ezd_rpw_update(int $id, array $d, int $user_id): void {
    $r = ezd_rpw_get($id);
    if (!$r) return;
    db()->prepare(
        "UPDATE ezd_rpw SET data_wplywu=:dw,typ=:typ,nadawca=:nad,znak_obcy=:zo,
         opis=:opis,uwagi=:uw WHERE id=:id"
    )->execute([
        ':dw'  => $d['data_wplywu'] ?: $r['data_wplywu'],
        ':typ' => $d['typ'] ?? $r['typ'],
        ':nad' => trim($d['nadawca'] ?? ''),
        ':zo'  => trim($d['znak_obcy'] ?? ''),
        ':opis'=> trim($d['opis'] ?? ''),
        ':uw'  => trim($d['uwagi'] ?? ''),
        ':id'  => $id,
    ]);
    ezd_log(null, null, null, null, $user_id, 'rpw_update', 'Edytowano ' . ezd_rpw_label($r));
}

function ezd_rpw_przekaz(int $id, int $unit_id, int $user_id): void {
    $r = ezd_rpw_get($id);
    if (!$r || $r['status'] === 'w_sprawie') return;
    db()->prepare("UPDATE ezd_rpw SET przekazano_unit_id=?, status='przekazana' WHERE id=?")->execute([$unit_id ?: null, $id]);
    ezd_log(null, null, null, null, $user_id, 'rpw_przekaz', ezd_rpw_label($r) . ' → jednostka #' . $unit_id);
}

function ezd_rpw_odrzuc(int $id, int $user_id, string $powod = ''): void {
    $r = ezd_rpw_get($id);
    if (!$r || $r['status'] === 'w_sprawie') return;
    db()->prepare("UPDATE ezd_rpw SET status='odrzucona' WHERE id=?")->execute([$id]);
    ezd_log(null, null, null, null, $user_id, 'rpw_odrzuc', ezd_rpw_label($r) . ($powod ? ' — ' . $powod : ''));
}

function ezd_rpw_delete(int $id, int $user_id): void {
    $r = ezd_rpw_get($id);
    if (!$r) return;
    if ($r['status'] === 'w_sprawie') throw new \RuntimeException('Przesyłka jest powiązana ze sprawą — nie można jej usunąć.');
    if ($r['scan_file']) {
        $p = UPLOAD_DIR . EZD_RPW_SUBDIR . $id . '/' . $r['scan_file'];
        if (is_file($p)) @unlink($p);
    }
    db()->prepare("DELETE FROM ezd_rpw WHERE id=?")->execute([$id]);
    ezd_log(null, null, null, null, $user_id, 'rpw_delete', 'Usunięto ' . ezd_rpw_label($r));
}

function ezd_rpw_scan_upload(int $id, string $field, int $user_id): ?string {
    if (empty($_FILES[$field]['tmp_name'])) return 'Nie wybrano pliku.';
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return 'Błąd przesyłania (kod: ' . $f['error'] . ').';
    if ($f['size'] > EZD_MAX_SIZE)     return 'Plik za duży (maks. 25 MB).';
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EZD_ALLOWED_EXT, true)) return 'Niedozwolony format pliku.';

    $dir = UPLOAD_DIR . EZD_RPW_SUBDIR . $id . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $stored)) return 'Nie udało się zapisać pliku.';

    // usuń poprzedni skan jeśli był
    $r = ezd_rpw_get($id);
    if ($r && $r['scan_file']) { $old = $dir . $r['scan_file']; if (is_file($old)) @unlink($old); }

    db()->prepare("UPDATE ezd_rpw SET scan_file=?,scan_name=?,scan_mime=?,scan_size=? WHERE id=?")
        ->execute([$stored, $f['name'], $f['type'] ?: 'application/octet-stream', $f['size'], $id]);
    ezd_log(null, null, null, null, $user_id, 'rpw_scan', 'Dodano skan do ' . ezd_rpw_label($r ?? ['rpw_nr'=>'?','rok'=>'']));
    return null;
}

/**
 * Konwersja przesyłki z dziennika na pismo w sprawie (dekretacja do sprawy).
 * Jeśli podano teczka_id zamiast sprawa_id — zakłada nową sprawę.
 * Przenosi skan (jeśli jest) do repozytorium sprawy jako załącznik pisma.
 * @return int id utworzonego pisma
 */
function ezd_rpw_assign(int $id, array $d, int $user_id): int {
    $r = ezd_rpw_get($id);
    if (!$r) throw new \RuntimeException('Przesyłka nie istnieje.');
    if ($r['status'] === 'w_sprawie') throw new \RuntimeException('Przesyłka jest już powiązana ze sprawą.');

    $sprawa_id = (int)($d['sprawa_id'] ?? 0);
    // Wariant: nowa sprawa w teczce
    if (!$sprawa_id && !empty($d['teczka_id'])) {
        $sprawa_id = ezd_sprawa_create([
            'teczka_id'   => (int)$d['teczka_id'],
            'title'       => trim(($d['sprawa_title'] ?? '') ?: ($r['opis'] ?: 'Sprawa z ' . ezd_rpw_label($r))),
            'description' => 'Wszczęto na podstawie ' . ezd_rpw_label($r) . ($r['nadawca'] ? ' (nadawca: ' . $r['nadawca'] . ')' : ''),
            'owner_id'    => $r['przekazano_do'] ?: $user_id,
            'priority'    => 'normal',
            'deadline'    => null,
        ], $user_id);
    }
    if (!$sprawa_id) throw new \RuntimeException('Wskaż koszulkę lub segregator dla nowej koszulki.');

    $sprawa = ezd_sprawa_get($sprawa_id);
    if (!$sprawa) throw new \RuntimeException('Koszulka nie istnieje.');

    // Utwórz pismo przychodzące z danych przesyłki
    $pismo_id = ezd_pismo_create([
        'sprawa_id'   => $sprawa_id,
        'kierunek'    => 'przychodzace',
        'title'       => $r['opis'] ?: ('Przesyłka ' . ezd_rpw_label($r)),
        'tresc'       => $r['uwagi'] ?? '',
        'nadawca'     => $r['nadawca'] ?? '',
        'odbiorca'    => '',
        'data_pisma'  => null,
        'data_wplywu' => $r['data_wplywu'],
        'data_wysylki'=> null,
        'status'      => 'nowe',
        'owner_id'    => $r['przekazano_do'] ?: null,
    ], $user_id);

    // Przenieś skan do repozytorium sprawy jako załącznik pisma
    if ($r['scan_file']) {
        $src = UPLOAD_DIR . EZD_RPW_SUBDIR . $id . '/' . $r['scan_file'];
        if (is_file($src)) {
            $destDir = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $sprawa_id . '/';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $ext    = strtolower(pathinfo($r['scan_file'], PATHINFO_EXTENSION));
            $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (@rename($src, $destDir . $stored)) {
                db()->prepare(
                    "INSERT INTO ezd_zalaczniki (sprawa_id,pismo_id,filename,original_name,mime_type,file_size,uploaded_by)
                     VALUES (?,?,?,?,?,?,?)"
                )->execute([$sprawa_id, $pismo_id, $stored, $r['scan_name'] ?: $r['scan_file'], $r['scan_mime'] ?: 'application/octet-stream', (int)$r['scan_size'], $user_id]);
                try { ezd_sp_sync_attachment((int)db()->lastInsertId()); } catch (\Throwable $e) {}
            }
        }
    }

    db()->prepare("UPDATE ezd_rpw SET status='w_sprawie', sprawa_id=?, pismo_id=?, scan_file='' WHERE id=?")
        ->execute([$sprawa_id, $pismo_id, $id]);
    ezd_log(null, $sprawa_id, $pismo_id, null, $user_id, 'rpw_assign', ezd_rpw_label($r) . ' → sprawa ' . $sprawa['znak_sprawy']);
    return $pismo_id;
}

function ezd_rpw_stats(): array {
    $rok = (int)date('Y');
    return [
        'koszulka'   => db_one("SELECT COUNT(*) AS c FROM ezd_rpw WHERE status IN ('nowa','przekazana')")['c'] ?? 0,
        'nowa'       => db_one("SELECT COUNT(*) AS c FROM ezd_rpw WHERE status='nowa'")['c'] ?? 0,
        'rpw_rok'    => db_one("SELECT COUNT(*) AS c FROM ezd_rpw WHERE rok=?", [$rok])['c'] ?? 0,
        'rpw_dzis'   => db_one("SELECT COUNT(*) AS c FROM ezd_rpw WHERE data_wplywu=date('now')")['c'] ?? 0,
    ];
}

function ezd_rpw_status_badge(string $status): string {
    $s = EZD_RPW_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . ' bg-opacity-15 text-' . $s['class'] . ' border border-' . $s['class'] . '" style="font-size:.65rem">' . h($s['label']) . '</span>';
}

// ── Powiadomienia o terminach (dedup) ────────────────────────────────────────

/** Czy dla danego obiektu i rodzaju kamienia milowego przypomnienie już wysłano. */
function ezd_reminder_sent(string $ref_type, int $ref_id, string $kind): bool {
    return (bool) db_one(
        "SELECT 1 FROM ezd_reminder_log WHERE ref_type=? AND ref_id=? AND kind=?",
        [$ref_type, $ref_id, $kind]
    );
}

/** Zapisz fakt wysłania przypomnienia (idempotentnie). */
function ezd_reminder_mark(string $ref_type, int $ref_id, string $kind): void {
    try {
        db()->prepare("INSERT INTO ezd_reminder_log (ref_type,ref_id,kind) VALUES (?,?,?)")
            ->execute([$ref_type, $ref_id, $kind]);
    } catch (\Throwable $e) { /* UNIQUE — już zapisane */ }
}

// ── Notatki sprawy ────────────────────────────────────────────────────────────

function ezd_notatki_by_sprawa(int $sprawa_id): array {
    return db_all(
        "SELECT n.*, u.name AS author FROM ezd_notatki n
         LEFT JOIN users u ON u.id=n.created_by
         WHERE n.sprawa_id=? ORDER BY n.pinned DESC, n.created_at DESC", [$sprawa_id]
    );
}

function ezd_notatka_get(int $id): ?array {
    return db_one("SELECT * FROM ezd_notatki WHERE id=?", [$id]);
}

function ezd_notatka_create(int $sprawa_id, string $tresc, int $user_id): int {
    db()->prepare("INSERT INTO ezd_notatki (sprawa_id,tresc,created_by) VALUES (?,?,?)")
        ->execute([$sprawa_id, trim($tresc), $user_id]);
    $id = (int)db()->lastInsertId();
    db()->prepare("UPDATE ezd_sprawy SET updated_at=datetime('now') WHERE id=?")->execute([$sprawa_id]);
    ezd_log(null, $sprawa_id, null, null, $user_id, 'notatka_create', 'Dodano notatkę');
    return $id;
}

function ezd_notatka_update(int $id, string $tresc, int $user_id): void {
    $n = ezd_notatka_get($id);
    if (!$n) return;
    db()->prepare("UPDATE ezd_notatki SET tresc=?, updated_at=datetime('now') WHERE id=?")->execute([trim($tresc), $id]);
    ezd_log(null, (int)$n['sprawa_id'], null, null, $user_id, 'notatka_update', 'Edytowano notatkę #' . $id);
}

function ezd_notatka_delete(int $id, int $user_id): void {
    $n = ezd_notatka_get($id);
    if (!$n) return;
    db()->prepare("DELETE FROM ezd_notatki WHERE id=?")->execute([$id]);
    ezd_log(null, (int)$n['sprawa_id'], null, null, $user_id, 'notatka_delete', 'Usunięto notatkę #' . $id);
}

function ezd_notatka_toggle_pin(int $id, int $user_id): void {
    db()->prepare("UPDATE ezd_notatki SET pinned = CASE pinned WHEN 1 THEN 0 ELSE 1 END WHERE id=?")->execute([$id]);
}

// ── Dokumenty wewnętrzne sprawy ──────────────────────────────────────────────

function ezd_dokumenty_by_sprawa(int $sprawa_id): array {
    return db_all(
        "SELECT d.*, u.name AS owner_name,
                (SELECT COUNT(*) FROM ezd_zalaczniki z WHERE z.dokument_id=d.id) AS plik_count
         FROM ezd_dokumenty d LEFT JOIN users u ON u.id=d.owner_id
         WHERE d.sprawa_id=? ORDER BY d.created_at DESC", [$sprawa_id]
    );
}

function ezd_dokument_get(int $id): ?array {
    return db_one(
        "SELECT d.*, s.znak_sprawy, s.title AS sprawa_title, s.status AS sprawa_status,
                u.name AS owner_name, c.name AS creator_name
         FROM ezd_dokumenty d
         JOIN ezd_sprawy s ON s.id = d.sprawa_id
         LEFT JOIN users u ON u.id = d.owner_id
         LEFT JOIN users c ON c.id = d.created_by
         WHERE d.id=?", [$id]
    );
}

function _ezd_next_sygnatura_dok(int $sprawa_id, string $znak): string {
    $c = db_one("SELECT COUNT(*) AS c FROM ezd_dokumenty WHERE sprawa_id=?", [$sprawa_id])['c'] ?? 0;
    return $znak . '.D.' . ($c + 1);
}

function ezd_dokument_create(array $d, int $user_id): int {
    $sprawa = ezd_sprawa_get((int)$d['sprawa_id']);
    if (!$sprawa) throw new \RuntimeException('Koszulka nie istnieje.');
    _ezd_check_sprawa_open($sprawa);
    $syg = _ezd_next_sygnatura_dok((int)$d['sprawa_id'], $sprawa['znak_sprawy']);
    db()->prepare(
        "INSERT INTO ezd_dokumenty (sprawa_id,sygnatura,rodzaj,title,tresc,status,owner_id,created_by,updated_at)
         VALUES (:sid,:syg,:rodz,:title,:tresc,:status,:owner,:uid,datetime('now'))"
    )->execute([
        ':sid'   => (int)$d['sprawa_id'], ':syg'   => $syg,
        ':rodz'  => array_key_exists($d['rodzaj'] ?? '', EZD_DOK_RODZAJE) ? $d['rodzaj'] : 'notatka_sluzbowa',
        ':title' => trim($d['title']),   ':tresc' => $d['tresc'] ?? '',
        ':status'=> array_key_exists($d['status'] ?? '', EZD_DOK_STATUSY) ? $d['status'] : 'projekt',
        ':owner' => $d['owner_id'] ?: null, ':uid' => $user_id,
    ]);
    $id = (int)db()->lastInsertId();
    db()->prepare("UPDATE ezd_sprawy SET updated_at=datetime('now') WHERE id=?")->execute([$d['sprawa_id']]);
    ezd_log(null, (int)$d['sprawa_id'], null, null, $user_id, 'dokument_create', "Dodano dokument wewnętrzny $syg");
    return $id;
}

function ezd_dokument_update(int $id, array $d, int $user_id): void {
    $doc = ezd_dokument_get($id);
    if (!$doc) return;
    if ($doc['sprawa_status'] === 'closed' && !is_admin()) {
        throw new \RuntimeException('Koszulka jest zamknięta — edycja zablokowana.');
    }
    db()->prepare(
        "UPDATE ezd_dokumenty SET rodzaj=:rodz,title=:title,tresc=:tresc,status=:status,
         owner_id=:owner,updated_at=datetime('now') WHERE id=:id"
    )->execute([
        ':rodz'  => array_key_exists($d['rodzaj'] ?? '', EZD_DOK_RODZAJE) ? $d['rodzaj'] : $doc['rodzaj'],
        ':title' => trim($d['title']), ':tresc' => $d['tresc'] ?? '',
        ':status'=> array_key_exists($d['status'] ?? '', EZD_DOK_STATUSY) ? $d['status'] : $doc['status'],
        ':owner' => $d['owner_id'] ?: null, ':id' => $id,
    ]);
    ezd_log(null, (int)$doc['sprawa_id'], null, null, $user_id, 'dokument_update', 'Edytowano dokument #' . $id);
}

function ezd_dokument_delete(int $id, int $user_id): void {
    $doc = ezd_dokument_get($id);
    if (!$doc) return;
    foreach (ezd_zalaczniki_by((int)$doc['sprawa_id'], null, null, $id) as $z) {
        ezd_zal_delete((int)$z['id'], $user_id);
    }
    db()->prepare("DELETE FROM ezd_dokumenty WHERE id=?")->execute([$id]);
    ezd_log(null, (int)$doc['sprawa_id'], null, null, $user_id, 'dokument_delete', 'Usunięto dokument ' . $doc['sygnatura']);
}

function ezd_dok_status_badge(string $status): string {
    $s = EZD_DOK_STATUSY[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . ' bg-opacity-15 text-' . $s['class'] . ' border border-' . $s['class'] . '" style="font-size:.65rem">' . h($s['label']) . '</span>';
}

// ── Rejestr pełnomocnictw (sprawy z JRWA „013") ───────────────────────────────

/** Symbol JRWA, pod którym prowadzone są pełnomocnictwa (konfigurowalny). */
function ezd_peln_jrwa(): string {
    $s = trim(org_setting('ezd_peln_jrwa'));
    return $s !== '' ? $s : '013';
}

/** Status pełnomocnictwa wyliczony z dat. @return array{key:string,label:string,class:string} */
function ezd_peln_status(array $p): array {
    if (!empty($p['data_odwolania'])) return ['key'=>'odwolane','label'=>'Odwołane','class'=>'danger'];
    if (!empty($p['data_waznosci']) && $p['data_waznosci'] < date('Y-m-d')) return ['key'=>'wygasle','label'=>'Wygasłe','class'=>'secondary'];
    return ['key'=>'wazne','label'=>'Ważne','class'=>'success'];
}

/** Czy sprawa należy do klasyfikacji pełnomocnictw (JRWA 013). */
function ezd_peln_is_sprawa(int $sprawa_id): bool {
    $sym = ezd_peln_jrwa();
    return (bool) db_one(
        "SELECT 1 FROM ezd_sprawy s JOIN ezd_teczki t ON t.id=s.teczka_id
         LEFT JOIN ezd_jrwa j ON j.id=t.jrwa_id
         WHERE s.id=? AND (j.symbol=? OR t.symbol=?)",
        [$sprawa_id, $sym, $sym]
    );
}

/** Lista pełnomocnictw = sprawy z JRWA 013 + metadane. Filtruje status/q w PHP. */
function ezd_pelnomocnictwa_all(array $f = []): array {
    $sym = ezd_peln_jrwa();
    $rows = db_all(
        "SELECT s.id AS sprawa_id, s.znak_sprawy, s.title, s.status AS sprawa_status,
                s.ciagla, s.created_at, t.symbol AS teczka_symbol, t.rok AS teczka_rok,
                o.name AS owner_name,
                p.numer, p.mocodawca, p.pelnomocnik, p.zakres,
                p.data_udzielenia, p.data_waznosci, p.data_odwolania, p.uwagi
         FROM ezd_sprawy s
         JOIN ezd_teczki t ON t.id = s.teczka_id
         LEFT JOIN ezd_jrwa j ON j.id = t.jrwa_id
         LEFT JOIN ezd_pelnomocnictwa p ON p.sprawa_id = s.id
         LEFT JOIN users o ON o.id = s.owner_id
         WHERE (j.symbol=? OR t.symbol=?)
         ORDER BY COALESCE(p.data_udzielenia, s.created_at) DESC, s.id DESC",
        [$sym, $sym]
    );
    $q      = mb_strtolower(trim($f['q'] ?? ''));
    $status = $f['status'] ?? '';
    $out = [];
    foreach ($rows as $r) {
        $st = ezd_peln_status($r);
        $r['_status'] = $st;
        if ($status && $st['key'] !== $status) continue;
        if ($q !== '') {
            $hay = mb_strtolower(($r['mocodawca'] ?? '').' '.($r['pelnomocnik'] ?? '').' '.($r['znak_sprawy'] ?? '').' '.($r['title'] ?? '').' '.($r['numer'] ?? ''));
            if (mb_strpos($hay, $q) === false) continue;
        }
        $out[] = $r;
    }
    return $out;
}

function ezd_pelnomocnictwo_get(int $sprawa_id): ?array {
    $s = ezd_sprawa_get($sprawa_id);
    if (!$s) return null;
    $p = db_one("SELECT * FROM ezd_pelnomocnictwa WHERE sprawa_id=?", [$sprawa_id]) ?: [];
    return array_merge($s, [
        'numer'           => $p['numer'] ?? '',
        'mocodawca'       => $p['mocodawca'] ?? '',
        'pelnomocnik'     => $p['pelnomocnik'] ?? '',
        'zakres'          => $p['zakres'] ?? '',
        'data_udzielenia' => $p['data_udzielenia'] ?? null,
        'data_waznosci'   => $p['data_waznosci'] ?? null,
        'data_odwolania'  => $p['data_odwolania'] ?? null,
        'peln_uwagi'      => $p['uwagi'] ?? '',
    ]);
}

function ezd_pelnomocnictwo_save(int $sprawa_id, array $d, int $user_id): void {
    if (!ezd_peln_is_sprawa($sprawa_id)) {
        throw new \RuntimeException('Sprawa nie należy do klasyfikacji pełnomocnictw (JRWA ' . ezd_peln_jrwa() . ').');
    }
    db()->prepare(
        "INSERT INTO ezd_pelnomocnictwa
            (sprawa_id,numer,mocodawca,pelnomocnik,zakres,data_udzielenia,data_waznosci,data_odwolania,uwagi,updated_by,updated_at)
         VALUES (:sid,:num,:moc,:pel,:zak,:du,:dw,:do_,:uw,:uid,datetime('now'))
         ON CONFLICT(sprawa_id) DO UPDATE SET
            numer=excluded.numer, mocodawca=excluded.mocodawca, pelnomocnik=excluded.pelnomocnik,
            zakres=excluded.zakres, data_udzielenia=excluded.data_udzielenia, data_waznosci=excluded.data_waznosci,
            data_odwolania=excluded.data_odwolania, uwagi=excluded.uwagi, updated_by=excluded.updated_by, updated_at=datetime('now')"
    )->execute([
        ':sid' => $sprawa_id,
        ':num' => trim($d['numer'] ?? ''),
        ':moc' => trim($d['mocodawca'] ?? ''),
        ':pel' => trim($d['pelnomocnik'] ?? ''),
        ':zak' => trim($d['zakres'] ?? ''),
        ':du'  => ($d['data_udzielenia'] ?? '') ?: null,
        ':dw'  => ($d['data_waznosci'] ?? '') ?: null,
        ':do_' => ($d['data_odwolania'] ?? '') ?: null,
        ':uw'  => trim($d['uwagi'] ?? ''),
        ':uid' => $user_id,
    ]);
    ezd_log(null, $sprawa_id, null, null, $user_id, 'pelnomocnictwo_save', 'Zaktualizowano dane pełnomocnictwa');
}

function ezd_peln_stats(): array {
    $all = ezd_pelnomocnictwa_all();
    $c = ['total'=>count($all),'wazne'=>0,'wygasle'=>0,'odwolane'=>0];
    foreach ($all as $r) $c[$r['_status']['key']]++;
    return $c;
}

/** Teczka pod JRWA pełnomocnictw, do której można dodać nową sprawę (najnowsza otwarta). */
function ezd_peln_teczka_id(): ?int {
    $sym = ezd_peln_jrwa();
    $r = db_one(
        "SELECT t.id FROM ezd_teczki t LEFT JOIN ezd_jrwa j ON j.id=t.jrwa_id
         WHERE (j.symbol=? OR t.symbol=?) AND t.status='open'
         ORDER BY t.rok DESC, t.id DESC LIMIT 1",
        [$sym, $sym]
    );
    return $r ? (int)$r['id'] : null;
}

// ── Rejestracja zaświadczeń w EZD (JRWA 53) ──────────────────────────────────

function ezd_cert_jrwa(): string {
    $s = trim((string)org_setting('ezd_cert_jrwa'));
    return $s !== '' ? $s : '53';
}

/** Hasło JRWA zaświadczeń — utworzone, jeśli nie istnieje. */
function _ezd_cert_jrwa_id(): int {
    $sym = ezd_cert_jrwa();
    $j = db_one("SELECT id FROM ezd_jrwa WHERE symbol=?", [$sym]);
    if ($j) return (int)$j['id'];
    db()->prepare("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order) VALUES (?,?,?,?,?)")
        ->execute([$sym, 'Zaświadczenia', 'B5', 'Zaświadczenia wydawane wolontariuszom i współpracownikom', 530]);
    return (int)db()->lastInsertId();
}

/** Teczka roczna zaświadczeń (utworzona w razie potrzeby). */
function _ezd_cert_teczka_id(int $rok, int $user_id): int {
    $jid = _ezd_cert_jrwa_id();
    $t = db_one("SELECT id FROM ezd_teczki WHERE jrwa_id=? AND rok=? AND status='open' ORDER BY id LIMIT 1", [$jid, $rok]);
    if ($t) return (int)$t['id'];
    return ezd_teczka_create(['jrwa_id'=>$jid, 'symbol'=>ezd_cert_jrwa(), 'title'=>"Zaświadczenia $rok", 'rok'=>$rok, 'owner_id'=>null], $user_id);
}

/** Sprawa ciągła „Rejestr zaświadczeń {rok}" (utworzona w razie potrzeby). */
function _ezd_cert_sprawa_id(int $rok, int $user_id): int {
    $tid   = _ezd_cert_teczka_id($rok, $user_id);
    $title = "Rejestr zaświadczeń $rok";
    $s = db_one("SELECT id FROM ezd_sprawy WHERE teczka_id=? AND title=? LIMIT 1", [$tid, $title]);
    if ($s) return (int)$s['id'];
    return ezd_sprawa_create(['teczka_id'=>$tid, 'title'=>$title, 'description'=>'Rejestr zaświadczeń wydanych w '.$rok.' r.', 'priority'=>'normal', 'owner_id'=>null, 'ciagla'=>1], $user_id);
}

/**
 * Rejestruje wydane zaświadczenie jako pismo wychodzące w EZD (JRWA zaświadczeń).
 * Idempotentne — gdy zaświadczenie ma już ezd_pismo_id, zwraca istniejące id.
 * @return int|null id pisma EZD lub null gdy moduł wyłączony
 */
function ezd_register_certificate(array $req, int $user_id): ?int {
    if (!module_enabled('ezd_enabled')) return null;
    if (!empty($req['ezd_pismo_id'])) return (int)$req['ezd_pismo_id'];

    $issued = $req['issued_at'] ?? $req['created_at'] ?? date('Y-m-d');
    $rok    = (int)substr($issued, 0, 4) ?: (int)date('Y');
    $sprawa_id = _ezd_cert_sprawa_id($rok, $user_id ?: 0);

    $num  = trim((string)($req['cert_number'] ?? ''));
    $name = trim((string)($req['requester_name'] ?? ''));
    $tresc = trim(
        ($num ? "Numer zaświadczenia: $num\n" : '')
        . (!empty($req['cel']) ? 'Cel: ' . $req['cel'] . "\n" : '')
        . (!empty($req['sign_type']) ? 'Forma: ' . $req['sign_type'] . "\n" : '')
        . (!empty($req['verify_code']) ? 'Kod weryfikacyjny: ' . $req['verify_code'] : '')
    );

    $pid = ezd_pismo_create([
        'sprawa_id'   => $sprawa_id,
        'kierunek'    => 'wychodzace',
        'title'       => 'Zaświadczenie' . ($num ? ' nr ' . $num : '') . ($name ? ' — ' . $name : ''),
        'tresc'       => $tresc,
        'nadawca'     => '',
        'odbiorca'    => $name,
        'data_pisma'  => substr($issued, 0, 10) ?: null,
        'data_wplywu' => null,
        'data_wysylki'=> substr($issued, 0, 10) ?: null,
        'status'      => 'zakonczone',
        'owner_id'    => $user_id ?: null,
    ], $user_id ?: 0);

    try { db()->prepare("UPDATE certificate_requests SET ezd_pismo_id=? WHERE id=?")->execute([$pid, (int)$req['id']]); } catch (\Throwable $e) {}
    return $pid;
}

/** Lista zarejestrowanych zaświadczeń (z modułu zaświadczeń powiązanych z EZD). */
function ezd_zaswiadczenia_all(string $q = ''): array {
    $where = "cr.ezd_pismo_id IS NOT NULL";
    $params = [];
    if ($q !== '') {
        $where .= " AND (cr.cert_number LIKE ? OR cr.requester_name LIKE ?)";
        $like = '%'.$q.'%'; $params[] = $like; $params[] = $like;
    }
    return db_all(
        "SELECT cr.id, cr.cert_number, cr.requester_name, cr.requester_email, cr.cel,
                cr.sign_type, cr.status, cr.issued_at, cr.verify_code, cr.ezd_pismo_id,
                p.sygnatura, p.sprawa_id, s.znak_sprawy
         FROM certificate_requests cr
         LEFT JOIN ezd_pisma  p ON p.id = cr.ezd_pismo_id
         LEFT JOIN ezd_sprawy s ON s.id = p.sprawa_id
         WHERE $where
         ORDER BY cr.issued_at DESC, cr.id DESC",
        $params
    );
}

/** Rejestruje wszystkie wydane, a jeszcze nieujęte w EZD zaświadczenia. @return int liczba dodanych */
function ezd_zaswiadczenia_backfill(int $user_id): int {
    $rows = db_all(
        "SELECT * FROM certificate_requests
         WHERE ezd_pismo_id IS NULL AND cert_number IS NOT NULL AND cert_number<>''
           AND status IN ('wydane','gotowe','esign_oczekuje','esign_podpisane')
         ORDER BY issued_at, id"
    );
    $n = 0;
    foreach ($rows as $r) {
        try { if (ezd_register_certificate($r, $user_id)) $n++; } catch (\Throwable $e) {}
    }
    return $n;
}

function ezd_zaswiadczenia_count(): int {
    try { return (int)(db_one("SELECT COUNT(*) c FROM certificate_requests WHERE ezd_pismo_id IS NOT NULL")['c'] ?? 0); }
    catch (\Throwable $e) { return 0; }
}

// ── Rejestracja korespondencji w EZD (dziennik kancelaryjny) ─────────────────

function ezd_corr_jrwa(): string {
    $s = trim((string)org_setting('corr_ezd_jrwa'));
    return $s !== '' ? $s : 'KOR';
}

/** Hasło JRWA korespondencji — utworzone, jeśli nie istnieje. */
function _ezd_corr_jrwa_id(): int {
    $sym = ezd_corr_jrwa();
    $j = db_one("SELECT id FROM ezd_jrwa WHERE symbol=?", [$sym]);
    if ($j) return (int)$j['id'];
    db()->prepare("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order) VALUES (?,?,?,?,?)")
        ->execute([$sym, 'Korespondencja ogólna', 'B5', 'Pisma wpływające i wychodzące niezakwalifikowane do innych kategorii', 70]);
    return (int)db()->lastInsertId();
}

/** Teczka roczna korespondencji (utworzona w razie potrzeby). */
function _ezd_corr_teczka_id(int $rok, int $user_id): int {
    $jid = _ezd_corr_jrwa_id();
    $t = db_one("SELECT id FROM ezd_teczki WHERE jrwa_id=? AND rok=? AND status='open' ORDER BY id LIMIT 1", [$jid, $rok]);
    if ($t) return (int)$t['id'];
    return ezd_teczka_create(['jrwa_id'=>$jid, 'symbol'=>ezd_corr_jrwa(), 'title'=>"Korespondencja $rok", 'rok'=>$rok, 'owner_id'=>null], $user_id);
}

/** Sprawa ciągła dziennika korespondencji wg kierunku (incoming/outgoing). */
function ezd_corr_sprawa_id(string $direction, int $rok, int $user_id): int {
    $tid   = _ezd_corr_teczka_id($rok, $user_id);
    $title = $direction === 'outgoing' ? "Korespondencja wychodząca $rok" : "Korespondencja przychodząca $rok";
    $s = db_one("SELECT id FROM ezd_sprawy WHERE teczka_id=? AND title=? LIMIT 1", [$tid, $title]);
    if ($s) return (int)$s['id'];
    return ezd_sprawa_create(['teczka_id'=>$tid, 'title'=>$title, 'description'=>'Dziennik korespondencji '.($direction==='outgoing'?'wychodzącej':'przychodzącej').' '.$rok.' r.', 'priority'=>'normal', 'owner_id'=>null, 'ciagla'=>1], $user_id);
}

// ── Rejestracja pism do wolontariuszy bez umowy w EZD (JRWA WOL) ─────────────

function ezd_vol_jrwa(): string {
    $s = trim((string)org_setting('vol_corr_ezd_jrwa'));
    return $s !== '' ? $s : 'WOL';
}

/** Hasło JRWA wolontariatu — utworzone, jeśli nie istnieje. */
function _ezd_vol_jrwa_id(): int {
    $sym = ezd_vol_jrwa();
    $j = db_one("SELECT id FROM ezd_jrwa WHERE symbol=?", [$sym]);
    if ($j) return (int)$j['id'];
    db()->prepare("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order) VALUES (?,?,?,?,?)")
        ->execute([$sym, 'Wolontariat', 'B10', 'Sprawy i korespondencja dotycząca wolontariuszy', 200]);
    return (int)db()->lastInsertId();
}

/** Teczka roczna „Pisma do wolontariuszy bez umowy {rok}" (utworzona w razie potrzeby). */
function _ezd_vol_teczka_id(int $rok, int $user_id): int {
    $jid = _ezd_vol_jrwa_id();
    $t = db_one("SELECT id FROM ezd_teczki WHERE jrwa_id=? AND rok=? AND status='open' ORDER BY id LIMIT 1", [$jid, $rok]);
    if ($t) return (int)$t['id'];
    return ezd_teczka_create(['jrwa_id'=>$jid, 'symbol'=>ezd_vol_jrwa(), 'title'=>"Pisma do wolontariuszy bez umowy $rok", 'rok'=>$rok, 'owner_id'=>null], $user_id);
}

/** Sprawa ciągła „Pisma do wolontariuszy bez umowy {rok}" (utworzona w razie potrzeby). */
function _ezd_vol_sprawa_id(int $rok, int $user_id): int {
    $tid   = _ezd_vol_teczka_id($rok, $user_id);
    $title = "Pisma do wolontariuszy bez umowy $rok";
    $s = db_one("SELECT id FROM ezd_sprawy WHERE teczka_id=? AND title=? LIMIT 1", [$tid, $title]);
    if ($s) return (int)$s['id'];
    return ezd_sprawa_create(['teczka_id'=>$tid, 'title'=>$title, 'description'=>'Rejestr pism wysłanych do wolontariuszy bez umowy w '.$rok.' r.', 'priority'=>'normal', 'owner_id'=>null, 'ciagla'=>1], $user_id);
}

// ── Dokumenty księgowe (EDOK/KDOK) — obieg od zapłaty ────────────────────────
// Prowizjonowanie hasła JRWA + teczki rocznej + sprawy ciągłej dla dokumentów
// finansowo-księgowych. Sama rejestracja dokumentu żyje w includes/ksiegowosc.php
// (kdok_register_in_ezd) — moduł KDOK bywa w osobnej bazie, więc link zwrotny
// zapisuje wołający po stronie bazy KDOK, bez JOIN-a między bazami.

function ezd_kdok_jrwa(): string {
    $s = trim((string)org_setting('ezd_kdok_jrwa'));
    return $s !== '' ? $s : 'KSG';
}

/** Hasło JRWA dokumentów księgowych — utworzone, jeśli nie istnieje. */
function _ezd_kdok_jrwa_id(): int {
    $sym = ezd_kdok_jrwa();
    $j = db_one("SELECT id FROM ezd_jrwa WHERE symbol=?", [$sym]);
    if ($j) return (int)$j['id'];
    db()->prepare("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order) VALUES (?,?,?,?,?)")
        ->execute([$sym, 'Dokumenty księgowe - obieg od zapłaty', 'B5',
                   'Dokumenty finansowo-księgowe zatwierdzone do wypłaty w module EOD Dokumentów Księgowych', 300]);
    return (int)db()->lastInsertId();
}

/** Teczka roczna dokumentów księgowych (utworzona w razie potrzeby). */
function _ezd_kdok_teczka_id(int $rok, int $user_id): int {
    $jid = _ezd_kdok_jrwa_id();
    $t = db_one("SELECT id FROM ezd_teczki WHERE jrwa_id=? AND rok=? AND status='open' ORDER BY id LIMIT 1", [$jid, $rok]);
    if ($t) return (int)$t['id'];
    return ezd_teczka_create(['jrwa_id'=>$jid, 'symbol'=>ezd_kdok_jrwa(), 'title'=>"Dokumenty księgowe $rok", 'rok'=>$rok, 'owner_id'=>null], $user_id);
}

/**
 * Tworzy dedykowaną koszulkę (sprawę) EZD dla pojedynczego dokumentu KDOK.
 * Sprawdza najpierw ref_type='kdok', ref_id=$doc['id'] — unikamy duplikatów.
 */
function ezd_kdok_koszulka_create(array $doc, int $user_id): int {
    $doc_id = (int)($doc['id'] ?? 0);
    // Idempotentność — czy koszulka już istnieje?
    $existing = db_one("SELECT id FROM ezd_sprawy WHERE ref_type='kdok' AND ref_id=?", [$doc_id]);
    if ($existing) return (int)$existing['id'];

    $rok = (int)substr((string)($doc['created_at'] ?? date('Y')), 0, 4) ?: (int)date('Y');
    $tid = _ezd_kdok_teczka_id($rok, $user_id);

    $typ_label = '';
    if (function_exists('KDOK_TYPES')) {
        // Stała może być niedostępna gdy ezd.php ładuje się bez ksiegowosc.php
    }
    $type_labels = [
        'ksef'        => 'Faktura KSeF',
        'ksef_reczny' => 'Faktura KSeF (ręczna)',
        'rachunek'    => 'Rachunek',
        'lista_plac'  => 'Lista płac',
        'wyciag'      => 'Wyciąg bankowy',
    ];
    $typ_label = $type_labels[$doc['type'] ?? ''] ?? ($doc['type'] ?? 'Dokument');

    $nr_fakt  = trim((string)($doc['nr_faktury'] ?? $doc['number'] ?? ''));
    $title    = $typ_label . ($nr_fakt ? ' ' . $nr_fakt : '') . (($doc['title'] ?? '') !== '' ? ' — ' . $doc['title'] : '');

    $parts = [];
    if (($doc['nip_dostawcy'] ?? '') !== '')     $parts[] = 'NIP: ' . $doc['nip_dostawcy'];
    if (($doc['kwota_brutto'] ?? '') !== '')      $parts[] = 'Kwota brutto: ' . $doc['kwota_brutto'] . ' ' . ($doc['waluta'] ?? 'PLN');
    elseif (($doc['kwota'] ?? '') !== '')         $parts[] = 'Kwota: ' . $doc['kwota'];
    if (($doc['termin_platnosci'] ?? '') !== '')  $parts[] = 'Termin płatności: ' . $doc['termin_platnosci'];
    if (($doc['centrum_kosztow'] ?? '') !== '')   $parts[] = 'CK: ' . $doc['centrum_kosztow'];
    elseif (($doc['mpk'] ?? '') !== '')           $parts[] = 'MPK: ' . $doc['mpk'];
    if (($doc['projekt'] ?? '') !== '')           $parts[] = 'Projekt: ' . $doc['projekt'];
    elseif (($doc['grant_name'] ?? '') !== '')    $parts[] = 'Projekt: ' . $doc['grant_name'];
    if (!empty($doc['wymaga_mpp']))               $parts[] = 'Wymaga split payment (MPP)';

    $desc = 'Koszulka dokumentu finansowo-księgowego KDOK/' . ($doc['number'] ?? '?') . ".\n"
          . ($parts ? implode(' | ', $parts) : '');

    $deadline = ($doc['termin_platnosci'] ?? '') ?: null;

    return ezd_sprawa_create([
        'teczka_id'   => $tid,
        'title'       => mb_substr($title, 0, 255),
        'description' => $desc,
        'priority'    => 'normal',
        'owner_id'    => $doc['created_by'] ?? null,
        'deadline'    => $deadline,
        'ref_type'    => 'kdok',
        'ref_id'      => $doc_id,
    ], $user_id);
}

/** Sprawa ciągła „Dokumenty księgowe - obieg od zapłaty {rok}" (utworzona w razie potrzeby). */
function ezd_kdok_sprawa_id(int $rok, int $user_id): int {
    $tid   = _ezd_kdok_teczka_id($rok, $user_id);
    $title = "Dokumenty księgowe - obieg od zapłaty $rok";
    $s = db_one("SELECT id FROM ezd_sprawy WHERE teczka_id=? AND title=? LIMIT 1", [$tid, $title]);
    if ($s) return (int)$s['id'];
    return ezd_sprawa_create(['teczka_id'=>$tid, 'title'=>$title, 'description'=>'Rejestr dokumentów finansowo-księgowych zatwierdzonych do wypłaty w '.$rok.' r. (moduł EOD Dokumentów Księgowych).', 'priority'=>'normal', 'owner_id'=>null, 'ciagla'=>1], $user_id);
}

// ── Rejestracja weryfikacji RPTS w EZD (JRWA KAD — akta osobowe) ─────────────

function ezd_rpts_jrwa(): string {
    $s = trim((string)org_setting('rpts_ezd_jrwa'));
    return $s !== '' ? $s : 'KAD';
}

/** Hasło JRWA kadr — istnieje z seeda, samonaprawa na wypadek starszej bazy. */
function _ezd_rpts_jrwa_id(): int {
    $sym = ezd_rpts_jrwa();
    $j = db_one("SELECT id FROM ezd_jrwa WHERE symbol=?", [$sym]);
    if ($j) return (int)$j['id'];
    db()->prepare("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order) VALUES (?,?,?,?,?)")
        ->execute([$sym, 'Kadry i sprawy pracownicze', 'B50', 'Umowy o pracę, akta osobowe', 30]);
    return (int)db()->lastInsertId();
}

/** Teczka roczna „Weryfikacje RPTS {rok}" (utworzona w razie potrzeby). */
function _ezd_rpts_teczka_id(int $rok, int $user_id): int {
    $jid   = _ezd_rpts_jrwa_id();
    $title = "Weryfikacje RPTS $rok";
    $t = db_one("SELECT id FROM ezd_teczki WHERE jrwa_id=? AND rok=? AND title=? AND status='open' ORDER BY id LIMIT 1", [$jid, $rok, $title]);
    if ($t) return (int)$t['id'];
    return ezd_teczka_create(['jrwa_id'=>$jid, 'symbol'=>ezd_rpts_jrwa(), 'title'=>$title, 'rok'=>$rok, 'owner_id'=>null], $user_id);
}

/**
 * Otwiera dedykowaną sprawę EZD dla weryfikacji RPTS danej osoby, zaraz po
 * złożeniu przez nią zgody w panelu wolontariusza (jedna sprawa na osobę,
 * nie ciągła — do zamknięcia po wykonaniu faktycznej weryfikacji i
 * odnotowaniu wyniku na umowie). Idempotentne — jeśli users.rpts_ezd_sprawa_id
 * jest już ustawione, zwraca istniejące id bez tworzenia duplikatu.
 *
 * @return int|null id sprawy EZD, albo null gdy moduł EZD jest wyłączony
 */
function ezd_register_rpts_consent(array $user, int $created_by = 0): ?int {
    if (!module_enabled('ezd_enabled')) return null;
    if (!empty($user['rpts_ezd_sprawa_id'])) return (int)$user['rpts_ezd_sprawa_id'];

    $uid  = $created_by ?: (int)$user['id'];
    $rok  = (int)date('Y');
    $tid  = _ezd_rpts_teczka_id($rok, $uid);
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))
        ?: ($user['name'] ?? $user['email'] ?? ('#' . $user['id']));

    $tresc = "OŚWIADCZENIE O WYRAŻENIU ZGODY NA WERYFIKACJĘ W REJESTRZE SPRAWCÓW PRZESTĘPSTW NA TLE SEKSUALNYM (RSPTS)\n\n"
        . "Imię i nazwisko: {$name}\n"
        . "PESEL: " . ($user['rpts_pesel'] ?? '') . "\n"
        . "Data i miejsce urodzenia: " . ($user['rpts_data_urodzenia'] ?? '') . ", " . ($user['rpts_miejsce_urodzenia'] ?? '') . "\n"
        . "Nazwisko rodowe: " . ($user['rpts_nazwisko_rodowe'] ?? '') . "\n"
        . "Imię ojca: " . ($user['rpts_imie_ojca'] ?? '') . "\n"
        . "Imię matki: " . ($user['rpts_imie_matki'] ?? '') . "\n\n"
        . "Osoba wyraziła zgodę na weryfikację w Rejestrze z dostępem ograniczonym, zgodnie "
        . "z art. 21 ustawy z dnia 13 maja 2016 r. o przeciwdziałaniu zagrożeniom przestępczością "
        . "na tle seksualnym i ochronie małoletnich.";

    $sprawa_id = ezd_sprawa_create([
        'teczka_id'   => $tid,
        'title'       => "Weryfikacja RPTS — {$name}",
        'description' => 'Sprawa otwarta automatycznie po złożeniu zgody w panelu wolontariusza. '
                        . 'Do zamknięcia po wykonaniu weryfikacji na rps.ms.gov.pl i odnotowaniu wyniku na umowie.',
        'priority'    => 'normal',
        'owner_id'    => null,
        'ref_type'    => 'rpts_consent',
        'ref_id'      => (int)$user['id'],
    ], $uid);

    try {
        ezd_pismo_create([
            'sprawa_id'   => $sprawa_id,
            'kierunek'    => 'przychodzace',
            'title'       => 'Oświadczenie o wyrażeniu zgody na weryfikację RPTS',
            'tresc'       => $tresc,
            'nadawca'     => $name,
            'odbiorca'    => '',
            'data_pisma'  => date('Y-m-d'),
            'data_wplywu' => date('Y-m-d'),
            'status'      => 'nowe',
            'rodzaj_medium' => 'inne',
        ], $uid);
    } catch (\Throwable $e) {
        // Sprawa jest już utworzona — brak pisma nie jest krytyczny.
    }

    try {
        db()->prepare("UPDATE users SET rpts_ezd_sprawa_id=? WHERE id=?")->execute([$sprawa_id, (int)$user['id']]);
    } catch (\Throwable $e) {}

    return $sprawa_id;
}

// ── Rejestracja zgody przedstawiciela ustawowego na wolontariat (JRWA WOL) ───

/** Teczka roczna „Zgody opiekunów wolontariuszy niepełnoletnich {rok}" (utworzona w razie potrzeby). */
function _ezd_guardian_consent_teczka_id(int $rok, int $user_id): int {
    $jid   = _ezd_vol_jrwa_id();
    $title = "Zgody opiekunów wolontariuszy niepełnoletnich $rok";
    $t = db_one("SELECT id FROM ezd_teczki WHERE jrwa_id=? AND rok=? AND title=? AND status='open' ORDER BY id LIMIT 1", [$jid, $rok, $title]);
    if ($t) return (int)$t['id'];
    return ezd_teczka_create(['jrwa_id'=>$jid, 'symbol'=>ezd_vol_jrwa(), 'title'=>$title, 'rok'=>$rok, 'owner_id'=>null], $user_id);
}

/**
 * Otwiera sprawę EZD dla zaproszenia opiekuna do złożenia/odnowienia zgody na
 * wolontariat małoletniego + RODO (pismo podpisane przez przedstawiciela
 * Fundacji — zob. org_representatives), i rejestruje samo pismo (wychodzące).
 * Idempotentne — jeśli umowy_wolontariat.zgoda_przedstawiciela_ezd_sprawa_id
 * jest już ustawione i sprawa jest otwarta, zwraca istniejące id.
 *
 * @return array{sprawa_id:int, znak_sprawy:string, podpisujacy:string}|null
 */
function ezd_register_guardian_consent_letter(array $contract, string $guardian_name, int $created_by = 0): ?array {
    if (!module_enabled('ezd_enabled')) return null;

    $existing_id = (int)($contract['zgoda_przedstawiciela_ezd_sprawa_id'] ?? 0);
    if ($existing_id) {
        $existing = ezd_sprawa_get($existing_id);
        if ($existing && $existing['status'] !== 'closed') {
            $existing_pismo = db_one("SELECT id FROM ezd_pisma WHERE sprawa_id=? ORDER BY id DESC LIMIT 1", [$existing_id]);
            return [
                'sprawa_id'   => $existing_id,
                'pismo_id'    => $existing_pismo['id'] ?? null,
                'znak_sprawy' => $existing['znak_sprawy'],
                'podpisujacy' => '',
            ];
        }
    }

    $uid  = $created_by ?: 0;
    $rok  = (int)date('Y');
    $tid  = _ezd_guardian_consent_teczka_id($rok, $uid);
    $osoba = $contract['imie_nazwisko'] ?? '';

    $reps = org_representatives();
    $podpisujacy = $reps ? ($reps[0]['name'] . ($reps[0]['title'] ? ' (' . $reps[0]['title'] . ')' : '')) : '';

    $sprawa_id = ezd_sprawa_create([
        'teczka_id'   => $tid,
        'title'       => "Zgoda opiekuna na wolontariat — {$osoba}",
        'description' => 'Sprawa otwarta automatycznie — zaproszenie przedstawiciela ustawowego do złożenia/'
                        . 'odnowienia zgody na wolontariat małoletniego i przetwarzanie jego danych (RODO). '
                        . 'Do zamknięcia po złożeniu zgody „na klik" w panelu opiekuna.',
        'priority'    => 'normal',
        'owner_id'    => null,
        'ref_type'    => 'guardian_consent',
        'ref_id'      => (int)$contract['id'],
    ], $uid);

    $pismo_id = null;
    try {
        $pismo_id = ezd_pismo_create([
            'sprawa_id'     => $sprawa_id,
            'kierunek'      => 'wychodzace',
            'title'         => 'Wyrażenie zgody na udział dziecka w wolontariacie',
            'tresc'         => 'Zaproszenie przedstawiciela ustawowego ' . $guardian_name . ' do odnowienia zgody na wolontariat '
                              . 'małoletniego ' . $osoba . ' oraz na przetwarzanie jego danych osobowych (RODO). '
                              . ($podpisujacy ? "Podpisano: {$podpisujacy}." : ''),
            'nadawca'       => '',
            'odbiorca'      => $guardian_name,
            'data_pisma'    => date('Y-m-d'),
            'data_wysylki'  => date('Y-m-d'),
            'status'        => 'nowe',
            'rodzaj_medium' => 'email',
        ], $uid);
    } catch (\Throwable $e) {
        // Sprawa jest już utworzona — brak pisma nie jest krytyczny.
    }

    $sprawa = ezd_sprawa_get($sprawa_id);
    try {
        db()->prepare("UPDATE umowy_wolontariat SET zgoda_przedstawiciela_ezd_sprawa_id=? WHERE id=?")
            ->execute([$sprawa_id, (int)$contract['id']]);
    } catch (\Throwable $e) {}

    return [
        'sprawa_id'   => $sprawa_id,
        'pismo_id'    => $pismo_id,
        'znak_sprawy' => $sprawa['znak_sprawy'] ?? '',
        'podpisujacy' => $podpisujacy,
    ];
}

// ── Teczka „Informatyka i technologia" (integracja z modułem Helpdesk) ───────

/** Hasło JRWA „IT" — istnieje z seeda, samonaprawa na wypadek starszej bazy. */
function _ezd_it_jrwa_id(): int {
    $j = db_one("SELECT id FROM ezd_jrwa WHERE symbol='IT'");
    if ($j) return (int)$j['id'];
    db()->prepare("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order) VALUES (?,?,?,?,?)")
        ->execute(['IT', 'Informatyka i technologia', 'B5', 'Licencje, umowy serwisowe, polityki IT', 90]);
    return (int)db()->lastInsertId();
}

/** Teczka roczna „Informatyka i technologia {rok}" (utworzona w razie potrzeby). */
function _ezd_it_teczka_id(int $rok, int $user_id): int {
    $jid = _ezd_it_jrwa_id();
    $t = db_one("SELECT id FROM ezd_teczki WHERE jrwa_id=? AND rok=? AND status='open' ORDER BY id LIMIT 1", [$jid, $rok]);
    if ($t) return (int)$t['id'];
    return ezd_teczka_create(['jrwa_id'=>$jid, 'symbol'=>'IT', 'title'=>"Informatyka i technologia $rok", 'rok'=>$rok, 'owner_id'=>null], $user_id);
}

/**
 * Dołącza istniejący plik z dysku jako załącznik EZD — odpowiednik ezd_upload() dla
 * pliku, który nie pochodzi z $_FILES. Kopiuje plik do katalogu sprawy.
 * @return int|null id załącznika lub null przy błędzie.
 */
function ezd_attach_path(string $srcPath, string $origName, int $sprawa_id, ?int $pismo_id, int $user_id): ?int {
    if (!is_file($srcPath)) return null;
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) ?: 'bin';
    $dir = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $sprawa_id . '/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@copy($srcPath, $dir . $stored)) return null;
    $size = filesize($dir . $stored) ?: 0;
    $mime = function_exists('mime_content_type') ? (mime_content_type($dir . $stored) ?: 'application/octet-stream') : 'application/octet-stream';
    db()->prepare(
        "INSERT INTO ezd_zalaczniki (sprawa_id,pismo_id,umowa_id,dokument_id,grupa_id,filename,original_name,mime_type,file_size,wersja,prev_id,uploaded_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([$sprawa_id, $pismo_id, null, null, null, $stored, mb_substr($origName, 0, 255), $mime, $size, 1, null, $user_id]);
    ezd_log(null, $sprawa_id, $pismo_id, null, $user_id, 'upload', 'Wgrano plik: ' . $origName);
    $new_id = (int)db()->lastInsertId();
    try { ezd_sp_sync_attachment($new_id); } catch (\Throwable $e) {}
    return $new_id;
}

/**
 * Rejestruje pismo wysłane do wolontariusza bez umowy jako pismo EZD (wychodzące)
 * w sprawie ciągłej pod JRWA WOL; dołącza oryginał dokumentu jako załącznik.
 * @param array $d ['title','tresc','odbiorca','file_path','file_name']
 * @return int|null id pisma EZD lub null gdy moduł EZD wyłączony.
 */
function ezd_register_volunteer_letter(array $d, int $user_id): ?int {
    if (!module_enabled('ezd_enabled')) return null;
    $rok = (int)date('Y');
    $sprawa_id = _ezd_vol_sprawa_id($rok, $user_id ?: 0);
    $pid = ezd_pismo_create([
        'sprawa_id'   => $sprawa_id,
        'kierunek'    => 'wychodzace',
        'title'       => trim((string)($d['title'] ?? 'Pismo do wolontariusza')),
        'tresc'       => (string)($d['tresc'] ?? ''),
        'nadawca'     => '',
        'odbiorca'    => trim((string)($d['odbiorca'] ?? '')),
        'data_pisma'  => date('Y-m-d'),
        'data_wplywu' => null,
        'data_wysylki'=> date('Y-m-d'),
        'status'      => 'zakonczone',
        'owner_id'    => $user_id ?: null,
    ], $user_id ?: 0);
    if (!empty($d['file_path']) && is_file($d['file_path'])) {
        try { ezd_attach_path($d['file_path'], $d['file_name'] ?? basename($d['file_path']), $sprawa_id, $pid, $user_id ?: 0); }
        catch (\Throwable $e) {}
    }
    return $pid;
}

// ── Dekretacja ───────────────────────────────────────────────────────────────

function ezd_dekretacje_by_sprawa(int $sprawa_id): array {
    return db_all(
        "SELECT d.*, z.name AS zlecajacy_name, w.name AS wykonawca_name
         FROM ezd_dekretacje d
         LEFT JOIN users z ON z.id=d.zlecajacy_id
         LEFT JOIN users w ON w.id=d.wykonawca_id
         WHERE d.sprawa_id=?
         ORDER BY d.created_at DESC", [$sprawa_id]
    );
}

function ezd_dekretacja_create(array $d, int $user_id): int {
    // unit_id może nie istnieć w starych bazach (dodane przez org.php migration), próbuj z fallback
    try {
        db()->prepare(
            "INSERT INTO ezd_dekretacje (sprawa_id,pismo_id,umowa_id,zlecajacy_id,wykonawca_id,unit_id,dyspozycja,tresc,deadline)
             VALUES (:sid,:pid,:uid2,:zl,:wyk,:unit,:dys,:tr,:dl)"
        )->execute([
            ':sid'  => $d['sprawa_id'] ?: null,   ':pid'  => $d['pismo_id'] ?: null,
            ':uid2' => $d['umowa_id']  ?: null,   ':zl'   => $user_id,
            ':wyk'  => (int)$d['wykonawca_id'],   ':unit' => $d['unit_id'] ?: null,
            ':dys'  => $d['dyspozycja'] ?? 'do_zalat',
            ':tr'   => $d['tresc']     ?? '',      ':dl'   => $d['deadline'] ?: null,
        ]);
    } catch (\Throwable $e) {
        // Fallback bez unit_id (kolumna jeszcze nie istnieje)
        db()->prepare(
            "INSERT INTO ezd_dekretacje (sprawa_id,pismo_id,umowa_id,zlecajacy_id,wykonawca_id,dyspozycja,tresc,deadline)
             VALUES (:sid,:pid,:uid2,:zl,:wyk,:dys,:tr,:dl)"
        )->execute([
            ':sid'  => $d['sprawa_id'] ?: null,   ':pid'  => $d['pismo_id'] ?: null,
            ':uid2' => $d['umowa_id']  ?: null,   ':zl'   => $user_id,
            ':wyk'  => (int)$d['wykonawca_id'],   ':dys'  => $d['dyspozycja'] ?? 'do_zalat',
            ':tr'   => $d['tresc']     ?? '',      ':dl'   => $d['deadline'] ?: null,
        ]);
    }
    $id = (int)db()->lastInsertId();
    ezd_log(null, $d['sprawa_id'] ?: null, $d['pismo_id'] ?: null, $d['umowa_id'] ?: null,
            $user_id, 'dekretacja_create', EZD_DYSPOZYCJE[$d['dyspozycja'] ?? 'do_zalat'] . ' → #' . $d['wykonawca_id']);

    // Workflow BPM: dyspozycja przesuwa etap obiegu do przodu (nigdy wstecz) — wg workflow JRWA sprawy
    if (!empty($d['sprawa_id'])) {
        $dysp = $d['dyspozycja'] ?? 'do_zalat';
        $sp = ezd_sprawa_get((int)$d['sprawa_id']);
        if ($sp && $sp['status'] !== 'closed') {
            $steps = ezd_sprawa_workflow($sp);
            $keys  = array_column($steps, 'key');
            $target = null;
            foreach ($steps as $st) { if (($st['dyspozycja'] ?? '') === $dysp) { $target = $st['key']; break; } }
            if ($target) {
                $curIdx = array_search($sp['etap'] ?: ($keys[0] ?? ''), $keys, true);
                $tgtIdx = array_search($target, $keys, true);
                if ($tgtIdx !== false && ($curIdx === false || $tgtIdx > $curIdx)) {
                    try { ezd_sprawa_set_etap((int)$d['sprawa_id'], $target, $user_id); } catch (\Throwable $e) {}
                }
            }
        }
    }
    return $id;
}

function ezd_dekretacja_complete(int $id, int $user_id): void {
    db()->prepare("UPDATE ezd_dekretacje SET status='zakonczone',completed_at=datetime('now') WHERE id=?")->execute([$id]);
    ezd_log(null, null, null, null, $user_id, 'dekretacja_done', 'Zamknięto dekretację #' . $id);
}

// ── Załączniki ───────────────────────────────────────────────────────────────

function ezd_zalaczniki_by(int $sprawa_id, ?int $pismo_id = null, ?int $umowa_id = null, ?int $dokument_id = null): array {
    if ($pismo_id) {
        return db_all("SELECT z.*,u.name AS uploader FROM ezd_zalaczniki z LEFT JOIN users u ON u.id=z.uploaded_by WHERE z.pismo_id=? ORDER BY z.uploaded_at DESC", [$pismo_id]);
    }
    if ($umowa_id) {
        return db_all("SELECT z.*,u.name AS uploader FROM ezd_zalaczniki z LEFT JOIN users u ON u.id=z.uploaded_by WHERE z.umowa_id=? ORDER BY z.uploaded_at DESC", [$umowa_id]);
    }
    if ($dokument_id) {
        return db_all("SELECT z.*,u.name AS uploader FROM ezd_zalaczniki z LEFT JOIN users u ON u.id=z.uploaded_by WHERE z.dokument_id=? ORDER BY z.uploaded_at DESC", [$dokument_id]);
    }
    return db_all("SELECT z.*,u.name AS uploader FROM ezd_zalaczniki z LEFT JOIN users u ON u.id=z.uploaded_by WHERE z.sprawa_id=? ORDER BY z.uploaded_at DESC", [$sprawa_id]);
}

function ezd_upload(string $field, int $sprawa_id, int $user_id, ?int $pismo_id = null, ?int $umowa_id = null, ?int $dokument_id = null, ?int $replace_id = null, ?int $grupa_id = null, ?string $custom_name = null, ?int &$out_id = null): ?string {
    if (empty($_FILES[$field]['tmp_name'])) return 'Nie wybrano pliku.';
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return 'Błąd przesyłania (kod: ' . $f['error'] . ').';
    if ($f['size'] > EZD_MAX_SIZE)     return 'Plik za duży (maks. 25 MB).';
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EZD_ALLOWED_EXT, true)) return 'Niedozwolony format pliku.';

    // Nazwa wyświetlana — własna (jeśli podano) lub oryginalna nazwa pliku.
    // Zawsze zachowujemy rzeczywiste rozszerzenie pliku.
    $orig_name = $f['name'];
    if ($custom_name !== null && trim($custom_name) !== '') {
        $cn = str_replace(['/', '\\', "\0"], '', trim($custom_name));
        $cn = trim(preg_replace('/\s+/', ' ', $cn));
        if ($cn !== '') {
            if (strtolower(pathinfo($cn, PATHINFO_EXTENSION)) !== $ext) $cn .= '.' . $ext;
            $orig_name = mb_substr($cn, 0, 255);
        }
    }

    $dir = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $sprawa_id . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $stored)) return 'Nie udało się zapisać pliku.';

    $wersja = 1;
    if ($replace_id) {
        $prev = db_one("SELECT wersja FROM ezd_zalaczniki WHERE id=?", [$replace_id]);
        $wersja = ($prev['wersja'] ?? 0) + 1;
    }

    db()->prepare(
        "INSERT INTO ezd_zalaczniki (sprawa_id,pismo_id,umowa_id,dokument_id,grupa_id,filename,original_name,mime_type,file_size,wersja,prev_id,uploaded_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([$sprawa_id, $pismo_id, $umowa_id, $dokument_id, $grupa_id ?: null, $stored, $orig_name, $f['type'] ?: 'application/octet-stream', $f['size'], $wersja, $replace_id ?: null, $user_id]);
    $new_zal_id = (int)db()->lastInsertId();
    $out_id     = $new_zal_id;

    ezd_log(null, $sprawa_id, $pismo_id, $umowa_id, $user_id, 'upload', 'Wgrano plik: ' . $orig_name . " (v$wersja)");

    // Synchronizacja z SharePoint (folder sprawy) w tle — błąd nie blokuje uploadu.
    try { ezd_sp_sync_attachment($new_zal_id); } catch (\Throwable $e) {}

    return null;
}

// Szablony pustych plików do funkcji "Nowy plik" (repozytorium sprawy).
const EZD_OFFICE_TEMPLATES = [
    'docx' => ['file' => 'ezd_blank.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'label' => 'Nowy dokument'],
    'xlsx' => ['file' => 'ezd_blank.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',       'label' => 'Nowy arkusz'],
];

/** Buduje pusty .docx z nagłówkiem strony (prawy górny róg) zawierającym znak sprawy. */
function _ezd_build_docx_with_header(string $path, string $headerText): void {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $phpWord->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
    $section = $phpWord->addSection();
    $header  = $section->addHeader();
    $header->addText(htmlspecialchars($headerText, ENT_QUOTES, 'UTF-8'), ['size' => 9, 'color' => '555555'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::END]);
    $section->addText('');
    \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($path);
}

/** Buduje pusty .xlsx z nagłówkiem wydruku (prawa sekcja — prawy górny róg) zawierającym znak sprawy. */
function _ezd_build_xlsx_with_header(string $path, string $headerText): void {
    // Kod &R = prawa sekcja nagłówka wydruku Excela; && to literalny znak & w tym języku.
    $safe = str_replace('&', '&&', $headerText);
    $safe = htmlspecialchars($safe, ENT_QUOTES | ENT_XML1, 'UTF-8');

    $zip = new \ZipArchive();
    $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Arkusz1" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData/><headerFooter><oddHeader>&amp;R' . $safe . '</oddHeader></headerFooter></worksheet>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:creator>feerSZO</dc:creator></cp:coreProperties>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>feerSZO</Application></Properties>');
    $zip->close();
}

/**
 * Tworzy nowy, pusty plik Word/Excel w repozytorium sprawy pod podaną nazwą —
 * domyślnie z gotowego szablonu, a gdy $with_znak, ze świeżo zbudowanym
 * nagłówkiem strony zawierającym znak sprawy (prawy górny róg). Synchronizuje
 * z SharePoint w tle, żeby dało się go od razu otworzyć do edycji w Office Online.
 * @return array{ok:bool,error:?string,id:?int}
 */
function ezd_new_office_file(int $sprawa_id, int $user_id, string $type, string $name, ?int $grupa_id = null, bool $with_znak = false): array {
    if (!isset(EZD_OFFICE_TEMPLATES[$type])) {
        return ['ok' => false, 'error' => 'Nieprawidłowy typ pliku.', 'id' => null];
    }
    $tpl = EZD_OFFICE_TEMPLATES[$type];

    $name = str_replace(['/', '\\', "\0"], '', $name);
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === $type) {
        $name = pathinfo($name, PATHINFO_FILENAME);
    }
    if ($name === '') $name = $tpl['label'];
    $orig_name = mb_substr($name, 0, 200) . '.' . $type;

    $dir = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $sprawa_id . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $type;
    $dest   = $dir . $stored;

    if ($with_znak) {
        $sprawa = ezd_sprawa_get($sprawa_id);
        $znak   = 'Znak sprawy: ' . ($sprawa['znak_sprawy'] ?? '');
        try {
            if ($type === 'docx') _ezd_build_docx_with_header($dest, $znak);
            else _ezd_build_xlsx_with_header($dest, $znak);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Nie udało się zbudować pliku z nagłówkiem: ' . $e->getMessage(), 'id' => null];
        }
    } else {
        $tpl_path = __DIR__ . '/templates/' . $tpl['file'];
        if (!is_file($tpl_path)) {
            return ['ok' => false, 'error' => 'Brak szablonu pliku na serwerze.', 'id' => null];
        }
        if (!copy($tpl_path, $dest)) {
            return ['ok' => false, 'error' => 'Nie udało się utworzyć pliku.', 'id' => null];
        }
    }

    db()->prepare(
        "INSERT INTO ezd_zalaczniki (sprawa_id,grupa_id,filename,original_name,mime_type,file_size,uploaded_by)
         VALUES (?,?,?,?,?,?,?)"
    )->execute([$sprawa_id, $grupa_id ?: null, $stored, $orig_name, $tpl['mime'], filesize($dir . $stored), $user_id]);
    $new_id = (int)db()->lastInsertId();

    ezd_log(null, $sprawa_id, null, null, $user_id, 'new_file', 'Utworzono nowy plik: ' . $orig_name);

    // Synchronizacja z SharePoint (folder sprawy) w tle — błąd nie blokuje utworzenia pliku.
    try { ezd_sp_sync_attachment($new_id); } catch (\Throwable $e) {}

    return ['ok' => true, 'error' => null, 'id' => $new_id];
}

function ezd_zal_get(int $id): ?array {
    return db_one("SELECT * FROM ezd_zalaczniki WHERE id=?", [$id]);
}

/**
 * Zwraca adres do otwarcia pliku Word (.doc/.docx) w Office Word Online.
 * Przy 1. wywołaniu wysyła plik na skonfigurowaną witrynę SharePoint (patrz
 * admin/sp_onboarding.php) i zapamiętuje zwrócony przez Graph API webUrl —
 * kolejne otwarcia tej samej wersji pliku używają już zapamiętanego adresu.
 * @return array{ok:bool,error:?string,url:?string}
 */
/** Usuwa znaki niedozwolone w nazwach plików/folderów SharePoint. */
function _ezd_sp_sanitize(string $s): string {
    $s = str_replace(['"', '*', ':', '<', '>', '?', '\\', '|', '/'], '-', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s), " ./");
    return $s !== '' ? $s : 'bez-nazwy';
}

/**
 * Ścieżka folderu sprawy na SharePoint: cases/{symbol JRWA}/{znak sprawy + tytuł}.
 * Współdzielona przez wszystkie pliki sprawy — repozytorium sprawy i pliki
 * dołączone do jej pism/umów/dokumentów trafiają do tego samego folderu.
 */
function ezd_sprawa_sp_folder(int $sprawa_id): ?string {
    $row = db_one(
        "SELECT s.znak_sprawy, s.title, s.created_at, j.symbol AS jrwa_symbol
         FROM ezd_sprawy s
         JOIN ezd_teczki t ON t.id = s.teczka_id
         LEFT JOIN ezd_jrwa j ON j.id = t.jrwa_id
         WHERE s.id = ?", [$sprawa_id]
    );
    if (!$row) return null;
    $jrwa = _ezd_sp_sanitize($row['jrwa_symbol'] ?: 'bez-JRWA');
    // Nazwa folderu sprawy: „Tytuł sprawy - data wszczęcia (znak)" — znak sprawy
    // (unikalny) na końcu zabezpiecza przed kolizją folderów o tym samym tytule i dacie.
    $data = $row['created_at'] ? substr($row['created_at'], 0, 10) : '';
    $name = trim($row['title'])
          . ($data !== '' ? ' - ' . $data : '')
          . ($row['znak_sprawy'] ? ' (' . $row['znak_sprawy'] . ')' : '');
    $case = _ezd_sp_sanitize($name);
    return 'cases/' . $jrwa . '/' . $case;
}

/**
 * Wysyła załącznik na SharePoint do folderu jego sprawy (patrz ezd_sprawa_sp_folder)
 * i zapamiętuje webUrl/drive_id/item_id na rekordzie. Bezpieczna do wywołania
 * „w tle" (fire-and-forget) po każdym wgraniu pliku — nigdy nie zgłasza wyjątku,
 * błąd wraca w tablicy wyniku.
 * @return array{ok:bool,error:?string,url:?string}
 */
function ezd_sp_sync_attachment(int $zal_id): array {
    $z = ezd_zal_get($zal_id);
    if (!$z) return ['ok' => false, 'error' => 'Nie znaleziono pliku.', 'url' => null];

    require_once __DIR__ . '/m365.php';

    if (m365_setting('sp_enabled') !== '1') {
        return ['ok' => false, 'error' => 'Synchronizacja z SharePoint nie jest włączona (Admin → SharePoint).', 'url' => null];
    }
    $site_url = m365_setting('sp_site_url');
    if (!$site_url) {
        return ['ok' => false, 'error' => 'Brak skonfigurowanej witryny SharePoint (Admin → SharePoint).', 'url' => null];
    }
    $folder = ezd_sprawa_sp_folder((int)$z['sprawa_id']);
    if (!$folder) {
        return ['ok' => false, 'error' => 'Nie udało się wyznaczyć folderu sprawy na SharePoint.', 'url' => null];
    }
    $local_path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/' . $z['filename'];
    if (!is_file($local_path)) {
        return ['ok' => false, 'error' => 'Plik nie istnieje na serwerze.', 'url' => null];
    }

    try {
        $graph = new M365Graph();
        if (!$graph->is_configured()) {
            return ['ok' => false, 'error' => 'Integracja Microsoft 365 nie jest skonfigurowana.', 'url' => null];
        }
        $library     = m365_setting('sp_library') ?: '';
        $base_folder = trim(m365_setting('sp_base_folder'), '/');
        $sp_path     = ($base_folder !== '' ? $base_folder . '/' : '') . $folder . '/' . $z['original_name'];

        $site_id  = $graph->sp_site_id($site_url);
        $drive_id = $graph->sp_drive_id($site_id, $library);
        $item     = $graph->sp_upload_file($site_id, $drive_id, $sp_path, $local_path);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Błąd SharePoint: ' . $e->getMessage(), 'url' => null];
    }

    $web_url = $item['webUrl'] ?? null;
    $item_id = $item['id'] ?? null;
    if (!$web_url || !$item_id) {
        return ['ok' => false, 'error' => 'SharePoint nie zwrócił adresu dokumentu.', 'url' => null];
    }

    db()->prepare("UPDATE ezd_zalaczniki SET sp_web_url=?, sp_synced_at=CURRENT_TIMESTAMP, sp_drive_id=?, sp_item_id=? WHERE id=?")
        ->execute([$web_url, $drive_id, $item_id, $zal_id]);

    return ['ok' => true, 'error' => null, 'url' => $web_url];
}

function ezd_office_online_url(int $zal_id, int $user_id): array {
    $z = ezd_zal_get($zal_id);
    if (!$z) return ['ok' => false, 'error' => 'Nie znaleziono pliku.', 'url' => null];

    $ext = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EZD_OFFICE_ONLINE_EXT, true)) {
        return ['ok' => false, 'error' => 'Otwieranie w Office Online jest dostępne tylko dla plików Word/Excel (doc, docx, xls, xlsx).', 'url' => null];
    }

    if (!empty($z['sp_web_url'])) {
        return ['ok' => true, 'error' => null, 'url' => $z['sp_web_url']];
    }

    $r = ezd_sp_sync_attachment($zal_id);
    if ($r['ok']) {
        ezd_log(null, (int)$z['sprawa_id'], $z['pismo_id'] ?: null, $z['umowa_id'] ?: null, $user_id, 'office_online',
            'Otwarto w Word Online: ' . $z['original_name']);
    }
    return $r;
}

/**
 * Ściąga aktualną treść pliku z SharePoint (po edycji w Office Online) i zapisuje
 * ją albo jako NOWĄ WERSJĘ załącznika ($mode='version', domyślnie — oryginał
 * zostaje zachowany, analogicznie do ponownego wgrania pliku), albo NADPISUJE
 * istniejący plik w miejscu ($mode='replace' — bez tworzenia nowego wpisu,
 * bez zachowania poprzedniej treści, nieodwracalne).
 * Wymaga, by dokument był już wcześniej otwarty w Office Online (ma sp_item_id).
 * @return array{ok:bool,error:?string,id:?int,mode:?string}
 */
function ezd_office_online_pull(int $zal_id, int $user_id, string $mode = 'version'): array {
    if (!in_array($mode, ['version', 'replace'], true)) $mode = 'version';

    $z = ezd_zal_get($zal_id);
    if (!$z) return ['ok' => false, 'error' => 'Nie znaleziono pliku.', 'id' => null, 'mode' => null];
    if (empty($z['sp_item_id']) || empty($z['sp_drive_id'])) {
        return ['ok' => false, 'error' => 'Dokument nie był jeszcze otwarty w Office Online.', 'id' => null, 'mode' => null];
    }

    require_once __DIR__ . '/m365.php';
    try {
        $graph   = new M365Graph();
        $content = $graph->sp_download_file($z['sp_drive_id'], $z['sp_item_id']);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Błąd SharePoint: ' . $e->getMessage(), 'id' => null, 'mode' => null];
    }

    $dir = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // Bez zmian od ostatniego zapisu — nie twórz pustej wersji / nie dotykaj pliku
    // (ważne przy automatycznej synchronizacji po zamknięciu edytora, gdy dokument
    // był tylko otwarty do podglądu).
    $current_path = $dir . $z['filename'];
    if (is_file($current_path) && hash_equals(hash_file('sha256', $current_path), hash('sha256', $content))) {
        return ['ok' => true, 'error' => null, 'id' => $zal_id, 'mode' => null, 'unchanged' => true];
    }

    if ($mode === 'replace') {
        // Nadpisuje istniejący plik pod tą samą, dotychczasową nazwą przechowywania.
        if (file_put_contents($dir . $z['filename'], $content) === false) {
            return ['ok' => false, 'error' => 'Nie udało się zapisać pliku.', 'id' => null, 'mode' => null];
        }
        db()->prepare("UPDATE ezd_zalaczniki SET file_size=?, uploaded_by=?, uploaded_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([strlen($content), $user_id, $zal_id]);

        ezd_log(null, (int)$z['sprawa_id'], $z['pismo_id'] ?: null, $z['umowa_id'] ?: null, $user_id, 'office_online_pull_replace',
            'Zastąpiono oryginalny plik zmianami z Office Online: ' . $z['original_name']);

        return ['ok' => true, 'error' => null, 'id' => $zal_id, 'mode' => 'replace'];
    }

    $ext    = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (file_put_contents($dir . $stored, $content) === false) {
        return ['ok' => false, 'error' => 'Nie udało się zapisać pliku.', 'id' => null, 'mode' => null];
    }

    db()->prepare(
        "INSERT INTO ezd_zalaczniki (sprawa_id,pismo_id,umowa_id,dokument_id,grupa_id,filename,original_name,mime_type,file_size,wersja,prev_id,uploaded_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $z['sprawa_id'], $z['pismo_id'], $z['umowa_id'], $z['dokument_id'], $z['grupa_id'],
        $stored, $z['original_name'], $z['mime_type'], strlen($content), (int)$z['wersja'] + 1, $zal_id, $user_id,
    ]);
    $new_id = (int)db()->lastInsertId();

    ezd_log(null, (int)$z['sprawa_id'], $z['pismo_id'] ?: null, $z['umowa_id'] ?: null, $user_id, 'office_online_pull',
        'Zapisano zmiany z Office Online jako nową wersję: ' . $z['original_name'] . " (v" . ((int)$z['wersja'] + 1) . ")");

    return ['ok' => true, 'error' => null, 'id' => $new_id, 'mode' => 'version'];
}

/**
 * Konwertuje plik Word/Excel (doc/docx/xls/xlsx) na PDF przez Microsoft Graph
 * (?format=pdf) i zapisuje wynik jako nowy, osobny załącznik w tej samej grupie
 * plików — obok oryginału (nie jako jego nowa wersja, bo to inny format).
 * Wymaga synchronizacji z SharePoint; jeśli plik nie był jeszcze wysłany, wysyła go najpierw.
 * @return array{ok:bool,error:?string,id:?int}
 */
function ezd_convert_to_pdf(int $zal_id, int $user_id): array {
    $z = ezd_zal_get($zal_id);
    if (!$z) return ['ok' => false, 'error' => 'Nie znaleziono pliku.', 'id' => null];

    $ext = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EZD_PDF_CONVERTIBLE_EXT, true)) {
        return ['ok' => false, 'error' => 'Konwersja na PDF jest dostępna tylko dla plików Word/Excel (doc, docx, xls, xlsx).', 'id' => null];
    }

    if (empty($z['sp_drive_id']) || empty($z['sp_item_id'])) {
        $sync = ezd_sp_sync_attachment($zal_id);
        if (!$sync['ok']) return ['ok' => false, 'error' => $sync['error'] ?: 'Nie udało się wysłać pliku na SharePoint.', 'id' => null];
        $z = ezd_zal_get($zal_id);
    }

    require_once __DIR__ . '/m365.php';
    try {
        $graph   = new M365Graph();
        $content = $graph->sp_download_file_as_pdf($z['sp_drive_id'], $z['sp_item_id']);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Błąd konwersji: ' . $e->getMessage(), 'id' => null];
    }

    $dir = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
    if (file_put_contents($dir . $stored, $content) === false) {
        return ['ok' => false, 'error' => 'Nie udało się zapisać pliku PDF.', 'id' => null];
    }
    $pdf_name = pathinfo($z['original_name'], PATHINFO_FILENAME) . '.pdf';

    db()->prepare(
        "INSERT INTO ezd_zalaczniki (sprawa_id,pismo_id,umowa_id,dokument_id,grupa_id,filename,original_name,mime_type,file_size,wersja,prev_id,converted_from_id,uploaded_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $z['sprawa_id'], $z['pismo_id'], $z['umowa_id'], $z['dokument_id'], $z['grupa_id'],
        $stored, $pdf_name, 'application/pdf', strlen($content), 1, null, $zal_id, $user_id,
    ]);
    $new_id = (int)db()->lastInsertId();

    ezd_log(null, (int)$z['sprawa_id'], $z['pismo_id'] ?: null, $z['umowa_id'] ?: null, $user_id, 'convert_pdf',
        'Przekonwertowano na PDF: ' . $z['original_name'] . ' → ' . $pdf_name);

    return ['ok' => true, 'error' => null, 'id' => $new_id];
}

/**
 * "Spinacz" — łączy 2+ wgranych plików (Word/Excel są najpierw konwertowane na PDF
 * przez SharePoint/Graph) w JEDEN plik PDF i dodaje go do repozytorium koszulki.
 * Pliki wejściowe i pośrednie konwersje są tworzone jako tymczasowe załączniki na
 * czas operacji (żeby odtworzyć istniejące ścieżki uploadu/konwersji), a po scaleniu
 * usuwane — w koszulce zostaje tylko wynikowy PDF. Cała operacja jest atomowa: błąd
 * na którymkolwiek etapie usuwa wszystko, co powstało do tego momentu.
 * @param string $files_field nazwa pola $_FILES z tablicą plików (np. <input name="files[]" multiple>)
 * @return array{ok:bool,error:?string,id:?int}
 */
/** Ścieżka do binarki qpdf (jeśli dostępna na serwerze) — cache na czas żądania. */
function _ezd_qpdf_bin(): ?string {
    static $bin = false;
    if ($bin !== false) return $bin;
    $bin = null;
    if (function_exists('exec')) {
        $out = []; $rc = 1;
        @exec('command -v qpdf 2>/dev/null', $out, $rc);
        if ($rc === 0 && !empty($out[0])) $bin = trim($out[0]);
    }
    return $bin;
}

/**
 * Scala pliki PDF (ścieżki na dysku, w podanej kolejności) w jeden plik $out.
 * Preferuje qpdf — obsługuje KAŻDĄ wersję PDF, w tym skompresowany cross-reference
 * (PDF 1.5+, jaki generują Word/Excel/Chrome/LibreOffice „zapisz jako PDF").
 * Gdy qpdf niedostępny, używa FPDI (tylko PDF ≤1.4) i rzuca czytelny komunikat,
 * jeśli natrafi na nowoczesną kompresję.
 * @throws \RuntimeException
 */
function ezd_merge_pdf_files(array $paths, string $out): void {
    $paths = array_values(array_filter($paths, 'is_file'));
    if (count($paths) < 2) throw new \RuntimeException('Za mało dostępnych plików PDF do scalenia.');

    $qpdf = _ezd_qpdf_bin();
    if ($qpdf) {
        $args = implode(' ', array_map('escapeshellarg', $paths));
        $cmd  = escapeshellarg($qpdf) . ' --warning-exit-0 --empty --pages ' . $args . ' -- ' . escapeshellarg($out) . ' 2>&1';
        $o = []; $rc = 1; @exec($cmd, $o, $rc);
        if ($rc === 0 && is_file($out) && filesize($out) > 0) return;
        // qpdf zawiódł (np. plik zaszyfrowany) — spróbuj jeszcze FPDI poniżej.
        if (is_file($out)) @unlink($out);
    }

    require_once dirname(__DIR__) . '/includes/fpdf/fpdf.php';
    require_once dirname(__DIR__) . '/includes/fpdi/autoload_fpdi.php';
    $pdf = new \setasign\Fpdi\Fpdi();
    foreach ($paths as $path) {
        try {
            $cnt = $pdf->setSourceFile($path);
            for ($p = 1; $p <= $cnt; $p++) {
                $tpl = $pdf->importPage($p);
                $sz  = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($sz['width'] > $sz['height'] ? 'L' : 'P', [$sz['width'], $sz['height']]);
                $pdf->useTemplate($tpl);
            }
        } catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
            throw new \RuntimeException('Plik „' . basename($path) . '" używa nowoczesnej kompresji PDF (1.5+), której nie obsługuje wbudowany parser. Na serwerze potrzebny jest qpdf (dodany do obrazu Docker) — po wdrożeniu aktualizacji scalanie zadziała dla wszystkich plików.');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Nie udało się odczytać pliku „' . basename($path) . '": ' . $e->getMessage());
        }
    }
    $pdf->Output('F', $out);
}

function ezd_spinacz_merge(int $sprawa_id, int $user_id, string $files_field, ?string $custom_name = null, ?int $grupa_id = null): array {
    $f = $_FILES[$files_field] ?? null;
    if (!$f || empty($f['name']) || !is_array($f['name'])) {
        return ['ok' => false, 'error' => 'Nie wybrano plików.', 'id' => null];
    }

    $allowed  = array_merge(['pdf'], EZD_PDF_CONVERTIBLE_EXT);
    $n_files  = 0;
    $need_convert = false;
    foreach ($f['error'] as $i => $err) {
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $n_files++;
        $ext = strtolower(pathinfo($f['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return ['ok' => false, 'error' => 'Niedozwolony format pliku: ' . $f['name'][$i] . ' (Spinacz przyjmuje PDF, DOC, DOCX, XLS, XLSX).', 'id' => null];
        }
        if (in_array($ext, EZD_PDF_CONVERTIBLE_EXT, true)) $need_convert = true;
    }
    if ($n_files < 2) return ['ok' => false, 'error' => 'Wybierz co najmniej 2 pliki do połączenia.', 'id' => null];

    require_once __DIR__ . '/m365.php';
    if ($need_convert && (m365_setting('sp_enabled') !== '1' || !(new M365Graph())->is_configured())) {
        return ['ok' => false, 'error' => 'Konwersja plików Word/Excel na PDF wymaga włączonej integracji z SharePoint (Admin → SharePoint).', 'id' => null];
    }

    // Usuwa tymczasowy załącznik (plik + wpis, bez logowania każdego z osobna — patrz jeden zbiorczy wpis niżej)
    // i best-effort usuwa też jego kopię z SharePoint, jeśli została zsynchronizowana.
    $cleanup = function (array $ids): void {
        foreach ($ids as $zid) {
            $z = ezd_zal_get($zid);
            if (!$z) continue;
            if (!empty($z['sp_drive_id']) && !empty($z['sp_item_id'])) {
                try { (new M365Graph())->sp_delete_item($z['sp_drive_id'], $z['sp_item_id']); } catch (\Throwable $e) {}
            }
            $path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $z['sprawa_id'] . '/' . $z['filename'];
            if (is_file($path)) @unlink($path);
            db()->prepare("DELETE FROM ezd_zalaczniki WHERE id=?")->execute([$zid]);
        }
    };

    $created   = []; // wszystkie tymczasowe załączniki (oryginały + konwersje PDF) — do usunięcia na końcu
    $merge_ids = []; // id załączników PDF do scalenia, w kolejności wgrania
    $names     = [];

    foreach ($f['error'] as $i => $err) {
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) {
            $cleanup($created);
            return ['ok' => false, 'error' => 'Błąd przesyłania pliku: ' . $f['name'][$i], 'id' => null];
        }

        $ext = strtolower(pathinfo($f['name'][$i], PATHINFO_EXTENSION));

        $_FILES['_spinacz_tmp'] = [
            'name' => $f['name'][$i], 'type' => $f['type'][$i] ?? '',
            'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i],
        ];
        $orig_id = null;
        $err_msg = ezd_upload('_spinacz_tmp', $sprawa_id, $user_id, null, null, null, null, null, null, $orig_id);
        unset($_FILES['_spinacz_tmp']);
        if ($err_msg) { $cleanup($created); return ['ok' => false, 'error' => $err_msg, 'id' => null]; }
        $created[] = $orig_id;
        $names[]   = $f['name'][$i];

        if (in_array($ext, EZD_PDF_CONVERTIBLE_EXT, true)) {
            $conv = ezd_convert_to_pdf($orig_id, $user_id);
            if (!$conv['ok']) {
                $cleanup($created);
                return ['ok' => false, 'error' => 'Nie udało się przekonwertować pliku ' . $f['name'][$i] . ' na PDF: ' . $conv['error'], 'id' => null];
            }
            $created[]   = $conv['id'];
            $merge_ids[] = $conv['id'];
        } else {
            $merge_ids[] = $orig_id;
        }
    }

    if (count($merge_ids) < 2) { $cleanup($created); return ['ok' => false, 'error' => 'Wybierz co najmniej 2 pliki do połączenia.', 'id' => null]; }

    // Ścieżki plików PDF do scalenia (w kolejności wgrania)
    $merge_paths = [];
    foreach ($merge_ids as $mid) {
        $mz = ezd_zal_get($mid);
        if (!$mz) continue;
        $merge_paths[] = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $mz['sprawa_id'] . '/' . $mz['filename'];
    }

    // ── Nazwa i miejsce docelowe ─────────────────────────────────────────────
    $dir = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $sprawa_id . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $stored  = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
    $dest    = $dir . $stored;

    $final_name = trim((string)$custom_name);
    if ($final_name !== '') {
        $final_name = str_replace(['/', '\\', "\0"], '', $final_name);
        $final_name = trim(preg_replace('/\s+/', ' ', $final_name));
        if (strtolower(pathinfo($final_name, PATHINFO_EXTENSION)) !== 'pdf') $final_name .= '.pdf';
        $final_name = mb_substr($final_name, 0, 255);
    }
    if ($final_name === '') $final_name = 'Spinacz_' . date('Y-m-d_His') . '.pdf';

    // ── Scalanie (qpdf → FPDI) prosto do pliku ───────────────────────────────
    try {
        ezd_merge_pdf_files($merge_paths, $dest);
    } catch (\Throwable $e) {
        if (is_file($dest)) @unlink($dest);
        $cleanup($created);
        return ['ok' => false, 'error' => 'Nie udało się połączyć plików w PDF: ' . $e->getMessage(), 'id' => null];
    }
    if (!is_file($dest) || filesize($dest) === 0) {
        if (is_file($dest)) @unlink($dest);
        $cleanup($created);
        return ['ok' => false, 'error' => 'Nie udało się zapisać scalonego pliku PDF.', 'id' => null];
    }

    db()->prepare(
        "INSERT INTO ezd_zalaczniki (sprawa_id,pismo_id,umowa_id,dokument_id,grupa_id,filename,original_name,mime_type,file_size,wersja,prev_id,uploaded_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([$sprawa_id, null, null, null, $grupa_id ?: null, $stored, $final_name, 'application/pdf', filesize($dest), 1, null, $user_id]);
    $merged_id = (int)db()->lastInsertId();

    ezd_log(null, $sprawa_id, null, null, $user_id, 'spinacz',
        'Spinacz: połączono ' . count($merge_ids) . ' plik(ów) w „' . $final_name . '": ' . implode(', ', $names));

    // Porządki — usuń tymczasowe oryginały i pośrednie konwersje (patrz $cleanup powyżej).
    $cleanup($created);

    // Synchronizacja wynikowego PDF z SharePoint w tle — błąd nie blokuje operacji.
    try { ezd_sp_sync_attachment($merged_id); } catch (\Throwable $e) {}

    return ['ok' => true, 'error' => null, 'id' => $merged_id];
}

function ezd_zal_delete(int $id, int $user_id): void {
    $z = ezd_zal_get($id);
    if (!$z) return;
    $path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $z['sprawa_id'] . '/' . $z['filename'];
    if (is_file($path)) unlink($path);
    db()->prepare("DELETE FROM ezd_zalaczniki WHERE id=?")->execute([$id]);
    ezd_log(null, $z['sprawa_id'], null, null, $user_id, 'del_attachment', 'Usunięto plik: ' . $z['original_name']);
}

// ── Przekaż dokumenty — dostęp do konkretnych plików, poza dostępem do sprawy ──

/** Przyznaje dostęp do wybranych plików wskazanej osobie. Zwraca liczbę nowych wpisów. */
function ezd_zal_access_grant(array $zalacznik_ids, int $user_id, int $granted_by, string $note = ''): int {
    $note = trim($note);
    $n = 0;
    $stmt = db()->prepare(
        "INSERT OR IGNORE INTO ezd_zalacznik_access (zalacznik_id,user_id,granted_by,note) VALUES (?,?,?,?)"
    );
    foreach ($zalacznik_ids as $zid) {
        $zid = (int)$zid;
        if (!$zid) continue;
        $z = ezd_zal_get($zid);
        if (!$z) continue;
        $stmt->execute([$zid, $user_id, $granted_by, $note]);
        if (db()->lastInsertId()) {
            $n++;
            ezd_log(null, (int)$z['sprawa_id'], $z['pismo_id'] ?: null, $z['umowa_id'] ?: null, $granted_by,
                'zal_access_grant', 'Przekazano dostęp do pliku: ' . $z['original_name']);
        }
    }
    return $n;
}

/** Osoby, którym przekazano dostęp do danego pliku (poza dostępem do całej sprawy). */
function ezd_zal_access_list(int $zalacznik_id): array {
    return db_all(
        "SELECT a.*, u.name AS user_name, u.email AS user_email
         FROM ezd_zalacznik_access a JOIN users u ON u.id = a.user_id
         WHERE a.zalacznik_id = ? ORDER BY a.granted_at DESC",
        [$zalacznik_id]
    );
}

function ezd_zal_access_has(int $zalacznik_id, int $user_id): bool {
    return (bool) db_one(
        "SELECT 1 FROM ezd_zalacznik_access WHERE zalacznik_id=? AND user_id=?",
        [$zalacznik_id, $user_id]
    );
}

function ezd_zal_access_revoke(int $id, int $revoked_by): void {
    db()->prepare("DELETE FROM ezd_zalacznik_access WHERE id=?")->execute([$id]);
}

// ── Grupy plików w sprawie ───────────────────────────────────────────────────

function ezd_grupy_by_sprawa(int $sprawa_id): array {
    return db_all(
        "SELECT g.*, (SELECT COUNT(*) FROM ezd_zalaczniki z WHERE z.grupa_id=g.id) AS plik_count
         FROM ezd_grupy_plikow g WHERE g.sprawa_id=? ORDER BY g.sort_order, g.nazwa", [$sprawa_id]
    );
}

function ezd_grupa_get(int $id): ?array {
    return db_one("SELECT * FROM ezd_grupy_plikow WHERE id=?", [$id]);
}

function ezd_grupa_create(int $sprawa_id, string $nazwa, int $user_id): int {
    $nazwa = trim($nazwa);
    if ($nazwa === '') throw new \RuntimeException('Nazwa grupy jest wymagana.');
    db()->prepare("INSERT INTO ezd_grupy_plikow (sprawa_id,nazwa,created_by) VALUES (?,?,?)")
        ->execute([$sprawa_id, $nazwa, $user_id]);
    $gid = (int)db()->lastInsertId();
    ezd_log(null, $sprawa_id, null, null, $user_id, 'grupa_create', 'Utworzono grupę plików: ' . $nazwa);
    return $gid;
}

function ezd_grupa_rename(int $id, string $nazwa, int $user_id): void {
    $g = ezd_grupa_get($id);
    if (!$g) return;
    $nazwa = trim($nazwa);
    if ($nazwa === '') throw new \RuntimeException('Nazwa grupy jest wymagana.');
    db()->prepare("UPDATE ezd_grupy_plikow SET nazwa=? WHERE id=?")->execute([$nazwa, $id]);
    ezd_log(null, (int)$g['sprawa_id'], null, null, $user_id, 'grupa_rename', 'Zmieniono nazwę grupy #' . $id);
}

function ezd_grupa_delete(int $id, int $user_id): void {
    $g = ezd_grupa_get($id);
    if (!$g) return;
    // Pliki nie są usuwane — wracają do „bez grupy"
    db()->prepare("UPDATE ezd_zalaczniki SET grupa_id=NULL WHERE grupa_id=?")->execute([$id]);
    db()->prepare("DELETE FROM ezd_grupy_plikow WHERE id=?")->execute([$id]);
    ezd_log(null, (int)$g['sprawa_id'], null, null, $user_id, 'grupa_delete', 'Usunięto grupę plików: ' . $g['nazwa']);
}

function ezd_zal_set_grupa(int $zal_id, ?int $grupa_id, int $user_id): void {
    $z = ezd_zal_get($zal_id);
    if (!$z) return;
    // Walidacja: grupa musi należeć do tej samej sprawy
    if ($grupa_id) {
        $g = ezd_grupa_get($grupa_id);
        if (!$g || (int)$g['sprawa_id'] !== (int)$z['sprawa_id']) return;
    }
    db()->prepare("UPDATE ezd_zalaczniki SET grupa_id=? WHERE id=?")->execute([$grupa_id ?: null, $zal_id]);
    ezd_log(null, (int)$z['sprawa_id'], null, null, $user_id, 'zal_grupa', 'Przeniesiono plik do grupy');
}

// ── Audit log ────────────────────────────────────────────────────────────────

function ezd_log(?int $teczka_id, ?int $sprawa_id, ?int $pismo_id, ?int $umowa_id, int $user_id, string $action, string $details = ''): void {
    try {
        db()->prepare(
            "INSERT INTO ezd_log (teczka_id,sprawa_id,pismo_id,umowa_id,user_id,action,details,ip)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$teczka_id, $sprawa_id, $pismo_id, $umowa_id, $user_id, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Throwable $e) {}
}

function ezd_log_by_sprawa(int $sprawa_id, int $limit = 50): array {
    return db_all(
        "SELECT l.*, u.name AS user_name FROM ezd_log l
         LEFT JOIN users u ON u.id=l.user_id
         WHERE l.sprawa_id=?
         ORDER BY l.created_at DESC LIMIT ?",
        [$sprawa_id, $limit]
    );
}

// ── Timeline (Pisma + Umowy zmiksowane) ──────────────────────────────────────

function ezd_timeline(int $sprawa_id): array {
    $pisma = db_all(
        "SELECT 'pismo' AS _typ, id, sygnatura, title, kierunek AS sub_type,
                status, created_at, updated_at, owner_id
         FROM ezd_pisma WHERE sprawa_id=?", [$sprawa_id]
    );
    $umowy = db_all(
        "SELECT 'umowa' AS _typ, id, sygnatura, title, typ AS sub_type,
                status, created_at, updated_at, owner_id
         FROM ezd_umowy WHERE sprawa_id=?", [$sprawa_id]
    );
    $timeline = array_merge($pisma, $umowy);
    usort($timeline, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
    return $timeline;
}

// ── Stats ────────────────────────────────────────────────────────────────────

function ezd_stats(): array {
    return [
        'teczki_open'    => db_one("SELECT COUNT(*) AS c FROM ezd_teczki WHERE status='open'")['c']           ?? 0,
        'sprawy_open'    => db_one("SELECT COUNT(*) AS c FROM ezd_sprawy WHERE status IN ('open','in_progress')")['c'] ?? 0,
        'pisma_month'    => db_one("SELECT COUNT(*) AS c FROM ezd_pisma WHERE created_at >= date('now','start of month')")['c'] ?? 0,
        'umowy_active'   => db_one("SELECT COUNT(*) AS c FROM ezd_umowy WHERE status='aktywna'")['c']          ?? 0,
        'dekr_pending'   => db_one("SELECT COUNT(*) AS c FROM ezd_dekretacje WHERE status='oczekuje'")['c']    ?? 0,
    ];
}

// ── Helpery UI ───────────────────────────────────────────────────────────────

function ezd_status_badge_sprawa(string $status): string {
    $s = EZD_STATUSES_SPRAWA[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . h($s['label']) . '</span>';
}

function ezd_priority_badge(string $p): string {
    $pr = EZD_PRIORITIES[$p] ?? ['label' => $p, 'class' => 'secondary'];
    return '<span class="badge bg-' . $pr['class'] . ' bg-opacity-15 text-' . $pr['class'] . ' border border-' . $pr['class'] . '" style="font-size:.65rem">' . h($pr['label']) . '</span>';
}

/**
 * "Ile leży" — czas od podanej daty (created_at dekretacji) do teraz, po polsku.
 * Zwraca np. "dziś", "1 dzień", "3 dni", "2 tyg.". Kolor (klasa) rośnie z czasem:
 * do 3 dni — muted, do 7 — warning, powyżej — danger.
 * @return array{label:string,class:string,days:int}
 */
function ezd_lezy_since(?string $datetime): array {
    if (!$datetime) return ['label' => '—', 'class' => 'muted', 'days' => 0];
    $ts = strtotime($datetime);
    if (!$ts) return ['label' => '—', 'class' => 'muted', 'days' => 0];
    $days = (int)floor((time() - $ts) / 86400);
    if ($days <= 0)      $label = 'dziś';
    elseif ($days === 1) $label = '1 dzień';
    elseif ($days < 5)   $label = $days . ' dni';
    elseif ($days < 7)   $label = $days . ' dni';
    elseif ($days < 14)  $label = '1 tydz.';
    elseif ($days < 31)  $label = floor($days / 7) . ' tyg.';
    else                 $label = floor($days / 30) . ' mies.';
    $class = $days > 7 ? 'danger' : ($days > 3 ? 'warning' : 'muted');
    return ['label' => $label, 'class' => $class, 'days' => $days];
}

function ezd_filesize(int $b): string {
    if ($b < 1024)           return $b . ' B';
    if ($b < 1024 * 1024)   return round($b / 1024, 1) . ' KB';
    return round($b / (1024 * 1024), 1) . ' MB';
}

function ezd_file_icon(string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match(true) {
        $ext === 'pdf'                            => 'bi-file-earmark-pdf text-danger',
        in_array($ext, ['doc','docx','odt'])      => 'bi-file-earmark-word text-primary',
        in_array($ext, ['xls','xlsx','ods','csv'])=> 'bi-file-earmark-excel text-success',
        in_array($ext, ['png','jpg','jpeg','gif'])=> 'bi-file-earmark-image text-info',
        in_array($ext, ['zip'])                   => 'bi-file-earmark-zip text-warning',
        in_array($ext, ['eml','msg'])             => 'bi-envelope text-secondary',
        default                                    => 'bi-file-earmark text-secondary',
    };
}

function _ezd_check_sprawa_open(array $sprawa): void {
    if ($sprawa['status'] === 'closed' && !is_admin()) {
        throw new \RuntimeException('Koszulka jest zamknięta. Edycja zablokowana dla nieadministratorów.');
    }
}

// ── Wykrywanie i walidacja podpisu el. — wydzielone do includes/sigcheck.php ──
require_once __DIR__ . '/sigcheck.php';

// ── Archiwum zakładowe (spisy zdawczo-odbiorcze, brakowanie) ──────────────────
require_once __DIR__ . '/ezd_archiwum.php';

// ── Szablony pism / korespondencja seryjna ────────────────────────────────────
require_once __DIR__ . '/ezd_szablony.php';
