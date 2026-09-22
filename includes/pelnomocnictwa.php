<?php
/**
 * Rejestr pełnomocnictw — jedyne źródło prawdy w SZO (samodzielny moduł, poza EZD,
 * bez wymogu zakładania sprawy/koszulki). Pola wzorowane na dawnym rejestrze EZD
 * opartym o klasę JRWA 013 (ezd/pelnomocnictwa/ przekierowuje tu), prowadzony jako
 * jedna, prosta tabela w stylu rejestru byłych osób (includes/byli.php).
 *
 * Status (Ważne / Wygasłe / Odwołane) jest wyliczany z dat, nie przechowywany.
 */

// ── Auto-migracja ─────────────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS pelnomocnictwa (
        id                     INTEGER PRIMARY KEY AUTOINCREMENT,
        numer                  TEXT    NOT NULL DEFAULT '',
        mocodawca              TEXT    NOT NULL DEFAULT '',
        pelnomocnik            TEXT    NOT NULL DEFAULT '',
        pelnomocnik_pesel      TEXT    NOT NULL DEFAULT '',
        pelnomocnik_user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        zwrot                  TEXT    NOT NULL DEFAULT '',
        rodzaj                 TEXT    NOT NULL DEFAULT 'ogolne',
        kor_tryb               TEXT    NOT NULL DEFAULT '',
        kor_szczegoly          TEXT    NOT NULL DEFAULT '',
        zakres                 TEXT    NOT NULL DEFAULT '',
        forma                  TEXT    NOT NULL DEFAULT '',
        data_udzielenia        DATE,
        data_waznosci          DATE,
        data_odwolania         DATE,
        uwagi                  TEXT    NOT NULL DEFAULT '',
        podpisujacy            TEXT    NOT NULL DEFAULT '',
        podpisujacy_funkcja    TEXT    NOT NULL DEFAULT '',
        dokument_plik          TEXT    NOT NULL DEFAULT '',
        dokument_oryginal_nazwa TEXT   NOT NULL DEFAULT '',
        dokument_typ           TEXT    NOT NULL DEFAULT '',
        dokument_uploaded_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        dokument_uploaded_at   DATETIME,
        ezd_sprawa_id          INTEGER,
        created_by             INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    try { db()->exec("CREATE INDEX IF NOT EXISTS idx_pelnomocnictwa_numer ON pelnomocnictwa(numer)"); } catch (\Throwable $e) {}

    // Historia zmian wpisu (audyt) — kto, kiedy, co. Osobna od globalnego admin_audit_log,
    // bo tu potrzebujemy osi czasu przypiętej do konkretnego pełnomocnictwa (peln_id).
    db()->exec("CREATE TABLE IF NOT EXISTS pelnomocnictwa_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        peln_id    INTEGER NOT NULL,
        action     TEXT    NOT NULL DEFAULT '',
        details    TEXT    NOT NULL DEFAULT '',
        user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        user_name  TEXT    NOT NULL DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    try { db()->exec("CREATE INDEX IF NOT EXISTS idx_pelnomocnictwa_log_peln ON pelnomocnictwa_log(peln_id)"); } catch (\Throwable $e) {}

    // Samonaprawa: kolumny dodane po pierwszym wdrożeniu (CREATE TABLE IF NOT EXISTS nie
    // modyfikuje już istniejącej tabeli) — ALTER TABLE jest no-op jeśli kolumna już istnieje.
    $cols = [
        "pelnomocnik_pesel       TEXT    NOT NULL DEFAULT ''",
        "pelnomocnik_user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL",
        "zwrot                   TEXT    NOT NULL DEFAULT ''",
        "rodzaj                  TEXT    NOT NULL DEFAULT 'ogolne'",
        "kor_tryb                TEXT    NOT NULL DEFAULT ''",
        "kor_szczegoly           TEXT    NOT NULL DEFAULT ''",
        "ezd_sprawa_id           INTEGER",
        "podpisujacy             TEXT    NOT NULL DEFAULT ''",
        "podpisujacy_funkcja     TEXT    NOT NULL DEFAULT ''",
        "dokument_plik           TEXT    NOT NULL DEFAULT ''",
        "dokument_oryginal_nazwa TEXT    NOT NULL DEFAULT ''",
        "dokument_typ            TEXT    NOT NULL DEFAULT ''",
        "dokument_uploaded_by    INTEGER REFERENCES users(id) ON DELETE SET NULL",
        "dokument_uploaded_at    DATETIME",
    ];
    foreach ($cols as $def) {
        try { db()->exec("ALTER TABLE pelnomocnictwa ADD COLUMN $def"); } catch (\Throwable $e) {}
    }
})();

