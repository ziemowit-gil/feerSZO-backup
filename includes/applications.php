<?php
/**
 * User applications module — migrations, seed data, helpers.
 */

function _applications_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS application_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL,
        icon TEXT NOT NULL DEFAULT 'bi-file-text',
        description TEXT NOT NULL DEFAULT '',
        requires_contract INTEGER NOT NULL DEFAULT 0,
        allow_attachment INTEGER NOT NULL DEFAULT 1,
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS application_type_fields (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type_id INTEGER NOT NULL REFERENCES application_types(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        label TEXT NOT NULL,
        field_type TEXT NOT NULL DEFAULT 'text',
        options_json TEXT NOT NULL DEFAULT '[]',
        placeholder TEXT NOT NULL DEFAULT '',
        required INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_applications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        type_id INTEGER REFERENCES application_types(id) ON DELETE SET NULL,
        contract_type TEXT NOT NULL DEFAULT '',
        contract_id INTEGER NOT NULL DEFAULT 0,
        tytul TEXT NOT NULL DEFAULT '',
        fields_json TEXT NOT NULL DEFAULT '{}',
        plik TEXT DEFAULT NULL,
        status TEXT NOT NULL DEFAULT 'nowy',
        odpowiedz TEXT DEFAULT NULL,
        odpowiedz_at DATETIME DEFAULT NULL,
        answered_by INTEGER DEFAULT NULL REFERENCES users(id),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL
    )");

    // Migracja starych kolumn (jeśli tabela była wcześniej)
    foreach ([
        "ALTER TABLE user_applications ADD COLUMN type_id INTEGER REFERENCES application_types(id) ON DELETE SET NULL",
        "ALTER TABLE user_applications ADD COLUMN fields_json TEXT NOT NULL DEFAULT '{}'",
        "ALTER TABLE user_applications ADD COLUMN tytul TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE user_applications ADD COLUMN updated_at DATETIME DEFAULT NULL",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (\Throwable $e) {}
    }

    // Seed: domyślne typy wniosków przy pierwszym uruchomieniu
    $count = (int)$pdo->query("SELECT COUNT(*) FROM application_types")->fetchColumn();
    if ($count > 0) {
        // Self-heal: instalacje zaseedowane wcześniej (lub zmigrowane ze starego
        // systemu) mogą nie mieć typu „Wniosek o rozwiązanie umowy". Dokładamy go
        // JEDNORAZOWO (flaga w settings) — świadome usunięcie przez admina nie wraca.
        _app_ensure_rozwiazanie($pdo);
        return;
    }

    $ins = $pdo->prepare(
        "INSERT INTO application_types (name, label, icon, description, requires_contract, allow_attachment, is_active, sort_order)
         VALUES (?,?,?,?,?,?,1,?)"
    );
    $types_seed = [
        ['wniosek_urlop',       'Wniosek o urlop / zwolnienie',  'bi-calendar-check',  'Wniosek o urlop, wolne lub zwolnienie od obowiązków.', 0, 1, 10],
        ['wniosek_zaswiadcz',   'Wniosek o zaświadczenie',       'bi-award',           'Prośba o wystawienie zaświadczenia.',                   0, 0, 20],
        ['wniosek_zmiana',      'Wniosek o zmianę warunków',     'bi-pencil-square',   'Wniosek o zmianę warunków obowiązującej umowy.',        1, 1, 30],
        ['wniosek_rozwiazanie', 'Wniosek o rozwiązanie umowy',   'bi-file-earmark-x',  'Wniosek o rozwiązanie umowy.',                          1, 1, 40],
        ['pismo_inne',          'Inne pismo / zapytanie',        'bi-envelope-paper',  'Dowolne pismo lub zapytanie skierowane do organizacji.', 0, 1, 50],
    ];
    foreach ($types_seed as $t) $ins->execute($t);

    // Pola domyślne per typ
    $fld = $pdo->prepare(
        "INSERT INTO application_type_fields (type_id, name, label, field_type, options_json, placeholder, required, sort_order)
         VALUES (?,?,?,?,?,?,?,?)"
    );

    $get_id = fn(string $n) => (int)$pdo->query("SELECT id FROM application_types WHERE name='$n'")->fetchColumn();

    $uid = $get_id('wniosek_urlop');
    $fld->execute([$uid, 'data_od',       'Data od',       'date',     '[]', '',                               1, 10]);
    $fld->execute([$uid, 'data_do',       'Data do',       'date',     '[]', '',                               1, 20]);
    $fld->execute([$uid, 'uzasadnienie',  'Uzasadnienie',  'textarea', '[]', 'Opisz powód wniosku...',          0, 30]);

    $zid = $get_id('wniosek_zaswiadcz');
    $fld->execute([$zid, 'cel',           'Cel zaświadczenia', 'select', json_encode(['Do banku', 'Do urzędu', 'Do szkoły / uczelni', 'Inne']), '', 1, 10]);
    $fld->execute([$zid, 'info',          'Dodatkowe informacje', 'textarea', '[]', 'Np. wymagana treść...', 0, 20]);

    $mid = $get_id('wniosek_zmiana');
    $fld->execute([$mid, 'opis_zmiany',   'Opis wnioskowanej zmiany', 'textarea', '[]', 'Opisz jakie zmiany chcesz wprowadzić...', 1, 10]);

    $rid = $get_id('wniosek_rozwiazanie');
    $fld->execute([$rid, 'data_rozw',     'Proponowana data rozwiązania', 'date', '[]', '', 1, 10]);
    $fld->execute([$rid, 'uzasadnienie',  'Uzasadnienie', 'textarea', '[]', 'Podaj powód rozwiązania...', 0, 20]);

    $iid = $get_id('pismo_inne');
    $fld->execute([$iid, 'tresc',         'Treść pisma', 'textarea', '[]', 'Opisz swoją sprawę szczegółowo...', 1, 10]);
}

/**
 * Jednorazowo zapewnia obecność typu „Wniosek o rozwiązanie umowy" na istniejących
 * instalacjach. Dodaje typ + pola, gdy go brak; reaktywuje, jeśli istnieje, ale jest
 * nieaktywny. Po wykonaniu ustawia flagę w settings, więc późniejsze świadome
 * wyłączenie/usunięcie przez administratora już nie jest cofane. Nigdy nie przerywa
 * inicjalizacji (błędy łykane).
 */
function _app_ensure_rozwiazanie(\PDO $pdo): void {
    try {
        $done = $pdo->query("SELECT value FROM settings WHERE key_='app_seed_rozwiazanie_v1'")->fetchColumn();
        if ($done) return;

        $row = $pdo->query("SELECT id, is_active FROM application_types WHERE name='wniosek_rozwiazanie'")
                   ->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $maxsort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM application_types")->fetchColumn();
            $ins = $pdo->prepare(
                "INSERT INTO application_types (name, label, icon, description, requires_contract, allow_attachment, is_active, sort_order)
                 VALUES ('wniosek_rozwiazanie','Wniosek o rozwiązanie umowy','bi-file-earmark-x','Wniosek o rozwiązanie umowy.',1,1,1,?)"
            );
            $ins->execute([max(40, $maxsort + 10)]);
            $rid = (int)$pdo->lastInsertId();
            $fld = $pdo->prepare(
                "INSERT INTO application_type_fields (type_id, name, label, field_type, options_json, placeholder, required, sort_order)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            $fld->execute([$rid, 'data_rozw',    'Proponowana data rozwiązania', 'date',     '[]', '',                            1, 10]);
            $fld->execute([$rid, 'uzasadnienie', 'Uzasadnienie',                 'textarea', '[]', 'Podaj powód rozwiązania...',  0, 20]);
        } elseif (!(int)$row['is_active']) {
            $pdo->prepare("UPDATE application_types SET is_active=1 WHERE id=?")->execute([(int)$row['id']]);
        }

        $pdo->prepare("INSERT INTO settings (key_, value) VALUES ('app_seed_rozwiazanie_v1','1')
                       ON CONFLICT(key_) DO UPDATE SET value=excluded.value")->execute();
    } catch (\Throwable $e) { /* nie blokuj inicjalizacji modułu */ }
}

// ── Helpery ───────────────────────────────────────────────────────────────────

function app_types_all(bool $active_only = false): array {
    _applications_init();
    $w = $active_only ? 'WHERE is_active=1' : '';
    return db_all("SELECT * FROM application_types $w ORDER BY sort_order, id");
}

function app_type_get(int $id): ?array {
    _applications_init();
    return db_one("SELECT * FROM application_types WHERE id=?", [$id]);
}

function app_type_fields(int $type_id): array {
    _applications_init();
    return db_all("SELECT * FROM application_type_fields WHERE type_id=? ORDER BY sort_order, id", [$type_id]);
}

function app_types_with_fields(bool $active_only = false): array {
    $types = app_types_all($active_only);
    foreach ($types as &$t) {
        $t['fields'] = app_type_fields($t['id']);
    }
    return $types;
}

/**
 * Waliduje i zbiera dane pól z $_POST dla danego zestawu pól.
 * Zwraca ['data' => [...], 'errors' => [...]].
 */
function app_collect_fields(array $fields): array {
    $data = []; $errors = [];
    foreach ($fields as $f) {
        $val = trim($_POST['field_' . $f['name']] ?? '');
        if ($f['required'] && $val === '') {
            $errors[] = 'Pole „' . $f['label'] . '" jest wymagane.';
        }
        $data[$f['name']] = $val;
    }
    return compact('data', 'errors');
}

/** Zwraca tablicę opcji dla pola select. */
function app_field_options(array $field): array {
    $opts = json_decode($field['options_json'] ?? '[]', true);
    return is_array($opts) ? $opts : [];
}
