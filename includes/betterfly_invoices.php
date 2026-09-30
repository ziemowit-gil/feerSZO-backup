<?php
/**
 * includes/betterfly_invoices.php — warstwa biznesowa integracji Betterfly.
 *
 * Odpowiada za:
 *   - mapowanie danych SZO (CRM / TI) na struktury API Betterfly,
 *   - rozliczanie modułu TI PER KURS (jeden kurs = osobna faktura / pozycja),
 *   - bramę uprawnień: moduł TI musi być włączony przez administratora,
 *   - synchronizację statusów płatności (Betterfly → SZO),
 *   - lokalny rejestr powiązań (tabela betterfly_invoices).
 *
 * Warstwa transportowa (OAuth, cURL, endpointy) jest w includes/betterfly.php.
 *
 * Uwaga księgowa: faktura w Betterfly odwołuje się do ISTNIEJĄCYCH obiektów —
 * PurchasingPartyId (kontrahent) i ProductId (produkt). Dlatego:
 *   - nabywcę mapujemy po NIP (find-or-create kontrahenta),
 *   - dla pozycji kursu używamy produktu skonfigurowanego przez admina
 *     (betterfly_ti_product_id, opcjonalnie nadpisanie per kurs), a tożsamość
 *     kursu przenosimy w opisie pozycji (ProductDescription). Nie tworzymy
 *     produktów „w ciemno", bo schemat POST /products zależy od wersji API.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/betterfly.php';

// ── Schemat lokalnego rejestru (idempotentna samonaprawa) ────────────────────

function betterfly_invoices_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS betterfly_invoices (
        id                    INTEGER PRIMARY KEY AUTOINCREMENT,
        source                TEXT    NOT NULL DEFAULT 'ti_course',  -- ti_course | crm
        course_id             INTEGER NOT NULL DEFAULT 0,            -- k30_ti_courses.id (0 dla CRM)
        client_id             INTEGER NOT NULL DEFAULT 0,            -- k30_clients.id
        crm_contact_id        INTEGER NOT NULL DEFAULT 0,            -- kontakt CRM (jeśli dotyczy)
        period_month          INTEGER NOT NULL DEFAULT 0,
        period_year           INTEGER NOT NULL DEFAULT 0,
        betterfly_customer_id INTEGER NOT NULL DEFAULT 0,
        betterfly_invoice_id  INTEGER NOT NULL DEFAULT 0,
        number                TEXT    NOT NULL DEFAULT '',
        net_total             REAL    NOT NULL DEFAULT 0,
        gross_total           REAL    NOT NULL DEFAULT 0,
        vat_total             REAL    NOT NULL DEFAULT 0,
        currency              TEXT    NOT NULL DEFAULT 'PLN',
        doc_status            INTEGER NOT NULL DEFAULT 0,            -- 0 bufor / 1 zatwierdzona
        payment_status        INTEGER NOT NULL DEFAULT 0,            -- 0 niezapłacona / 1 zapłacona / 2 częściowo
        last_error            TEXT    NOT NULL DEFAULT '',
        created_by            INTEGER,
        created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at            DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Zapobiega dublowaniu faktury dla tego samego kursu/klienta/okresu.
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_betterfly_inv_scope
        ON betterfly_invoices (source, course_id, client_id, period_month, period_year)");

    // Migracje (samonaprawa): powiązanie z obiegiem akceptacji EODoK i kierunek.
    foreach ([
        "ALTER TABLE betterfly_invoices ADD COLUMN direction       TEXT    NOT NULL DEFAULT 'sales'", // sales | purchase
        "ALTER TABLE betterfly_invoices ADD COLUMN edok_doc_id      INTEGER NOT NULL DEFAULT 0",        // edok_documents.id
        "ALTER TABLE betterfly_invoices ADD COLUMN approval_status  TEXT    NOT NULL DEFAULT ''",        // '' | pending | approved | rejected
        "ALTER TABLE betterfly_invoices ADD COLUMN reference_number TEXT    NOT NULL DEFAULT ''",        // numer obcy (zakup)
        "ALTER TABLE betterfly_invoices ADD COLUMN selling_party_id INTEGER NOT NULL DEFAULT 0",         // dostawca (zakup)
        "ALTER TABLE betterfly_invoices ADD COLUMN confirmed_at     DATETIME",
    ] as $sql) {
        try { db()->exec($sql); } catch (\Throwable $e) { /* kolumna już istnieje */ }
    }
    db()->exec("CREATE INDEX IF NOT EXISTS ix_betterfly_inv_edok ON betterfly_invoices (edok_doc_id)");
    // Faktury zakupu deduplikujemy po Id dokumentu Betterfly.
    db()->exec("CREATE INDEX IF NOT EXISTS ix_betterfly_inv_bfid ON betterfly_invoices (betterfly_invoice_id)");
}

// ── Brama uprawnień: moduł TI włączony przez administratora ───────────────────

/**
 * Rzuca BetterFlyException, jeśli fakturowanie TI przez Betterfly nie jest dostępne.
 *
 * Warunki:
 *   1. Integracja Betterfly aktywna       (settings: betterfly_enabled = '1'),
 *   2. Administrator włączył moduł TI      (settings: betterfly_ti_enabled = '1'),
 *   3. Wywołujący (kontekst web) ma prawo zapisu w obszarze TI (can_write('karty30')).
 *      W kontekście CLI/cron (brak sesji użytkownika) sprawdzamy tylko 1–2.
 */
function betterfly_ti_require(): void
{
    if (!BetterFlyClient::isEnabled()) {
        throw new BetterFlyException('Integracja Comarch Betterfly nie jest włączona.');
    }
    if (org_setting('betterfly_ti_enabled') !== '1') {
        throw new BetterFlyException('Fakturowanie modułu TI przez Betterfly nie zostało włączone przez administratora.');
    }
    // Uprawnienie użytkownika egzekwujemy tylko, gdy istnieje sesja (web).
    if (function_exists('current_user') && current_user() && function_exists('can_write')) {
        if (!can_write('karty30')) {
            throw new BetterFlyException('Brak uprawnień do fakturowania w module TI.');
        }
    }
}

// ── Rozliczanie PER KURS ─────────────────────────────────────────────────────

/**
 * Liczy rozliczenie klienta za KONKRETNY kurs w danym miesiącu.
 * Reguła godzin (spójna z includes/ti_hours_report.php): ceil(minuty / 60) PER LEKCJA,
 * tylko dla lekcji z odnotowaną obecnością (attended = 1). Stawka pochodzi z zapisu
 * kursanta na kurs (k30_ti_enrollments.hourly_rate).
 *
 * @return array{course_id:int,client_id:int,course_name:string,month:int,year:int,
 *               hours:float,hourly_rate:float,amount:float,lessons:int}
 */