// ── Zwrot grzecznościowy ────────────────────────────────────────────────────────

/** Opcje zwrotu grzecznościowego pełnomocnika (klucz => etykieta). */
function pelnomocnictwo_zwroty(): array {
    return ['' => '— (uzupełnisz w dokumencie)', 'pan' => 'Pan', 'pani' => 'Pani'];
}

/** Forma adresatywna w celowniku do dokumentu: „Panu” / „Pani” / (placeholder) „Pani/Panu”. */
function pelnomocnictwo_zwrot_celownik(?string $z): string {
    return match ($z) { 'pan' => 'Panu', 'pani' => 'Pani', default => 'Pani/Panu' };
}

// ── Rodzaje pełnomocnictw ──────────────────────────────────────────────────────

/** Rodzaje pełnomocnictwa (klucz => etykieta). */
function pelnomocnictwa_rodzaje(): array {
    return [
        'ogolne'        => 'Ogólne / do spraw',
        'korespondencja'=> 'Do odbioru korespondencji',
    ];
}

function pelnomocnictwo_rodzaj_label(string $rodzaj): string {
    return pelnomocnictwa_rodzaje()[$rodzaj] ?? pelnomocnictwa_rodzaje()['ogolne'];
}

/** Tryby pełnomocnictwa do odbioru korespondencji (klucz => etykieta). */
function pelnomocnictwo_kor_tryby(): array {
    return [
        'ogolne'     => 'Ogólne (wszelka korespondencja)',
        'konkretne'  => 'Do odbioru konkretnej korespondencji',
        'wylaczenie' => 'Wszelka, z wyłączeniem',
    ];
}

/**
 * Zdanie opisujące zakres pełnomocnictwa do odbioru korespondencji — używane
 * w generowanym dokumencie oraz jako podpowiedź na liście. Zwraca '' dla wpisów,
 * które nie są pełnomocnictwem korespondencyjnym.
 */
function pelnomocnictwo_kor_opis(array $row): string {
    if (($row['rodzaj'] ?? 'ogolne') !== 'korespondencja') return '';
    $tryb  = $row['kor_tryb'] ?? 'ogolne';
    $szcz  = trim((string)($row['kor_szczegoly'] ?? ''));
    $wszelka = 'wszelkiej korespondencji (przesyłek listowych, poleconych, paczek oraz przekazów pocztowych), '
             . 'w tym korespondencji z urzędów, sądów oraz innych organów i instytucji';
    return match ($tryb) {
        'konkretne'  => 'korespondencji obejmującej: ' . ($szcz !== '' ? $szcz : '_______________'),
        'wylaczenie' => $wszelka . ', z wyłączeniem: ' . ($szcz !== '' ? $szcz : '_______________'),
        default      => $wszelka,
    };
}

// ── Status wyliczany z dat ─────────────────────────────────────────────────────

/** @return string 'odwolane'|'wygasle'|'wazne' */
function pelnomocnictwo_status(array $row): string {
    $today = date('Y-m-d');
    if (!empty($row['data_odwolania']) && $row['data_odwolania'] <= $today) return 'odwolane';
    if (!empty($row['data_waznosci']) && $row['data_waznosci'] < $today)   return 'wygasle';
    return 'wazne';
}

/** @return array{0:string,1:string} [etykieta, klasa koloru bootstrap] */
function pelnomocnictwo_status_label(string $status): array {
    return match ($status) {
        'odwolane' => ['Odwołane', 'secondary'],
        'wygasle'  => ['Wygasłe',  'danger'],
        default    => ['Ważne',    'success'],
    };
}

function pelnomocnictwa_statuses(): array {
    return ['wazne' => 'Ważne', 'wygasle' => 'Wygasłe', 'odwolane' => 'Odwołane'];
}

