<?php
/**
 * _table.php — auto-migracja tabeli umowy_migracja_webngo.
 * Dołączany przez add/view/list.php — uruchamia CREATE TABLE IF NOT EXISTS.
 */
function migracja_webngo_ensure_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS umowy_migracja_webngo (
        id                   INTEGER PRIMARY KEY AUTOINCREMENT,
        numer_umowy          TEXT    NOT NULL,
        -- Dane osoby
        imie_nazwisko        TEXT,
        pesel                TEXT,
        email                TEXT,
        -- Umowa źródłowa (webNGO)
        typ_umowy_zrodla     TEXT    NOT NULL,
        webngo_id            TEXT,
        webngo_numer_umowy   TEXT    NOT NULL,
        webngo_data_zawarcia TEXT,
        -- Uzasadnienie migracji
        powod_migracji       TEXT    NOT NULL,
        opis_powodu          TEXT    NOT NULL,
        -- Nowa umowa w systemie
        nowy_typ_umowy       TEXT,
        nowy_numer_umowy     TEXT,
        -- Status i dane operacyjne
        status               TEXT    NOT NULL DEFAULT 'w_toku',
        data_migracji        TEXT,
        osoba_migrujaca      TEXT,
        uwagi                TEXT,
        -- Systemowe
        created_by           INTEGER,
        created_at           TEXT,
        updated_at           TEXT
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_migr_webngo_numer
                ON umowy_migracja_webngo(webngo_numer_umowy)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_migr_webngo_status
                ON umowy_migracja_webngo(status)");
}

const MIGRACJA_POWODY = [
    'zmiana_systemu'  => 'Zmiana systemu (webNGO → FEER SZO)',
    'blad_danych'     => 'Błąd / niekompletność danych w webNGO',
    'rozszerzenie'    => 'Rozszerzenie zakresu funkcji',
    'wymog_ustawowy'  => 'Wymóg ustawowy lub regulaminowy',
    'ujednolicenie'   => 'Ujednolicenie dokumentacji',
    'inne'            => 'Inne',
];

const MIGRACJA_TYPY = [
    'zlecenie'    => 'Umowa zlecenie',
    'wolontariat' => 'Porozumienie wolontariackie',
    'dzielo'      => 'Umowa o dzieło',
    'praca'       => 'Umowa o pracę',
    'uslugi'      => 'Umowa o usługi',
    'inne'        => 'Inna',
];

const MIGRACJA_STATUSY = [
    'w_toku'     => ['label' => 'W toku',     'class' => 'warning'],
    'zakonczona' => ['label' => 'Zakończona', 'class' => 'success'],
    'anulowana'  => ['label' => 'Anulowana',  'class' => 'danger'],
];

function migracja_status_badge(string $s): string {
    $d = MIGRACJA_STATUSY[$s] ?? ['label' => $s, 'class' => 'secondary'];
    return '<span class="badge bg-' . $d['class'] . '">' . htmlspecialchars($d['label']) . '</span>';
}
