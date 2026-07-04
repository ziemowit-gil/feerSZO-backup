<?php
/**
 * Moduł EZD / Kancelaria — helpery DB + auto-migracja.
 *
 * Hierarchia: Teczka (JRWA) → Sprawa → Pismo / Umowa
 *             Każdy poziom: Załączniki, Dekretacje, Log
 */

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
        "CREATE INDEX IF NOT EXISTS idx_ezd_sprawy_ref     ON ezd_sprawy(ref_type, ref_id)",
    ] as $idx) {
        try { $pdo->exec($idx); } catch (\Throwable $e) {}
    }

    // Seed JRWA (tylko jeśli pusta)
    $cnt = $pdo->query("SELECT COUNT(*) FROM ezd_jrwa")->fetchColumn();
    if ((int)$cnt === 0) {
        $ins = $pdo->prepare("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order) VALUES (?,?,?,?,?)");
        foreach ([
            ['ORG', 'Organizacja i zarządzanie',        'A',   'Statuty, regulaminy, protokoły organów',          10],
            ['FIN', 'Finanse i księgowość',              'B10', 'Budżety, sprawozdania finansowe, faktury',        20],
            ['KAD', 'Kadry i sprawy pracownicze',        'B50', 'Umowy o pracę, akta osobowe',                    30],
            ['WOL', 'Wolontariat',                       'B10', 'Porozumienia wolontariackie, listy obecności',   40],
            ['PRM', 'Projekty i programy',               'B10', 'Wnioski, umowy dotacyjne, raporty projektowe',   50],
            ['ZAM', 'Zamówienia i umowy z kontrahentami','B5',  'Umowy zlecenie, o dzieło, usługowe',             60],
            ['KOR', 'Korespondencja ogólna',             'B5',  'Pisma wpływające i wychodzące niezakwalifikowane do innych kategorii', 70],
            ['PR',  'Promocja i komunikacja',            'B5',  'Materiały PR, media społecznościowe, publikacje', 80],
            ['IT',  'Informatyka i technologia',         'B5',  'Licencje, umowy serwisowe, polityki IT',         90],
        ] as [$sym, $tit, $kat, $desc, $ord]) {
            try { $ins->execute([$sym, $tit, $kat, $desc, $ord]); } catch (\Throwable $e) {}
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
})();

// ── Stałe ────────────────────────────────────────────────────────────────────