// ── Numeracja ──────────────────────────────────────────────────────────────────

/** Auto-numer w formacie P/{nr:04d}/{rok} — licznik roczny wg roku utworzenia wpisu. */
function pelnomocnictwa_suggest_numer(?int $year = null): string {
    $year = $year ?: (int)date('Y');
    $cnt = (int)(db_one(
        "SELECT COUNT(*) AS cnt FROM pelnomocnictwa WHERE strftime('%Y', created_at) = ?",
        [(string)$year]
    )['cnt'] ?? 0);
    return sprintf('P/%04d/%d', $cnt + 1, $year);
}

// ── Historia zmian (audyt) ─────────────────────────────────────────────────────

/** Dopisuje wpis do historii pełnomocnictwa. Autora bierze z current_user() (UI) lub $user_id. */
function pelnomocnictwo_log(int $peln_id, string $action, string $details = '', ?int $user_id = null): void {
    if ($peln_id <= 0) return;
    $uid = $user_id;
    $uname = '';
    if (function_exists('current_user') && ($u = current_user())) {
        $uid   = $uid ?: (int)($u['id'] ?? 0);
        $uname = (string)($u['name'] ?? '');
    }
    if ($uname === '' && $uid) {
        $r = db_one("SELECT name FROM users WHERE id=?", [$uid]);
        $uname = (string)($r['name'] ?? '');
    }
    try {
        db()->prepare(
            "INSERT INTO pelnomocnictwa_log (peln_id, action, details, user_id, user_name) VALUES (?,?,?,?,?)"
        )->execute([$peln_id, $action, $details, $uid ?: null, $uname]);
    } catch (\Throwable $e) {}
}

/** Oś czasu (od najnowszych) dla danego wpisu. */
function pelnomocnictwo_history(int $peln_id): array {
    try {
        return db_all("SELECT * FROM pelnomocnictwa_log WHERE peln_id=? ORDER BY created_at DESC, id DESC", [$peln_id]);
    } catch (\Throwable $e) { return []; }
}

/** @return array{0:string,1:string} [etykieta, klasa ikony bootstrap] dla akcji z historii. */
function pelnomocnictwo_log_meta(string $action): array {
    return match ($action) {
        'create'      => ['Utworzenie wpisu',      'bi-plus-circle text-success'],
        'update'      => ['Edycja danych',         'bi-pencil text-primary'],
        'revoke'      => ['Odwołanie',             'bi-x-octagon text-danger'],
        'doc_upload'  => ['Dołączono skan',        'bi-paperclip text-secondary'],
        'doc_delete'  => ['Usunięto skan',         'bi-scissors text-warning'],
        'doc_generate'=> ['Wygenerowano dokument', 'bi-file-earmark-richtext text-info'],
        'reminder'    => ['Przypomnienie o wygasaniu', 'bi-bell text-warning'],
        'ezd_koszulka'=> ['Założono koszulkę w EZD', 'bi-folder-plus text-primary'],
        'ezd_pismo'   => ['Skan w koszulce EZD',    'bi-folder-check text-success'],
        default       => [$action ?: 'Zmiana',     'bi-dot text-muted'],
    };
}

// ── CRUD ──────────────────────────────────────────────────────────────────────

/**
 * @param array{q?:string,status?:string,rok?:int} $f
 */
function pelnomocnictwa_all(array $f = []): array {
    $where  = ['1=1'];
    $params = [];

    $q = trim($f['q'] ?? '');
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(p.numer LIKE ? OR p.mocodawca LIKE ? OR p.pelnomocnik LIKE ? OR p.zakres LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($f['rok'])) {
        $where[] = "strftime('%Y', p.created_at) = ?";
        $params[] = (string)(int)$f['rok'];
    }

    $rows = db_all(
        "SELECT p.*, u.name AS creator_name FROM pelnomocnictwa p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE " . implode(' AND ', $where) . "
         ORDER BY p.data_udzielenia DESC, p.id DESC",
        $params
    );

    $status = trim($f['status'] ?? '');
    if ($status !== '') {
        $rows = array_values(array_filter($rows, fn($r) => pelnomocnictwo_status($r) === $status));
    }
    return $rows;
}

