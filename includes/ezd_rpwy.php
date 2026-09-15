<?php
/**
 * Rejestr Przesyłek Wychodzących (RPW-W) — książka nadawcza.
 *
 * Odpowiednik dziennika podawczego (ezd_rpw) po stronie wysyłki: każde pismo
 * wychodzące dostaje kolejny numer nadania w roku, sposób wysyłki, numer
 * przesyłki, koszt oraz — dla przesyłek z potwierdzeniem odbioru — datę
 * doręczenia (ZPO / dowód odbioru z e-Doręczeń). Data doręczenia jest punktem
 * odniesienia dla terminów procedury, dlatego wpis może mieć własny termin
 * liczony w dniach od doręczenia.
 *
 * Tabela: ezd_rpwy (samonaprawa schematu przy include).
 */

require_once __DIR__ . '/ezd.php';

const EZD_RPWY_SUBDIR = 'ezd/rpwy/';

/** Sposoby wysyłki. `zpo` = przesyłka, dla której oczekujemy potwierdzenia odbioru. */
const EZD_RPWY_SPOSOBY = [
    'zwykly'       => ['label' => 'List zwykły',                 'icon' => 'bi-envelope',        'zpo' => false],
    'polecony'     => ['label' => 'List polecony',               'icon' => 'bi-envelope-paper',  'zpo' => false],
    'polecony_zpo' => ['label' => 'Polecony za potwierdzeniem odbioru (ZPO)', 'icon' => 'bi-envelope-check', 'zpo' => true],
    'kurier'       => ['label' => 'Kurier',                      'icon' => 'bi-truck',           'zpo' => true],
    'edoreczenia'  => ['label' => 'e-Doręczenia (ADE)',          'icon' => 'bi-mailbox2',        'zpo' => true],
    'email'        => ['label' => 'E-mail',                      'icon' => 'bi-at',              'zpo' => false],
    'osobiscie'    => ['label' => 'Odbiór osobisty za pokwitowaniem', 'icon' => 'bi-person-walking', 'zpo' => true],
    'inne'         => ['label' => 'Inny sposób',                 'icon' => 'bi-question-circle', 'zpo' => false],
];

const EZD_RPWY_STATUSY = [
    'przygotowana' => ['label' => 'Przygotowana', 'class' => 'secondary'],
    'nadana'       => ['label' => 'Nadana',       'class' => 'primary'],
    'doreczona'    => ['label' => 'Doręczona',    'class' => 'success'],
    'zwrocona'     => ['label' => 'Zwrócona',     'class' => 'danger'],
    'anulowana'    => ['label' => 'Anulowana',    'class' => 'dark'],
];

