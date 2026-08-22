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
        // Dokładanie kolumn do istniejących tabel (idempotentnie, jak w rozliczenia.php)
        foreach ([
            'rozliczenie_id'       => 'INTEGER',
            'plik_sha256'          => 'TEXT',
            'kdok_doc_id'          => 'INTEGER',
            'kdok_number'          => 'TEXT',
            'downloaded_at'        => 'DATETIME',
            'download_count'       => 'INTEGER',
            'accepted_by'          => 'INTEGER',
            'accepted_at'          => 'DATETIME',
            'paid_at'              => 'DATETIME',
            'plik_podpisany'       => 'TEXT',
            'plik_podpisany_nazwa' => 'TEXT',
            'plik_podpisany_size'  => 'INTEGER',
            'signed_at'            => 'DATETIME',
            'signed_ip'            => 'TEXT',
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
    $now = date('Y-m-d H:i:s');
    return db_insert('zlecenie_rachunki', [
        'contract_type'    => $type,
        'contract_id'      => (int)($data['contract_id'] ?? 0),
        'status'           => $data['status'] ?? 'nowy',
        'numer'            => ($data['numer'] ?? '') !== '' ? $data['numer'] : null,
        'data_wystawienia' => ($data['data_wystawienia'] ?? '') !== '' ? $data['data_wystawienia'] : null,
        'okres'            => ($data['okres'] ?? '') !== '' ? $data['okres'] : null,
        'kwota_brutto'     => ($data['kwota_brutto'] ?? '') === '' || $data['kwota_brutto'] === null
                              ? null : (float)$data['kwota_brutto'],
        'uwagi'            => ($data['uwagi'] ?? '') !== '' ? $data['uwagi'] : null,
        'rozliczenie_id'   => !empty($data['rozliczenie_id']) ? (int)$data['rozliczenie_id'] : null,
        'plik'             => $data['plik']        ?? null,
        'plik_nazwa'       => $data['plik_nazwa']  ?? null,
        'plik_size'        => $data['plik_size']   ?? null,
        'plik_sha256'      => $data['plik_sha256'] ?? null,
        'download_count'   => 0,
        'created_by'       => $user_id,
        'created_at'       => $now,
        'updated_at'       => $now,
    ]);
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
    $body_html = $tpl['html'];
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

    try {
        $mail_id = mail_queue_add($email, $imie, $subject, $body_html, $body_text,
            'zlecenie', (int)$rach['contract_id'], '', true);
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Błąd wysyłki: ' . $e->getMessage()];
    }

    rachunek_notify_mark($rid, $email, $mail_id);

    return ['ok' => true, 'msg' => 'Wysłano powiadomienie do: ' . $email,
            'email' => $email, 'url' => $url, 'mail_id' => $mail_id];
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

/** Adres siedziby organizacji — dla dostarczenia oryginału. */
function rachunek_org_address(): string {
    $adres  = trim((string)org_setting('org_adres'));
    $miasto = trim((string)org_setting('org_miejscowosc'));
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
