<?php
/**
 * includes/stripe.php — Integracja płatności Stripe (Checkout).
 *
 * Konfiguracja w settings (prefix stripe_): klucze API, webhook secret, waluta.
 * Płatności śledzone w tabeli stripe_payments (powiązanie z dowolnym źródłem
 * przez source_type + source_id — np. rozliczenie TI, zlecenie, usługa).
 *
 * Wywołania API przez stream_context (jak M365Graph) — bez zależności curl.
 */

// ── Ustawienia ────────────────────────────────────────────────────────────────
function stripe_setting(string $key, string $default = ''): string {
    static $cache = [];
    $fk = 'stripe_' . $key;
    if (!array_key_exists($fk, $cache)) {
        try {
            $r = db_one("SELECT value FROM settings WHERE key_=?", [$fk]);
            $cache[$fk] = $r['value'] ?? $default;
        } catch (\Throwable $e) { $cache[$fk] = $default; }
    }
    return $cache[$fk] !== '' ? $cache[$fk] : $default;
}

function stripe_save_setting(string $key, string $value): void {
    $fk = 'stripe_' . $key;
    try {
        db()->prepare("INSERT INTO settings(key_,value) VALUES(?,?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
           ->execute([$fk, $value]);
    } catch (\Throwable $e) {
        $ex = db_one("SELECT key_ FROM settings WHERE key_=?", [$fk]);
        if ($ex) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $fk]);
        else     db()->prepare("INSERT INTO settings(key_,value) VALUES(?,?)")->execute([$fk, $value]);
    }
}

function stripe_enabled(): bool {
    return stripe_setting('enabled') === '1' && stripe_configured();
}

function stripe_configured(): bool {
    return stripe_setting('secret_key') !== '';
}

function stripe_currency(): string {
    return strtolower(stripe_setting('currency', 'pln')) ?: 'pln';
}

// ── Migracja tabeli płatności ──────────────────────────────────────────────────
function stripe_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS stripe_payments (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            source_type      TEXT    NOT NULL DEFAULT '',   -- np. k30_ti_billing | zlecenie | usluga
            source_id        INTEGER NOT NULL DEFAULT 0,
            description      TEXT    NOT NULL DEFAULT '',
            amount_grosze    INTEGER NOT NULL DEFAULT 0,    -- kwota w najmniejszej jednostce (grosze)
            currency         TEXT    NOT NULL DEFAULT 'pln',
            status           TEXT    NOT NULL DEFAULT 'pending', -- pending | paid | failed | expired
            session_id       TEXT    NOT NULL DEFAULT '',   -- Stripe Checkout Session id (cs_...)
            payment_intent   TEXT    NOT NULL DEFAULT '',   -- pi_...
            checkout_url     TEXT    NOT NULL DEFAULT '',
            customer_email   TEXT    NOT NULL DEFAULT '',
            created_by       INTEGER,
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
            paid_at          DATETIME
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_stripe_pay_source ON stripe_payments(source_type, source_id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_stripe_pay_session ON stripe_payments(session_id)");
    } catch (\Throwable $e) {}
}

// ── Wywołanie API Stripe (POST form-encoded) ────────────────────────────────────
function stripe_api_post(string $path, array $params): array {
    $secret = stripe_setting('secret_key');
    if ($secret === '') throw new RuntimeException('Brak klucza API Stripe (Secret key).');
    $url  = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $body = http_build_query($params);
    $ctx  = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: Bearer {$secret}\r\n"
                         . "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => $body,
        'ignore_errors' => true,
        'timeout'       => 20,
    ]]);
    $raw  = @file_get_contents($url, false, $ctx);
    $resp = json_decode($raw ?: '{}', true) ?? [];
    if (isset($resp['error'])) {
        $msg = $resp['error']['message'] ?? 'Nieznany błąd Stripe.';
        throw new RuntimeException('Stripe: ' . $msg);
    }
    return $resp;
}

function stripe_api_get(string $path): array {
    $secret = stripe_setting('secret_key');
    if ($secret === '') throw new RuntimeException('Brak klucza API Stripe (Secret key).');
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Authorization: Bearer {$secret}\r\n",
        'ignore_errors' => true,
        'timeout'       => 20,
    ]]);
    $raw  = @file_get_contents('https://api.stripe.com/v1/' . ltrim($path, '/'), false, $ctx);
    $resp = json_decode($raw ?: '{}', true) ?? [];
    if (isset($resp['error'])) {
        throw new RuntimeException('Stripe: ' . ($resp['error']['message'] ?? 'błąd'));
    }
    return $resp;
}

