<?php
/**
 * includes/payu.php — Integracja płatności PayU (REST API v2.1, OAuth client_credentials).
 *
 * Konfiguracja w settings (prefix payu_): pos_id, client_id, client_secret, md5_key,
 * environment (sandbox|prod), currency. Płatności śledzone w tabeli payu_payments
 * (powiązanie z dowolnym źródłem przez source_type + source_id — np. rozliczenie TI).
 *
 * Wywołania API przez stream_context (bez zależności curl) — spójnie ze stripe.php.
 */

// ── Ustawienia ────────────────────────────────────────────────────────────────
function payu_setting(string $key, string $default = ''): string {
    static $cache = [];
    $fk = 'payu_' . $key;
    if (!array_key_exists($fk, $cache)) {
        try {
            $r = db_one("SELECT value FROM settings WHERE key_=?", [$fk]);
            $cache[$fk] = $r['value'] ?? $default;
        } catch (\Throwable $e) { $cache[$fk] = $default; }
    }
    return $cache[$fk] !== '' ? $cache[$fk] : $default;
}

function payu_save_setting(string $key, string $value): void {
    $fk = 'payu_' . $key;
    try {
        db()->prepare("INSERT INTO settings(key_,value) VALUES(?,?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
           ->execute([$fk, $value]);
    } catch (\Throwable $e) {
        $ex = db_one("SELECT key_ FROM settings WHERE key_=?", [$fk]);
        if ($ex) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $fk]);
        else     db()->prepare("INSERT INTO settings(key_,value) VALUES(?,?)")->execute([$fk, $value]);
    }
}

function payu_configured(): bool {
    return payu_setting('pos_id') !== '' && payu_setting('client_id') !== ''
        && payu_setting('client_secret') !== '' && payu_setting('md5_key') !== '';
}

function payu_enabled(): bool {
    return payu_setting('enabled') === '1' && payu_configured();
}

function payu_currency(): string {
    return strtoupper(payu_setting('currency', 'PLN')) ?: 'PLN';
}

function payu_environment(): string {
    return payu_setting('environment', 'sandbox') === 'prod' ? 'prod' : 'sandbox';
}

/** Bazowy URL API PayU zależnie od środowiska. */
function payu_base_url(): string {
    return payu_environment() === 'prod' ? 'https://secure.payu.com' : 'https://secure.snd.payu.com';
}

// ── Migracja tabeli płatności ──────────────────────────────────────────────────
function payu_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS payu_payments (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            source_type      TEXT    NOT NULL DEFAULT '',   -- np. k30_ti_billing
            source_id        INTEGER NOT NULL DEFAULT 0,
            description      TEXT    NOT NULL DEFAULT '',
            amount_grosze    INTEGER NOT NULL DEFAULT 0,    -- kwota w groszach
            currency         TEXT    NOT NULL DEFAULT 'PLN',
            status           TEXT    NOT NULL DEFAULT 'pending', -- pending | paid | failed | expired | canceled
            order_id         TEXT    NOT NULL DEFAULT '',   -- PayU orderId
            ext_order_id     TEXT    NOT NULL DEFAULT '',   -- nasz unikalny identyfikator
            redirect_uri     TEXT    NOT NULL DEFAULT '',   -- URL do płatności (przekierowanie kupującego)
            customer_email   TEXT    NOT NULL DEFAULT '',
            created_by       INTEGER,
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
            paid_at          DATETIME
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_payu_pay_source ON payu_payments(source_type, source_id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_payu_pay_order  ON payu_payments(order_id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_payu_pay_ext    ON payu_payments(ext_order_id)");
    } catch (\Throwable $e) {}
}

// ── OAuth ───────────────────────────────────────────────────────────────────────
/** Pobiera token dostępu (client_credentials). Cache na czas życia żądania. */
function payu_oauth_token(): string {
    static $tok = null;
    if ($tok !== null) return $tok;
    $url  = payu_base_url() . '/pl/standard/user/oauth/authorize';
    $body = http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => payu_setting('client_id'),
        'client_secret' => payu_setting('client_secret'),
    ]);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => $body,
        'ignore_errors' => true,
        'timeout'       => 20,
    ]]);
    $raw  = @file_get_contents($url, false, $ctx);
    $resp = json_decode($raw ?: '{}', true) ?? [];
    if (empty($resp['access_token'])) {
        $msg = $resp['error_description'] ?? ($resp['error'] ?? 'brak tokenu');
        throw new RuntimeException('PayU OAuth: ' . $msg);
    }
    return $tok = (string)$resp['access_token'];
}