function pelnomocnictwo_get(int $id): ?array {
    return db_one("SELECT * FROM pelnomocnictwa WHERE id=?", [$id]);
}

/** Zapis (utworzenie lub aktualizacja). Zwraca id wpisu. */
function pelnomocnictwo_save(int $id, array $d, ?int $user_id): int {
    $mocodawca   = trim($d['mocodawca'] ?? '');
    $pelnomocnik = trim($d['pelnomocnik'] ?? '');
    if ($mocodawca === '')   throw new \RuntimeException('Mocodawca jest wymagany.');
    if ($pelnomocnik === '') throw new \RuntimeException('Pełnomocnik jest wymagany.');

    $pelnomocnik_pesel  = trim($d['pelnomocnik_pesel'] ?? '');
    $pelnomocnik_user_id = (int)($d['pelnomocnik_user_id'] ?? 0) ?: null;
    $zwrot              = in_array(($d['zwrot'] ?? ''), ['pan','pani'], true) ? $d['zwrot'] : '';
    $rodzaj             = array_key_exists(($d['rodzaj'] ?? ''), pelnomocnictwa_rodzaje()) ? $d['rodzaj'] : 'ogolne';
    $kor_tryb           = '';
    $kor_szczegoly      = '';
    if ($rodzaj === 'korespondencja') {
        $kor_tryb      = array_key_exists(($d['kor_tryb'] ?? ''), pelnomocnictwo_kor_tryby()) ? $d['kor_tryb'] : 'ogolne';
        $kor_szczegoly = ($kor_tryb === 'ogolne') ? '' : trim($d['kor_szczegoly'] ?? '');
    }
    $zakres             = trim($d['zakres'] ?? '');
    $forma              = trim($d['forma'] ?? '');
    $data_udzielenia    = ($d['data_udzielenia'] ?? '') ?: null;
    $data_waznosci      = ($d['data_waznosci'] ?? '') ?: null;
    $data_odwolania     = ($d['data_odwolania'] ?? '') ?: null;
    $uwagi              = trim($d['uwagi'] ?? '');
    $podpisujacy         = trim($d['podpisujacy'] ?? '');
    $podpisujacy_funkcja = trim($d['podpisujacy_funkcja'] ?? '');
    // Funkcja podpisującego — jeśli nie podano wprost, uzupełnij z rejestru przedstawicieli organizacji.
    if ($podpisujacy_funkcja === '' && $podpisujacy !== '' && function_exists('org_representatives')) {
        foreach (org_representatives() as $rep) {
            if ($rep['name'] === $podpisujacy) { $podpisujacy_funkcja = $rep['title']; break; }
        }
    }

    if ($id) {
        $numer = trim($d['numer'] ?? '');
        if ($numer === '') {
            $existing = pelnomocnictwo_get($id);
            if (!$existing) throw new \RuntimeException('Wpis nie istnieje.');
            $numer = $existing['numer'];
        }
        db()->prepare(
            "UPDATE pelnomocnictwa SET numer=?,mocodawca=?,pelnomocnik=?,pelnomocnik_pesel=?,pelnomocnik_user_id=?,zwrot=?,rodzaj=?,kor_tryb=?,kor_szczegoly=?,zakres=?,forma=?,
             data_udzielenia=?,data_waznosci=?,data_odwolania=?,uwagi=?,podpisujacy=?,podpisujacy_funkcja=?,
             updated_at=datetime('now') WHERE id=?"
        )->execute([$numer, $mocodawca, $pelnomocnik, $pelnomocnik_pesel, $pelnomocnik_user_id, $zwrot, $rodzaj, $kor_tryb, $kor_szczegoly, $zakres, $forma, $data_udzielenia, $data_waznosci, $data_odwolania, $uwagi, $podpisujacy, $podpisujacy_funkcja, $id]);
        pelnomocnictwo_log($id, 'update', 'Zaktualizowano dane wpisu.', $user_id);
        return $id;
    }

    $numer = trim($d['numer'] ?? '') ?: pelnomocnictwa_suggest_numer();
    db()->prepare(
        "INSERT INTO pelnomocnictwa (numer,mocodawca,pelnomocnik,pelnomocnik_pesel,pelnomocnik_user_id,zwrot,rodzaj,kor_tryb,kor_szczegoly,zakres,forma,data_udzielenia,data_waznosci,data_odwolania,uwagi,podpisujacy,podpisujacy_funkcja,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([$numer, $mocodawca, $pelnomocnik, $pelnomocnik_pesel, $pelnomocnik_user_id, $zwrot, $rodzaj, $kor_tryb, $kor_szczegoly, $zakres, $forma, $data_udzielenia, $data_waznosci, $data_odwolania, $uwagi, $podpisujacy, $podpisujacy_funkcja, $user_id]);
    $newId = (int)db()->lastInsertId();
    pelnomocnictwo_log($newId, 'create', 'Dodano pełnomocnictwo ' . $numer . ' dla: ' . $pelnomocnik . '.', $user_id);
    return $newId;
}

/**
 * Szybkie odwołanie pełnomocnictwa wprost z listy: ustawia datę odwołania
 * (domyślnie dzisiaj) i loguje zdarzenie. Nie nadpisuje już odwołanego wpisu.
 */
function pelnomocnictwo_revoke(int $id, ?string $date, ?int $user_id): void {
    $row = pelnomocnictwo_get($id);
    if (!$row) throw new \RuntimeException('Wpis nie istnieje.');
    if (!empty($row['data_odwolania'])) throw new \RuntimeException('Pełnomocnictwo jest już odwołane.');

    $date = ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ? $date : date('Y-m-d');
    db()->prepare("UPDATE pelnomocnictwa SET data_odwolania=?, updated_at=datetime('now') WHERE id=?")
        ->execute([$date, $id]);
    pelnomocnictwo_log($id, 'revoke', 'Odwołano ze skutkiem na ' . pelnomocnictwo_data_slownie($date) . '.', $user_id);
}

function pelnomocnictwo_delete(int $id): void {
    $row = pelnomocnictwo_get($id);
    if ($row && $row['dokument_plik']) {
        @unlink(dirname(__DIR__) . '/uploads/pelnomocnictwa/' . $row['dokument_plik']);
    }
    db()->prepare("DELETE FROM pelnomocnictwa WHERE id=?")->execute([$id]);
}

/** Data słownie po polsku, np. „21 stycznia 2026”. Puste wejście → ''. */
function pelnomocnictwo_data_slownie(?string $ymd): string {
    if (!$ymd) return '';
    $ts = strtotime($ymd);
    if (!$ts) return '';
    static $miesiace = [1=>'stycznia','lutego','marca','kwietnia','maja','czerwca',
        'lipca','sierpnia','września','października','listopada','grudnia'];
    return (int)date('j', $ts) . ' ' . $miesiace[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Rozbija pole „zakres” (jeden wpis na linię) na listę punktów do dokumentu. */
function pelnomocnictwo_zakres_items(array $row): array {
    $lines = preg_split('/\r\n|\r|\n/', (string)($row['zakres'] ?? ''));
    return array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
}

/**
 * Dane organizacji do generowanych dokumentów (mocodawca = zawsze organizacja).
 * Konfigurowalne w Ustawieniach organizacji (admin/org_settings.php).
 */
function pelnomocnictwa_org_ident(): array {
    return [
        'nazwa'       => org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''),
        'adres'       => org_setting('org_adres') ?: '',
        'miejscowosc' => org_setting('org_miejscowosc') ?: '',
        'krs'         => org_setting('org_krs') ?: '',
        'nip'         => org_setting('org_nip') ?: '',
        'sad'         => org_setting('org_sad_rejestrowy') ?: '',
    ];
}

/** Zapisuje przesłany skan podpisanego dokumentu, powiązany z wpisem rejestru. Zwraca komunikat błędu lub null. */
function pelnomocnictwo_upload(int $id, int $user_id, string $typ = ''): ?string {
    $f = $_FILES['dokument'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) return 'Nie wybrano pliku.';

    $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return 'Niedozwolony typ pliku (dozwolone: PDF, JPG, PNG).';
    if ($f['size'] > 20 * 1024 * 1024) return 'Plik zbyt duży (maks. 20 MB).';

    $row = pelnomocnictwo_get($id);
    if (!$row) return 'Wpis nie istnieje.';

    $dir = dirname(__DIR__) . '/uploads/pelnomocnictwa/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if ($row['dokument_plik']) @unlink($dir . $row['dokument_plik']);

    $slug   = preg_replace('/[^A-Za-z0-9]+/', '_', $row['numer']) ?: (string)$id;
    $stored = 'peln_' . $slug . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $stored)) return 'Błąd zapisu pliku.';

    db()->prepare(
        "UPDATE pelnomocnictwa SET dokument_plik=?, dokument_oryginal_nazwa=?, dokument_typ=?,
         dokument_uploaded_by=?, dokument_uploaded_at=datetime('now') WHERE id=?"
    )->execute([$stored, $f['name'], $typ, $user_id, $id]);
    pelnomocnictwo_log($id, 'doc_upload', 'Dołączono skan (' . ($typ === 'odwolanie' ? 'odwołanie' : 'pełnomocnictwo') . '): ' . $f['name'], $user_id);
    return null;
}

