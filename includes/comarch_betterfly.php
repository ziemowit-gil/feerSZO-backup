<?php
/**
 * Klient API Comarch Betterfly (Publiczne API v1.x) — wystawianie i pobieranie faktur.
 *
 * Dokumentacja: https://pomoc.comarchbetterfly.pl/dokumentacja/api-informacje-ogolne/
 *  - Uwierzytelnianie: OAuth 2.0, grant_type=client_credentials.
 *    Token: POST {base}/api2/public/token, nagłówek Authorization: Basic base64(client_id:client_secret),
 *    Content-Type: application/x-www-form-urlencoded, body: grant_type=client_credentials.
 *    Access token ważny ~10 min → używany jako "Bearer {token}" przy wywołaniach API.
 *  - Dane w JSON.
 *  - Faktury sprzedaży: {base}/api2/public/v1.7/invoices  (POST tworzy, GET listuje, GET /{id} pobiera).
 *  - Wydruk PDF: GET {base}/api2/public/v1.4/invoices/{id}/print → dokument w Base64.
 *
 * Konfiguracja w ustawieniach organizacji (admin/comarch_settings.php):
 *   comarch_enabled, comarch_client_id, comarch_client_secret, comarch_base_url (opcjonalnie).
 *
 * UWAGA: Comarch operuje na IDENTYFIKATORACH (PurchasingPartyId, PaymentTypeId,
 * ProductId, VatRateId), nie na danych tekstowych jak Fakturownia — dane słownikowe
 * pobiera się osobnymi wywołaniami (comarch_partners/products/vat_rates/payment_types).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

const COMARCH_BASE_DEFAULT = 'https://app.comarchbetterfly.pl';
const COMARCH_API_VER      = 'v1.7'; // faktury sprzedaży
const COMARCH_PRINT_VER    = 'v1.4'; // wydruki

/** Bazowy URL (z ustawień lub domyślny), bez końcowego ukośnika. */
function comarch_base(): string {
    $b = trim(org_setting('comarch_base_url'));
    return rtrim($b !== '' ? $b : COMARCH_BASE_DEFAULT, '/');
}

/** Czy integracja jest skonfigurowana. */
function comarch_configured(): bool {
    return org_setting('comarch_client_id') !== '' && org_setting('comarch_client_secret') !== '';
}

function comarch_enabled(): bool {
    return org_setting('comarch_enabled') === '1' && comarch_configured();
}

/**
 * Zwraca ważny access token (OAuth client_credentials), z pamięci podręcznej.
 * Token trzymany w settings (comarch_token / comarch_token_exp) + cache statyczny.
 * @return array{ok:bool,token?:string,error?:string}
 */
function comarch_token(bool $force = false): array {
    static $mem = null;
    $now = time();
    if (!$force && $mem && $mem['exp'] > $now + 15) return ['ok' => true, 'token' => $mem['token']];

    if (!$force) {
        $cached = org_setting('comarch_token');
        $exp    = (int)org_setting('comarch_token_exp');
        if ($cached !== '' && $exp > $now + 15) {
            $mem = ['token' => $cached, 'exp' => $exp];
            return ['ok' => true, 'token' => $cached];
        }
    }

    $cid = org_setting('comarch_client_id');
    $sec = org_setting('comarch_client_secret');
    if ($cid === '' || $sec === '') return ['ok' => false, 'error' => 'Brak Client ID / Client Secret.'];

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 15,
        'ignore_errors' => true,
        'header'        => "Authorization: Basic " . base64_encode($cid . ':' . $sec) . "\r\n"
                         . "Content-Type: application/x-www-form-urlencoded\r\n"
                         . "Accept: application/json\r\n",
        'content'       => 'grant_type=client_credentials',
    ]]);
    $raw  = @file_get_contents(comarch_base() . '/api2/public/token', false, $ctx);
    $code = _comarch_http_code($http_response_header ?? []);
    $data = $raw ? (json_decode($raw, true) ?: []) : [];

    if ($code !== 200 || empty($data['access_token'])) {
        return ['ok' => false, 'error' => 'Uwierzytelnianie nie powiodło się (HTTP ' . $code . '): '
                                          . ($data['error_description'] ?? $data['error'] ?? mb_substr((string)$raw, 0, 160))];
    }
    $token = (string)$data['access_token'];
    $exp   = $now + (int)($data['expires_in'] ?? 600);
    org_setting_set('comarch_token', $token);
    org_setting_set('comarch_token_exp', (string)$exp);
    $mem = ['token' => $token, 'exp' => $exp];
    return ['ok' => true, 'token' => $token];
}