function betterfly_ti_course_billing(int $course_id, int $client_id, int $month, int $year): array
{
    $course = db_one("SELECT id, name, no_invoice FROM k30_ti_courses WHERE id=?", [$course_id]);
    if (!$course) {
        throw new BetterFlyException("Kurs TI #{$course_id} nie istnieje.");
    }
    if ((int)($course['no_invoice'] ?? 0) === 1) {
        throw new BetterFlyException("Kurs \"{$course['name']}\" jest oznaczony jako wyłączony z fakturowania.");
    }

    $enr = db_one(
        "SELECT hourly_rate, hourly_rate_online FROM k30_ti_enrollments WHERE course_id=? AND client_id=?",
        [$course_id, $client_id]
    );
    if (!$enr) {
        throw new BetterFlyException("Klient #{$client_id} nie jest zapisany na kurs #{$course_id}.");
    }
    $rate = (float)$enr['hourly_rate'];

    // Lekcje kursu w danym miesiącu, na których klient był obecny.
    $rows = db_all(
        "SELECT s.id, s.duration_min, s.lesson_date, s.lesson_method
           FROM k30_ti_sessions s
           JOIN k30_ti_attendance a ON a.session_id = s.id AND a.client_id = ?
          WHERE s.course_id = ?
            AND a.attended = 1
            AND CAST(strftime('%m', s.lesson_date) AS INTEGER) = ?
            AND CAST(strftime('%Y', s.lesson_date) AS INTEGER) = ?",
        [$client_id, $course_id, $month, $year]
    );

    // Zmiany cen (includes/ti_price_changes.php) — stawka z dnia każdej lekcji i jej
    // trybu (online / stacjonarna), przy kilku stawkach w miesiącu osobne pozycje (rate_parts).
    require_once __DIR__ . '/ti_price_changes.php';
    $lessons = [];
    foreach ($rows as $r) {
        $lessons[] = ['date' => (string)$r['lesson_date'], 'hours' => (float)ceil(((int)$r['duration_min']) / 60), // ceil per lekcja
                      'session_id' => (int)$r['id'], 'online' => ti_session_is_online((string)$r['lesson_method'], $course_id)];
    }
    $bd    = ti_price_hourly_breakdown(['model' => 2, 'hourly_rate' => $rate, 'hourly_rate_online' => (float)($enr['hourly_rate_online'] ?? 0), 'amount' => 0.0],
                                       $course_id, $client_id, $lessons);
    $hours = (float)$bd['hours'];

    return [
        'course_id'   => $course_id,
        'client_id'   => $client_id,
        'course_name' => (string)$course['name'],
        'month'       => $month,
        'year'        => $year,
        'hours'       => $hours,
        'hourly_rate' => (float)$bd['rate'],
        'rate_parts'  => $bd['parts'] ?: [['rate' => (float)$bd['rate'], 'hours' => 0.0, 'amount' => 0.0]],
        'amount'      => (float)$bd['amount'],
        'lessons'     => count($rows),
    ];
}

/**
 * Pozycje faktury z rozliczenia kursu — jedna na każdą stawkę w miesiącu
 * (zmiana ceny w trakcie miesiąca daje dwie pozycje z różnymi cenami).
 */
function betterfly_ti_rate_lines(array $b, int $course_id, int $month, int $year, array $opts): array
{
    $parts = $b['rate_parts'] ?? [['rate' => (float)$b['hourly_rate'], 'hours' => (float)$b['hours']]];
    $multi = count($parts) > 1;
    $out   = [];
    foreach ($parts as $p) {
        $hoursTxt = rtrim(rtrim(number_format((float)$p['hours'], 2, '.', ''), '0'), '.');
        $out[] = [
            'ProductId'            => betterfly_ti_product_id($course_id),
            'Quantity'             => (float)$p['hours'],
            'ProductCurrencyPrice' => betterfly_ti_unit_net((float)$p['rate']),
            'ProductDescription'   => sprintf('Zajęcia TI — %s, %02d/%d (%s godz.%s)', $b['course_name'], $month, $year, $hoursTxt,
                                        $multi ? ', stawka ' . number_format((float)$p['rate'], 2, ',', ' ') . ' zł/godz.' : ''),
            'VatRateId'            => (int)($opts['vat_rate_id'] ?? 0) ?: (int)org_setting('betterfly_default_vat_rate_id'),
        ];
    }
    return $out;
}

// ── Mapowanie danych nabywcy ─────────────────────────────────────────────────

/**
 * Buduje payload kontrahenta Betterfly ze znormalizowanych danych nabywcy.
 * Wejście (klucze opcjonalne poza name):
 *   name, nip, email, phone, street, building, flat, postcode, city, country,
 *   is_company (bool; domyślnie: firma gdy podano NIP).
 */
function betterfly_customer_payload(array $buyer): array
{
    $name = trim((string)($buyer['name'] ?? ''));
    if ($name === '') {
        throw new BetterFlyException('Nabywca wymaga nazwy.');
    }
    $nip        = preg_replace('/\D+/', '', (string)($buyer['nip'] ?? '')) ?? '';
    $isCompany  = array_key_exists('is_company', $buyer) ? (bool)$buyer['is_company'] : ($nip !== '');

    $payload = [
        'Name'        => $name,
        'CustomerType'=> $isCompany ? 1 : 0, // 1 = podmiot gospodarczy, 0 = osoba fizyczna
    ];
    if ($nip !== '')                             $payload['CustomerTaxNumber'] = $nip;
    if (trim((string)($buyer['email'] ?? '')))   $payload['Mail']             = trim((string)$buyer['email']);
    if (trim((string)($buyer['phone'] ?? '')))   $payload['PhoneNumber']      = trim((string)$buyer['phone']);
    if (trim((string)($buyer['country'] ?? ''))) $payload['CountryCode']      = trim((string)$buyer['country']);

    $addr = array_filter([
        'Street'         => trim((string)($buyer['street']   ?? '')),
        'BuildingNumber' => trim((string)($buyer['building'] ?? '')),
        'FlatNumber'     => trim((string)($buyer['flat']     ?? '')),
        'PostalCode'     => trim((string)($buyer['postcode'] ?? '')),
        'City'           => trim((string)($buyer['city']     ?? '')),
    ], fn($v) => $v !== '');
    if ($addr) $payload['Address'] = $addr;

    return $payload;
}

/** Buduje dane nabywcy z kontaktu CRM (crm_contact_persons / crm.php). */
function betterfly_buyer_from_crm_contact(array $c): array
{
    return [
        'name'     => (string)($c['imie_nazwisko'] ?? $c['name'] ?? ''),
        'nip'      => (string)($c['nip'] ?? ''),
        'email'    => (string)($c['email'] ?? ''),
        'phone'    => (string)($c['telefon'] ?? $c['phone'] ?? ''),
        'street'   => trim((string)($c['addr_street'] ?? $c['adres'] ?? '')),
        'building' => (string)($c['addr_house'] ?? ''),
        'flat'     => (string)($c['addr_flat'] ?? ''),
        'postcode' => (string)($c['addr_postal'] ?? ''),
        'city'     => (string)($c['addr_city'] ?? ''),
    ];
}