// ── Auto-migracja ─────────────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo  = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS ezd_rpwy (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        rpwy_nr         INTEGER NOT NULL,
        rok             INTEGER NOT NULL,
        pismo_id        INTEGER REFERENCES ezd_pisma(id)  ON DELETE SET NULL,
        sprawa_id       INTEGER REFERENCES ezd_sprawy(id) ON DELETE SET NULL,
        data_wysylki    DATE    NOT NULL,
        sposob          TEXT    NOT NULL DEFAULT 'zwykly',
        odbiorca        TEXT    NOT NULL DEFAULT '',
        adres           TEXT    NOT NULL DEFAULT '',
        ade             TEXT    NOT NULL DEFAULT '',
        nr_nadania      TEXT    NOT NULL DEFAULT '',
        liczba_szt      INTEGER NOT NULL DEFAULT 1,
        koszt           REAL    NOT NULL DEFAULT 0,
        status          TEXT    NOT NULL DEFAULT 'przygotowana',
        data_doreczenia DATE,
        termin_dni      INTEGER NOT NULL DEFAULT 0,
        zwrot_powod     TEXT    NOT NULL DEFAULT '',
        uwagi           TEXT    NOT NULL DEFAULT '',
        epo_file        TEXT    NOT NULL DEFAULT '',
        epo_name        TEXT    NOT NULL DEFAULT '',
        epo_mime        TEXT    NOT NULL DEFAULT '',
        epo_size        INTEGER NOT NULL DEFAULT 0,
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Kolumny dokładane do istniejącej tabeli (idempotentnie)
    foreach ([
        "ALTER TABLE ezd_rpwy ADD COLUMN awizo_date     DATE",
        "ALTER TABLE ezd_rpwy ADD COLUMN doreczenie_typ TEXT NOT NULL DEFAULT 'faktyczne'",
        // Szczegóły z Postivo.pl — patrz ezd_rpwy_apply_postivo_status().
        // nr_nadania (istniejące pole) trzyma numer śledzenia U OPERATORA
        // (np. Poczty Polskiej); postivo_job_id to ID zlecenia w samym Postivo.
        "ALTER TABLE ezd_rpwy ADD COLUMN postivo_job_id      TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE ezd_rpwy ADD COLUMN postivo_operator    TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE ezd_rpwy ADD COLUMN postivo_service_name TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE ezd_rpwy ADD COLUMN postivo_status_name TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE ezd_rpwy ADD COLUMN postivo_dispatch_date DATE",
        "ALTER TABLE ezd_rpwy ADD COLUMN postivo_pages        INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE ezd_rpwy ADD COLUMN postivo_events_json  TEXT NOT NULL DEFAULT '[]'",
        // Poświadczenie NADANIA (generowane od razu, patrz
        // ezd_rpwy_dispatch_cert_pdf()) — OSOBNE od epo_* (to jest dowód
        // DORĘCZENIA/ZPO, skan wraca dopiero po czasie) — inaczej wygenerowanie
        // poświadczenia od razu blokowałoby później wgranie skanu ZPO.
        "ALTER TABLE ezd_rpwy ADD COLUMN nadanie_file TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE ezd_rpwy ADD COLUMN nadanie_name TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE ezd_rpwy ADD COLUMN nadanie_mime TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE ezd_rpwy ADD COLUMN nadanie_size INTEGER NOT NULL DEFAULT 0",
    ] as $alter) {
        try { $pdo->exec($alter); } catch (\Throwable $e) { /* kolumna już istnieje */ }
    }

    foreach ([
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpwy_rok    ON ezd_rpwy(rok, rpwy_nr)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpwy_status ON ezd_rpwy(status)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpwy_pismo  ON ezd_rpwy(pismo_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpwy_sprawa ON ezd_rpwy(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpwy_data   ON ezd_rpwy(data_wysylki)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpwy_dor    ON ezd_rpwy(data_doreczenia)",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (\Throwable $e) {}
    }
})();

// ── Numeracja i etykiety ──────────────────────────────────────────────────────

function _ezd_next_rpwy(int $rok): int {
    $r = db_one("SELECT MAX(rpwy_nr) AS m FROM ezd_rpwy WHERE rok=?", [$rok]);
    return (int)($r['m'] ?? 0) + 1;
}

function ezd_rpwy_label(array $r): string {
    return 'RPW-W ' . $r['rpwy_nr'] . '/' . $r['rok'];
}

function ezd_rpwy_status_badge(string $status): string {
    $s = EZD_RPWY_STATUSY[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . ' bg-opacity-15 text-' . $s['class']
         . ' border border-' . $s['class'] . '" style="font-size:.65rem">' . h($s['label']) . '</span>';
}

/** Czy sposób wysyłki przewiduje potwierdzenie odbioru. */
function ezd_rpwy_wymaga_zpo(string $sposob): bool {
    return (bool)(EZD_RPWY_SPOSOBY[$sposob]['zpo'] ?? false);
}

/** Sposoby wysyłki przewidujące potwierdzenie odbioru. */
function ezd_rpwy_sposoby_zpo(): array {
    return array_keys(array_filter(EZD_RPWY_SPOSOBY, fn($s) => !empty($s['zpo'])));
}

/** Te same sposoby jako fragment listy SQL — klucze pochodzą z kodu, nie z wejścia. */
function _ezd_rpwy_zpo_sql(): string {
    return "'" . implode("','", ezd_rpwy_sposoby_zpo()) . "'";
}

/** Warunek SQL: termin od doręczenia już minął. */
function _ezd_rpwy_po_terminie_sql(string $a = 'w'): string {
    return "$a.termin_dni > 0 AND $a.data_doreczenia IS NOT NULL AND $a.status <> 'anulowana'
            AND date($a.data_doreczenia, '+' || $a.termin_dni || ' days') < date('now')";
}

/** Warunek SQL: nadana za potwierdzeniem odbioru, bez potwierdzenia po $dni dniach. */
function _ezd_rpwy_brak_zpo_sql(int $dni = 21, string $a = 'w'): string {
    $lista = _ezd_rpwy_zpo_sql();
    $dni   = max(1, $dni);
    return "$a.status='nadana' AND $a.data_doreczenia IS NULL
            AND $a.sposob IN ($lista) AND $a.data_wysylki <= date('now','-$dni days')";
}

/** Liczba dni na odbiór przesyłki po awizowaniu — po nich następuje fikcja doręczenia. */
const EZD_RPWY_AWIZO_DNI = 14;

/**
 * Termin liczony od daty doręczenia (dzień doręczenia się nie liczy).
 * Doręczenie może być faktyczne albo przyjęte w trybie fikcji doręczenia.
 * @return array{do:string,dni_do_konca:int,po_terminie:bool,fikcja:bool}|null
 */
function ezd_rpwy_termin(array $r): ?array {
    $dni = (int)($r['termin_dni'] ?? 0);
    if ($dni <= 0 || empty($r['data_doreczenia'])) return null;
    $do   = date('Y-m-d', strtotime($r['data_doreczenia'] . ' +' . $dni . ' days'));
    $diff = (int)floor((strtotime($do) - strtotime(date('Y-m-d'))) / 86400);
    return [
        'do'           => $do,
        'dni_do_konca' => $diff,
        'po_terminie'  => $diff < 0,
        'fikcja'       => ($r['doreczenie_typ'] ?? 'faktyczne') === 'fikcja',
    ];
}

/** Data, z którą przesyłka uznaje się za doręczoną po bezskutecznym awizowaniu. */
function ezd_rpwy_fikcja_data(string $awizo_date): string {
    return date('Y-m-d', strtotime($awizo_date . ' +' . EZD_RPWY_AWIZO_DNI . ' days'));
}

/**
 * Przyjmuje doręczenie w trybie fikcji: przesyłka nieodebrana w terminie
 * uznaje się za doręczoną z upływem okresu na odbiór, licząc od awizowania.
 * Zwrot pozostaje udokumentowany w powodzie zwrotu.
 */
function ezd_rpwy_set_fikcja(int $id, string $awizo_date, int $user_id): void {
    $r = ezd_rpwy_get($id);
    if (!$r) throw new \RuntimeException('Wpis nie istnieje.');
    if (!$awizo_date || !strtotime($awizo_date)) throw new \RuntimeException('Podaj datę awizowania przesyłki.');
    if ($awizo_date > date('Y-m-d')) throw new \RuntimeException('Data awizowania nie może być z przyszłości.');

    $dor = ezd_rpwy_fikcja_data($awizo_date);
    db()->prepare(
        "UPDATE ezd_rpwy SET awizo_date=:aw, data_doreczenia=:dd, doreczenie_typ='fikcja',
         status='doreczona', updated_at=datetime('now') WHERE id=:id"
    )->execute([':aw' => $awizo_date, ':dd' => $dor, ':id' => $id]);

    ezd_log(null, $r['sprawa_id'] ?: null, $r['pismo_id'] ?: null, null, $user_id, 'rpwy_fikcja',
        ezd_rpwy_label($r) . ': fikcja doręczenia — awizowano ' . date_pl($awizo_date)
        . ', doręczenie przyjęte na ' . date_pl($dor));
}

/**
 * Przenosi termin liczony od doręczenia na termin załatwienia koszulki,
 * o ile koszulka nie ma wcześniejszego terminu. Dzięki temu przypomnienia
 * o sprawach obejmują też terminy wynikające z doręczenia.
 * @return string|null ustawiona data albo null, gdy nic nie zmieniono
 */
function ezd_rpwy_apply_termin_do_sprawy(int $id, int $user_id): ?string {
    $r = ezd_rpwy_get($id);
    if (!$r || !$r['sprawa_id']) return null;
    $t = ezd_rpwy_termin($r);
    if (!$t) return null;
    $s = ezd_sprawa_get((int)$r['sprawa_id']);
    if (!$s || !empty($s['ciagla'])) return null;
    if (!empty($s['deadline']) && $s['deadline'] <= $t['do']) return null;

    db()->prepare("UPDATE ezd_sprawy SET deadline=?, updated_at=datetime('now') WHERE id=?")
        ->execute([$t['do'], (int)$r['sprawa_id']]);
    ezd_log(null, (int)$r['sprawa_id'], $r['pismo_id'] ?: null, null, $user_id, 'rpwy_termin_sprawa',
        'Termin koszulki ustawiony na ' . date_pl($t['do']) . ' — ' . (int)$r['termin_dni']
        . ' dni od doręczenia ' . ezd_rpwy_label($r));
    return $t['do'];
}

// ── Terminy i nadzór nad potwierdzeniami (dla crona) ──────────────────────────

/**
 * Przesyłki z terminem liczonym od doręczenia, których termin wypada
 * najpóźniej podanego dnia — do przypomnień.
 */
function ezd_rpwy_terminy_do(string $granica): array {
    $rows = db_all(
        "SELECT w.*, p.sygnatura AS pismo_sygnatura, p.title AS pismo_title, p.owner_id AS pismo_owner_id,
                s.znak_sprawy, s.title AS sprawa_title, s.owner_id AS sprawa_owner_id, s.status AS sprawa_status
         FROM ezd_rpwy w
         LEFT JOIN ezd_pisma  p ON p.id = w.pismo_id
         LEFT JOIN ezd_sprawy s ON s.id = w.sprawa_id
         WHERE w.termin_dni > 0 AND w.data_doreczenia IS NOT NULL AND w.status <> 'anulowana'
         ORDER BY w.data_doreczenia ASC"
    );
    $out = [];
    foreach ($rows as $r) {
        $t = ezd_rpwy_termin($r);
        if (!$t || $t['do'] > $granica) continue;
        if (($r['sprawa_status'] ?? '') === 'closed') continue;
        $r['termin_do']    = $t['do'];
        $r['po_terminie']  = $t['po_terminie'];
        $out[] = $r;
    }
    return $out;
}

/**
 * Przesyłki nadane sposobem przewidującym potwierdzenie odbioru, dla których
 * po $dni dniach nadal nie ma potwierdzenia — brakujące ZPO wymaga reklamacji.
 */
function ezd_rpwy_bez_zpo(int $dni = 21): array {
    if (!ezd_rpwy_sposoby_zpo()) return [];
    return db_all(
        "SELECT w.*, p.sygnatura AS pismo_sygnatura, s.znak_sprawy
         FROM ezd_rpwy w
         LEFT JOIN ezd_pisma  p ON p.id = w.pismo_id
         LEFT JOIN ezd_sprawy s ON s.id = w.sprawa_id
         WHERE " . _ezd_rpwy_brak_zpo_sql($dni) . "
         ORDER BY w.data_wysylki ASC"
    );
}

// ── Odczyt ────────────────────────────────────────────────────────────────────

function ezd_rpwy_get(int $id): ?array {
    return db_one(
        "SELECT w.*, p.sygnatura AS pismo_sygnatura, p.title AS pismo_title, p.kierunek AS pismo_kierunek,
                s.znak_sprawy, s.title AS sprawa_title,
                c.name AS creator_name
         FROM ezd_rpwy w
         LEFT JOIN ezd_pisma  p ON p.id = w.pismo_id
         LEFT JOIN ezd_sprawy s ON s.id = w.sprawa_id
         LEFT JOIN users      c ON c.id = w.created_by
         WHERE w.id=?", [$id]
    );
}

/** Wpis książki nadawczej powiązany z pismem (jeśli istnieje). */
function ezd_rpwy_for_pismo(int $pismo_id): ?array {
    return db_one("SELECT * FROM ezd_rpwy WHERE pismo_id=? ORDER BY id DESC LIMIT 1", [$pismo_id]);
}

/** Filtry: rok, status, sposob, q, od, do. */
function ezd_rpwy_all(array $f = []): array {
    $where = ["1=1"]; $params = [];
    if (($f['rok'] ?? '') !== '')  { $where[] = "w.rok=?";           $params[] = (int)$f['rok']; }
    if (!empty($f['status']))      { $where[] = "w.status=?";        $params[] = $f['status']; }
    if (!empty($f['sposob']))      { $where[] = "w.sposob=?";        $params[] = $f['sposob']; }
    if (($f['flag'] ?? '') === 'po_terminie') $where[] = '(' . _ezd_rpwy_po_terminie_sql() . ')';
    if (($f['flag'] ?? '') === 'brak_zpo')    $where[] = '(' . _ezd_rpwy_brak_zpo_sql() . ')';
    if (!empty($f['od']))          { $where[] = "w.data_wysylki>=?"; $params[] = $f['od']; }
    if (!empty($f['do']))          { $where[] = "w.data_wysylki<=?"; $params[] = $f['do']; }
    if (!empty($f['q'])) {
        $where[] = "(w.odbiorca LIKE ? OR w.adres LIKE ? OR w.nr_nadania LIKE ? OR w.ade LIKE ? OR w.uwagi LIKE ? OR p.title LIKE ? OR p.sygnatura LIKE ?)";
        $q = '%' . $f['q'] . '%';
        for ($i = 0; $i < 7; $i++) $params[] = $q;
    }
    return db_all(
        "SELECT w.*, p.sygnatura AS pismo_sygnatura, p.title AS pismo_title, s.znak_sprawy
         FROM ezd_rpwy w
         LEFT JOIN ezd_pisma  p ON p.id = w.pismo_id
         LEFT JOIN ezd_sprawy s ON s.id = w.sprawa_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY w.rok DESC, w.rpwy_nr DESC
         LIMIT 500",
        $params
    );
}

/** Pozycje książki nadawczej za okres — w kolejności nadania (do wydruku). */
function ezd_rpwy_ksiazka(string $od, string $do): array {
    return db_all(
        "SELECT w.*, p.sygnatura AS pismo_sygnatura, p.title AS pismo_title, s.znak_sprawy
         FROM ezd_rpwy w
         LEFT JOIN ezd_pisma  p ON p.id = w.pismo_id
         LEFT JOIN ezd_sprawy s ON s.id = w.sprawa_id
         WHERE w.data_wysylki BETWEEN ? AND ? AND w.status <> 'anulowana'
         ORDER BY w.data_wysylki ASC, w.rpwy_nr ASC",
        [$od, $do]
    );
}

function ezd_rpwy_stats(): array {
    $rok = (int)date('Y');
    return [
        'do_nadania'  => (int)(db_one("SELECT COUNT(*) c FROM ezd_rpwy WHERE status='przygotowana'")['c'] ?? 0),
        'oczek_zpo'   => (int)(db_one("SELECT COUNT(*) c FROM ezd_rpwy w
             WHERE w.status='nadana' AND w.data_doreczenia IS NULL AND w.sposob IN (" . _ezd_rpwy_zpo_sql() . ")")['c'] ?? 0),
        'zwroty'      => (int)(db_one("SELECT COUNT(*) c FROM ezd_rpwy WHERE status='zwrocona'")['c'] ?? 0),
        'dzis'        => (int)(db_one("SELECT COUNT(*) c FROM ezd_rpwy WHERE data_wysylki=date('now') AND status<>'anulowana'")['c'] ?? 0),
        'rok'         => (int)(db_one("SELECT COUNT(*) c FROM ezd_rpwy WHERE rok=? AND status<>'anulowana'", [$rok])['c'] ?? 0),
        'po_terminie' => (int)(db_one("SELECT COUNT(*) c FROM ezd_rpwy w WHERE " . _ezd_rpwy_po_terminie_sql())['c'] ?? 0),
        'brak_zpo'    => (int)(db_one("SELECT COUNT(*) c FROM ezd_rpwy w WHERE " . _ezd_rpwy_brak_zpo_sql())['c'] ?? 0),
        'koszt_rok'   => (float)(db_one("SELECT COALESCE(SUM(koszt),0) s FROM ezd_rpwy WHERE rok=? AND status<>'anulowana'", [$rok])['s'] ?? 0),
    ];
}

// ── Zapis ─────────────────────────────────────────────────────────────────────

/** Waliduje dane wpisu; zwraca listę błędów (pusta = OK). */
function ezd_rpwy_validate(array $d): array {
    $e = [];
    if (!array_key_exists($d['sposob'] ?? '', EZD_RPWY_SPOSOBY)) $e[] = 'Nieprawidłowy sposób wysyłki.';
    if (trim((string)($d['odbiorca'] ?? '')) === '')             $e[] = 'Odbiorca jest wymagany.';
    if (($d['sposob'] ?? '') === 'edoreczenia' && trim((string)($d['ade'] ?? '')) === '') {
        $e[] = 'Dla e-Doręczeń podaj adres do doręczeń elektronicznych (ADE) odbiorcy.';
    }
    if ((int)($d['liczba_szt'] ?? 1) < 1) $e[] = 'Liczba przesyłek musi być większa od zera.';
    return $e;
}

/** @return array{id:int,rpwy_nr:int,rok:int} */
function ezd_rpwy_create(array $d, int $user_id): array {
    $data = ($d['data_wysylki'] ?? '') ?: date('Y-m-d');
    $rok  = (int)substr($data, 0, 4) ?: (int)date('Y');
    $nr   = _ezd_next_rpwy($rok);
    $st   = $d['status'] ?? 'przygotowana';
    if (!array_key_exists($st, EZD_RPWY_STATUSY)) $st = 'przygotowana';

    db()->prepare(
        "INSERT INTO ezd_rpwy (rpwy_nr,rok,pismo_id,sprawa_id,data_wysylki,sposob,odbiorca,adres,ade,
         nr_nadania,liczba_szt,koszt,status,data_doreczenia,termin_dni,uwagi,created_by)
         VALUES (:nr,:rok,:pid,:sid,:dw,:sp,:odb,:adr,:ade,:nn,:ls,:k,:st,:dd,:td,:uw,:uid)"
    )->execute([
        ':nr'  => $nr,
        ':rok' => $rok,
        ':pid' => ((int)($d['pismo_id']  ?? 0)) ?: null,
        ':sid' => ((int)($d['sprawa_id'] ?? 0)) ?: null,
        ':dw'  => $data,
        ':sp'  => $d['sposob'] ?? 'zwykly',
        ':odb' => trim((string)($d['odbiorca'] ?? '')),
        ':adr' => trim((string)($d['adres'] ?? '')),
        ':ade' => trim((string)($d['ade'] ?? '')),
        ':nn'  => trim((string)($d['nr_nadania'] ?? '')),
        ':ls'  => max(1, (int)($d['liczba_szt'] ?? 1)),
        ':k'   => (float)str_replace(',', '.', (string)($d['koszt'] ?? 0)),
        ':st'  => $st,
        ':dd'  => ($d['data_doreczenia'] ?? '') ?: null,
        ':td'  => max(0, (int)($d['termin_dni'] ?? 0)),
        ':uw'  => trim((string)($d['uwagi'] ?? '')),
        ':uid' => $user_id,
    ]);
    $id = (int)db()->lastInsertId();

    ezd_log(null, ((int)($d['sprawa_id'] ?? 0)) ?: null, ((int)($d['pismo_id'] ?? 0)) ?: null, null,
        $user_id, 'rpwy_create',
        "Wpis do książki nadawczej RPW-W $nr/$rok — " . (EZD_RPWY_SPOSOBY[$d['sposob'] ?? 'zwykly']['label'] ?? '')
        . ', odbiorca: ' . mb_substr(trim((string)($d['odbiorca'] ?? '')), 0, 60));

    return ['id' => $id, 'rpwy_nr' => $nr, 'rok' => $rok];
}

function ezd_rpwy_update(int $id, array $d, int $user_id): void {
    $r = ezd_rpwy_get($id);
    if (!$r) return;
    db()->prepare(
        "UPDATE ezd_rpwy SET data_wysylki=:dw,sposob=:sp,odbiorca=:odb,adres=:adr,ade=:ade,
         nr_nadania=:nn,liczba_szt=:ls,koszt=:k,termin_dni=:td,uwagi=:uw,updated_at=datetime('now')
         WHERE id=:id"
    )->execute([
        ':dw'  => ($d['data_wysylki'] ?? '') ?: $r['data_wysylki'],
        ':sp'  => array_key_exists($d['sposob'] ?? '', EZD_RPWY_SPOSOBY) ? $d['sposob'] : $r['sposob'],
        ':odb' => trim((string)($d['odbiorca'] ?? '')),
        ':adr' => trim((string)($d['adres'] ?? '')),
        ':ade' => trim((string)($d['ade'] ?? '')),
        ':nn'  => trim((string)($d['nr_nadania'] ?? '')),
        ':ls'  => max(1, (int)($d['liczba_szt'] ?? 1)),
        ':k'   => (float)str_replace(',', '.', (string)($d['koszt'] ?? 0)),
        ':td'  => max(0, (int)($d['termin_dni'] ?? 0)),
        ':uw'  => trim((string)($d['uwagi'] ?? '')),
        ':id'  => $id,
    ]);
    ezd_log(null, $r['sprawa_id'] ?: null, $r['pismo_id'] ?: null, null, $user_id, 'rpwy_update',
        'Edytowano ' . ezd_rpwy_label($r));
}

/**
 * Zmiana statusu przesyłki wraz z datami.
 *  nadana    — wymaga (opcjonalnie) numeru nadania; ustawia datę wysyłki
 *  doreczona — ustawia datę doręczenia (punkt odniesienia dla terminów)
 *  zwrocona  — zapisuje powód zwrotu
 *  anulowana — wpis pozostaje w rejestrze (ciągłość numeracji), oznaczony jako anulowany
 */
function ezd_rpwy_set_status(int $id, string $status, array $d, int $user_id): void {
    $r = ezd_rpwy_get($id);
    if (!$r) throw new \RuntimeException('Wpis nie istnieje.');
    if (!array_key_exists($status, EZD_RPWY_STATUSY)) throw new \RuntimeException('Nieprawidłowy status.');

    $set    = ["status=:st", "updated_at=datetime('now')"];
    $params = [':st' => $status, ':id' => $id];
    $opis   = '';

    if ($status === 'nadana') {
        $set[] = "data_wysylki=:dw";
        // Brak daty w żądaniu nie może cofać ani nadpisywać już zarejestrowanej daty nadania
        $params[':dw'] = ($d['data_wysylki'] ?? '') ?: ($r['data_wysylki'] ?: date('Y-m-d'));
        if (($d['nr_nadania'] ?? '') !== '') {
            $set[] = "nr_nadania=:nn";
            $params[':nn'] = trim((string)$d['nr_nadania']);
        }
        if (($d['koszt'] ?? '') !== '') {
            $set[] = "koszt=:k";
            $params[':k'] = (float)str_replace(',', '.', (string)$d['koszt']);
        }
        $opis = 'nadano ' . date_pl($params[':dw']) . (($d['nr_nadania'] ?? '') !== '' ? ', nr ' . $d['nr_nadania'] : '');
    } elseif ($status === 'doreczona') {
        $set[] = "data_doreczenia=:dd";
        $set[] = "doreczenie_typ='faktyczne'";
        $params[':dd'] = ($d['data_doreczenia'] ?? '') ?: date('Y-m-d');
        $opis = 'doręczono ' . date_pl($params[':dd']);
    } elseif ($status === 'zwrocona') {
        $set[] = "zwrot_powod=:zp";
        $params[':zp'] = trim((string)($d['zwrot_powod'] ?? ''));
        $opis = 'zwrot' . ($params[':zp'] !== '' ? ' — ' . $params[':zp'] : '');
    } elseif ($status === 'anulowana') {
        $opis = 'anulowano wpis';
    }

    db()->prepare("UPDATE ezd_rpwy SET " . implode(',', $set) . " WHERE id=:id")->execute($params);

    // Data wysyłki pisma powinna odzwierciedlać faktyczne nadanie
    if ($status === 'nadana' && $r['pismo_id']) {
        try {
            db()->prepare("UPDATE ezd_pisma SET data_wysylki=?, updated_at=datetime('now') WHERE id=? AND (data_wysylki IS NULL OR data_wysylki='')")
                ->execute([$params[':dw'], (int)$r['pismo_id']]);
        } catch (\Throwable $e) {}
    }

    ezd_log(null, $r['sprawa_id'] ?: null, $r['pismo_id'] ?: null, null, $user_id, 'rpwy_status',
        ezd_rpwy_label($r) . ': ' . (EZD_RPWY_STATUSY[$status]['label'] ?? $status) . ($opis ? ' (' . $opis . ')' : ''));
}

/** Wgrywa dowód doręczenia (skan ZPO / dowód odbioru z e-Doręczeń). */
function ezd_rpwy_epo_upload(int $id, string $field, int $user_id): ?string {
    if (empty($_FILES[$field]['tmp_name'])) return 'Nie wybrano pliku.';
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return 'Błąd przesyłania (kod: ' . $f['error'] . ').';
    if ($f['size'] > EZD_MAX_SIZE)     return 'Plik za duży (maks. 25 MB).';
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EZD_ALLOWED_EXT, true)) return 'Niedozwolony format pliku.';

    $dir = UPLOAD_DIR . EZD_RPWY_SUBDIR . $id . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $stored)) return 'Nie udało się zapisać pliku.';

    $r = ezd_rpwy_get($id);
    if ($r && $r['epo_file']) { $old = $dir . $r['epo_file']; if (is_file($old)) @unlink($old); }

    db()->prepare("UPDATE ezd_rpwy SET epo_file=?,epo_name=?,epo_mime=?,epo_size=?,updated_at=datetime('now') WHERE id=?")
        ->execute([$stored, $f['name'], $f['type'] ?: 'application/octet-stream', (int)$f['size'], $id]);
    ezd_log(null, $r['sprawa_id'] ?? null, $r['pismo_id'] ?? null, null, $user_id, 'rpwy_epo',
        'Dodano dowód doręczenia do ' . ($r ? ezd_rpwy_label($r) : '#' . $id));
    return null;
}