/** Wyciąga kod HTTP z tablicy nagłówków odpowiedzi. */
function _comarch_http_code(array $headers): int {
    preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $headers[0] ?? '', $m);
    return (int)($m[1] ?? 0);
}

/**
 * Jedno wywołanie API (z Bearer). Odświeża token raz przy 401.
 * @return array{code:int,data:array,raw:string,error?:string}
 */
function comarch_request(string $path, string $method = 'GET', ?array $payload = null, int $timeout = 20, bool $retry = true): array {
    $tok = comarch_token();
    if (!$tok['ok']) return ['code' => 0, 'data' => [], 'raw' => '', 'error' => $tok['error']];

    $url  = comarch_base() . '/api2/public/' . ltrim($path, '/');
    $opts = [
        'method'        => $method,
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'header'        => "Authorization: Bearer {$tok['token']}\r\nAccept: application/json\r\n"
                         . ($payload !== null ? "Content-Type: application/json\r\n" : ''),
    ];
    if ($payload !== null) $opts['content'] = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $raw  = @file_get_contents($url, false, stream_context_create(['http' => $opts]));
    $code = _comarch_http_code($http_response_header ?? []);

    // Token wygasł/nieważny — odśwież raz i powtórz.
    if ($code === 401 && $retry) {
        comarch_token(true);
        return comarch_request($path, $method, $payload, $timeout, false);
    }

    return [
        'code' => $code,
        'data' => $raw ? (json_decode($raw, true) ?? []) : [],
        'raw'  => (string)$raw,
    ];
}