/**
 * Buduje dane nabywcy dla kursanta TI (k30_clients). Kursant to zwykle osoba
 * fizyczna bez NIP — jeśli fakturę pokrywa płatnik z NIP (rodzic/podmiot),
 * przekaż jego dane w $override (name/nip/...).
 */
function betterfly_buyer_from_ti_client(int $client_id, array $override = []): array
{
    $c = db_one("SELECT id, name, email, phone, address FROM k30_clients WHERE id=?", [$client_id]);
    if (!$c) {
        throw new BetterFlyException("Kursant TI #{$client_id} nie istnieje.");
    }
    $buyer = [
        'name'   => (string)$c['name'],
        'email'  => (string)($c['email'] ?? ''),
        'phone'  => (string)($c['phone'] ?? ''),
        'street' => trim((string)($c['address'] ?? '')),
    ];
    return array_merge($buyer, array_filter($override, fn($v) => $v !== null && $v !== ''));
}

// ── Mapowanie pozycji i budowa faktury ───────────────────────────────────────

/**
 * Zwraca ProductId dla pozycji kursu. Kolejność:
 *   1) nadpisanie per kurs: settings betterfly_ti_product_course_{course_id},
 *   2) domyślny produkt TI:  settings betterfly_ti_product_id.
 * Rzuca wyjątek, gdy admin nie skonfigurował żadnego — świadomie nie tworzymy
 * produktów automatycznie (schemat POST /products zależy od wersji API).
 */
function betterfly_ti_product_id(int $course_id): int
{
    $override = (int)org_setting('betterfly_ti_product_course_' . $course_id);
    if ($override > 0) return $override;

    $default = (int)org_setting('betterfly_ti_product_id');
    if ($default > 0) return $default;

    throw new BetterFlyException(
        'Brak produktu Betterfly dla pozycji kursu — ustaw betterfly_ti_product_id '
        . '(lub betterfly_ti_product_course_' . $course_id . ') w ustawieniach.'
    );
}

/**
 * Buduje payload faktury sprzedaży.
 *
 * @param int   $purchasingPartyId  Id kontrahenta (nabywcy) w Betterfly
 * @param array $items              pozycje: [{ProductId, Quantity, ProductCurrencyPrice, ProductDescription?, VatRateId?}]
 * @param array $opts               issue_date, sales_date, payment_deadline (Y-m-d),
 *                                  payment_type_id, description, invoice_type, payment_status
 */
function betterfly_invoice_payload(int $purchasingPartyId, array $items, array $opts = []): array
{
    if ($purchasingPartyId <= 0) {
        throw new BetterFlyException('Faktura wymaga PurchasingPartyId (nabywcy).');
    }
    if (!$items) {
        throw new BetterFlyException('Faktura wymaga co najmniej jednej pozycji.');
    }

    $paymentTypeId = (int)($opts['payment_type_id'] ?? (int)org_setting('betterfly_default_payment_type_id'));
    if ($paymentTypeId <= 0) {
        throw new BetterFlyException('Brak formy płatności — ustaw betterfly_default_payment_type_id lub przekaż payment_type_id.');
    }

    $iso = static fn(?string $d): ?string => $d ? (new DateTimeImmutable($d))->format('Y-m-d\T00:00:00P') : null;

    $today   = date('Y-m-d');
    $payload = [
        'PurchasingPartyId' => $purchasingPartyId,
        'PaymentTypeId'     => $paymentTypeId,
        'PaymentStatus'     => (int)($opts['payment_status'] ?? 0),
        'InvoiceType'       => (int)($opts['invoice_type'] ?? 0),
        'IssueDate'         => $iso($opts['issue_date']       ?? $today),
        'SalesDate'         => $iso($opts['sales_date']       ?? $today),
        'PaymentDeadline'   => $iso($opts['payment_deadline'] ?? $today),
        'Items'             => [],
    ];
    if (!empty($opts['description'])) {
        $payload['Description'] = (string)$opts['description'];
    }
    // Rachunek bankowy: numer (IBAN/NRB) lub Id konta zdefiniowanego w Betterfly.
    if (!empty($opts['bank_account_number'])) {
        $payload['BankAccountNumber'] = (string)$opts['bank_account_number'];
    }
    if (!empty($opts['bank_account_id'])) {
        $payload['BankAccountId'] = (int)$opts['bank_account_id'];
    }

    $defaultVat = (int)org_setting('betterfly_default_vat_rate_id');
    foreach ($items as $it) {
        $line = [
            'ProductId'            => (int)$it['ProductId'],
            'Quantity'             => (float)$it['Quantity'],
            'ProductCurrencyPrice' => round((float)$it['ProductCurrencyPrice'], 2),
        ];
        $line['ProductDescription'] = (string)($it['ProductDescription'] ?? '');
        $vat = (int)($it['VatRateId'] ?? $defaultVat);
        if ($vat > 0) $line['VatRateId'] = $vat;
        $payload['Items'][] = $line;
    }

    return $payload;
}

/**
 * Przelicza cenę brutto na netto, gdy stawki TI są brutto
 * (settings betterfly_ti_price_is_gross = '1', procent VAT w betterfly_default_vat_percent).
 * Betterfly przyjmuje ProductCurrencyPrice jako cenę netto.
 */
function betterfly_ti_unit_net(float $rate): float
{
    if (org_setting('betterfly_ti_price_is_gross') !== '1') {
        return round($rate, 2);
    }
    $vatPct = (float)(org_setting('betterfly_default_vat_percent') ?: 23);
    return round($rate / (1 + $vatPct / 100), 2);
}

/**
 * Rachunek bankowy (NRB/IBAN) dla faktury TI kursanta. Priorytet ustala istniejąca
 * k30_ti_client_payment(): rachunek indywidualny kursanta → domyślny kursu →
 * rachunek organizacji „dla TI" (fallback). Zwraca numer bez spacji ('' gdy brak).
 */
function betterfly_ti_bank_account(int $client_id): string
{
    if (!function_exists('k30_ti_client_payment')) {
        $k = __DIR__ . '/karty30.php';
        if (is_file($k)) { try { require_once $k; } catch (\Throwable $e) { /* ignore */ } }
    }
    if (!function_exists('k30_ti_client_payment')) return '';
    try {
        $acc = (string)(k30_ti_client_payment($client_id)['account'] ?? '');
    } catch (\Throwable $e) {
        return '';
    }
    return preg_replace('/\s+/', '', $acc) ?? '';
}