/** Usuwa przesłany skan dokumentu z wpisu (plik + metadane). */
function pelnomocnictwo_document_delete(int $id): void {
    $row = pelnomocnictwo_get($id);
    if (!$row || !$row['dokument_plik']) return;
    @unlink(dirname(__DIR__) . '/uploads/pelnomocnictwa/' . $row['dokument_plik']);
    db()->prepare(
        "UPDATE pelnomocnictwa SET dokument_plik='', dokument_oryginal_nazwa='', dokument_typ='',
         dokument_uploaded_by=NULL, dokument_uploaded_at=NULL WHERE id=?"
    )->execute([$id]);
    pelnomocnictwo_log($id, 'doc_delete', 'Usunięto dołączony skan: ' . ($row['dokument_oryginal_nazwa'] ?: ''));
}

/**
 * Pełnomocnictwa, w których dana osoba jest pełnomocnikiem — po powiązaniu z kontem
 * (pelnomocnik_user_id) LUB, dla starszych wpisów bez powiązania, po dopasowaniu nazwiska.
 * Do widżetu „Moje pełnomocnictwa" w panelu i widżetu Zastępstwa w EZD.
 *
 * @param bool $only_active tylko ważne (domyślnie true)
 */
function pelnomocnictwa_for_user(int $user_id, string $name = '', bool $only_active = true): array {
    $where  = ['(p.pelnomocnik_user_id = ?'];
    $params = [$user_id];
    if (trim($name) !== '') { $where[0] .= ' OR p.pelnomocnik = ?'; $params[] = trim($name); }
    $where[0] .= ')';
    try {
        $rows = db_all(
            "SELECT p.* FROM pelnomocnictwa p WHERE " . implode(' AND ', $where) .
            " ORDER BY p.data_udzielenia DESC, p.id DESC",
            $params
        );
    } catch (\Throwable $e) { return []; }
    if ($only_active) {
        $rows = array_values(array_filter($rows, fn($r) => pelnomocnictwo_status($r) === 'wazne'));
    }
    return $rows;
}

