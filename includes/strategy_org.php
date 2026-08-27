<?php
/**
 * includes/strategy_org.php — Moduł „Strategia Organizacji" (planowanie działań operacyjnych).
 *
 * Zastępuje stary moduł Strategii Rozwoju NGO (includes/strategy.php) jako
 * główne wejście /strategy/. Planowanie działań w wymiarach:
 * grupa docelowa × horyzont czasowy (miesiąc/rok) × typ działania ×
 * powiązanie statutowe × źródło finansowania × zasoby (ludzie/sprzęt/finanse).
 *
 * Tabele:
 *   strat_target_groups    — słownik grup docelowych
 *   strat_action_types     — słownik typów działań
 *   strat_statute_refs     — słownik punktów/paragrafów statutu
 *   strat_funding_sources  — źródła finansowania (projekt/dotacja/środki własne)
 *   strat_plans            — zaplanowane działania
 *   strat_plan_resources   — alokacja zasobów do działania
 */

// ── Auto-migracja (wzorzec samonaprawy schematu) ─────────────────────────────
function strat_org_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo = db();

        $pdo->exec("CREATE TABLE IF NOT EXISTS strat_target_groups (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            nazwa      TEXT NOT NULL,
            opis       TEXT,
            kolor      TEXT NOT NULL DEFAULT '#2563eb',
            is_active  INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT (datetime('now','localtime'))
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS strat_action_types (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            nazwa      TEXT NOT NULL,
            ikona      TEXT NOT NULL DEFAULT 'bi-calendar-event',
            kolor      TEXT NOT NULL DEFAULT '#0891b2',
            is_active  INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT (datetime('now','localtime'))
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS strat_statute_refs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            kod        TEXT NOT NULL,
            tytul      TEXT NOT NULL,
            opis       TEXT,
            is_active  INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT (datetime('now','localtime'))
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS strat_funding_sources (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            nazwa            TEXT NOT NULL,
            typ              TEXT NOT NULL DEFAULT 'srodki_wlasne',
            budzet_calkowity DECIMAL(14,2),
            rok              INTEGER,
            opis             TEXT,
            is_active        INTEGER NOT NULL DEFAULT 1,
            sort_order       INTEGER NOT NULL DEFAULT 0,
            created_at       DATETIME DEFAULT (datetime('now','localtime'))
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS strat_plans (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            nazwa             TEXT NOT NULL,
            opis              TEXT,
            rok               INTEGER NOT NULL,
            miesiac           INTEGER NOT NULL,
            target_group_id   INTEGER REFERENCES strat_target_groups(id)   ON DELETE SET NULL,
            action_type_id    INTEGER REFERENCES strat_action_types(id)    ON DELETE SET NULL,
            statute_ref_id    INTEGER REFERENCES strat_statute_refs(id)    ON DELETE SET NULL,
            funding_source_id INTEGER REFERENCES strat_funding_sources(id) ON DELETE SET NULL,
            budzet            DECIMAL(14,2) NOT NULL DEFAULT 0,
            status            TEXT NOT NULL DEFAULT 'planowane',
            owner_id          INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_by        INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at        DATETIME DEFAULT (datetime('now','localtime')),
            updated_at        DATETIME DEFAULT (datetime('now','localtime'))
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS strat_plan_resources (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            plan_id  INTEGER NOT NULL REFERENCES strat_plans(id) ON DELETE CASCADE,
            rodzaj   TEXT NOT NULL DEFAULT 'ludzki',
            nazwa    TEXT NOT NULL,
            user_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            ilosc    DECIMAL(10,2) NOT NULL DEFAULT 1,
            jednostka TEXT,
            koszt    DECIMAL(14,2) NOT NULL DEFAULT 0,
            notatka  TEXT
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_strat_plans_rok ON strat_plans(rok, miesiac)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_strat_plan_res ON strat_plan_resources(plan_id)");

        strat_org_seed($pdo);
    } catch (\Throwable $e) {
        error_log('strat_org_migrate: ' . $e->getMessage());
    }
}

// ── Dane startowe słowników (tylko gdy puste) ────────────────────────────────
function strat_org_seed(PDO $pdo): void {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM strat_target_groups")->fetchColumn();
    if ($n === 0) {
        $groups = [
            ['Seniorzy', '#7c3aed'], ['Młodzież', '#2563eb'], ['Dzieci', '#0891b2'],
            ['Osoby z niepełnosprawnościami', '#059669'], ['Kadra NGO', '#c2410c'],
            ['Społeczność lokalna', '#be185d'],
        ];
        $st = $pdo->prepare("INSERT INTO strat_target_groups (nazwa, kolor, sort_order) VALUES (?,?,?)");
        foreach ($groups as $i => $g) $st->execute([$g[0], $g[1], $i]);
    }
    $n = (int)$pdo->query("SELECT COUNT(*) FROM strat_action_types")->fetchColumn();
    if ($n === 0) {
        $types = [
            ['Warsztaty', 'bi-easel', '#2563eb'], ['Doradztwo', 'bi-chat-square-text', '#0891b2'],
            ['Wydarzenie kulturalne', 'bi-music-note-beamed', '#7c3aed'],
            ['Kampania społeczna', 'bi-megaphone', '#c2410c'], ['Szkolenie', 'bi-mortarboard', '#059669'],
        ];
        $st = $pdo->prepare("INSERT INTO strat_action_types (nazwa, ikona, kolor, sort_order) VALUES (?,?,?,?)");
        foreach ($types as $i => $t) $st->execute([$t[0], $t[1], $t[2], $i]);
    }
}

// ── Stałe słownikowe ─────────────────────────────────────────────────────────
const STRAT_PLAN_STATUSES  = ['planowane', 'w_realizacji', 'zrealizowane', 'anulowane'];
const STRAT_FUNDING_TYPES  = ['projekt', 'dotacja', 'srodki_wlasne'];
const STRAT_RESOURCE_KINDS = ['ludzki', 'sprzetowy', 'finansowy'];

// ── Odczyt danych dla frontu ─────────────────────────────────────────────────

/** Wszystkie słowniki (także nieaktywne — front pokazuje je tylko w ustawieniach). */
function strat_org_dictionaries(): array {
    return [
        'groups'   => db_all("SELECT * FROM strat_target_groups   ORDER BY sort_order, nazwa"),
        'types'    => db_all("SELECT * FROM strat_action_types    ORDER BY sort_order, nazwa"),
        'statutes' => db_all("SELECT * FROM strat_statute_refs    ORDER BY sort_order, kod"),
        'fundings' => db_all("SELECT * FROM strat_funding_sources ORDER BY sort_order, nazwa"),
    ];
}

/** Działania danego roku wraz z zasobami (zasoby dopięte do każdego planu). */
function strat_org_plans(int $rok): array {
    $plans = db_all("SELECT p.*, u.name AS owner_name
                     FROM strat_plans p
                     LEFT JOIN users u ON u.id = p.owner_id
                     WHERE p.rok = ?
                     ORDER BY p.miesiac, p.nazwa", [$rok]);
    if (!$plans) return [];
    $ids = array_column($plans, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $res = db_all("SELECT r.*, u.name AS user_name
                   FROM strat_plan_resources r
                   LEFT JOIN users u ON u.id = r.user_id
                   WHERE r.plan_id IN ($ph) ORDER BY r.rodzaj, r.id", $ids);
    $byPlan = [];
    foreach ($res as $r) $byPlan[(int)$r['plan_id']][] = $r;
    foreach ($plans as &$p) $p['resources'] = $byPlan[(int)$p['id']] ?? [];
    return $plans;
}

/** Lata, w których istnieją plany (do selektora roku). */
function strat_org_years(): array {
    $rows = db_all("SELECT DISTINCT rok FROM strat_plans ORDER BY rok");
    return array_map(fn($r) => (int)$r['rok'], $rows);
}

// ── Walidacja i zapis ────────────────────────────────────────────────────────

/**
 * Walidacja danych działania. Zwraca listę błędów (pusta = OK).
 * Sprawdza też istnienie rekordów słownikowych wskazanych kluczami obcymi.
 */
function strat_org_validate_plan(array $d): array {
    $err = [];
    if (trim((string)($d['nazwa'] ?? '')) === '')      $err[] = 'Nazwa działania jest wymagana.';
    $rok = (int)($d['rok'] ?? 0);
    if ($rok < 2000 || $rok > 2100)                    $err[] = 'Rok poza dopuszczalnym zakresem.';
    $mies = (int)($d['miesiac'] ?? 0);
    if ($mies < 1 || $mies > 12)                       $err[] = 'Wybierz miesiąc (1–12).';
    if (!in_array($d['status'] ?? 'planowane', STRAT_PLAN_STATUSES, true))
        $err[] = 'Nieprawidłowy status.';
    if ((float)($d['budzet'] ?? 0) < 0)                $err[] = 'Budżet nie może być ujemny.';

    // Klucze obce — puste dozwolone, wskazane muszą istnieć
    $fks = [
        'target_group_id'   => 'strat_target_groups',
        'action_type_id'    => 'strat_action_types',
        'statute_ref_id'    => 'strat_statute_refs',
        'funding_source_id' => 'strat_funding_sources',
    ];
    foreach ($fks as $field => $table) {
        $v = (int)($d[$field] ?? 0);
        if ($v > 0 && !db_one("SELECT id FROM $table WHERE id = ?", [$v]))
            $err[] = "Wskazany rekord słownika ($field) nie istnieje.";
    }

    foreach ((array)($d['resources'] ?? []) as $r) {
        if (!in_array($r['rodzaj'] ?? '', STRAT_RESOURCE_KINDS, true))
            { $err[] = 'Nieprawidłowy rodzaj zasobu.'; break; }
        if (trim((string)($r['nazwa'] ?? '')) === '')
            { $err[] = 'Każdy zasób musi mieć nazwę.'; break; }
        if ((float)($r['koszt'] ?? 0) < 0 || (float)($r['ilosc'] ?? 0) < 0)
            { $err[] = 'Ilość i koszt zasobu nie mogą być ujemne.'; break; }
    }
    return $err;
}

/**
 * Zapis działania (insert lub update) wraz z pełną wymianą listy zasobów.
 * Zwraca id zapisanego planu. Rzuca RuntimeException przy błędach walidacji.
 */
function strat_org_save_plan(array $d, int $userId): int {
    $err = strat_org_validate_plan($d);
    if ($err) throw new RuntimeException(implode(' ', $err));

    $pdo = db();
    $id  = (int)($d['id'] ?? 0);
    $fields = [
        'nazwa'             => trim((string)$d['nazwa']),
        'opis'              => trim((string)($d['opis'] ?? '')) ?: null,
        'rok'               => (int)$d['rok'],
        'miesiac'           => (int)$d['miesiac'],
        'target_group_id'   => (int)($d['target_group_id'] ?? 0) ?: null,
        'action_type_id'    => (int)($d['action_type_id'] ?? 0) ?: null,
        'statute_ref_id'    => (int)($d['statute_ref_id'] ?? 0) ?: null,
        'funding_source_id' => (int)($d['funding_source_id'] ?? 0) ?: null,
        'budzet'            => round((float)($d['budzet'] ?? 0), 2),
        'status'            => (string)($d['status'] ?? 'planowane'),
        'owner_id'          => (int)($d['owner_id'] ?? 0) ?: null,
    ];

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            if (!db_one("SELECT id FROM strat_plans WHERE id = ?", [$id]))
                throw new RuntimeException('Działanie nie istnieje.');
            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
            $st  = $pdo->prepare("UPDATE strat_plans SET $set, updated_at = datetime('now','localtime') WHERE id = :id");
            $st->execute($fields + ['id' => $id]);
            $pdo->prepare("DELETE FROM strat_plan_resources WHERE plan_id = ?")->execute([$id]);
        } else {
            $fields['created_by'] = $userId;
            $cols = implode(', ', array_keys($fields));
            $ph   = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
            $pdo->prepare("INSERT INTO strat_plans ($cols) VALUES ($ph)")->execute($fields);
            $id = (int)$pdo->lastInsertId();
        }

        $st = $pdo->prepare("INSERT INTO strat_plan_resources
            (plan_id, rodzaj, nazwa, user_id, ilosc, jednostka, koszt, notatka)
            VALUES (?,?,?,?,?,?,?,?)");
        foreach ((array)($d['resources'] ?? []) as $r) {
            $st->execute([
                $id,
                (string)$r['rodzaj'],
                trim((string)$r['nazwa']),
                (int)($r['user_id'] ?? 0) ?: null,
                round((float)($r['ilosc'] ?? 1), 2),
                trim((string)($r['jednostka'] ?? '')) ?: null,
                round((float)($r['koszt'] ?? 0), 2),
                trim((string)($r['notatka'] ?? '')) ?: null,
            ]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}

/** Usunięcie działania (zasoby kasuje ON DELETE CASCADE). */
function strat_org_delete_plan(int $id): void {
    db_exec("DELETE FROM strat_plan_resources WHERE plan_id = ?", [$id]); // pewność przy wyłączonych FK
    db_exec("DELETE FROM strat_plans WHERE id = ?", [$id]);
}

// ── Słowniki: zapis/usuwanie (panel ustawień, tylko admin) ───────────────────

/** Mapa: klucz słownika → [tabela, dozwolone kolumny]. */
function strat_org_dict_map(): array {
    return [
        'groups'   => ['strat_target_groups',   ['nazwa','opis','kolor','is_active','sort_order']],
        'types'    => ['strat_action_types',    ['nazwa','ikona','kolor','is_active','sort_order']],
        'statutes' => ['strat_statute_refs',    ['kod','tytul','opis','is_active','sort_order']],
        'fundings' => ['strat_funding_sources', ['nazwa','typ','budzet_calkowity','rok','opis','is_active','sort_order']],
    ];
}

function strat_org_dict_save(string $dict, array $d): int {
    $map = strat_org_dict_map();
    if (!isset($map[$dict])) throw new RuntimeException('Nieznany słownik.');
    [$table, $cols] = $map[$dict];

    $data = [];
    foreach ($cols as $c) if (array_key_exists($c, $d)) $data[$c] = $d[$c];
    // Wymagane pola tekstowe
    $required = $dict === 'statutes' ? ['kod','tytul'] : ['nazwa'];
    foreach ($required as $rq)
        if (trim((string)($data[$rq] ?? '')) === '') throw new RuntimeException('Uzupełnij wymagane pola słownika.');
    if ($dict === 'fundings' && !in_array($data['typ'] ?? 'srodki_wlasne', STRAT_FUNDING_TYPES, true))
        throw new RuntimeException('Nieprawidłowy typ źródła finansowania.');

    $id = (int)($d['id'] ?? 0);
    if ($id > 0) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($data)));
        db()->prepare("UPDATE $table SET $set WHERE id = :id")->execute($data + ['id' => $id]);
        return $id;
    }
    return db_insert($table, $data);
}

function strat_org_dict_delete(string $dict, int $id): void {
    $map = strat_org_dict_map();
    if (!isset($map[$dict])) throw new RuntimeException('Nieznany słownik.');
    [$table] = $map[$dict];
    // Rekord użyty w planach — dezaktywuj zamiast usuwać, żeby nie gubić kontekstu
    $fkCol = ['groups'=>'target_group_id','types'=>'action_type_id','statutes'=>'statute_ref_id','fundings'=>'funding_source_id'][$dict];
    $used  = db_one("SELECT id FROM strat_plans WHERE $fkCol = ? LIMIT 1", [$id]);
    if ($used) {
        db_exec("UPDATE $table SET is_active = 0 WHERE id = ?", [$id]);
        throw new RuntimeException('Pozycja jest użyta w działaniach — została dezaktywowana zamiast usunięta.');
    }
    db_exec("DELETE FROM $table WHERE id = ?", [$id]);
}