/**
 * Zapisuje jako "dowód DORĘCZENIA" (epo_*) surowe bajty dokumentu — skan ZPO /
 * dowód odbioru z e-Doręczeń, wgrywany ręcznie PO tym jak przesyłka wróci.
 * Wariant ezd_rpwy_epo_upload() bez $_FILES.
 *
 * UWAGA: NIE używać dla poświadczenia NADANIA (to co innego — patrz
 * ezd_rpwy_store_nadanie_bytes() niżej; te dwa pola muszą być rozdzielone,
 * bo poświadczenie nadania powstaje od razu, a dowód doręczenia dopiero
 * później, i wgranie jednego nie może blokować drugiego).
 */
function ezd_rpwy_store_epo_bytes(int $id, string $bytes, string $name, string $mime, int $user_id, string $log_note = 'Dodano dowód doręczenia'): void {
    $dir = UPLOAD_DIR . EZD_RPWY_SUBDIR . $id . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $ext    = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'pdf';
    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (file_put_contents($dir . $stored, $bytes) === false) {
        throw new \RuntimeException('Nie udało się zapisać dowodu doręczenia na dysku.');
    }

    $r = ezd_rpwy_get($id);
    if ($r && $r['epo_file']) { $old = $dir . $r['epo_file']; if (is_file($old)) @unlink($old); }

    db()->prepare("UPDATE ezd_rpwy SET epo_file=?,epo_name=?,epo_mime=?,epo_size=?,updated_at=datetime('now') WHERE id=?")
        ->execute([$stored, $name, $mime, strlen($bytes), $id]);
    ezd_log(null, $r['sprawa_id'] ?? null, $r['pismo_id'] ?? null, null, $user_id, 'rpwy_epo',
        $log_note . ' — ' . ($r ? ezd_rpwy_label($r) : '#' . $id));
}

