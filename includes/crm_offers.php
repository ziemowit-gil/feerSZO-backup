<?php
/**
 * includes/crm_offers.php — Moduł Ofertowania CRM (działalność odpłatna).
 *
 * Tabele (baza CRM — crm_db(), domyślnie główna):
 *   crm_offer_catalog       — katalog usług/produktów odpłatnych (cennik)
 *   crm_offers              — nagłówek oferty (klient, status, warunki, finansowanie)
 *   crm_offer_variants      — warianty oferty (pakiety A/B/C) w jednym dokumencie
 *   crm_offer_items         — pozycje oferty (per wariant), z katalogu lub niestandardowe
 *   crm_offer_confirmations — POTWIERDZENIA (wymagane dla osoby fizycznej)
 *   crm_offer_events        — dziennik zdarzeń oferty (audyt, także akcje klienta)
 *   crm_offer_templates     — szablony ofert (gotowe zestawy pozycji)
 *
 * Powiązania z istniejącym CRM/SZO:
 *   crm_offers.contact_id  → crm_contacts(id)        — kontrahent / instytucja / osoba
 *   crm_offers.case_id     → crm_cases(id)           — sprawa (szansa sprzedaży)
 *   crm_offers.owner_id    → users(id)               — handlowiec / koordynator
 *   crm_offers.objective_id→ strategy_objectives(id) — cel statutowy (baza główna)
 *   strategy_mapping(entity_type='crm_offer')        — wkład oferty w cel statutowy
 *   crm_activities                                    — automatyczny follow-up
 *   umowy_uslugi                                      — konwersja na umowę o świadczenie usług
 *
 * ⚠ ZASADA KLUCZOWA (osoba fizyczna):
 *   Oferta skierowana do osoby fizycznej (konsumenta) wymaga POTWIERDZENIA przez
 *   klienta — dopiero potwierdzenie pozwala uznać ofertę za zaakceptowaną i
 *   uruchomić realizację (konwersję). Patrz crm_offer_requires_confirmation()
 *   oraz crm_offer_blocker().
 *
 * Wymaga: db.php, auth.php, functions.php, crm.php
 */

require_once __DIR__ . '/crm.php';

// ─────────────────────────────────────────────────────────────────────────────
// STAŁE
// ─────────────────────────────────────────────────────────────────────────────

/** Cykl życia oferty. `client` = status ustawiany decyzją klienta. */
const CRM_OFFER_STATUSES = [
    'szkic' => [
        'label' => 'Szkic', 'color' => '#6B7280', 'bg' => '#F3F4F6',
        'icon' => 'bi-pencil', 'open' => 1,
        'hint' => 'Oferta w przygotowaniu — niewidoczna dla klienta.',
    ],
    'do_zatwierdzenia' => [
        'label' => 'Do zatwierdzenia', 'color' => '#7C3AED', 'bg' => '#F5F3FF',
        'icon' => 'bi-shield-check', 'open' => 1,
        'hint' => 'Rabat przekracza limit — wymaga zatwierdzenia przez osobę uprawnioną.',
    ],
    'wyslana' => [
        'label' => 'Wysłana', 'color' => '#1D4ED8', 'bg' => '#EEF4FF',
        'icon' => 'bi-send', 'open' => 1,
        'hint' => 'Oferta przekazana klientowi — czeka na decyzję.',
    ],
    'zaakceptowana' => [
        'label' => 'Zaakceptowana', 'color' => '#2E844A', 'bg' => '#EFF7ED',
        'icon' => 'bi-check-circle', 'open' => 0,
        'hint' => 'Klient wybrał wariant i zaakceptował warunki.',
    ],
    'odrzucona' => [
        'label' => 'Odrzucona', 'color' => '#DC2626', 'bg' => '#FEF2F2',
        'icon' => 'bi-x-circle', 'open' => 0,
        'hint' => 'Klient nie przyjął oferty.',
    ],
    'wygasla' => [
        'label' => 'Wygasła', 'color' => '#B45309', 'bg' => '#FEF3E2',
        'icon' => 'bi-hourglass-bottom', 'open' => 0,
        'hint' => 'Minął termin ważności bez decyzji klienta.',
    ],
    'zrealizowana' => [
        'label' => 'Zrealizowana', 'color' => '#0F766E', 'bg' => '#ECFDF5',
        'icon' => 'bi-box-seam', 'open' => 0,
        'hint' => 'Oferta przekształcona w zamówienie / umowę / projekt.',
    ],
    'anulowana' => [
        'label' => 'Anulowana', 'color' => '#9CA3AF', 'bg' => '#F3F4F6',
        'icon' => 'bi-slash-circle', 'open' => 0,
        'hint' => 'Oferta wycofana przez organizację.',
    ],
];

/** Stawki VAT właściwe dla działalności odpłatnej NGO (zw./np. to nie 0%!). */
const CRM_OFFER_VAT_RATES = [
    '23' => ['label' => '23%',  'rate' => 23.0, 'note' => ''],
    '8'  => ['label' => '8%',   'rate' => 8.0,  'note' => ''],
    '5'  => ['label' => '5%',   'rate' => 5.0,  'note' => ''],
    '0'  => ['label' => '0%',   'rate' => 0.0,  'note' => ''],
    'zw' => ['label' => 'zw.',  'rate' => 0.0,  'note' => 'Zwolnienie przedmiotowe (art. 43 ustawy o VAT) — wymaga podania podstawy.'],
    'np' => ['label' => 'np.',  'rate' => 0.0,  'note' => 'Czynność niepodlegająca opodatkowaniu VAT.'],
];

/** Źródło finansowania / kwalifikacja działalności — pole wymagane w NGO. */
const CRM_OFFER_FUNDING = [
    'odplatna'    => 'Odpłatna działalność pożytku publicznego',
    'nieodplatna' => 'Nieodpłatna działalność pożytku publicznego',
    'gospodarcza' => 'Działalność gospodarcza',
    'dotacja'     => 'Finansowane z dotacji / projektu',
    'mieszane'    => 'Finansowanie mieszane (odpłatność + dotacja)',
    'sponsor'     => 'Sponsor / darczyńca',
];

/** Sposób potwierdzenia oferty przez klienta. */
const CRM_OFFER_CONFIRM_METHODS = [
    'online' => ['label' => 'Potwierdzenie online (link)',      'icon' => 'bi-cursor',        'self' => 1],
    'email'  => ['label' => 'Potwierdzenie e-mail',             'icon' => 'bi-envelope-check','self' => 0],
    'skan'   => ['label' => 'Skan / podpisany dokument',        'icon' => 'bi-paperclip',     'self' => 0],
    'osobiscie' => ['label' => 'Osobiście / protokolarnie',     'icon' => 'bi-person-check',  'self' => 0],
];

/** Typy kontaktów traktowane jako osoba fizyczna (konsument). */
const CRM_OFFER_PERSON_TYPES = ['osoba'];

/** Cele konwersji „1 kliknięciem". */
const CRM_OFFER_CONVERT_TARGETS = [
    'sprawa'  => ['label' => 'Sprawa CRM (zamówienie / projekt)', 'icon' => 'bi-briefcase-fill'],
    'zadanie' => ['label' => 'Zadanie operacyjne',                'icon' => 'bi-check2-square'],
    'umowa'   => ['label' => 'Umowa o świadczenie usług',         'icon' => 'bi-file-earmark-text-fill'],
];

// ─────────────────────────────────────────────────────────────────────────────
// USTAWIENIA
// ─────────────────────────────────────────────────────────────────────────────

/** Ustawienie modułu ofert (settings w bazie głównej) z wartością domyślną. */
function crm_offer_setting(string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $v = '';
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $v = (string)($r['value'] ?? '');
    } catch (\Throwable $e) {}
    $cache[$key] = ($v === '') ? $default : $v;
    return $cache[$key];
}

function crm_offer_setting_save(string $key, string $value): void {
    try {
        db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)
                       ON CONFLICT(key_) DO UPDATE SET value=excluded.value")->execute([$key, $value]);
    } catch (\Throwable $e) {
        if (db_one("SELECT key_ FROM settings WHERE key_=?", [$key])) {
            db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $key]);
        } else {
            db_insert('settings', ['key_' => $key, 'value' => $value]);
        }
    }
}

/** Domyślna liczba dni ważności oferty. */
function crm_offer_default_validity(): int {
    return max(1, (int)crm_offer_setting('crm_offer_validity_days', '14'));
}

/** Po ilu dniach od wysłania powstaje zadanie follow-up. */
function crm_offer_followup_days(): int {
    return max(1, (int)crm_offer_setting('crm_offer_followup_days', '3'));
}

