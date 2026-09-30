<?php
/**
 * includes/p24.php — Integracja płatności Przelewy24 (REST API v3.2).
 *
 * Konfiguracja w settings (prefix p24_): merchant_id, pos_id, api_key (klucz
 * raportowy — Basic Auth), crc (klucz do liczenia podpisu sha384), environment
 * (sandbox|prod), currency. Płatności śledzone w tabeli p24_payments (powiązanie
 * z dowolnym źródłem przez source_type + source_id — np. rozliczenie TI),
 * dokładnie ten sam wzorzec co includes/payu.php i includes/stripe.php.
 *
 * Przelewy24 wymaga DODATKOWEGO kroku względem PayU/Stripe: samo przyjście
 * powiadomienia (webhook) nie wystarcza, żeby uznać płatność za rozliczoną —
 * trzeba jawnie wywołać transaction/verify i sprawdzić status "success"
 * (patrz p24_mark_paid()). Wywołania API przez stream_context (bez zależności
 * curl) — spójnie z resztą bramek.
 */

// ── Ustawienia ────────────────────────────────────────────────────────────────
function p24_setting(string $key, string $default = ''): string {
    static $cache = [];
    $fk = 'p24_' . $key;
    if (!array_key_exists($fk, $cache)) {
        try {
            $r = db_one("SELECT value FROM settings WHERE key_=?", [$fk]);
            $cache[$fk] = $r['value'] ?? $default;
        } catch (\Throwable $e) { $cache[$fk] = $default; }
    }
    return $cache[$fk] !== '' ? $cache[$fk] : $default;
}

function p24_save_setting(string $key, string $value): void {
    $fk = 'p24_' . $key;
    try {
        db()->prepare("INSERT INTO settings(key_,value) VALUES(?,?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
           ->execute([$fk, $value]);
    } catch (\Throwable $e) {
        $ex = db_one("SELECT key_ FROM settings WHERE key_=?", [$fk]);
        if ($ex) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $fk]);
        else     db()->prepare("INSERT INTO settings(key_,value) VALUES(?,?)")->execute([$fk, $value]);
    }
}

function p24_configured(): bool {
    return p24_setting('merchant_id') !== '' && p24_setting('pos_id') !== ''
        && p24_setting('api_key') !== '' && p24_setting('crc') !== '';
}

function p24_enabled(): bool {
    return p24_setting('enabled') === '1' && p24_configured();
}

function p24_currency(): string {
    return strtoupper(p24_setting('currency', 'PLN')) ?: 'PLN';
}

/** Domyślnie sandbox — świadomie, dopóki ktoś ręcznie nie przełączy na 'prod' w admin/p24_settings.php. */
function p24_environment(): string {
    return p24_setting('environment', 'sandbox') === 'prod' ? 'prod' : 'sandbox';
}

/** Bazowy URL API Przelewy24 zależnie od środowiska. */
function p24_base_url(): string {
    return p24_environment() === 'prod' ? 'https://secure.przelewy24.pl' : 'https://sandbox.przelewy24.pl';
}

// ── Migracja tabeli płatności ──────────────────────────────────────────────────
function p24_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS p24_payments (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            source_type      TEXT    NOT NULL DEFAULT '',   -- np. k30_ti_billing
            source_id        INTEGER NOT NULL DEFAULT 0,
            description      TEXT    NOT NULL DEFAULT '',
            amount_grosze    INTEGER NOT NULL DEFAULT 0,    -- kwota w groszach
            currency         TEXT    NOT NULL DEFAULT 'PLN',
            status           TEXT    NOT NULL DEFAULT 'pending', -- pending | paid | failed | expired
            session_id       TEXT    NOT NULL DEFAULT '',   -- nasz unikalny identyfikator (sessionId)
            order_id         TEXT    NOT NULL DEFAULT '',   -- orderId z powiadomienia P24
            token            TEXT    NOT NULL DEFAULT '',   -- token z transaction/register
            redirect_uri     TEXT    NOT NULL DEFAULT '',   -- URL do płatności (przekierowanie kupującego)
            customer_email   TEXT    NOT NULL DEFAULT '',
            created_by       INTEGER,
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
            paid_at          DATETIME
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_p24_pay_source  ON p24_payments(source_type, source_id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_p24_pay_session ON p24_payments(session_id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_p24_pay_order   ON p24_payments(order_id)");
    } catch (\Throwable $e) {}
}

// ── Wywołania API ────────────────────────────────────────────────────────────────
/** POST/PUT z Basic Auth (posId:apiKey) i treścią JSON. Zwraca tablicę zdekodowaną z odpowiedzi. */
function p24_api_call(string $method, string $path, array $payload): array {
    $auth = base64_encode(p24_setting('pos_id') . ':' . p24_setting('api_key'));
    $ctx  = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => "Authorization: Basic {$auth}\r\n"
                          . "Content-Type: application/json\r\n",
        'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'ignore_errors' => true,
        'timeout'       => 20,
    ]]);
    $raw = @file_get_contents(p24_base_url() . $path, false, $ctx);
    return json_decode($raw ?: '{}', true) ?? [];
}

