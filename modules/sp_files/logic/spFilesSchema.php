<?php
/**
 * modules/sp_files/logic/spFilesSchema.php — schemat dopasowania skanów z SharePointa do umów bez pliku.
 *
 * sp_library_files    — lokalny indeks plików biblioteki SharePoint (nazwa, folder, rozmiar, adres) budowany przez SpIndex;
 *                       dopasowanie (SpMatcher) pracuje na nim, a nie na zapytaniach do Graph. Bez treści plików.
 * sp_index_runs       — przebiegi indeksowania: kolejka folderów jeszcze do przejrzenia (wznawianie między żądaniami),
 *                       blokada przed równoległym krokiem, wynik. Zakończony przebieg usuwa z indeksu pliki, których już nie ma.
 * sp_match_rejections — propozycje odrzucone przez administratora (umowa + plik): nie wracają na listę.
 * sp_match_attached   — skany przypisane do umów (z którego pliku SharePointa, jaki plik lokalny, kto, kiedy, jaka pewność).
 */
function sp_files_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (szo_schema_current('sp_files_migrate', __FILE__)) return;       // raz na wersję pliku

    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS sp_library_files (
        item_id     TEXT PRIMARY KEY,
        name        TEXT NOT NULL,
        folder      TEXT NOT NULL DEFAULT '',
        path        TEXT NOT NULL,
        ext         TEXT NOT NULL DEFAULT '',
        size        INTEGER NOT NULL DEFAULT 0,
        web_url     TEXT NOT NULL DEFAULT '',
        modified_at TEXT NOT NULL DEFAULT '',
        app_named   INTEGER NOT NULL DEFAULT 0,
        run_id      INTEGER NOT NULL DEFAULT 0,
        indexed_at  TEXT NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sp_lib_files_run  ON sp_library_files(run_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sp_lib_files_name ON sp_library_files(name)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sp_index_runs (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        started_at   TEXT NOT NULL,
        finished_at  TEXT,
        status       TEXT NOT NULL DEFAULT 'running' CHECK (status IN ('running','complete','partial','aborted','error')),
        start_folder TEXT NOT NULL DEFAULT '',
        queue        TEXT NOT NULL DEFAULT '[]',
        folders_done INTEGER NOT NULL DEFAULT 0,
        files_seen   INTEGER NOT NULL DEFAULT 0,
        errors       INTEGER NOT NULL DEFAULT 0,
        message      TEXT NOT NULL DEFAULT '',
        locked_until INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sp_match_rejections (
        contract_type TEXT    NOT NULL,
        contract_id   INTEGER NOT NULL,
        item_id       TEXT    NOT NULL,
        rejected_by   INTEGER NOT NULL DEFAULT 0,
        rejected_at   TEXT    NOT NULL,
        PRIMARY KEY (contract_type, contract_id, item_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sp_match_attached (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_type TEXT    NOT NULL,
        contract_id   INTEGER NOT NULL,
        item_id       TEXT    NOT NULL,
        sp_path       TEXT    NOT NULL DEFAULT '',
        rel           TEXT    NOT NULL,
        bytes         INTEGER NOT NULL DEFAULT 0,
        score         INTEGER NOT NULL DEFAULT 0,
        attached_by   INTEGER NOT NULL DEFAULT 0,
        attached_at   TEXT    NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sp_match_att_item     ON sp_match_attached(item_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sp_match_att_contract ON sp_match_attached(contract_type, contract_id)");
}
