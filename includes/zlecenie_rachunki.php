<?php
/**
 * includes/zlecenie_rachunki.php
 * Rejestr RACHUNKÓW umowy zlecenie — dokumenty wgrywane do systemu przez
 * księgowego lub opiekuna umowy, udostępniane zleceniobiorcy linkiem z tokenem.
 *
 * To OSOBNY rejestr od includes/rozliczenia.php:
 *   - rozliczenia  → „zlecenie wystawienia rachunku” (dane dla księgowego, e-mail + PDF),
 *   - rachunki     → konkretny dokument (plik) + powiadomienie zleceniobiorcy,
 *                    śledzenie pobrania, akceptacji i zapłaty, przekazanie do EOD (KDOK).
 *
 * Jedna umowa ma wiele rachunków. Historia zdarzeń trafia do contract_audit_log
 * przez log_contract_action() (wywoływane po stronie widoku / api).
 *
 * Wymaga wcześniejszego załadowania includes/db.php i includes/functions.php.
 */

require_once __DIR__ . '/db.php';

// ── Auto-migracja (tworzy tabelę + dokłada kolumny) ─────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS zlecenie_rachunki (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_type    TEXT NOT NULL DEFAULT 'zlecenie',
            contract_id      INTEGER NOT NULL,
            status           TEXT NOT NULL DEFAULT 'nowy',
            test_mode        INTEGER DEFAULT 0,
            numer            TEXT,
            data_wystawienia TEXT,
            okres            TEXT,
            kwota_brutto     REAL,
            uwagi            TEXT,
            rozliczenie_id   INTEGER,
            plik             TEXT,
            plik_nazwa       TEXT,
            plik_size        INTEGER,
            plik_sha256      TEXT,
            plik_podpisany       TEXT,
            plik_podpisany_nazwa TEXT,
            plik_podpisany_size  INTEGER,
            signed_at        DATETIME,
            signed_ip        TEXT,
            kdok_doc_id      INTEGER,
            kdok_number      TEXT,
            notify_token     TEXT,
            notified_at      DATETIME,
            notified_to_email TEXT,
            notify_mail_queue_id INTEGER,
            downloaded_at    DATETIME,
            download_count   INTEGER DEFAULT 0,
            accepted_by      INTEGER,
            accepted_at      DATETIME,
            paid_at          DATETIME,
            created_by       INTEGER,
            created_at       DATETIME,
            updated_at       DATETIME
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_zrach_contract ON zlecenie_rachunki(contract_type, contract_id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_zrach_token ON zlecenie_rachunki(notify_token)");
        db()->exec("CREATE TABLE IF NOT EXISTS zlecenie_rachunek_komentarze (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            rachunek_id INTEGER NOT NULL,
            author_type TEXT NOT NULL DEFAULT 'user',
            author_id   INTEGER,
            author_name TEXT,
            tresc       TEXT NOT NULL,
            ip          TEXT,
            created_at  DATETIME
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_zrach_kom ON zlecenie_rachunek_komentarze(rachunek_id)");
        // Dokładanie kolumn do istniejących tabel (idempotentnie, jak w rozliczenia.php)
        foreach ([
            'okres_od'             => 'TEXT',   // YYYY-MM — początek okresu rachunku
            'okres_do'             => 'TEXT',   // YYYY-MM — koniec okresu (rachunek za kilka miesięcy)
            'rozliczenie_id'       => 'INTEGER',
            'plik_sha256'          => 'TEXT',
            'kdok_doc_id'          => 'INTEGER',
            'kdok_number'          => 'TEXT',
            'downloaded_at'        => 'DATETIME',
            'download_count'       => 'INTEGER',
            'accepted_by'          => 'INTEGER',
            'accepted_at'          => 'DATETIME',
            'paid_at'              => 'DATETIME',
            'test_mode'            => 'INTEGER',
            'plik_podpisany'       => 'TEXT',
            'plik_podpisany_nazwa' => 'TEXT',
            'plik_podpisany_size'  => 'INTEGER',
            'signed_at'            => 'DATETIME',
            'signed_ip'            => 'TEXT',
            // Obieg w EODoK (zastąpił KDOK dla rachunków od EDOK_CUTOFF_RACHUNEK) —
            // pojedynczy dokument albo lista płac, na której rachunek został ujęty.
            'edok_doc_id'          => 'INTEGER',
            'edok_number'          => 'TEXT',
        ] as $col => $def) {
            try { db()->exec("ALTER TABLE zlecenie_rachunki ADD COLUMN {$col} {$def}"); } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {
        error_log('[zlecenie_rachunki migrate] ' . $e->getMessage());
    }
})();

const ZLEC_RACHUNEK_STATUSES = [
    'nowy'          => ['label' => 'Nowy',                      'class' => 'secondary'],
    'przekazany'    => ['label' => 'Przekazany zleceniobiorcy', 'class' => 'info'],
    'pobrany'       => ['label' => 'Pobrany',                   'class' => 'primary'],
    'podpisany'     => ['label' => 'Podpisany — wgrany',        'class' => 'dark'],
    'zaakceptowany' => ['label' => 'Zaakceptowany',             'class' => 'success'],
    'zaplacony'     => ['label' => 'Zapłacony',                 'class' => 'success'],
    'odrzucony'     => ['label' => 'Odrzucony',                 'class' => 'danger'],
];

/** Statusy, które są jeszcze „w toku” — do licznika na zakładce. */
const ZLEC_RACHUNEK_OPEN_STATUSES = ['nowy', 'przekazany', 'pobrany', 'podpisany'];

/** Terminy dostarczenia podpisanego rachunku (dni od udostępnienia). */
const ZLEC_RACHUNEK_SKAN_DAYS     = 2;  // skan podpisanego rachunku e-mailem
const ZLEC_RACHUNEK_ORYGINAL_DAYS = 7;  // oryginał pocztą / osobiście

function rachunek_status_badge(string $status): string {
    $s = ZLEC_RACHUNEK_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

function rachunek_status_label(string $status): string {
    return ZLEC_RACHUNEK_STATUSES[$status]['label'] ?? $status;
}

// ── Tryb testowy i numeracja ───────────────────────────────────────────────────

/**
 * Rachunek testowy — służy do przećwiczenia obiegu (mail, link, podpis,
 * komentarze) bez skutków księgowych. Nie trafia do EOD Dokumentów Księgowych,
 * nie dostaje numeru RACH/… i nie wycisza banera „Wyślij rachunek”.
 */
function rachunek_is_test(array $rach): bool {
    return !empty($rach['test_mode']);
}

/**
 * Kolejny numer rachunku w masce RACH/{nr}/{MM}/{RRRR}, np. RACH/3/08/2026.
 * Licznik startuje od 1 w każdym miesiącu; brany jest najwyższy dotąd nadany
 * numer w tym miesiącu, żeby usunięcie rekordu nie powodowało kolizji.
 * Numery dostają wyłącznie rachunki nietestowe.
 */
function rachunek_next_number(?string $date = null): string {
    $t  = $date ? strtotime($date) : false;
    $t  = $t ?: time();
    $mm = date('m', $t);
    $yy = date('Y', $t);
    $next = 1;
    try {
        $rows = db_all(
            "SELECT numer FROM zlecenie_rachunki WHERE numer LIKE ?",
            ['RACH/%/' . $mm . '/' . $yy]
        );
        foreach ($rows as $r) {
            if (preg_match('#^RACH/(\d+)/' . $mm . '/' . $yy . '$#', (string)$r['numer'], $m)) {
                $next = max($next, (int)$m[1] + 1);
            }
        }
    } catch (\Throwable $e) {}
    return 'RACH/' . $next . '/' . $mm . '/' . $yy;
}

/**
 * Nadaje numer rachunkowi nietestowemu, który go jeszcze nie ma.
 * Wywoływane przy tworzeniu i przy zdjęciu oznaczenia „testowy”.
 *
 * @return string Nadany (lub istniejący) numer; '' dla rachunku testowego.
 */
function rachunek_assign_number(int $rid): string {
    $r = get_rachunek($rid);
    if (!$r || rachunek_is_test($r)) return '';
    if (trim((string)($r['numer'] ?? '')) !== '') return (string)$r['numer'];
    $numer = rachunek_next_number($r['data_wystawienia'] ?? ($r['created_at'] ?? null));
    update_rachunek($rid, ['numer' => $numer]);
    return $numer;
}

/** Przełącza tryb testowy; przy wyłączeniu nadaje numer, jeśli go brakuje. */
function rachunek_set_test_mode(int $rid, bool $test): array {
    $r = get_rachunek($rid);
    if (!$r) return ['ok' => false, 'msg' => 'Nie znaleziono rachunku.'];
    if (!$test && !empty($r['kdok_doc_id'])) {
        // Dokument jest już w obiegu księgowym — nie da się go „odtestować” wstecz.
        return ['ok' => false, 'msg' => 'Rachunek jest już w obiegu księgowym.'];
    }
    if ($test && !empty($r['edok_doc_id'])) {
        return ['ok' => false, 'msg' => 'Nie można oznaczyć jako testowy — rachunek trafił już do EODoK ('
              . ($r['edok_number'] ?: '#' . (int)$r['edok_doc_id']) . ').'];
    }
    if ($test && !empty($r['kdok_doc_id'])) {
        return ['ok' => false, 'msg' => 'Nie można oznaczyć jako testowy — rachunek trafił już do EOD ('
              . ($r['kdok_number'] ?: '#' . (int)$r['kdok_doc_id']) . ').'];
    }
    update_rachunek($rid, ['test_mode' => $test ? 1 : 0]);
    $numer = $test ? '' : rachunek_assign_number($rid);
    return ['ok' => true, 'test' => $test, 'numer' => $numer,
            'msg' => $test ? 'Oznaczono jako rachunek testowy.'
                           : 'Zdjęto oznaczenie testowe.' . ($numer ? ' Numer: ' . $numer : '')];
}

// ── Odczyt ─────────────────────────────────────────────────────────────────────

/** Lista rachunków dla umowy (najnowsze pierwsze). */
function get_rachunki(string $type, int $id): array {
    try {
        return db_all(
            "SELECT r.*, c.name AS created_by_name, a.name AS accepted_by_name
             FROM zlecenie_rachunki r
             LEFT JOIN users c ON c.id = r.created_by
             LEFT JOIN users a ON a.id = r.accepted_by
             WHERE r.contract_type = ? AND r.contract_id = ?
             ORDER BY r.id DESC",
            [$type, $id]
        );
    } catch (\Throwable $e) {
        return [];
    }
}

function get_rachunek(int $rid): ?array {
    try {
        return db_one(
            "SELECT r.*, c.name AS created_by_name, a.name AS accepted_by_name
             FROM zlecenie_rachunki r
             LEFT JOIN users c ON c.id = r.created_by
             LEFT JOIN users a ON a.id = r.accepted_by
             WHERE r.id = ?",
            [$rid]
        );
    } catch (\Throwable $e) {
        return null;
    }
}

function get_rachunki_count(string $type, int $id): int {
    try {
        return (int)(db_one(
            "SELECT COUNT(*) AS c FROM zlecenie_rachunki WHERE contract_type=? AND contract_id=?",
            [$type, $id]
        )['c'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

/** Ile rachunków czeka na dalsze kroki (do badge'a na zakładce). */
function get_rachunki_open_count(string $type, int $id): int {
    try {
        $ph = implode(',', array_fill(0, count(ZLEC_RACHUNEK_OPEN_STATUSES), '?'));
        return (int)(db_one(
            "SELECT COUNT(*) AS c FROM zlecenie_rachunki
             WHERE contract_type=? AND contract_id=? AND status IN ({$ph})",
            array_merge([$type, $id], ZLEC_RACHUNEK_OPEN_STATUSES)
        )['c'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

// ── Zapis ──────────────────────────────────────────────────────────────────────

/** Tworzy rekord rachunku. $data: contract_id + numer/data_wystawienia/okres/kwota_brutto/uwagi/plik*. */
function create_rachunek(array $data, ?int $user_id, string $type = 'zlecenie'): int {
    $now  = date('Y-m-d H:i:s');
    $test = !empty($data['test_mode']);

    // Rachunek nietestowy bez własnego numeru dostaje numer z maski RACH/{nr}/{MM}/{RRRR}.
    $numer = trim((string)($data['numer'] ?? ''));
    if ($numer === '' && !$test) {
        $numer = rachunek_next_number($data['data_wystawienia'] ?? null);
    }

    $rid = db_insert('zlecenie_rachunki', [
        'contract_type'    => $type,
        'contract_id'      => (int)($data['contract_id'] ?? 0),
        'status'           => $data['status'] ?? 'nowy',
        'numer'            => $numer !== '' ? $numer : null,
        'data_wystawienia' => ($data['data_wystawienia'] ?? '') !== '' ? $data['data_wystawienia'] : null,
        'okres'            => ($data['okres'] ?? '') !== '' ? $data['okres']
                              : ((($data['okres_od'] ?? '') !== '') ? rachunek_okres_label((string)$data['okres_od'], (string)($data['okres_do'] ?? '')) : null),
        'okres_od'         => ($data['okres_od'] ?? '') !== '' ? $data['okres_od'] : null,
        'okres_do'         => ($data['okres_do'] ?? '') !== '' ? $data['okres_do'] : (($data['okres_od'] ?? '') !== '' ? $data['okres_od'] : null),
        'kwota_brutto'     => ($data['kwota_brutto'] ?? '') === '' || $data['kwota_brutto'] === null
                              ? null : (float)$data['kwota_brutto'],
        'uwagi'            => ($data['uwagi'] ?? '') !== '' ? $data['uwagi'] : null,
        'rozliczenie_id'   => !empty($data['rozliczenie_id']) ? (int)$data['rozliczenie_id'] : null,
        'plik'             => $data['plik']        ?? null,
        'plik_nazwa'       => $data['plik_nazwa']  ?? null,
        'plik_size'        => $data['plik_size']   ?? null,
        'plik_sha256'      => $data['plik_sha256'] ?? null,
        'test_mode'        => $test ? 1 : 0,
        'download_count'   => 0,
        'created_by'       => $user_id,
        'created_at'       => $now,
        'updated_at'       => $now,
    ]);
    return $rid;
}

function update_rachunek(int $rid, array $data): void {
    $data['updated_at'] = date('Y-m-d H:i:s');
    db_update('zlecenie_rachunki', $data, $rid);
}

/** Zmiana statusu + znaczniki akceptacji/zapłaty. */
function rachunek_set_status(int $rid, string $status, ?int $user_id): void {
    if (!isset(ZLEC_RACHUNEK_STATUSES[$status])) return;
    $data = ['status' => $status];
    if ($status === 'zaakceptowany') {
        $data['accepted_by'] = $user_id;
        $data['accepted_at'] = date('Y-m-d H:i:s');
    }
    if ($status === 'zaplacony') {
        $data['paid_at'] = date('Y-m-d H:i:s');
    }
    update_rachunek($rid, $data);
}

/** Trwałe usunięcie rachunku razem z plikiem (tylko admin — egzekwowane w api/ajax.php). */
function delete_rachunek(int $rid): void {
    $r = get_rachunek($rid);
    foreach (['plik', 'plik_podpisany'] as $col) {
        if ($r && !empty($r[$col])) {
            $abs = rachunek_file_abs($r[$col]);
            if ($abs && is_file($abs)) @unlink($abs);
        }
    }
    try { db()->prepare("DELETE FROM zlecenie_rachunek_komentarze WHERE rachunek_id=?")->execute([$rid]); } catch (\Throwable $e) {}
    db()->prepare("DELETE FROM zlecenie_rachunki WHERE id=?")->execute([$rid]);
}

// ── Plik rachunku ──────────────────────────────────────────────────────────────

const ZLEC_RACHUNEK_UPLOAD_SUBDIR = 'zlecenie_rachunki/';
const ZLEC_RACHUNEK_MAX_BYTES     = 30 * 1024 * 1024; // 30 MB — jak w EOD Dok. Księgowych

/** Rozszerzenie → dozwolone typy MIME (weryfikacja realnej zawartości pliku). */
const ZLEC_RACHUNEK_MIMES = [
    'pdf'  => ['application/pdf'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
];

/**
 * Przyjmuje wpis z $_FILES i zapisuje plik w uploads/zlecenie_rachunki/.
 * Zwraca ['rel','name','size','sha256'] albo rzuca RuntimeException.
 */
function _rachunek_store_upload(array $f): array {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Błąd wysyłania pliku (kod: ' . ($f['error'] ?? '?') . ').');
    }
    if (($f['size'] ?? 0) <= 0) {
        throw new RuntimeException('Plik jest pusty.');
    }
    if ($f['size'] > ZLEC_RACHUNEK_MAX_BYTES) {
        throw new RuntimeException('Plik nie może przekraczać 30 MB.');
    }
    $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
    if (!isset(ZLEC_RACHUNEK_MIMES[$ext])) {
        throw new RuntimeException('Dozwolone formaty: PDF, JPG, PNG, DOC, DOCX.');
    }
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!in_array($mime, ZLEC_RACHUNEK_MIMES[$ext], true)) {
        throw new RuntimeException('Zawartość pliku nie odpowiada rozszerzeniu .' . $ext . ' (wykryto: ' . $mime . ').');
    }

    $dir = rtrim(UPLOAD_DIR, '/') . '/' . ZLEC_RACHUNEK_UPLOAD_SUBDIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Nie można utworzyć katalogu na pliki rachunków.');
    }
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) {
        throw new RuntimeException('Nie można zapisać pliku na serwerze.');
    }
    return [
        'rel'    => ZLEC_RACHUNEK_UPLOAD_SUBDIR . $name,
        'name'   => mb_substr(basename($f['name']), 0, 200),
        'size'   => (int)$f['size'],
        'sha256' => hash_file('sha256', $dir . $name) ?: null,
    ];
}

/** Plik rachunku wgrywany przez pracownika — klucze kolumn tabeli. */
function rachunek_store_file(array $f): array {
    $u = _rachunek_store_upload($f);
    return [
        'plik'        => $u['rel'],
        'plik_nazwa'  => $u['name'],
        'plik_size'   => $u['size'],
        'plik_sha256' => $u['sha256'],
    ];
}

/** Skan podpisanego rachunku wgrywany przez zleceniobiorcę — klucze kolumn tabeli. */
function rachunek_store_signed_file(array $f): array {
    $u = _rachunek_store_upload($f);
    return [
        'plik_podpisany'       => $u['rel'],
        'plik_podpisany_nazwa' => $u['name'],
        'plik_podpisany_size'  => $u['size'],
    ];
}

/** Bezwzględna ścieżka pliku rachunku (null, gdy ścieżka wychodzi poza katalog uploads). */
function rachunek_file_abs(?string $rel): ?string {
    if (!$rel) return null;
    $base = realpath(rtrim(UPLOAD_DIR, '/'));
    if ($base === false) return null;
    $abs  = $base . '/' . ltrim($rel, '/');
    $real = realpath($abs);
    if ($real === false) return null;
    return str_starts_with($real, $base . DIRECTORY_SEPARATOR) ? $real : null;
}

/** Rozmiar pliku w czytelnej postaci. */
function rachunek_file_size_h(?int $bytes): string {
    if (!$bytes) return '';
    if ($bytes < 1024)         return $bytes . ' B';
    if ($bytes < 1024 * 1024)  return round($bytes / 1024) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

// ── Token dostępu dla zleceniobiorcy ───────────────────────────────────────────

/** Generuje (lub zwraca istniejący) token dostępu do rachunku. */
function rachunek_ensure_token(int $rid): string {
    $row = db_one("SELECT notify_token FROM zlecenie_rachunki WHERE id=?", [$rid]);
    if (!empty($row['notify_token'])) return $row['notify_token'];
    $token = bin2hex(random_bytes(24));
    db()->prepare("UPDATE zlecenie_rachunki SET notify_token=? WHERE id=?")->execute([$token, $rid]);
    return $token;
}

/** Publiczny adres strony rachunku dla zleceniobiorcy. */
function rachunek_public_url(int $rid): string {
    return rtrim(APP_URL, '/') . '/contracts/zlecenie/rachunek_pobierz.php?token='
         . urlencode(rachunek_ensure_token($rid));
}

/** Pobiera rachunek po tokenie (bez logowania — dla zleceniobiorcy). */
function get_rachunek_by_token(string $token): ?array {
    if ($token === '') return null;
    try {
        return db_one("SELECT * FROM zlecenie_rachunki WHERE notify_token = ?", [$token]);
    } catch (\Throwable $e) {
        return null;
    }
}

/** Odnotowuje pobranie pliku przez zleceniobiorcę (pierwsze pobranie zmienia status). */
function rachunek_mark_downloaded(int $rid): void {
    $r = get_rachunek($rid);
    if (!$r) return;
    $data = [
        'downloaded_at'  => date('Y-m-d H:i:s'),
        'download_count' => (int)($r['download_count'] ?? 0) + 1,
    ];
    if (in_array($r['status'], ['nowy', 'przekazany'], true)) $data['status'] = 'pobrany';
    update_rachunek($rid, $data);
}

/** Zapisuje metadane wysłanego powiadomienia do zleceniobiorcy. */
function rachunek_notify_mark(int $rid, string $email, ?int $mail_id): void {
    $r = get_rachunek($rid);
    $data = [
        'notified_at'          => date('Y-m-d H:i:s'),
        'notified_to_email'    => $email,
        'notify_mail_queue_id' => $mail_id,
    ];
    if (($r['status'] ?? '') === 'nowy') $data['status'] = 'przekazany';
    update_rachunek($rid, $data);
}

// ── Powiadomienia ──────────────────────────────────────────────────────────────

/**
 * Wysyła do zleceniobiorcy e-mail „Nowy rachunek w systemie” z linkiem do pobrania.
 * Treść pochodzi z konfigurowalnego szablonu `zlecenie_rachunek_new`
 * (admin/email_templates.php) — tu jest tylko podstawienie zmiennych.
 *
 * @return array{ok:bool, msg:string, email?:string, url?:string, mail_id?:int}
 */
function rachunek_notify_contractor(int $rid, ?int $actor_id = null): array {
    $rach = get_rachunek($rid);
    if (!$rach) return ['ok' => false, 'msg' => 'Nie znaleziono rachunku.'];

    $contract = db_one("SELECT * FROM umowy_zlecenie WHERE id=?", [(int)$rach['contract_id']]);
    if (!$contract) return ['ok' => false, 'msg' => 'Nie znaleziono umowy.'];

    $email = trim((string)($contract['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => 'Zleceniobiorca nie ma poprawnego adresu e-mail w umowie — uzupełnij go w zakładce Zleceniobiorca.'];
    }

    require_once __DIR__ . '/mail_queue.php';
    require_once __DIR__ . '/email_templates.php';

    $org   = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Organizacja');
    $imie  = (string)($contract['imie_nazwisko'] ?? '');
    $numer = (string)($contract['numer_umowy'] ?? '');
    $url   = rachunek_public_url($rid);

    // Terminy liczone od chwili udostępnienia rachunku (czyli od tej wysyłki).
    $dl    = rachunek_deadlines(['notified_at' => date('Y-m-d H:i:s'), 'created_at' => $rach['created_at'] ?? null]);
    $skan  = rachunek_skan_email();
    $adres = rachunek_org_address();

    $vars = [
        'org'            => htmlspecialchars($org, ENT_QUOTES, 'UTF-8'),
        'name'           => htmlspecialchars($imie, ENT_QUOTES, 'UTF-8'),
        'numer'          => htmlspecialchars($numer, ENT_QUOTES, 'UTF-8'),
        'okres'          => htmlspecialchars((string)($rach['okres'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'numer_rachunku' => htmlspecialchars((string)($rach['numer'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'url'            => htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
        'skan_email'     => htmlspecialchars($skan, ENT_QUOTES, 'UTF-8'),
        'skan_days'      => (string)ZLEC_RACHUNEK_SKAN_DAYS,
        'oryginal_days'  => (string)ZLEC_RACHUNEK_ORYGINAL_DAYS,
        'skan_deadline'     => $dl['skan']     ? ' (do ' . date_pl($dl['skan']) . ')'     : '',
        'oryginal_deadline' => $dl['oryginal'] ? ' (do ' . date_pl($dl['oryginal']) . ')' : '',
        'adres'          => $adres ? ' na adres: ' . htmlspecialchars($adres, ENT_QUOTES, 'UTF-8') : '',
    ];

    $tpl = email_tpl_render('zlecenie_rachunek_new', $vars);
    if (empty($tpl['enabled'])) {
        return ['ok' => false, 'msg' => 'Szablon „Nowy rachunek w systemie” jest wyłączony w Ustawieniach → Szablony e-mail.'];
    }
    $subject   = $tpl['subject'] ?: ('Nowy rachunek w systemie – ' . $org);
    // Rachunek testowy — odbiorca musi od razu widzieć, że to próba obiegu.
    if (rachunek_is_test($rach)) $subject = '[TEST] ' . $subject;
    // Wiadomość idzie WYŁĄCZNIE jako zwykły tekst (text/plain): treść to szablon z Ustawień → Szablony e-mail
    // zamieniony na tekst (linki jako adresy, bez przycisku), z zapasową treścią gdy szablon jest pusty.
    $tpl_raw   = email_tpl_get('zlecenie_rachunek_new');   // sama treść, bez ramki układu HTML
    $body_text = $tpl_raw ? mail_html_to_text(email_tpl_substitute((string)$tpl_raw['body'], $vars)) : '';
    if ($body_text === '') {
        $txt_skan = $dl['skan']     ? ' (do ' . date_pl($dl['skan']) . ')'     : '';
        $txt_oryg = $dl['oryginal'] ? ' (do ' . date_pl($dl['oryginal']) . ')' : '';
        $body_text = "Dzień dobry,\n\n"
            . "Informujemy, że w systemie został wygenerowany nowy rachunek"
            . ($numer ? " do umowy {$numer}" : '') . ".\n\n"
            . "Możesz przejść do niego bezpośrednio pod poniższym adresem:\n{$url}\n\n"
            . "Prosimy o pobranie dokumentu oraz dopełnienie dalszych kroków związanych z jego rozliczeniem.\n"
            . "Rachunek należy wydrukować, podpisać odręcznie i dostarczyć jednym z dwóch sposobów:\n\n"
            . "Opcja 1 (szybciej): wgraj skan podpisanego rachunku pod powyższym linkiem, a oryginał dostarcz\n"
            . "  w ciągu " . ZLEC_RACHUNEK_ORYGINAL_DAYS . " dni{$txt_oryg}.\n"
            . "Opcja 2: w ciągu " . ZLEC_RACHUNEK_SKAN_DAYS . " dni{$txt_skan} prześlij skan podpisanego rachunku\n"
            . "  na adres {$skan}, a następnie w ciągu " . ZLEC_RACHUNEK_ORYGINAL_DAYS . " dni{$txt_oryg} dostarcz oryginał"
            . ($adres ? " na adres: {$adres}" : '') . ".\n\n"
            . "W razie pytań lub problemów technicznych pozostajemy do dyspozycji.\n\n"
            . "Z poważaniem,\n{$org}";
    }
    $body_html = '';   // pusty HTML = kolejka wysyła czysty text/plain (SMTP i Graph)

    // DW — stała kopia wysyłki rachunków (bez adresu głównego odbiorcy).
    $cc = array_values(array_filter(rachunek_cc_emails(),
        fn($e) => strcasecmp($e, $email) !== 0));

    try {
        $mail_id = mail_queue_add($email, $imie, $subject, $body_html, $body_text,
            'zlecenie', (int)$rach['contract_id'], '', true, [], '', $cc);
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Błąd wysyłki: ' . $e->getMessage()];
    }

    rachunek_notify_mark($rid, $email, $mail_id);

    return ['ok' => true,
            'msg'   => 'Wysłano powiadomienie do: ' . $email . ($cc ? ' (DW: ' . implode(', ', $cc) . ')' : ''),
            'email' => $email, 'cc' => $cc, 'url' => $url, 'mail_id' => $mail_id];
}

/**
 * Informuje o nowym rachunku osoby prowadzące umowę: opiekuna (contract_supervisors)
 * oraz autora umowy (created_by). Powiadomienie w systemie + e-mail.
 * Autor akcji nie dostaje powiadomienia o własnym działaniu.
 *
 * @return string[] Lista adresów/nazw, które zostały powiadomione.
 */
function rachunek_notify_internal(int $rid, ?int $actor_id = null): array {
    $rach = get_rachunek($rid);
    if (!$rach) return [];
    $contract = db_one("SELECT * FROM umowy_zlecenie WHERE id=?", [(int)$rach['contract_id']]);
    if (!$contract) return [];

    require_once __DIR__ . '/supervisors.php';
    require_once __DIR__ . '/notifications.php';

    $targets = [];
    $sup = supervisor_get('zlecenie', (int)$rach['contract_id']);
    if ($sup && !empty($sup['user_id'])) $targets[(int)$sup['user_id']] = true;
    if (!empty($contract['created_by']))  $targets[(int)$contract['created_by']] = true;
    if ($actor_id) unset($targets[(int)$actor_id]);
    if (!$targets) return [];

    $numer = (string)($contract['numer_umowy'] ?? '');
    $osoba = (string)($contract['imie_nazwisko'] ?? '');
    $okres = (string)($rach['okres'] ?? '');
    $kwota = $rach['kwota_brutto'] !== null ? money((float)$rach['kwota_brutto']) : '';
    $url   = rtrim(APP_URL, '/') . '/contracts/zlecenie/view.php?id=' . (int)$rach['contract_id'] . '&tab=rachunki';

    $title = 'Nowy rachunek do umowy ' . ($numer ?: '#' . (int)$rach['contract_id']);
    $body  = trim($osoba . ($okres ? ' · ' . $okres : '') . ($kwota ? ' · ' . $kwota : ''));

    require_once __DIR__ . '/mail_queue.php';
    $org  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Organizacja');
    $done = [];

    foreach (array_keys($targets) as $uid) {
        try { notif_create($uid, 'contract', $title, $body, $url); } catch (\Throwable $e) {}
        $u = db_one("SELECT name, email FROM users WHERE id=? AND is_active=1", [$uid]);
        if (!$u || empty($u['email']) || !filter_var($u['email'], FILTER_VALIDATE_EMAIL)) continue;
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#212529;line-height:1.6">'
              . '<p>Dzień dobry' . (!empty($u['name']) ? ', ' . h($u['name']) : '') . ',</p>'
              . '<p>Do umowy zlecenie' . ($numer ? ' <strong>' . h($numer) . '</strong>' : '')
              . ($osoba ? ' (' . h($osoba) . ')' : '') . ' dodano nowy rachunek'
              . ($okres ? ' za okres <strong>' . h($okres) . '</strong>' : '')
              . ($kwota ? ' na kwotę <strong>' . h($kwota) . '</strong>' : '') . '.</p>'
              . '<p style="margin:20px 0"><a href="' . h($url) . '" '
              . 'style="background:#2563eb;color:#fff;padding:10px 22px;border-radius:5px;text-decoration:none;font-weight:bold">'
              . 'Otwórz rejestr rachunków</a></p>'
              . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0">'
              . '<p style="color:#888;font-size:12px">' . h($org) . '</p></div>';
        try {
            mail_queue_add($u['email'], $u['name'] ?? '', $title . ' — ' . $org, $html, '',
                'zlecenie', (int)$rach['contract_id'], '', true);
            $done[] = $u['email'];
        } catch (\Throwable $e) {}
    }
    return $done;
}

// ── Podpisany rachunek: terminy i odbiór ───────────────────────────────────────

/** Adres, na który zleceniobiorca ma wysłać skan podpisanego rachunku. */
function rachunek_skan_email(): string {
    $v = trim((string)org_setting('rachunek_skan_email'));
    if ($v !== '') return $v;
    $v = trim((string)org_setting('org_email'));
    if ($v !== '') return $v;
    return defined('ORG_EMAIL') ? ORG_EMAIL : 'fundacja@feer.org.pl';
}

/**
 * Adresy DW (kopia) dla wysyłki rachunków do zleceniobiorcy.
 * Ustawienie `rachunek_cc_emails` — lista rozdzielona przecinkami lub średnikami.
 * Domyślnie ziemowit.gil@feer.org.pl, żeby żadna wysyłka nie przeszła bez kopii.
 */
function rachunek_cc_emails(): array {
    $raw = trim((string)org_setting('rachunek_cc_emails'));
    if ($raw === '') $raw = 'ziemowit.gil@feer.org.pl';
    $out = [];
    foreach (preg_split('/[,;\s]+/', $raw) as $e) {
        $e = trim($e);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[strtolower($e)] = $e;
    }
    return array_values($out);
}

/** Adres siedziby organizacji — dla dostarczenia oryginału. */
function rachunek_org_address(): string {
    $adres  = trim((string)org_setting('org_adres'));
    $miasto = trim((string)org_setting('org_miejscowosc'));
    // Miasto dopisujemy tylko, gdy nie ma go już w adresie („…, 30-554 Kraków, Kraków”).
    if ($miasto !== '' && mb_stripos($adres, $miasto) !== false) $miasto = '';
    return trim($adres . ($miasto ? ', ' . $miasto : ''), ', ');
}

/**
 * Terminy dostarczenia podpisanego rachunku, liczone od udostępnienia go
 * zleceniobiorcy (data powiadomienia, a gdy go nie było — data dodania).
 *
 * @return array{start:?string, skan:?string, oryginal:?string, skan_left:?int, oryginal_left:?int}
 */
function rachunek_deadlines(array $rach): array {
    $start = $rach['notified_at'] ?: ($rach['created_at'] ?? null);
    if (!$start) {
        return ['start' => null, 'skan' => null, 'oryginal' => null, 'skan_left' => null, 'oryginal_left' => null];
    }
    $t0 = strtotime($start);
    if ($t0 === false) {
        return ['start' => null, 'skan' => null, 'oryginal' => null, 'skan_left' => null, 'oryginal_left' => null];
    }
    $skan = strtotime('+' . ZLEC_RACHUNEK_SKAN_DAYS . ' days', $t0);
    $oryg = strtotime('+' . ZLEC_RACHUNEK_ORYGINAL_DAYS . ' days', $t0);
    $today = strtotime(date('Y-m-d'));
    return [
        'start'         => date('Y-m-d', $t0),
        'skan'          => date('Y-m-d', $skan),
        'oryginal'      => date('Y-m-d', $oryg),
        'skan_left'     => (int)floor((strtotime(date('Y-m-d', $skan)) - $today) / 86400),
        'oryginal_left' => (int)floor((strtotime(date('Y-m-d', $oryg)) - $today) / 86400),
    ];
}

/** Czy skan podpisanego rachunku już wpłynął (którąkolwiek drogą). */
function rachunek_is_signed(array $rach): bool {
    return !empty($rach['signed_at']) || !empty($rach['plik_podpisany'])
        || in_array($rach['status'] ?? '', ['podpisany', 'zaakceptowany', 'zaplacony'], true);
}

/**
 * Zapisuje podpisany rachunek wgrany przez zleceniobiorcę i przestawia status.
 * Poprzedni plik podpisany (jeśli był) jest usuwany — liczy się ostatnia wersja.
 */
function rachunek_mark_signed(int $rid, array $file_cols, string $ip = ''): void {
    $prev = get_rachunek($rid);
    if ($prev && !empty($prev['plik_podpisany']) && ($prev['plik_podpisany'] !== ($file_cols['plik_podpisany'] ?? null))) {
        $abs = rachunek_file_abs($prev['plik_podpisany']);
        if ($abs && is_file($abs)) @unlink($abs);
    }
    update_rachunek($rid, $file_cols + [
        'signed_at' => date('Y-m-d H:i:s'),
        'signed_ip' => mb_substr($ip, 0, 45),
        'status'    => 'podpisany',
    ]);
}

/**
 * Informuje opiekuna umowy, jej autora i księgowego, że wpłynął podpisany rachunek.
 *
 * @return string[] Adresy, na które poszło powiadomienie.
 */
function rachunek_notify_signed(int $rid): array {
    $rach = get_rachunek($rid);
    if (!$rach) return [];
    $contract = db_one("SELECT * FROM umowy_zlecenie WHERE id=?", [(int)$rach['contract_id']]);
    if (!$contract) return [];

    require_once __DIR__ . '/supervisors.php';
    require_once __DIR__ . '/notifications.php';
    require_once __DIR__ . '/mail_queue.php';

    $numer = (string)($contract['numer_umowy'] ?? '');
    $osoba = (string)($contract['imie_nazwisko'] ?? '');
    $url   = rtrim(APP_URL, '/') . '/contracts/zlecenie/view.php?id=' . (int)$rach['contract_id'] . '&tab=rachunki';
    $org   = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Organizacja');
    $title = 'Podpisany rachunek wpłynął — umowa ' . ($numer ?: '#' . (int)$rach['contract_id']);
    $body  = trim($osoba . (!empty($rach['numer']) ? ' · rachunek ' . $rach['numer'] : ''));

    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#212529;line-height:1.6">'
          . '<p>Dzień dobry,</p>'
          . '<p>' . ($osoba ? '<strong>' . h($osoba) . '</strong>' : 'Zleceniobiorca')
          . ' wgrał(a) podpisany rachunek' . (!empty($rach['numer']) ? ' nr <strong>' . h($rach['numer']) . '</strong>' : '')
          . ($numer ? ' do umowy <strong>' . h($numer) . '</strong>' : '') . '.</p>'
          . '<p style="margin:20px 0"><a href="' . h($url) . '" '
          . 'style="background:#16a34a;color:#fff;padding:10px 22px;border-radius:5px;text-decoration:none;font-weight:bold">'
          . 'Otwórz rejestr rachunków</a></p>'
          . '<p style="color:#555;font-size:13px">Przypominamy, że oryginał rachunku zleceniobiorca dostarcza '
          . 'w terminie ' . ZLEC_RACHUNEK_ORYGINAL_DAYS . ' dni od udostępnienia dokumentu.</p>'
          . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0">'
          . '<p style="color:#888;font-size:12px">' . h($org) . '</p></div>';

    $targets = [];
    $sup = supervisor_get('zlecenie', (int)$rach['contract_id']);
    if ($sup && !empty($sup['user_id'])) $targets[(int)$sup['user_id']] = true;
    if (!empty($contract['created_by']))  $targets[(int)$contract['created_by']] = true;

    $done = [];
    foreach (array_keys($targets) as $uid) {
        try { notif_create($uid, 'contract', $title, $body, $url); } catch (\Throwable $e) {}
        $u = db_one("SELECT name, email FROM users WHERE id=? AND is_active=1", [$uid]);
        if (!$u || empty($u['email']) || !filter_var($u['email'], FILTER_VALIDATE_EMAIL)) continue;
        try {
            mail_queue_add($u['email'], $u['name'] ?? '', $title . ' — ' . $org, $html, '',
                'zlecenie', (int)$rach['contract_id'], '', true);
            $done[] = $u['email'];
        } catch (\Throwable $e) {}
    }

    // Stałe adresy DW rachunków — dostają też informację o podpisanym dokumencie.
    foreach (rachunek_cc_emails() as $cc_mail) {
        if (in_array($cc_mail, $done, true)) continue;
        try {
            mail_queue_add($cc_mail, '', $title . ' — ' . $org, $html, '',
                'zlecenie', (int)$rach['contract_id'], '', true);
            $done[] = $cc_mail;
        } catch (\Throwable $e) {}
    }

    // Kopia na adres, na który i tak trafiają skany rachunków
    $skan = rachunek_skan_email();
    if ($skan && filter_var($skan, FILTER_VALIDATE_EMAIL) && !in_array($skan, $done, true)) {
        try {
            mail_queue_add($skan, '', $title . ' — ' . $org, $html, '',
                'zlecenie', (int)$rach['contract_id'], '', true);
            $done[] = $skan;
        } catch (\Throwable $e) {}
    }
    return $done;
}

// ── Komentarze do rachunku ─────────────────────────────────────────────────────

/** Komentarze rachunku, od najstarszego. */
function rachunek_comments(int $rid): array {
    try {
        return db_all(
            "SELECT * FROM zlecenie_rachunek_komentarze WHERE rachunek_id=? ORDER BY id ASC",
            [$rid]
        );
    } catch (\Throwable $e) {
        return [];
    }
}

/** Liczba komentarzy per rachunek: [rachunek_id => liczba]. */
function rachunek_comments_counts(array $rachunek_ids): array {
    $ids = array_values(array_unique(array_map('intval', $rachunek_ids)));
    if (!$ids) return [];
    try {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_all(
            "SELECT rachunek_id, COUNT(*) AS c FROM zlecenie_rachunek_komentarze
             WHERE rachunek_id IN ({$ph}) GROUP BY rachunek_id",
            $ids
        );
        $out = [];
        foreach ($rows as $r) $out[(int)$r['rachunek_id']] = (int)$r['c'];
        return $out;
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Dodaje komentarz. $author_type: 'user' (pracownik) albo 'zleceniobiorca'
 * (osoba z umowy, piszące przez link z tokenem — bez konta w systemie).
 */
function rachunek_comment_add(int $rid, string $tresc, string $author_type,
                              ?int $author_id, string $author_name, string $ip = ''): ?int {
    $tresc = trim($tresc);
    if ($tresc === '') return null;
    return db_insert('zlecenie_rachunek_komentarze', [
        'rachunek_id' => $rid,
        'author_type' => in_array($author_type, ['user', 'zleceniobiorca'], true) ? $author_type : 'user',
        'author_id'   => $author_id ?: null,
        'author_name' => mb_substr($author_name, 0, 160) ?: 'Nieznany',
        'tresc'       => mb_substr($tresc, 0, 4000),
        'ip'          => mb_substr($ip, 0, 45),
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
}

/** Usuwa komentarz (tylko admin — egzekwowane po stronie widoku). */
function rachunek_comment_delete(int $comment_id): void {
    try {
        db()->prepare("DELETE FROM zlecenie_rachunek_komentarze WHERE id=?")->execute([$comment_id]);
    } catch (\Throwable $e) {}
}

// ── Baner „wyślij rachunek” ────────────────────────────────────────────────────

/** Statusy umowy, przy których oczekujemy rachunku za bieżący miesiąc. */
const ZLEC_RACHUNEK_ACTIVE_STATUSES = ['podpisana', 'w realizacji', 'do rozliczenia', 'obowiązująca'];

/**
 * Czy w rejestrze jest rachunek za wskazany miesiąc (domyślnie bieżący).
 * Rachunki testowe są POMIJANE — baner „Wyślij rachunek” to przypomnienie
 * operacyjne i nie może go wyciszyć dokument dodany na próbę.
 */
function rachunek_exists_for_month(int $contract_id, ?string $ym = null, string $type = 'zlecenie'): bool {
    $ym = $ym ?: date('Y-m');
    foreach (get_rachunki($type, $contract_id) as $r) {
        if (rachunek_is_test($r)) continue;
        if (in_array($ym, rachunek_okres_months($r), true)) return true;
    }
    return false;
}

/**
 * Miesiące (YYYY-MM) objęte rachunkiem: zakres okres_od–okres_do (rachunek za kilka miesięcy),
 * a dla starszych rekordów bez zakresu — miesiąc daty wystawienia (lub dodania).
 */
function rachunek_okres_months(array $r): array {
    $od = (string)($r['okres_od'] ?? ''); $do = (string)($r['okres_do'] ?? '') ?: $od;
    if (!preg_match('/^\d{4}-\d{2}$/', $od)) {
        $d = $r['data_wystawienia'] ?: ($r['created_at'] ?? '');
        return $d !== '' ? [substr((string)$d, 0, 7)] : [];
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $do) || $do < $od) $do = $od;
    $out = []; $cur = $od; $guard = 0;
    while ($cur <= $do && $guard++ < 36) {
        $out[] = $cur;
        [$y, $m] = array_map('intval', explode('-', $cur));
        $m++; if ($m > 12) { $m = 1; $y++; }
        $cur = sprintf('%04d-%02d', $y, $m);
    }
    return $out;
}

/**
 * Walidacja zakresu okresu z formularza: ['od'=>'YYYY-MM','do'=>'YYYY-MM','label'=>..., 'error'=>?string].
 * Puste „do” = ten sam miesiąc co „od”. Maksymalnie 12 miesięcy.
 */
function rachunek_okres_parse(string $od, string $do): array {
    $od = trim($od); $do = trim($do) ?: $od;
    if ($od === '') return ['od' => '', 'do' => '', 'label' => '', 'error' => null];
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $od)) return ['od' => '', 'do' => '', 'label' => '', 'error' => 'Nieprawidłowy miesiąc początkowy okresu rachunku.'];
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $do)) return ['od' => '', 'do' => '', 'label' => '', 'error' => 'Nieprawidłowy miesiąc końcowy okresu rachunku.'];
    if ($do < $od) return ['od' => '', 'do' => '', 'label' => '', 'error' => 'Koniec okresu rachunku nie może być wcześniejszy niż początek.'];
    $n = count(rachunek_okres_months(['okres_od' => $od, 'okres_do' => $do]));
    if ($n > 12) return ['od' => '', 'do' => '', 'label' => '', 'error' => 'Okres rachunku może obejmować najwyżej 12 miesięcy.'];
    return ['od' => $od, 'do' => $do, 'label' => rachunek_okres_label($od, $do), 'error' => null];
}

/** Etykieta okresu: „wrzesień 2026”, „wrzesień–październik 2026”, „grudzień 2025 – luty 2026”. */
function rachunek_okres_label(string $od, string $do = ''): string {
    $do = $do ?: $od;
    if ($do === $od) return rachunek_month_label($od);
    [$y1, $m1] = array_map('intval', explode('-', $od) + [1 => 1]);
    [$y2, $m2] = array_map('intval', explode('-', $do) + [1 => 1]);
    $months = ['styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'];
    if ($y1 === $y2) return ($months[$m1 - 1] ?? $od) . '–' . ($months[$m2 - 1] ?? $do) . ' ' . $y1;
    return rachunek_month_label($od) . ' – ' . rachunek_month_label($do);
}

/**
 * Czy dla tej umowy pokazać baner „Wyślij rachunek”: umowa aktywna, jeszcze
 * nie zakończona w poprzednim miesiącu i bez rachunku za bieżący miesiąc.
 */
function rachunek_banner_needed(array $contract, string $type = 'zlecenie'): bool {
    if (!in_array((string)($contract['status'] ?? ''), ZLEC_RACHUNEK_ACTIVE_STATUSES, true)) return false;
    $koniec = (string)($contract['data_zakonczenia'] ?? '');
    if ($koniec !== '' && $koniec < date('Y-m-01')) return false;   // umowa skończyła się przed tym miesiącem
    $start = (string)($contract['data_rozpoczecia'] ?? '');
    if ($start !== '' && $start > date('Y-m-t')) return false;      // jeszcze się nie zaczęła
    return !rachunek_exists_for_month((int)($contract['id'] ?? 0), null, $type);
}

/** Nazwa bieżącego miesiąca po polsku, np. „sierpień 2026”. */
function rachunek_month_label(?string $ym = null): string {
    $months = ['styczeń','luty','marzec','kwiecień','maj','czerwiec',
               'lipiec','sierpień','wrzesień','październik','listopad','grudzień'];
    $ym = $ym ?: date('Y-m');
    [$y, $m] = array_map('intval', explode('-', $ym) + [1 => 1]);
    return ($months[$m - 1] ?? $ym) . ' ' . $y;
}

// ── Przekazanie do EOD Dokumentów Księgowych (KDOK) ────────────────────────────

/**
 * Zakłada dokument w EOD Dok. Księgowych na podstawie rachunku (typ „rachunek”,
 * powiązany z umową). Kopiuje plik do kdok_docs/ — obieg księgowy ma własny
 * egzemplarz, niezależny od rejestru umowy.
 *
 * @return array{ok:bool, msg:string, doc_id?:int, number?:string}
 */
function rachunek_push_to_kdok(int $rid, ?int $user_id): array {
    $rach = get_rachunek($rid);
    if (!$rach)                    return ['ok' => false, 'msg' => 'Nie znaleziono rachunku.'];
    if (rachunek_is_test($rach)) {
        return ['ok' => false, 'msg' => 'Rachunek testowy nie trafia do EOD Dokumentów Księgowych. '
              . 'Zdejmij oznaczenie „testowy”, jeśli dokument ma wejść do obiegu księgowego.'];
    }
    if (!empty($rach['kdok_doc_id'])) {
        return ['ok' => false, 'msg' => 'Ten rachunek jest już w obiegu jako ' . ($rach['kdok_number'] ?: '#' . (int)$rach['kdok_doc_id']) . '.'];
    }
    $src = rachunek_file_abs($rach['plik'] ?? null);
    if (!$src) return ['ok' => false, 'msg' => 'Rachunek nie ma pliku — dodaj skan przed przekazaniem do EOD.'];

    $contract = db_one("SELECT * FROM umowy_zlecenie WHERE id=?", [(int)$rach['contract_id']]);
    if (!$contract) return ['ok' => false, 'msg' => 'Nie znaleziono umowy.'];

    require_once __DIR__ . '/ksiegowosc.php';
    try {
        kdok_migrate();

        $sha = $rach['plik_sha256'] ?: (hash_file('sha256', $src) ?: null);
        if ($sha) {
            $dup = kdok_one("SELECT id, number FROM kdok_documents WHERE file_sha256 = ?", [$sha]);
            if ($dup) {
                update_rachunek($rid, ['kdok_doc_id' => (int)$dup['id'], 'kdok_number' => $dup['number']]);
                return ['ok' => false, 'msg' => 'Ten dokument jest już w obiegu jako ' . $dup['number'] . '.'];
            }
        }

        $dir = rtrim(UPLOAD_DIR, '/') . '/kdok_docs/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'msg' => 'Nie można utworzyć katalogu kdok_docs.'];
        }
        $ext  = strtolower(pathinfo($src, PATHINFO_EXTENSION)) ?: 'pdf';
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!copy($src, $dir . $name)) {
            return ['ok' => false, 'msg' => 'Nie można skopiować pliku do obiegu.'];
        }

        $osoba  = (string)($contract['imie_nazwisko'] ?? '');
        $numer  = (string)($contract['numer_umowy'] ?? '');
        $okres  = (string)($rach['okres'] ?? '');
        $brutto = $rach['kwota_brutto'] !== null ? number_format((float)$rach['kwota_brutto'], 2, '.', '') : '';
        $title  = 'Rachunek do umowy zlecenie' . ($numer ? ' ' . $numer : '') . ($osoba ? ' — ' . $osoba : '');
        $number = kdok_next_number();

        $doc_id = kdok_insert('kdok_documents', [
            'number'          => $number,
            'type'            => 'rachunek',
            'title'           => $title,
            'description'     => trim(($okres ? 'Okres: ' . $okres . '. ' : '') . (string)($rach['uwagi'] ?? '')),
            'kwota'           => $brutto !== '' ? $brutto . ' PLN' : '',
            'creator_name'    => (string)(db_one("SELECT name FROM users WHERE id=?", [(int)$user_id])['name'] ?? ''),
            'file_path'       => 'kdok_docs/' . $name,
            'file_sha256'     => $sha,
            'file_size'       => filesize($dir . $name) ?: null,
            'status'          => 'w_obiegu',
            'created_by'      => $user_id,
            'miesiac'         => (int)date('n'),
            'rok'             => (int)date('Y'),
            'contract_type'   => 'zlecenie',
            'contract_id'     => (int)$rach['contract_id'],
            'nr_faktury'      => (string)($rach['numer'] ?? ''),
            'rachunek_bankowy'=> (string)($contract['rachunek_bankowy'] ?? ''),
            'kwota_brutto'    => $brutto,
            'waluta'          => 'PLN',
            'status_platnosci'=> 'nowy',
            'tytul_przelewu'  => $title,
        ]);

        foreach (array_keys(KDOK_STEPS) as $step) {
            kdok_insert('kdok_steps', ['doc_id' => $doc_id, 'step_type' => $step]);
        }
        kdok_log($doc_id, 'Dokument dodany do obiegu', 'Z rejestru rachunków umowy zlecenie (rachunek #' . $rid . ')');

        $doc_row = kdok_one("SELECT * FROM kdok_documents WHERE id=?", [$doc_id]);
        if ($doc_row) { try { kdok_create_koszulka_ezd($doc_row, (int)$user_id); } catch (\Throwable $e) {} }

        update_rachunek($rid, ['kdok_doc_id' => $doc_id, 'kdok_number' => $number]);

        return ['ok' => true, 'msg' => 'Przekazano do EOD jako ' . $number, 'doc_id' => $doc_id, 'number' => $number];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Błąd EOD: ' . $e->getMessage()];
    }
}

// ── Przekazanie do EODoK (obieg akceptacji dokumentów księgowych) ────────────

/**
 * Przekazuje rachunek do właściwego obiegu: rachunki wystawione od
 * EDOK_CUTOFF_RACHUNEK → EODoK, wcześniejsze → dotychczasowy KDOK (ta sama
 * reguła co w edok/add.php, patrz edok_typ_data_graniczna()).
 */
function rachunek_push_to_obieg(int $rid, ?int $user_id): array {
    require_once __DIR__ . '/edok.php';
    $rach = get_rachunek($rid);
    if (!$rach) return ['ok' => false, 'msg' => 'Nie znaleziono rachunku.'];
    $data = substr((string)($rach['data_wystawienia'] ?: date('Y-m-d')), 0, 10);
    return $data < EDOK_CUTOFF_RACHUNEK ? rachunek_push_to_kdok($rid, $user_id) : rachunek_push_to_edok($rid, $user_id);
}

/**
 * Zakłada dokument EODoK (typ „rachunek”, wydatek) na podstawie rachunku do umowy
 * zlecenie: zleceniobiorca jako kontrahent, rachunek bankowy z umowy, numer z Rejestru
 * Umów do tytułu przelewu („WYNAGRODZENIE MM/RRRR RU/…”), okres z daty wystawienia.
 * Kwotę do wypłaty (po potrąceniach PIT/ZUS) uzupełnia się na karcie dokumentu —
 * domyślnie przelew idzie na kwotę brutto rachunku.
 *
 * @return array{ok:bool, msg:string, doc_id?:int, number?:string}
 */
function rachunek_push_to_edok(int $rid, ?int $user_id): array {
    require_once __DIR__ . '/edok.php';
    edok_migrate();
    $rach = get_rachunek($rid);
    if (!$rach)                  return ['ok' => false, 'msg' => 'Nie znaleziono rachunku.'];
    if (rachunek_is_test($rach)) return ['ok' => false, 'msg' => 'Rachunek testowy nie trafia do obiegu księgowego.'];
    if (!empty($rach['edok_doc_id'])) return ['ok' => false, 'msg' => 'Ten rachunek jest już w EODoK jako ' . ($rach['edok_number'] ?: '#' . (int)$rach['edok_doc_id']) . '.'];
    if (!empty($rach['kdok_doc_id'])) return ['ok' => false, 'msg' => 'Ten rachunek jest już w obiegu KDOK jako ' . ($rach['kdok_number'] ?: '#' . (int)$rach['kdok_doc_id']) . '.'];
    $src = rachunek_file_abs($rach['plik_podpisany'] ?? null) ?: rachunek_file_abs($rach['plik'] ?? null);
    if (!$src) return ['ok' => false, 'msg' => 'Rachunek nie ma pliku — dodaj skan przed przekazaniem do EODoK.'];

    $contract = db_one("SELECT * FROM umowy_zlecenie WHERE id=?", [(int)$rach['contract_id']]);
    if (!$contract) return ['ok' => false, 'msg' => 'Nie znaleziono umowy.'];

    $dir = rtrim(UPLOAD_DIR, '/') . '/edok_docs/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return ['ok' => false, 'msg' => 'Nie można utworzyć katalogu edok_docs.'];
    $ext  = strtolower(pathinfo($src, PATHINFO_EXTENSION)) ?: 'pdf';
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!copy($src, $dir . $name)) return ['ok' => false, 'msg' => 'Nie można skopiować pliku do obiegu.'];

    $user   = $user_id ? db_one("SELECT id, name FROM users WHERE id=?", [$user_id]) : null;
    $osoba  = (string)($contract['imie_nazwisko'] ?? '');
    $ru     = trim((string)($contract['nr_rejestru'] ?? '')) ?: (string)($contract['numer_umowy'] ?? '');
    $data   = substr((string)($rach['data_wystawienia'] ?: date('Y-m-d')), 0, 10);
    $okres  = substr($data, 0, 7);
    $brutto = $rach['kwota_brutto'] !== null ? number_format((float)$rach['kwota_brutto'], 2, ',', '') : '';
    $number = edok_next_number();
    $doc = [
        'number'           => $number,
        'title'            => (string)($rach['numer'] ?: $number),
        'kierunek'         => 'wydatek',
        'typ_dokumentu'    => 'rachunek',
        'description'      => 'Wynagrodzenie z umowy zlecenie ' . $ru . ' — ' . $osoba
                              . ($rach['okres'] ? ', okres: ' . $rach['okres'] : '') . ($rach['uwagi'] ? '. ' . $rach['uwagi'] : ''),
        'kontrahent_nazwa' => $osoba,
        'nr_faktury'       => (string)($rach['numer'] ?? ''),
        'data_wystawienia' => $data,
        'data_wplywu'      => date('Y-m-d'),
        'kwota_netto'      => $brutto,
        'stawka_vat'       => 'np',
        'kwota_vat'        => '0,00',
        'kwota_brutto'     => $brutto,
        'waluta'           => 'PLN',
        'rachunek_bankowy' => preg_replace('/\s+/', '', (string)($contract['rachunek_bankowy'] ?? '')),
        'contract_type'    => 'zlecenie',
        'contract_id'      => (int)$rach['contract_id'],
        'okres'            => $okres,
        'umowa_numer'      => $ru,
        'zlec_rachunek_id' => $rid,
        'file_path'        => 'edok_docs/' . $name,
        'file_size'        => filesize($dir . $name) ?: null,
        'status'           => 'w_obiegu',
        'created_by'       => $user_id,
        'creator_name'     => (string)($user['name'] ?? ''),
        'created_at'       => date('Y-m-d H:i:s'),
        'updated_at'       => date('Y-m-d H:i:s'),
    ];
    $doc['tytul_przelewu'] = edok_generate_tytul_przelewu($doc);
    $doc_id = db_insert('edok_documents', $doc);
    edok_log($doc_id, 'submit', '', 'draft', 'w_obiegu', 'Dokument ' . $number . ' złożony do obiegu z rejestru rachunków umowy zlecenie ' . $ru . ' (rachunek #' . $rid . ').');
    update_rachunek($rid, ['edok_doc_id' => $doc_id, 'edok_number' => $number]);

    return ['ok' => true, 'msg' => 'Przekazano do EODoK jako ' . $number, 'doc_id' => $doc_id, 'number' => $number];
}

// ═══════════════════════════════════════════════════════════════════════════════
//  EWIDENCJA GODZIN (wpisy dzienne / e-dziennik Karty30 per grupa),
//  PRZESUNIĘCIA GODZIN WZGLĘDEM ROZLICZENIA i SALDO ROZLICZEŃ (kwota wypłaty
//  vs kwota pozostała do rozliczenia, przenoszona między miesiącami).
//
//  Warstwy:
//    zlecenie_ewidencja_wpisy        — wpis = dzień pracy (ręczny) albo sesja z e-dziennika;
//                                      liczony do miesiąca swojej daty.
//    zlecenie_ewidencja_przesuniecia — „X godzin z miesiąca A rozliczamy w miesiącu B”
//                                      (w całości lub części; jedyny mechanizm przesuwania).
//    zlecenie_rozliczenie_miesiace   — stan miesiąca w saldzie (otwarty/zamknięty),
//                                      ręczne nadpisanie kwoty wypłaty, uwagi.
//    zlecenie_rozliczenie_korekty    — korekta (+/− zł, +/− h) księgowana w otwartym miesiącu,
//                                      z odniesieniem do miesiąca/rachunku, którego dotyczy
//                                      („źle wyrachunkowane” — nie ruszamy zamkniętych miesięcy).
//    zlecenie_rachunki.rozliczenie_ym — ręczne przypisanie rachunku do miesiąca salda
//                                      (np. rachunek z sierpnia wystawiony we wrześniu, bez okresu).
//    timesheets                      — miesięczna karta (istniejąca); po każdej zmianie
//                                      ewidencji sumę godzin ROZLICZANYCH w miesiącu
//                                      wpisujemy tam, więc stare ekrany i rozliczenia
//                                      (zlecenie wystawienia rachunku) widzą to samo.
//                                      Gdy miesiąc NIE MA wpisów dziennych, godziny bierzemy
//                                      z tej karty (ręczna ewidencja miesięczna admina).
//    naleznosc_override              — ręczna kwota należności za miesiąc (admin, „per kwota”).
//    różnica                         — rachunek vs należność miesiąca; przy stawce godzinowej
//                                      przeliczana na brakujące / nadmiarowe godziny.
//
//  Saldo miesiąca M:
//    godziny_rozl(M)  = godziny_ewid(M) − przesunięte Z M + przesunięte DO M
//    naleznosc(M)     = godziny_rozl × stawka (godzinowo) | 0 (ryczałt: cały ryczałt
//                       wchodzi jako saldo początkowe pierwszego miesiąca) + korekty(M)
//    do_rozliczenia(M)= naleznosc(M) + pozostalo(M−1)
//    wyplata(M)       = suma rachunków przypisanych do M (nadpisywalna ręcznie)
//    pozostalo(M)     = do_rozliczenia(M) − wyplata(M)   → przechodzi do M+1
//
//  Rachunków system NIE generuje — robi to księgowy; wypłata = kwota z rejestru rachunków.
// ═══════════════════════════════════════════════════════════════════════════════

(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        foreach ([
            'zrodlo_godzin'              => "TEXT NOT NULL DEFAULT 'reczne'", // reczne | karty30
            'ti_instructor_user_id'      => 'INTEGER',                        // users.id prowadzącego w e-dzienniku
            'ostatnia_synchronizacja_at' => 'DATETIME',
        ] as $col => $def) {
            try { db()->exec("ALTER TABLE umowy_zlecenie ADD COLUMN {$col} {$def}"); } catch (\Throwable $e) {}
        }
        db()->exec("CREATE TABLE IF NOT EXISTS zlecenie_ewidencja_wpisy (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_id    INTEGER NOT NULL,
            data           TEXT    NOT NULL,
            godziny        REAL    NOT NULL,
            opis           TEXT,
            zrodlo         TEXT    NOT NULL DEFAULT 'reczne',
            ti_course_id   INTEGER,
            ti_course_name TEXT,
            ti_group_code  TEXT,
            ti_session_id  INTEGER,
            created_by     INTEGER,
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_by     INTEGER,
            updated_at     DATETIME
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_zlew_contract_data ON zlecenie_ewidencja_wpisy(contract_id, data)");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_zlew_session ON zlecenie_ewidencja_wpisy(contract_id, ti_session_id)");

        db()->exec("CREATE TABLE IF NOT EXISTS zlecenie_ewidencja_przesuniecia (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_id INTEGER NOT NULL,
            z_ym        TEXT    NOT NULL,
            do_ym       TEXT    NOT NULL,
            godziny     REAL    NOT NULL,
            powod       TEXT,
            created_by  INTEGER,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_zlprzes_contract ON zlecenie_ewidencja_przesuniecia(contract_id)");

        db()->exec("CREATE TABLE IF NOT EXISTS zlecenie_rozliczenie_miesiace (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_id      INTEGER NOT NULL,
            ym               TEXT    NOT NULL,
            status           TEXT    NOT NULL DEFAULT 'otwarty',
            wyplata_override REAL,
            naleznosc_override REAL,               -- ręczna kwota należności za miesiąc (admin)
            uwagi            TEXT,
            zamkniety_by     INTEGER,
            zamkniety_at     DATETIME,
            updated_at       DATETIME,
            UNIQUE(contract_id, ym)
        )");
        try { db()->exec("ALTER TABLE zlecenie_rozliczenie_miesiace ADD COLUMN naleznosc_override REAL"); } catch (\Throwable $e) {}
        // Rachunek może być ręcznie przypisany do innego miesiąca salda niż wynika z okresu/daty
        try { db()->exec("ALTER TABLE zlecenie_rachunki ADD COLUMN rozliczenie_ym TEXT"); } catch (\Throwable $e) {}
        db()->exec("CREATE TABLE IF NOT EXISTS zlecenie_rozliczenie_korekty (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_id INTEGER NOT NULL,
            ym          TEXT    NOT NULL,          -- miesiąc zaksięgowania (musi być otwarty)
            dotyczy_ym  TEXT,                      -- miesiąc, którego korekta dotyczy
            kwota       REAL    NOT NULL DEFAULT 0, -- +/− zł (zmienia należność)
            godziny     REAL    NOT NULL DEFAULT 0, -- +/− h  (zmienia godziny rozliczane)
            rachunek_id INTEGER,                   -- błędny rachunek, którego dotyczy (opcjonalnie)
            powod       TEXT    NOT NULL,
            created_by  INTEGER,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_zlkor_contract ON zlecenie_rozliczenie_korekty(contract_id, ym)");
    } catch (\Throwable $e) {
        error_log('[zlecenie_ewidencja migrate] ' . $e->getMessage());
    }
})();

const ZL_EW_ZRODLA = [
    'reczne'  => ['label' => 'Ręczne wpisy',                 'desc' => 'Godziny wpisywane ręcznie: data, liczba godzin, opis prac.'],
    'karty30' => ['label' => 'Dydaktyka – Karty30 dziennik', 'desc' => 'Godziny pobierane z e-dziennika TI z podziałem na grupy; wpis ręczny tylko poza dziennikiem.'],
];

/** Statusy sesji e-dziennika liczone do wynagrodzenia (jak w karty30.php / schedulerReports.php). */
const ZL_EW_K30_PAYABLE = ['held', 'individual_change', 'remote_material'];

const ZL_EW_MONTHS_PL = ['styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'];

// ── Pomocnicze ────────────────────────────────────────────────────────────────

function zl_ew_zrodlo(array $contract): string {
    $z = (string)($contract['zrodlo_godzin'] ?? 'reczne');
    return isset(ZL_EW_ZRODLA[$z]) ? $z : 'reczne';
}

function zl_ew_uses_karty30(array $contract): bool {
    return zl_ew_zrodlo($contract) === 'karty30';
}

function zl_ew_ym_valid(string $ym): bool {
    return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym);
}

function zl_ew_ym_label(string $ym): string {
    [$y, $m] = array_map('intval', explode('-', $ym) + [1 => 1]);
    return (ZL_EW_MONTHS_PL[$m - 1] ?? $ym) . ' ' . $y;
}

function zl_ew_ym_add(string $ym, int $months): string {
    [$y, $m] = array_map('intval', explode('-', $ym) + [1 => 1]);
    $idx = $y * 12 + ($m - 1) + $months;
    return sprintf('%04d-%02d', intdiv($idx, 12), $idx % 12 + 1);
}

function zl_ew_minutes_to_hours(int $minutes): float {
    return round(max(0, $minutes) / 60, 2);
}

function zl_ew_log(int $contract_id, string $action, string $note, ?int $user_id): void {
    if (function_exists('log_contract_action')) {
        try { log_contract_action('zlecenie', $contract_id, (int)$user_id, $action, $note); } catch (\Throwable $e) {}
    }
}

/**
 * Miesiące objęte umową (od rozpoczęcia do min(zakończenie, dziś)), najnowszy pierwszy.
 * Dla umów bez dat — bieżący miesiąc. Uwzględnia też miesiące, w których są wpisy/przesunięcia/rachunki.
 *
 * @return string[] YYYY-MM
 */
function zl_ew_months(array $contract): array {
    $start = substr((string)($contract['data_rozpoczecia'] ?: ($contract['data_zawarcia'] ?: date('Y-m-d'))), 0, 7);
    $end   = (string)($contract['data_zakonczenia'] ?? '');
    $end   = ($end !== '' && !$contract['bezterminowa'] && $end < date('Y-m-d')) ? substr($end, 0, 7) : date('Y-m');
    if (!zl_ew_ym_valid($start)) $start = date('Y-m');
    if ($end < $start) $end = $start;

    $set = [];
    $cur = $start; $guard = 0;
    while ($cur <= $end && $guard++ < 240) { $set[$cur] = true; $cur = zl_ew_ym_add($cur, 1); }

    $cid = (int)($contract['id'] ?? 0);
    if ($cid) {
        try {
            foreach (db_all("SELECT DISTINCT substr(data,1,7) AS ym FROM zlecenie_ewidencja_wpisy WHERE contract_id=?", [$cid]) as $r) $set[$r['ym']] = true;
            foreach (db_all("SELECT z_ym, do_ym FROM zlecenie_ewidencja_przesuniecia WHERE contract_id=?", [$cid]) as $r) { $set[$r['z_ym']] = true; $set[$r['do_ym']] = true; }
            foreach (db_all("SELECT ym FROM zlecenie_rozliczenie_miesiace WHERE contract_id=?", [$cid]) as $r) $set[$r['ym']] = true;
            foreach (db_all("SELECT ym FROM zlecenie_rozliczenie_korekty WHERE contract_id=?", [$cid]) as $r) $set[$r['ym']] = true;
        } catch (\Throwable $e) {}
    }
    $out = array_keys(array_filter($set, fn($k) => zl_ew_ym_valid((string)$k), ARRAY_FILTER_USE_KEY));
    rsort($out);
    return $out;
}

// ── Stan miesiąca w saldzie (otwarty / zamknięty) ─────────────────────────────

function zl_rozl_month_row(int $contract_id, string $ym): ?array {
    return db_one("SELECT * FROM zlecenie_rozliczenie_miesiace WHERE contract_id=? AND ym=?", [$contract_id, $ym]);
}

function zl_rozl_month_locked(int $contract_id, string $ym): bool {
    $r = zl_rozl_month_row($contract_id, $ym);
    return $r !== null && $r['status'] === 'zamkniety';
}

/** Rzuca wyjątek, gdy miesiąc jest zamknięty w saldzie (wpisy i przesunięcia tylko do odczytu). */
function zl_rozl_assert_open(int $contract_id, string $ym): void {
    if (zl_rozl_month_locked($contract_id, $ym)) {
        throw new RuntimeException('Miesiąc ' . zl_ew_ym_label($ym) . ' jest zamknięty w rozliczeniu — otwórz go ponownie, aby zmieniać godziny.');
    }
}

function zl_rozl_month_close(int $contract_id, string $ym, ?int $user_id, ?float $wyplata_override = null, string $uwagi = ''): void {
    if (!zl_ew_ym_valid($ym)) throw new InvalidArgumentException('Nieprawidłowy miesiąc.');
    $row = zl_rozl_month_row($contract_id, $ym);
    $data = [
        'status'           => 'zamkniety',
        'wyplata_override' => $wyplata_override,
        'uwagi'            => $uwagi !== '' ? $uwagi : ($row['uwagi'] ?? null),
        'zamkniety_by'     => $user_id,
        'zamkniety_at'     => date('Y-m-d H:i:s'),
        'updated_at'       => date('Y-m-d H:i:s'),
    ];
    if ($row) {
        db_exec("UPDATE zlecenie_rozliczenie_miesiace SET status=?, wyplata_override=?, uwagi=?, zamkniety_by=?, zamkniety_at=?, updated_at=? WHERE id=?",
            [$data['status'], $data['wyplata_override'], $data['uwagi'], $data['zamkniety_by'], $data['zamkniety_at'], $data['updated_at'], (int)$row['id']]);
    } else {
        db_insert('zlecenie_rozliczenie_miesiace', $data + ['contract_id' => $contract_id, 'ym' => $ym]);
    }
    $info = $wyplata_override !== null ? sprintf(', wypłata ustalona ręcznie: %.2f zł', $wyplata_override) : '';
    zl_ew_log($contract_id, 'rozliczenie_zamknij', 'Zamknięto miesiąc ' . zl_ew_ym_label($ym) . ' w saldzie rozliczeń' . $info, $user_id);
}

function zl_rozl_month_reopen(int $contract_id, string $ym, ?int $user_id, string $powod): void {
    $row = zl_rozl_month_row($contract_id, $ym);
    if (!$row || $row['status'] !== 'zamkniety') return;
    db_exec("UPDATE zlecenie_rozliczenie_miesiace SET status='otwarty', zamkniety_by=NULL, zamkniety_at=NULL, updated_at=datetime('now') WHERE id=?", [(int)$row['id']]);
    zl_ew_log($contract_id, 'rozliczenie_otworz', 'Ponownie otwarto miesiąc ' . zl_ew_ym_label($ym) . '. Powód: ' . $powod, $user_id);
}

/** Ręczne ustalenie kwoty wypłaty za miesiąc (NULL = wróć do sumy rachunków). */
function zl_rozl_month_set_wyplata(int $contract_id, string $ym, ?float $kwota, ?int $user_id, string $uwagi = ''): void {
    if (!zl_ew_ym_valid($ym)) throw new InvalidArgumentException('Nieprawidłowy miesiąc.');
    $row = zl_rozl_month_row($contract_id, $ym);
    if ($row) {
        db_exec("UPDATE zlecenie_rozliczenie_miesiace SET wyplata_override=?, uwagi=COALESCE(NULLIF(?, ''), uwagi), updated_at=datetime('now') WHERE id=?",
            [$kwota, $uwagi, (int)$row['id']]);
    } else {
        db_insert('zlecenie_rozliczenie_miesiace', ['contract_id' => $contract_id, 'ym' => $ym, 'status' => 'otwarty',
            'wyplata_override' => $kwota, 'uwagi' => $uwagi ?: null, 'updated_at' => date('Y-m-d H:i:s')]);
    }
    zl_ew_log($contract_id, 'rozliczenie_wyplata',
        $kwota === null ? 'Kwota wypłaty za ' . zl_ew_ym_label($ym) . ': wg rachunków' : sprintf('Kwota wypłaty za %s ustalona ręcznie: %.2f zł', zl_ew_ym_label($ym), $kwota),
        $user_id);
}

// ── Wpisy ewidencji ───────────────────────────────────────────────────────────

/** Wpisy umowy; opcjonalnie tylko z jednego miesiąca. */
function zl_ew_wpisy(int $contract_id, ?string $ym = null): array {
    if ($ym !== null) {
        return db_all("SELECT * FROM zlecenie_ewidencja_wpisy WHERE contract_id=? AND substr(data,1,7)=? ORDER BY data, id", [$contract_id, $ym]);
    }
    return db_all("SELECT * FROM zlecenie_ewidencja_wpisy WHERE contract_id=? ORDER BY data DESC, id DESC", [$contract_id]);
}

function zl_ew_wpis(int $wpis_id): ?array {
    return db_one("SELECT * FROM zlecenie_ewidencja_wpisy WHERE id=?", [$wpis_id]);
}

/** Czy umowa ma w ogóle wpisy dzienne (wtedy karta miesięczna timesheets jest utrzymywana automatycznie). */
function zl_ew_has_wpisy(int $contract_id): bool {
    return (int)(db_one("SELECT COUNT(*) AS c FROM zlecenie_ewidencja_wpisy WHERE contract_id=?", [$contract_id])['c'] ?? 0) > 0;
}

function zl_ew_assert_date_in_contract(array $contract, string $data): void {
    $from = (string)($contract['data_rozpoczecia'] ?? '');
    $to   = (string)($contract['data_zakonczenia'] ?? '');
    if ($from !== '' && $data < $from) throw new InvalidArgumentException("Data {$data} jest przed rozpoczęciem umowy ({$from}).");
    if ($to !== '' && empty($contract['bezterminowa']) && $data > $to) throw new InvalidArgumentException("Data {$data} jest po zakończeniu umowy ({$to}).");
}

/** Ręczny wpis dzienny. */
function zl_ew_add(array $contract, string $data, float $godziny, string $opis, ?int $user_id): int {
    $cid = (int)$contract['id'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !strtotime($data)) throw new InvalidArgumentException('Nieprawidłowa data.');
    if ($godziny < 0.25 || $godziny > 24) throw new InvalidArgumentException('Liczba godzin musi być w zakresie 0,25–24.');
    zl_ew_assert_date_in_contract($contract, $data);
    zl_rozl_assert_open($cid, substr($data, 0, 7));
    if (zl_ew_uses_karty30($contract) && trim($opis) === '') {
        throw new InvalidArgumentException('Dla umowy rozliczanej z e-dziennika wpis ręczny (poza dziennikiem) wymaga opisu.');
    }
    $id = db_insert('zlecenie_ewidencja_wpisy', [
        'contract_id' => $cid, 'data' => $data, 'godziny' => round($godziny, 2), 'opis' => $opis ?: null,
        'zrodlo' => 'reczne', 'created_by' => $user_id, 'created_at' => date('Y-m-d H:i:s'),
    ]);
    zl_ew_sync_timesheet($cid, substr($data, 0, 7));
    zl_ew_log($cid, 'ewidencja_wpis', sprintf('Dodano wpis %s: %.2f h', $data, $godziny), $user_id);
    return $id;
}

function zl_ew_update(array $contract, int $wpis_id, string $data, float $godziny, string $opis, ?int $user_id): void {
    $cid = (int)$contract['id'];
    $w = zl_ew_wpis($wpis_id);
    if (!$w || (int)$w['contract_id'] !== $cid) throw new InvalidArgumentException('Nie znaleziono wpisu.');
    if ($w['zrodlo'] === 'karty30') throw new RuntimeException('Wpis pochodzi z e-dziennika — zmień go w dzienniku i ponów synchronizację.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !strtotime($data)) throw new InvalidArgumentException('Nieprawidłowa data.');
    if ($godziny < 0.25 || $godziny > 24) throw new InvalidArgumentException('Liczba godzin musi być w zakresie 0,25–24.');
    zl_ew_assert_date_in_contract($contract, $data);
    $old_ym = substr((string)$w['data'], 0, 7); $new_ym = substr($data, 0, 7);
    zl_rozl_assert_open($cid, $old_ym);
    if ($new_ym !== $old_ym) zl_rozl_assert_open($cid, $new_ym);
    db_exec("UPDATE zlecenie_ewidencja_wpisy SET data=?, godziny=?, opis=?, updated_by=?, updated_at=datetime('now') WHERE id=?",
        [$data, round($godziny, 2), $opis ?: null, $user_id, $wpis_id]);
    zl_ew_sync_timesheet($cid, $old_ym);
    if ($new_ym !== $old_ym) zl_ew_sync_timesheet($cid, $new_ym);
    zl_ew_log($cid, 'ewidencja_wpis_edycja', sprintf('Zmieniono wpis #%d → %s: %.2f h', $wpis_id, $data, $godziny), $user_id);
}

function zl_ew_delete(array $contract, int $wpis_id, ?int $user_id): void {
    $cid = (int)$contract['id'];
    $w = zl_ew_wpis($wpis_id);
    if (!$w || (int)$w['contract_id'] !== $cid) return;
    if ($w['zrodlo'] === 'karty30') throw new RuntimeException('Wpis pochodzi z e-dziennika — anuluj sesję w dzienniku i ponów synchronizację.');
    $ym = substr((string)$w['data'], 0, 7);
    zl_rozl_assert_open($cid, $ym);
    db_exec("DELETE FROM zlecenie_ewidencja_wpisy WHERE id=?", [$wpis_id]);
    zl_ew_sync_timesheet($cid, $ym);
    zl_ew_log($cid, 'ewidencja_wpis_usun', sprintf('Usunięto wpis #%d (%s: %.2f h)', $wpis_id, $w['data'], (float)$w['godziny']), $user_id);
}

/** Suma godzin z wpisów dziennych w miesiącu (wg daty wpisu). */
function zl_ew_month_hours_wpisy(int $contract_id, string $ym): float {
    return round((float)(db_one("SELECT COALESCE(SUM(godziny),0) AS s FROM zlecenie_ewidencja_wpisy WHERE contract_id=? AND substr(data,1,7)=?", [$contract_id, $ym])['s'] ?? 0), 2);
}

/** Czy miesiąc ma wpisy dzienne (wtedy karta miesięczna jest pochodną, nie źródłem). */
function zl_ew_month_has_wpisy(int $contract_id, string $ym): bool {
    return (int)(db_one("SELECT COUNT(*) AS c FROM zlecenie_ewidencja_wpisy WHERE contract_id=? AND substr(data,1,7)=?", [$contract_id, $ym])['c'] ?? 0) > 0;
}

/** Godziny z ręcznej karty miesięcznej (timesheets) — ewidencja miesięczna admina. */
function zl_ew_month_hours_karta(int $contract_id, string $ym): float {
    if (!zl_ew_ym_valid($ym)) return 0.0;
    [$rok, $mies] = array_map('intval', explode('-', $ym));
    try {
        $r = db_one("SELECT godziny, status FROM timesheets WHERE contract_type='zlecenie' AND contract_id=? AND rok=? AND miesiac=?", [$contract_id, $rok, $mies]);
    } catch (\Throwable $e) { return 0.0; }
    if (!$r || $r['status'] === 'odrzucone') return 0.0;
    return round((float)$r['godziny'], 2);
}

/**
 * Godziny ewidencji w miesiącu: wpisy dzienne, a gdy ich nie ma — ręczna karta miesięczna.
 * Zwraca też źródło przez $zrodlo ('wpisy' | 'karta' | 'brak').
 */
function zl_ew_month_hours(int $contract_id, string $ym, ?string &$zrodlo = null): float {
    if (zl_ew_month_has_wpisy($contract_id, $ym)) { $zrodlo = 'wpisy'; return zl_ew_month_hours_wpisy($contract_id, $ym); }
    $h = zl_ew_month_hours_karta($contract_id, $ym);
    $zrodlo = $h > 0 ? 'karta' : 'brak';
    return $h;
}

/**
 * Ręczna ewidencja miesięczna admina: liczba godzin za miesiąc wprost do karty (timesheets).
 * Dozwolone tylko, gdy miesiąc nie ma wpisów dziennych (wtedy to one są źródłem).
 */
function zl_ew_month_set_hours(array $contract, string $ym, float $godziny, string $opis, ?int $user_id): void {
    $cid = (int)$contract['id'];
    if (!zl_ew_ym_valid($ym)) throw new InvalidArgumentException('Nieprawidłowy miesiąc.');
    if ($godziny < 0 || $godziny > 744) throw new InvalidArgumentException('Liczba godzin za miesiąc musi być w zakresie 0–744.');
    zl_rozl_assert_open($cid, $ym);
    if (zl_ew_month_has_wpisy($cid, $ym)) throw new RuntimeException('Miesiąc ' . zl_ew_ym_label($ym) . ' ma wpisy dzienne — godziny wynikają z nich; popraw wpisy albo dodaj korektę.');
    [$rok, $mies] = array_map('intval', explode('-', $ym));
    $ts = db_one("SELECT * FROM timesheets WHERE contract_type='zlecenie' AND contract_id=? AND rok=? AND miesiac=?", [$cid, $rok, $mies]);
    if ($godziny <= 0) {
        if ($ts) db_exec("DELETE FROM timesheets WHERE id=?", [(int)$ts['id']]);
    } elseif ($ts) {
        db_exec("UPDATE timesheets SET godziny=?, opis=?, status='zatwierdzone', uwagi_admin=NULL, updated_at=datetime('now') WHERE id=?", [$godziny, $opis ?: $ts['opis'], (int)$ts['id']]);
    } else {
        db_exec("INSERT INTO timesheets (contract_type, contract_id, user_id, rok, miesiac, godziny, opis, status, created_at)
                 VALUES ('zlecenie', ?, ?, ?, ?, ?, ?, 'zatwierdzone', datetime('now'))", [$cid, (int)$user_id, $rok, $mies, $godziny, $opis ?: 'Ewidencja miesięczna (ręcznie)']);
    }
    zl_ew_log($cid, 'ewidencja_miesiac', sprintf('Ręczna ewidencja miesięczna %s: %s h%s', zl_ew_ym_label($ym), number_format($godziny, 2, ',', ' '), $opis ? ' — ' . $opis : ''), $user_id);
}

/** Ręczna kwota należności za miesiąc (admin, „per kwota”); NULL = wróć do wyliczenia z godzin. */
function zl_rozl_month_set_naleznosc(int $contract_id, string $ym, ?float $kwota, ?int $user_id, string $uwagi = ''): void {
    if (!zl_ew_ym_valid($ym)) throw new InvalidArgumentException('Nieprawidłowy miesiąc.');
    zl_rozl_assert_open($contract_id, $ym);
    $row = zl_rozl_month_row($contract_id, $ym);
    if ($row) {
        db_exec("UPDATE zlecenie_rozliczenie_miesiace SET naleznosc_override=?, uwagi=COALESCE(NULLIF(?, ''), uwagi), updated_at=datetime('now') WHERE id=?", [$kwota, $uwagi, (int)$row['id']]);
    } else {
        db_insert('zlecenie_rozliczenie_miesiace', ['contract_id' => $contract_id, 'ym' => $ym, 'status' => 'otwarty',
            'naleznosc_override' => $kwota, 'uwagi' => $uwagi ?: null, 'updated_at' => date('Y-m-d H:i:s')]);
    }
    zl_ew_log($contract_id, 'rozliczenie_naleznosc',
        $kwota === null ? 'Należność za ' . zl_ew_ym_label($ym) . ': wg godzin' : sprintf('Należność za %s ustalona ręcznie: %.2f zł%s', zl_ew_ym_label($ym), $kwota, $uwagi ? ' — ' . $uwagi : ''),
        $user_id);
}

/** Podział godzin miesiąca na grupy e-dziennika (+ wiersz „ręczne”). */
function zl_ew_month_groups(int $contract_id, string $ym): array {
    $rows = db_all(
        "SELECT ti_course_id, COALESCE(ti_course_name,'') AS nazwa, COALESCE(ti_group_code,'') AS kod, zrodlo,
                COUNT(*) AS n, ROUND(SUM(godziny),2) AS godziny
         FROM zlecenie_ewidencja_wpisy WHERE contract_id=? AND substr(data,1,7)=?
         GROUP BY zrodlo, ti_course_id, ti_course_name, ti_group_code
         ORDER BY zrodlo DESC, nazwa", [$contract_id, $ym]);
    return array_map(fn($r) => [
        'ti_course_id' => $r['ti_course_id'] !== null ? (int)$r['ti_course_id'] : null,
        'nazwa'        => $r['zrodlo'] === 'karty30' ? ($r['nazwa'] ?: ('grupa #' . $r['ti_course_id'])) : 'Wpisy ręczne',
        'kod'          => $r['kod'],
        'zrodlo'       => $r['zrodlo'],
        'liczba'       => (int)$r['n'],
        'godziny'      => (float)$r['godziny'],
    ], $rows);
}

// ── Integracja z kartą miesięczną (timesheets) ────────────────────────────────

/**
 * Po zmianie ewidencji/przesunięć: suma godzin ROZLICZANYCH w miesiącu trafia do timesheets.
 * Istniejący wpis „zatwierdzone” nie jest ruszany (karta zamknięta merytorycznie);
 * nowy wpis dostaje status „złożone”. Gdy w miesiącu nic nie ma — wpis automatyczny jest usuwany.
 */
function zl_ew_sync_timesheet(int $contract_id, string $ym): void {
    if (!zl_ew_ym_valid($ym)) return;
    [$rok, $mies] = array_map('intval', explode('-', $ym));
    $contract = db_one("SELECT * FROM umowy_zlecenie WHERE id=?", [$contract_id]);
    if (!$contract) return;
    if (!zl_ew_month_has_wpisy($contract_id, $ym)) {
        // Brak wpisów dziennych: karta miesięczna jest ŹRÓDŁEM (ręczna ewidencja admina) — nie dotykamy jej,
        // chyba że to nasz automatyczny wpis po usunięciu ostatniego wpisu dziennego.
        try {
            [$rok, $mies] = array_map('intval', explode('-', $ym));
            $ts = db_one("SELECT id, opis, status FROM timesheets WHERE contract_type='zlecenie' AND contract_id=? AND rok=? AND miesiac=?", [$contract_id, $rok, $mies]);
            if ($ts && str_starts_with((string)$ts['opis'], 'Ewidencja:') && $ts['status'] !== 'zatwierdzone') db_exec("DELETE FROM timesheets WHERE id=?", [(int)$ts['id']]);
        } catch (\Throwable $e) {}
        return;
    }
    $hours = zl_rozl_month_hours($contract_id, $ym);
    $groups = array_filter(zl_ew_month_groups($contract_id, $ym), fn($g) => $g['zrodlo'] === 'karty30');
    $opis = 'Ewidencja: ' . number_format(zl_ew_month_hours($contract_id, $ym), 2, ',', ' ') . ' h'
          . ($groups ? ' (' . implode(', ', array_map(fn($g) => $g['nazwa'] . ' ' . number_format($g['godziny'], 2, ',', ' ') . ' h', $groups)) . ')' : '')
          . (abs($hours - zl_ew_month_hours($contract_id, $ym)) > 0.004 ? '; po przesunięciach: ' . number_format($hours, 2, ',', ' ') . ' h' : '');
    try {
        $ts = db_one("SELECT * FROM timesheets WHERE contract_type='zlecenie' AND contract_id=? AND rok=? AND miesiac=?", [$contract_id, $rok, $mies]);
        if ($hours <= 0) {
            if ($ts && str_starts_with((string)$ts['opis'], 'Ewidencja:') && $ts['status'] !== 'zatwierdzone') {
                db_exec("DELETE FROM timesheets WHERE id=?", [(int)$ts['id']]);
            }
            return;
        }
        if ($ts) {
            if ($ts['status'] === 'zatwierdzone' && abs((float)$ts['godziny'] - $hours) < 0.004) return;
            $status = $ts['status'] === 'zatwierdzone' ? 'zatwierdzone' : 'złożone';
            db_exec("UPDATE timesheets SET godziny=?, opis=?, status=?, updated_at=datetime('now') WHERE id=?", [$hours, $opis, $status, (int)$ts['id']]);
        } else {
            db_exec("INSERT INTO timesheets (contract_type, contract_id, user_id, rok, miesiac, godziny, opis, status, created_at)
                     VALUES ('zlecenie', ?, ?, ?, ?, ?, ?, 'złożone', datetime('now'))",
                [$contract_id, (int)($contract['created_by'] ?? 0), $rok, $mies, $hours, $opis]);
        }
    } catch (\Throwable $e) {
        error_log('[zl_ew_sync_timesheet] ' . $e->getMessage());
    }
}

// ── E-dziennik Karty30 ────────────────────────────────────────────────────────

function zl_ew_karty30_available(): bool {
    static $ok = null;
    if ($ok === null) {
        try { db_one("SELECT 1 FROM k30_ti_sessions LIMIT 1"); db_one("SELECT 1 FROM k30_ti_courses LIMIT 1"); $ok = true; }
        catch (\Throwable $e) { $ok = false; }
    }
    return $ok;
}

/**
 * Sesje prowadzącego w miesiącu, liczone do wynagrodzenia:
 *  - sesja ma instructor_id = prowadzący, albo
 *  - sesja bez własnego prowadzącego i grupa ma instructor_id = prowadzący, albo
 *  - prowadzący jest współprowadzącym grupy.
 * Tylko statusy płatne, bez szkiców, w granicach dat umowy.
 */
function zl_ew_karty30_sessions(array $contract, string $ym): array {
    if (!zl_ew_karty30_available()) return [];
    $uid = (int)($contract['ti_instructor_user_id'] ?? 0);
    if ($uid <= 0 || !zl_ew_ym_valid($ym)) return [];
    $ph = implode(',', array_fill(0, count(ZL_EW_K30_PAYABLE), '?'));
    $sql = "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.duration_min, s.topic, s.status, s.instructor_id,
                   c.id AS course_id, c.name AS course_name, COALESCE(c.display_name,'') AS display_name, COALESCE(c.group_code,'') AS group_code
            FROM k30_ti_sessions s
            JOIN k30_ti_courses c ON c.id = s.course_id
            LEFT JOIN k30_ti_course_coinstructors ci ON ci.course_id = c.id AND ci.user_id = ?
            WHERE substr(s.lesson_date,1,7) = ?
              AND s.status IN ({$ph})
              AND s.draft_id IS NULL
              AND (s.instructor_id = ? OR (s.instructor_id IS NULL AND c.instructor_id = ?) OR ci.id IS NOT NULL)";
    $params = [$uid, $ym, ...ZL_EW_K30_PAYABLE, $uid, $uid];
    $from = (string)($contract['data_rozpoczecia'] ?? '');
    $to   = empty($contract['bezterminowa']) ? (string)($contract['data_zakonczenia'] ?? '') : '';
    if ($from !== '') { $sql .= " AND s.lesson_date >= ?"; $params[] = substr($from, 0, 10); }
    if ($to !== '')   { $sql .= " AND s.lesson_date <= ?"; $params[] = substr($to, 0, 10); }
    $sql .= " ORDER BY s.lesson_date, s.time_from, s.id";
    return db_all($sql, $params);
}

function zl_ew_karty30_hours(array $s): float {
    $min = (int)($s['duration_min'] ?? 0);
    if ($min <= 0 && !empty($s['time_from']) && !empty($s['time_to'])) {
        $a = strtotime('2000-01-01 ' . substr($s['time_from'], 0, 5)); $b = strtotime('2000-01-01 ' . substr($s['time_to'], 0, 5));
        $min = ($a && $b) ? max(0, (int)(($b - $a) / 60)) : 0;
    }
    return zl_ew_minutes_to_hours($min);
}

/** Podgląd dziennika bez zapisu: grupy + suma. */
function zl_ew_karty30_preview(array $contract, string $ym): array {
    $sesje = zl_ew_karty30_sessions($contract, $ym);
    $grupy = [];
    foreach ($sesje as $s) {
        $k = (int)$s['course_id'];
        $grupy[$k] ??= ['ti_course_id' => $k, 'nazwa' => $s['display_name'] ?: $s['course_name'], 'kod' => $s['group_code'], 'liczba' => 0, 'godziny' => 0.0];
        $grupy[$k]['liczba']++;
        $grupy[$k]['godziny'] = round($grupy[$k]['godziny'] + zl_ew_karty30_hours($s), 2);
    }
    usort($grupy, fn($a, $b) => strcmp($a['nazwa'], $b['nazwa']));
    return ['sesje' => $sesje, 'grupy' => array_values($grupy), 'suma' => round(array_sum(array_column($grupy, 'godziny')), 2)];
}

/**
 * Synchronizacja miesiąca z e-dziennikiem (idempotentna: wpis = sesja).
 * Dodaje nowe sesje, aktualizuje zmienione, usuwa te, których już nie ma (anulowane, zmieniony prowadzący).
 *
 * @return array{added:int, updated:int, removed:int, suma:float, grupy:array, message:string}
 */
function zl_ew_karty30_sync(array $contract, string $ym, ?int $user_id): array {
    $cid = (int)$contract['id'];
    if (!zl_ew_uses_karty30($contract)) throw new LogicException('Umowa nie ma źródła godzin „Dydaktyka – Karty30 dziennik”.');
    if ((int)($contract['ti_instructor_user_id'] ?? 0) <= 0) throw new LogicException('Umowa nie ma przypisanego prowadzącego z e-dziennika.');
    if (!zl_ew_karty30_available()) throw new RuntimeException('Tabele e-dziennika (k30_ti_*) nie są dostępne.');
    zl_rozl_assert_open($cid, $ym);

    $prev = zl_ew_karty30_preview($contract, $ym);
    $res  = ['added' => 0, 'updated' => 0, 'removed' => 0, 'suma' => $prev['suma'], 'grupy' => $prev['grupy'], 'message' => ''];

    $existing = [];
    foreach (db_all("SELECT * FROM zlecenie_ewidencja_wpisy WHERE contract_id=? AND zrodlo='karty30' AND substr(data,1,7)=?", [$cid, $ym]) as $w) {
        $existing[(int)$w['ti_session_id']] = $w;
    }

    $own_tx = !db()->inTransaction();
    if ($own_tx) db()->beginTransaction();
    try {
        foreach ($prev['sesje'] as $s) {
            $sid   = (int)$s['id'];
            $hours = zl_ew_karty30_hours($s);
            $nazwa = $s['display_name'] ?: $s['course_name'];
            $opis  = implode(' · ', array_filter([
                $nazwa,
                (!empty($s['time_from']) && !empty($s['time_to'])) ? substr($s['time_from'], 0, 5) . '–' . substr($s['time_to'], 0, 5) : '',
                trim((string)$s['topic']),
                $s['status'] === 'remote_material' ? '(praca własna / materiał zdalny)' : '',
            ]));
            if (isset($existing[$sid])) {
                $w = $existing[$sid];
                $dirty = abs((float)$w['godziny'] - $hours) > 0.004 || $w['data'] !== $s['lesson_date']
                      || (int)$w['ti_course_id'] !== (int)$s['course_id'] || (string)$w['opis'] !== $opis;
                if ($dirty) {
                    db_exec("UPDATE zlecenie_ewidencja_wpisy SET data=?, godziny=?, opis=?, ti_course_id=?, ti_course_name=?, ti_group_code=?, updated_by=?, updated_at=datetime('now') WHERE id=?",
                        [$s['lesson_date'], $hours, $opis, (int)$s['course_id'], $nazwa, $s['group_code'], $user_id, (int)$w['id']]);
                    $res['updated']++;
                }
                unset($existing[$sid]);
                continue;
            }
            db_insert('zlecenie_ewidencja_wpisy', [
                'contract_id' => $cid, 'data' => $s['lesson_date'], 'godziny' => $hours, 'opis' => $opis, 'zrodlo' => 'karty30',
                'ti_course_id' => (int)$s['course_id'], 'ti_course_name' => $nazwa, 'ti_group_code' => $s['group_code'],
                'ti_session_id' => $sid, 'created_by' => $user_id, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $res['added']++;
        }
        foreach ($existing as $stale) {
            db_exec("DELETE FROM zlecenie_ewidencja_wpisy WHERE id=?", [(int)$stale['id']]);
            $res['removed']++;
        }
        db_exec("UPDATE umowy_zlecenie SET ostatnia_synchronizacja_at=datetime('now') WHERE id=?", [$cid]);
        if ($own_tx) db()->commit();
    } catch (\Throwable $e) {
        if ($own_tx) db()->rollBack();
        throw $e;
    }

    zl_ew_sync_timesheet($cid, $ym);

    $changed = $res['added'] + $res['updated'] + $res['removed'] > 0;
    $res['message'] = $changed
        ? sprintf('Pobrano z dziennika: %d nowych, %d zmienionych, %d usuniętych sesji. Razem %s h w %d grupach.',
            $res['added'], $res['updated'], $res['removed'], number_format($res['suma'], 2, ',', ' '), count($res['grupy']))
        : sprintf('Dziennik bez zmian: %s h w %d grupach.', number_format($res['suma'], 2, ',', ' '), count($res['grupy']));
    if ($changed) zl_ew_log($cid, 'ewidencja_sync_karty30', zl_ew_ym_label($ym) . ': ' . $res['message'], $user_id);
    return $res;
}

// ── Przesunięcia godzin względem rozliczenia ──────────────────────────────────

function zl_przes_list(int $contract_id): array {
    return db_all(
        "SELECT p.*, u.name AS created_by_name
         FROM zlecenie_ewidencja_przesuniecia p LEFT JOIN users u ON u.id = p.created_by
         WHERE p.contract_id=? ORDER BY p.z_ym DESC, p.id DESC", [$contract_id]);
}

/** Godziny przesunięte Z miesiąca (ujemnie dla salda) i DO miesiąca. */
function zl_przes_month(int $contract_id, string $ym): array {
    $out = (float)(db_one("SELECT COALESCE(SUM(godziny),0) AS s FROM zlecenie_ewidencja_przesuniecia WHERE contract_id=? AND z_ym=?", [$contract_id, $ym])['s'] ?? 0);
    $in  = (float)(db_one("SELECT COALESCE(SUM(godziny),0) AS s FROM zlecenie_ewidencja_przesuniecia WHERE contract_id=? AND do_ym=?", [$contract_id, $ym])['s'] ?? 0);
    return ['out' => round($out, 2), 'in' => round($in, 2)];
}

/**
 * „X godzin z miesiąca A rozliczamy w miesiącu B”. Nie można przesunąć więcej niż
 * miesiąc A ma do rozliczenia (ewidencja − już przesunięte + przyjęte).
 */
function zl_przes_add(array $contract, string $z_ym, string $do_ym, float $godziny, string $powod, ?int $user_id): int {
    $cid = (int)$contract['id'];
    if (!zl_ew_ym_valid($z_ym) || !zl_ew_ym_valid($do_ym)) throw new InvalidArgumentException('Nieprawidłowy miesiąc.');
    if ($z_ym === $do_ym) throw new InvalidArgumentException('Miesiąc źródłowy i docelowy muszą być różne.');
    if ($godziny <= 0) throw new InvalidArgumentException('Liczba godzin musi być większa od zera.');
    if (trim($powod) === '') throw new InvalidArgumentException('Podaj powód przesunięcia (trafia do historii umowy).');
    zl_rozl_assert_open($cid, $z_ym);
    zl_rozl_assert_open($cid, $do_ym);
    $available = zl_rozl_month_hours($cid, $z_ym);
    if ($godziny > $available + 0.004) {
        throw new InvalidArgumentException(sprintf('W miesiącu %s do rozliczenia jest tylko %s h.', zl_ew_ym_label($z_ym), number_format($available, 2, ',', ' ')));
    }
    $id = db_insert('zlecenie_ewidencja_przesuniecia', [
        'contract_id' => $cid, 'z_ym' => $z_ym, 'do_ym' => $do_ym, 'godziny' => round($godziny, 2),
        'powod' => trim($powod), 'created_by' => $user_id, 'created_at' => date('Y-m-d H:i:s'),
    ]);
    zl_ew_sync_timesheet($cid, $z_ym);
    zl_ew_sync_timesheet($cid, $do_ym);
    zl_ew_log($cid, 'ewidencja_przesuniecie', sprintf('Przesunięto %s h z %s do %s. Powód: %s', number_format($godziny, 2, ',', ' '), zl_ew_ym_label($z_ym), zl_ew_ym_label($do_ym), trim($powod)), $user_id);
    return $id;
}

function zl_przes_delete(array $contract, int $przes_id, ?int $user_id): void {
    $cid = (int)$contract['id'];
    $p = db_one("SELECT * FROM zlecenie_ewidencja_przesuniecia WHERE id=? AND contract_id=?", [$przes_id, $cid]);
    if (!$p) return;
    zl_rozl_assert_open($cid, $p['z_ym']);
    zl_rozl_assert_open($cid, $p['do_ym']);
    db_exec("DELETE FROM zlecenie_ewidencja_przesuniecia WHERE id=?", [$przes_id]);
    zl_ew_sync_timesheet($cid, $p['z_ym']);
    zl_ew_sync_timesheet($cid, $p['do_ym']);
    zl_ew_log($cid, 'ewidencja_przesuniecie_usun', sprintf('Cofnięto przesunięcie %s h z %s do %s', number_format((float)$p['godziny'], 2, ',', ' '), zl_ew_ym_label($p['z_ym']), zl_ew_ym_label($p['do_ym'])), $user_id);
}

/** Godziny ROZLICZANE w miesiącu = ewidencja − przesunięte z + przesunięte do + korekty godzin. */
function zl_rozl_month_hours(int $contract_id, string $ym): float {
    $p = zl_przes_month($contract_id, $ym);
    $k = zl_korekta_month($contract_id, $ym);
    return round(zl_ew_month_hours($contract_id, $ym) - $p['out'] + $p['in'] + $k['godziny'], 2);
}

// ── Rachunki: miesiąc w saldzie (obsługa rachunków „z sierpnia”) ──────────────

/** Nazwa polskiego miesiąca w tekście „okres” (np. „sierpień 2026”, „za sierpień”) → YYYY-MM. */
function zl_rozl_parse_okres_text(string $txt, string $fallback_year = ''): ?string {
    $t = mb_strtolower(trim($txt));
    if ($t === '') return null;
    if (preg_match('/(\d{4})-(0[1-9]|1[0-2])/', $t, $m)) return $m[1] . '-' . $m[2];
    if (preg_match('/\b(0?[1-9]|1[0-2])[.\/](\d{4})\b/', $t, $m)) return sprintf('%04d-%02d', (int)$m[2], (int)$m[1]);
    $stems = ['stycz' => 1, 'lut' => 2, 'mar' => 3, 'kwie' => 4, 'maj' => 5, 'czerw' => 6, 'lip' => 7, 'sierp' => 8, 'wrze' => 9, 'pa' => 10, 'listop' => 11, 'grud' => 12];
    $month = null;
    foreach ($stems as $stem => $num) { if (str_contains($t, $stem)) { $month = $num; break; } }
    if (!$month) return null;
    $year = preg_match('/\b(20\d{2})\b/', $t, $m) ? (int)$m[1] : (int)($fallback_year ?: date('Y'));
    return sprintf('%04d-%02d', $year, $month);
}

/**
 * Miesiąc salda, do którego liczy się rachunek:
 *  1. ręczne przypisanie (rozliczenie_ym),
 *  2. ostatni miesiąc zakresu okres_od–okres_do,
 *  3. miesiąc z tekstowego pola „okres” („sierpień 2026”),
 *  4. miesiąc daty wystawienia / dodania.
 * Zwraca [ym, zrodlo].
 */
function zl_rozl_rachunek_ym(array $r): array {
    $manual = (string)($r['rozliczenie_ym'] ?? '');
    if (zl_ew_ym_valid($manual)) return [$manual, 'reczne'];
    $od = (string)($r['okres_od'] ?? '');
    if (zl_ew_ym_valid($od)) { $m = rachunek_okres_months($r); return [end($m), 'okres']; }
    $d = substr((string)($r['data_wystawienia'] ?: ($r['created_at'] ?? '')), 0, 7);
    $txt = zl_rozl_parse_okres_text((string)($r['okres'] ?? ''), substr($d, 0, 4));
    if ($txt) return [$txt, 'tekst'];
    return [zl_ew_ym_valid($d) ? $d : date('Y-m'), 'data'];
}

/** Ręczne przypisanie rachunku do miesiąca salda (NULL = wróć do automatu). */
function zl_rozl_rachunek_set_ym(array $contract, int $rachunek_id, ?string $ym, ?int $user_id): void {
    $cid = (int)$contract['id'];
    $r = get_rachunek($rachunek_id);
    if (!$r || (int)$r['contract_id'] !== $cid) throw new InvalidArgumentException('Nie znaleziono rachunku.');
    if ($ym !== null && !zl_ew_ym_valid($ym)) throw new InvalidArgumentException('Nieprawidłowy miesiąc.');
    [$old] = zl_rozl_rachunek_ym($r);
    zl_rozl_assert_open($cid, $old);
    if ($ym !== null) zl_rozl_assert_open($cid, $ym);
    db_exec("UPDATE zlecenie_rachunki SET rozliczenie_ym=?, updated_at=datetime('now') WHERE id=?", [$ym, $rachunek_id]);
    zl_ew_log($cid, 'rozliczenie_rachunek_ym', sprintf('Rachunek #%d%s przypisano do miesiąca %s (było: %s)',
        $rachunek_id, $r['numer'] ? ' ' . $r['numer'] : '', $ym ? zl_ew_ym_label($ym) : 'wg okresu/daty', zl_ew_ym_label($old)), $user_id);
}

/** Rachunki, których miesiąc salda został odgadnięty z tekstu lub daty (warto sprawdzić). */
function zl_rozl_rachunki_niepewne(int $contract_id): array {
    $out = [];
    foreach (get_rachunki('zlecenie', $contract_id) as $r) {
        if (rachunek_is_test($r)) continue;
        [$ym, $src] = zl_rozl_rachunek_ym($r);
        if (in_array($src, ['tekst', 'data'], true)) $out[] = ['id' => (int)$r['id'], 'numer' => $r['numer'], 'ym' => $ym, 'zrodlo' => $src, 'okres' => $r['okres'], 'data_wystawienia' => $r['data_wystawienia']];
    }
    return $out;
}

// ── Korekty („coś było źle wyrachunkowane”) ───────────────────────────────────

function zl_korekta_list(int $contract_id): array {
    return db_all(
        "SELECT k.*, u.name AS created_by_name, r.numer AS rachunek_numer
         FROM zlecenie_rozliczenie_korekty k
         LEFT JOIN users u ON u.id = k.created_by
         LEFT JOIN zlecenie_rachunki r ON r.id = k.rachunek_id
         WHERE k.contract_id=? ORDER BY k.ym DESC, k.id DESC", [$contract_id]);
}

/** Suma korekt zaksięgowanych w miesiącu: ['kwota' => zł, 'godziny' => h, 'pozycje' => [...]]. */
function zl_korekta_month(int $contract_id, string $ym): array {
    $rows = db_all("SELECT * FROM zlecenie_rozliczenie_korekty WHERE contract_id=? AND ym=? ORDER BY id", [$contract_id, $ym]);
    return [
        'kwota'   => round(array_sum(array_map(fn($r) => (float)$r['kwota'], $rows)), 2),
        'godziny' => round(array_sum(array_map(fn($r) => (float)$r['godziny'], $rows)), 2),
        'pozycje' => $rows,
    ];
}

/**
 * Korekta księgowana w OTWARTYM miesiącu $ym, dotycząca miesiąca $dotyczy_ym (może być zamknięty —
 * nie ruszamy go, korekta wyrównuje saldo w bieżącym). Kwota i/lub godziny ze znakiem.
 */
function zl_korekta_add(array $contract, string $ym, ?string $dotyczy_ym, float $kwota, float $godziny, string $powod, ?int $rachunek_id, ?int $user_id): int {
    $cid = (int)$contract['id'];
    if (!zl_ew_ym_valid($ym)) throw new InvalidArgumentException('Nieprawidłowy miesiąc księgowania.');
    if ($dotyczy_ym !== null && $dotyczy_ym !== '' && !zl_ew_ym_valid($dotyczy_ym)) throw new InvalidArgumentException('Nieprawidłowy miesiąc, którego dotyczy korekta.');
    if (abs($kwota) < 0.005 && abs($godziny) < 0.005) throw new InvalidArgumentException('Podaj kwotę lub liczbę godzin korekty (ze znakiem).');
    if (trim($powod) === '') throw new InvalidArgumentException('Podaj powód korekty (trafia do historii umowy).');
    zl_rozl_assert_open($cid, $ym);
    if ($rachunek_id) {
        $r = get_rachunek($rachunek_id);
        if (!$r || (int)$r['contract_id'] !== $cid) throw new InvalidArgumentException('Nie znaleziono rachunku.');
    }
    $id = db_insert('zlecenie_rozliczenie_korekty', [
        'contract_id' => $cid, 'ym' => $ym, 'dotyczy_ym' => $dotyczy_ym ?: null,
        'kwota' => round($kwota, 2), 'godziny' => round($godziny, 2), 'rachunek_id' => $rachunek_id ?: null,
        'powod' => trim($powod), 'created_by' => $user_id, 'created_at' => date('Y-m-d H:i:s'),
    ]);
    if (abs($godziny) >= 0.005) zl_ew_sync_timesheet($cid, $ym);
    zl_ew_log($cid, 'rozliczenie_korekta', sprintf('Korekta w %s%s: %s%s zł, %s%s h%s. Powód: %s',
        zl_ew_ym_label($ym), $dotyczy_ym ? ' (dotyczy ' . zl_ew_ym_label($dotyczy_ym) . ')' : '',
        $kwota >= 0 ? '+' : '', number_format($kwota, 2, ',', ' '), $godziny >= 0 ? '+' : '', number_format($godziny, 2, ',', ' '),
        $rachunek_id ? ', rachunek #' . $rachunek_id : '', trim($powod)), $user_id);
    return $id;
}

function zl_korekta_delete(array $contract, int $korekta_id, ?int $user_id): void {
    $cid = (int)$contract['id'];
    $k = db_one("SELECT * FROM zlecenie_rozliczenie_korekty WHERE id=? AND contract_id=?", [$korekta_id, $cid]);
    if (!$k) return;
    zl_rozl_assert_open($cid, $k['ym']);
    db_exec("DELETE FROM zlecenie_rozliczenie_korekty WHERE id=?", [$korekta_id]);
    if (abs((float)$k['godziny']) >= 0.005) zl_ew_sync_timesheet($cid, $k['ym']);
    zl_ew_log($cid, 'rozliczenie_korekta_usun', sprintf('Usunięto korektę #%d z %s (%s zł, %s h)', $korekta_id, zl_ew_ym_label($k['ym']), number_format((float)$k['kwota'], 2, ',', ' '), number_format((float)$k['godziny'], 2, ',', ' ')), $user_id);
}

// ── Saldo rozliczeń ───────────────────────────────────────────────────────────

/** Należność brutto za miesiąc wg typu stawki z umowy. */
function zl_rozl_naleznosc(array $contract, float $godziny_rozl): float {
    $typ = (string)($contract['typ_stawki'] ?? '');
    $stawka = (float)($contract['stawka_kwota'] ?? 0);
    return match ($typ) {
        'godzinowo'   => round($godziny_rozl * $stawka, 2),
        'miesiecznie' => round($stawka, 2),
        default       => 0.0, // ryczałt: saldo początkowe (zl_rozl_ledger), inne: brak
    };
}

/**
 * Wypłaty wg rejestru rachunków: kwota rachunku trafia do miesiąca salda wg zl_rozl_rachunek_ym()
 * (ręczne przypisanie → okres → tekst „okres” → data). Rachunki testowe i odrzucone pominięte.
 *
 * @return array<string, array{kwota: float, rachunki: array}>
 */
function zl_rozl_wyplaty_by_month(int $contract_id): array {
    $out = [];
    foreach (get_rachunki('zlecenie', $contract_id) as $r) {
        if (rachunek_is_test($r) || $r['status'] === 'odrzucony' || $r['kwota_brutto'] === null) continue;
        [$ym, $src] = zl_rozl_rachunek_ym($r);
        $months = rachunek_okres_months($r);
        $out[$ym] ??= ['kwota' => 0.0, 'rachunki' => []];
        $out[$ym]['kwota'] = round($out[$ym]['kwota'] + (float)$r['kwota_brutto'], 2);
        $out[$ym]['rachunki'][] = ['id' => (int)$r['id'], 'numer' => $r['numer'], 'kwota' => (float)$r['kwota_brutto'], 'status' => $r['status'],
            'okres' => zl_ew_ym_valid((string)($r['okres_od'] ?? '')) && $months ? rachunek_okres_label($months[0], end($months)) : (string)($r['okres'] ?? ''),
            'zrodlo_ym' => $src, 'data_wystawienia' => $r['data_wystawienia']];
    }
    return $out;
}

/**
 * Pełne saldo umowy: wiersz na miesiąc (chronologicznie) + podsumowanie.
 *
 * @return array{miesiace: array<int, array>, suma: array}
 */
function zl_rozl_ledger(array $contract): array {
    $cid = (int)$contract['id'];
    $months = zl_ew_months($contract);
    sort($months);
    $wyplaty = zl_rozl_wyplaty_by_month($cid);
    $stany = [];
    foreach (db_all("SELECT * FROM zlecenie_rozliczenie_miesiace WHERE contract_id=?", [$cid]) as $s) $stany[$s['ym']] = $s;
    // Miesiące z rachunkami poza zakresem umowy też pokazujemy
    foreach (array_keys($wyplaty) as $ym) if (!in_array($ym, $months, true)) $months[] = $ym;
    sort($months);

    $is_ryczalt = (string)($contract['typ_stawki'] ?? '') === 'ryczalt';
    $saldo_prev = $is_ryczalt ? round((float)($contract['wynagrodzenie_brutto'] ?? 0), 2) : 0.0;

    $rows = []; $sum = ['godziny_ewid' => 0.0, 'godziny_rozl' => 0.0, 'naleznosc' => 0.0, 'wyplata' => 0.0];
    foreach ($months as $i => $ym) {
        $ewid  = zl_ew_month_hours($cid, $ym);
        $przes = zl_przes_month($cid, $ym);
        $kor   = zl_korekta_month($cid, $ym);
        $zr    = null; zl_ew_month_hours($cid, $ym, $zr);
        $rozl  = round($ewid - $przes['out'] + $przes['in'] + $kor['godziny'], 2);
        $stan  = $stany[$ym] ?? null;
        $nal_auto = round(zl_rozl_naleznosc($contract, $rozl) + $kor['kwota'], 2);
        $nal   = ($stan && $stan['naleznosc_override'] !== null) ? round((float)$stan['naleznosc_override'], 2) : $nal_auto;
        $wyp_r = $wyplaty[$ym]['kwota'] ?? 0.0;
        $wyp   = ($stan && $stan['wyplata_override'] !== null) ? round((float)$stan['wyplata_override'], 2) : $wyp_r;
        $do    = round($nal + $saldo_prev, 2);
        $poz   = round($do - $wyp, 2);
        // Różnica rachunek vs należność TEGO miesiąca (bez salda z poprzednich): >0 = rachunek wyższy niż wynika z godzin
        $roznica = round($wyp - $nal, 2);
        $stawka  = (float)($contract['stawka_kwota'] ?? 0);
        $godz_roznica = ((string)($contract['typ_stawki'] ?? '') === 'godzinowo' && $stawka > 0) ? round($roznica / $stawka, 2) : null;
        $rows[] = [
            'ym' => $ym, 'label' => zl_ew_ym_label($ym),
            'godziny_ewid' => $ewid, 'przes_out' => $przes['out'], 'przes_in' => $przes['in'], 'godziny_rozl' => $rozl,
            'korekty_kwota' => $kor['kwota'], 'korekty_godziny' => $kor['godziny'], 'korekty' => $kor['pozycje'],
            'godziny_zrodlo' => $zr,
            'naleznosc' => $nal, 'naleznosc_auto' => $nal_auto, 'naleznosc_override' => $stan['naleznosc_override'] ?? null,
            'przeniesione_in' => $saldo_prev, 'do_rozliczenia' => $do,
            'roznica' => $roznica,                       // zł: wypłata − należność miesiąca
            'brakuje_godzin'  => $godz_roznica !== null && $godz_roznica > 0.004 ? $godz_roznica : 0.0,   // rachunek wyższy → tylu godzin brakuje w ewidencji
            'nadmiar_godzin'  => $godz_roznica !== null && $godz_roznica < -0.004 ? -$godz_roznica : 0.0, // rachunek niższy → tyle godzin nie zostało opłaconych
            'wyplata_bez_rachunku' => $wyp > 0.004 && !($wyplaty[$ym]['rachunki'] ?? []),
            'wyplata' => $wyp, 'wyplata_rachunki' => $wyp_r, 'wyplata_override' => $stan['wyplata_override'] ?? null,
            'rachunki' => $wyplaty[$ym]['rachunki'] ?? [],
            'pozostalo' => $poz,
            'status' => $stan['status'] ?? 'otwarty', 'uwagi' => $stan['uwagi'] ?? null,
            'zamkniety_at' => $stan['zamkniety_at'] ?? null,
            'is_current' => $ym === date('Y-m'),
            'is_first'   => $i === 0,
        ];
        $saldo_prev = $poz;
        $sum['godziny_ewid'] += $ewid; $sum['godziny_rozl'] += $rozl; $sum['naleznosc'] += $nal; $sum['wyplata'] += $wyp;
    }
    $sum = array_map(fn($v) => round($v, 2), $sum);
    $sum['ryczalt']   = $is_ryczalt ? round((float)($contract['wynagrodzenie_brutto'] ?? 0), 2) : null;
    $sum['pozostalo'] = $rows ? end($rows)['pozostalo'] : ($is_ryczalt ? $sum['ryczalt'] : 0.0);
    $sum['roznica']   = round($sum['wyplata'] - $sum['naleznosc'], 2);
    $sum['brakuje_godzin'] = round(array_sum(array_column($rows, 'brakuje_godzin')), 2);
    $sum['nadmiar_godzin'] = round(array_sum(array_column($rows, 'nadmiar_godzin')), 2);
    $sum['typ_stawki'] = (string)($contract['typ_stawki'] ?? '');
    $sum['stawka']     = (float)($contract['stawka_kwota'] ?? 0);
    return ['miesiace' => array_reverse($rows), 'suma' => $sum];
}

/** Czy można zakończyć umowę bez „ogona”: pozostało 0 i żaden otwarty miesiąc nie ma godzin. */
function zl_rozl_close_blocker(array $contract): ?string {
    try {
        $l = zl_rozl_ledger($contract);
    } catch (\Throwable $e) { return null; }
    if (abs($l['suma']['pozostalo']) > 0.004) {
        return 'Saldo rozliczeń nie jest zerowe: pozostało ' . money($l['suma']['pozostalo']) . ' do rozliczenia.';
    }
    foreach ($l['miesiace'] as $m) {
        if ($m['status'] !== 'zamkniety' && $m['godziny_rozl'] > 0) return 'Miesiąc ' . $m['label'] . ' z godzinami nie został zamknięty w saldzie.';
    }
    return null;
}