/**
 * Ważne pełnomocnictwa wygasające w ciągu najbliższych $days dni (włącznie).
 * Pomija bezterminowe (brak data_waznosci) i już odwołane/wygasłe.
 * Zwraca posortowane rosnąco po dacie ważności (najpilniejsze pierwsze).
 */
function pelnomocnictwa_expiring(int $days = 30): array {
    $today = date('Y-m-d');
    $limit = date('Y-m-d', strtotime("+{$days} day"));
    try {
        $rows = db_all(
            "SELECT p.*, u.name AS creator_name FROM pelnomocnictwa p
             LEFT JOIN users u ON u.id = p.created_by
             WHERE p.data_waznosci IS NOT NULL AND p.data_waznosci <> ''
               AND (p.data_odwolania IS NULL OR p.data_odwolania = '' OR p.data_odwolania > ?)
               AND date(p.data_waznosci) >= date(?) AND date(p.data_waznosci) <= date(?)
             ORDER BY p.data_waznosci ASC, p.id ASC",
            [$today, $today, $limit]
        );
    } catch (\Throwable $e) { return []; }
    // Dodatkowe zabezpieczenie: status wyliczany (na wypadek odwołania z datą dzisiejszą).
    return array_values(array_filter($rows, fn($r) => pelnomocnictwo_status($r) === 'wazne'));
}

