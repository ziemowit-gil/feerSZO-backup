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
        created_by             INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    try { db()->exec("CREATE INDEX IF NOT EXISTS idx_pelnomocnictwa_numer ON pelnomocnictwa(numer)"); } catch (\Throwable $e) {}

    // Samonaprawa: kolumny dodane po pierwszym wdrożeniu (CREATE TABLE IF NOT EXISTS nie
    // modyfikuje już istniejącej tabeli) — ALTER TABLE jest no-op jeśli kolumna już istnieje.
    $cols = [
        "pelnomocnik_pesel       TEXT    NOT NULL DEFAULT ''",
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
            "UPDATE pelnomocnictwa SET numer=?,mocodawca=?,pelnomocnik=?,pelnomocnik_pesel=?,zakres=?,forma=?,
             data_udzielenia=?,data_waznosci=?,data_odwolania=?,uwagi=?,podpisujacy=?,podpisujacy_funkcja=?,
             updated_at=datetime('now') WHERE id=?"
        )->execute([$numer, $mocodawca, $pelnomocnik, $pelnomocnik_pesel, $zakres, $forma, $data_udzielenia, $data_waznosci, $data_odwolania, $uwagi, $podpisujacy, $podpisujacy_funkcja, $id]);
        return $id;
    }

    $numer = trim($d['numer'] ?? '') ?: pelnomocnictwa_suggest_numer();
    db()->prepare(
        "INSERT INTO pelnomocnictwa (numer,mocodawca,pelnomocnik,pelnomocnik_pesel,zakres,forma,data_udzielenia,data_waznosci,data_odwolania,uwagi,podpisujacy,podpisujacy_funkcja,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([$numer, $mocodawca, $pelnomocnik, $pelnomocnik_pesel, $zakres, $forma, $data_udzielenia, $data_waznosci, $data_odwolania, $uwagi, $podpisujacy, $podpisujacy_funkcja, $user_id]);
    return (int)db()->lastInsertId();
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