/** Szybki test połączenia — pobiera saldo konta. Zwraca ['ok'=>bool,'msg'=>...]. */
function stripe_test_connection(): array {
    try {
        $b = stripe_api_get('balance');
        $cur = strtoupper($b['available'][0]['currency'] ?? stripe_currency());
        return ['ok' => true, 'msg' => 'Połączenie działa. Konto Stripe aktywne (waluta salda: ' . $cur . ').'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

/**
 * Tworzy Checkout Session dla danej płatności i zapisuje wiersz stripe_payments.
 *
 * @param  string $source_type  identyfikator źródła (np. 'k30_ti_billing')
 * @param  int    $source_id    id rekordu źródła
 * @param  float  $amount_pln   kwota w PLN (zostanie przeliczona na grosze)
 * @param  string $description  opis pozycji (widoczny w Checkout)
 * @param  string $success_url  URL powrotu po sukcesie (absolutny)
 * @param  string $cancel_url   URL powrotu po anulowaniu (absolutny)
 * @param  string $email        e-mail klienta (opcjonalnie — prefill)
 * @return array  ['id'=>payment row id, 'url'=>checkout url, 'session_id'=>cs_...]
 */
function stripe_create_checkout(string $source_type, int $source_id, float $amount_pln,
                                 string $description, string $success_url, string $cancel_url,
                                 string $email = ''): array {
    stripe_migrate();
    $grosze = (int) round($amount_pln * 100);
    if ($grosze < 1) throw new RuntimeException('Kwota płatności musi być większa od zera.');
    $currency = stripe_currency();

    // Wstępny wiersz (by mieć id do metadanych)
    $pid = db_insert('stripe_payments', [
        'source_type'   => $source_type,
        'source_id'     => $source_id,
        'description'   => mb_substr($description, 0, 300),
        'amount_grosze' => $grosze,
        'currency'      => $currency,
        'status'        => 'pending',
        'customer_email' => $email,
        'created_by'    => function_exists('current_user') ? (current_user()['id'] ?? null) : null,
    ]);

    $params = [
        'mode'                => 'payment',
        'success_url'         => $success_url,
        'cancel_url'          => $cancel_url,
        'client_reference_id' => $source_type . ':' . $source_id,
        'line_items'          => [[
            'quantity'   => 1,
            'price_data' => [
                'currency'     => $currency,
                'unit_amount'  => $grosze,
                'product_data' => ['name' => mb_substr($description, 0, 250) ?: 'Płatność'],
            ],
        ]],
        'metadata' => [
            'source_type' => $source_type,
            'source_id'   => (string)$source_id,
            'payment_id'  => (string)$pid,
        ],
    ];
    if ($email !== '') $params['customer_email'] = $email;

    try {
        $resp = stripe_api_post('checkout/sessions', $params);
    } catch (\Throwable $e) {
        db()->prepare("UPDATE stripe_payments SET status='failed' WHERE id=?")->execute([$pid]);
        throw $e;
    }

    db()->prepare("UPDATE stripe_payments SET session_id=?, payment_intent=?, checkout_url=? WHERE id=?")
       ->execute([$resp['id'] ?? '', $resp['payment_intent'] ?? '', $resp['url'] ?? '', $pid]);

    return ['id' => $pid, 'url' => $resp['url'] ?? '', 'session_id' => $resp['id'] ?? ''];
}

/**
 * Weryfikuje podpis webhooka Stripe (nagłówek Stripe-Signature).
 * @return bool true gdy podpis poprawny (lub gdy brak skonfigurowanego sekretu — wtedy pomijamy weryfikację).
 */
function stripe_verify_webhook(string $payload, string $sig_header, int $tolerance = 300): bool {
    $secret = stripe_setting('webhook_secret');
    if ($secret === '') return true; // brak sekretu — weryfikacja wyłączona (zalecane: ustawić)
    $ts = null; $v1 = [];
    foreach (explode(',', $sig_header) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't') $ts = $kv[1];
        if ($kv[0] === 'v1') $v1[] = $kv[1];
    }
    if ($ts === null || !$v1) return false;
    $signed   = $ts . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);
    $ok = false;
    foreach ($v1 as $sig) { if (hash_equals($expected, $sig)) { $ok = true; break; } }
    if (!$ok) return false;
    if ($tolerance > 0 && abs(time() - (int)$ts) > $tolerance) return false;
    return true;
}

/**
 * Oznacza płatność jako opłaconą i propaguje status do źródła
 * (np. k30_ti_billing.status='paid'). Idempotentne.
 */
function stripe_mark_paid(int $payment_id): void {
    $p = db_one("SELECT * FROM stripe_payments WHERE id=?", [$payment_id]);
    if (!$p || $p['status'] === 'paid') return;
    db()->prepare("UPDATE stripe_payments SET status='paid', paid_at=datetime('now') WHERE id=?")->execute([$payment_id]);

    // Propagacja do źródła
    try {
        if ($p['source_type'] === 'k30_ti_billing' && (int)$p['source_id'] > 0) {
            $bill = db_one("SELECT client_id, COALESCE(course_id,0) AS course_id FROM k30_ti_billing WHERE id=?", [(int)$p['source_id']]);
            if ($bill) {
                // Zapisz wpłatę do księgi TI — saldo/nadpłata przeliczane automatycznie
                require_once __DIR__ . '/ti_payments.php';
                // Wpłata księgowana na grupę, której dotyczy opłacone rozliczenie (model kombinowany)
                ti_payment_add((int)$bill['client_id'], (float)$p['amount_grosze'] / 100, date('Y-m-d'),
                               'stripe', 'Płatność Stripe', 'stripe', (int)$p['source_id'], (int)$bill['course_id']);
            }
        }
        // Doładowanie portfela kursanta TI (source_id = client_id) — wpłata OGÓLNA
        // (course_id=0), automatycznie zużywana FIFO na kolejne należności za zajęcia.
        if ($p['source_type'] === 'k30_ti_wallet' && (int)$p['source_id'] > 0) {
            require_once __DIR__ . '/ti_payments.php';
            ti_payment_add((int)$p['source_id'], (float)$p['amount_grosze'] / 100, date('Y-m-d'),
                           'stripe', 'Doładowanie portfela (Stripe)', 'stripe', $payment_id, 0);
        }
        // Nadpłata do końca roku (powody podatkowe/księgowe) — księgowana identycznie
        // jak doładowanie portfela (wpłata ogólna), plus e-mail do adminów o FV.
        if ($p['source_type'] === 'k30_ti_wallet_year_end' && (int)$p['source_id'] > 0) {
            require_once __DIR__ . '/ti_payments.php';
            ti_payment_add((int)$p['source_id'], (float)$p['amount_grosze'] / 100, date('Y-m-d'),
                           'stripe', 'Nadpłata do końca roku (Stripe)', 'stripe', $payment_id, 0);
            ti_year_end_overpay_notify_admin((int)$p['source_id'], (float)$p['amount_grosze'] / 100, 'stripe');
        }
        // Kolejne źródła można dodać tutaj (zlecenie_rozliczenia, umowy_uslugi…)
    } catch (\Throwable $e) {}
}

/**
 * Aktywnie sprawdza w API status oczekującej płatności i księguje ją, gdy Checkout
 * został opłacony — uzupełnienie webhooka (powrót kupującego często go wyprzedza).
 * @return string aktualny status wiersza stripe_payments ('' gdy nie znaleziono)
 */
function stripe_reconcile_payment(int $payment_id): string {
    $p = db_one("SELECT id, status, session_id FROM stripe_payments WHERE id=?", [$payment_id]);
    if (!$p) return '';
    if ($p['status'] !== 'pending' || $p['session_id'] === '') return (string)$p['status'];
    try {
        $s = stripe_api_get('checkout/sessions/' . rawurlencode($p['session_id']));
        if (($s['payment_status'] ?? '') === 'paid') { stripe_mark_paid($payment_id); return 'paid'; }
        if (($s['status'] ?? '') === 'expired') {
            db()->prepare("UPDATE stripe_payments SET status='expired' WHERE id=? AND status='pending'")
               ->execute([$payment_id]);
            return 'expired';
        }
    } catch (\Throwable $e) {}
    return 'pending';
}

/** Ostatnia płatność dla danego źródła (do wyświetlenia linku/statusu). */
function stripe_payment_for(string $source_type, int $source_id): ?array {
    stripe_migrate();
    return db_one(
        "SELECT * FROM stripe_payments WHERE source_type=? AND source_id=? ORDER BY id DESC LIMIT 1",
        [$source_type, $source_id]
    ) ?: null;
}