/**
 * Czy wpis wymaga wgrania podpisanego skanu — wymuszamy podpis dla wszystkich
 * nieodwołanych pełnomocnictw, które nie mają jeszcze dołączonego dokumentu.
 */
function pelnomocnictwo_needs_signature(array $row): bool {
    if (!empty($row['dokument_plik'])) return false;
    return pelnomocnictwo_status($row) !== 'odwolane';
}

/** Nieodwołane pełnomocnictwa bez podpisanego skanu — do banera „domagaj się podpisu". */
function pelnomocnictwa_missing_signature(): array {
    try {
        $rows = db_all(
            "SELECT * FROM pelnomocnictwa WHERE (dokument_plik IS NULL OR dokument_plik = '')
             ORDER BY data_udzielenia DESC, id DESC"
        );
    } catch (\Throwable $e) { return []; }
    return array_values(array_filter($rows, 'pelnomocnictwo_needs_signature'));
}

/**
 * Statyczny HTML dokumentu (do eksportu PDF i załączania do koszulki EZD).
 * Odpowiada wizualnie widokowi ekranowemu (pelnomocnictwa/dokument.php), ale
 * bez pól edytowalnych — zwrot Pan/Pani jest już rozstrzygnięty, „w imieniu”
 * używa nazwy organizacji. $typ = 'pelnomocnictwo'|'odwolanie'.
 */
function pelnomocnictwo_pdf_html(array $row, string $typ = 'pelnomocnictwo'): string {
    $typ = $typ === 'odwolanie' ? 'odwolanie' : 'pelnomocnictwo';
    $org = pelnomocnictwa_org_ident();
    $dzis = date('Y-m-d');
    $is_kor   = ($row['rodzaj'] ?? 'ogolne') === 'korespondencja';
    $kor_opis = $is_kor ? pelnomocnictwo_kor_opis($row) : '';
    $zwrot_c  = pelnomocnictwo_zwrot_celownik($row['zwrot'] ?? '');
    $data_dok = $typ === 'odwolanie' ? ($row['data_odwolania'] ?: $dzis) : ($row['data_udzielenia'] ?: $dzis);
    $data_dok_slow = pelnomocnictwo_data_slownie($data_dok) ?: date('d.m.Y');
    $data_udz_slow = pelnomocnictwo_data_slownie($row['data_udzielenia'] ?: null);
    $miejsc   = $org['miejscowosc'] ?: '_______________';
    $w_imieniu = $org['nazwa'] ?: 'Fundacji';
    $items    = pelnomocnictwo_zakres_items($row);

    $tytul = $is_kor
        ? ($typ === 'odwolanie' ? 'Odwołanie pełnomocnictwa do odbioru korespondencji' : 'Pełnomocnictwo do odbioru korespondencji')
        : ($typ === 'odwolanie' ? 'Odwołanie pełnomocnictwa' : 'Pełnomocnictwo');

    $pesel = $row['pelnomocnik_pesel'] ? ' (PESEL: ' . h($row['pelnomocnik_pesel']) . ')' : '';

    ob_start(); ?>
<style>
  body { font-family: 'DejaVu Serif', serif; font-size: 11pt; color:#000; line-height:1.55; }
  .doc-date { text-align:right; margin-bottom:20pt; }
  .doc-title { text-align:center; font-weight:bold; text-transform:uppercase; margin-bottom:16pt; }
  .doc-body p { text-align:justify; margin:0 0 8pt; }
  ol.doc-list { margin:2pt 0 10pt 18pt; }
  ol.doc-list li { text-align:justify; margin-bottom:3pt; }
  .doc-sign { margin-top:48pt; text-align:right; }
  .doc-sign .line { border-top:1px solid #000; display:inline-block; width:220px; padding-top:3pt; font-weight:bold; }
</style>
<div class="doc-date"><?= h($miejsc) ?>, dnia <?= h($data_dok_slow) ?></div>
<div class="doc-title"><?= h($tytul) ?></div>
<div class="doc-body">
<p>ja, niżej podpisany/a <strong><?= h($row['podpisujacy'] ?: '_______________') ?></strong>,
jako <?= h($row['podpisujacy_funkcja'] ?: '_______________') ?> <strong><?= h($org['nazwa']) ?></strong>
z siedzibą przy <?= h($org['adres'] ?: '_______________') ?>,
wpisanej do rejestru stowarzyszeń Krajowego Rejestru Sądowego,
<?= h($org['sad'] ?: '') ?> pod nr: <?= h($org['krs'] ?: '_______________') ?>,
posiadającej NIP: <?= h($org['nip'] ?: '_______________') ?>, uprawniony do jednoosobowej reprezentacji;</p>

<?php if ($typ === 'odwolanie'): ?>
<p>odwołuję pełnomocnictwo udzielone <?= h($zwrot_c) ?></p>
<p>1) <strong><?= h($row['pelnomocnik']) ?></strong><?= $pesel ?><?= $data_udz_slow ? ' w dniu ' . h($data_udz_slow) . 'r.' : '' ?></p>
<?php else: ?>
<p>udzielam pełnomocnictwa <?= h($zwrot_c) ?></p>
<p>1) <strong><?= h($row['pelnomocnik']) ?></strong><?= $pesel ?></p>
<?php endif; ?>

<?php if ($is_kor): ?>
<p>do odbioru w imieniu <?= h($w_imieniu) ?> <?= h($kor_opis) ?>.</p>
<?php else: ?>
<p>do działania w imieniu <?= h($w_imieniu) ?>, w sprawach:</p>
<ol class="doc-list">
  <?php foreach ($items as $it): ?><li><?= h($it) ?></li><?php endforeach; ?>
  <?php if (!$items): ?><li>_______________</li><?php endif; ?>
</ol>
<?php endif; ?>

<?php if ($typ === 'pelnomocnictwo'): ?>
<p><?= $row['data_waznosci']
      ? 'Pełnomocnictwo obowiązuje do dnia ' . h(pelnomocnictwo_data_slownie($row['data_waznosci'])) . 'r.'
      : 'Pełnomocnictwo ma charakter bezterminowy, do czasu jego odwołania.' ?></p>
<?php endif; ?>
</div>
<div class="doc-sign"><span class="line"><?= h($row['podpisujacy'] ?: '_______________') ?></span></div>
<?php
    return (string)ob_get_clean();
}