/** Czy oferta dla osoby fizycznej wymaga potwierdzenia (domyślnie: TAK). */
function crm_offer_confirm_person_required(): bool {
    return crm_offer_setting('crm_offer_confirm_person', '1') === '1';
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRACJA
// ─────────────────────────────────────────────────────────────────────────────

function crm_offers_migrate(): bool {
    static $done = null;
    if ($done !== null) return $done;
    $done = false;
    try {
        $done = _crm_offers_migrate_run();
    } catch (\Throwable $e) {
        // Kartoteka kontaktu i dashboard tylko „przy okazji" pokazują oferty —
        // problem ze schematem modułu nie może wywalić tych stron (500).
        // Strony samego modułu pokazują komunikat z crm_offers_last_error().
        crm_offers_last_error($e->getMessage());
        error_log('[crm_offers_migrate] ' . $e->getMessage());
        $done = false;
    }
    return $done;
}

/** Czy schemat modułu ofert jest dostępny (migracja przeszła). */
function crm_offers_available(): bool {
    return crm_offers_migrate();
}

/** Ostatni błąd migracji modułu — do pokazania na stronach modułu. */
function crm_offers_last_error(?string $set = null): string {
    static $err = '';
    if ($set !== null) $err = $set;
    return $err;
}

function _crm_offers_migrate_run(): bool {
    crm_migrate();
    $pdo = crm_db();

    // ── Katalog usług odpłatnych (cennik) ───────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_offer_catalog (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        code             TEXT,
        name             TEXT    NOT NULL,
        description      TEXT,
        category         TEXT,
        unit             TEXT    NOT NULL DEFAULT 'szt.',
        unit_net         DECIMAL(12,2) NOT NULL DEFAULT 0,
        vat_rate         TEXT    NOT NULL DEFAULT '23',
        vat_basis        TEXT,
        funding_source   TEXT    NOT NULL DEFAULT 'odplatna',
        objective_id     INTEGER,
        sphere_id        INTEGER,
        accounting_note  TEXT,
        min_unit_net     DECIMAL(12,2),
        is_active        INTEGER NOT NULL DEFAULT 1,
        sort_order       INTEGER NOT NULL DEFAULT 0,
        created_by       INTEGER,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_ofc_active ON crm_offer_catalog(is_active, sort_order)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_ofc_cat    ON crm_offer_catalog(category)");

    // ── Oferty ──────────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_offers (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        offer_number       TEXT    NOT NULL,
        revision           INTEGER NOT NULL DEFAULT 1,
        parent_offer_id    INTEGER REFERENCES crm_offers(id) ON DELETE SET NULL,
        contact_id         INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        case_id            INTEGER REFERENCES crm_cases(id) ON DELETE SET NULL,
        owner_id           INTEGER,
        status             TEXT    NOT NULL DEFAULT 'szkic',
        title              TEXT    NOT NULL,
        intro              TEXT,
        terms              TEXT,
        notes_internal     TEXT,
        currency           TEXT    NOT NULL DEFAULT 'PLN',
        valid_until        DATE,
        payment_terms_days INTEGER NOT NULL DEFAULT 14,
        delivery_terms     TEXT,
        discount_pct       DECIMAL(5,2) NOT NULL DEFAULT 0,
        discount_reason    TEXT,
        discount_approved_by INTEGER,
        discount_approved_at DATETIME,
        selected_variant_id  INTEGER,
        total_net          DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_vat          DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_gross        DECIMAL(12,2) NOT NULL DEFAULT 0,
        funding_source     TEXT    NOT NULL DEFAULT 'odplatna',
        objective_id       INTEGER,
        sphere_id          INTEGER,
        statutory_note     TEXT,
        accounting_note    TEXT,
        client_type        TEXT    NOT NULL DEFAULT 'osoba',
        client_snapshot    TEXT,
        requires_confirmation INTEGER NOT NULL DEFAULT 0,
        confirmed_at       DATETIME,
        confirmation_id    INTEGER,
        access_token       TEXT,
        public_views       INTEGER NOT NULL DEFAULT 0,
        last_viewed_at     DATETIME,
        sent_at            DATETIME,
        decided_at         DATETIME,
        reject_reason      TEXT,
        followup_at        DATETIME,
        followup_activity_id INTEGER,
        expire_notified_at DATETIME,
        converted_type     TEXT,
        converted_id       INTEGER,
        converted_at       DATETIME,
        converted_by       INTEGER,
        created_by         INTEGER,
        created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted_at         DATETIME
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_crm_offers_number  ON crm_offers(offer_number)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_offers_contact ON crm_offers(contact_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_offers_status  ON crm_offers(status, valid_until)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_offers_owner   ON crm_offers(owner_id, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_offers_case    ON crm_offers(case_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_offers_token   ON crm_offers(access_token)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_offers_upd     ON crm_offers(updated_at)");

    // ── Warianty (pakiety A/B/C w jednym dokumencie) ─────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_offer_variants (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        offer_id       INTEGER NOT NULL REFERENCES crm_offers(id) ON DELETE CASCADE,
        code           TEXT    NOT NULL DEFAULT 'A',
        name           TEXT    NOT NULL,
        description    TEXT,
        is_recommended INTEGER NOT NULL DEFAULT 0,
        sort_order     INTEGER NOT NULL DEFAULT 0,
        total_net      DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_vat      DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_gross    DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_ofv_offer ON crm_offer_variants(offer_id, sort_order)");

    // ── Pozycje oferty ──────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_offer_items (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        offer_id      INTEGER NOT NULL REFERENCES crm_offers(id)         ON DELETE CASCADE,
        variant_id    INTEGER NOT NULL REFERENCES crm_offer_variants(id) ON DELETE CASCADE,
        catalog_id    INTEGER REFERENCES crm_offer_catalog(id) ON DELETE SET NULL,
        name          TEXT    NOT NULL,
        description   TEXT,
        unit          TEXT    NOT NULL DEFAULT 'szt.',
        qty           DECIMAL(12,3) NOT NULL DEFAULT 1,
        unit_net      DECIMAL(12,2) NOT NULL DEFAULT 0,
        discount_pct  DECIMAL(5,2)  NOT NULL DEFAULT 0,
        vat_rate      TEXT    NOT NULL DEFAULT '23',
        vat_basis     TEXT,
        line_net      DECIMAL(12,2) NOT NULL DEFAULT 0,
        line_vat      DECIMAL(12,2) NOT NULL DEFAULT 0,
        line_gross    DECIMAL(12,2) NOT NULL DEFAULT 0,
        objective_id  INTEGER,
        merit_note    TEXT,
        is_optional   INTEGER NOT NULL DEFAULT 0,
        sort_order    INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_ofi_offer   ON crm_offer_items(offer_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_ofi_variant ON crm_offer_items(variant_id, sort_order)");

    // ── Potwierdzenia (wymagane dla osoby fizycznej) ─────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_offer_confirmations (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        offer_id       INTEGER NOT NULL REFERENCES crm_offers(id) ON DELETE CASCADE,
        variant_id     INTEGER,
        method         TEXT    NOT NULL DEFAULT 'online',
        confirmed_name TEXT,
        confirmed_email TEXT,
        statements     TEXT,
        note           TEXT,
        file_path      TEXT,
        ip             TEXT,
        user_agent     TEXT,
        recorded_by    INTEGER,
        confirmed_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        revoked_at     DATETIME,
        revoked_by     INTEGER,
        revoke_reason  TEXT
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_ofcf_offer ON crm_offer_confirmations(offer_id)");

    // ── Dziennik zdarzeń oferty ─────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_offer_events (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        offer_id    INTEGER NOT NULL REFERENCES crm_offers(id) ON DELETE CASCADE,
        event       TEXT    NOT NULL,
        from_status TEXT,
        to_status   TEXT,
        actor_id    INTEGER,
        actor_label TEXT,
        detail      TEXT,
        meta        TEXT,
        ip          TEXT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_ofe_offer ON crm_offer_events(offer_id, created_at)");

    // ── Szablony ofert ──────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_offer_templates (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        name               TEXT    NOT NULL,
        description        TEXT,
        title              TEXT,
        intro              TEXT,
        terms              TEXT,
        funding_source     TEXT    NOT NULL DEFAULT 'odplatna',
        statutory_note     TEXT,
        objective_id       INTEGER,
        validity_days      INTEGER NOT NULL DEFAULT 14,
        payment_terms_days INTEGER NOT NULL DEFAULT 14,
        variants_json      TEXT,
        is_active          INTEGER NOT NULL DEFAULT 1,
        created_by         INTEGER,
        created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Idempotentne dokładanie kolumn (dla baz z wcześniejszej wersji modułu)
    foreach ([
        "ALTER TABLE crm_offers ADD COLUMN expire_notified_at DATETIME",
        "ALTER TABLE crm_offers ADD COLUMN followup_activity_id INTEGER",
        "ALTER TABLE crm_offer_items ADD COLUMN merit_note TEXT",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (\Throwable $e) {}
    }
    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// UPRAWNIENIA
// ─────────────────────────────────────────────────────────────────────────────

/** Odczyt ofert — jak odczyt CRM. */
function crm_offer_can_read(): bool {
    if (!function_exists('is_admin')) return false;
    return is_admin() || can_write('crm') || can_read('crm');
}

/** Tworzenie / edycja ofert. */
function crm_offer_can_write(): bool {
    if (!function_exists('is_admin')) return false;
    return is_admin() || can_write('crm');
}

function crm_offer_can_delete(): bool {
    if (!function_exists('is_admin')) return false;
    return is_admin() || can_delete('crm');
}

/** ID bieżącego użytkownika — 0 w CLI/cronie (auth.php może nie być załadowany). */
function _crm_offer_uid(): int {
    if (!function_exists('current_user')) return 0;
    $u = current_user();
    return (int)($u['id'] ?? 0);
}

/** Rola bieżącego użytkownika (do limitów rabatów). */
function _crm_offer_role(): string {
    if (!function_exists('current_user')) return '';
    return (string)(current_user()['role'] ?? '');
}

/**
 * Limit rabatu (%) dla roli bieżącego użytkownika.
 * Konfiguracja: settings.crm_offer_discount_limits = JSON {"rola":procent}.
 * Admin zawsze 100%. Brak wpisu → crm_offer_discount_default_limit (domyślnie 10%).
 */
function crm_offer_discount_limit(): float {
    if (function_exists('is_admin') && is_admin()) return 100.0;
    $map = json_decode(crm_offer_setting('crm_offer_discount_limits', '{}'), true) ?: [];
    $role = _crm_offer_role();
    if (isset($map[$role]) && $map[$role] !== '') return (float)$map[$role];
    return (float)crm_offer_setting('crm_offer_discount_default_limit', '10');
}

/** Kto może zatwierdzać rabaty ponad limit. */
function crm_offer_can_approve_discount(): bool {
    if (function_exists('is_admin') && is_admin()) return true;
    $roles = array_filter(array_map('trim', explode(',', crm_offer_setting('crm_offer_discount_approvers', 'admin'))));
    return in_array(_crm_offer_role(), $roles, true);
}

/** Kto może edytować katalog / cennik usług odpłatnych. */
function crm_offer_can_edit_catalog(): bool {
    if (function_exists('is_admin') && is_admin()) return true;
    $roles = array_filter(array_map('trim', explode(',', crm_offer_setting('crm_offer_catalog_editors', 'admin'))));
    return in_array(_crm_offer_role(), $roles, true);
}

/** Czy rabat wymaga zatwierdzenia (przekracza limit autora). */
function crm_offer_discount_needs_approval(float $pct): bool {
    return $pct > crm_offer_discount_limit() + 0.001;
}

// ─────────────────────────────────────────────────────────────────────────────
// NUMERACJA
// ─────────────────────────────────────────────────────────────────────────────

/** Kolejny numer oferty: {PREFIX}/{NNNN}/{ROK}, np. OF/0007/2026. */
function crm_offer_next_number(): string {
    $prefix = crm_offer_setting('crm_offer_number_prefix', 'OF');
    $year   = date('Y');
    for ($i = 0; $i < 50; $i++) {
        $row = crm_one(
            "SELECT COUNT(*) AS n FROM crm_offers WHERE offer_number LIKE ?",
            [$prefix . '/%/' . $year]
        );
        $n      = (int)($row['n'] ?? 0) + 1 + $i;
        $number = sprintf('%s/%04d/%s', $prefix, $n, $year);
        if (!crm_one("SELECT id FROM crm_offers WHERE offer_number=?", [$number])) return $number;
    }
    return sprintf('%s/%s/%s', $prefix, substr((string)time(), -6), $year);
}

function crm_offer_token_new(): string {
    return bin2hex(random_bytes(16));
}

// ─────────────────────────────────────────────────────────────────────────────
// PRZELICZANIE KWOT
// ─────────────────────────────────────────────────────────────────────────────

/** Stawka VAT jako liczba (zw./np. → 0). */
function crm_offer_vat_pct(string $code): float {
    return (float)(CRM_OFFER_VAT_RATES[$code]['rate'] ?? 0.0);
}

/**
 * Wyliczenie pozycji: ilość × cena netto − rabat pozycji − rabat oferty.
 * Zwraca ['net'=>, 'vat'=>, 'gross'=>].
 */
function crm_offer_line_calc(float $qty, float $unit_net, float $discount_pct, string $vat_rate, float $offer_discount_pct = 0.0): array {
    $base = $qty * $unit_net;
    $base *= (1 - max(0.0, min(100.0, $discount_pct)) / 100);
    $base *= (1 - max(0.0, min(100.0, $offer_discount_pct)) / 100);
    $net   = round($base, 2);
    $vat   = round($net * crm_offer_vat_pct($vat_rate) / 100, 2);
    return ['net' => $net, 'vat' => $vat, 'gross' => round($net + $vat, 2)];
}

/**
 * Przelicza wszystkie pozycje i warianty oferty, zapisuje sumy.
 * Sumy nagłówka = wariant wybrany przez klienta, w przeciwnym razie rekomendowany,
 * a gdy brak rekomendacji — pierwszy wariant (kolejność wyświetlania).
 */
function crm_offer_recalc(int $offer_id): array {
    $offer = crm_one("SELECT * FROM crm_offers WHERE id=?", [$offer_id]);
    if (!$offer) return ['net' => 0.0, 'vat' => 0.0, 'gross' => 0.0];

    $odisc = (float)($offer['discount_pct'] ?? 0);
    $variants = crm_all("SELECT * FROM crm_offer_variants WHERE offer_id=? ORDER BY sort_order, id", [$offer_id]);

    foreach ($variants as $v) {
        $vn = $vv = 0.0;
        $items = crm_all("SELECT * FROM crm_offer_items WHERE variant_id=? ORDER BY sort_order, id", [(int)$v['id']]);
        foreach ($items as $it) {
            $c = crm_offer_line_calc(
                (float)$it['qty'], (float)$it['unit_net'], (float)$it['discount_pct'],
                (string)$it['vat_rate'], $odisc
            );
            crm_update('crm_offer_items', [
                'line_net' => $c['net'], 'line_vat' => $c['vat'], 'line_gross' => $c['gross'],
            ], (int)$it['id']);
            // Pozycje opcjonalne nie wchodzą do sumy wariantu
            if ((int)($it['is_optional'] ?? 0) === 1) continue;
            $vn += $c['net'];
            $vv += $c['vat'];
        }
        crm_update('crm_offer_variants', [
            'total_net' => round($vn, 2), 'total_vat' => round($vv, 2), 'total_gross' => round($vn + $vv, 2),
        ], (int)$v['id']);
    }

    // Który wariant reprezentuje ofertę w listach i statystykach
    $lead = null;
    $sel  = (int)($offer['selected_variant_id'] ?? 0);
    foreach ($variants as $v) {
        if ($sel && (int)$v['id'] === $sel) { $lead = $v; break; }
    }
    if (!$lead) {
        foreach ($variants as $v) { if ((int)$v['is_recommended'] === 1) { $lead = $v; break; } }
    }
    if (!$lead && $variants) $lead = $variants[0];

    $tot = ['net' => 0.0, 'vat' => 0.0, 'gross' => 0.0];
    if ($lead) {
        $fresh = crm_one("SELECT total_net, total_vat, total_gross FROM crm_offer_variants WHERE id=?", [(int)$lead['id']]);
        $tot = [
            'net'   => (float)($fresh['total_net'] ?? 0),
            'vat'   => (float)($fresh['total_vat'] ?? 0),
            'gross' => (float)($fresh['total_gross'] ?? 0),
        ];
    }
    crm_update('crm_offers', [
        'total_net' => $tot['net'], 'total_vat' => $tot['vat'], 'total_gross' => $tot['gross'],
        'updated_at' => date('Y-m-d H:i:s'),
    ], $offer_id);

    return $tot;
}

// ─────────────────────────────────────────────────────────────────────────────
// POBIERANIE
// ─────────────────────────────────────────────────────────────────────────────

/** Oferta + dane kontaktu (bez JOIN-a do users — users bywa w innej bazie). */
function crm_offer_get(int $id): ?array {
    $o = crm_one(
        "SELECT o.*, c.imie_nazwisko AS contact_name, c.type AS contact_type, c.email AS contact_email,
                c.telefon AS contact_phone, c.nip AS contact_nip, c.adres AS contact_address,
                c.organizacja AS contact_org, c.status AS contact_status,
                cs.title AS case_title
         FROM crm_offers o
         JOIN crm_contacts c ON c.id = o.contact_id
         LEFT JOIN crm_cases cs ON cs.id = o.case_id
         WHERE o.id=? AND o.deleted_at IS NULL", [$id]
    );
    if (!$o) return null;
    $o['owner_name']  = crm_offer_user_name((int)($o['owner_id'] ?? 0));
    $o['author_name'] = crm_offer_user_name((int)($o['created_by'] ?? 0));
    return $o;
}

/** Oferta po tokenie publicznym (dla strony klienta). */
function crm_offer_get_by_token(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{16,64}$/', $token)) return null;
    $row = crm_one("SELECT id FROM crm_offers WHERE access_token=? AND deleted_at IS NULL", [$token]);
    return $row ? crm_offer_get((int)$row['id']) : null;
}

/** Nazwa użytkownika z bazy głównej (cache). */
function crm_offer_user_name(int $uid): string {
    static $cache = [];
    if (!$uid) return '';
    if (isset($cache[$uid])) return $cache[$uid];
    try {
        $u = db_one("SELECT * FROM users WHERE id=?", [$uid]);
    } catch (\Throwable $e) { $u = null; }
    $n = '';
    if ($u) {
        $n = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));
        if ($n === '') $n = (string)($u['name'] ?? '');
        if ($n === '') $n = (string)($u['email'] ?? '');
    }
    return $cache[$uid] = $n;
}

function crm_offer_variants_list(int $offer_id): array {
    return crm_all("SELECT * FROM crm_offer_variants WHERE offer_id=? ORDER BY sort_order, id", [$offer_id]);
}

function crm_offer_items_list(int $offer_id, ?int $variant_id = null): array {
    if ($variant_id) {
        return crm_all("SELECT * FROM crm_offer_items WHERE variant_id=? ORDER BY sort_order, id", [$variant_id]);
    }
    return crm_all("SELECT * FROM crm_offer_items WHERE offer_id=? ORDER BY variant_id, sort_order, id", [$offer_id]);
}

/** Oferta z wariantami i pozycjami — jedna struktura do renderowania. */
function crm_offer_full(int $id): ?array {
    $o = crm_offer_get($id);
    if (!$o) return null;
    $o['variants'] = crm_offer_variants_list($id);
    foreach ($o['variants'] as &$v) {
        $v['items'] = crm_offer_items_list($id, (int)$v['id']);
    }
    unset($v);
    return $o;
}

function crm_offer_catalog_list(bool $active_only = true): array {
    $w = $active_only ? "WHERE is_active=1" : '';
    return crm_all("SELECT * FROM crm_offer_catalog $w ORDER BY sort_order, category, name");
}

/** Cele statutowe (baza główna, moduł Strategii) — [] gdy modułu nie ma. */
function crm_offer_objectives(): array {
    try {
        return db_all(
            "SELECT o.id, o.nazwa, o.status, s.nazwa AS sphere_nazwa, s.id AS sphere_id
             FROM strategy_objectives o
             LEFT JOIN public_benefit_spheres s ON s.id = o.sphere_id
             WHERE o.status <> 'zarzucony' ORDER BY s.sort_order, o.nazwa"
        );
    } catch (\Throwable $e) { return []; }
}

function crm_offer_objective_label(?int $id): string {
    if (!$id) return '';
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    try {
        $r = db_one("SELECT nazwa FROM strategy_objectives WHERE id=?", [$id]);
    } catch (\Throwable $e) { $r = null; }
    return $cache[$id] = (string)($r['nazwa'] ?? '');
}

/** Historia ofert kontrahenta — do kartoteki w CRM. */
function crm_offer_history(int $contact_id, int $limit = 50): array {
    $limit = max(1, min(500, $limit));
    try {
        return crm_all(
            "SELECT * FROM crm_offers WHERE contact_id=? AND deleted_at IS NULL
             ORDER BY created_at DESC LIMIT $limit", [$contact_id]
        );
    } catch (\Throwable $e) {
        error_log('[crm_offer_history] ' . $e->getMessage());
        return [];
    }
}

/** Agregaty ofert kontrahenta (kartoteka). */
function crm_offer_contact_summary(int $contact_id): array {
    try {
        return _crm_offer_contact_summary($contact_id);
    } catch (\Throwable $e) {
        error_log('[crm_offer_contact_summary] ' . $e->getMessage());
        return ['cnt' => 0, 'won' => 0, 'lost' => 0, 'open' => 0, 'won_value' => 0.0];
    }
}

function _crm_offer_contact_summary(int $contact_id): array {
    $r = crm_one(
        "SELECT COUNT(*) AS cnt,
                SUM(CASE WHEN status='zaakceptowana' OR status='zrealizowana' THEN 1 ELSE 0 END) AS won,
                SUM(CASE WHEN status='odrzucona' THEN 1 ELSE 0 END) AS lost,
                SUM(CASE WHEN status IN ('szkic','do_zatwierdzenia','wyslana') THEN 1 ELSE 0 END) AS open,
                SUM(CASE WHEN status='zaakceptowana' OR status='zrealizowana' THEN total_gross ELSE 0 END) AS won_value
         FROM crm_offers WHERE contact_id=? AND deleted_at IS NULL", [$contact_id]
    ) ?: [];
    return [
        'cnt'       => (int)($r['cnt'] ?? 0),
        'won'       => (int)($r['won'] ?? 0),
        'lost'      => (int)($r['lost'] ?? 0),
        'open'      => (int)($r['open'] ?? 0),
        'won_value' => (float)($r['won_value'] ?? 0),
    ];
}

/** Statystyki modułu (kafelki na liście i dashboardzie). */
function crm_offer_stats(): array {
    $out = ['total' => 0, 'by_status' => [], 'pipeline' => 0.0, 'won_value' => 0.0, 'win_rate' => 0.0];
    try {
        $rows = crm_all("SELECT status, COUNT(*) AS n, SUM(total_gross) AS v FROM crm_offers WHERE deleted_at IS NULL GROUP BY status");
    } catch (\Throwable $e) {
        error_log('[crm_offer_stats] ' . $e->getMessage());
        return $out;
    }
    $decided = $won = 0;
    foreach ($rows as $r) {
        $out['by_status'][$r['status']] = ['n' => (int)$r['n'], 'v' => (float)$r['v']];
        $out['total'] += (int)$r['n'];
        if (in_array($r['status'], ['szkic', 'do_zatwierdzenia', 'wyslana'], true)) $out['pipeline'] += (float)$r['v'];
        if (in_array($r['status'], ['zaakceptowana', 'zrealizowana'], true)) { $out['won_value'] += (float)$r['v']; $won += (int)$r['n']; $decided += (int)$r['n']; }
        if (in_array($r['status'], ['odrzucona', 'wygasla'], true)) $decided += (int)$r['n'];
    }
    $out['win_rate'] = $decided ? round($won * 100 / $decided, 1) : 0.0;
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// POTWIERDZENIE OFERTY — OSOBA FIZYCZNA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Czy oferta wymaga POTWIERDZENIA przez klienta.
 *
 * Reguła organizacyjna: oferta skierowana do OSOBY FIZYCZNEJ (konsumenta) nie
 * może być uznana za przyjętą wyłącznie na podstawie adnotacji pracownika —
 * potrzebne jest udokumentowane potwierdzenie klienta (link online, e-mail,
 * skan, protokół). Dla instytucji/kontrahentów wystarcza akceptacja rejestrowana
 * przez opiekuna.
 */
function crm_offer_requires_confirmation(array $offer): bool {
    if ((int)($offer['requires_confirmation'] ?? 0) === 1) return true;
    if (!crm_offer_confirm_person_required()) return false;
    $type = (string)($offer['client_type'] ?? $offer['contact_type'] ?? '');
    return in_array($type, CRM_OFFER_PERSON_TYPES, true);
}

/** Aktualne (niewycofane) potwierdzenie oferty. */
function crm_offer_confirmation(int $offer_id): ?array {
    return crm_one(
        "SELECT * FROM crm_offer_confirmations
         WHERE offer_id=? AND revoked_at IS NULL
         ORDER BY confirmed_at DESC, id DESC LIMIT 1", [$offer_id]
    );
}

function crm_offer_is_confirmed(array $offer): bool {
    if (!crm_offer_requires_confirmation($offer)) return true;
    return crm_offer_confirmation((int)$offer['id']) !== null;
}

/**
 * Twarda blokada akcji. Zwraca komunikat, jeśli akcji NIE wolno wykonać.
 * $action: 'accept' | 'convert'
 */
function crm_offer_blocker(array $offer, string $action): ?string {
    if (!in_array($action, ['accept', 'convert'], true)) return null;
    if (!crm_offer_requires_confirmation($offer)) return null;
    if (crm_offer_confirmation((int)$offer['id'])) return null;

    return $action === 'accept'
        ? 'Oferta dla osoby fizycznej wymaga potwierdzenia przez klienta — nie można oznaczyć jej jako zaakceptowanej bez zarejestrowanego potwierdzenia.'
        : 'Nie można uruchomić realizacji: oferta dla osoby fizycznej nie ma zarejestrowanego potwierdzenia klienta.';
}

/**
 * Ostrzeżenie „miękkie" — pokazywane ZANIM użytkownik wyśle ofertę lub uruchomi
 * realizację. Nie blokuje, ale musi być widoczne (baner + potwierdzenie w JS).
 */
function crm_offer_warning(array $offer): ?string {
    if (!crm_offer_requires_confirmation($offer)) return null;
    if (crm_offer_confirmation((int)$offer['id'])) return null;
    return 'Oferta dla OSOBY FIZYCZNEJ. Zanim uruchomisz realizację, klient musi potwierdzić ofertę '
         . '(link „Potwierdź ofertę" w wiadomości albo rejestracja potwierdzenia w systemie: e-mail, skan, protokół). '
         . 'Bez potwierdzenia oferta pozostaje wyłącznie propozycją.';
}

/**
 * Rejestruje potwierdzenie oferty.
 * $data: variant_id, method, confirmed_name, confirmed_email, statements[], note, file_path
 */
function crm_offer_record_confirmation(int $offer_id, array $data, ?int $recorded_by = null): int {
    $method = (string)($data['method'] ?? 'online');
    if (!isset(CRM_OFFER_CONFIRM_METHODS[$method])) $method = 'online';

    $cid = crm_insert('crm_offer_confirmations', [
        'offer_id'        => $offer_id,
        'variant_id'      => !empty($data['variant_id']) ? (int)$data['variant_id'] : null,
        'method'          => $method,
        'confirmed_name'  => trim((string)($data['confirmed_name'] ?? '')) ?: null,
        'confirmed_email' => trim((string)($data['confirmed_email'] ?? '')) ?: null,
        'statements'      => !empty($data['statements']) ? json_encode($data['statements'], JSON_UNESCAPED_UNICODE) : null,
        'note'            => trim((string)($data['note'] ?? '')) ?: null,
        'file_path'       => (string)($data['file_path'] ?? '') ?: null,
        'ip'              => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        'user_agent'      => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250) ?: null,
        'recorded_by'     => $recorded_by,
        'confirmed_at'    => date('Y-m-d H:i:s'),
    ]);

    crm_update('crm_offers', [
        'confirmed_at'    => date('Y-m-d H:i:s'),
        'confirmation_id' => $cid,
        'updated_at'      => date('Y-m-d H:i:s'),
    ], $offer_id);

    crm_offer_log($offer_id, 'confirmed', [
        'actor_id'    => $recorded_by,
        'actor_label' => $recorded_by ? null : (trim((string)($data['confirmed_name'] ?? '')) ?: 'klient'),
        'detail'      => 'Potwierdzenie oferty — ' . (CRM_OFFER_CONFIRM_METHODS[$method]['label'] ?? $method),
        'meta'        => ['confirmation_id' => $cid, 'method' => $method],
    ]);
    return $cid;
}

/** Wycofanie potwierdzenia (np. omyłkowa rejestracja). */
function crm_offer_revoke_confirmation(int $offer_id, string $reason, int $by): void {
    $c = crm_offer_confirmation($offer_id);
    if (!$c) return;
    crm_update('crm_offer_confirmations', [
        'revoked_at'    => date('Y-m-d H:i:s'),
        'revoked_by'    => $by,
        'revoke_reason' => $reason ?: null,
    ], (int)$c['id']);
    crm_update('crm_offers', ['confirmed_at' => null, 'confirmation_id' => null, 'updated_at' => date('Y-m-d H:i:s')], $offer_id);
    crm_offer_log($offer_id, 'confirmation_revoked', ['actor_id' => $by, 'detail' => $reason]);
}

// ─────────────────────────────────────────────────────────────────────────────
// DZIENNIK ZDARZEŃ
// ─────────────────────────────────────────────────────────────────────────────

function crm_offer_log(int $offer_id, string $event, array $opts = []): void {
    try {
        crm_insert('crm_offer_events', [
            'offer_id'    => $offer_id,
            'event'       => $event,
            'from_status' => $opts['from_status'] ?? null,
            'to_status'   => $opts['to_status'] ?? null,
            'actor_id'    => array_key_exists('actor_id', $opts) ? $opts['actor_id'] : (_crm_offer_uid() ?: null),
            'actor_label' => $opts['actor_label'] ?? null,
            'detail'      => $opts['detail'] ?? null,
            'meta'        => !empty($opts['meta']) ? json_encode($opts['meta'], JSON_UNESCAPED_UNICODE) : null,
            'ip'          => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        error_log('[crm_offer_log] ' . $e->getMessage());
    }
}

function crm_offer_events(int $offer_id, int $limit = 100): array {
    $limit = max(1, min(500, $limit));
    $rows = crm_all("SELECT * FROM crm_offer_events WHERE offer_id=? ORDER BY created_at DESC, id DESC LIMIT $limit", [$offer_id]);
    foreach ($rows as &$r) {
        $r['actor_name'] = $r['actor_label'] ?: crm_offer_user_name((int)($r['actor_id'] ?? 0));
    }
    return $rows;
}

/** Czytelne etykiety zdarzeń dla osi czasu. */
function crm_offer_event_label(string $event): string {
    return [
        'created'              => 'Utworzono ofertę',
        'updated'              => 'Zmieniono ofertę',
        'status_changed'       => 'Zmiana statusu',
        'sent'                 => 'Wysłano do klienta',
        'viewed'               => 'Klient otworzył ofertę',
        'accepted'            => 'Akceptacja',
        'rejected'             => 'Odrzucenie',
        'confirmed'            => 'Potwierdzenie klienta',
        'confirmation_revoked' => 'Wycofano potwierdzenie',
        'discount_requested'   => 'Rabat zgłoszony do zatwierdzenia',
        'discount_approved'    => 'Rabat zatwierdzony',
        'expired'              => 'Wygaśnięcie oferty',
        'converted'            => 'Konwersja / uruchomienie realizacji',
        'followup_created'     => 'Utworzono zadanie follow-up',
        'revision'             => 'Nowa wersja oferty',
        'pdf'                  => 'Wygenerowano PDF',
    ][$event] ?? $event;
}

// ─────────────────────────────────────────────────────────────────────────────
// CYKL ŻYCIA
// ─────────────────────────────────────────────────────────────────────────────

/** Publiczny adres oferty dla klienta (token). */
function crm_offer_public_url(array $offer): string {
    return APP_URL . '/crm/oferta.php?t=' . urlencode((string)($offer['access_token'] ?? ''));
}

/** Zmiana statusu z zapisem w dzienniku. Zwraca true, gdy status faktycznie się zmienił. */
function crm_offer_set_status(int $offer_id, string $status, array $opts = []): bool {
    if (!isset(CRM_OFFER_STATUSES[$status])) return false;
    $o = crm_one("SELECT id, status, contact_id FROM crm_offers WHERE id=?", [$offer_id]);
    if (!$o || $o['status'] === $status) return false;

    $data = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
    if (in_array($status, ['zaakceptowana', 'odrzucona', 'wygasla'], true)) {
        $data['decided_at'] = date('Y-m-d H:i:s');
    }
    if (array_key_exists('reject_reason', $opts)) $data['reject_reason'] = $opts['reject_reason'] ?: null;
    if (array_key_exists('selected_variant_id', $opts)) $data['selected_variant_id'] = $opts['selected_variant_id'] ?: null;
    crm_update('crm_offers', $data, $offer_id);

    crm_offer_log($offer_id, $opts['event'] ?? 'status_changed', [
        'from_status' => $o['status'],
        'to_status'   => $status,
        'actor_id'    => $opts['actor_id'] ?? null,
        'actor_label' => $opts['actor_label'] ?? null,
        'detail'      => $opts['detail'] ?? null,
    ]);

    if (!empty($opts['recalc'])) crm_offer_recalc($offer_id);

    // Automatyzacje CRM — reguły „zdarzenie → akcja" na kontakcie
    $ev = ['wyslana' => 'offer_sent', 'zaakceptowana' => 'offer_accepted', 'odrzucona' => 'offer_rejected'][$status] ?? null;
    if ($ev) {
        try {
            require_once __DIR__ . '/crm_automation.php';
            crm_automation_fire($ev, (int)$o['contact_id'], ['offer_id' => $offer_id, 'to_status' => $status]);
        } catch (\Throwable $e) { error_log('[crm_offer] automation: ' . $e->getMessage()); }
    }
    return true;
}

/**
 * Tworzy zadanie follow-up w CRM (crm_activities) N dni po wysłaniu oferty.
 * Idempotentne — nie duplikuje, jeśli zadanie już istnieje i nie jest zamknięte.
 */
function crm_offer_create_followup(array $offer, ?int $days = null): ?int {
    $days = $days ?? crm_offer_followup_days();
    $offer_id = (int)$offer['id'];

    $existing = (int)($offer['followup_activity_id'] ?? 0);
    if ($existing) {
        $a = crm_one("SELECT id, status FROM crm_activities WHERE id=?", [$existing]);
        if ($a && $a['status'] === 'planned') return $existing;
    }

    $when = date('Y-m-d H:i:s', strtotime('+' . $days . ' days 09:00'));
    $aid  = crm_insert('crm_activities', [
        'contact_id'   => (int)$offer['contact_id'],
        'type'         => 'task',
        'title'        => 'Follow-up oferty ' . $offer['offer_number'],
        'description'  => "Sprawdź decyzję klienta w sprawie oferty {$offer['offer_number']} — „{$offer['title']}\".\n"
                        . 'Wartość: ' . number_format((float)$offer['total_gross'], 2, ',', ' ') . ' ' . $offer['currency']
                        . ($offer['valid_until'] ? ' · Ważna do: ' . $offer['valid_until'] : ''),
        'scheduled_at' => $when,
        'status'       => 'planned',
        'assigned_to'  => (int)($offer['owner_id'] ?? 0) ?: null,
        'created_by'   => (int)($offer['owner_id'] ?? 0) ?: null,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    crm_update('crm_offers', ['followup_at' => $when, 'followup_activity_id' => $aid], $offer_id);
    crm_offer_log($offer_id, 'followup_created', [
        'detail' => 'Zadanie follow-up na ' . date('d.m.Y', strtotime($when)) . ' (' . $days . ' dni od wysłania).',
        'meta'   => ['activity_id' => $aid],
    ]);
    return $aid;
}

/**
 * Wysyła ofertę do klienta: e-mail z linkiem publicznym (+ PDF), status → wysłana,
 * wpis w historii komunikacji kontaktu i zadanie follow-up.
 *
 * $opts: to (e-mail), subject, message (dodatkowy tekst), attach_pdf (bool)
 * Zwraca ['ok'=>bool, 'error'=>string, 'queued'=>int]
 */
function crm_offer_send(array $offer, array $opts = []): array {
    $offer_id = (int)$offer['id'];
    $to = trim((string)($opts['to'] ?? $offer['contact_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Brak poprawnego adresu e-mail klienta.', 'queued' => 0];
    }

    if (empty($offer['access_token'])) {
        $offer['access_token'] = crm_offer_token_new();
        crm_update('crm_offers', ['access_token' => $offer['access_token']], $offer_id);
    }

    $url     = crm_offer_public_url($offer);
    $subject = trim((string)($opts['subject'] ?? '')) ?: ('Oferta ' . $offer['offer_number'] . ' — ' . $offer['title']);
    $extra   = trim((string)($opts['message'] ?? ''));

    $needs_conf = crm_offer_requires_confirmation($offer);

    $body  = '<p>Szanowni Państwo,</p>';
    $body .= '<p>przekazujemy ofertę <strong>' . h($offer['offer_number']) . '</strong> — '
           . h($offer['title']) . '.</p>';
    if ($extra !== '') $body .= '<p>' . nl2br(h($extra)) . '</p>';
    $body .= '<p><strong>Wartość:</strong> ' . h(number_format((float)$offer['total_gross'], 2, ',', ' ') . ' ' . $offer['currency']) . ' brutto';
    if (!empty($offer['valid_until'])) $body .= '<br><strong>Oferta ważna do:</strong> ' . h(date('d.m.Y', strtotime($offer['valid_until'])));
    $body .= '</p>';
    if ($needs_conf) {
        $body .= '<p style="background:#FFF7ED;border-left:3px solid #EA580C;padding:10px 12px;font-size:13px">'
               . '<strong>Potwierdzenie oferty.</strong> Aby oferta mogła zostać zrealizowana, prosimy o jej '
               . 'potwierdzenie pod poniższym linkiem. Do momentu potwierdzenia oferta stanowi wyłącznie propozycję '
               . 'i nie rozpoczynamy świadczenia usługi.</p>';
    }
    $body .= '<p>Treść oferty (wraz z wariantami do wyboru) dostępna jest online:</p>';

    $html = _feer_email_tpl($body, 'Oferta ' . $offer['offer_number'], $url,
        $needs_conf ? 'Zobacz i potwierdź ofertę' : 'Zobacz ofertę');

    $attachments = [];
    if (!empty($opts['attach_pdf'])) {
        $pdf = crm_offer_pdf_file($offer_id);
        if ($pdf) $attachments[] = $pdf;
    }

    require_once __DIR__ . '/mail_queue.php';
    $queued = mail_queue_add(
        $to, (string)($offer['contact_name'] ?? ''), $subject, $html, '',
        'crm_offer', $offer_id, '', false, $attachments
    );

    // Historia komunikacji kontaktu
    try {
        crm_insert('crm_communications', [
            'contact_id'    => (int)$offer['contact_id'],
            'channel'       => 'email',
            'direction'     => 'out',
            'template_name' => 'oferta:' . $offer['offer_number'],
            'subject'       => $subject,
            'body'          => $html,
            'status'        => 'w kolejce',
            'sent_by'       => _crm_offer_uid() ?: null,
            'sent_at'       => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) { error_log('[crm_offer_send] komunikacja: ' . $e->getMessage()); }

    crm_update('crm_offers', [
        'sent_at'               => date('Y-m-d H:i:s'),
        'requires_confirmation' => $needs_conf ? 1 : 0,
        'updated_at'            => date('Y-m-d H:i:s'),
    ], $offer_id);

    crm_offer_set_status($offer_id, 'wyslana', [
        'event'  => 'sent',
        'detail' => 'E-mail do ' . $to . ($attachments ? ' (z PDF)' : ''),
    ]);
    crm_offer_log($offer_id, 'sent', ['detail' => 'Adresat: ' . $to, 'meta' => ['queue_id' => $queued]]);

    $offer['total_gross'] = $offer['total_gross'] ?? 0;
    crm_offer_create_followup(crm_offer_get($offer_id) ?? $offer);

    return ['ok' => true, 'error' => '', 'queued' => $queued];
}

/** Rejestracja wejścia klienta na stronę oferty. */
function crm_offer_mark_viewed(int $offer_id): void {
    crm_db()->prepare("UPDATE crm_offers SET public_views = public_views + 1, last_viewed_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $offer_id]);
    // Nie zaśmiecamy osi czasu — jeden wpis „viewed" na 6 godzin
    $recent = crm_one(
        "SELECT id FROM crm_offer_events WHERE offer_id=? AND event='viewed' AND created_at > ? LIMIT 1",
        [$offer_id, date('Y-m-d H:i:s', time() - 6 * 3600)]
    );
    if (!$recent) crm_offer_log($offer_id, 'viewed', ['actor_id' => null, 'actor_label' => 'klient']);
}

/** Oferty do wygaszenia (cron). */
function crm_offer_expire_due(): int {
    $rows = crm_all(
        "SELECT id FROM crm_offers
         WHERE deleted_at IS NULL AND status IN ('wyslana','do_zatwierdzenia')
           AND valid_until IS NOT NULL AND valid_until < ?", [date('Y-m-d')]
    );
    $n = 0;
    foreach ($rows as $r) {
        if (crm_offer_set_status((int)$r['id'], 'wygasla', ['event' => 'expired', 'detail' => 'Automatyczne wygaśnięcie — minął termin ważności.'])) $n++;
    }
    return $n;
}

/** Uzupełnia brakujące zadania follow-up (np. gdy wysyłka szła cronem). */
function crm_offer_followups_due(): int {
    $rows = crm_all(
        "SELECT * FROM crm_offers
         WHERE deleted_at IS NULL AND status='wyslana' AND sent_at IS NOT NULL
           AND (followup_activity_id IS NULL OR followup_activity_id=0)
           AND sent_at <= ?", [date('Y-m-d H:i:s', time() - crm_offer_followup_days() * 86400)]
    );
    $n = 0;
    foreach ($rows as $o) {
        if (crm_offer_create_followup($o, 0)) $n++;
    }
    return $n;
}

// ─────────────────────────────────────────────────────────────────────────────
// KONWERSJA 1-KLIKNIĘCIEM
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Przekształca wygraną ofertę w sprawę CRM (zamówienie/projekt), zadanie
 * operacyjne albo umowę o świadczenie usług (formularz z wypełnionymi danymi).
 *
 * ⚠ Dla osoby fizycznej wymaga zarejestrowanego potwierdzenia — patrz
 *   crm_offer_blocker($offer, 'convert').
 *
 * Zwraca ['ok'=>bool,'error'=>string,'type'=>string,'id'=>int,'url'=>string]
 */
function crm_offer_convert(array $offer, string $target, array $opts = []): array {
    $offer_id = (int)$offer['id'];
    if (!isset(CRM_OFFER_CONVERT_TARGETS[$target])) {
        return ['ok' => false, 'error' => 'Nieznany cel konwersji.', 'type' => $target, 'id' => 0, 'url' => ''];
    }
    if ($blk = crm_offer_blocker($offer, 'convert')) {
        return ['ok' => false, 'error' => $blk, 'type' => $target, 'id' => 0, 'url' => ''];
    }

    $full    = crm_offer_full($offer_id) ?? $offer;
    $variant = crm_offer_selected_variant($full);
    $lines   = [];
    foreach (($variant['items'] ?? []) as $it) {
        $lines[] = '• ' . $it['name'] . ' — ' . rtrim(rtrim(number_format((float)$it['qty'], 3, ',', ' '), '0'), ',')
                 . ' ' . $it['unit'] . ' × ' . number_format((float)$it['unit_net'], 2, ',', ' ') . ' ' . $full['currency'];
    }
    $summary = "Na podstawie oferty {$full['offer_number']} (wariant "
             . ($variant['code'] ?? '—') . ' — ' . ($variant['name'] ?? '') . ").\n"
             . ($lines ? implode("\n", $lines) . "\n" : '')
             . 'Wartość: ' . number_format((float)$full['total_net'], 2, ',', ' ') . ' netto / '
             . number_format((float)$full['total_gross'], 2, ',', ' ') . ' brutto ' . $full['currency'] . ".\n"
             . 'Finansowanie: ' . (CRM_OFFER_FUNDING[$full['funding_source']] ?? $full['funding_source']) . '.';

    $uid = _crm_offer_uid();
    $res = ['ok' => true, 'error' => '', 'type' => $target, 'id' => 0, 'url' => ''];

    if ($target === 'sprawa') {
        $case_id = crm_insert('crm_cases', [
            'contact_id'  => (int)$full['contact_id'],
            'title'       => 'Realizacja: ' . $full['title'] . ' (' . $full['offer_number'] . ')',
            'description' => $summary . ($full['delivery_terms'] ? "\nWarunki realizacji: " . $full['delivery_terms'] : ''),
            'status'      => 'open',
            'priority'    => 'medium',
            'created_by'  => $uid ?: null,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $res['id']  = $case_id;
        $res['url'] = APP_URL . '/crm/cases/view.php?id=' . $case_id;
        if (empty($full['case_id'])) crm_update('crm_offers', ['case_id' => $case_id], $offer_id);
        try {
            require_once __DIR__ . '/crm_automation.php';
            crm_automation_fire('case_created', (int)$full['contact_id'], ['case_id' => $case_id]);
        } catch (\Throwable $e) {}
    } elseif ($target === 'zadanie') {
        $aid = crm_insert('crm_activities', [
            'contact_id'   => (int)$full['contact_id'],
            'type'         => 'task',
            'title'        => 'Realizacja oferty ' . $full['offer_number'],
            'description'  => $summary,
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('+1 day 09:00')),
            'status'       => 'planned',
            'assigned_to'  => (int)($full['owner_id'] ?? 0) ?: null,
            'created_by'   => $uid ?: null,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $res['id']  = $aid;
        $res['url'] = APP_URL . '/crm/contact/view.php?id=' . (int)$full['contact_id'] . '#activities-section';
    } else { // umowa
        $q = http_build_query([
            'offer_id'         => $offer_id,
            'nazwa_wykonawcy'  => $full['contact_name'],
            'nip_pesel'        => $full['contact_nip'],
            'adres'            => $full['contact_address'],
            'email'            => $full['contact_email'],
            'telefon'          => $full['contact_phone'],
            'przedmiot_uslugi' => $full['title'],
            'zakres_uslug'     => $summary,
            'wartosc_netto'    => number_format((float)$full['total_net'], 2, '.', ''),
            'wartosc_brutto'   => number_format((float)$full['total_gross'], 2, '.', ''),
            'stawka_vat'       => crm_offer_dominant_vat($variant),
            'waluta'           => $full['currency'],
            'termin_platnosci_dni' => (int)$full['payment_terms_days'],
        ]);
        $res['url'] = APP_URL . '/contracts/uslugi/add.php?' . $q;
    }

    crm_update('crm_offers', [
        'converted_type' => $target,
        'converted_id'   => $res['id'] ?: null,
        'converted_at'   => date('Y-m-d H:i:s'),
        'converted_by'   => $uid ?: null,
        'updated_at'     => date('Y-m-d H:i:s'),
    ], $offer_id);
    crm_offer_set_status($offer_id, 'zrealizowana', [
        'event'  => 'converted',
        'detail' => CRM_OFFER_CONVERT_TARGETS[$target]['label'] . ($res['id'] ? ' #' . $res['id'] : ''),
    ]);
    crm_offer_log($offer_id, 'converted', [
        'detail' => CRM_OFFER_CONVERT_TARGETS[$target]['label'],
        'meta'   => ['target' => $target, 'id' => $res['id']],
    ]);
    return $res;
}

/** Dowiązanie utworzonej umowy do oferty (wywoływane z formularza umowy usług). */
function crm_offer_link_contract(int $offer_id, int $contract_id, string $type = 'uslugi'): void {
    if (!$offer_id || !$contract_id) return;
    crm_offers_migrate();
    if (!crm_one("SELECT id FROM crm_offers WHERE id=?", [$offer_id])) return;
    crm_update('crm_offers', [
        'converted_type' => 'umowa',
        'converted_id'   => $contract_id,
        'converted_at'   => date('Y-m-d H:i:s'),
        'converted_by'   => _crm_offer_uid() ?: null,
        'updated_at'     => date('Y-m-d H:i:s'),
    ], $offer_id);
    crm_offer_log($offer_id, 'converted', [
        'detail' => 'Umowa o świadczenie usług #' . $contract_id . ' (' . $type . ')',
        'meta'   => ['target' => 'umowa', 'id' => $contract_id, 'contract_type' => $type],
    ]);
}

/** Wariant wybrany przez klienta (lub rekomendowany / pierwszy). */
function crm_offer_selected_variant(array $full): array {
    $vs = $full['variants'] ?? [];
    if (!$vs) return [];
    $sel = (int)($full['selected_variant_id'] ?? 0);
    foreach ($vs as $v) if ($sel && (int)$v['id'] === $sel) return $v;
    foreach ($vs as $v) if ((int)$v['is_recommended'] === 1) return $v;
    return $vs[0];
}

/** Dominująca stawka VAT wariantu (do przeniesienia na umowę). */
function crm_offer_dominant_vat(array $variant): string {
    $acc = [];
    foreach (($variant['items'] ?? []) as $it) {
        $acc[$it['vat_rate']] = ($acc[$it['vat_rate']] ?? 0) + (float)$it['line_net'];
    }
    if (!$acc) return '23';
    arsort($acc);
    return (string)array_key_first($acc);
}

/** Powiązanie oferty z celem statutowym (moduł Strategii, baza główna). */
function crm_offer_map_objective(int $offer_id, ?int $objective_id, float $weight = 100.0): void {
    try {
        db_exec("DELETE FROM strategy_mapping WHERE entity_type='crm_offer' AND entity_id=?", [$offer_id]);
        if ($objective_id) {
            db_insert('strategy_mapping', [
                'objective_id'        => $objective_id,
                'entity_type'         => 'crm_offer',
                'entity_id'           => $offer_id,
                'contribution_weight' => $weight,
                'note'                => 'Oferta CRM (działalność odpłatna)',
                'added_by'            => _crm_offer_uid() ?: null,
            ]);
        }
    } catch (\Throwable $e) {
        // Moduł Strategii może być nieobecny — powiązanie jest opcjonalne
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// DOKUMENT OFERTY (HTML → wydruk, PDF, strona klienta, e-mail)
// ─────────────────────────────────────────────────────────────────────────────

function crm_offer_money(float $v, string $cur = 'PLN'): string {
    return number_format($v, 2, ',', ' ') . ' ' . $cur;
}

function crm_offer_qty(float $q): string {
    $s = number_format($q, 3, ',', ' ');
    $s = rtrim(rtrim($s, '0'), ',');
    return $s === '' ? '0' : $s;
}

/** Pigułka statusu (HTML) do list i widoków. */
function crm_offer_status_pill(string $status, bool $small = false): string {
    $c = CRM_OFFER_STATUSES[$status] ?? ['label' => $status, 'color' => '#6B7280', 'bg' => '#F3F4F6', 'icon' => 'bi-dot'];
    $fs = $small ? '.7rem' : '.74rem';
    return '<span style="display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .6rem;border-radius:2rem;'
         . 'font-size:' . $fs . ';font-weight:600;white-space:nowrap;background:' . $c['bg'] . ';color:' . $c['color'] . '">'
         . '<i class="bi ' . $c['icon'] . '" aria-hidden="true"></i>' . h($c['label']) . '</span>';
}

/** Dane organizacji na dokumencie oferty. */
function crm_offer_org_block(): array {
    return [
        'name'  => org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''),
        'adres' => org_setting('org_adres'),
        'nip'   => org_setting('org_nip'),
        'krs'   => org_setting('org_krs'),
        'regon' => org_setting('org_regon'),
        'email' => org_setting('org_email'),
        'tel'   => org_setting('org_telefon') ?: org_setting('org_tel'),
        'www'   => org_setting('org_www'),
    ];
}

/**
 * Kolory i logo fundacji na dokumencie.
 * Źródło: ustawienia organizacji (te same, z których korzysta reszta systemu),
 * bo dokument oferty ma wyglądać jak resztą systemu, a nie mieć własną paletę.
 */
function crm_offer_brand(): array {
    static $b = null;
    if ($b !== null) return $b;

    require_once __DIR__ . '/branding.php';
    $base = function_exists('branding_load')
        ? branding_load()
        : ['primary' => '', 'sidebar' => '', 'logo_url' => '', 'org_name' => ''];

    // Kolor dokumentu: własne ustawienie modułu, a domyślnie zieleń marki CRM
    // (--crm-primary z assets/css/crm-module.css). Świadomie NIE bierzemy
    // volunteer_color — to kolor panelu wolontariusza, nie identyfikacja fundacji.
    $primary = crm_offer_setting('crm_offer_brand_color', '');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) $primary = '#2E844A';
    $dark    = function_exists('color_darken') ? color_darken($primary, 35) : $primary;
    $logo    = org_setting('org_logo');
    $file    = $logo ? dirname(__DIR__) . '/assets/logo/' . $logo : '';
    if ($file && !is_file($file)) $file = '';

    $b = [
        'primary'   => $primary,
        'dark'      => $dark,
        'soft'      => function_exists('color_rgba') ? color_rgba($primary, 0.08) : '#F3F6FB',
        'on_dark'   => function_exists('color_contrast_text') ? color_contrast_text($primary) : '#ffffff',
        'logo_file' => $file,
        'logo_url'  => $base['logo_url'] ?? '',
        'org_name'  => $base['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : ''),
    ];
    return $b;
}

/**
 * NRB → czytelny zapis IBAN, tak samo jak format_iban_pl() w admin/org_settings.php:
 * 'PL' + 26 cyfr = 28 znaków dzielonych na grupy po 4 (PL78 1600 1462 …).
 */
function crm_offer_iban(string $nrb): string {
    $n = preg_replace('/\D/', '', $nrb);
    if (strlen($n) !== 26) return $nrb;
    return implode(' ', str_split('PL' . $n, 4));
}

/**
 * Rachunki bankowe organizacji (settings.org_rachunki_bankowe to JSON) w kolejności
 * przydatności dla danego rodzaju działalności — rachunek z opisem wskazującym na
 * działalność odpłatną / szkolenia / przychody idzie pierwszy.
 */
function crm_offer_bank_accounts(string $funding = 'odplatna', string $currency = 'PLN'): array {
    $raw  = org_setting('org_rachunki_bankowe');
    $list = $raw ? (json_decode($raw, true) ?: []) : [];
    if (!is_array($list)) return [];

    $accounts = [];
    foreach ($list as $a) {
        if (!is_array($a) || empty($a['nrb'])) continue;
        $accounts[] = [
            'nrb'    => (string)$a['nrb'],
            'iban'   => crm_offer_iban((string)$a['nrb']),
            'waluta' => (string)($a['waluta'] ?? 'PLN'),
            'nazwa'  => (string)($a['nazwa'] ?? ''),
            'bank'   => (string)($a['bank'] ?? ''),
            'opis'   => (string)($a['opis'] ?? ''),
        ];
    }
    if (!$accounts) return [];

    $wanted = in_array($funding, ['odplatna', 'gospodarcza', 'mieszane'], true)
        ? ['odpłat', 'odplat', 'szkole', 'przychod', 'rozlicz']
        : ['dotacj', 'projekt', 'darow', 'sponsor'];

    usort($accounts, static function (array $x, array $y) use ($wanted, $currency): int {
        $score = static function (array $a) use ($wanted, $currency): int {
            $s = 0;
            $opis = mb_strtolower($a['opis']);
            foreach ($wanted as $w) { if (str_contains($opis, $w)) { $s -= 10; break; } }
            if ($a['waluta'] !== '' && $a['waluta'] !== $currency) $s += 5;
            return $s;
        };
        return $score($x) <=> $score($y);
    });
    return $accounts;
}

/**
 * Renderuje treść dokumentu oferty (bez <html>). Używane przez wydruk, PDF,
 * publiczną stronę klienta oraz podgląd w CRM.
 *
 * $opt: ['pdf'=>bool, 'internal'=>bool]  internal = pokaż notatki wewnętrzne
 */
function crm_offer_document_html(array $full, array $opt = []): string {
    $pdf      = !empty($opt['pdf']);
    $internal = !empty($opt['internal']);
    $cur      = (string)$full['currency'];
    $org      = crm_offer_org_block();
    $sel      = (int)($full['selected_variant_id'] ?? 0);

    $brand = crm_offer_brand();
    // W PDF-ie mpdf czyta obrazek ze ścieżki lokalnej, w przeglądarce z URL-a
    $logo_src = $pdf ? $brand['logo_file'] : $brand['logo_url'];

    $o = '';
    $o .= '<table class="of-head" width="100%"><tr>';
    $o .= '<td style="vertical-align:top">';
    if ($logo_src) {
        $o .= '<img class="of-logo" src="' . h($logo_src) . '" alt="' . h($org['name']) . '">';
    }
    $o .= '<div class="of-org">' . h($org['name']) . '</div>';
    if ($org['adres']) $o .= '<div class="of-small">' . nl2br(h($org['adres'])) . '</div>';
    $meta = array_filter([
        $org['nip']   ? 'NIP ' . $org['nip'] : '',
        $org['krs']   ? 'KRS ' . $org['krs'] : '',
        $org['regon'] ? 'REGON ' . $org['regon'] : '',
    ]);
    if ($meta) $o .= '<div class="of-small">' . h(implode(' · ', $meta)) . '</div>';
    $contact_meta = array_filter([$org['email'], $org['tel'], $org['www']]);
    if ($contact_meta) $o .= '<div class="of-small">' . h(implode(' · ', $contact_meta)) . '</div>';
    $o .= '</td>';
    $o .= '<td style="vertical-align:top;text-align:right">';
    $o .= '<div class="of-doc-title">OFERTA</div>';
    $o .= '<div class="of-doc-nr">' . h($full['offer_number']) . '</div>';
    $o .= '<div class="of-small">Data: ' . h(date('d.m.Y', strtotime((string)$full['created_at']))) . '</div>';
    if (!empty($full['valid_until'])) {
        $o .= '<div class="of-small">Ważna do: <strong>' . h(date('d.m.Y', strtotime((string)$full['valid_until']))) . '</strong></div>';
    }
    if ((int)($full['revision'] ?? 1) > 1) $o .= '<div class="of-small">Wersja: ' . (int)$full['revision'] . '</div>';
    $o .= '</td></tr></table>';
    $o .= '<div class="of-rule"></div>';

    // Odbiorca
    $o .= '<table class="of-parties" width="100%"><tr><td style="vertical-align:top;width:60%">';
    $o .= '<div class="of-label">Oferta dla</div>';
    $o .= '<div class="of-client">' . h($full['contact_name']) . '</div>';
    if (!empty($full['contact_org']) && $full['contact_org'] !== $full['contact_name']) {
        $o .= '<div class="of-small">' . h($full['contact_org']) . '</div>';
    }
    if (!empty($full['contact_address'])) $o .= '<div class="of-small">' . nl2br(h($full['contact_address'])) . '</div>';
    if (!empty($full['contact_nip']))     $o .= '<div class="of-small">NIP: ' . h($full['contact_nip']) . '</div>';
    if (!empty($full['contact_email']))   $o .= '<div class="of-small">' . h($full['contact_email']) . '</div>';
    $o .= '</td><td style="vertical-align:top">';
    $o .= '<div class="of-label">Osoba prowadząca</div>';
    $o .= '<div class="of-small"><strong>' . h($full['owner_name'] ?: $full['author_name']) . '</strong></div>';
    if ($org['email']) $o .= '<div class="of-small">' . h($org['email']) . '</div>';
    $o .= '<div class="of-label" style="margin-top:8px">Termin płatności</div>';
    $o .= '<div class="of-small">' . (int)$full['payment_terms_days'] . ' dni od doręczenia faktury / rachunku</div>';
    $o .= '</td></tr></table>';

    $o .= '<h1 class="of-title">' . h($full['title']) . '</h1>';
    if (!empty($full['intro'])) $o .= '<div class="of-intro">' . nl2br(h($full['intro'])) . '</div>';

    // Warianty
    $multi = count($full['variants'] ?? []) > 1;
    foreach (($full['variants'] ?? []) as $v) {
        $is_sel = $sel && (int)$v['id'] === $sel;
        $cls = 'of-variant' . ($is_sel ? ' of-variant--sel' : '') . ((int)$v['is_recommended'] === 1 ? ' of-variant--rec' : '');
        $o .= '<div class="' . $cls . '">';
        $o .= '<div class="of-vhead">';
        if ($multi) $o .= '<span class="of-vcode">' . h($v['code']) . '</span>';
        $o .= '<span class="of-vname">' . h($v['name']) . '</span>';
        if ((int)$v['is_recommended'] === 1) $o .= '<span class="of-badge of-badge--rec">rekomendowany</span>';
        if ($is_sel) $o .= '<span class="of-badge of-badge--sel">wybrany przez klienta</span>';
        $o .= '</div>';
        if (!empty($v['description'])) $o .= '<div class="of-vdesc">' . nl2br(h($v['description'])) . '</div>';

        $o .= '<table class="of-items" width="100%"><thead><tr>'
            . '<th style="width:26px">#</th><th>Pozycja</th><th style="width:70px">Ilość</th>'
            . '<th style="width:90px">Cena netto</th><th style="width:52px">Rabat</th>'
            . '<th style="width:48px">VAT</th><th style="width:92px">Wartość netto</th>'
            . '<th style="width:96px">Wartość brutto</th></tr></thead><tbody>';
        $i = 0;
        foreach (($v['items'] ?? []) as $it) {
            $i++;
            $vr = CRM_OFFER_VAT_RATES[$it['vat_rate']] ?? ['label' => $it['vat_rate']];
            $o .= '<tr' . ((int)$it['is_optional'] === 1 ? ' class="of-opt"' : '') . '>';
            $o .= '<td>' . $i . '</td><td><strong>' . h($it['name']) . '</strong>';
            if ((int)$it['is_optional'] === 1) $o .= ' <span class="of-badge">opcja</span>';
            if (!empty($it['description'])) $o .= '<div class="of-small">' . nl2br(h($it['description'])) . '</div>';
            if (!empty($it['vat_basis']))   $o .= '<div class="of-small">Podstawa zwolnienia VAT: ' . h($it['vat_basis']) . '</div>';
            if ($internal && !empty($it['merit_note'])) $o .= '<div class="of-small of-int">Merytorycznie: ' . h($it['merit_note']) . '</div>';
            $o .= '</td>';
            $o .= '<td>' . h(crm_offer_qty((float)$it['qty']) . ' ' . $it['unit']) . '</td>';
            $o .= '<td class="of-num">' . h(number_format((float)$it['unit_net'], 2, ',', ' ')) . '</td>';
            $o .= '<td class="of-num">' . ((float)$it['discount_pct'] > 0 ? h(rtrim(rtrim(number_format((float)$it['discount_pct'], 2, ',', ' '), '0'), ',')) . '%' : '—') . '</td>';
            $o .= '<td>' . h($vr['label']) . '</td>';
            $o .= '<td class="of-num">' . h(number_format((float)$it['line_net'], 2, ',', ' ')) . '</td>';
            $o .= '<td class="of-num"><strong>' . h(number_format((float)$it['line_gross'], 2, ',', ' ')) . '</strong></td>';
            $o .= '</tr>';
        }
        if (!$i) $o .= '<tr><td colspan="8" class="of-small">Brak pozycji.</td></tr>';
        $o .= '</tbody><tfoot><tr>';
        $o .= '<td colspan="6" class="of-num of-label">Razem (bez pozycji opcjonalnych)</td>';
        $o .= '<td class="of-num">' . h(number_format((float)$v['total_net'], 2, ',', ' ')) . '</td>';
        $o .= '<td class="of-num of-total">' . h(number_format((float)$v['total_gross'], 2, ',', ' ')) . '</td>';
        $o .= '</tr>';
        if ((float)$v['total_vat'] > 0) {
            $o .= '<tr><td colspan="6" class="of-num of-small">w tym VAT</td>'
                . '<td colspan="2" class="of-num of-small">' . h(crm_offer_money((float)$v['total_vat'], $cur)) . '</td></tr>';
        }
        $o .= '</tfoot></table>';
        $o .= '<div class="of-vsum">Wartość wariantu: <strong>' . h(crm_offer_money((float)$v['total_gross'], $cur))
            . '</strong> brutto (' . h(crm_offer_money((float)$v['total_net'], $cur)) . ' netto)</div>';
        $o .= '</div>';
    }

    if ((float)($full['discount_pct'] ?? 0) > 0) {
        $o .= '<div class="of-note"><strong>Rabat ogólny:</strong> '
            . h(rtrim(rtrim(number_format((float)$full['discount_pct'], 2, ',', ' '), '0'), ',')) . '%'
            . (!empty($full['discount_reason']) ? ' — ' . h($full['discount_reason']) : '')
            . ' (uwzględniony w cenach powyżej)</div>';
    }

    // Zwolnienia VAT / kwalifikacja działalności
    $vat_codes = [];
    foreach (($full['variants'] ?? []) as $v) {
        foreach (($v['items'] ?? []) as $it) $vat_codes[$it['vat_rate']] = true;
    }
    if (isset($vat_codes['zw']) || isset($vat_codes['np'])) {
        $o .= '<div class="of-note of-note--vat">';
        if (isset($vat_codes['zw'])) $o .= 'Część pozycji objęta zwolnieniem z VAT (art. 43 ustawy o podatku od towarów i usług). ';
        if (isset($vat_codes['np'])) $o .= 'Część pozycji nie podlega opodatkowaniu VAT. ';
        $o .= 'Świadczenie realizowane w ramach: <strong>' . h(CRM_OFFER_FUNDING[$full['funding_source']] ?? $full['funding_source']) . '</strong>.';
        $o .= '</div>';
    }

    if (!empty($full['delivery_terms'])) {
        $o .= '<div class="of-sect"><div class="of-label">Warunki realizacji</div><div>' . nl2br(h($full['delivery_terms'])) . '</div></div>';
    }
    if (!empty($full['terms'])) {
        $o .= '<div class="of-sect"><div class="of-label">Warunki oferty</div><div>' . nl2br(h($full['terms'])) . '</div></div>';
    }
    if (!empty($full['statutory_note'])) {
        $o .= '<div class="of-sect"><div class="of-label">Zgodność z celami statutowymi</div><div>'
            . nl2br(h($full['statutory_note'])) . '</div></div>';
    }
    if ($internal && !empty($full['accounting_note'])) {
        $o .= '<div class="of-sect of-int"><div class="of-label">Opis merytoryczny dla księgowości</div><div>'
            . nl2br(h($full['accounting_note'])) . '</div></div>';
    }
    if ($internal && !empty($full['notes_internal'])) {
        $o .= '<div class="of-sect of-int"><div class="of-label">Notatki wewnętrzne (nie dla klienta)</div><div>'
            . nl2br(h($full['notes_internal'])) . '</div></div>';
    }

    if (crm_offer_requires_confirmation($full)) {
        $conf = crm_offer_confirmation((int)$full['id']);
        $o .= '<div class="of-confirm">';
        if ($conf) {
            $o .= '<strong>Oferta potwierdzona przez klienta.</strong><br>'
                . h((CRM_OFFER_CONFIRM_METHODS[$conf['method']]['label'] ?? $conf['method']))
                . ' · ' . h($conf['confirmed_name'] ?: '—')
                . ' · ' . h(date('d.m.Y H:i', strtotime((string)$conf['confirmed_at'])));
        } else {
            $o .= '<strong>Wymagane potwierdzenie oferty.</strong> Oferta skierowana do osoby fizycznej — '
                . 'realizacja rozpocznie się po potwierdzeniu warunków przez Zamawiającego. '
                . 'Do tego czasu dokument stanowi wyłącznie propozycję i nie tworzy zobowiązania po żadnej ze stron.';
        }
        $o .= '</div>';
    }

    $accounts = crm_offer_bank_accounts((string)$full['funding_source'], $cur);
    if ($accounts) {
        // Pierwszy rachunek = właściwy dla tego rodzaju działalności (patrz
        // crm_offer_bank_accounts); pozostałe podajemy tylko dla walut innych niż oferta.
        $show = [$accounts[0]];
        foreach (array_slice($accounts, 1) as $a) {
            if ($a['waluta'] !== '' && $a['waluta'] !== $cur) $show[] = $a;
        }
        $o .= '<div class="of-sect of-pay"><div class="of-label">Dane do płatności</div>';
        foreach ($show as $a) {
            $o .= '<div class="of-acct"><span class="of-nrb">' . h($a['iban']) . '</span>';
            $line = array_filter([$a['bank'], $a['waluta'], $a['opis']]);
            if ($line) $o .= '<span class="of-small"> · ' . h(implode(' · ', $line)) . '</span>';
            if ($a['nazwa'] !== '' && $a['nazwa'] !== $org['name']) {
                $o .= '<div class="of-small">Odbiorca: ' . h($a['nazwa']) . '</div>';
            }
            $o .= '</div>';
        }
        $o .= '<div class="of-small">Płatność na podstawie faktury / rachunku, termin '
            . (int)$full['payment_terms_days'] . ' dni. W tytule prosimy podać numer oferty '
            . h($full['offer_number']) . '.</div>';
        $o .= '</div>';
    }
    $footer = crm_offer_setting('crm_offer_footer', '');
    if ($footer !== '') $o .= '<div class="of-footer">' . nl2br(h($footer)) . '</div>';

    return $o;
}

/**
 * Pliki fontów marki (Lato = tekst, Montserrat = nagłówki) w assets/fonts.
 * Gdy ich nie ma, PDF używa DejaVu Sans — dokument dalej się generuje, tylko
 * bez firmowej typografii. Nazwy plików zgodne z paczkami z Google Fonts.
 */
function crm_offer_pdf_fontdata(): array {
    $dir = dirname(__DIR__) . '/assets/fonts';
    if (!is_dir($dir)) return ['dir' => '', 'data' => []];

    $families = [
        'lato' => ['R' => 'Lato-Regular.ttf', 'B' => 'Lato-Bold.ttf',
                   'I' => 'Lato-Italic.ttf',  'BI' => 'Lato-BoldItalic.ttf'],
        'montserrat' => ['R' => 'Montserrat-Regular.ttf', 'B' => 'Montserrat-Bold.ttf',
                         'I' => 'Montserrat-Italic.ttf',  'BI' => 'Montserrat-BoldItalic.ttf'],
    ];
    $data = [];
    foreach ($families as $name => $faces) {
        if (!is_file($dir . '/' . $faces['R'])) continue;   // bez odmiany podstawowej nie rejestrujemy
        $entry = [];
        foreach ($faces as $style => $file) {
            if (is_file($dir . '/' . $file)) $entry[$style] = $file;
        }
        $data[$name] = $entry;
    }
    return ['dir' => $dir, 'data' => $data];
}

/** Rodziny fontów do CSS. $pdf=true → nazwy zarejestrowane w mpdf. */
function crm_offer_font_stacks(bool $pdf = false): array {
    if (!$pdf) {
        return [
            'body' => "'Lato','Segoe UI',-apple-system,BlinkMacSystemFont,Roboto,sans-serif",
            'head' => "'Montserrat','Lato','Segoe UI',sans-serif",
        ];
    }
    $have = crm_offer_pdf_fontdata()['data'];
    return [
        'body' => isset($have['lato'])       ? 'lato'       : 'dejavusans',
        'head' => isset($have['montserrat']) ? 'montserrat' : (isset($have['lato']) ? 'lato' : 'dejavusans'),
    ];
}

/** Link do fontów Google — dla stron HTML (wydruk, strona klienta). */
function crm_offer_font_link(): string {
    return '<link rel="preconnect" href="https://fonts.googleapis.com">'
         . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
         . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?'
         . 'family=Lato:ital,wght@0,400;0,700;1,400&family=Montserrat:wght@600;700;800&display=swap">';
}

/**
 * Arkusz stylów dokumentu — wspólny dla wydruku, PDF i strony klienta.
 * Kolory pochodzą z ustawień organizacji (crm_offer_brand()), więc dokument
 * jest w barwach fundacji, a nie w zaszytej palecie.
 */
function crm_offer_document_css(bool $for_pdf = false): string {
    $b  = crm_offer_brand();
    $f  = crm_offer_font_stacks($for_pdf);
    $pr = $b['primary'];
    $dk = $b['dark'];
    $sf = $b['soft'];

    return <<<CSS
.of-doc { color:#111827; font-size:11pt; line-height:1.45; font-family:{$f['body']}; }
.of-doc h1, .of-doc h2, .of-doc .of-doc-title, .of-doc .of-org,
.of-doc .of-vname, .of-doc .of-label { font-family:{$f['head']}; }
.of-head { padding-bottom:6px; }
.of-logo { max-height:44px; max-width:210px; margin-bottom:6px; }
.of-rule { height:3px; background:{$pr}; margin:0 0 14px; }
.of-org { font-weight:700; font-size:13pt; color:{$dk}; }
.of-doc-title { font-size:17pt; font-weight:800; letter-spacing:.06em; color:{$pr}; }
.of-doc-nr { font-family:monospace; font-size:11pt; font-weight:700; color:{$dk}; }
.of-small { font-size:8.5pt; color:#6B7280; }
.of-int { color:#7C3AED; }
.of-label { font-size:7.5pt; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#6B7280; margin-bottom:2px; }
.of-parties { margin-bottom:14px; }
.of-client { font-weight:700; font-size:11.5pt; color:{$dk}; }
.of-title { font-size:14pt; font-weight:700; margin:6px 0 6px; color:{$dk}; }
.of-intro { margin-bottom:12px; }
.of-variant { border:1px solid #E5E7EB; border-radius:6px; padding:10px 12px; margin-bottom:12px; }
.of-variant--rec { border-color:{$pr}; }
.of-variant--sel { border-color:{$dk}; background:{$sf}; }
.of-vhead { display:flex; align-items:center; gap:8px; margin-bottom:4px; }
.of-vcode { display:inline-block; width:22px; height:22px; line-height:22px; text-align:center; border-radius:4px; background:{$pr}; color:{$b['on_dark']}; font-weight:700; font-size:9pt; }
.of-vname { font-weight:700; font-size:11.5pt; color:{$dk}; }
.of-vdesc { font-size:9.5pt; color:#374151; margin-bottom:6px; }
.of-badge { display:inline-block; padding:1px 6px; border-radius:10px; background:#F3F4F6; color:#4B5563; font-size:7.5pt; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
.of-badge--rec { background:{$sf}; color:{$dk}; }
.of-badge--sel { background:{$sf}; color:{$dk}; }
.of-items { border-collapse:collapse; width:100%; margin-top:6px; }
.of-items th { background:{$sf}; border-bottom:1px solid {$pr}; font-size:8pt; text-transform:uppercase; letter-spacing:.04em; color:{$dk}; padding:4px 5px; text-align:left; }
.of-items td { border-bottom:1px solid #F3F4F6; padding:5px; font-size:9.5pt; vertical-align:top; }
.of-items tfoot td { border-top:1px solid {$pr}; border-bottom:none; font-size:9.5pt; padding-top:6px; }
.of-num { text-align:right; white-space:nowrap; }
.of-total { font-weight:800; font-size:11pt; color:{$dk}; }
.of-opt td { background:#FCFCFD; color:#6B7280; }
.of-vsum { text-align:right; margin-top:6px; font-size:10.5pt; }
.of-sect { margin:10px 0; }
.of-note { background:#F9FAFB; border-left:3px solid #9CA3AF; padding:7px 10px; font-size:9.5pt; margin:8px 0; }
.of-note--vat { border-left-color:{$pr}; background:{$sf}; }
.of-confirm { border:1px dashed #EA580C; background:#FFF7ED; padding:9px 11px; font-size:9.5pt; margin:12px 0; }
.of-pay { background:{$sf}; border-radius:6px; padding:8px 11px; }
.of-acct { margin-bottom:3px; }
.of-nrb { font-family:monospace; font-weight:700; font-size:10pt; color:{$dk}; }
.of-footer { margin-top:16px; border-top:1px solid #E5E7EB; padding-top:8px; font-size:8.5pt; color:#6B7280; }
CSS;
}

/** PDF oferty jako string (mpdf) lub null przy błędzie. */
function crm_offer_pdf(int $offer_id, bool $internal = false): ?string {
    $full = crm_offer_full($offer_id);
    if (!$full) return null;
    try {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $tmp = UPLOAD_DIR . 'mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);

        // Fonty marki (Lato + Montserrat) rejestrujemy tylko, gdy pliki TTF są
        // w assets/fonts — inaczej mpdf zostaje przy DejaVu Sans.
        $fonts = crm_offer_pdf_fontdata();
        $stack = crm_offer_font_stacks(true);
        $cfg = [
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 16, 'margin_right' => 14, 'margin_top' => 14, 'margin_bottom' => 16,
            'default_font' => $stack['body'], 'tempDir' => $tmp,
        ];
        if ($fonts['data']) {
            $default = (new \Mpdf\Config\FontVariables())->getDefaults();
            $cfg['fontDir']  = array_merge((new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'], [$fonts['dir']]);
            $cfg['fontdata'] = $default['fontdata'] + $fonts['data'];
        }
        $mpdf = new \Mpdf\Mpdf($cfg);
        $brand = crm_offer_brand();
        $mpdf->SetTitle('Oferta ' . $full['offer_number']);
        $mpdf->SetAuthor(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER'));
        $mpdf->SetHTMLFooter(
            '<table width="100%" style="font-size:7.5pt;color:#9CA3AF;border-top:1px solid ' . $brand['primary'] . ';padding-top:3px">'
            . '<tr><td>' . h($brand['org_name']) . '</td>'
            . '<td style="text-align:right">' . h($full['offer_number']) . ' · strona {PAGENO}/{nbpg}</td></tr></table>'
        );
        $mpdf->WriteHTML('body{font-family:' . $stack['body'] . ';} ' . crm_offer_document_css(true), \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML('<div class="of-doc">' . crm_offer_document_html($full, ['pdf' => true, 'internal' => $internal]) . '</div>',
            \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } catch (\Throwable $e) {
        error_log('[crm_offer_pdf] ' . $e->getMessage());
        return null;
    }
}

/** Zapisuje PDF oferty na dysku i zwraca opis załącznika dla mail_queue_add(). */
function crm_offer_pdf_file(int $offer_id): ?array {
    $pdf = crm_offer_pdf($offer_id);
    if ($pdf === null) return null;
    $o   = crm_one("SELECT offer_number FROM crm_offers WHERE id=?", [$offer_id]);
    $dir = rtrim(UPLOAD_DIR, '/') . '/crm_offers';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($o['offer_number'] ?? ('oferta_' . $offer_id)));
    $path = $dir . '/' . $safe . '.pdf';
    if (@file_put_contents($path, $pdf) === false) return null;
    return ['path' => $path, 'name' => 'Oferta_' . $safe . '.pdf', 'mime' => 'application/pdf', 'size' => strlen($pdf)];
}