/**
 * Tryb „mini" kancelarii — uproszczony rejestr spraw i dokumentów bez
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
    'epuap'  => ['label' => 'ePUAP / e-Doręczenia', 'icon' => 'bi-shield-lock'],
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
    'epuap'     => ['label' => 'ePUAP / e-Doręczenia', 'icon' => 'bi-shield-lock'],
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
    if (!empty($f['q']))         { $where[] = "(s.title LIKE ? OR s.znak_sprawy LIKE ?)"; $q = '%'.$f['q'].'%'; $params[] = $q; $params[] = $q; }
    if (!empty($f['deadline_od'])) { $where[] = "s.deadline>=?"; $params[] = $f['deadline_od']; }
    if (!empty($f['deadline_do'])) { $where[] = "s.deadline<=?"; $params[] = $f['deadline_do']; }
    if (!empty($f['mine_or_shared']) && $viewer_id) {
        $where[] = "(s.owner_id=? OR s.created_by=? OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))";
        array_push($params, $viewer_id, $viewer_id, $viewer_id);
    }
    if ($viewer_id !== null && !is_admin() && !can_read('ezd')) {
        $where[] = "(s.owner_id=? OR s.created_by=? OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))";
        array_push($params, $viewer_id, $viewer_id, $viewer_id);
    }
    return db_all(
        "SELECT s.*, t.symbol AS teczka_symbol, t.title AS teczka_title, u.name AS owner_name
         FROM ezd_sprawy s
         JOIN ezd_teczki t ON t.id = s.teczka_id
         LEFT JOIN users u ON u.id = s.owner_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY s.updated_at DESC
         LIMIT 200",
        $params
    );
}

function ezd_sprawa_create(array $d, int $user_id): int {
    $teczka = ezd_teczka_get((int)$d['teczka_id']);
    if (!$teczka) throw new \RuntimeException('Teczka nie istnieje.');
    if ($teczka['status'] === 'closed') throw new \RuntimeException('Teczka jest zamknięta.');

    $rok   = (int)date('Y');
    $numer = _ezd_next_numer((int)$d['teczka_id'], $rok);
    $znak  = strtoupper($teczka['symbol']) . '.' . $numer . '.' . $rok;

    $parent_id = !empty($d['parent_id']) ? (int)$d['parent_id'] : null;
    if ($parent_id) {
        $parent = ezd_sprawa_get($parent_id);
        if (!$parent || (int)$parent['teczka_id'] !== (int)$d['teczka_id']) {
            throw new \RuntimeException('Podsprawa musi należeć do tej samej teczki co sprawa nadrzędna.');
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
        throw new \RuntimeException('Sprawa jest zamknięta. Skontaktuj się z administratorem.');
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
    if ($s['status'] === 'closed' && !is_admin()) throw new \RuntimeException('Sprawa jest zamknięta.');

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

function ezd_pismo_create(array $d, int $user_id): int {
    $sprawa   = ezd_sprawa_get((int)$d['sprawa_id']);
    if (!$sprawa) throw new \RuntimeException('Sprawa nie istnieje.');
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
        ':dp'     => $d['data_pisma']   ?: null,
        ':dw'     => $d['data_wplywu']  ?: null,
        ':dy'     => $d['data_wysylki'] ?: null,
        ':status' => $d['status']   ?? 'nowe',
        ':owner'  => $d['owner_id'] ?: null,
        ':medium' => $medium,
        ':uid'    => $user_id,
    ]);
    $id = (int)db()->lastInsertId();
    db()->prepare("UPDATE ezd_sprawy SET updated_at=datetime('now') WHERE id=?")->execute([$d['sprawa_id']]);
    ezd_log(null, (int)$d['sprawa_id'], $id, null, $user_id, 'pismo_create', "Dodano pismo $sygnatura");
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
    return $znak . '.P.' . ($c + 1);
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
    if (!$sprawa) throw new \RuntimeException('Sprawa nie istnieje.');
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
        throw new \RuntimeException('Sprawa jest zamknięta — edycja zablokowana.');
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

function ezd_rpw_get(int $id): ?array {
    return db_one(
        "SELECT r.*, s.znak_sprawy, s.title AS sprawa_title,
                p.title AS pismo_title, p.sygnatura AS pismo_sygnatura,
                u.name AS przekazano_name, c.name AS creator_name
         FROM ezd_rpw r
         LEFT JOIN ezd_sprawy s ON s.id = r.sprawa_id
         LEFT JOIN ezd_pisma  p ON p.id = r.pismo_id
         LEFT JOIN users      u ON u.id = r.przekazano_do
         LEFT JOIN users      c ON c.id = r.created_by
         WHERE r.id=?", [$id]
    );
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
    return db_all(
        "SELECT r.*, s.znak_sprawy, u.name AS przekazano_name
         FROM ezd_rpw r
         LEFT JOIN ezd_sprawy s ON s.id = r.sprawa_id
         LEFT JOIN users      u ON u.id = r.przekazano_do
         WHERE " . implode(' AND ', $where) . "
         ORDER BY r.rok DESC, r.rpw_nr DESC
         LIMIT 500",
        $params
    );
}

/** @return array{id:int,rpw_nr:int,rok:int} */
function ezd_rpw_create(array $d, int $user_id): array {
    $data = $d['data_wplywu'] ?: date('Y-m-d');
    $rok  = (int)substr($data, 0, 4) ?: (int)date('Y');
    $nr   = _ezd_next_rpw($rok);
    db()->prepare(
        "INSERT INTO ezd_rpw (rpw_nr,rok,data_wplywu,typ,nadawca,znak_obcy,opis,uwagi,status,przekazano_do,created_by)
         VALUES (:nr,:rok,:dw,:typ,:nad,:zo,:opis,:uw,:st,:pd,:uid)"
    )->execute([
        ':nr'  => $nr, ':rok' => $rok, ':dw' => $data,
        ':typ' => $d['typ'] ?? 'list',
        ':nad' => trim($d['nadawca'] ?? ''),
        ':zo'  => trim($d['znak_obcy'] ?? ''),
        ':opis'=> trim($d['opis'] ?? ''),
        ':uw'  => trim($d['uwagi'] ?? ''),
        ':st'  => !empty($d['przekazano_do']) ? 'przekazana' : 'nowa',
        ':pd'  => $d['przekazano_do'] ?? null,
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

function ezd_rpw_przekaz(int $id, int $wykonawca_id, int $user_id): void {
    $r = ezd_rpw_get($id);
    if (!$r || $r['status'] === 'w_sprawie') return;
    db()->prepare("UPDATE ezd_rpw SET przekazano_do=?, status='przekazana' WHERE id=?")->execute([$wykonawca_id, $id]);
    ezd_log(null, null, null, null, $user_id, 'rpw_przekaz', ezd_rpw_label($r) . ' → użytkownik #' . $wykonawca_id);
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
    if (!$sprawa_id) throw new \RuntimeException('Wskaż sprawę lub teczkę dla nowej sprawy.');

    $sprawa = ezd_sprawa_get($sprawa_id);
    if (!$sprawa) throw new \RuntimeException('Sprawa nie istnieje.');

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
    if (!$sprawa) throw new \RuntimeException('Sprawa nie istnieje.');
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
        throw new \RuntimeException('Sprawa jest zamknięta — edycja zablokowana.');
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
        "SELECT s.znak_sprawy, s.title, j.symbol AS jrwa_symbol
         FROM ezd_sprawy s
         JOIN ezd_teczki t ON t.id = s.teczka_id
         LEFT JOIN ezd_jrwa j ON j.id = t.jrwa_id
         WHERE s.id = ?", [$sprawa_id]
    );
    if (!$row) return null;
    $jrwa = _ezd_sp_sanitize($row['jrwa_symbol'] ?: 'bez-JRWA');
    $case = _ezd_sp_sanitize(trim($row['znak_sprawy'] . ' ' . $row['title']));
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
 * Ściąga aktualną treść pliku z SharePoint (po edycji w Word Online) i zapisuje
 * jako nową wersję załącznika (analogicznie do ponownego wgrania pliku).
 * Wymaga, by dokument był już wcześniej otwarty w Word Online (ma sp_item_id).
 * @return array{ok:bool,error:?string,id:?int}
 */
function ezd_office_online_pull(int $zal_id, int $user_id): array {
    $z = ezd_zal_get($zal_id);
    if (!$z) return ['ok' => false, 'error' => 'Nie znaleziono pliku.', 'id' => null];
    if (empty($z['sp_item_id']) || empty($z['sp_drive_id'])) {
        return ['ok' => false, 'error' => 'Dokument nie był jeszcze otwarty w Word Online.', 'id' => null];
    }

    require_once __DIR__ . '/m365.php';
    try {
        $graph   = new M365Graph();
        $content = $graph->sp_download_file($z['sp_drive_id'], $z['sp_item_id']);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Błąd SharePoint: ' . $e->getMessage(), 'id' => null];
    }

    $ext    = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
    $dir    = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (file_put_contents($dir . $stored, $content) === false) {
        return ['ok' => false, 'error' => 'Nie udało się zapisać pliku.', 'id' => null];
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
        'Zapisano zmiany z Word Online jako nową wersję: ' . $z['original_name'] . " (v" . ((int)$z['wersja'] + 1) . ")");

    return ['ok' => true, 'error' => null, 'id' => $new_id];
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

function ezd_zal_delete(int $id, int $user_id): void {
    $z = ezd_zal_get($id);
    if (!$z) return;
    $path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $z['sprawa_id'] . '/' . $z['filename'];
    if (is_file($path)) unlink($path);
    db()->prepare("DELETE FROM ezd_zalaczniki WHERE id=?")->execute([$id]);
    ezd_log(null, $z['sprawa_id'], null, null, $user_id, 'del_attachment', 'Usunięto plik: ' . $z['original_name']);
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
        throw new \RuntimeException('Sprawa jest zamknięta. Edycja zablokowana dla nieadministratorów.');
    }
}

// ── Wykrywanie i walidacja podpisu el. — wydzielone do includes/sigcheck.php ──
require_once __DIR__ . '/sigcheck.php';
