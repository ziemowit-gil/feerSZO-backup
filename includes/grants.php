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

    // Cel statutowy §6 + powiązany segregator EZD JRWA
    try { $pdo->exec("ALTER TABLE actions ADD COLUMN cel_statutowy INTEGER"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE actions ADD COLUMN jrwa_id INTEGER REFERENCES ezd_jrwa(id) ON DELETE SET NULL"); } catch (\Throwable $e) {}
})();

// ── JRWA auto-segregator ───────────────────────────────────────────────────────

/** Mapa: cel_statutowy (1-9) → symbol klasy JRWA §6 */
const ACTION_CEL_JRWA = [
    1 => '31', 2 => '32', 3 => '33', 4 => '34', 5 => '35',
    6 => '36', 7 => '37', 8 => '38', 9 => '39',
];

/** Etykiety §6 celów */
const ACTION_CELE = [
    1 => 'Przeciwdziałanie wykluczeniu społecznemu',
    2 => 'Działalność edukacyjna',
    3 => 'Promocja i organizacja wolontariatu',
    4 => 'Podnoszenie kwalifikacji zawodowych os. z niepełnospr.',
    5 => 'Promowanie samorozwoju os. z niepełnosprawnościami',
    6 => 'Działania na rzecz osób starszych',
    7 => 'Integracja osób z niepełnosprawnościami',
    8 => 'Promowanie tyfloinformatyki',
    9 => 'Działalność na rzecz NGO i aktywizacja społeczeństwa',
];

/**
 * Tworzy segregator JRWA dla działania (jeśli już nie ma).
 * Symbol: {klasa-§6}.{NNN} np. 32.001
 * Zwraca jrwa_id lub null gdy ezd_jrwa nie istnieje/brak celu.
 */
function action_ensure_jrwa(int $action_id, int $cel_statutowy, string $nazwa): ?int {
    if (!isset(ACTION_CEL_JRWA[$cel_statutowy])) return null;
    try {
        $pdo = db();
        $parentSym = ACTION_CEL_JRWA[$cel_statutowy];
        $parent = $pdo->prepare("SELECT id FROM ezd_jrwa WHERE symbol=? LIMIT 1");
        $parent->execute([$parentSym]);
        $pid = $parent->fetchColumn();
        if (!$pid) return null;
        $pid = (int)$pid;

        // Sprawdź czy action już ma jrwa_id
        $existing = db_one("SELECT jrwa_id FROM actions WHERE id=?", [$action_id]);
        if (!empty($existing['jrwa_id'])) {
            return (int)$existing['jrwa_id'];
        }

        // Ustal kolejny numer w tej klasie
        $last = $pdo->prepare("SELECT symbol FROM ezd_jrwa WHERE parent_id=? AND symbol LIKE ? ORDER BY symbol DESC LIMIT 1");
        $last->execute([$pid, $parentSym . '.%']);
        $lastSym = $last->fetchColumn();
        if ($lastSym) {
            $n = (int)substr($lastSym, strrpos($lastSym, '.') + 1) + 1;
        } else {
            $n = 1;
        }
        $sym = $parentSym . '.' . str_pad($n, 3, '0', STR_PAD_LEFT);

        // Wstaw segregator
        $ins = $pdo->prepare(
            "INSERT INTO ezd_jrwa (symbol, title, kat_arch, description, sort_order, parent_id)
             VALUES (?,?,?,?,?,?)"
        );
        $ins->execute([$sym, $nazwa, 'B5', "Działanie #{$action_id}", $n * 10, $pid]);
        $jrwa_id = (int)$pdo->lastInsertId();

        // Zapisz z powrotem na działanie
        $pdo->prepare("UPDATE actions SET jrwa_id=? WHERE id=?")->execute([$jrwa_id, $action_id]);
        return $jrwa_id;
    } catch (\Throwable $e) {
        error_log('[action_ensure_jrwa] ' . $e->getMessage());
        return null;
    }
}

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