/** Czy Betterfly jest wybranym backendem faktur TI (settings: invoices_backend='betterfly'). */
function betterfly_is_ti_backend(): bool
{
    return trim(org_setting('invoices_backend')) === 'betterfly';
}

/**
 * Wystawia fakturę Betterfly z wiersza rozliczenia k30_ti_billing.
 * Wiersz z course_id>0 → faktura za kurs; course_id=0/NULL → zbiorcza per kursant
 * (osobna pozycja na każdy kurs). Mapuje istniejący przycisk „Wystaw fakturę".
 */
function betterfly_issue_ti_from_billing(int $billing_id, array $opts = []): array
{
    betterfly_invoices_migrate();
    $b = db_one("SELECT * FROM k30_ti_billing WHERE id=?", [$billing_id]);
    if (!$b) {
        throw new BetterFlyException("Rozliczenie #{$billing_id} nie istnieje.");
    }
    $client = (int)$b['client_id'];
    $month  = (int)$b['month'];
    $year   = (int)$b['year'];
    $course = (int)($b['course_id'] ?? 0);

    return $course > 0
        ? betterfly_issue_ti_course_invoice($course, $client, $month, $year, $opts)
        : betterfly_issue_ti_client_invoice($client, $month, $year, $opts);
}

/**
 * Znajduje rekord betterfly_invoices odpowiadający wierszowi rozliczenia
 * (do pokazania statusu na liście rozliczeń TI). Null gdy brak.
 */
function betterfly_link_for_billing(array $billing_row): ?array
{
    betterfly_invoices_migrate();
    return db_one(
        "SELECT * FROM betterfly_invoices
          WHERE direction='sales' AND client_id=? AND course_id=? AND period_month=? AND period_year=?",
        [(int)$billing_row['client_id'], (int)($billing_row['course_id'] ?? 0),
         (int)$billing_row['month'], (int)$billing_row['year']]
    );
}

// ── Wystawianie faktury za KURS (TI → Betterfly) ─────────────────────────────

/**
 * Wystawia fakturę za jeden kurs TI dla jednego kursanta za dany miesiąc.
 *
 * Kroki:
 *   1. weryfikacja bramy (moduł TI włączony przez admina + uprawnienia),
 *   2. rozliczenie per kurs (godziny × stawka),
 *   3. find-or-create kontrahenta po NIP,
 *   4. budowa i wysłanie faktury (jedna pozycja = ten kurs),
 *   5. opcjonalne zatwierdzenie, zapis do lokalnego rejestru.
 *
 * @param array $opts  buyer_override (dane płatnika z NIP), confirm (bool),
 *                     payment_type_id, vat_rate_id, payment_deadline, uid,
 *                     allow_zero (dopuść 0 godzin)
 * @return array{ok:bool, local_id:int, betterfly_invoice_id:int, number:string,
 *               customer_id:int, billing:array, error?:string}
 */