/**
 * Zapisuje jako "poświadczenie NADANIA" (nadanie_*) surowe bajty dokumentu —
 * np. wygenerowane od razu po wysyłce przez Postivo (patrz
 * ezd_rpwy_dispatch_cert_pdf() i ezd/sprawy/quick_dispatch.php). Osobne pole
 * od epo_* (dowód DORĘCZENIA) — patrz uwaga wyżej.
 */
function ezd_rpwy_store_nadanie_bytes(int $id, string $bytes, string $name, string $mime, int $user_id, string $log_note = 'Dodano poświadczenie nadania'): void {
    $dir = UPLOAD_DIR . EZD_RPWY_SUBDIR . $id . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $ext    = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'pdf';
    $stored = 'nadanie_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (file_put_contents($dir . $stored, $bytes) === false) {
        throw new \RuntimeException('Nie udało się zapisać poświadczenia nadania na dysku.');
    }

    $r = ezd_rpwy_get($id);
    if ($r && $r['nadanie_file']) { $old = $dir . $r['nadanie_file']; if (is_file($old)) @unlink($old); }

    db()->prepare("UPDATE ezd_rpwy SET nadanie_file=?,nadanie_name=?,nadanie_mime=?,nadanie_size=?,updated_at=datetime('now') WHERE id=?")
        ->execute([$stored, $name, $mime, strlen($bytes), $id]);
    ezd_log(null, $r['sprawa_id'] ?? null, $r['pismo_id'] ?? null, null, $user_id, 'rpwy_nadanie',
        $log_note . ' — ' . ($r ? ezd_rpwy_label($r) : '#' . $id));
}

