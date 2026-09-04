<?php
/**
 * EODoK — Elektroniczny Obieg Dokumentów Księgowych.
 *
 * Osobny moduł (NIE część EOD Dokumentów Księgowych / KDOK w includes/ksiegowosc.php).
 * Skrót kodowy w kodzie/tabelach pozostaje „edok" (jak „kdok" dla KDOK) — nazwa
 * widoczna dla użytkowników to EODoK.
 * Realizuje pełny, 5-etapowy proces akceptacji dokumentu księgowego wymagany
 * ustawą o rachunkowości (art. 21): kontrola merytoryczna → formalno-prawna →
 * rachunkowa → dekretacja i alokacja kosztów → zatwierdzenie końcowe.
 *
 * Etapy są SEKWENCYJNE: edok_step_blocked_reason() blokuje decyzję na etapie N,
 * dopóki etap N-1 nie ma statusu 'ok'. edok_step_validation_errors() blokuje
 * samo "Tak/OK" (nie blokuje "Z uwagami"/"Odrzuć"), gdy brakuje wymaganych
 * danych na danym etapie.
 *
 * Audyt zastępuje fizyczne pieczątki: każda decyzja i zmiana statusu zapisuje
 * się w edok_events (insert-only) z identyfikatorem osoby, snapshotem jej roli
 * i stemplem czasowym serwera.
 *
 * Role (tabela edok_user_roles):
 *   upload     – może dodawać dokumenty
 *   meryt      – kontrola merytoryczna (etap 1)
 *   formal     – kontrola formalno-prawna (etap 2)
 *   rachunkowa – kontrola rachunkowa (etap 3)
 *   dekretacja – dekretacja i alokacja kosztów (etap 4)
 *   zatwierdza – zatwierdzenie końcowe do zapłaty i księgowania (etap 5)
 *   ksiegowy   – Główny Księgowy: cofanie decyzji (odblokowanie obiegu)
 * Admin ma wszystkie uprawnienia automatycznie.
 */

// ── Stałe ─────────────────────────────────────────────────────────────────────

const EDOK_TYPES = [
    'faktura_vat'        => 'Faktura VAT',
    'faktura_korygujaca' => 'Faktura korygująca',
    'rachunek'           => 'Rachunek',
    'nota_ksiegowa'      => 'Nota księgowa',
    'lista_plac'         => 'Lista płac',
    'inny'               => 'Inny dokument księgowy',
];

const EDOK_STATUSES = [
    'draft'         => ['label' => 'Projekt (wersja robocza)',              'class' => 'secondary'],
    'w_obiegu'      => ['label' => 'W obiegu',                              'class' => 'warning'],
    'zaakceptowany' => ['label' => 'Zaakceptowany do zapłaty i księgowania','class' => 'success'],
    'odrzucony'     => ['label' => 'Odrzucony',                             'class' => 'danger'],
    'wycofany'      => ['label' => 'Wycofany',                              'class' => 'dark'],
];

const EDOK_STEPS = [
    'meryt'      => 'Kontrola merytoryczna',
    'formal'     => 'Kontrola formalno-prawna',
    'rachunkowa' => 'Kontrola rachunkowa',
    'dekretacja' => 'Dekretacja i alokacja kosztów',
    'zatwierdza' => 'Zatwierdzenie końcowe do zapłaty i księgowania',
];

const EDOK_ROLES = [
    'upload'     => 'Może dodawać dokumenty',
    'meryt'      => 'Kontrola merytoryczna (etap 1)',
    'formal'     => 'Kontrola formalno-prawna (etap 2)',
    'rachunkowa' => 'Kontrola rachunkowa (etap 3)',
    'dekretacja' => 'Dekretacja i alokacja kosztów (etap 4)',
    'zatwierdza' => 'Zatwierdzenie końcowe do wypłaty i księgowania (etap 5)',
    'ksiegowy'   => 'Główny Księgowy – cofanie decyzji (odblokowanie obiegu)',
];