/** Szybki test połączenia — próbuje pobrać token OAuth. */
function payu_test_connection(): array {
    try {
        payu_oauth_token();
        return ['ok' => true, 'msg' => 'Połączenie działa. Token OAuth pobrany ('
            . (payu_environment() === 'prod' ? 'produkcja' : 'sandbox') . ').'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

/**
 * Tworzy zamówienie PayU i zapisuje wiersz payu_payments.
 *
 * @return array ['id'=>payment row id, 'url'=>redirectUri, 'order_id'=>...]
 */
function payu_create_order(string $source_type, int $source_id, float $amount_pln,
                           string $description, string $continue_url, string $notify_url,
                           string $email = '', string $customer_ip = ''): array {
    payu_migrate();
    $grosze = (int) round($amount_pln * 100);
    if ($grosze < 1) throw new RuntimeException('Kwota płatności musi być większa od zera.');
    $currency = payu_currency();
    $ext = $source_type . '-' . $source_id . '-' . substr(bin2hex(random_bytes(6)), 0, 8);
    $ip  = $customer_ip !== '' ? $customer_ip : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    if ($ip === '::1') $ip = '127.0.0.1';

    // Wstępny wiersz (id w extOrderId niepotrzebne — mamy własny ext)
    $pid = db_insert('payu_payments', [
        'source_type'    => $source_type,
        'source_id'      => $source_id,
        'description'    => mb_substr($description, 0, 300),
        'amount_grosze'  => $grosze,
        'currency'       => $currency,
        'status'         => 'pending',
        'ext_order_id'   => $ext,
        'customer_email' => $email,
        'created_by'     => function_exists('current_user') ? (current_user()['id'] ?? null) : null,
    ]);

    $payload = [
        'notifyUrl'     => $notify_url,
        'continueUrl'   => $continue_url,
        'customerIp'    => $ip,
        'merchantPosId' => payu_setting('pos_id'),
        'description'   => mb_substr($description, 0, 250) ?: 'Płatność',
        'currencyCode'  => $currency,
        'totalAmount'   => (string)$grosze,
        'extOrderId'    => $ext,
        'products'      => [[
            'name'      => mb_substr($description, 0, 250) ?: 'Płatność',
            'unitPrice' => (string)$grosze,
            'quantity'  => '1',
        ]],
    ];
    if ($email !== '') $payload['buyer'] = ['email' => $email];

    $url = payu_base_url() . '/api/v2_1/orders';
    $ctx = stream_context_create(['http' => [
        'method'         => 'POST',
        'header'         => "Authorization: Bearer " . payu_oauth_token() . "\r\n"
                          . "Content-Type: application/json\r\n",
        'content'        => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'ignore_errors'  => true,
        'follow_location'=> 0, // PayU zwraca 302 z JSON-em (redirectUri) w treści
        'timeout'        => 20,
    ]]);
    $raw  = @file_get_contents($url, false, $ctx);
    $resp = json_decode($raw ?: '{}', true) ?? [];

    $code = $resp['status']['statusCode'] ?? '';
    if ($code !== 'SUCCESS' && empty($resp['redirectUri'])) {
        db()->prepare("UPDATE payu_payments SET status='failed' WHERE id=?")->execute([$pid]);
        $msg = $resp['status']['statusDesc'] ?? ($resp['status']['codeLiteral'] ?? 'nie udało się utworzyć zamówienia');
        throw new RuntimeException('PayU: ' . $msg);
    }

    $order_id = (string)($resp['orderId'] ?? '');
    $redirect = (string)($resp['redirectUri'] ?? '');
    db()->prepare("UPDATE payu_payments SET order_id=?, redirect_uri=? WHERE id=?")
       ->execute([$order_id, $redirect, $pid]);

    return ['id' => $pid, 'url' => $redirect, 'order_id' => $order_id];
}

/**
 * Weryfikuje podpis powiadomienia PayU (nagłówek OpenPayU-Signature).
 * Format: "sender=...;signature=HASH;algorithm=MD5;content=DOCUMENT".
 * Dla MD5: hash = md5(rawBody + drugi_klucz).
 * @return bool true gdy podpis poprawny (lub brak klucza — wtedy pomijamy weryfikację).
 */
function payu_verify_notification(string $payload, string $sig_header): bool {
    $key = payu_setting('md5_key');
    if ($key === '') return true; // brak klucza — weryfikacja wyłączona (zalecane: ustawić)
    $parts = [];
    foreach (explode(';', $sig_header) as $p) {
        $kv = explode('=', trim($p), 2);
        if (count($kv) === 2) $parts[strtolower(trim($kv[0]))] = trim($kv[1]);
    }
    $sig = $parts['signature'] ?? '';
    $alg = strtoupper($parts['algorithm'] ?? 'MD5');
    if ($sig === '') return false;
    $expected = match ($alg) {
        'SHA-256', 'SHA256' => hash('sha256', $payload . $key),
        'SHA-1', 'SHA1'     => sha1($payload . $key),
        default             => md5($payload . $key),
    };
    return hash_equals($expected, strtolower($sig));
}

/**
 * Oznacza płatność jako opłaconą i propaguje status do źródła
 * (np. k30_ti_billing.status='paid'). Idempotentne.
 */
function payu_mark_paid(int $payment_id): void {
    $p = db_one("SELECT * FROM payu_payments WHERE id=?", [$payment_id]);
    if (!$p || $p['status'] === 'paid') return;
    db()->prepare("UPDATE payu_payments SET status='paid', paid_at=datetime('now') WHERE id=?")->execute([$payment_id]);
    try {
        if ($p['source_type'] === 'k30_ti_billing' && (int)$p['source_id'] > 0) {
            $bill = db_one("SELECT client_id, COALESCE(course_id,0) AS course_id FROM k30_ti_billing WHERE id=?", [(int)$p['source_id']]);
            if ($bill) {
                require_once __DIR__ . '/ti_payments.php';
                // Wpłata księgowana na grupę, której dotyczy opłacone rozliczenie (model kombinowany)
                ti_payment_add((int)$bill['client_id'], (float)$p['amount_grosze'] / 100, date('Y-m-d'),
                               'payu', 'Płatność PayU', 'payu', (int)$p['source_id'], (int)$bill['course_id']);
            }
        }
        // Doładowanie portfela kursanta TI (source_id = client_id) — wpłata OGÓLNA
        // (course_id=0), automatycznie zużywana FIFO na kolejne należności za zajęcia.
        if ($p['source_type'] === 'k30_ti_wallet' && (int)$p['source_id'] > 0) {
            require_once __DIR__ . '/ti_payments.php';
            ti_payment_add((int)$p['source_id'], (float)$p['amount_grosze'] / 100, date('Y-m-d'),
                           'payu', 'Doładowanie portfela (PayU)', 'payu', $payment_id, 0);
        }
        // Nadpłata do końca roku (powody podatkowe/księgowe) — księgowana identycznie
        // jak doładowanie portfela (wpłata ogólna), plus e-mail do adminów o FV.
        if ($p['source_type'] === 'k30_ti_wallet_year_end' && (int)$p['source_id'] > 0) {
            require_once __DIR__ . '/ti_payments.php';
            ti_payment_add((int)$p['source_id'], (float)$p['amount_grosze'] / 100, date('Y-m-d'),
                           'payu', 'Nadpłata do końca roku (PayU)', 'payu', $payment_id, 0);
            ti_year_end_overpay_notify_admin((int)$p['source_id'], (float)$p['amount_grosze'] / 100, 'payu');
        }
        // Kolejne źródła można dodać tutaj.
    } catch (\Throwable $e) {}
}

/**
 * Aktywnie sprawdza w API status oczekującego zamówienia i księguje płatność,
 * gdy jest COMPLETED — uzupełnienie notyfikacji (powrót kupującego często ją wyprzedza).
 * @return string aktualny status wiersza payu_payments ('' gdy nie znaleziono)
 */
function payu_reconcile_payment(int $payment_id): string {
    $p = db_one("SELECT id, status, order_id FROM payu_payments WHERE id=?", [$payment_id]);
    if (!$p) return '';
    if ($p['status'] !== 'pending' || $p['order_id'] === '') return (string)$p['status'];
    try {
        $url = payu_base_url() . '/api/v2_1/orders/' . rawurlencode($p['order_id']);
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => 'Authorization: Bearer ' . payu_oauth_token() . "\r\n",
            'ignore_errors' => true,
            'timeout'       => 20,
        ]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $resp = json_decode($raw ?: '{}', true) ?? [];
        $st   = strtoupper((string)($resp['orders'][0]['status'] ?? ''));
        if ($st !== '') payu_apply_order_status($payment_id, $st);
        $row = db_one("SELECT status FROM payu_payments WHERE id=?", [$payment_id]);
        return (string)($row['status'] ?? 'pending');
    } catch (\Throwable $e) {}
    return 'pending';
}

/** Aktualizuje status płatności wg statusu zamówienia PayU (z notyfikacji). */
function payu_apply_order_status(int $payment_id, string $order_status): void {
    $order_status = strtoupper($order_status);
    if ($order_status === 'COMPLETED') { payu_mark_paid($payment_id); return; }
    $map = ['CANCELED' => 'canceled', 'REJECTED' => 'failed'];
    if (isset($map[$order_status])) {
        db()->prepare("UPDATE payu_payments SET status=? WHERE id=? AND status='pending'")
           ->execute([$map[$order_status], $payment_id]);
    }
}

/** Ostatnia płatność dla danego źródła (do wyświetlenia linku/statusu). */
function payu_payment_for(string $source_type, int $source_id): ?array {
    payu_migrate();
    return db_one(
        "SELECT * FROM payu_payments WHERE source_type=? AND source_id=? ORDER BY id DESC LIMIT 1",
        [$source_type, $source_id]
    ) ?: null;
}