function ezd_rpwy_delete(int $id, int $user_id): void {
    $r = ezd_rpwy_get($id);
    if (!$r) return;
    if (in_array($r['status'], ['nadana', 'doreczona', 'zwrocona'], true)) {
        throw new \RuntimeException('Przesyłka została już nadana — wpisu w książce nadawczej nie można usunąć. Użyj statusu „Anulowana".');
    }
    if ($r['epo_file']) {
        $p = UPLOAD_DIR . EZD_RPWY_SUBDIR . $id . '/' . $r['epo_file'];
        if (is_file($p)) @unlink($p);
    }
    db()->prepare("DELETE FROM ezd_rpwy WHERE id=?")->execute([$id]);
    ezd_log(null, $r['sprawa_id'] ?: null, $r['pismo_id'] ?: null, null, $user_id, 'rpwy_delete',
        'Usunięto ' . ezd_rpwy_label($r));
}

/**
 * Tworzy wpis książki nadawczej na podstawie pisma wychodzącego.
 * Idempotentnie — jeśli pismo ma już wpis, zwraca istniejący.
 * @return array{id:int,rpwy_nr:int,rok:int}
 */
function ezd_rpwy_from_pismo(int $pismo_id, array $over, int $user_id): array {
    $exists = ezd_rpwy_for_pismo($pismo_id);
    if ($exists) return ['id' => (int)$exists['id'], 'rpwy_nr' => (int)$exists['rpwy_nr'], 'rok' => (int)$exists['rok']];

    $p = ezd_pismo_get($pismo_id);
    if (!$p) throw new \RuntimeException('Pismo nie istnieje.');

    // Medium pisma → domyślny sposób wysyłki
    $map    = ['email' => 'email', 'epuap' => 'edoreczenia', 'papier' => 'zwykly'];
    $sposob = $over['sposob'] ?? ($map[$p['rodzaj_medium'] ?? 'papier'] ?? 'zwykly');

    return ezd_rpwy_create(array_merge([
        'pismo_id'     => $pismo_id,
        'sprawa_id'    => (int)$p['sprawa_id'],
        'data_wysylki' => ($p['data_wysylki'] ?? '') ?: date('Y-m-d'),
        'sposob'       => $sposob,
        'odbiorca'     => $p['odbiorca'] ?: '—',
        'status'       => ($p['data_wysylki'] ?? '') ? 'nadana' : 'przygotowana',
    ], $over), $user_id);
}

