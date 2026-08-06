<?php
/**
 * Moduł Granty i Działania — helpery DB + auto-migracja.
 */

// ── Auto-migracja ─────────────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS grants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nazwa TEXT NOT NULL,
        donator TEXT NOT NULL,
        program TEXT,
        nr_umowy TEXT,
        nr_wewnetrzny TEXT,
        status TEXT NOT NULL DEFAULT 'pomysł',
        kwota_wnioskowana DECIMAL(14,2),
        kwota_przyznana DECIMAL(14,2),
        waluta TEXT NOT NULL DEFAULT 'PLN',
        data_zlozenia DATE,
        data_od DATE,
        data_do DATE,
        data_rozliczenia DATE,
        cel_strategiczny TEXT,
        obszar_tematyczny TEXT,
        koordynator_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        opis TEXT,
        uwagi TEXT,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS actions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nazwa TEXT NOT NULL,
        typ TEXT NOT NULL DEFAULT 'inne',
        opis TEXT,
        status TEXT NOT NULL DEFAULT 'planowane',
        koordynator_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        data_od DATE,
        data_do DATE,
        cykliczne INTEGER NOT NULL DEFAULT 0,
        czestotliwosc TEXT,
        lokalizacja TEXT,
        forma TEXT DEFAULT 'stacjonarne',
        link_online TEXT,
        wlasne_dzialanie INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS action_grants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
        grant_id INTEGER NOT NULL REFERENCES grants(id) ON DELETE CASCADE,
        udzial_procent DECIMAL(5,2),
        kwota DECIMAL(14,2),
        notatka TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS action_indicators (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
        nazwa TEXT NOT NULL,
        wartosc_planowana DECIMAL(10,2),
        wartosc_realizowana DECIMAL(10,2) DEFAULT 0
    )");

    // Korzyści i grupa docelowa
    try { $pdo->exec("ALTER TABLE actions ADD COLUMN korzysci_tytul TEXT NOT NULL DEFAULT 'Korzyści'"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE actions ADD COLUMN korzysci TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE actions ADD COLUMN dla_kogo TEXT"); } catch (\Throwable $e) {}

    // Powiązanie umów wolontariatu z działaniem lub grantem
    try { $pdo->exec("ALTER TABLE umowy_wolontariat ADD COLUMN action_id INTEGER REFERENCES actions(id) ON DELETE SET NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE umowy_wolontariat ADD COLUMN grant_id INTEGER REFERENCES grants(id) ON DELETE SET NULL"); } catch (\Throwable $e) {}
})();

// ── Helpery ────────────────────────────────────────────────────────────────────

function grants_all(array $filters = []): array {
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'g.status = :status';
        $params[':status'] = $filters['status'];
    }
    if (!empty($filters['donator'])) {
        $where[] = 'g.donator LIKE :donator';
        $params[':donator'] = '%' . $filters['donator'] . '%';
    }
    if (!empty($filters['year'])) {
        $where[] = "(strftime('%Y', g.data_od) = :year OR strftime('%Y', g.data_zlozenia) = :year)";
        $params[':year'] = $filters['year'];
    }
    if (!empty($filters['koordynator_id'])) {
        $where[] = 'g.koordynator_id = :koord';
        $params[':koord'] = (int)$filters['koordynator_id'];
    }

    $sql = "SELECT g.*, u.name AS koordynator_name,
                   (SELECT COUNT(*) FROM action_grants ag WHERE ag.grant_id = g.id) AS actions_count
            FROM grants g
            LEFT JOIN users u ON u.id = g.koordynator_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY g.created_at DESC";

    return db_all($sql, $params);
}

function grant_by_id(int $id): ?array {
    return db_one(
        "SELECT g.*, u.name AS koordynator_name
         FROM grants g
         LEFT JOIN users u ON u.id = g.koordynator_id
         WHERE g.id = ?",
        [$id]
    );
}

function actions_all(array $filters = []): array {
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'a.status = :status';
        $params[':status'] = $filters['status'];
    }
    if (!empty($filters['typ'])) {
        $where[] = 'a.typ = :typ';
        $params[':typ'] = $filters['typ'];
    }
    if (!empty($filters['koordynator_id'])) {
        $where[] = 'a.koordynator_id = :koord';
        $params[':koord'] = (int)$filters['koordynator_id'];
    }
    if (!empty($filters['grant_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM action_grants ag WHERE ag.action_id = a.id AND ag.grant_id = :grant_id)';
        $params[':grant_id'] = (int)$filters['grant_id'];
    }
    if (!empty($filters['data_od'])) {
        $where[] = 'a.data_do >= :data_od';
        $params[':data_od'] = $filters['data_od'];
    }
    if (!empty($filters['data_do'])) {
        $where[] = 'a.data_od <= :data_do';
        $params[':data_do'] = $filters['data_do'];
    }

    $sql = "SELECT a.*, u.name AS koordynator_name
            FROM actions a
            LEFT JOIN users u ON u.id = a.koordynator_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.data_od DESC, a.created_at DESC";

    return db_all($sql, $params);
}

function action_by_id(int $id): ?array {
    return db_one(
        "SELECT a.*, u.name AS koordynator_name
         FROM actions a
         LEFT JOIN users u ON u.id = a.koordynator_id
         WHERE a.id = ?",
        [$id]
    );
}

function action_grants_for(int $action_id): array {
    return db_all(
        "SELECT ag.*, g.nazwa, g.donator, g.waluta
         FROM action_grants ag
         JOIN grants g ON g.id = ag.grant_id
         WHERE ag.action_id = ?
         ORDER BY g.nazwa",
        [$action_id]
    );
}

function grant_actions_for(int $grant_id): array {
    return db_all(
        "SELECT a.*, ag.udzial_procent, ag.kwota AS ag_kwota, ag.notatka AS ag_notatka,
                u.name AS koordynator_name
         FROM action_grants ag
         JOIN actions a ON a.id = ag.action_id
         LEFT JOIN users u ON u.id = a.koordynator_id
         WHERE ag.grant_id = ?
         ORDER BY a.data_od DESC",
        [$grant_id]
    );
}

function grant_action_indicators_for(int $action_id): array {
    return db_all(
        "SELECT * FROM action_indicators WHERE action_id = ? ORDER BY id",
        [$action_id]
    );
}