/** Renderuje dokument do bajtów PDF przez mPDF. Zwraca [bajty, nazwa_pliku] lub null. */
function pelnomocnictwo_pdf_render(array $row, string $typ = 'pelnomocnictwo'): ?array {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) return null;
    require_once $autoload;
    if (!class_exists('\\Mpdf\\Mpdf')) return null;

    $tmp = (defined('UPLOAD_DIR') ? UPLOAD_DIR : sys_get_temp_dir() . '/') . 'mpdf_tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0755, true);

    try {
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 25, 'margin_right' => 25, 'margin_top' => 20, 'margin_bottom' => 18,
            'default_font' => 'dejavuserif', 'tempDir' => $tmp,
        ]);
        $tytul = ($typ === 'odwolanie' ? 'Odwolanie pelnomocnictwa ' : 'Pelnomocnictwo ') . ($row['numer'] ?? '');
        $mpdf->SetTitle($tytul);
        $mpdf->WriteHTML(pelnomocnictwo_pdf_html($row, $typ));
        $slug = preg_replace('/[^A-Za-z0-9\-_]/', '_', ($typ === 'odwolanie' ? 'Odwolanie_' : 'Pelnomocnictwo_') . ($row['numer'] ?? $row['id'] ?? ''));
        return [$mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN), $slug . '.pdf'];
    } catch (\Throwable $e) {
        return null;
    }
}

/** Statystyki do widżetu/dashboardu: liczba wg statusu. */
function pelnomocnictwa_stats(): array {
    $out = ['wazne' => 0, 'wygasle' => 0, 'odwolane' => 0, 'total' => 0];
    foreach (db_all("SELECT data_waznosci, data_odwolania FROM pelnomocnictwa") as $r) {
        $out[pelnomocnictwo_status($r)]++;
        $out['total']++;
    }
    return $out;
}