/** Czytelny komunikat błędu z odpowiedzi. */
function comarch_error(array $res): string {
    if (!empty($res['error'])) return (string)$res['error'];
    $d = $res['data'] ?? [];
    if (is_array($d)) {
        foreach (['Message', 'message', 'error_description', 'error', 'Error'] as $k) {
            if (!empty($d[$k])) return is_string($d[$k]) ? $d[$k] : json_encode($d[$k], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($d['ModelState']) && is_array($d['ModelState'])) {
            $parts = [];
            foreach ($d['ModelState'] as $k => $v) $parts[] = (is_array($v) ? implode(', ', $v) : (string)$v);
            if ($parts) return implode('; ', array_slice($parts, 0, 4));
        }
    }
    return ($res['raw'] ?? '') !== '' ? mb_substr($res['raw'], 0, 200) : ('HTTP ' . ($res['code'] ?? 0));
}

// ── Faktury sprzedaży ────────────────────────────────────────────────────────

/**
 * Wystawia fakturę sprzedaży. $inv używa nazw pól API Comarch, np.:
 *   PurchasingPartyId (int, wymagane), PaymentTypeId (int, wymagane),
 *   Items => [['ProductId'=>int,'Quantity'=>float,'ProductCurrencyPrice'=>float,'VatRateId'=>int,'ProductDescription'=>string], ...],
 *   IssueDate, SalesDate, PaymentDeadline (RRRR-MM-DD), InvoiceType (0=od netto,1=od brutto),
 *   PaymentStatus (0/1/2), ReceivingPartyId, Description.
 * @return array{success:bool,invoice_id?:int,number?:string,data?:array,error?:string}
 */
function comarch_create_invoice(array $inv): array {
    if (!comarch_configured()) return ['success' => false, 'error' => 'Brak konfiguracji API Comarch.'];
    if (empty($inv['PurchasingPartyId'])) return ['success' => false, 'error' => 'Wymagane: PurchasingPartyId (nabywca).'];
    if (empty($inv['PaymentTypeId']))     return ['success' => false, 'error' => 'Wymagane: PaymentTypeId (forma płatności).'];

    $res = comarch_request(COMARCH_API_VER . '/invoices', 'POST', $inv);
    if (in_array($res['code'], [200, 201], true) && !empty($res['data'])) {
        $d = $res['data'];
        return [
            'success'    => true,
            'invoice_id' => (int)($d['Id'] ?? $d['id'] ?? 0),
            'number'     => (string)($d['Number'] ?? $d['DocumentNumber'] ?? $d['number'] ?? ''),
            'data'       => $d,
        ];
    }
    return ['success' => false, 'error' => comarch_error($res)];
}

/** Pobiera pojedynczą fakturę po Id. */
function comarch_get_invoice(int $id): array {
    if ($id <= 0) return ['ok' => false, 'error' => 'Brak Id faktury.'];
    $res = comarch_request(COMARCH_API_VER . '/invoices/' . $id);
    if ($res['code'] === 200 && !empty($res['data'])) return ['ok' => true, 'invoice' => $res['data']];
    if ($res['code'] === 404) return ['ok' => false, 'error' => 'Faktura nie istnieje (404).', 'gone' => true];
    return ['ok' => false, 'error' => comarch_error($res)];
}

/** Lista faktur (opcjonalny numer). $params np. ['number'=>'FS/1/2026']. */
function comarch_list_invoices(array $params = []): array {
    $q   = $params ? ('?' . http_build_query($params)) : '';
    $res = comarch_request(COMARCH_API_VER . '/invoices' . $q);
    if ($res['code'] === 200) return ['ok' => true, 'items' => is_array($res['data']) ? $res['data'] : []];
    return ['ok' => false, 'error' => comarch_error($res)];
}

/**
 * Pobiera wydruk faktury jako binarny PDF. Endpoint zwraca dokument w Base64
 * (bezpośrednio jako string JSON albo w polu). Zwraca surowe bajty PDF lub null.
 */
function comarch_invoice_pdf(int $id, ?int $customPrintId = null): ?string {
    if ($id <= 0) return null;
    $path = COMARCH_PRINT_VER . '/invoices/' . $id . '/print';
    if ($customPrintId) $path .= '?customPrintId=' . (int)$customPrintId;
    $res = comarch_request($path);
    if ($res['code'] !== 200) return null;

    // Odpowiedź bywa: czysty string Base64, JSON-owy string, albo obiekt z polem.
    $b64 = '';
    $d = $res['data'];
    if (is_string($d) && $d !== '') {
        $b64 = $d;
    } elseif (is_array($d)) {
        foreach (['Content', 'content', 'File', 'file', 'Data', 'data', 'Printout', 'Base64'] as $k) {
            if (!empty($d[$k]) && is_string($d[$k])) { $b64 = $d[$k]; break; }
        }
    }
    if ($b64 === '' && $res['raw'] !== '') {
        // Surowa odpowiedź to Base64 (ewentualnie w cudzysłowach).
        $b64 = trim($res['raw'], "\"\r\n ");
    }
    if ($b64 === '') return null;

    $bin = base64_decode($b64, true);
    if ($bin === false || !str_starts_with($bin, '%PDF')) return null;
    return $bin;
}

// ── Dane słownikowe (do mapowania przy wystawianiu) ──────────────────────────

/** Kontrahenci (nabywcy) — do PurchasingPartyId. */
function comarch_partners(array $params = []): array {
    $res = comarch_request(COMARCH_API_VER . '/parties' . ($params ? '?' . http_build_query($params) : ''));
    return $res['code'] === 200 ? ['ok' => true, 'items' => (array)$res['data']] : ['ok' => false, 'error' => comarch_error($res)];
}

/** Produkty/usługi — do ProductId. */
function comarch_products(array $params = []): array {
    $res = comarch_request(COMARCH_API_VER . '/products' . ($params ? '?' . http_build_query($params) : ''));
    return $res['code'] === 200 ? ['ok' => true, 'items' => (array)$res['data']] : ['ok' => false, 'error' => comarch_error($res)];
}

/** Stawki VAT — do VatRateId. */
function comarch_vat_rates(): array {
    $res = comarch_request(COMARCH_API_VER . '/vatrates');
    return $res['code'] === 200 ? ['ok' => true, 'items' => (array)$res['data']] : ['ok' => false, 'error' => comarch_error($res)];
}

/** Formy płatności — do PaymentTypeId. */
function comarch_payment_types(): array {
    $res = comarch_request(COMARCH_API_VER . '/paymenttypes');
    return $res['code'] === 200 ? ['ok' => true, 'items' => (array)$res['data']] : ['ok' => false, 'error' => comarch_error($res)];
}

/** Szybki test połączenia: token + próba pobrania listy faktur (page 1). */
function comarch_test_connection(): array {
    $tok = comarch_token(true);
    if (!$tok['ok']) return ['ok' => false, 'error' => $tok['error']];
    $res = comarch_request(COMARCH_API_VER . '/invoices?page=1');
    if (in_array($res['code'], [200, 204], true)) return ['ok' => true];
    return ['ok' => false, 'error' => comarch_error($res)];
}