/**
 * Szybka wysyłka z listy dokumentów koszulki: zaznaczone pliki (dowolna
 * liczba) trafiają do JEDNEGO nowego pisma wychodzącego, które dostaje
 * JEDEN wpis w książce nadawczej (liczba_szt = liczba plików) — jedna
 * przesyłka/koperta, niezależnie ile dokumentów zawiera. Zwraca
 * ['pismo_id'=>int, 'id'=>int (rpwy), 'rpwy_nr'=>int, 'rok'=>int].
 */
function ezd_rpwy_quick_dispatch(int $sprawa_id, array $zal_ids, string $title, string $odbiorca, string $sposob, int $user_id): array {
    $zal_ids = array_values(array_unique(array_map('intval', $zal_ids)));
    if (!$zal_ids) throw new \RuntimeException('Nie wybrano żadnego pliku.');
    $title = trim($title) !== '' ? trim($title) : 'Przesyłka wychodząca';

    // Upewnij się, że wskazane pliki naprawdę należą do tej koszulki (nie do
    // innej sprawy podsuniętej przez zmanipulowany request).
    $placeholders = implode(',', array_fill(0, count($zal_ids), '?'));
    $found = db_all(
        "SELECT id FROM ezd_zalaczniki WHERE sprawa_id=? AND id IN ($placeholders)",
        array_merge([$sprawa_id], $zal_ids)
    );
    if (count($found) !== count($zal_ids)) {
        throw new \RuntimeException('Część wskazanych plików nie należy do tej koszulki.');
    }

    $pismo_id = ezd_pismo_create([
        'sprawa_id'     => $sprawa_id,
        'kierunek'      => 'wychodzace',
        'title'         => $title,
        'odbiorca'      => $odbiorca,
        'rodzaj_medium' => 'papier',
        'data_wysylki'  => date('Y-m-d'),
    ], $user_id);

    db()->prepare("UPDATE ezd_zalaczniki SET pismo_id=? WHERE sprawa_id=? AND id IN ($placeholders)")
        ->execute(array_merge([$pismo_id, $sprawa_id], $zal_ids));

    ezd_log(null, $sprawa_id, $pismo_id, null, $user_id, 'quick_dispatch',
        'Szybka rejestracja w wychodzących: ' . count($zal_ids) . ' plik(ów) → pismo #' . $pismo_id);

    $rpwy = ezd_rpwy_from_pismo($pismo_id, [
        'sposob'     => $sposob,
        'odbiorca'   => $odbiorca ?: '—',
        'liczba_szt' => count($zal_ids),
        'status'     => 'przygotowana',
    ], $user_id);

    return ['pismo_id' => $pismo_id] + $rpwy;
}