/** Szybki test połączenia — próbuje pobrać testowy dostęp do konta (testAccess). */
function p24_test_connection(): array {
    try {
        $resp = p24_api_call('GET', '/api/v1/testAccess', []);
        if (($resp['data'] ?? null) === true) {
            return ['ok' => true, 'msg' => 'Połączenie działa (' . (p24_environment() === 'prod' ? 'produkcja' : 'sandbox') . ').'];
        }
        return ['ok' => false, 'msg' => $resp['error'] ?? 'Nie udało się potwierdzić dostępu — sprawdź merchant_id/pos_id/api_key.'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

/**
 * Tworzy zamówienie Przelewy24 (transaction/register) i zapisuje wiersz p24_payments.
 * @return array ['id'=>payment row id, 'url'=>redirect URL, 'token'=>...]
 */
function p24_create_order(string $source_type, int $source_id, float $amount_pln,
                          string $description, string $return_url, string $notify_url,
                          string $email = ''): array {
    p24_migrate();
    $grosze = (int) round($amount_pln * 100);
    if ($grosze < 1) throw new RuntimeException('Kwota płatności musi być większa od zera.');
    $currency    = p24_currency();
    $merchant_id = (int)p24_setting('merchant_id');
    $pos_id      = (int)p24_setting('pos_id');
    $crc         = p24_setting('crc');
    $session_id  = $source_type . '-' . $source_id . '-' . substr(bin2hex(random_bytes(8)), 0, 12);

    $pid = db_insert('p24_payments', [
        'source_type'    => $source_type,
        'source_id'      => $source_id,
        'description'    => mb_substr($description, 0, 300),
        'amount_grosze'  => $grosze,
        'currency'       => $currency,
        'status'         => 'pending',
        'session_id'     => $session_id,
        'customer_email' => $email,
        'created_by'     => function_exists('current_user') ? (current_user()['id'] ?? null) : null,
    ]);

    // Kolejność pól w JSON-ie do liczenia podpisu jest ISTOTNA — P24 sam liczy
    // hash w tej kolejności po swojej stronie (dokumentacja REST API v3.2).
    $sign = hash('sha384', json_encode([
        'sessionId' => $session_id,
        'merchantId'=> $merchant_id,
        'amount'    => $grosze,
        'currency'  => $currency,
        'crc'       => $crc,
    ], JSON_UNESCAPED_UNICODE));

    $payload = [
        'merchantId'  => $merchant_id,
        'posId'       => $pos_id,
        'sessionId'   => $session_id,
        'amount'      => $grosze,
        'currency'    => $currency,
        'description' => mb_substr($description, 0, 300) ?: 'Płatność',
        'email'       => $email !== '' ? $email : 'brak@' . 'feer.org.pl',
        'country'     => 'PL',
        'language'    => 'pl',
        'urlReturn'   => $return_url,
        'urlStatus'   => $notify_url,
        'sign'        => $sign,
    ];

    $resp  = p24_api_call('POST', '/api/v1/transaction/register', $payload);
    $token = (string)($resp['data']['token'] ?? '');

    if ($token === '') {
        db()->prepare("UPDATE p24_payments SET status='failed' WHERE id=?")->execute([$pid]);
        $msg = $resp['error'] ?? (is_array($resp['data'] ?? null) ? json_encode($resp['data']) : 'nie udało się zarejestrować transakcji');
        throw new RuntimeException('Przelewy24: ' . $msg);
    }

    $redirect = rtrim(p24_base_url(), '/') . '/trnRequest/' . $token;
    db()->prepare("UPDATE p24_payments SET token=?, redirect_uri=? WHERE id=?")
       ->execute([$token, $redirect, $pid]);

    return ['id' => $pid, 'url' => $redirect, 'token' => $token];
}

/**
 * Weryfikuje podpis powiadomienia Przelewy24 (pole "sign" w treści JSON).
 * Hash = SHA384(json_encode(['merchantId','posId','sessionId','amount','originAmount',
 * 'currency','orderId','methodId','statement','crc'])) — dokładna kolejność pól
 * wg dokumentacji P24 REST API v3.2.
 * @return bool true gdy podpis poprawny (lub brak klucza CRC — wtedy pomijamy weryfikację).
 */
function p24_verify_notification(array $body): bool {
    $crc = p24_setting('crc');
    if ($crc === '') return true; // brak klucza — weryfikacja wyłączona (zalecane: ustawić)
    $sign_recv = (string)($body['sign'] ?? '');
    if ($sign_recv === '') return false;
    $expected = hash('sha384', json_encode([
        'merchantId'   => $body['merchantId']    ?? null,
        'posId'        => $body['posId']         ?? null,
        'sessionId'    => $body['sessionId']      ?? '',
        'amount'       => $body['amount']         ?? 0,
        'originAmount' => $body['originAmount']   ?? ($body['amount'] ?? 0),
        'currency'     => $body['currency']       ?? '',
        'orderId'      => $body['orderId']        ?? 0,
        'methodId'     => $body['methodId']       ?? 0,
        'statement'    => $body['statement']      ?? '',
        'crc'          => $crc,
    ], JSON_UNESCAPED_UNICODE));
    return hash_equals($expected, strtolower($sign_recv));
}

/**
 * Krok wymagany przez Przelewy24: dopiero potwierdzenie przez transaction/verify
 * uznaje transakcję za rozliczoną — samo przyjęcie powiadomienia NIE wystarcza
 * (inaczej niż w Stripe/PayU, gdzie webhook sam w sobie jest wiążący).
 */
function p24_verify_transaction(int $payment_id): bool {
    $p = db_one("SELECT * FROM p24_payments WHERE id=?", [$payment_id]);
    if (!$p || $p['order_id'] === '') return false;
    $merchant_id = (int)p24_setting('merchant_id');
    $pos_id      = (int)p24_setting('pos_id');
    $crc         = p24_setting('crc');
    $sign = hash('sha384', json_encode([
        'sessionId' => $p['session_id'],
        'orderId'   => (int)$p['order_id'],
        'amount'    => (int)$p['amount_grosze'],
        'currency'  => $p['currency'],
        'crc'       => $crc,
    ], JSON_UNESCAPED_UNICODE));
    $resp = p24_api_call('PUT', '/api/v1/transaction/verify', [
        'merchantId' => $merchant_id,
        'posId'      => $pos_id,
        'sessionId'  => $p['session_id'],
        'amount'     => (int)$p['amount_grosze'],
        'currency'   => $p['currency'],
        'orderId'    => (int)$p['order_id'],
        'sign'       => $sign,
    ]);
    return ($resp['data']['status'] ?? '') === 'success';
}

/**
 * Przetwarza powiadomienie: zapisuje orderId, weryfikuje transakcję (transaction/verify)
 * i dopiero po jej potwierdzeniu księguje wpłatę. Idempotentne.
 */
function p24_mark_paid_from_notification(int $payment_id, string $order_id): void {
    $p = db_one("SELECT * FROM p24_payments WHERE id=?", [$payment_id]);
    if (!$p || $p['status'] === 'paid') return;
    if ($order_id !== '') {
        db()->prepare("UPDATE p24_payments SET order_id=? WHERE id=?")->execute([$order_id, $payment_id]);
    }
    if (!p24_verify_transaction($payment_id)) return; // niepotwierdzone — zostaw 'pending', spróbujemy przy reconcile
    p24_mark_paid($payment_id);
}

/**
 * Oznacza płatność jako opłaconą i propaguje status do źródła
 * (np. k30_ti_billing.status='paid'). Idempotentne. UWAGA: wołać dopiero
 * PO pozytywnej weryfikacji transaction/verify (p24_verify_transaction()) —
 * nie bezpośrednio z samego przyjęcia powiadomienia.
 */
function p24_mark_paid(int $payment_id): void {
    $p = db_one("SELECT * FROM p24_payments WHERE id=?", [$payment_id]);
    if (!$p || $p['status'] === 'paid') return;
    db()->prepare("UPDATE p24_payments SET status='paid', paid_at=datetime('now') WHERE id=?")->execute([$payment_id]);
    try {
        if ($p['source_type'] === 'k30_ti_billing' && (int)$p['source_id'] > 0) {
            $bill = db_one("SELECT client_id, COALESCE(course_id,0) AS course_id FROM k30_ti_billing WHERE id=?", [(int)$p['source_id']]);
            if ($bill) {
                require_once __DIR__ . '/ti_payments.php';
                ti_payment_add((int)$bill['client_id'], (float)$p['amount_grosze'] / 100, date('Y-m-d'),
                               'p24', 'Płatność Przelewy24', 'p24', (int)$p['source_id'], (int)$bill['course_id']);
            }
        }
        // Doładowanie portfela kursanta TI (source_id = client_id) — wpłata OGÓLNA
        // (course_id=0), automatycznie zużywana FIFO na kolejne należności za zajęcia.
        if ($p['source_type'] === 'k30_ti_wallet' && (int)$p['source_id'] > 0) {
            require_once __DIR__ . '/ti_payments.php';
            ti_payment_add((int)$p['source_id'], (float)$p['amount_grosze'] / 100, date('Y-m-d'),
                           'p24', 'Doładowanie portfela (Przelewy24)', 'p24', $payment_id, 0);
        }
        // Nadpłata do końca roku (powody podatkowe/księgowe) — księgowana identycznie
        // jak doładowanie portfela (wpłata ogólna), plus e-mail do adminów o FV.
        if ($p['source_type'] === 'k30_ti_wallet_year_end' && (int)$p['source_id'] > 0) {
            require_once __DIR__ . '/ti_payments.php';
            ti_payment_add((int)$p['source_id'], (float)$p['amount_grosze'] / 100, date('Y-m-d'),
                           'p24', 'Nadpłata do końca roku (Przelewy24)', 'p24', $payment_id, 0);
            ti_year_end_overpay_notify_admin((int)$p['source_id'], (float)$p['amount_grosze'] / 100, 'p24');
        }
        // Portal płatności SZO (/platnosci) — source_id = portal_transactions.id;
        // rozliczenie koszyka (pozycje, wpłaty TI, faktury) w modules/payment_portal.
        if ($p['source_type'] === 'payment_portal' && (int)$p['source_id'] > 0) {
            require_once dirname(__DIR__) . '/modules/payment_portal/logic/paymentPortal.php';
            pp_settle_from_p24((int)$p['source_id'], db_one("SELECT * FROM p24_payments WHERE id=?", [$payment_id]) ?: $p);
        }
        // Kolejne źródła można dodać tutaj.
    } catch (\Throwable $e) {}
}

/**
 * Aktywnie sprawdza (transaction/verify) status oczekującej płatności i księguje,
 * gdy potwierdzona — uzupełnienie notyfikacji (powrót kupującego często ją wyprzedza).
 * Wymaga, żeby orderId był już znany (ustawiany dopiero powiadomieniem P24) —
 * bez niego nie ma czego weryfikować, więc zwraca 'pending' bez wywołania API.
 * @return string aktualny status wiersza p24_payments ('' gdy nie znaleziono)
 */
function p24_reconcile_payment(int $payment_id): string {
    $p = db_one("SELECT id, status, order_id FROM p24_payments WHERE id=?", [$payment_id]);
    if (!$p) return '';
    if ($p['status'] !== 'pending' || $p['order_id'] === '') return (string)$p['status'];
    try {
        if (p24_verify_transaction($payment_id)) p24_mark_paid($payment_id);
        $row = db_one("SELECT status FROM p24_payments WHERE id=?", [$payment_id]);
        return (string)($row['status'] ?? 'pending');
    } catch (\Throwable $e) {}
    return 'pending';
}

/** Ostatnia płatność dla danego źródła (do wyświetlenia linku/statusu). */
function p24_payment_for(string $source_type, int $source_id): ?array {
    p24_migrate();
    return db_one(
        "SELECT * FROM p24_payments WHERE source_type=? AND source_id=? ORDER BY id DESC LIMIT 1",
        [$source_type, $source_id]
    ) ?: null;
}
