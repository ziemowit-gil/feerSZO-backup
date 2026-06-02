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

    // Indeksy wydajnościowe
    foreach ([
        "CREATE INDEX IF NOT EXISTS idx_ezd_sprawy_teczka  ON ezd_sprawy(teczka_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_pisma_sprawa   ON ezd_pisma(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_umowy_sprawa   ON ezd_umowy(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_dekr_sprawa    ON ezd_dekretacje(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_dekr_wyk       ON ezd_dekretacje(wykonawca_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_zal_sprawa     ON ezd_zalaczniki(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_log_sprawa     ON ezd_log(sprawa_id)",
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
})();

// ── Stałe ────────────────────────────────────────────────────────────────────

const EZD_UPLOAD_SUBDIR = 'ezd/';
const EZD_ALLOWED_EXT   = ['pdf','doc','docx','xls','xlsx','odt','ods','pptx','png','jpg','jpeg','gif','zip','txt','csv','eml','msg'];
const EZD_MAX_SIZE      = 25 * 1024 * 1024; // 25 MB

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
const EZD_KIERUNKI = [
    'przychodzace' => ['label' => 'Przychodzące', 'icon' => 'bi-box-arrow-in-down-left', 'class' => 'info'],
    'wychodzace'   => ['label' => 'Wychodzące',   'icon' => 'bi-box-arrow-up-right',     'class' => 'primary'],
    'wewnetrzne'   => ['label' => 'Wewnętrzne',   'icon' => 'bi-arrow-left-right',       'class' => 'secondary'],
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

// ── JRWA ─────────────────────────────────────────────────────────────────────

function ezd_jrwa_all(): array {
    return db_all("SELECT * FROM ezd_jrwa ORDER BY sort_order, symbol");
}

function ezd_jrwa_get(int $id): ?array {
    return db_one("SELECT * FROM ezd_jrwa WHERE id=?", [$id]);
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
                u.name AS owner_name, c.name AS creator_name
         FROM ezd_sprawy s
         JOIN ezd_teczki t ON t.id = s.teczka_id
         LEFT JOIN users u ON u.id = s.owner_id
         LEFT JOIN users c ON c.id = s.created_by
         WHERE s.id=?", [$id]
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

function ezd_sprawy_all(array $f = []): array {
    $where = ["1=1"]; $params = [];
    if (!empty($f['status']))    { $where[] = "s.status=?";     $params[] = $f['status']; }
    if (!empty($f['priority']))  { $where[] = "s.priority=?";   $params[] = $f['priority']; }
    if (!empty($f['teczka_id'])) { $where[] = "s.teczka_id=?";  $params[] = (int)$f['teczka_id']; }
    if (!empty($f['owner_id']))  { $where[] = "s.owner_id=?";   $params[] = (int)$f['owner_id']; }
    if (!empty($f['q']))         { $where[] = "(s.title LIKE ? OR s.znak_sprawy LIKE ?)"; $q = '%'.$f['q'].'%'; $params[] = $q; $params[] = $q; }
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

    db()->prepare(
        "INSERT INTO ezd_sprawy (teczka_id,znak_sprawy,numer,title,description,status,priority,owner_id,deadline,created_by,updated_at)
         VALUES (:tid,:znak,:num,:title,:desc,:status,:prio,:owner,:deadline,:uid,datetime('now'))"
    )->execute([
        ':tid'      => (int)$d['teczka_id'],
        ':znak'     => $znak,
        ':num'      => $numer,
        ':title'    => trim($d['title']),
        ':desc'     => trim($d['description'] ?? ''),
        ':status'   => $d['status']   ?? 'open',
        ':prio'     => $d['priority'] ?? 'normal',
        ':owner'    => $d['owner_id'] ?: null,
        ':deadline' => $d['deadline'] ?: null,
        ':uid'      => $user_id,
    ]);
    $id = (int)db()->lastInsertId();
    ezd_log(null, $id, null, null, $user_id, 'sprawa_create', "Otwarto sprawę $znak: {$d['title']}");
    return $id;
}

function ezd_sprawa_update(int $id, array $d, int $user_id): void {
    $sprawa = ezd_sprawa_get($id);
    if (!$sprawa) return;

    // Blokada zamkniętej sprawy dla nie-adminów
    if ($sprawa['status'] === 'closed' && !is_admin()) {
        throw new \RuntimeException('Sprawa jest zamknięta. Skontaktuj się z administratorem.');
    }

    $closing = ($d['status'] ?? $sprawa['status']) === 'closed' && $sprawa['status'] !== 'closed';

    db()->prepare(
        "UPDATE ezd_sprawy SET teczka_id=:tid,title=:title,description=:desc,
         status=:status,priority=:prio,owner_id=:owner,deadline=:deadline,
         updated_at=datetime('now') WHERE id=:id"
    )->execute([
        ':tid'      => (int)($d['teczka_id'] ?? $sprawa['teczka_id']),
        ':title'    => trim($d['title']),
        ':desc'     => trim($d['description'] ?? ''),
        ':status'   => $d['status']   ?? $sprawa['status'],
        ':prio'     => $d['priority'] ?? $sprawa['priority'],
        ':owner'    => $d['owner_id'] ?: null,
        ':deadline' => $d['deadline'] ?: null,
        ':id'       => $id,
    ]);
    if ($closing) {
        db()->prepare("UPDATE ezd_sprawy SET closed_at=datetime('now') WHERE id=?")->execute([$id]);
        // Zamknij otwarte dekretacje
        db()->prepare("UPDATE ezd_dekretacje SET status='zakonczone',completed_at=datetime('now') WHERE sprawa_id=? AND status='oczekuje'")->execute([$id]);
    }
    ezd_log(null, $id, null, null, $user_id, 'sprawa_update', 'Edytowano sprawę #' . $id);
}

function _ezd_next_numer(int $teczka_id, int $rok): int {
    $r = db_one(
        "SELECT MAX(numer) AS m FROM ezd_sprawy WHERE teczka_id=? AND numer IS NOT NULL",
        [$teczka_id]
    );
    return ($r['m'] ?? 0) + 1;
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
    db()->prepare(
        "INSERT INTO ezd_pisma (sprawa_id,sygnatura,kierunek,title,tresc,nadawca,odbiorca,
         data_pisma,data_wplywu,data_wysylki,status,owner_id,created_by,updated_at)
         VALUES (:sid,:sygn,:kier,:title,:tresc,:nad,:odb,:dp,:dw,:dy,:status,:owner,:uid,datetime('now'))"
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
    db()->prepare(
        "UPDATE ezd_pisma SET kierunek=:k,title=:t,tresc=:tr,nadawca=:n,odbiorca=:o,
         data_pisma=:dp,data_wplywu=:dw,data_wysylki=:dy,status=:s,owner_id=:ow,
         updated_at=datetime('now') WHERE id=:id"
    )->execute([
        ':k' => $d['kierunek'] ?? $p['kierunek'], ':t'  => trim($d['title']),
        ':tr'=> $d['tresc']    ?? '',              ':n'  => $d['nadawca']  ?? '',
        ':o' => $d['odbiorca'] ?? '',              ':dp' => $d['data_pisma']   ?: null,
        ':dw'=> $d['data_wplywu'] ?: null,         ':dy' => $d['data_wysylki'] ?: null,
        ':s' => $d['status']   ?? $p['status'],   ':ow' => $d['owner_id'] ?: null,
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
    return $id;
}

function ezd_dekretacja_complete(int $id, int $user_id): void {
    db()->prepare("UPDATE ezd_dekretacje SET status='zakonczone',completed_at=datetime('now') WHERE id=?")->execute([$id]);
    ezd_log(null, null, null, null, $user_id, 'dekretacja_done', 'Zamknięto dekretację #' . $id);
}

// ── Załączniki ───────────────────────────────────────────────────────────────

function ezd_zalaczniki_by(int $sprawa_id, ?int $pismo_id = null, ?int $umowa_id = null): array {
    if ($pismo_id) {
        return db_all("SELECT z.*,u.name AS uploader FROM ezd_zalaczniki z LEFT JOIN users u ON u.id=z.uploaded_by WHERE z.pismo_id=? ORDER BY z.uploaded_at DESC", [$pismo_id]);
    }
    if ($umowa_id) {
        return db_all("SELECT z.*,u.name AS uploader FROM ezd_zalaczniki z LEFT JOIN users u ON u.id=z.uploaded_by WHERE z.umowa_id=? ORDER BY z.uploaded_at DESC", [$umowa_id]);
    }
    return db_all("SELECT z.*,u.name AS uploader FROM ezd_zalaczniki z LEFT JOIN users u ON u.id=z.uploaded_by WHERE z.sprawa_id=? ORDER BY z.uploaded_at DESC", [$sprawa_id]);
}

function ezd_upload(string $field, int $sprawa_id, int $user_id, ?int $pismo_id = null, ?int $umowa_id = null, ?int $replace_id = null): ?string {
    if (empty($_FILES[$field]['tmp_name'])) return 'Nie wybrano pliku.';
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return 'Błąd przesyłania (kod: ' . $f['error'] . ').';
    if ($f['size'] > EZD_MAX_SIZE)     return 'Plik za duży (maks. 25 MB).';
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EZD_ALLOWED_EXT, true)) return 'Niedozwolony format pliku.';

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
        "INSERT INTO ezd_zalaczniki (sprawa_id,pismo_id,umowa_id,filename,original_name,mime_type,file_size,wersja,prev_id,uploaded_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    )->execute([$sprawa_id, $pismo_id, $umowa_id, $stored, $f['name'], $f['type'] ?: 'application/octet-stream', $f['size'], $wersja, $replace_id ?: null, $user_id]);

    ezd_log(null, $sprawa_id, $pismo_id, $umowa_id, $user_id, 'upload', 'Wgrano plik: ' . $f['name'] . " (v$wersja)");
    return null;
}

function ezd_zal_get(int $id): ?array {
    return db_one("SELECT * FROM ezd_zalaczniki WHERE id=?", [$id]);
}

function ezd_zal_delete(int $id, int $user_id): void {
    $z = ezd_zal_get($id);
    if (!$z) return;
    $path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $z['sprawa_id'] . '/' . $z['filename'];
    if (is_file($path)) unlink($path);
    db()->prepare("DELETE FROM ezd_zalaczniki WHERE id=?")->execute([$id]);
    ezd_log(null, $z['sprawa_id'], null, null, $user_id, 'del_attachment', 'Usunięto plik: ' . $z['original_name']);
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
