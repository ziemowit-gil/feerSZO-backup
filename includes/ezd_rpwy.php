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

    foreach ([
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpwy_rok    ON ezd_rpwy(rok, rpwy_nr)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpwy_status ON ezd_rpwy(status)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpwy_pismo  ON ezd_rpwy(pismo_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpwy_sprawa ON ezd_rpwy(sprawa_id)",
        "CREATE INDEX IF NOT EXISTS idx_ezd_rpwy_data   ON ezd_rpwy(data_wysylki)",
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

/**
 * Termin liczony od daty doręczenia (dzień doręczenia się nie liczy).
 * @return array{do:string,dni_do_konca:int,po_terminie:bool}|null
 */
function ezd_rpwy_termin(array $r): ?array {
    $dni = (int)($r['termin_dni'] ?? 0);
    if ($dni <= 0 || empty($r['data_doreczenia'])) return null;
    $do   = date('Y-m-d', strtotime($r['data_doreczenia'] . ' +' . $dni . ' days'));
    $diff = (int)floor((strtotime($do) - strtotime(date('Y-m-d'))) / 86400);
    return ['do' => $do, 'dni_do_konca' => $diff, 'po_terminie' => $diff < 0];
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
        'oczek_zpo'   => (int)(db_one("SELECT COUNT(*) c FROM ezd_rpwy WHERE status='nadana' AND data_doreczenia IS NULL")['c'] ?? 0),
        'zwroty'      => (int)(db_one("SELECT COUNT(*) c FROM ezd_rpwy WHERE status='zwrocona'")['c'] ?? 0),
        'dzis'        => (int)(db_one("SELECT COUNT(*) c FROM ezd_rpwy WHERE data_wysylki=date('now') AND status<>'anulowana'")['c'] ?? 0),
        'rok'         => (int)(db_one("SELECT COUNT(*) c FROM ezd_rpwy WHERE rok=? AND status<>'anulowana'", [$rok])['c'] ?? 0),
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
        $params[':dw'] = ($d['data_wysylki'] ?? '') ?: date('Y-m-d');
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