// Klasyfikacja kosztu na etapie dekretacji — zgodna z podziałem działalności
// organizacji: projekty/działania oraz statutowa odpłatna/nieodpłatna.
const EDOK_RODZAJ_DZIALALNOSCI = [
    'projekt'     => 'Projekt / działanie',
    'odplatna'    => 'Działalność statutowa odpłatna',
    'nieodplatna' => 'Działalność statutowa nieodpłatna',
];

// ── Schemat (samonaprawa, jak w innych modułach: CREATE TABLE + ALTER ADD COLUMN) ─

function edok_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $db = db();
    $db->exec("CREATE TABLE IF NOT EXISTS edok_documents (
        id     INTEGER PRIMARY KEY AUTOINCREMENT,
        number TEXT    NOT NULL DEFAULT '',
        title  TEXT    NOT NULL DEFAULT ''
    )");
    _edok_add_columns($db, 'edok_documents', [
        'typ_dokumentu'       => "TEXT NOT NULL DEFAULT ''",
        'description'         => "TEXT NOT NULL DEFAULT ''",
        'kontrahent_nazwa'    => "TEXT NOT NULL DEFAULT ''",
        'kontrahent_nip'      => "TEXT NOT NULL DEFAULT ''",
        'nr_faktury'          => "TEXT NOT NULL DEFAULT ''",
        'data_wystawienia'    => "TEXT",
        'data_sprzedazy'      => "TEXT",
        'data_wplywu'         => "TEXT",
        'kwota_netto'         => "TEXT NOT NULL DEFAULT ''",
        'kwota_vat'           => "TEXT NOT NULL DEFAULT ''",
        'kwota_brutto'        => "TEXT NOT NULL DEFAULT ''",
        'waluta'              => "TEXT NOT NULL DEFAULT 'PLN'",
        'rodzaj_dzialalnosci' => "TEXT NOT NULL DEFAULT ''",
        'projekt'             => "TEXT NOT NULL DEFAULT ''",
        'mpk'                 => "TEXT NOT NULL DEFAULT ''",
        'contract_type'       => "TEXT",
        'contract_id'         => "INTEGER",
        'file_path'           => "TEXT NOT NULL DEFAULT ''",
        'file_size'           => "INTEGER",
        'status'              => "TEXT NOT NULL DEFAULT 'draft'",
        'created_by'          => "INTEGER",
        'creator_name'        => "TEXT NOT NULL DEFAULT ''",
        'created_at'          => "TEXT",
        'updated_at'          => "TEXT",
    ]);

    $db->exec("CREATE TABLE IF NOT EXISTS edok_steps (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        doc_id     INTEGER NOT NULL,
        step_key   TEXT    NOT NULL,
        status     TEXT    NOT NULL DEFAULT 'oczekuje',
        user_id    INTEGER,
        user_name  TEXT    NOT NULL DEFAULT '',
        user_role  TEXT    NOT NULL DEFAULT '',
        decided_at TEXT,
        notes      TEXT    NOT NULL DEFAULT ''
    )");

    // Audyt — insert-only, zastępuje fizyczne pieczątki (identyfikator + stempel czasowy).
    $db->exec("CREATE TABLE IF NOT EXISTS edok_events (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        doc_id      INTEGER NOT NULL,
        event_type  TEXT    NOT NULL,
        step_key    TEXT    NOT NULL DEFAULT '',
        step_label  TEXT    NOT NULL DEFAULT '',
        from_status TEXT    NOT NULL DEFAULT '',
        to_status   TEXT    NOT NULL DEFAULT '',
        actor_id    INTEGER NOT NULL,
        actor_name  TEXT    NOT NULL DEFAULT '',
        actor_role  TEXT    NOT NULL DEFAULT '',
        comment     TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT ''
    )");

    // Przelewy własne / przesunięcia między rachunkami bankowymi organizacji —
    // "z jakiego na jakie i dlaczego", razem z klasyfikacją (rodzaj działalności/projekt)
    // źródłową i docelową. To zestawienie, nie obieg akceptacji — jeden wpis = jeden fakt.
    $db->exec("CREATE TABLE IF NOT EXISTS edok_transfers (
        id                     INTEGER PRIMARY KEY AUTOINCREMENT,
        data_przelewu          TEXT    NOT NULL DEFAULT '',
        rachunek_z_nrb         TEXT    NOT NULL DEFAULT '',
        rachunek_z_nazwa       TEXT    NOT NULL DEFAULT '',
        rachunek_do_nrb        TEXT    NOT NULL DEFAULT '',
        rachunek_do_nazwa      TEXT    NOT NULL DEFAULT '',
        kwota                  TEXT    NOT NULL DEFAULT '',
        waluta                 TEXT    NOT NULL DEFAULT 'PLN',
        rodzaj_dzialalnosci_z  TEXT    NOT NULL DEFAULT '',
        projekt_z              TEXT    NOT NULL DEFAULT '',
        rodzaj_dzialalnosci_do TEXT    NOT NULL DEFAULT '',
        projekt_do             TEXT    NOT NULL DEFAULT '',
        uzasadnienie           TEXT    NOT NULL DEFAULT '',
        created_by             INTEGER,
        creator_name           TEXT    NOT NULL DEFAULT '',
        created_at             TEXT    NOT NULL DEFAULT ''
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS edok_user_roles (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        role    TEXT    NOT NULL
    )");
    try { $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_edok_user_roles ON edok_user_roles(user_id, role)"); } catch (\Throwable $e) {}
}

function _edok_add_columns(PDO $db, string $table, array $cols): void {
    foreach ($cols as $col => $def) {
        try { $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}"); }
        catch (\Throwable $e) {} // kolumna już istnieje
    }
}

// ── Role ──────────────────────────────────────────────────────────────────────

function edok_has_role(string $role, ?int $user_id = null): bool {
    if (is_admin()) return true;
    $uid = $user_id ?? (current_user()['id'] ?? 0);
    if (!$uid) return false;
    return (bool) db_one("SELECT 1 FROM edok_user_roles WHERE user_id = ? AND role = ?", [$uid, $role]);
}

function edok_has_any_role(?int $user_id = null): bool {
    if (is_admin()) return true;
    $uid = $user_id ?? (current_user()['id'] ?? 0);
    if (!$uid) return false;
    return (bool) db_one("SELECT 1 FROM edok_user_roles WHERE user_id = ?", [$uid]);
}

function edok_require_role(string $role): void {
    require_login();
    if (!edok_has_role($role)) {
        http_response_code(403);
        require_once __DIR__ . '/header.php';
        echo '<div class="alert alert-danger m-4">Brak uprawnień do tej operacji.</div>';
        require_once __DIR__ . '/footer.php';
        exit;
    }
}

function edok_require_access(): void {
    require_login();
    if (!edok_has_any_role()) {
        http_response_code(403);
        require_once __DIR__ . '/header.php';
        echo '<div class="alert alert-danger m-4">Brak dostępu do modułu EODoK — Elektroniczny Obieg Dokumentów Księgowych.</div>';
        require_once __DIR__ . '/footer.php';
        exit;
    }
}

function edok_has_unlock_perm(): bool {
    return is_admin() || edok_has_role('ksiegowy');
}

function edok_user_roles(int $user_id): array {
    return array_column(db_all("SELECT role FROM edok_user_roles WHERE user_id = ?", [$user_id]), 'role');
}

// ── Numeracja ─────────────────────────────────────────────────────────────────

function edok_next_number(): string {
    $year = date('Y');
    $row  = db_one("SELECT number FROM edok_documents WHERE number LIKE ? ORDER BY id DESC LIMIT 1", ["EODoK/%/$year"]);
    $next = 1;
    if ($row) {
        preg_match('/EODoK\/(\d+)\//', $row['number'], $m);
        $next = (int)($m[1] ?? 0) + 1;
    }
    return sprintf('EODoK/%04d/%s', $next, $year);
}

// ── Pobieranie dokumentu ──────────────────────────────────────────────────────

function edok_get(int $id): ?array {
    $doc = db_one("SELECT * FROM edok_documents WHERE id = ?", [$id]);
    if (!$doc) return null;

    $doc['steps'] = [];
    foreach (db_all(
        "SELECT * FROM edok_steps WHERE doc_id = ?
         ORDER BY CASE step_key WHEN 'meryt' THEN 1 WHEN 'formal' THEN 2 WHEN 'rachunkowa' THEN 3 WHEN 'dekretacja' THEN 4 WHEN 'zatwierdza' THEN 5 END",
        [$id]
    ) as $s) {
        $doc['steps'][$s['step_key']] = $s;
    }
    return $doc;
}

function edok_events(int $doc_id): array {
    return db_all("SELECT * FROM edok_events WHERE doc_id = ? ORDER BY id ASC", [$doc_id]);
}

// ── Kolejność i walidacje etapów ───────────────────────────────────────────────

/** Kolejność ustawowa etapów obiegu (indeks 0 = brak poprzednika). */
function edok_step_order(): array {
    return array_keys(EDOK_STEPS);
}

/**
 * Zwraca powód blokady, jeśli etap $step_key nie może być jeszcze decydowany,
 * bo poprzedzający go etap nie ma statusu 'ok' — albo null, gdy droga jest wolna.
 */
function edok_step_blocked_reason(array $doc, string $step_key): ?string {
    $order = edok_step_order();
    $idx   = array_search($step_key, $order, true);
    if ($idx === false || $idx === 0) return null;

    $prev_key    = $order[$idx - 1];
    $prev_status = $doc['steps'][$prev_key]['status'] ?? null;
    if ($prev_status !== 'ok') {
        return 'Etap „' . EDOK_STEPS[$prev_key] . '” musi być zakończony (Tak/OK), zanim można zdecydować o etapie „' . EDOK_STEPS[$step_key] . '”.';
    }
    return null;
}

/**
 * Walidacje merytoryczne wymagane PRZED zaakceptowaniem („Tak/OK") danego etapu.
 * Nie blokują decyzji „Z uwagami"/„Odrzuć" — te muszą dać się zapisać zawsze,
 * żeby dokument dało się skierować do poprawy.
 */
function edok_step_validation_errors(array $doc, string $step_key): array {
    $errors = [];

    if ($step_key === 'meryt') {
        if (trim((string)($doc['description'] ?? '')) === '') $errors[] = 'Kontrola merytoryczna: uzupełnij opis wydatku (cel, powiązanie z zamówieniem/umową).';
        if (trim((string)($doc['file_path']   ?? '')) === '') $errors[] = 'Kontrola merytoryczna: dołącz skan dokumentu źródłowego.';
        $brutto = (float) str_replace(',', '.', str_replace(' ', '', (string)($doc['kwota_brutto'] ?? '')));
        if ($brutto <= 0) $errors[] = 'Kontrola merytoryczna: podaj kwotę brutto większą od zera.';
    }

    if ($step_key === 'formal') {
        $nip = preg_replace('/\D/', '', (string)($doc['kontrahent_nip'] ?? ''));
        if ($nip !== '' && !edok_nip_valid($nip)) $errors[] = 'Kontrola formalno-prawna: NIP kontrahenta ma nieprawidłową sumę kontrolną.';
        if (trim((string)($doc['nr_faktury'] ?? '')) === '') $errors[] = 'Kontrola formalno-prawna: podaj numer dokumentu.';
    }

    if ($step_key === 'rachunkowa') {
        $netto  = (float) str_replace(',', '.', str_replace(' ', '', (string)($doc['kwota_netto']  ?? '')));
        $vat    = (float) str_replace(',', '.', str_replace(' ', '', (string)($doc['kwota_vat']    ?? '')));
        $brutto = (float) str_replace(',', '.', str_replace(' ', '', (string)($doc['kwota_brutto'] ?? '')));
        if ($brutto > 0 && abs(($netto + $vat) - $brutto) > 0.01) {
            $errors[] = 'Kontrola rachunkowa: suma netto + VAT (' . number_format($netto + $vat, 2, ',', ' ')
                      . ' PLN) nie zgadza się z kwotą brutto (' . number_format($brutto, 2, ',', ' ') . ' PLN). Popraw dane finansowe dokumentu.';
        }
    }

    if ($step_key === 'dekretacja') {
        $rodzaj = trim((string)($doc['rodzaj_dzialalnosci'] ?? ''));
        if ($rodzaj === '' || !isset(EDOK_RODZAJ_DZIALALNOSCI[$rodzaj])) {
            $errors[] = 'Dekretacja: wskaż rodzaj działalności (projekt/działanie, statutowa odpłatna lub nieodpłatna).';
        } elseif ($rodzaj === 'projekt' && trim((string)($doc['projekt'] ?? '')) === '') {
            $errors[] = 'Dekretacja: przy rodzaju „Projekt / działanie” wskaż nazwę projektu.';
        }
    }

    return $errors;
}

function edok_nip_valid(string $nip): bool {
    if (!preg_match('/^\d{10}$/', $nip)) return false;
    $w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
    $sum = 0;
    for ($i = 0; $i < 9; $i++) $sum += $w[$i] * (int)$nip[$i];
    return ($sum % 11) === (int)$nip[9];
}

// ── Audyt ─────────────────────────────────────────────────────────────────────

/** Zapisuje jedno zdarzenie audytu — identyfikator + rola + stempel czasowy zastępują pieczątkę. */
function edok_log(int $doc_id, string $event_type, string $step_key, string $from_status, string $to_status, string $comment): void {
    $user = current_user();
    $uid  = (int)($user['id'] ?? 0);
    db_insert('edok_events', [
        'doc_id'      => $doc_id,
        'event_type'  => $event_type,
        'step_key'    => $step_key,
        'step_label'  => $step_key !== '' ? (EDOK_STEPS[$step_key] ?? $step_key) : '',
        'from_status' => $from_status,
        'to_status'   => $to_status,
        'actor_id'    => $uid,
        'actor_name'  => $user['name'] ?? ('uid:' . $uid),
        'actor_role'  => is_admin() ? 'admin' : implode(',', edok_user_roles($uid)),
        'comment'     => $comment,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
}

// ── Cykl życia dokumentu ────────────────────────────────────────────────────────

function edok_is_complete(array $doc): bool {
    foreach (array_keys(EDOK_STEPS) as $step) {
        if (($doc['steps'][$step]['status'] ?? null) !== 'ok') return false;
    }
    return true;
}

/**
 * Zapisuje decyzję jednego etapu obiegu dla dokumentu.
 * Zwraca ['status' => string edok_documents.status po zapisie, 'rejected' => bool].
 */
function edok_decide_step(array $doc, string $step_key, string $status, int $user_id, string $notes): array {
    $id    = (int)$doc['id'];
    $user  = current_user();
    $who   = $user['name'] ?? ('uid:' . $user_id);
    $role  = is_admin() ? 'admin' : (in_array($step_key, edok_user_roles($user_id), true) ? $step_key : implode(',', edok_user_roles($user_id)));
    $step_row  = $doc['steps'][$step_key] ?? null;
    $dec_label = match($status) { 'ok' => 'TAK', 'uwagi' => 'Z uwagami', 'odrzucono' => 'ODRZUCONO', default => $status };

    if ($step_row) {
        db_exec(
            "UPDATE edok_steps SET status=?, user_id=?, user_name=?, user_role=?, decided_at=?, notes=? WHERE id=?",
            [$status, $user_id, $who, $role, date('Y-m-d H:i:s'), $notes, $step_row['id']]
        );
    } else {
        db_insert('edok_steps', [
            'doc_id'     => $id,
            'step_key'   => $step_key,
            'status'     => $status,
            'user_id'    => $user_id,
            'user_name'  => $who,
            'user_role'  => $role,
            'decided_at' => date('Y-m-d H:i:s'),
            'notes'      => $notes,
        ]);
    }

    edok_log($id, 'decision', $step_key, $doc['status'], $doc['status'], EDOK_STEPS[$step_key] . ' → ' . $dec_label . ($notes !== '' ? (': ' . $notes) : ''));

    if ($status === 'odrzucono') {
        db_exec("UPDATE edok_documents SET status='odrzucony', updated_at=datetime('now') WHERE id=?", [$id]);
        edok_log($id, 'status_change', $step_key, $doc['status'], 'odrzucony', 'Dokument odrzucony na etapie: ' . EDOK_STEPS[$step_key]);
        return ['status' => 'odrzucony', 'rejected' => true];
    }

    $fresh      = edok_get($id);
    $new_status = edok_is_complete($fresh) ? 'zaakceptowany' : 'w_obiegu';
    db_exec("UPDATE edok_documents SET status=?, updated_at=datetime('now') WHERE id=?", [$new_status, $id]);
    if ($new_status === 'zaakceptowany') {
        edok_log($id, 'status_change', '', $doc['status'], 'zaakceptowany', 'Obieg zakończony — dokument zaakceptowany do zapłaty i księgowania (5/5 etapów).');
    }
    return ['status' => $new_status, 'rejected' => false];
}

/** Cofnięcie decyzji (tylko admin/ksiegowy) — resetuje wszystkie etapy, wznawia obieg od etapu 1. */
function edok_unlock(int $doc_id, string $reason): void {
    $doc = edok_get($doc_id);
    if (!$doc) throw new RuntimeException('Dokument nie istnieje.');
    if (!in_array($doc['status'], ['zaakceptowany', 'odrzucony'], true)) {
        throw new RuntimeException('Cofnięcie jest możliwe tylko dla dokumentu zaakceptowanego lub odrzuconego.');
    }
    db_exec("DELETE FROM edok_steps WHERE doc_id = ?", [$doc_id]);
    db_exec("UPDATE edok_documents SET status='w_obiegu', updated_at=datetime('now') WHERE id=?", [$doc_id]);
    edok_log($doc_id, 'unlock', '', $doc['status'], 'w_obiegu', 'Cofnięto decyzję, obieg wznowiony od etapu 1. Powód: ' . $reason);
}

/** Wycofanie dokumentu przez wnioskodawcę/admina — kończy obieg bez decyzji merytorycznej. */
function edok_withdraw(int $doc_id, string $reason): void {
    $doc = edok_get($doc_id);
    if (!$doc) throw new RuntimeException('Dokument nie istnieje.');
    if (in_array($doc['status'], ['zaakceptowany', 'wycofany'], true)) {
        throw new RuntimeException('Nie można wycofać dokumentu w tym stanie.');
    }
    db_exec("UPDATE edok_documents SET status='wycofany', updated_at=datetime('now') WHERE id=?", [$doc_id]);
    edok_log($doc_id, 'withdraw', '', $doc['status'], 'wycofany', $reason);
}

// ── Przelewy własne / przesunięcia po rachunkach i klasyfikacjach ─────────────

/** Lista rachunków bankowych organizacji (settings.org_rachunki_bankowe, JSON). */
function edok_rachunki_list(): array {
    return json_decode(org_setting('org_rachunki_bankowe') ?: '[]', true) ?: [];
}

function edok_transfer_add(array $d, int $user_id): int {
    $user = current_user();
    $id = db_insert('edok_transfers', [
        'data_przelewu'          => $d['data_przelewu'],
        'rachunek_z_nrb'         => $d['rachunek_z_nrb'],
        'rachunek_z_nazwa'       => $d['rachunek_z_nazwa'],
        'rachunek_do_nrb'        => $d['rachunek_do_nrb'],
        'rachunek_do_nazwa'      => $d['rachunek_do_nazwa'],
        'kwota'                  => $d['kwota'],
        'waluta'                 => $d['waluta'] ?: 'PLN',
        'rodzaj_dzialalnosci_z'  => $d['rodzaj_dzialalnosci_z'],
        'projekt_z'              => $d['projekt_z'],
        'rodzaj_dzialalnosci_do' => $d['rodzaj_dzialalnosci_do'],
        'projekt_do'             => $d['projekt_do'],
        'uzasadnienie'           => $d['uzasadnienie'],
        'created_by'             => $user_id,
        'creator_name'           => $user['name'] ?? ('uid:' . $user_id),
        'created_at'             => date('Y-m-d H:i:s'),
    ]);
    return $id;
}

function edok_transfer_label_klasyfikacja(string $rodzaj, string $projekt): string {
    if ($rodzaj === '') return '—';
    $label = EDOK_RODZAJ_DZIALALNOSCI[$rodzaj] ?? $rodzaj;
    return $rodzaj === 'projekt' && $projekt !== '' ? $label . ': ' . $projekt : $label;
}

// ── Helpery UI ────────────────────────────────────────────────────────────────

function edok_status_badge(string $status): string {
    $s = EDOK_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . h($s['label']) . '</span>';
}
