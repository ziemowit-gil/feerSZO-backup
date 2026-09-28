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
    // Wydatki / dokumenty kosztowe
    'faktura_vat'        => 'Faktura VAT',
    'faktura_korygujaca' => 'Faktura korygująca',
    'rachunek'           => 'Rachunek',
    'nota_ksiegowa'      => 'Nota księgowa',
    'lista_plac'         => 'Lista płac',
    'inny'               => 'Inny dokument księgowy',
    // Przychody (Uchwała 5/2026 §1 pkt 2 — obowiązkowo od 1.10.2026, patrz EDOK_TYPES_PRZYCHOD)
    'wyciag_bankowy'      => 'Wyciąg bankowy',
    'potwierdzenie_wplaty' => 'Potwierdzenie wpłaty',
    'faktura_sprzedazy'   => 'Faktura sprzedaży',
    'darowizna'           => 'Darowizna',
    'dotacja_grant'       => 'Dotacja / grant',
    'inny_przychod'       => 'Inny dokument przychodowy',
];

/** Typy dokumentów klasyfikowane jako przychodowe (edok_documents.kierunek = 'przychod'). */
const EDOK_TYPES_PRZYCHOD = ['wyciag_bankowy', 'potwierdzenie_wplaty', 'faktura_sprzedazy', 'darowizna', 'dotacja_grant', 'inny_przychod'];

// Daty graniczne wprowadzenia EODoK dla dokumentów historycznych — faktury
// i rachunki wystawione przed tymi datami idą dotychczasowym obiegiem (KDOK),
// nie przez EODoK (patrz edok_typ_data_graniczna(), sprawdzane w edok/add.php).
const EDOK_CUTOFF_FAKTURA  = '2026-09-01'; // faktury VAT / korygujące
const EDOK_CUTOFF_RACHUNEK = '2026-10-01'; // rachunki

/** Data graniczna dla typu dokumentu (faktury/rachunki) — null, jeśli typ nie ma granicy. */
function edok_typ_data_graniczna(string $typ_dokumentu): ?string {
    if (in_array($typ_dokumentu, ['faktura_vat', 'faktura_korygujaca'], true)) return EDOK_CUTOFF_FAKTURA;
    if ($typ_dokumentu === 'rachunek') return EDOK_CUTOFF_RACHUNEK;
    return null;
}

const EDOK_STATUSES = [
    'draft'         => ['label' => 'Projekt (wersja robocza)',              'class' => 'secondary'],
    'w_obiegu'      => ['label' => 'W obiegu',                              'class' => 'warning'],
    'zaakceptowany' => ['label' => 'Zaakceptowany do zapłaty i księgowania','class' => 'success'],
    'odrzucony'     => ['label' => 'Odrzucony',                             'class' => 'danger'],
    'wycofany'      => ['label' => 'Wycofany',                              'class' => 'dark'],
];