function betterfly_issue_ti_course_invoice(int $course_id, int $client_id, int $month, int $year, array $opts = []): array
{
    betterfly_invoices_migrate();
    betterfly_ti_require();

    $uid = (int)($opts['uid'] ?? (function_exists('current_user') && current_user() ? (int)current_user()['id'] : 0));

    $billing = betterfly_ti_course_billing($course_id, $client_id, $month, $year);
    if ($billing['hours'] <= 0 && empty($opts['allow_zero'])) {
        throw new BetterFlyException(
            "Brak godzin do zafakturowania dla kursu \"{$billing['course_name']}\" ({$month}/{$year})."
        );
    }

    // Nabywca: kursant + ewentualne dane płatnika (np. rodzic/podmiot z NIP).
    $buyer   = betterfly_buyer_from_ti_client($client_id, (array)($opts['buyer_override'] ?? []));
    $client  = BetterFlyClient::fromSettings();

    // Rejestruj rekord wcześnie, by zapisać ewentualny błąd (last_error).
    $localId = betterfly_upsert_local([
        'source'       => 'ti_course',
        'course_id'    => $course_id,
        'client_id'    => $client_id,
        'period_month' => $month,
        'period_year'  => $year,
        'created_by'   => $uid,
    ]);

    try {
        $customerId = $client->ensureCustomer(betterfly_customer_payload($buyer));

        $items = betterfly_ti_rate_lines($billing, $course_id, $month, $year, $opts);
        $itemsNet = 0.0;
        foreach ($items as $it) $itemsNet += $it['ProductCurrencyPrice'] * $it['Quantity'];

        $payload = betterfly_invoice_payload($customerId, $items, [
            'payment_type_id'     => $opts['payment_type_id']  ?? null,
            'payment_deadline'    => $opts['payment_deadline'] ?? null,
            'bank_account_number' => (string)($opts['bank_account_number'] ?? '') ?: betterfly_ti_bank_account($client_id),
            'description'         => 'Faktura za kurs TI: ' . $billing['course_name'],
        ]);

        $invoiceId = $client->createInvoice($payload);

        // Numer nadawany automatycznie — dociągnij z API.
        $fetched   = $client->getInvoice($invoiceId);
        $number    = (string)($fetched['Number'] ?? '');
        $docStatus = (int)($fetched['Status'] ?? 0);

        // Kierunek akceptacji: gdy włączona brama EODoK, faktura zostaje w buforze,
        // a zatwierdzenie (confirm) nastąpi po finalnej akceptacji obiegu.
        $viaEdok   = !empty($opts['via_edok']) || betterfly_edok_gate_sales();
        $approval  = '';
        $edokDocId = 0;

        if ($viaEdok) {
            $edokDocId = betterfly_edok_create_doc([
                'number'      => $number,
                'party_name'  => (string)($buyer['name'] ?? ''),
                'party_nip'   => (string)($buyer['nip'] ?? ''),
                'issue_date'  => date('Y-m-d'),
                'net'         => round($itemsNet, 2),
                'gross'       => (float)($fetched['GrossTotal'] ?? 0),
                'vat'         => (float)($fetched['VatTotal'] ?? 0),
                'currency'    => (string)($fetched['CurrencyCode'] ?? 'PLN'),
                'description' => 'Faktura sprzedaży za kurs TI: ' . $billing['course_name']
                                 . sprintf(' (%02d/%d)', $month, $year),
            ], 'sales');
            $approval = 'pending';
        } elseif (!empty($opts['confirm'])) {
            $client->confirmInvoice($invoiceId);
            $fetched   = $client->getInvoice($invoiceId) ?: $fetched;
            $number    = (string)($fetched['Number'] ?? $number);
            $docStatus = (int)($fetched['Status'] ?? 1);
        }

        betterfly_upsert_local([
            'id'                    => $localId,
            'direction'             => 'sales',
            'betterfly_customer_id' => $customerId,
            'betterfly_invoice_id'  => $invoiceId,
            'number'                => $number,
            'net_total'             => (float)($fetched['NetTotal']   ?? round($itemsNet, 2)),
            'gross_total'           => (float)($fetched['GrossTotal'] ?? 0),
            'vat_total'             => (float)($fetched['VatTotal']   ?? 0),
            'currency'              => (string)($fetched['CurrencyCode'] ?? 'PLN'),
            'doc_status'            => $docStatus,
            'payment_status'        => (int)($fetched['PaymentStatus'] ?? 0),
            'edok_doc_id'           => $edokDocId,
            'approval_status'       => $approval,
            'last_error'            => '',
        ]);

        return [
            'ok'                   => true,
            'local_id'             => $localId,
            'betterfly_invoice_id' => $invoiceId,
            'number'               => $number,
            'customer_id'          => $customerId,
            'via_edok'             => $viaEdok,
            'edok_doc_id'          => $edokDocId,
            'billing'              => $billing,
        ];
    } catch (BetterFlyException $e) {
        betterfly_upsert_local(['id' => $localId, 'last_error' => $e->getMessage()]);
        error_log('[betterfly] Wystawienie faktury TI (kurs ' . $course_id . ', klient ' . $client_id . ') nieudane: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Wystawia JEDNĄ fakturę dla kursanta za dany miesiąc z OSOBNĄ POZYCJĄ na każdy
 * kurs, na który jest zapisany (rozliczanie per kurs = osobna pozycja faktury).
 *
 * Kursy bez godzin w okresie oraz oznaczone „nie fakturuj" są pomijane.
 *
 * @param array $opts  buyer_override, confirm, payment_type_id, vat_rate_id,
 *                     payment_deadline, uid, via_edok
 * @return array{ok:bool, local_id:int, betterfly_invoice_id:int, number:string,
 *               customer_id:int, via_edok:bool, edok_doc_id:int, courses:array, total_net:float}
 */
function betterfly_issue_ti_client_invoice(int $client_id, int $month, int $year, array $opts = []): array
{
    betterfly_invoices_migrate();
    betterfly_ti_require();

    $uid = (int)($opts['uid'] ?? (function_exists('current_user') && current_user() ? (int)current_user()['id'] : 0));

    // Kursy kursanta z policzonym rozliczeniem — po jednej pozycji na kurs.
    $enrolled = db_all("SELECT course_id FROM k30_ti_enrollments WHERE client_id=? ORDER BY course_id", [$client_id]);
    $lines    = [];
    $courses  = [];
    foreach ($enrolled as $e) {
        $cid = (int)$e['course_id'];
        try {
            $b = betterfly_ti_course_billing($cid, $client_id, $month, $year);
        } catch (BetterFlyException $ex) {
            continue; // kurs wyłączony z fakturowania itp.
        }
        if ($b['hours'] <= 0) continue; // brak godzin w okresie — pomiń pozycję

        foreach (betterfly_ti_rate_lines($b, $cid, $month, $year, $opts) as $ln) $lines[] = $ln;
        $courses[] = $b;
    }

    if (!$lines) {
        throw new BetterFlyException("Brak kursów z godzinami do zafakturowania dla kursanta #{$client_id} ({$month}/{$year}).");
    }

    $buyer  = betterfly_buyer_from_ti_client($client_id, (array)($opts['buyer_override'] ?? []));
    $client = BetterFlyClient::fromSettings();

    // Rekord zbiorczy: course_id=0 (rozliczenie łączne kursanta) — dedup per okres.
    $localId = betterfly_upsert_local([
        'source'       => 'ti_course',
        'direction'    => 'sales',
        'course_id'    => 0,
        'client_id'    => $client_id,
        'period_month' => $month,
        'period_year'  => $year,
        'created_by'   => $uid,
    ]);

    try {
        $customerId = $client->ensureCustomer(betterfly_customer_payload($buyer));
        $totalNet   = 0.0;
        foreach ($lines as $l) $totalNet += $l['ProductCurrencyPrice'] * $l['Quantity'];

        $payload = betterfly_invoice_payload($customerId, $lines, [
            'payment_type_id'     => $opts['payment_type_id']  ?? null,
            'payment_deadline'    => $opts['payment_deadline'] ?? null,
            'bank_account_number' => (string)($opts['bank_account_number'] ?? '') ?: betterfly_ti_bank_account($client_id),
            'description'         => sprintf('Faktura za zajęcia TI (%d kurs%s), %02d/%d',
                                          count($lines), count($lines) === 1 ? '' : 'y', $month, $year),
        ]);
        $invoiceId = $client->createInvoice($payload);
        $fetched   = $client->getInvoice($invoiceId);
        $number    = (string)($fetched['Number'] ?? '');
        $docStatus = (int)($fetched['Status'] ?? 0);

        $viaEdok   = !empty($opts['via_edok']) || betterfly_edok_gate_sales();
        $approval  = '';
        $edokDocId = 0;

        if ($viaEdok) {
            $courseNames = implode(', ', array_map(fn($c) => $c['course_name'], $courses));
            $edokDocId = betterfly_edok_create_doc([
                'number'      => $number,
                'party_name'  => (string)($buyer['name'] ?? ''),
                'party_nip'   => (string)($buyer['nip'] ?? ''),
                'issue_date'  => date('Y-m-d'),
                'net'         => round($totalNet, 2),
                'gross'       => (float)($fetched['GrossTotal'] ?? 0),
                'vat'         => (float)($fetched['VatTotal'] ?? 0),
                'currency'    => (string)($fetched['CurrencyCode'] ?? 'PLN'),
                'description' => sprintf('Faktura sprzedaży TI (%02d/%d) — kursy: %s', $month, $year, $courseNames),
            ], 'sales');
            $approval = 'pending';
        } elseif (!empty($opts['confirm'])) {
            $client->confirmInvoice($invoiceId);
            $fetched   = $client->getInvoice($invoiceId) ?: $fetched;
            $number    = (string)($fetched['Number'] ?? $number);
            $docStatus = (int)($fetched['Status'] ?? 1);
        }

        betterfly_upsert_local([
            'id'                    => $localId,
            'betterfly_customer_id' => $customerId,
            'betterfly_invoice_id'  => $invoiceId,
            'number'                => $number,
            'net_total'             => (float)($fetched['NetTotal']   ?? round($totalNet, 2)),
            'gross_total'           => (float)($fetched['GrossTotal'] ?? 0),
            'vat_total'             => (float)($fetched['VatTotal']   ?? 0),
            'currency'              => (string)($fetched['CurrencyCode'] ?? 'PLN'),
            'doc_status'            => $docStatus,
            'payment_status'        => (int)($fetched['PaymentStatus'] ?? 0),
            'edok_doc_id'           => $edokDocId,
            'approval_status'       => $approval,
            'last_error'            => '',
        ]);

        return [
            'ok'                   => true,
            'local_id'             => $localId,
            'betterfly_invoice_id' => $invoiceId,
            'number'               => $number,
            'customer_id'          => $customerId,
            'via_edok'             => $viaEdok,
            'edok_doc_id'          => $edokDocId,
            'courses'              => $courses,
            'total_net'            => round($totalNet, 2),
        ];
    } catch (BetterFlyException $e) {
        betterfly_upsert_local(['id' => $localId, 'last_error' => $e->getMessage()]);
        error_log('[betterfly] Faktura zbiorcza TI (klient ' . $client_id . ') nieudana: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Wystawia fakturę CRM z dowolnych pozycji (np. z oferty/usługi).
 *
 * @param array $buyer  dane nabywcy (patrz betterfly_customer_payload / betterfly_buyer_from_crm_contact)
 * @param array $items  pozycje: [{ProductId, Quantity, ProductCurrencyPrice, ProductDescription?, VatRateId?}]
 * @param array $opts   crm_contact_id, confirm, payment_type_id, payment_deadline, description, uid
 * @return array{ok:bool, local_id:int, betterfly_invoice_id:int, number:string, customer_id:int}
 */
function betterfly_issue_crm_invoice(array $buyer, array $items, array $opts = []): array
{
    betterfly_invoices_migrate();
    if (!BetterFlyClient::isEnabled()) {
        throw new BetterFlyException('Integracja Comarch Betterfly nie jest włączona.');
    }
    if (function_exists('current_user') && current_user() && function_exists('can_write')) {
        if (!can_write('crm')) {
            throw new BetterFlyException('Brak uprawnień do fakturowania w module CRM.');
        }
    }

    $uid    = (int)($opts['uid'] ?? (function_exists('current_user') && current_user() ? (int)current_user()['id'] : 0));
    $client = BetterFlyClient::fromSettings();

    $customerId = $client->ensureCustomer(betterfly_customer_payload($buyer));
    $payload    = betterfly_invoice_payload($customerId, $items, $opts);
    $invoiceId  = $client->createInvoice($payload);

    if (!empty($opts['confirm'])) {
        $client->confirmInvoice($invoiceId);
    }
    $fetched = $client->getInvoice($invoiceId);

    $localId = betterfly_upsert_local([
        'source'                => 'crm',
        'crm_contact_id'        => (int)($opts['crm_contact_id'] ?? 0),
        'betterfly_customer_id' => $customerId,
        'betterfly_invoice_id'  => $invoiceId,
        'number'                => (string)($fetched['Number'] ?? ''),
        'net_total'             => (float)($fetched['NetTotal']   ?? 0),
        'gross_total'           => (float)($fetched['GrossTotal'] ?? 0),
        'vat_total'             => (float)($fetched['VatTotal']   ?? 0),
        'currency'              => (string)($fetched['CurrencyCode'] ?? 'PLN'),
        'doc_status'            => (int)($fetched['Status'] ?? (!empty($opts['confirm']) ? 1 : 0)),
        'payment_status'        => (int)($fetched['PaymentStatus'] ?? 0),
        'created_by'            => $uid,
    ]);

    return [
        'ok'                   => true,
        'local_id'             => $localId,
        'betterfly_invoice_id' => $invoiceId,
        'number'               => (string)($fetched['Number'] ?? ''),
        'customer_id'          => $customerId,
    ];
}

// ── Pobieranie / synchronizacja statusów (Betterfly → SZO) ───────────────────

/**
 * Synchronizuje jedną fakturę z lokalnego rejestru: pobiera aktualny stan z
 * Betterfly i aktualizuje status dokumentu oraz płatności.
 *
 * @return array zaktualizowany rekord lokalny
 */
function betterfly_sync_invoice(int $local_id): array
{
    betterfly_invoices_migrate();
    $row = db_one("SELECT * FROM betterfly_invoices WHERE id=?", [$local_id]);
    if (!$row) {
        throw new BetterFlyException("Rekord faktury #{$local_id} nie istnieje.");
    }
    if ((int)$row['betterfly_invoice_id'] <= 0) {
        throw new BetterFlyException("Rekord #{$local_id} nie ma powiązanej faktury Betterfly.");
    }

    $client  = BetterFlyClient::fromSettings();
    $fetched = $client->getInvoice((int)$row['betterfly_invoice_id']);
    if (!$fetched) {
        throw new BetterFlyException("Nie udało się pobrać faktury Betterfly #{$row['betterfly_invoice_id']}.");
    }

    betterfly_upsert_local([
        'id'             => $local_id,
        'number'         => (string)($fetched['Number']       ?? $row['number']),
        'net_total'      => (float)($fetched['NetTotal']      ?? $row['net_total']),
        'gross_total'    => (float)($fetched['GrossTotal']    ?? $row['gross_total']),
        'vat_total'      => (float)($fetched['VatTotal']      ?? $row['vat_total']),
        'currency'       => (string)($fetched['CurrencyCode'] ?? $row['currency']),
        'doc_status'     => (int)($fetched['Status']        ?? $row['doc_status']),
        'payment_status' => (int)($fetched['PaymentStatus'] ?? $row['payment_status']),
        'last_error'     => '',
    ]);

    return db_one("SELECT * FROM betterfly_invoices WHERE id=?", [$local_id]) ?? [];
}

/**
 * Synchronizuje wszystkie faktury nieoznaczone jako w pełni opłacone.
 * Nadaje się do crona. Zwraca liczbę zaktualizowanych i listę błędów.
 *
 * @return array{synced:int, errors:array<int,string>}
 */
function betterfly_sync_pending(): array
{
    betterfly_invoices_migrate();
    $rows   = db_all("SELECT id FROM betterfly_invoices WHERE betterfly_invoice_id > 0 AND payment_status <> 1");
    $synced = 0;
    $errors = [];
    foreach ($rows as $r) {
        try {
            betterfly_sync_invoice((int)$r['id']);
            $synced++;
        } catch (BetterFlyException $e) {
            $errors[(int)$r['id']] = $e->getMessage();
            error_log('[betterfly] Synchronizacja faktury #' . $r['id'] . ' nieudana: ' . $e->getMessage());
        }
    }
    return ['synced' => $synced, 'errors' => $errors];
}

/**
 * Ponawia zatwierdzenie faktur sprzedaży zaakceptowanych w EODoK, które nie
 * zostały jeszcze potwierdzone w Betterfly (np. gdy hook zawiódł na sieci).
 * Sieć bezpieczeństwa dla crona.
 *
 * @return array{confirmed:int, errors:array<int,string>}
 */
function betterfly_confirm_approved_pending(): array
{
    betterfly_invoices_migrate();
    $rows = db_all(
        "SELECT * FROM betterfly_invoices
          WHERE direction='sales' AND approval_status='approved'
            AND doc_status<>1 AND betterfly_invoice_id>0"
    );
    $confirmed = 0; $errors = [];
    foreach ($rows as $row) {
        try {
            _betterfly_confirm_sales_row($row);
            $confirmed++;
        } catch (\Throwable $e) {
            $errors[(int)$row['id']] = $e->getMessage();
            error_log('[betterfly] Ponowne zatwierdzenie faktury #' . $row['id'] . ' nieudane: ' . $e->getMessage());
        }
    }
    return ['confirmed' => $confirmed, 'errors' => $errors];
}

/** Czytelny status płatności (Betterfly PaymentStatus 0/1/2). */
function betterfly_payment_status_label(int $status): string
{
    return [0 => 'Niezapłacona', 1 => 'Zapłacona', 2 => 'Częściowo zapłacona'][$status] ?? 'Nieznany';
}

/**
 * Słownik stawek VAT Betterfly (pole „Rate"/VatRateId) → etykieta.
 * Zgodny z dokumentacją produktów: 9=23%, 8=8%, 7=5%, 6=4%, 2=0%, 1=zw, 0=np.
 */
function betterfly_vat_rate_options(): array
{
    return [
        9 => '23%',
        8 => '8%',
        7 => '5%',
        6 => '4%',
        2 => '0%',
        1 => 'zw. (zwolniony)',
        0 => 'np. (nie podlega)',
    ];
}

// ── Integracja z obiegiem akceptacji EODoK ───────────────────────────────────

/** Czy faktury sprzedaży mają przechodzić akceptację EODoK przed zatwierdzeniem. */
function betterfly_edok_gate_sales(): bool
{
    return org_setting('betterfly_edok_gate_sales') === '1';
}

/** Czy pobrane faktury zakupu mają wchodzić do obiegu EODoK. */
function betterfly_edok_gate_purchase(): bool
{
    return org_setting('betterfly_edok_gate_purchase') === '1';
}

/**
 * Tworzy dokument w obiegu EODoK dla faktury Betterfly (wzorzec: edok_ksef_create_doc).
 * Zwraca id dokumentu edok_documents (0, gdy moduł EODoK niedostępny).
 *
 * @param array  $inv        number, party_name, party_nip, issue_date, net, vat, gross,
 *                          currency, description, file_path?, reference_number?
 * @param string $direction 'sales' (przychód, faktura_sprzedazy) | 'purchase' (wydatek, faktura_vat)
 */
function betterfly_edok_create_doc(array $inv, string $direction): int
{
    $edok = __DIR__ . '/edok.php';
    if (!is_file($edok)) {
        error_log('[betterfly] Moduł EODoK niedostępny — pomijam utworzenie dokumentu obiegu.');
        return 0;
    }
    require_once $edok;
    if (!function_exists('edok_next_number') || !function_exists('edok_log')) {
        error_log('[betterfly] EODoK: brak wymaganych funkcji — pomijam.');
        return 0;
    }
    if (function_exists('edok_migrate')) edok_migrate();

    $isPurchase = ($direction === 'purchase');
    $kierunek   = $isPurchase ? 'wydatek' : 'przychod';
    $typ        = $isPurchase ? 'faktura_vat' : 'faktura_sprzedazy';
    $party      = trim((string)($inv['party_name'] ?? ''));
    $title      = trim((string)($inv['number'] ?? '') . ($party !== '' ? ' — ' . $party : ''))
                  ?: ('Faktura Betterfly ' . ($inv['reference_number'] ?? $inv['number'] ?? ''));

    $doc_id = db_insert('edok_documents', [
        'number'           => edok_next_number(),
        'title'            => $title,
        'typ_dokumentu'    => $typ,
        'kierunek'         => $kierunek,
        'description'      => (string)($inv['description'] ?? ('Faktura ' . ($isPurchase ? 'zakupu' : 'sprzedaży') . ' zaimportowana z Comarch Betterfly.')),
        'kontrahent_nazwa' => $party,
        'kontrahent_nip'   => (string)($inv['party_nip'] ?? ''),
        'nr_faktury'       => (string)($inv['reference_number'] ?? $inv['number'] ?? ''),
        'data_wystawienia' => ($inv['issue_date'] ?? '') ?: null,
        'kwota_netto'      => (string)($inv['net']   ?? ''),
        'kwota_vat'        => (string)($inv['vat']   ?? ''),
        'kwota_brutto'     => (string)($inv['gross'] ?? ''),
        'waluta'           => (string)($inv['currency'] ?? 'PLN') ?: 'PLN',
        'file_path'        => (string)($inv['file_path'] ?? ''),
        'status'           => 'w_obiegu',
        'created_by'       => null,
        'creator_name'     => 'Comarch Betterfly (auto-import)',
        'created_at'       => date('Y-m-d H:i:s'),
        'updated_at'       => date('Y-m-d H:i:s'),
    ]);

    edok_log($doc_id, 'submit', '', 'draft', 'w_obiegu',
        'Auto-import z Comarch Betterfly (' . ($isPurchase ? 'faktura zakupu' : 'faktura sprzedaży')
        . '). Dokument oczekuje na akceptację obiegu; po finalnym zatwierdzeniu '
        . ($isPurchase ? 'zostanie skierowany do zapłaty.' : 'faktura zostanie zatwierdzona w Betterfly.'));

    return (int)$doc_id;
}

/**
 * Hook wywoływany po finalnej akceptacji dokumentu w EODoK (edok.php, blok
 * status='zaakceptowany'). Best-effort: NIE rzuca wyjątków (akceptacja obiegu
 * jest już zapisana i nie może zostać cofnięta błędem integracji).
 *
 * Sprzedaż: zatwierdza (confirm) fakturę w Betterfly.
 * Zakup:    oznacza jako zaakceptowaną do zapłaty (API zakupu jest tylko do odczytu).
 */
function betterfly_on_edok_approved(int $edok_doc_id): void
{
    try {
        betterfly_invoices_migrate();
        $row = db_one("SELECT * FROM betterfly_invoices WHERE edok_doc_id=?", [$edok_doc_id]);
        if (!$row) return; // dokument EODoK niepowiązany z Betterfly

        db_update('betterfly_invoices', ['approval_status' => 'approved'], (int)$row['id']);

        if (($row['direction'] ?? 'sales') === 'sales' && (int)$row['doc_status'] !== 1 && (int)$row['betterfly_invoice_id'] > 0) {
            _betterfly_confirm_sales_row($row);
        }
    } catch (\Throwable $e) {
        error_log('[betterfly] Hook EODoK (dok #' . $edok_doc_id . ') nieudany: ' . $e->getMessage());
    }
}

/**
 * Ręczne zatwierdzenie faktury sprzedaży powiązanej z dokumentem EODoK
 * (przycisk „Zatwierdź w Betterfly"). W odróżnieniu od hooka — rzuca wyjątek,
 * żeby UI mógł pokazać błąd.
 *
 * @return array zaktualizowany rekord betterfly_invoices
 */
function betterfly_confirm_from_edok(int $edok_doc_id): array
{
    betterfly_invoices_migrate();
    $row = db_one("SELECT * FROM betterfly_invoices WHERE edok_doc_id=?", [$edok_doc_id]);
    if (!$row) {
        throw new BetterFlyException('Ten dokument EODoK nie jest powiązany z fakturą Betterfly.');
    }
    if (($row['direction'] ?? 'sales') !== 'sales') {
        throw new BetterFlyException('Faktury zakupu nie zatwierdza się w Betterfly (API zakupu jest tylko do odczytu).');
    }
    if ((int)$row['betterfly_invoice_id'] <= 0) {
        throw new BetterFlyException('Brak powiązanej faktury Betterfly do zatwierdzenia.');
    }
    if ((int)$row['doc_status'] === 1) {
        return $row; // już zatwierdzona — idempotentnie
    }
    _betterfly_confirm_sales_row($row);
    return db_one("SELECT * FROM betterfly_invoices WHERE id=?", [(int)$row['id']]) ?? [];
}

/** Wspólny rdzeń zatwierdzania faktury sprzedaży (hook + przycisk ręczny). */
function _betterfly_confirm_sales_row(array $row): void
{
    $client = BetterFlyClient::fromSettings();
    $client->confirmInvoice((int)$row['betterfly_invoice_id']);
    $fetched = $client->getInvoice((int)$row['betterfly_invoice_id']);

    betterfly_upsert_local([
        'id'             => (int)$row['id'],
        'number'         => (string)($fetched['Number']       ?? $row['number']),
        'net_total'      => (float)($fetched['NetTotal']      ?? $row['net_total']),
        'gross_total'    => (float)($fetched['GrossTotal']    ?? $row['gross_total']),
        'vat_total'      => (float)($fetched['VatTotal']      ?? $row['vat_total']),
        'currency'       => (string)($fetched['CurrencyCode'] ?? $row['currency']),
        'doc_status'     => (int)($fetched['Status'] ?? 1),
        'payment_status' => (int)($fetched['PaymentStatus'] ?? $row['payment_status']),
        'approval_status'=> 'approved',
        'confirmed_at'   => date('Y-m-d H:i:s'),
        'last_error'     => '',
    ]);
}

/**
 * Pobiera faktury ZAKUPU z Betterfly i wprowadza nowe do obiegu EODoK.
 * Deduplikacja po betterfly_invoice_id (direction='purchase').
 *
 * @param array $opts query (filtry API), limit
 * @return array{imported:int, skipped:int, errors:array<int,string>}
 */
function betterfly_import_purchase_invoices(array $opts = []): array
{
    betterfly_invoices_migrate();
    if (!BetterFlyClient::isEnabled()) {
        throw new BetterFlyException('Integracja Comarch Betterfly nie jest włączona.');
    }

    $client   = BetterFlyClient::fromSettings();
    $list     = $client->listPurchaseInvoices((array)($opts['query'] ?? []));
    $imported = 0; $skipped = 0; $errors = [];

    foreach ($list as $inv) {
        $bfId = (int)($inv['Id'] ?? 0);
        if ($bfId <= 0) { $skipped++; continue; }

        $exists = db_one(
            "SELECT id FROM betterfly_invoices WHERE direction='purchase' AND betterfly_invoice_id=?",
            [$bfId]
        );
        if ($exists) { $skipped++; continue; }

        try {
            $localId = betterfly_upsert_local([
                'source'               => 'crm',
                'direction'            => 'purchase',
                'betterfly_invoice_id' => $bfId,
                'selling_party_id'     => (int)($inv['SellingPartyId'] ?? 0),
                'number'               => (string)($inv['Number'] ?? ''),
                'reference_number'     => (string)($inv['ReferenceNumber'] ?? ''),
                'net_total'            => (float)($inv['NetTotal']   ?? 0),
                'gross_total'          => (float)($inv['GrossTotal'] ?? 0),
                'vat_total'            => (float)($inv['VatTotal']   ?? 0),
                'currency'             => (string)($inv['CurrencyCode'] ?? 'PLN'),
                'doc_status'           => (int)($inv['Status'] ?? 0),
                'payment_status'       => (int)($inv['PaymentStatus'] ?? 0),
                'approval_status'      => 'pending',
            ]);

            $edokDocId = betterfly_edok_create_doc([
                'number'           => (string)($inv['Number'] ?? ''),
                'reference_number' => (string)($inv['ReferenceNumber'] ?? ''),
                'party_name'       => (string)($inv['SellingParty']['Name'] ?? ''),
                'party_nip'        => (string)($inv['SellingParty']['CustomerTaxNumber'] ?? $inv['SellingParty']['Nip'] ?? ''),
                'issue_date'       => substr((string)($inv['IssueDate'] ?? ''), 0, 10) ?: null,
                'net'              => (float)($inv['NetTotal']   ?? 0),
                'vat'              => (float)($inv['VatTotal']   ?? 0),
                'gross'            => (float)($inv['GrossTotal'] ?? 0),
                'currency'         => (string)($inv['CurrencyCode'] ?? 'PLN'),
                'description'      => 'Faktura zakupu z Betterfly, nr obcy: ' . (string)($inv['ReferenceNumber'] ?? '—'),
            ], 'purchase');

            betterfly_upsert_local(['id' => $localId, 'edok_doc_id' => $edokDocId]);
            $imported++;
        } catch (\Throwable $e) {
            $errors[$bfId] = $e->getMessage();
            error_log('[betterfly] Import faktury zakupu #' . $bfId . ' nieudany: ' . $e->getMessage());
        }
    }

    return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

// ── Lokalny rejestr — upsert ─────────────────────────────────────────────────

/**
 * Wstawia lub aktualizuje rekord w betterfly_invoices. Przy braku 'id' próbuje
 * dopasować po unikalnym zakresie (source+course+client+okres) i zwraca istniejące id.
 */
function betterfly_upsert_local(array $data): int
{
    betterfly_invoices_migrate();

    if (empty($data['id'])) {
        // Deduplikacja po zakresie dla ścieżki TI (CRM zwykle wstawia nowy rekord).
        if (($data['source'] ?? '') === 'ti_course') {
            $existing = db_one(
                "SELECT id FROM betterfly_invoices
                  WHERE source='ti_course' AND course_id=? AND client_id=? AND period_month=? AND period_year=?",
                [(int)($data['course_id'] ?? 0), (int)($data['client_id'] ?? 0),
                 (int)($data['period_month'] ?? 0), (int)($data['period_year'] ?? 0)]
            );
            if ($existing) $data['id'] = (int)$existing['id'];
        }
    }

    if (!empty($data['id'])) {
        $id = (int)$data['id'];
        unset($data['id']);
        if ($data) db_update('betterfly_invoices', $data, $id);
        return $id;
    }

    return db_insert('betterfly_invoices', $data);
}