/**
 * Generuje PDF "poświadczenia nadania" dla wpisu RPW-W, tym samym stylem
 * wizualnym co "Kopia z poświadczeniem" (patrz includes/ezd_kopia.php —
 * ta sama tabela .cert-t, nagłówek systemu, autor wydruku), tylko z danymi
 * wysyłki zamiast danych kopiowanego dokumentu. Używane np. po wysyłce przez
 * Postivo — zamiast (albo obok) surowego "dispatch_cert" z ich API — patrz
 * ezd/sprawy/quick_dispatch.php. Zwraca surowe bajty PDF.
 */
function ezd_rpwy_dispatch_cert_pdf(int $rpwy_id, int $user_id): string {
    require_once __DIR__ . '/ezd_kopia.php';
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    $r = ezd_rpwy_get($rpwy_id);
    if (!$r) throw new \RuntimeException('Wpis RPW-W nie istnieje.');
    $pismo  = $r['pismo_id']  ? ezd_pismo_get((int)$r['pismo_id'])   : null;
    $sprawa = $r['sprawa_id'] ? ezd_sprawa_get((int)$r['sprawa_id']) : null;

    $row = function (string $label, string $value, bool $mono = false) {
        return '<tr><td class="lbl">' . h($label) . '</td>'
             . '<td class="val' . ($mono ? ' mono' : '') . '">' . h($value) . '</td></tr>';
    };

    $html = '<div class="cert">'
        . '<div class="cert-h">Poświadczenie nadania przesyłki:</div>'
        . '<table class="cert-t">'
        . $row('Numer w książce nadawczej (RPW-W)', ezd_rpwy_label($r))
        . ($sprawa ? $row('Znak sprawy (koszulka)', trim(($sprawa['znak_sprawy'] ?? '') . (($sprawa['title'] ?? '') !== '' ? ' — ' . $sprawa['title'] : ''))) : '')
        . ($pismo  ? $row('Pismo', trim(($pismo['sygnatura'] ?? '') . ' — ' . ($pismo['title'] ?? ''))) : '')
        . $row('Odbiorca', (string)($r['odbiorca'] ?: '—'))
        . $row('Adres', (string)($r['adres'] ?: '—'))
        . $row('Sposób wysyłki', EZD_RPWY_SPOSOBY[$r['sposob']]['label'] ?? (string)$r['sposob'])
        . $row('Data nadania', $r['data_wysylki'] ? date_pl($r['data_wysylki']) : '—')
        . $row('Numer nadania', (string)($r['nr_nadania'] ?: '—'))
        . ((float)($r['koszt'] ?? 0) > 0 ? $row('Koszt', number_format((float)$r['koszt'], 2, ',', ' ') . ' zł') : '')
        . '<tr><td class="lbl"></td><td class="val sys">' . h(_ezd_kopia_system_label()) . '</td></tr>'
        . $row('Data wystawienia poświadczenia', date('Y-m-d H:i'))
        . $row('Autor', ezd_kopia_autor($user_id))
        . '</table></div>';

    $tmp = UPLOAD_DIR . 'mpdf_tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4',
        'margin_left'   => 20,
        'margin_right'  => 20,
        'margin_top'    => 18,
        'margin_bottom' => 18,
        'default_font'  => 'dejavusans',
        'tempDir'       => $tmp,
    ]);
    $mpdf->SetTitle('Poświadczenie nadania — ' . ezd_rpwy_label($r));
    $mpdf->SetAuthor(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''));
    $mpdf->SetCreator('EZD ' . (defined('APP_VERSION') ? APP_VERSION : ''));
    $mpdf->WriteHTML(_ezd_kopia_css(), \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->AddPage();
    $mpdf->WriteHTML($html);
    return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

/**
 * Zapisuje w RPW-W szczegóły przesyłki z PostivoClient::get_status() — operator,
 * typ przesyłki, aktualny status, planowaną datę nadania, liczbę stron, numer
 * śledzenia u operatora (nr_nadania) i pełną historię zmian statusu
 * (postivo_events_json). Jeśli status przeszedł w stan finalny (doręczono/
 * zwrócono), odbija to też w polu `status` wpisu — patrz ezd_rpwy_set_status().
 * Wołane po wysyłce (ezd/sprawy/quick_dispatch.php) i cyklicznie
 * (cron/postivo_status_sync.php).
 */
function ezd_rpwy_apply_postivo_status(int $rpwy_id, array $status_data, int $user_id): void {
    $r = ezd_rpwy_get($rpwy_id);
    if (!$r) return;

    $sql = "UPDATE ezd_rpwy SET postivo_job_id=?, postivo_operator=?, postivo_service_name=?,
            postivo_status_name=?, postivo_dispatch_date=?, postivo_pages=?, postivo_events_json=?,
            updated_at=datetime('now')";
    $params = [
        (string)($status_data['job_id']        ?? ''),
        (string)($status_data['operator']      ?? ''),
        (string)($status_data['service_name']  ?? ''),
        (string)($status_data['status_name']   ?? ''),
        ($status_data['dispatch_date'] ?? '') ?: null,
        (int)($status_data['pages'] ?? 0),
        json_encode($status_data['events'] ?? [], JSON_UNESCAPED_UNICODE),
    ];
    $tracking = trim((string)($status_data['tracking'] ?? ''));
    if ($tracking !== '') { $sql .= ", nr_nadania=?"; $params[] = $tracking; }
    $sql .= " WHERE id=?"; $params[] = $rpwy_id;
    db()->prepare($sql)->execute($params);

    // Tylko przejścia, które faktycznie zmieniają sytuację prawną przesyłki —
    // 'processing'/'sent'/'unknown' zostają jako 'nadana' (już tak ustawione
    // przy wysyłce), nie ma co nadpisywać.
    $map = ['delivered' => 'doreczona', 'failed' => 'zwrocona'];
    $new_status = $map[$status_data['status'] ?? ''] ?? null;
    if ($new_status && $r['status'] !== $new_status) {
        $extra = ($status_data['status'] === 'failed')
            ? ['zwrot_powod' => 'Niedostarczone (Postivo: ' . ($status_data['status_name'] ?: 'failed') . ')']
            : [];
        ezd_rpwy_set_status($rpwy_id, $new_status, $extra, $user_id);
    }
}