const EDOK_STEPS = [
    'meryt'      => 'Zaakceptowano pod względem merytorycznym',
    'formal'     => 'Zaakceptowano pod względem formalnym',
    'rachunkowa' => 'Zaakceptowano pod względem rachunkowym',
    'dekretacja' => 'Dekretacja i alokacja kosztów',
    'zatwierdza' => 'Zatwierdzam do zapłaty / wypłaty',
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
        // Preliminarz Płatności (przeniesiony z KDOK — patrz edok_preliminarz_query())
        'termin_platnosci'       => "TEXT",
        'rachunek_bankowy'       => "TEXT NOT NULL DEFAULT ''",
        'status_platnosci'       => "TEXT NOT NULL DEFAULT 'nowy'",
        'wymaga_mpp'             => "INTEGER NOT NULL DEFAULT 0",
        'wyklucz_z_preliminarza' => "INTEGER NOT NULL DEFAULT 0",
        'tytul_przelewu'         => "TEXT NOT NULL DEFAULT ''",
        // Stawka VAT — kod z CRM_OFFER_VAT_RATES (includes/crm_offers.php): 23/8/5/0/zw/np
        'stawka_vat'             => "TEXT NOT NULL DEFAULT ''",
        // Numer referencyjny KSeF, gdy dokument pochodzi z synchronizacji
        'ksef_reference'         => "TEXT NOT NULL DEFAULT ''",
        // Dokumenty przychodowe (Uchwała 5/2026 §1 pkt 2) — kierunek odróżnia je od
        // wydatków (inny etap 5, inna walidacja formalna, pomijane w tytule przelewu
        // i Preliminarzu Płatności — patrz edok_step_label()/edok_preliminarz_query()).
        'kierunek'               => "TEXT NOT NULL DEFAULT 'wydatek'",
        'zrodlo_przychodu'       => "TEXT NOT NULL DEFAULT ''",
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
    _edok_add_columns($db, 'edok_steps', [
        // Weryfikacja tożsamości przy akceptacji (Uchwała 5/2026, §1 pkt 4) — tylko dla
        // decyzji "Tak/OK": metoda ('pin'), wynik ('ok') i czas weryfikacji.
        'verify_method' => "TEXT NOT NULL DEFAULT ''",
        'verify_result' => "TEXT NOT NULL DEFAULT ''",
        'verified_at'   => "TEXT",
    ]);

    // PIN EODoK — osobny sekret od hasła logowania, wymagany do potwierdzenia decyzji
    // "Tak/OK" na każdym z 5 etapów (Uchwała 5/2026, §1 pkt 4). Blokada po zbyt wielu
    // nieudanych próbach, jak w edok_kontrahent_accounts (includes/edok_portal_auth.php).
    $db->exec("CREATE TABLE IF NOT EXISTS edok_user_pins (
        user_id         INTEGER PRIMARY KEY,
        pin_hash        TEXT    NOT NULL DEFAULT '',
        failed_attempts INTEGER NOT NULL DEFAULT 0,
        locked_until    TEXT,
        created_at      TEXT    NOT NULL DEFAULT '',
        updated_at      TEXT    NOT NULL DEFAULT ''
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

    // Potwierdzone pary NIP + rachunek kontrahenta — przy PIERWSZYM eksporcie
    // przelewu na daną parę (w tym na nowy rachunek znanego NIP-u) eksport
    // wymaga ręcznego potwierdzenia poprawności. Patrz edok_ipko_unverified_pairs().
    $db->exec("CREATE TABLE IF NOT EXISTS edok_kontrahent_verified (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        nip              TEXT    NOT NULL DEFAULT '',
        nrb              TEXT    NOT NULL DEFAULT '',
        kontrahent_nazwa TEXT    NOT NULL DEFAULT '',
        verified_by      INTEGER,
        verifier_name    TEXT    NOT NULL DEFAULT '',
        verified_at      TEXT    NOT NULL DEFAULT ''
    )");
    try { $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_edok_kontrahent_verified ON edok_kontrahent_verified(nip, nrb)"); } catch (\Throwable $e) {}

    $db->exec("CREATE TABLE IF NOT EXISTS edok_user_roles (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        role    TEXT    NOT NULL
    )");
    try { $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_edok_user_roles ON edok_user_roles(user_id, role)"); } catch (\Throwable $e) {}

    // Kolejka dedup KSeF (przeniesiona z KDOK) — reużywa kdok_ksef_sync_export()/
    // kdok_ksef_sync_range() z includes/kdok_ksef.php, wskazując tę tabelę i
    // edok_ksef_create_doc() jako cel importu. Ten sam kształt co kdok_ksef_queue.
    $db->exec("CREATE TABLE IF NOT EXISTS edok_ksef_queue (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        ksef_reference TEXT    NOT NULL UNIQUE,
        invoice_number TEXT    NOT NULL DEFAULT '',
        seller_name    TEXT    NOT NULL DEFAULT '',
        seller_nip     TEXT    NOT NULL DEFAULT '',
        gross_value    TEXT    NOT NULL DEFAULT '',
        currency       TEXT    NOT NULL DEFAULT 'PLN',
        issue_date     TEXT    NOT NULL DEFAULT '',
        ksef_date      TEXT    NOT NULL DEFAULT '',
        doc_id         INTEGER DEFAULT NULL,
        created_at     TEXT    NOT NULL DEFAULT ''
    )");

    // Dokumenty końcowe (źródło + karta akceptacji) wygenerowane przez edok_generate_final_pdf()
    $db->exec("CREATE TABLE IF NOT EXISTS edok_generated_pdf (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        doc_id        INTEGER NOT NULL,
        file_path     TEXT    NOT NULL DEFAULT '',
        file_sha256   TEXT    NOT NULL DEFAULT '',
        file_size     INTEGER,
        generated_by  INTEGER,
        gen_name      TEXT    NOT NULL DEFAULT '',
        created_at    TEXT    NOT NULL DEFAULT ''
    )");

    // Archiwum miesięczne (Uchwała 5/2026 §7) — wydruk kart akceptacji + automatyczne
    // zestawienie dokument→karta→akceptant za dany miesiąc. generated_by NULL = cron.
    $db->exec("CREATE TABLE IF NOT EXISTS edok_monthly_archive (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        year          INTEGER NOT NULL,
        month         INTEGER NOT NULL,
        pdf_path      TEXT    NOT NULL DEFAULT '',
        csv_path      TEXT    NOT NULL DEFAULT '',
        doc_count     INTEGER NOT NULL DEFAULT 0,
        generated_by  INTEGER,
        gen_name      TEXT    NOT NULL DEFAULT '',
        generated_at  TEXT    NOT NULL DEFAULT '',
        verified_by   INTEGER,
        verified_name TEXT    NOT NULL DEFAULT '',
        verified_at   TEXT
    )");
    try { $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_edok_monthly_archive ON edok_monthly_archive(year, month)"); } catch (\Throwable $e) {}
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

// ── PIN — weryfikacja tożsamości przy akceptacji etapu (Uchwała 5/2026, §1 pkt 4) ─
// Osobny sekret dla EODoK, NIE hasło logowania. Wymagany tylko przy decyzji
// "Tak/OK" (akceptacja) — "Z uwagami"/"Odrzuć" nie są aktem akceptacji dokumentu.

const EDOK_PIN_MAX_ATTEMPTS = 5;
const EDOK_PIN_LOCKOUT_MIN  = 15;

function edok_pin_is_set(int $user_id): bool {
    return (bool) db_one("SELECT 1 FROM edok_user_pins WHERE user_id = ? AND pin_hash != ''", [$user_id]);
}

/** Ustawia/zmienia PIN. Rzuca wyjątek przy nieprawidłowym formacie (musi być walidowane wcześniej po stronie wywołującej co do potwierdzenia hasła). */
function edok_pin_set(int $user_id, string $pin): void {
    if (!preg_match('/^\d{6}$/', $pin)) throw new RuntimeException('PIN musi składać się z dokładnie 6 cyfr.');
    $hash = password_hash($pin, PASSWORD_BCRYPT);
    if (db_one("SELECT user_id FROM edok_user_pins WHERE user_id = ?", [$user_id])) {
        db_exec("UPDATE edok_user_pins SET pin_hash=?, failed_attempts=0, locked_until=NULL, updated_at=datetime('now') WHERE user_id=?", [$hash, $user_id]);
    } else {
        db_insert('edok_user_pins', [
            'user_id'         => $user_id,
            'pin_hash'        => $hash,
            'failed_attempts' => 0,
            'locked_until'    => null,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
    }
}

/**
 * Weryfikuje PIN przy próbie akceptacji ("Tak/OK") etapu $step_key dokumentu $doc_id.
 * Blokuje na EDOK_PIN_LOCKOUT_MIN minut po EDOK_PIN_MAX_ATTEMPTS nieudanych próbach.
 * Każda próba (udana i nieudana) o wyniku negatywnym trafia do audytu (edok_events).
 * Zwraca null przy sukcesie, albo komunikat błędu do pokazania użytkownikowi.
 */
function edok_pin_verify_for_decision(int $user_id, string $pin, int $doc_id, string $step_key): ?string {
    $row = db_one("SELECT * FROM edok_user_pins WHERE user_id = ?", [$user_id]);
    if (!$row || $row['pin_hash'] === '') {
        return 'Nie masz jeszcze ustawionego PIN-u EODoK — ustaw go, aby móc akceptować dokumenty.';
    }
    if (!empty($row['locked_until']) && strtotime($row['locked_until']) > time()) {
        return 'PIN zablokowany po zbyt wielu nieudanych próbach — spróbuj ponownie po ' . date('H:i', strtotime($row['locked_until'])) . '.';
    }
    if (!preg_match('/^\d{6}$/', $pin) || !password_verify($pin, $row['pin_hash'])) {
        $attempts     = (int)$row['failed_attempts'] + 1;
        $locked_until = $attempts >= EDOK_PIN_MAX_ATTEMPTS
            ? date('Y-m-d H:i:s', strtotime('+' . EDOK_PIN_LOCKOUT_MIN . ' minutes'))
            : null;
        db_exec("UPDATE edok_user_pins SET failed_attempts=?, locked_until=? WHERE user_id=?", [$attempts, $locked_until, $user_id]);
        edok_log($doc_id, 'pin_failed', $step_key, '', '',
            'Błędny PIN przy próbie akceptacji etapu „' . (EDOK_STEPS[$step_key] ?? $step_key) . '”'
            . ($locked_until ? ' — PIN zablokowany do ' . date('H:i', strtotime($locked_until)) . '.' : '.'));
        return $locked_until
            ? 'Błędny PIN. PIN zablokowany na ' . EDOK_PIN_LOCKOUT_MIN . ' minut po ' . EDOK_PIN_MAX_ATTEMPTS . ' nieudanych próbach.'
            : 'Błędny PIN.';
    }
    db_exec("UPDATE edok_user_pins SET failed_attempts=0, locked_until=NULL WHERE user_id=?", [$user_id]);
    return null;
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

/**
 * Numer dla dokumentu TESTOWEGO — osobny prefiks "EODoK-TEST/" (nigdy nie pasuje
 * do wzorca "EODoK/NNNN/RRRR" z edok_next_number()), żeby dokumenty demo/testowe
 * nigdy nie zużywały realnej sekwencji numerów akceptacji ani nie mieszały się
 * z prawdziwymi dokumentami w Preliminarzu/archiwizacji. Numeracja literowa:
 * A, B, C… Z, AA, AB… (kolejna wolna litera wg już istniejących numerów testowych).
 */
function edok_next_test_number(): string {
    $rows = db_all("SELECT number FROM edok_documents WHERE number LIKE 'EODoK-TEST/%'");
    $used = 0;
    foreach ($rows as $r) {
        if (preg_match('/^EODoK-TEST\/([A-Z]+)$/', $r['number'], $m)) {
            $val = 0;
            foreach (str_split($m[1]) as $ch) $val = $val * 26 + (ord($ch) - 64);
            $used = max($used, $val);
        }
    }
    $n = $used + 1;
    $letters = '';
    while ($n > 0) {
        $n--;
        $letters = chr(65 + ($n % 26)) . $letters;
        $n = intdiv($n, 26);
    }
    return 'EODoK-TEST/' . $letters;
}

/** Czy numer dokumentu jest numerem testowym (edok_next_test_number()) — do masowego czyszczenia demo/testów. */
function edok_is_test_number(string $number): bool {
    return str_starts_with($number, 'EODoK-TEST/');
}

/**
 * Usuwa wszystkie dokumenty testowe (i powiązane etapy/audyt/wygenerowane PDF-y,
 * łącznie z plikami na dysku, best-effort). Zwraca liczbę usuniętych dokumentów.
 */
function edok_delete_test_documents(): int {
    $rows = db_all("SELECT id FROM edok_documents WHERE number LIKE 'EODoK-TEST/%'");
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        foreach (db_all("SELECT file_path FROM edok_generated_pdf WHERE doc_id = ?", [$id]) as $g) {
            if ($g['file_path']) @unlink(UPLOAD_DIR . ltrim($g['file_path'], '/'));
        }
        db_exec("DELETE FROM edok_generated_pdf WHERE doc_id = ?", [$id]);
        db_exec("DELETE FROM edok_steps WHERE doc_id = ?", [$id]);
        db_exec("DELETE FROM edok_events WHERE doc_id = ?", [$id]);
        db_exec("DELETE FROM edok_documents WHERE id = ?", [$id]);
    }
    return count($rows);
}

/** Czy typ dokumentu jest fakturą (tytuł przelewu używa wtedy oznaczenia FAK, nie DOK). */
function edok_tytul_jest_faktura(string $typ_dokumentu): bool {
    return in_array($typ_dokumentu, ['faktura_vat', 'faktura_korygujaca'], true);
}

/**
 * Tytuł przelewu (dla wydatku) albo sugerowana referencja wpłaty (dla przychodu),
 * oparty na formatach z Uchwały 5/2026 §2 pkt 8-9, w kolejności ustalonej z
 * użytkownikiem: 1) opis, 2) numer dokumentu źródłowego (FAK/DOK), 3) numer
 * akceptacji (AKC), 4) kwota:
 *   faktura:     "PŁATNOŚĆ: {opis} - FAK: {nr faktury} - AKC: {numer akceptacji} - {kwota} {waluta}"
 *   bez faktury: "PŁATNOŚĆ: {opis} - DOK: {typ dokumentu} {nr} - AKC: {numer akceptacji} - {kwota} {waluta}"
 * Dla dokumentów przychodowych (kierunek=przychod) prefiks to "PRZYCHÓD:" zamiast
 * "PŁATNOŚĆ:", zawsze z identyfikatorem "DOK:" — nie ma tu wychodzącej płatności do
 * zlecenia, ale wartość i tak jest użyteczna jako sugerowana referencja, którą
 * wpłacający może podać w tytule swojego przelewu, albo do ręcznego dopasowania
 * wpływu na wyciągu bankowym do tego dokumentu (etap 5/5 — patrz edok/view.php).
 * Separator " - " (nie "|") — pionowa kreska bywa odrzucana lub obcinana przez
 * systemy bankowości elektronicznej (np. PKO) w polu tytułu przelewu; uchwała
 * używa "|" tylko jako wizualny separator w treści dokumentu, nie jako wymóg co do
 * znaku w samym przelewie. Z tego samego powodu wielokropek to zwykłe trzy kropki,
 * nie znak „…” (U+2026). Numerem akceptacji jest numer EODoK (nadawany przy intake,
 * patrz edok_next_number()) — jedyny numer, jaki dokument ma od początku obiegu.
 * Opis to pierwsze 60 znaków pola "description"; gdy pusty, używana jest nazwa typu
 * dokumentu. Przy przekroczeniu limitu 140 znaków (typowy limit pola tytułu w
 * bankowości elektronicznej — ten sam co w KDOK, ksiegowosc/add.php) tytuł skraca
 * się do formatu wprost z uchwały (§2 pkt 11) "AKC:{numer} FAK:{nr}" /
 * "AKC:{numer} DOK:{identyfikator}" — ta kolejność skrótu (AKC pierwsze) jest
 * cytatem z treści uchwały i NIE zmienia się razem z kolejnością pól pełnego
 * formatu powyżej; kwota jest w skrócie pomijana jako pierwsza (§2 pkt 11:
 * odrzuca się najpierw opis celu płatności, kwota nie jest wymieniona w ogóle,
 * więc traktowana jest jako równie zbywalna przy braku miejsca).
 */
function edok_generate_tytul_przelewu(array $doc): string {
    $jest_przychod = ($doc['kierunek'] ?? 'wydatek') === 'przychod';

    $numer         = trim((string)($doc['number'] ?? ''));
    $typ_dokumentu = (string)($doc['typ_dokumentu'] ?? '');
    $typ_label     = EDOK_TYPES[$typ_dokumentu] ?? 'Dokument księgowy';
    $nr_dok        = trim((string)($doc['nr_faktury'] ?? ''));
    $jest_faktura  = !$jest_przychod && edok_tytul_jest_faktura($typ_dokumentu) && $nr_dok !== '';
    $kwota         = trim((string)($doc['kwota_brutto'] ?? ''));
    $waluta        = trim((string)($doc['waluta'] ?? '')) ?: 'PLN';

    $opis = preg_replace('/\s+/', ' ', trim((string)($doc['description'] ?? '')));
    $opis_krotki = $typ_label;
    if ($opis !== '') {
        $opis_krotki = mb_substr($opis, 0, 60);
        if (mb_strlen($opis) > 60) $opis_krotki .= '...';
    }

    $ident = $jest_faktura
        ? 'FAK: ' . $nr_dok
        : 'DOK: ' . trim($typ_label . ($nr_dok !== '' ? ' ' . $nr_dok : ''));

    $parts = [($jest_przychod ? 'PRZYCHÓD: ' : 'PŁATNOŚĆ: ') . $opis_krotki, $ident];
    if ($numer !== '') $parts[] = 'AKC: ' . $numer;
    if ($kwota !== '') $parts[] = $kwota . ' ' . $waluta;
    $t = implode(' - ', $parts);

    if (mb_strlen($t) <= 140) return $t;

    // Format skrócony (§2 pkt 11): zachowuje numer akceptacji i identyfikator
    // dokumentu, odrzuca opis celu płatności jako pierwszy.
    $short_akc   = $numer !== '' ? 'AKC:' . $numer : '';
    $short_ident = $jest_faktura ? 'FAK:' . $nr_dok : 'DOK:' . ($nr_dok !== '' ? $nr_dok : $numer);
    $short = trim($short_akc . ' ' . $short_ident);
    return mb_substr($short, 0, 140);
}

/**
 * Tytuł zbiorczy dla płatności obejmującej kilka dokumentów jednym przelewem
 * (§2 pkt 10-11). Gdy pełna lista numerów faktur/dokumentów nie mieści się
 * w limicie 140 znaków, używa oznaczenia PAKIET — pełna lista pozostaje
 * w dokumentacji przelewu (edok_documents/edok_transfers), nie w samym tytule.
 */
function edok_generate_tytul_pakiet(array $docs, string $opis_celu = ''): string {
    $docs = array_values($docs);
    if (count($docs) === 1) return edok_generate_tytul_przelewu($docs[0]);
    if (count($docs) === 0) return '';

    $numery  = array_values(array_unique(array_filter(array_map(fn($d) => trim((string)($d['number'] ?? '')), $docs))));
    $faktury = array_values(array_unique(array_filter(array_map(fn($d) => trim((string)($d['nr_faktury'] ?? '')), $docs))));
    $waluta  = trim((string)($docs[0]['waluta'] ?? '')) ?: 'PLN';
    $suma    = array_sum(array_map(fn($d) => _edok_kwota_float((string)($d['kwota_brutto'] ?? '0')), $docs));

    $opis = $opis_celu !== '' ? preg_replace('/\s+/', ' ', trim($opis_celu)) : ('zbiorcza płatność za ' . count($docs) . ' dokumentów');
    $opis_krotki = mb_substr($opis, 0, 60);
    if (mb_strlen($opis) > 60) $opis_krotki .= '...';

    $akc = 'AKC: ' . implode(', ', $numery);
    $fak = $faktury ? 'FAK: ' . implode(', ', $faktury) : 'DOK: ' . implode(', ', $numery);

    $t = 'PŁATNOŚĆ: ' . $opis_krotki . ' - ' . $fak . ' - ' . $akc . ' - ' . number_format($suma, 2, ',', '') . ' ' . $waluta;
    if (mb_strlen($t) <= 140) return $t;

    $pierwszy  = $numery[0] ?? '';
    $short_akc = count($numery) > 1 ? 'AKC:' . $pierwszy . '+' . (count($numery) - 1) : 'AKC:' . $pierwszy;
    return mb_substr(trim($short_akc . ' PAKIET'), 0, 140);
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
 * Etykieta etapu — zależna od kierunku dokumentu. Uchwała 5/2026 §1 pkt 2 obejmuje
 * też dokumenty przychodowe, dla których etap 5 nie jest "zatwierdzeniem do zapłaty"
 * (nie ma wychodzącej płatności), tylko zatwierdzeniem do ujęcia przychodu w
 * ewidencji. Pozostałe etapy nazywają się tak samo niezależnie od kierunku.
 */
function edok_step_label(string $step_key, ?array $doc = null): string {
    if ($step_key === 'zatwierdza' && ($doc['kierunek'] ?? 'wydatek') === 'przychod') {
        return 'Zatwierdzenie do ujęcia przychodu w ewidencji';
    }
    return EDOK_STEPS[$step_key] ?? $step_key;
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
        return 'Etap „' . edok_step_label($prev_key, $doc) . '” musi być zakończony (Tak/OK), zanim można zdecydować o etapie „' . edok_step_label($step_key, $doc) . '”.';
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
        // Dokumenty przychodowe (Uchwała 5/2026 §2 ostatni punkt): źródło i data wpływu
        // muszą być ustalone, żeby powiązać wpływ z zapisem w ewidencji.
        if (($doc['kierunek'] ?? 'wydatek') === 'przychod') {
            if (trim((string)($doc['zrodlo_przychodu'] ?? '')) === '') $errors[] = 'Kontrola formalno-prawna: wskaż źródło przychodu (darczyńca, kontrahent albo tytuł wpływu).';
            if (trim((string)($doc['data_wplywu'] ?? '')) === '') $errors[] = 'Kontrola formalno-prawna: podaj datę wpływu środków.';
        }
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

/** Zapisuje jedno zdarzenie audytu — identyfikator + rola + stempel czasowy zastępują pieczątkę. $doc (opcjonalnie) daje etykiecie etapu właściwy kierunek (patrz edok_step_label()). */
function edok_log(int $doc_id, string $event_type, string $step_key, string $from_status, string $to_status, string $comment, ?array $doc = null): void {
    $user = current_user();
    $uid  = (int)($user['id'] ?? 0);
    db_insert('edok_events', [
        'doc_id'      => $doc_id,
        'event_type'  => $event_type,
        'step_key'    => $step_key,
        'step_label'  => $step_key !== '' ? edok_step_label($step_key, $doc) : '',
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
 * Dokumenty gotowe do akceptacji "Tak/OK" przez $user_id, pogrupowane wg etapu
 * (edok/pending.php — "podpisywanie zbiorcze": jeden wpisany PIN potwierdza
 * wiele dokumentów naraz zamiast osobno dla każdego). Zwraca tylko dokumenty,
 * dla których dany etap NIE jest jeszcze zdecydowany, NIE jest zablokowany
 * poprzednim etapem, user ma do niego rolę, i walidacja "OK" przechodzi bez
 * błędów (niekompletne dane wymagają ręcznej uwagi, nie nadają się do
 * zbiorczego podpisu). Każdy dokument czeka najwyżej na JEDEN aktywny etap
 * naraz — sekwencyjność obiegu (edok_step_blocked_reason()) to gwarantuje.
 *
 * @return array<string, array> step_key => lista dokumentów (edok_get())
 */
function edok_pending_for_user(int $user_id): array {
    $out = [];
    foreach (db_all("SELECT id FROM edok_documents WHERE status = 'w_obiegu'") as $row) {
        $doc = edok_get((int)$row['id']);
        if (!$doc) continue;
        foreach (edok_step_order() as $step_key) {
            $step = $doc['steps'][$step_key] ?? null;
            if ($step && in_array($step['status'], ['ok', 'uwagi', 'odrzucono'], true)) continue;
            if (edok_step_blocked_reason($doc, $step_key) !== null) continue;
            if (!edok_has_role($step_key, $user_id)) continue;
            if (edok_step_validation_errors($doc, $step_key)) continue;
            $out[$step_key][] = $doc;
            break; // znaleziono jedyny aktywny etap tego dokumentu — reszta nieistotna
        }
    }
    return $out;
}

/**
 * Zapisuje decyzję jednego etapu obiegu dla dokumentu.
 * Zwraca ['status' => string edok_documents.status po zapisie, 'rejected' => bool].
 */
function edok_decide_step(array $doc, string $step_key, string $status, int $user_id, string $notes, bool $pin_verified = false): array {
    $id    = (int)$doc['id'];
    $user  = current_user();
    $who   = $user['name'] ?? ('uid:' . $user_id);
    $role  = is_admin() ? 'admin' : (in_array($step_key, edok_user_roles($user_id), true) ? $step_key : implode(',', edok_user_roles($user_id)));
    $step_row  = $doc['steps'][$step_key] ?? null;
    $dec_label = match($status) { 'ok' => 'TAK', 'uwagi' => 'Z uwagami', 'odrzucono' => 'ODRZUCONO', default => $status };

    // Weryfikacja PIN wymagana i sprawdzona wcześniej (edok_pin_verify_for_decision) tylko
    // dla decyzji "Tak/OK" — to jedyna, którą uchwała nazywa "akceptacją dokumentu".
    $verify_method = ($status === 'ok' && $pin_verified) ? 'pin' : '';
    $verify_result = ($status === 'ok' && $pin_verified) ? 'ok'  : '';
    $verified_at   = ($status === 'ok' && $pin_verified) ? date('Y-m-d H:i:s') : null;

    if ($step_row) {
        db_exec(
            "UPDATE edok_steps SET status=?, user_id=?, user_name=?, user_role=?, decided_at=?, notes=?, verify_method=?, verify_result=?, verified_at=? WHERE id=?",
            [$status, $user_id, $who, $role, date('Y-m-d H:i:s'), $notes, $verify_method, $verify_result, $verified_at, $step_row['id']]
        );
    } else {
        db_insert('edok_steps', [
            'doc_id'        => $id,
            'step_key'      => $step_key,
            'status'        => $status,
            'user_id'       => $user_id,
            'user_name'     => $who,
            'user_role'     => $role,
            'decided_at'    => date('Y-m-d H:i:s'),
            'notes'         => $notes,
            'verify_method' => $verify_method,
            'verify_result' => $verify_result,
            'verified_at'   => $verified_at,
        ]);
    }

    edok_log($id, 'decision', $step_key, $doc['status'], $doc['status'], edok_step_label($step_key, $doc) . ' → ' . $dec_label . ($notes !== '' ? (': ' . $notes) : '') . ($verify_method === 'pin' ? ' (tożsamość zweryfikowana PIN-em)' : ''), $doc);

    if ($status === 'odrzucono') {
        db_exec("UPDATE edok_documents SET status='odrzucony', updated_at=datetime('now') WHERE id=?", [$id]);
        edok_log($id, 'status_change', $step_key, $doc['status'], 'odrzucony', 'Dokument odrzucony na etapie: ' . edok_step_label($step_key, $doc), $doc);
        return ['status' => 'odrzucony', 'rejected' => true];
    }

    $fresh      = edok_get($id);
    $new_status = edok_is_complete($fresh) ? 'zaakceptowany' : 'w_obiegu';
    db_exec("UPDATE edok_documents SET status=?, updated_at=datetime('now') WHERE id=?", [$new_status, $id]);
    if ($new_status === 'zaakceptowany') {
        $koncowy_opis = ($doc['kierunek'] ?? 'wydatek') === 'przychod'
            ? 'Obieg zakończony — przychód zaakceptowany do ujęcia w ewidencji (5/5 etapów).'
            : 'Obieg zakończony — dokument zaakceptowany do zapłaty i księgowania (5/5 etapów).';
        edok_log($id, 'status_change', '', $doc['status'], 'zaakceptowany', $koncowy_opis, $doc);
        // Dokument końcowy (źródło + karta akceptacji) — best-effort, błąd generowania
        // PDF nie może cofnąć już zapisanej akceptacji.
        try {
            edok_generate_final_pdf($id);
        } catch (\Throwable $e) {
            edok_log($id, 'generate_pdf_error', '', 'zaakceptowany', 'zaakceptowany', 'Nie udało się wygenerować dokumentu końcowego: ' . $e->getMessage());
        }
        // Integracja Comarch Betterfly — po finalnej akceptacji zatwierdź powiązaną
        // fakturę sprzedaży (lub oznacz zakup do zapłaty). Best-effort: błąd
        // integracji nie może cofnąć zapisanej akceptacji obiegu.
        try {
            if (is_file(__DIR__ . '/betterfly_invoices.php')) {
                require_once __DIR__ . '/betterfly_invoices.php';
                if (function_exists('betterfly_on_edok_approved')) {
                    betterfly_on_edok_approved($id);
                }
            }
        } catch (\Throwable $e) {
            edok_log($id, 'betterfly_error', '', 'zaakceptowany', 'zaakceptowany', 'Integracja Betterfly: ' . $e->getMessage());
        }
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

// ── Karta akceptacji — HTML współdzielony między edok/print.php (przeglądarka) ─
// i edok_generate_final_pdf() (mPDF). Jedno źródło prawdy dla wyglądu karty.

function edok_print_decision_label(?array $step): string {
    if (!$step || !in_array($step['status'], ['ok', 'uwagi', 'odrzucono'], true)) return 'OCZEKUJE';
    return match($step['status']) { 'ok' => 'TAK', 'uwagi' => 'Z UWAGAMI', 'odrzucono' => 'ODRZUCONO', default => $step['status'] };
}

function edok_print_who(?array $step): string {
    if (!$step || !$step['decided_at']) return '—';
    $verify = ($step['verify_method'] ?? '') === 'pin' && ($step['verify_result'] ?? '') === 'ok'
        ? '<br><span style="font-size:8.5px">Tożsamość zweryfikowana: PIN</span>' : '';
    return h($step['user_name']) . ($step['user_role'] ? ' (' . h($step['user_role']) . ')' : '')
        . '<br>' . date_pl($step['decided_at']) . ' ' . date('H:i', strtotime($step['decided_at'])) . $verify;
}

/** Styl karty — bez @page/@media print (nieistotne dla mPDF, dodawane osobno w print.php dla przeglądarki). */
function edok_print_css(): string {
    return '
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #000; margin: 0; padding: 14px; background: #fff; font-size: 11px; line-height: 1.3; }
  .sheet { max-width: 700px; margin: 0 auto; }
  h1 { font-size: 13px; margin: 0 0 2px; font-weight: 700; }
  .sub { font-size: 10px; margin-bottom: 8px; }
  h2 { font-size: 10px; text-transform: uppercase; letter-spacing: .3px; margin: 8px 0 2px; border-bottom: 1px solid #000; padding-bottom: 1px; }
  table { width: 100%; border-collapse: collapse; }
  td, th { padding: 2px 4px; vertical-align: top; }
  .head-table td { border: none; padding: 1px 4px; }
  .head-table td.l { width: 15%; }
  .kwoty td { border-top: 1px solid #000; border-bottom: 1px solid #000; font-weight: 700; }
  .kwoty td.lbl { font-weight: 400; width: 12%; }
  .desc { border: 1px solid #000; padding: 3px 5px; margin: 2px 0 4px; min-height: 12px; }
  .steps { border-collapse: collapse; margin-top: 2px; }
  .steps th, .steps td { border: 1px solid #000; font-size: 10px; }
  .steps th { text-transform: uppercase; font-size: 8.5px; font-weight: 700; text-align: left; }
  .steps td.dec { text-align: center; font-weight: 700; white-space: nowrap; }
  .stamp { margin-top: 8px; padding-top: 4px; border-top: 1px solid #000; font-size: 8.5px; line-height: 1.35; }
';
}

/** Fragment HTML karty akceptacji (bez <html>/<head>/<body>) — dla przeglądarki i dla mPDF. */
function edok_print_html(array $doc): string {
    $org = defined('ORG_NAME') ? ORG_NAME : '';
    $jest_przychod = ($doc['kierunek'] ?? 'wydatek') === 'przychod';
    $html = '<div class="sheet">';
    $html .= '<h1>' . h($org ?: 'EODoK') . ' — Karta akceptacji dokumentu' . ($jest_przychod ? ' przychodowego' : '') . '</h1>';
    $html .= '<div class="sub">Dokument <strong>' . h($doc['number']) . '</strong> · '
        . h(EDOK_TYPES[$doc['typ_dokumentu']] ?? $doc['typ_dokumentu']) . ' · nr ' . h($doc['nr_faktury'])
        . ' · status: ' . h(EDOK_STATUSES[$doc['status']]['label'] ?? $doc['status']) . '</div>';

    $html .= '<table class="head-table"><tr><td class="l">' . ($jest_przychod ? 'Kontrahent / darczyńca' : 'Kontrahent') . '</td><td>' . h($doc['kontrahent_nazwa'])
        . '</td><td class="l">NIP</td><td>' . h($doc['kontrahent_nip'] ?: '—') . '</td></tr></table>';

    $html .= '<table class="kwoty"><tr>'
        . '<td class="lbl">Netto</td><td>' . h($doc['kwota_netto'] ?: '—') . '</td>'
        . '<td class="lbl">VAT</td><td>' . h($doc['kwota_vat'] ?: '—') . '</td>'
        . '<td class="lbl">Brutto</td><td>' . h($doc['kwota_brutto'] ?: '—') . ' ' . h($doc['waluta']) . '</td>'
        . '</tr></table>';

    $html .= '<h2>' . ($jest_przychod ? 'Opis przychodu' : 'Opis wydatku') . '</h2><div class="desc">' . (trim($doc['description']) !== '' ? nl2br(h($doc['description'])) : '—') . '</div>';

    if ($jest_przychod) {
        $html .= '<h2>Źródło przychodu</h2><table class="head-table"><tr>'
            . '<td class="l">Źródło</td><td>' . h($doc['zrodlo_przychodu'] ?: '—') . '</td>'
            . '<td class="l">Data wpływu</td><td>' . h($doc['data_wplywu'] ? date_pl($doc['data_wplywu']) : '—') . '</td>'
            . '</tr></table>';
    }

    $html .= '<h2>' . ($jest_przychod ? 'Klasyfikacja przychodu' : 'Dekretacja i alokacja kosztów') . '</h2><table class="head-table"><tr>'
        . '<td class="l">Rodzaj działalności</td><td>' . h(EDOK_RODZAJ_DZIALALNOSCI[$doc['rodzaj_dzialalnosci']] ?? '—') . '</td>'
        . '<td class="l">Projekt / MPK</td><td>' . h($doc['projekt'] ?: $doc['mpk'] ?: '—') . '</td>'
        . '</tr></table>';

    $html .= '<h2>Etapy akceptacji</h2><table class="steps"><thead><tr>'
        . '<th style="width:32%">Etap</th><th style="width:14%">Rodzaj akceptacji</th><th style="width:22%">Kto / kiedy</th><th>Opis / uwagi</th>'
        . '</tr></thead><tbody>';
    foreach (array_keys(EDOK_STEPS) as $sk) {
        $s = $doc['steps'][$sk] ?? null;
        $html .= '<tr><td>' . h(edok_step_label($sk, $doc)) . '</td>'
            . '<td class="dec">' . h(edok_print_decision_label($s)) . '</td>'
            . '<td>' . edok_print_who($s) . '</td>'
            . '<td>' . ($s && trim((string)$s['notes']) !== '' ? nl2br(h($s['notes'])) : '—') . '</td></tr>';
    }
    $html .= '</tbody></table>';

    // current_user()/sesja web mogą nie istnieć poza kontekstem przeglądarki (np. cron
    // archiwizacji miesięcznej nie ładuje includes/auth.php celowo — patrz
    // cron/edok_monthly_archive.php) — wtedy karta jest oznaczona jako wygenerowana automatycznie.
    $stamp_user = function_exists('current_user') ? current_user() : null;
    $html .= '<div class="stamp">Karta wygenerowana elektronicznie z systemu EODoK dnia ' . date('d.m.Y H:i')
        . ' przez ' . h($stamp_user['name'] ?? 'system (archiwizacja automatyczna)') . '. Identyfikatory osób decydujących, stemple czasowe '
        . 'i historia decyzji zastępują w pełni tradycyjne pieczątki dekretacyjne.</div>';

    $html .= '</div>';
    return $html;
}

// ── Dokument końcowy (źródłowy + karta obiegu) — FPDI (import) + mPDF (karta) ──

/**
 * Składa jeden PDF: oryginalny dokument źródłowy (PDF-y strona po stronie,
 * albo obraz JPG/PNG na całej stronie; XML/DOCX pomijane — nie da się ich
 * "zaimportować" jako strony) + doklejona karta akceptacji (edok_print_html(),
 * wyrenderowana przez mPDF, doklejona przez FPDI jak zwykły import PDF).
 * Zapisuje do uploads/edok_generated/, rejestruje w edok_generated_pdf.
 * Zwraca względną ścieżkę pliku.
 */
function edok_generate_final_pdf(int $doc_id): string {
    $doc = edok_get($doc_id);
    if (!$doc) throw new RuntimeException('Dokument nie istnieje.');

    require_once dirname(__DIR__) . '/vendor/autoload.php';

    // 1) Karta akceptacji jako osobny PDF (mPDF, z gotowego HTML-a).
    $tmp_dir = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4',
        'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 8, 'margin_bottom' => 8,
        'default_font' => 'dejavusans', 'tempDir' => $tmp_dir,
    ]);
    $mpdf->SetTitle('Karta akceptacji ' . $doc['number']);
    $mpdf->WriteHTML('<style>' . edok_print_css() . '</style>' . edok_print_html($doc));
    $card_path = $tmp_dir . '/karta_' . $doc_id . '_' . bin2hex(random_bytes(4)) . '.pdf';
    $mpdf->Output($card_path, \Mpdf\Output\Destination::FILE);

    // 2) Złożenie finalnego PDF: źródło (jeśli PDF/obraz) + karta (import stron przez FPDI).
    require_once __DIR__ . '/fpdf/fpdf.php';
    require_once __DIR__ . '/fpdi/autoload_fpdi.php';

    $pdf = new \setasign\Fpdi\Fpdi();
    $pdf->SetAutoPageBreak(true, 10);

    $orig_path = $doc['file_path'] ? UPLOAD_DIR . ltrim($doc['file_path'], '/') : '';
    $ext = $orig_path ? strtolower(pathinfo($orig_path, PATHINFO_EXTENSION)) : '';

    if ($orig_path && is_file($orig_path)) {
        if ($ext === 'pdf') {
            try {
                $count = $pdf->setSourceFile($orig_path);
                for ($i = 1; $i <= $count; $i++) {
                    $tpl  = $pdf->importPage($i);
                    $size = $pdf->getTemplateSize($tpl);
                    $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                    $pdf->useTemplate($tpl);
                }
            } catch (\Throwable $e) {}
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $pdf->AddPage('P', 'A4');
            try {
                $pdf->Image($orig_path, 10, 10, 190);
            } catch (\Throwable $e) {}
        }
        // XML (KSeF) / DOCX — nie da się zaimportować jako strony PDF, pomijane.
    }

    $card_count = $pdf->setSourceFile($card_path);
    for ($i = 1; $i <= $card_count; $i++) {
        $tpl  = $pdf->importPage($i);
        $size = $pdf->getTemplateSize($tpl);
        $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl);
    }
    @unlink($card_path);

    $out_dir = UPLOAD_DIR . 'edok_generated/';
    if (!is_dir($out_dir)) mkdir($out_dir, 0755, true);
    $filename = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', 'final_' . $doc['number'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf');
    $full_path = $out_dir . $filename;
    $pdf->Output($full_path, 'F');

    $rel   = 'edok_generated/' . $filename;
    $user  = current_user();
    db_insert('edok_generated_pdf', [
        'doc_id'       => $doc_id,
        'file_path'    => $rel,
        'file_sha256'  => hash_file('sha256', $full_path),
        'file_size'    => filesize($full_path),
        'generated_by' => $user['id'] ?? null,
        'gen_name'     => $user['name'] ?? '',
        'created_at'   => date('Y-m-d H:i:s'),
    ]);

    edok_log($doc_id, 'generate_pdf', '', $doc['status'], $doc['status'], 'Wygenerowano dokument końcowy (źródło + karta akceptacji).');

    return $rel;
}

function edok_latest_generated_pdf(int $doc_id): ?array {
    return db_one("SELECT * FROM edok_generated_pdf WHERE doc_id = ? ORDER BY id DESC LIMIT 1", [$doc_id]);
}

// ── Archiwizacja miesięczna (Uchwała 5/2026 §7) ────────────────────────────────
// Wydruk kart akceptacji wszystkich dokumentów dodanych w danym miesiącu (wg
// created_at — ta sama definicja "miesiąca" co dotychczasowy ręczny przycisk
// w edok/monthly_pdf.php, teraz współdzielona z automatyczną archiwizacją) +
// zestawienie powiązań dokument→karta akceptacji→akceptant.

/** HTML wszystkich kart akceptacji z danego miesiąca (jedna karta = jedna strona, <pagebreak /> między nimi). Współdzielone przez edok/monthly_pdf.php (ręczny przycisk) i edok_build_monthly_pdf_file() (cron). */
function edok_monthly_cards_html(int $year, int $month): array {
    $rows = db_all(
        "SELECT id FROM edok_documents
         WHERE CAST(SUBSTR(created_at, 6, 2) AS INTEGER) = ? AND CAST(SUBSTR(created_at, 1, 4) AS INTEGER) = ?
         ORDER BY id ASC",
        [$month, $year]
    );
    $html = '<style>' . edok_print_css() . '</style>';
    $n = 0;
    foreach ($rows as $r) {
        $doc = edok_get((int)$r['id']);
        if (!$doc) continue;
        if ($n > 0) $html .= '<pagebreak />';
        $html .= edok_print_html($doc);
        $n++;
    }
    return ['html' => $html, 'count' => $n];
}

const EDOK_MONTHS_PL = ['', 'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec', 'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'];

/** Buduje i zapisuje na dysk PDF kart akceptacji za dany miesiąc. Zwraca ścieżkę względną albo null, gdy brak dokumentów. */
function edok_build_monthly_pdf_file(int $year, int $month): ?string {
    $cards = edok_monthly_cards_html($year, $month);
    if ($cards['count'] === 0) return null;

    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $tmp_dir = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4',
        'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 8, 'margin_bottom' => 8,
        'default_font' => 'dejavusans', 'tempDir' => $tmp_dir,
    ]);
    $mpdf->SetTitle('EODoK — dokumenty ' . (EDOK_MONTHS_PL[$month] ?? $month) . ' ' . $year);
    $mpdf->WriteHTML($cards['html']);

    $out_dir = UPLOAD_DIR . 'edok_generated/monthly/';
    if (!is_dir($out_dir)) mkdir($out_dir, 0755, true);
    $filename = sprintf('EODoK_%04d_%02d.pdf', $year, $month);
    $mpdf->Output($out_dir . $filename, \Mpdf\Output\Destination::FILE);
    return 'edok_generated/monthly/' . $filename;
}

/**
 * Zestawienie powiązań dokument→karta akceptacji→akceptant za dany miesiąc
 * (Uchwała 5/2026 §7 ostatni akapit) — jeden wiersz na (dokument, etap).
 * Kolumny: identyfikator dokumentu źródłowego, identyfikator karty akceptacji,
 * dane akceptanta, etap, status, data i czas akceptacji, numer faktury/numer
 * akceptacji wykorzystany w tytule przelewu. Zapisuje CSV na dysk, zwraca
 * ścieżkę względną albo null, gdy brak dokumentów w tym miesiącu.
 */
function edok_build_monthly_zestawienie_csv(int $year, int $month): ?string {
    $rows = db_all(
        "SELECT d.id AS doc_id, d.number, d.nr_faktury, d.tytul_przelewu,
                s.step_key, s.status AS step_status, s.user_name, s.user_role, s.decided_at, s.verify_method,
                g.id AS karta_id
         FROM edok_documents d
         JOIN edok_steps s ON s.doc_id = d.id
         LEFT JOIN edok_generated_pdf g ON g.doc_id = d.id
         WHERE CAST(SUBSTR(d.created_at, 6, 2) AS INTEGER) = ? AND CAST(SUBSTR(d.created_at, 1, 4) AS INTEGER) = ?
         ORDER BY d.id, CASE s.step_key WHEN 'meryt' THEN 1 WHEN 'formal' THEN 2 WHEN 'rachunkowa' THEN 3 WHEN 'dekretacja' THEN 4 WHEN 'zatwierdza' THEN 5 END",
        [$month, $year]
    );
    if (!$rows) return null;

    $out_dir = UPLOAD_DIR . 'edok_generated/monthly/';
    if (!is_dir($out_dir)) mkdir($out_dir, 0755, true);
    $filename = sprintf('zestawienie_%04d_%02d.csv', $year, $month);
    $full_path = $out_dir . $filename;
    $f = fopen($full_path, 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Dokument', 'Karta akceptacji (id)', 'Etap', 'Status etapu', 'Akceptant', 'Metoda weryfikacji', 'Data i czas', 'Nr faktury / nr akceptacji'], ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($f, [
            $r['number'],
            $r['karta_id'] ?: '—',
            EDOK_STEPS[$r['step_key']] ?? $r['step_key'],
            $r['step_status'],
            $r['user_name'] . ($r['user_role'] ? ' (' . $r['user_role'] . ')' : ''),
            $r['verify_method'] ?: '—',
            $r['decided_at'] ?: '—',
            $r['nr_faktury'] ?: $r['number'],
        ], ';', '"', '\\');
    }
    fclose($f);
    return 'edok_generated/monthly/' . $filename;
}

/**
 * Orkiestruje archiwizację miesięczną: buduje PDF + zestawienie, zapisuje/
 * aktualizuje wiersz w edok_monthly_archive (jeden na miesiąc — kolejne
 * wywołanie nadpisuje pliki i wiersz, np. przy ręcznym powtórzeniu po
 * uzupełnieniu dokumentów). $user_id = null → wywołanie z crona.
 * Zwraca wiersz edok_monthly_archive albo null, gdy brak dokumentów.
 */
function edok_run_monthly_archive(int $year, int $month, ?int $user_id = null): ?array {
    $pdf_path = edok_build_monthly_pdf_file($year, $month);
    if ($pdf_path === null) return null;
    $csv_path = edok_build_monthly_zestawienie_csv($year, $month);

    $doc_count = (int) (db_one(
        "SELECT COUNT(*) c FROM edok_documents WHERE CAST(SUBSTR(created_at,6,2) AS INTEGER)=? AND CAST(SUBSTR(created_at,1,4) AS INTEGER)=?",
        [$month, $year]
    )['c'] ?? 0);

    $user = $user_id ? db_one("SELECT name FROM users WHERE id = ?", [$user_id]) : null;
    $data = [
        'year'         => $year,
        'month'        => $month,
        'pdf_path'     => $pdf_path,
        'csv_path'     => $csv_path ?: '',
        'doc_count'    => $doc_count,
        'generated_by' => $user_id,
        'gen_name'     => $user_id ? ($user['name'] ?? ('uid:' . $user_id)) : 'cron/edok_monthly_archive.php',
        'generated_at' => date('Y-m-d H:i:s'),
    ];

    $existing = db_one("SELECT id FROM edok_monthly_archive WHERE year = ? AND month = ?", [$year, $month]);
    if ($existing) {
        db_exec(
            "UPDATE edok_monthly_archive SET pdf_path=?, csv_path=?, doc_count=?, generated_by=?, gen_name=?, generated_at=? WHERE id=?",
            [$data['pdf_path'], $data['csv_path'], $data['doc_count'], $data['generated_by'], $data['gen_name'], $data['generated_at'], $existing['id']]
        );
        $id = (int)$existing['id'];
    } else {
        $id = db_insert('edok_monthly_archive', $data);
    }
    return db_one("SELECT * FROM edok_monthly_archive WHERE id = ?", [$id]);
}

// ── Eksport przelewów zbiorczych — iPKO biznes (ELIXIR-O) ─────────────────────
// Wg oficjalnej specyfikacji PKO BP „Struktura pliku wejściowego – iPKO biznes
// – ELIXIR-O": plik CSV BEZ nagłówka/stopki, jeden wiersz = jedno zlecenie,
// pola rozdzielone przecinkiem, wiersze zakończone <CR><LF>, max 5000 rekordów
// w pliku, strona kodowa CP852 albo ISO-8859-2 — bank WPROST ODRADZA UTF-8 i
// Windows-1250 (błędy Ą/Ć/Ę/Ł/Ń/Ó/Ś/Ź/Ż). Znak "," rozdziela pola, znak "|"
// rozdziela WIERSZE WEWNĄTRZ pola złożonego (nazwa/adres/tytuł — patrz
// edok_ipko_name_address_field()) — żaden z tych znaków nie może występować
// w treści samego pola (edok_ipko_sanitize() je usuwa). To dokładnie dlatego
// edok_generate_tytul_przelewu() (Faza B) nie używa już "|" jako separatora
// w tytule przelewu — ten sam znak jest zastrzeżony przez format importu banku.
//
// UWAGA: to generator PLIKU IMPORTU, nie zlecenie płatności — wygenerowany
// plik trzeba samodzielnie zaimportować i zweryfikować w iPKO biznes przed
// skierowaniem do realizacji. Adres kontrahenta nie jest przechowywany
// w EODoK, więc pole nazwy/adresu kontrahenta zawiera samą nazwę. Przykłady
// w oficjalnym dokumencie PKO nie pokazują pól w cudzysłowach mimo że opis
// tekstowy specyfikacji tego wymaga — ten eksporter podąża za przykładami
// (bez cudzysłowów), bo to one odzwierciedlają format faktycznie akceptowany
// przez system banku.

/** Dzieli tekst na $max_lines wierszy po maksymalnie $len znaków (pola nazwa/adres/tytuł w Elixir-O). */
function edok_ipko_wrap_lines(string $text, int $len, int $max_lines): array {
    $text = preg_replace('/\s+/', ' ', trim($text));
    $lines = [];
    while ($text !== '' && count($lines) < $max_lines) {
        $lines[] = mb_substr($text, 0, $len);
        $text = mb_substr($text, $len);
    }
    while (count($lines) < $max_lines) $lines[] = '';
    return $lines;
}

/** Usuwa znaki zastrzeżone dla struktury pliku Elixir-O (",", "|", cudzysłów) z treści pola. */
function edok_ipko_sanitize(string $s): string {
    return str_replace([',', '|', '"'], [' ', '/', "'"], $s);
}

/**
 * Pole "nazwa i adres" (4*35 znaków, wiersze 1-2 = nazwa, 3-4 = adres, złączone "|").
 * Tabela pól w oficjalnym PDF PKO opisowo podaje separator "?", ale rzeczywiste
 * przykłady pliku w tym samym dokumencie (sekcja 3.2, zweryfikowane wizualnie
 * na renderze strony — pdftotext w tabeli myli "|" z "?") konsekwentnie używają
 * "|" — zgodnie też z ogólną zasadą z sekcji 2 dokumentu ("do oddzielenia
 * wykorzystywany jest znak pionowej kreski"). Ten eksporter podąża za
 * przykładami/zasadą ogólną, nie za opisem tabeli pól.
 */
function edok_ipko_name_address_field(string $nazwa, string $adres): string {
    return implode('|', array_merge(
        edok_ipko_wrap_lines(edok_ipko_sanitize($nazwa), 35, 2),
        edok_ipko_wrap_lines(edok_ipko_sanitize($adres), 35, 2)
    ));
}

/**
 * Pary NIP + rachunek z dokumentów do eksportu, których nikt jeszcze nie
 * potwierdził — pierwszy przelew do kontrahenta albo NOWY rachunek znanego
 * kontrahenta (typowy scenariusz podmiany rachunku na fakturze).
 * Zwraca [klucz => [nip, nrb, nazwa, known_nip, docs[]]]; dokumenty bez
 * 26-cyfrowego rachunku pomija (i tak nie trafią do pliku).
 */
function edok_ipko_unverified_pairs(array $docs): array {
    $out = [];
    foreach ($docs as $doc) {
        if (($doc['kierunek'] ?? 'wydatek') !== 'wydatek') continue;
        $nrb = preg_replace('/\D/', '', (string)($doc['rachunek_bankowy'] ?? ''));
        if (strlen($nrb) !== 26) continue;
        $nip = preg_replace('/\D/', '', (string)($doc['kontrahent_nip'] ?? ''));
        $key = $nip . ':' . $nrb;
        if (!isset($out[$key])) {
            if (db_one("SELECT id FROM edok_kontrahent_verified WHERE nip = ? AND nrb = ?", [$nip, $nrb])) continue;
            $out[$key] = [
                'nip'       => $nip,
                'nrb'       => $nrb,
                'nazwa'     => (string)($doc['kontrahent_nazwa'] ?? ''),
                'known_nip' => $nip !== '' && (bool) db_one("SELECT id FROM edok_kontrahent_verified WHERE nip = ?", [$nip]),
                'docs'      => [],
            ];
        }
        $out[$key]['docs'][] = (string)($doc['number'] ?? ('#' . $doc['id']));
    }
    return $out;
}

/** Zapisuje potwierdzenie poprawności pary NIP + rachunek. */
function edok_kontrahent_verify(string $nip, string $nrb, string $nazwa): void {
    $user = current_user();
    db_exec(
        "INSERT OR IGNORE INTO edok_kontrahent_verified (nip, nrb, kontrahent_nazwa, verified_by, verifier_name, verified_at) VALUES (?, ?, ?, ?, ?, ?)",
        [preg_replace('/\D/', '', $nip), preg_replace('/\D/', '', $nrb), $nazwa, (int)($user['id'] ?? 0) ?: null, $user['name'] ?? '', date('Y-m-d H:i:s')]
    );
}

/** Formatuje NRB w grupach 2+4×6 (czytelne do porównania z fakturą). */
function edok_nrb_format(string $nrb): string {
    $d = preg_replace('/\D/', '', $nrb);
    return strlen($d) === 26 ? substr($d, 0, 2) . ' ' . trim(chunk_split(substr($d, 2), 4, ' ')) : $nrb;
}

/**
 * Generuje plik przelewów zbiorczych ELIXIR-O dla podanych dokumentów EODoK
 * (tylko kierunek=wydatek — przychody nie generują wychodzącej płatności;
 * dokumenty bez prawidłowego 26-cyfrowego rachunku kontrahenta są pomijane).
 * $rachunek_zlecen_nrb — NRB rachunku organizacji, z którego mają pójść
 * przelewy (musi występować na liście edok_rachunki_list(), żeby dociągnąć
 * nazwę/adres nadawcy zapisane przy tym rachunku).
 * Zwraca gotowy content pliku w ISO-8859-2 (do zapisu/pobrania jako .txt).
 */
function edok_ipko_biznes_export(array $docs, string $rachunek_zlecen_nrb): string {
    $nrb_z = preg_replace('/\D/', '', $rachunek_zlecen_nrb);
    if (strlen($nrb_z) !== 26) throw new RuntimeException('Rachunek zleceniodawcy musi mieć 26 cyfr (NRB).');
    $bank_z = substr($nrb_z, 2, 8);

    $konto = null;
    foreach (edok_rachunki_list() as $r) if (preg_replace('/\D/', '', (string) $r['nrb']) === $nrb_z) { $konto = $r; break; }
    $nazwa_zlec = (($konto['nazwa'] ?? '') !== '') ? $konto['nazwa'] : (defined('ORG_NAME') ? ORG_NAME : '');
    $adres_zlec = (($konto['adres'] ?? '') !== '') ? $konto['adres'] : (string) org_setting('org_adres');

    $rows = [];
    foreach ($docs as $doc) {
        if (($doc['kierunek'] ?? 'wydatek') !== 'wydatek') continue;
        $nrb_k = preg_replace('/\D/', '', (string)($doc['rachunek_bankowy'] ?? ''));
        if (strlen($nrb_k) !== 26) continue; // brak/nieprawidłowy rachunek kontrahenta — nie da się ułożyć wiersza

        // Data realizacji i tytuł przelewu liczone na bieżąco w momencie eksportu
        // (nie z zapisanych w dokumencie termin_platnosci/tytul_przelewu, które mogą
        // być nieaktualne, np. gdy dokument czekał w Preliminarzu kilka dni) — tak
        // samo jak przy generowaniu/odświeżaniu tytułu przy nadawaniu do zapłaty.
        $data_fmt     = str_replace('-', '', date('Y-m-d'));
        $kwota_groszy = (int) round(_edok_kwota_float((string)($doc['kwota_brutto'] ?? '0')) * 100);
        $bank_k       = substr($nrb_k, 2, 8);
        $tytul        = implode('|', edok_ipko_wrap_lines(edok_ipko_sanitize(edok_generate_tytul_przelewu($doc)), 35, 4));
        // Referencja własna zleceniodawcy: max 16 znaków, bez polskich liter/znaków specjalnych poza / - ? : ( ) . , ' + spacja.
        $referencja   = mb_substr(preg_replace('/[^A-Za-z0-9\/\-?:().,\'+ ]/', '', (string)($doc['number'] ?? '')), 0, 16);

        $fields = [
            '110', $data_fmt, (string)$kwota_groszy, $bank_z, '0',
            $nrb_z, $nrb_k,
            edok_ipko_name_address_field($nazwa_zlec, $adres_zlec),
            edok_ipko_name_address_field((string)($doc['kontrahent_nazwa'] ?? ''), ''),
            '0', $bank_k,
            $tytul,
            '', '',
            '51',
        ];
        // Pole 16 (referencja) pojawia się w przykładach TYLKO gdy ma wartość —
        // bez referencji wiersz kończy się od razu po polu 15 ("51"), bez
        // dodatkowego pustego przecinka na końcu.
        if ($referencja !== '') $fields[] = $referencja;
        $rows[] = implode(',', $fields);
    }

    $content = $rows ? implode("\r\n", $rows) . "\r\n" : '';
    $encoded = @iconv('UTF-8', 'ISO-8859-2//TRANSLIT', $content);
    return $encoded !== false ? $encoded : $content;
}

/**
 * Eksport przelewów zbiorczych — Bank Millennium (Millenet), format ELIXIR-O.
 * Wg oficjalnej specyfikacji „Opis formatu pliku płatności krajowych do
 * importu w systemie Millenet" (wer. 2020-08-14): plik CSV bez nagłówka/
 * stopki, CRLF, pola rozdzielone przecinkiem, ale — inaczej niż w pliku iPKO
 * biznes (edok_ipko_biznes_export()) — WYBRANE pola ("Pole w „ "" w
 * specyfikacji: rachunki, nazwa/adres, tytuł, kod klasyfikacji, adnotacje)
 * muszą być w cudzysłowach; potwierdzone wprost oficjalnym przykładem w
 * dokumencie. To realna różnica między bankami we wspólnym standardzie
 * ELIXIR-O (Rada Bankowości Elektronicznej) — obie funkcje są osobno
 * zweryfikowane wobec swoich oficjalnych przykładów, nie kopiują się
 * bezkrytycznie. Podpola łączone "|", jak w iPKO biznes. Millennium wprost
 * akceptuje też UTF-8 (obok CP852/CP1250/ISO-8859-2) — bez wymuszonej
 * konwersji kodowania, w przeciwieństwie do PKO.
 */
function edok_millenet_export(array $docs, string $rachunek_zlecen_nrb): string {
    $nrb_z = preg_replace('/\D/', '', $rachunek_zlecen_nrb);
    if (strlen($nrb_z) !== 26) throw new RuntimeException('Rachunek zleceniodawcy musi mieć 26 cyfr (NRB).');
    $bank_z = substr($nrb_z, 2, 8);

    $konto = null;
    foreach (edok_rachunki_list() as $r) if (preg_replace('/\D/', '', (string) $r['nrb']) === $nrb_z) { $konto = $r; break; }
    $nazwa_zlec = (($konto['nazwa'] ?? '') !== '') ? $konto['nazwa'] : (defined('ORG_NAME') ? ORG_NAME : '');
    $adres_zlec = (($konto['adres'] ?? '') !== '') ? $konto['adres'] : (string) org_setting('org_adres');
    $q = fn(string $s): string => '"' . $s . '"';

    $rows = [];
    foreach ($docs as $doc) {
        if (($doc['kierunek'] ?? 'wydatek') !== 'wydatek') continue;
        $nrb_k = preg_replace('/\D/', '', (string)($doc['rachunek_bankowy'] ?? ''));
        if (strlen($nrb_k) !== 26) continue;

        // Data realizacji i tytuł przelewu liczone na bieżąco w momencie eksportu — patrz komentarz w edok_ipko_biznes_export().
        $data_fmt     = str_replace('-', '', date('Y-m-d'));
        $kwota_groszy = (int) round(_edok_kwota_float((string)($doc['kwota_brutto'] ?? '0')) * 100);
        $bank_k       = substr($nrb_k, 2, 8);
        $tytul        = implode('|', edok_ipko_wrap_lines(edok_ipko_sanitize(edok_generate_tytul_przelewu($doc)), 35, 4));
        // Adnotacje: kod rekoncyliacyjny do 16 znaków, umieszczony między "$$$...$$$" (§3.1 pozycja 16).
        $referencja = mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($doc['number'] ?? '')), 0, 16);
        $adnotacje  = $referencja !== '' ? '$$$' . $referencja . '$$$' : '';

        $fields = [
            '110', $data_fmt, (string)$kwota_groszy, $bank_z, '0',
            $q($nrb_z), $q($nrb_k),
            $q(edok_ipko_name_address_field($nazwa_zlec, $adres_zlec)),
            $q(edok_ipko_name_address_field((string)($doc['kontrahent_nazwa'] ?? ''), '')),
            '0', $bank_k,
            $q($tytul),
            $q(''), $q(''),
            $q('51'),
            $q($adnotacje),
        ];
        $rows[] = implode(',', $fields);
    }

    return $rows ? implode("\r\n", $rows) . "\r\n" : '';
}

/** Formaty pliku przelewów zbiorczych dostępne w Preliminarzu i na karcie dokumentu. */
const EDOK_PRZELEWY_FORMATY = [
    'auto'     => 'Automatycznie (wg banku rachunku nadawcy)',
    'ipko'     => 'iPKO biznes (PKO BP)',
    'millenet' => 'Millenet (Bank Millennium)',
];

/**
 * Format pliku dla rachunku nadawcy przy wyborze „auto": numer rozliczeniowy
 * banku w NRB (cyfry 3–6) — 1020 = PKO BP, 1160 = Bank Millennium. Inne banki
 * dostają iPKO biznes (czysty ELIXIR-O bez cudzysłowów, najszerzej akceptowany).
 */
function edok_przelewy_format_for_nrb(string $nrb, string $format = 'auto'): string {
    if ($format !== 'auto' && isset(EDOK_PRZELEWY_FORMATY[$format])) return $format;
    return substr(preg_replace('/\D/', '', $nrb), 2, 4) === '1160' ? 'millenet' : 'ipko';
}

/**
 * Generuje plik przelewów dla jednego rachunku nadawcy w wybranym formacie.
 * Zwraca ['content' => …, 'prefix' => część nazwy pliku, 'ext' => …] — content
 * pusty, gdy żaden dokument nie nadaje się do eksportu.
 */
function edok_przelewy_export(array $docs, string $rachunek_zlecen_nrb, string $format = 'auto'): array {
    $format = edok_przelewy_format_for_nrb($rachunek_zlecen_nrb, $format);
    if ($format === 'millenet') {
        return ['content' => edok_millenet_export($docs, $rachunek_zlecen_nrb), 'prefix' => 'Millenet', 'ext' => 'csv'];
    }
    return ['content' => edok_ipko_biznes_export($docs, $rachunek_zlecen_nrb), 'prefix' => 'iPKO_biznes', 'ext' => 'txt'];
}

// ── Import wyciągu bankowego — MT940 (iPKO biznes) ─────────────────────────────
// Wg oficjalnej specyfikacji PKO BP „Struktura pliku wyjściowego – Raport MT940":
// pole :61: (jedna operacja) + następujące po nim :86: z podpolami ~20..~63.
// Pole "puste" oznacza bajt ASCII 255 — zamieniany na sentinel PRZED konwersją
// kodowania (żeby nie zależeć od tego, jak 0xFF zmapuje się po iconv). Parser
// wyciąga tylko operacje UZNANIOWE ('C' — wpływy), bo tylko one kwalifikują się
// jako dokumenty przychodowe EODoK (Uchwała 5/2026 §1 pkt 2); operacje obciążeniowe
// ('D' — wypływy) są zwracane informacyjnie, ale nie są celem tego importu — EODoK
// dla wydatków ma własny obieg zaczynający się od faktury/rachunku, nie od wyciągu.

const EDOK_MT940_EMPTY = "\x02EMPTY\x02";

/** Normalizuje kodowanie pliku MT940 do UTF-8, chroniąc marker "pole puste" (ASCII 255) przed konwersją. */
function edok_mt940_normalize(string $raw): string {
    $raw = str_replace("\xFF", EDOK_MT940_EMPTY, $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $conv = @iconv('ISO-8859-2', 'UTF-8//TRANSLIT', $raw);
        if ($conv !== false) $raw = $conv;
    }
    return $raw;
}

/** Saldo (:60F:/:62F:/:64:): znak D/C + data RRMMDD + waluta + kwota (przecinek dziesiętny). */
function edok_mt940_parse_saldo(string $s): array {
    if (!preg_match('/^([CD])(\d{6})([A-Z]{3})([\d,]+)$/', trim($s), $m)) return [];
    return [
        'znak'   => $m[1],
        'data'   => '20' . substr($m[2], 0, 2) . '-' . substr($m[2], 2, 2) . '-' . substr($m[2], 4, 2),
        'waluta' => $m[3],
        'kwota'  => str_replace(',', '.', $m[4]),
    ];
}

/**
 * Pierwsza linia pola :61: — data waluty/operacji, znak C/D, kwota, referencja
 * własna (jeśli nie 'NONREF'), numer operacji (zawsze ostatnie 16 znaków linii —
 * to jedyny niezawodny sposób oddzielenia zmiennej długości pola referencji
 * od stałej długości numeru operacji, bez zgadywania czy referencja jest pełna).
 */
function edok_mt940_parse_61(string $line): ?array {
    if (!preg_match('/^(\d{6})(\d{4})([CD])([\d,]+)N(\d{3})(.*)$/', trim($line), $m)) return null;
    $rest           = $m[6];
    $numer_operacji = mb_substr($rest, -16);
    $referencja_raw = rtrim(mb_substr($rest, 0, -16), '/');
    return [
        'data_waluty'    => '20' . substr($m[1], 0, 2) . '-' . substr($m[1], 2, 2) . '-' . substr($m[1], 4, 2),
        'znak'           => $m[3],
        'kwota'          => str_replace(',', '.', $m[4]),
        'kod_ozsi'       => $m[5],
        'referencja'     => ($referencja_raw === '' || $referencja_raw === 'NONREF') ? null : $referencja_raw,
        'numer_operacji' => $numer_operacji,
    ];
}

/**
 * Parsuje cały plik MT940 (jeden wyciąg, może zawierać wiele operacji :61:/:86:).
 * Zwraca ['account_nrb', 'statement_no', 'opening', 'closing', 'transactions' => [...]]
 * gdzie każda transakcja ma: data_waluty, znak (C/D), kwota, referencja, numer_operacji,
 * tytul (złożony z podpól ~20-~25), kontrahent_bank (~30), kontrahent_konto (~31),
 * kontrahent_nazwa (~32+~33), kontrahent_iban (~38), data_dokumentu (~60), swrk (~63).
 */
function edok_mt940_parse(string $raw): array {
    $content = edok_mt940_normalize($raw);
    $lines   = preg_split('/\r\n|\r|\n/', $content);

    $out = ['account_nrb' => '', 'statement_no' => '', 'opening' => null, 'closing' => null, 'transactions' => []];
    $tx  = null;
    $sub = [];

    $get = function (int $n) use (&$sub): string {
        $v = trim($sub[$n] ?? '');
        return $v === EDOK_MT940_EMPTY ? '' : $v;
    };
    $flush = function () use (&$tx, &$sub, &$out, $get) {
        if ($tx === null) return;
        $tx['tytul']            = trim(implode(' ', array_filter([$get(20), $get(21), $get(22), $get(23), $get(24), $get(25)], fn($s) => $s !== '')));
        $tx['kontrahent_bank']  = $get(30);
        $tx['kontrahent_konto'] = $get(31);
        $tx['kontrahent_nazwa'] = trim(($get(32) . ' ' . $get(33)));
        $tx['kontrahent_iban']  = $get(38);
        $tx['data_dokumentu']   = $get(60);
        $tx['swrk']             = $get(63);
        $out['transactions'][]  = $tx;
        $tx = null; $sub = [];
    };

    foreach ($lines as $line) {
        $line = rtrim($line, "\r\n");
        if ($line === '') continue;
        if (str_starts_with($line, ':25:')) { $out['account_nrb'] = ltrim(substr($line, 4), '/'); continue; }
        if (str_starts_with($line, ':28C:')) { $out['statement_no'] = trim(substr($line, 5)); continue; }
        if (str_starts_with($line, ':60F:')) { $out['opening'] = edok_mt940_parse_saldo(substr($line, 5)); continue; }
        if (str_starts_with($line, ':61:')) { $flush(); $parsed = edok_mt940_parse_61(substr($line, 4)); if ($parsed) $tx = $parsed; continue; }
        if (str_starts_with($line, ':62F:')) { $flush(); $out['closing'] = edok_mt940_parse_saldo(substr($line, 5)); continue; }
        if (str_starts_with($line, ':64:') || str_starts_with($line, ':20:') || $line === '-') continue;
        if (preg_match('/^~(\d{2})(.*)$/', $line, $m)) { $sub[(int)$m[1]] = $m[2]; continue; }
        // inne linie (np. druga linia :61: z kodem O-ZSI, albo pierwsza linia :86:) — nieistotne dla importu
    }
    $flush();

    return $out;
}

/**
 * Tworzy dokument EODoK typu "wyciąg_bankowy" (kierunek=przychod, status=w_obiegu,
 * bez pliku źródłowego — kontrola merytoryczna i tak wymaga jego dołączenia, więc
 * import nie omija tego wymogu) z jednej operacji uznaniowej z edok_mt940_parse().
 * Zwraca id nowego dokumentu.
 */
function edok_mt940_create_doc(array $tx, int $user_id): int {
    $user  = current_user();
    $number = edok_next_number();
    $opis   = $tx['tytul'] !== '' ? $tx['tytul'] : 'Wpływ na rachunek bankowy';
    $doc = [
        'number'              => $number,
        'title'               => $opis,
        'kierunek'            => 'przychod',
        'typ_dokumentu'       => 'wyciag_bankowy',
        'description'         => $opis,
        'kontrahent_nazwa'    => $tx['kontrahent_nazwa'] !== '' ? $tx['kontrahent_nazwa'] : '(nieznany wpłacający)',
        'kontrahent_nip'      => '',
        'nr_faktury'          => $tx['referencja'] ?: $tx['numer_operacji'],
        'zrodlo_przychodu'    => trim($tx['kontrahent_nazwa'] . ($tx['kontrahent_iban'] !== '' ? ' (' . $tx['kontrahent_iban'] . ')' : '')),
        'data_wystawienia'    => $tx['data_waluty'],
        'data_wplywu'         => $tx['data_waluty'],
        'kwota_netto'         => number_format((float)$tx['kwota'], 2, ',', ''),
        'kwota_vat'           => '0,00',
        'kwota_brutto'        => number_format((float)$tx['kwota'], 2, ',', ''),
        'waluta'              => 'PLN',
        'rodzaj_dzialalnosci' => '',
        'status'              => 'w_obiegu',
        'created_by'          => $user_id,
        'creator_name'        => ($user['name'] ?? ('uid:' . $user_id)) . ' (import MT940)',
        'created_at'          => date('Y-m-d H:i:s'),
        'updated_at'          => date('Y-m-d H:i:s'),
    ];
    $doc['tytul_przelewu'] = edok_generate_tytul_przelewu($doc);
    $doc_id = db_insert('edok_documents', $doc);
    edok_log($doc_id, 'submit', '', 'draft', 'w_obiegu', 'Utworzono z importu wyciągu bankowego MT940 (operacja ' . $tx['numer_operacji'] . '). Wymaga dołączenia skanu wyciągu i uzupełnienia dekretacji przed kontrolą merytoryczną.', $doc);
    return $doc_id;
}

// ── Tabela analityczna przychody/koszty ────────────────────────────────────────
// Zestawienie zaakceptowanych dokumentów (przychody i wydatki) w wybranym okresie,
// pogrupowane wg klasyfikacji (rodzaj działalności / projekt), z wynikiem
// (przychody - wydatki). Kwoty w polach edok_documents są tekstem z przecinkiem
// dziesiętnym (format PL) — sumowane w PHP, NIE przez SQL CAST (który dla SQLite
// obcina "123,00" do 123, gubiąc grosze).

function _edok_kwota_float(string $s): float {
    return (float) str_replace(',', '.', str_replace(' ', '', $s));
}

/**
 * @param array $f data_od, data_do (YYYY-MM-DD, filtr po data_wplywu — ustawiane
 *                 dla każdego dokumentu, patrz edok/add.php)
 * @return array{groups: array, totals: array}
 */
function edok_analityczny_query(array $f = []): array {
    $where  = ["status = 'zaakceptowany'"];
    $params = [];
    if (!empty($f['data_od'])) { $where[] = "COALESCE(data_wplywu, data_wystawienia, created_at) >= ?"; $params[] = $f['data_od']; }
    if (!empty($f['data_do'])) { $where[] = "COALESCE(data_wplywu, data_wystawienia, created_at) <= ?"; $params[] = $f['data_do']; }

    $rows = db_all(
        "SELECT kierunek, rodzaj_dzialalnosci, projekt, kwota_netto, kwota_vat, kwota_brutto
         FROM edok_documents WHERE " . implode(' AND ', $where),
        $params
    );

    $groups = [];
    $totals = ['przychod_netto' => 0.0, 'przychod_brutto' => 0.0, 'wydatek_netto' => 0.0, 'wydatek_brutto' => 0.0];

    foreach ($rows as $r) {
        $kierunek = ($r['kierunek'] ?: 'wydatek') === 'przychod' ? 'przychod' : 'wydatek';
        $rodzaj   = $r['rodzaj_dzialalnosci'] ?: '';
        $projekt  = $rodzaj === 'projekt' ? trim((string)$r['projekt']) : '';
        $key      = $rodzaj . '|' . $projekt;

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'label'           => $rodzaj !== '' ? edok_transfer_label_klasyfikacja($rodzaj, $projekt) : '(bez klasyfikacji)',
                'przychod_netto'  => 0.0, 'przychod_brutto' => 0.0,
                'wydatek_netto'   => 0.0, 'wydatek_brutto'  => 0.0,
            ];
        }

        $netto  = _edok_kwota_float((string)$r['kwota_netto']);
        $brutto = _edok_kwota_float((string)$r['kwota_brutto']);
        $groups[$key][$kierunek . '_netto']  += $netto;
        $groups[$key][$kierunek . '_brutto'] += $brutto;
        $totals[$kierunek . '_netto']  += $netto;
        $totals[$kierunek . '_brutto'] += $brutto;
    }

    ksort($groups);
    $totals['wynik_brutto'] = $totals['przychod_brutto'] - $totals['wydatek_brutto'];
    $totals['wynik_netto']  = $totals['przychod_netto']  - $totals['wydatek_netto'];
    foreach ($groups as &$g) {
        $g['wynik_brutto'] = $g['przychod_brutto'] - $g['wydatek_brutto'];
        $g['wynik_netto']  = $g['przychod_netto']  - $g['wydatek_netto'];
    }
    unset($g);

    return ['groups' => array_values($groups), 'totals' => $totals];
}

// ── Preliminarz Płatności (przeniesiony z KDOK, ujednolicony z KDOK) ──────────
// EODoK jest docelowym miejscem dla NOWYCH dokumentów, ale istniejące
// dokumenty zaakceptowane jeszcze w KDOK (includes/ksiegowosc.php) też czekają
// na zapłatę — dlatego zapytanie łączy obie tabele w jedną listę zamiast
// zostawiać dwa osobne, rozjeżdżające się widoki „co trzeba zapłacić".

const EDOK_STATUS_PLATNOSCI = [
    'nowy'          => ['label' => 'Nowy',             'class' => 'secondary'],
    'do_realizacji' => ['label' => 'Do realizacji',    'class' => 'warning'],
    'zlecony'       => ['label' => 'Zlecony do banku', 'class' => 'info'],
    'oplacony'      => ['label' => 'Opłacony',         'class' => 'success'],
    'wstrzymany'    => ['label' => 'Wstrzymany',       'class' => 'danger'],
    'anulowany'     => ['label' => 'Anulowany',        'class' => 'dark'],
];

function edok_status_platnosci_badge(string $status): string {
    $s = EDOK_STATUS_PLATNOSCI[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . h($s['label']) . '</span>';
}

/** Priorytet P1 (krytyczny) – P5 (oczekujący) na podstawie terminu i MPP. */
function edok_platnosc_priorytet(array $row): int {
    $termin = $row['termin_platnosci'] ?? '';
    if (!$termin) return 5;
    $diff = (int) round((strtotime(substr($termin, 0, 10)) - strtotime(date('Y-m-d'))) / 86400);
    $mpp  = !empty($row['wymaga_mpp']);
    if ($diff < 0)          return 1;
    if ($mpp && $diff <= 7) return 1;
    if ($diff <= 3)         return 2;
    if ($diff <= 7)         return 3;
    if ($diff <= 14)        return 4;
    return 5;
}

function edok_platnosc_priorytet_label(int $p): string {
    return ['', 'Krytyczny', 'Pilny', 'Wkrótce', 'Normalny', 'Oczekujący'][$p] ?? '?';
}

/**
 * Dokumenty kwalifikowane do Preliminarza — z EODoK ORAZ (jeśli dostępny)
 * z archiwalnego KDOK, znormalizowane do wspólnego kształtu wiersza:
 * source, id, number, title, kontrahent, nip, rachunek_bankowy, kwota_netto,
 * kwota_vat, kwota_brutto, waluta, termin_platnosci, klasyfikacja,
 * status_platnosci, wymaga_mpp, priorytet, view_url.
 */
function edok_preliminarz_query(array $f = []): array {
    // Dokumenty przychodowe nie generują wychodzącej płatności — nie mają czego robić
    // w Preliminarzu (kolejce "co trzeba zapłacić").
    $where  = ["status = 'zaakceptowany'", "COALESCE(wyklucz_z_preliminarza,0) = 0", "COALESCE(kierunek,'wydatek') = 'wydatek'"];
    $params = [];
    if (!empty($f['status_platnosci'])) { $where[] = "COALESCE(status_platnosci,'nowy') = ?"; $params[] = $f['status_platnosci']; }
    else                                { $where[] = "COALESCE(status_platnosci,'nowy') NOT IN ('anulowany')"; }
    if (!empty($f['termin_od']))        { $where[] = "termin_platnosci >= ?"; $params[] = $f['termin_od']; }
    if (!empty($f['termin_do']))        { $where[] = "termin_platnosci <= ?"; $params[] = $f['termin_do']; }
    if (!empty($f['waluta']))           { $where[] = "waluta = ?"; $params[] = $f['waluta']; }
    if (!empty($f['mpp']))              { $where[] = "wymaga_mpp = 1"; }
    if (!empty($f['q'])) {
        $where[] = "(title LIKE ? OR kontrahent_nazwa LIKE ? OR nr_faktury LIKE ?)";
        $q = '%' . $f['q'] . '%';
        array_push($params, $q, $q, $q);
    }

    $edok_rows = db_all(
        "SELECT * FROM edok_documents WHERE " . implode(' AND ', $where) . " ORDER BY id DESC",
        $params
    );

    $out = [];
    foreach ($edok_rows as $r) {
        $out[] = [
            'source'            => 'edok',
            'id'                => (int)$r['id'],
            'number'            => $r['number'],
            'title'             => $r['title'],
            'kontrahent'        => $r['kontrahent_nazwa'],
            'nip'               => $r['kontrahent_nip'],
            'rachunek_bankowy'  => $r['rachunek_bankowy'],
            'kwota_netto'       => $r['kwota_netto'],
            'kwota_vat'         => $r['kwota_vat'],
            'kwota_brutto'      => $r['kwota_brutto'],
            'waluta'            => $r['waluta'] ?: 'PLN',
            'termin_platnosci'  => $r['termin_platnosci'],
            'klasyfikacja'      => edok_transfer_label_klasyfikacja($r['rodzaj_dzialalnosci'], $r['projekt']),
            'status_platnosci'  => $r['status_platnosci'] ?: 'nowy',
            'wymaga_mpp'        => (int)$r['wymaga_mpp'],
            // Dokumenty sprzed dodania pola mają puste tytul_przelewu — dogeneruj w locie,
            // żeby Preliminarz zawsze pokazywał gotowy tytuł do wklejenia w przelewie.
            'tytul_przelewu'    => $r['tytul_przelewu'] ?: edok_generate_tytul_przelewu($r),
            'view_url'          => APP_URL . '/edok/view.php?id=' . $r['id'],
        ];
    }

    // Archiwalny KDOK — best-effort, nie wywracaj Preliminarza gdy moduł/tabela nie istnieje.
    try {
        require_once __DIR__ . '/ksiegowosc.php';
        kdok_migrate();
        $kdok_where  = ["status = 'zaakceptowany'", "COALESCE(wyklucz_z_preliminarza,0) = 0"];
        $kdok_params = [];
        if (!empty($f['status_platnosci'])) { $kdok_where[] = "COALESCE(status_platnosci,'nowy') = ?"; $kdok_params[] = $f['status_platnosci']; }
        else                                { $kdok_where[] = "COALESCE(status_platnosci,'nowy') NOT IN ('anulowany')"; }
        if (!empty($f['termin_od']))        { $kdok_where[] = "termin_platnosci >= ?"; $kdok_params[] = $f['termin_od']; }
        if (!empty($f['termin_do']))        { $kdok_where[] = "termin_platnosci <= ?"; $kdok_params[] = $f['termin_do']; }
        if (!empty($f['waluta']))           { $kdok_where[] = "waluta = ?"; $kdok_params[] = $f['waluta']; }
        if (!empty($f['mpp']))              { $kdok_where[] = "wymaga_mpp = 1"; }
        if (!empty($f['q'])) {
            $kdok_where[] = "(title LIKE ? OR nip_dostawcy LIKE ? OR nr_faktury LIKE ?)";
            $q = '%' . $f['q'] . '%';
            array_push($kdok_params, $q, $q, $q);
        }
        $kdok_rows = kdok_all(
            "SELECT * FROM kdok_documents WHERE " . implode(' AND ', $kdok_where) . " ORDER BY id DESC",
            $kdok_params
        );
        foreach ($kdok_rows as $r) {
            $out[] = [
                'source'            => 'kdok',
                'id'                => (int)$r['id'],
                'number'            => $r['number'],
                'title'             => $r['title'],
                'kontrahent'        => $r['title'],
                'nip'               => $r['nip_dostawcy'] ?? '',
                'rachunek_bankowy'  => $r['rachunek_bankowy'] ?? '',
                'kwota_netto'       => $r['kwota_netto'] ?? '',
                'kwota_vat'         => $r['kwota_vat'] ?? '',
                'kwota_brutto'      => $r['kwota_brutto'] ?: $r['kwota'] ?: '',
                'waluta'            => $r['waluta'] ?: 'PLN',
                'termin_platnosci'  => $r['termin_platnosci'] ?? '',
                'klasyfikacja'      => $r['centrum_kosztow'] ?: $r['projekt'] ?? '',
                'status_platnosci'  => $r['status_platnosci'] ?: 'nowy',
                'wymaga_mpp'        => (int)($r['wymaga_mpp'] ?? 0),
                'tytul_przelewu'    => $r['tytul_przelewu'] ?: $r['title'],
                'view_url'          => APP_URL . '/ksiegowosc/view.php?id=' . $r['id'],
            ];
        }
    } catch (\Throwable $e) {}

    foreach ($out as &$row) $row['priorytet'] = edok_platnosc_priorytet($row);
    unset($row);
    usort($out, fn($a, $b) => $a['priorytet'] <=> $b['priorytet'] ?: strcmp($a['termin_platnosci'] ?? '', $b['termin_platnosci'] ?? ''));
    return $out;
}

// ── Integracja KSeF (reużywa includes/kdok_ksef.php — wspólne połączenie organizacyjne) ─

/** Tworzy dokument EODoK z danych faktury pobranej z KSeF (analogicznie do kdok_ksef_create_doc). */
function edok_ksef_create_doc(array $invoice_data): int {
    $title = trim(
        ($invoice_data['invoice_number'] ?? '')
        . ($invoice_data['seller_name'] ? ' — ' . $invoice_data['seller_name'] : '')
    ) ?: ('Faktura KSeF ' . ($invoice_data['ksef_reference'] ?? ''));

    $doc_id = db_insert('edok_documents', [
        'number'              => edok_next_number(),
        'title'               => $title,
        'typ_dokumentu'       => 'faktura_vat',
        'description'         => 'Faktura pobrana automatycznie z KSeF, nr referencyjny: ' . ($invoice_data['ksef_reference'] ?? ''),
        'kontrahent_nazwa'    => $invoice_data['seller_name'] ?? '',
        'kontrahent_nip'      => $invoice_data['seller_nip']  ?? '',
        'nr_faktury'          => $invoice_data['invoice_number'] ?? '',
        'data_wystawienia'    => $invoice_data['issue_date'] ?: null,
        'kwota_brutto'        => $invoice_data['gross_value'] ?? '',
        'waluta'              => $invoice_data['currency'] ?: 'PLN',
        'ksef_reference'      => $invoice_data['ksef_reference'] ?? '',
        'status'              => 'w_obiegu',
        'created_by'          => null,
        'creator_name'        => 'KSeF (auto-import)',
        'created_at'          => date('Y-m-d H:i:s'),
        'updated_at'          => date('Y-m-d H:i:s'),
    ]);

    edok_log($doc_id, 'submit', '', 'draft', 'w_obiegu', 'Auto-import z KSeF, nr referencyjny: ' . ($invoice_data['ksef_reference'] ?? '') . '. Dokument wymaga uzupełnienia opisu, kwot netto/VAT i dekretacji przed etapem kontroli merytorycznej.');

    return $doc_id;
}

// ── Szablony dokumentów (powtarzalne wydatki/przychody — np. stały czynsz,
// cykliczna darowizna) ──────────────────────────────────────────────────────
// Szablon jest globalny (jak task_templates w module Zadań — patrz
// includes/tasks.php) i tylko wypełnia formularz edok/add.php domyślnymi
// wartościami; zastosowanie NIE tworzy dokumentu samodzielnie — użytkownik
// zawsze uzupełnia kwotę/datę i przechodzi przez normalny formularz.

function edok_templates_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS edok_templates (
        id                   INTEGER PRIMARY KEY AUTOINCREMENT,
        name                 TEXT    NOT NULL,
        kierunek             TEXT    NOT NULL DEFAULT 'wydatek',
        typ_dokumentu        TEXT    NOT NULL DEFAULT '',
        description          TEXT    NOT NULL DEFAULT '',
        kontrahent_nazwa     TEXT    NOT NULL DEFAULT '',
        kontrahent_nip       TEXT    NOT NULL DEFAULT '',
        rachunek_bankowy     TEXT    NOT NULL DEFAULT '',
        stawka_vat           TEXT    NOT NULL DEFAULT '',
        waluta               TEXT    NOT NULL DEFAULT 'PLN',
        rodzaj_dzialalnosci  TEXT    NOT NULL DEFAULT '',
        projekt              TEXT    NOT NULL DEFAULT '',
        mpk                  TEXT    NOT NULL DEFAULT '',
        zrodlo_przychodu     TEXT    NOT NULL DEFAULT '',
        kwota_netto          TEXT    NOT NULL DEFAULT '',
        kwota_vat            TEXT    NOT NULL DEFAULT '',
        kwota_brutto         TEXT    NOT NULL DEFAULT '',
        is_active            INTEGER NOT NULL DEFAULT 1,
        created_by           INTEGER,
        created_at           TEXT,
        updated_at           TEXT
    )");
}

/** Wszystkie szablony (aktywne domyślnie), posortowane po kierunku i nazwie. */
function edok_get_templates(bool $active_only = true): array {
    edok_templates_migrate();
    return db_all(
        "SELECT * FROM edok_templates" . ($active_only ? " WHERE is_active = 1" : "")
        . " ORDER BY kierunek, name"
    );
}

function edok_get_template(int $id): ?array {
    edok_templates_migrate();
    return db_one("SELECT * FROM edok_templates WHERE id = ?", [$id]) ?: null;
}

const EDOK_TEMPLATE_FIELDS = [
    'name', 'kierunek', 'typ_dokumentu', 'description', 'kontrahent_nazwa', 'kontrahent_nip',
    'rachunek_bankowy', 'stawka_vat', 'waluta', 'rodzaj_dzialalnosci', 'projekt', 'mpk',
    'zrodlo_przychodu', 'kwota_netto', 'kwota_vat', 'kwota_brutto',
];

/** Tworzy (id=0) albo aktualizuje szablon. Zwraca id. */
function edok_template_save(array $data, int $id = 0): int {
    edok_templates_migrate();
    $row = [];
    foreach (EDOK_TEMPLATE_FIELDS as $f) $row[$f] = trim((string)($data[$f] ?? ''));
    if ($row['kierunek'] !== 'przychod') $row['kierunek'] = 'wydatek';
    if ($row['waluta'] === '') $row['waluta'] = 'PLN';

    if ($id > 0) {
        db_update('edok_templates', $row, $id);
        return $id;
    }
    $row['is_active']  = 1;
    $row['created_by'] = current_user()['id'] ?? null;
    $row['created_at'] = date('Y-m-d H:i:s');
    return db_insert('edok_templates', $row);
}

function edok_template_set_active(int $id, bool $active): void {
    edok_templates_migrate();
    db_update('edok_templates', ['is_active' => $active ? 1 : 0], $id);
}

function edok_template_delete(int $id): void {
    edok_templates_migrate();
    db()->prepare("DELETE FROM edok_templates WHERE id = ?")->execute([$id]);
}
