<?php
declare(strict_types=1);
/**
 * modules/dictionary/lib/dictionarySchema.php — schemat Słownika Centralnego (idempotentny, za bramką
 * szo_schema_current: wykona się raz na wersję pliku).
 *
 * Tabele (SQL przenośny SQLite ↔ MySQL — przyszłe przepisanie na Laravel):
 *   dictionaries              — słowniki; drzewo kategorii przez parent_id; kind, is_protected, soft-delete
 *   dictionary_entries        — wpisy; drzewo w obrębie słownika (parent_id), valid_from/valid_to,
 *                               attributes (JSON), version (blokada optymistyczna), soft-delete
 *   dictionary_entry_labels   — etykiety w innych językach (i18n); język domyślny (pl) = entries.label
 *   dictionary_history        — historia zmian (przed/po jako JSON) słowników i wpisów
 */

function dictionaryMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('szo_schema_current') && szo_schema_current('dictionary_migrate', __FILE__)) return;

    $pdo   = db();
    $mysql = defined('DB_TYPE') && DB_TYPE === 'mysql';
    $pk    = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $ref   = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $tail  = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $pdo->exec("CREATE TABLE IF NOT EXISTS dictionaries (
        id            $pk,
        parent_id     $ref NULL,
        code          VARCHAR(64)  NOT NULL,
        name          VARCHAR(190) NOT NULL,
        description   TEXT NULL,
        kind          VARCHAR(16)  NOT NULL DEFAULT 'business',
        is_protected  SMALLINT     NOT NULL DEFAULT 0,
        sort_order    INTEGER      NOT NULL DEFAULT 0,
        created_at    DATETIME NULL,
        created_by    $ref NULL,
        updated_at    DATETIME NULL,
        updated_by    $ref NULL,
        deleted_at    DATETIME NULL,
        CONSTRAINT uq_dictionaries_code UNIQUE (code),
        CONSTRAINT fk_dictionaries_parent FOREIGN KEY (parent_id) REFERENCES dictionaries(id) ON DELETE SET NULL
    )$tail");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dictionary_entries (
        id            $pk,
        dictionary_id $ref NOT NULL,
        parent_id     $ref NULL,
        code          VARCHAR(64)  NOT NULL,
        label         VARCHAR(255) NOT NULL,
        description   TEXT NULL,
        attributes    TEXT NULL,
        sort_order    INTEGER      NOT NULL DEFAULT 0,
        is_active     SMALLINT     NOT NULL DEFAULT 1,
        valid_from    DATE NULL,
        valid_to      DATE NULL,
        version       INTEGER      NOT NULL DEFAULT 1,
        created_at    DATETIME NULL,
        created_by    $ref NULL,
        updated_at    DATETIME NULL,
        updated_by    $ref NULL,
        deleted_at    DATETIME NULL,
        CONSTRAINT uq_dictionary_entries_code UNIQUE (dictionary_id, code),
        CONSTRAINT fk_dictionary_entries_dict FOREIGN KEY (dictionary_id) REFERENCES dictionaries(id) ON DELETE CASCADE,
        CONSTRAINT fk_dictionary_entries_parent FOREIGN KEY (parent_id) REFERENCES dictionary_entries(id) ON DELETE SET NULL
    )$tail");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dictionary_entry_labels (
        id        $pk,
        entry_id  $ref NOT NULL,
        locale    VARCHAR(8)   NOT NULL,
        label     VARCHAR(255) NOT NULL,
        CONSTRAINT uq_dictionary_entry_labels UNIQUE (entry_id, locale),
        CONSTRAINT fk_dictionary_entry_labels_entry FOREIGN KEY (entry_id) REFERENCES dictionary_entries(id) ON DELETE CASCADE
    )$tail");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dictionary_history (
        id            $pk,
        dictionary_id $ref NOT NULL,
        entry_id      $ref NULL,
        action        VARCHAR(32) NOT NULL,
        before_json   TEXT NULL,
        after_json    TEXT NULL,
        user_id       $ref NULL,
        created_at    DATETIME NULL
    )$tail");

    $indexes = [
        'ix_dictionaries_parent'        => 'dictionaries(parent_id)',
        'ix_dictionary_entries_list'    => 'dictionary_entries(dictionary_id, deleted_at, is_active, sort_order)',
        'ix_dictionary_entries_parent'  => 'dictionary_entries(parent_id)',
        'ix_dictionary_entries_valid'   => 'dictionary_entries(dictionary_id, valid_from, valid_to)',
        'ix_dictionary_history_dict'    => 'dictionary_history(dictionary_id, created_at)',
        'ix_dictionary_history_entry'   => 'dictionary_history(entry_id)',
    ];
    foreach ($indexes as $name => $on) {
        try { $pdo->exec(($mysql ? 'CREATE INDEX ' : 'CREATE INDEX IF NOT EXISTS ') . "$name ON $on"); }
        catch (\Throwable $e) { /* MySQL: indeks już istnieje */ }
    }

    // Uprawnienie 'slowniki' (includes/permissions.php): edytor domyślnie tylko odczyt panelu; admin ma wszystko z urzędu.
    try {
        $pdo->exec("INSERT INTO role_permissions (role_id, module, can_read, can_write, can_delete)
                    SELECT r.id, 'slowniki', 1, 0, 0 FROM roles r
                    WHERE r.name = 'editor'
                      AND NOT EXISTS (SELECT 1 FROM role_permissions p WHERE p.role_id = r.id AND p.module = 'slowniki')");
    } catch (\Throwable $e) { /* brak tabel uprawnień (instalacja) */ }
}
