<?php
/**
 * Helper API fakturownia.pl.
 *
 * Dwa zastosowania:
 *  - panel SaaS — fakturowanie tenantów (credentials podawane jako parametry),
 *  - moduł Faktury (includes/invoices.php) — credentials z ustawień organizacji.
 * Dlatego wszystkie funkcje przyjmują konto i token wprost, bez sięgania do bazy.
 */

/**
 * Jedno wywołanie API. Zwraca ['code'=>int, 'data'=>array, 'raw'=>string].
 *
 * Fakturownia odpowiada JSON-em także na błędy, więc `ignore_errors` jest
 * konieczne — bez tego file_get_contents() zwróciłby false i zgubił treść błędu.
 */
function fakturownia_request(string $account, string $token, string $path,
                             string $method = 'GET', ?array $payload = null, int $timeout = 15): array
{
    $url  = "https://{$account}.fakturownia.pl/" . ltrim($path, '/');
    $opts = [
        'method'        => $method,
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'header'        => "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: RejestrUmow/1.0",
    ];
    if ($method === 'GET') {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'api_token=' . urlencode($token);
    } else {
        $opts['content'] = json_encode(array_merge(['api_token' => $token], $payload ?? []), JSON_UNESCAPED_UNICODE);
    }

    $raw  = @file_get_contents($url, false, stream_context_create(['http' => $opts]));
    $code = 0;
    if (isset($http_response_header)) {
        preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $http_response_header[0] ?? '', $m);
        $code = (int)($m[1] ?? 0);
    }
    return [
        'code' => $code,
        'data' => $raw ? (json_decode($raw, true) ?? []) : [],
        'raw'  => (string)$raw,
    ];
}

/** Czytelny komunikat błędu z odpowiedzi API. */
function fakturownia_error(array $res): string
{
    $d = $res['data'];
    if (is_array($d)) {
        if (!empty($d['error']))   return is_string($d['error']) ? $d['error'] : json_encode($d['error'], JSON_UNESCAPED_UNICODE);
        if (!empty($d['message'])) return (string)$d['message'];
        // Błędy walidacji przychodzą jako mapa pole → lista komunikatów.
        if ($d && array_is_list($d) === false) {
            $parts = [];
            foreach ($d as $k => $v) $parts[] = $k . ': ' . (is_array($v) ? implode(', ', $v) : (string)$v);
            if ($parts) return implode('; ', array_slice($parts, 0, 4));
        }
    }
    return $res['raw'] !== '' ? mb_substr($res['raw'], 0, 200) : ('HTTP ' . $res['code']);
}

/** Pobiera fakturę z Fakturowni (status płatności, numer, kwoty). */
function fakturownia_get_invoice(string $account, string $token, int $invoice_id): array
{
    if (!$account || !$token || $invoice_id <= 0) return ['ok' => false, 'error' => 'Brak konfiguracji API'];
    $res = fakturownia_request($account, $token, "invoices/{$invoice_id}.json");
    if ($res['code'] === 200 && !empty($res['data']['id'])) {
        return ['ok' => true, 'invoice' => $res['data']];
    }
    if ($res['code'] === 404) return ['ok' => false, 'error' => 'Faktura nie istnieje w Fakturowni (404)', 'gone' => true];
    return ['ok' => false, 'error' => fakturownia_error($res)];
}

/**
 * Pobiera PDF faktury. Zwraca treść pliku albo null.
 * PDF nie jest JSON-em, więc nie idzie przez fakturownia_request().
 */
function fakturownia_invoice_pdf(string $account, string $token, int $invoice_id): ?string
{
    if (!$account || !$token || $invoice_id <= 0) return null;
    $url = "https://{$account}.fakturownia.pl/invoices/{$invoice_id}.pdf?api_token=" . urlencode($token);
    $ctx = stream_context_create(['http' => [
        'timeout'       => 25,
        'ignore_errors' => true,
        'header'        => "Accept: application/pdf\r\nUser-Agent: RejestrUmow/1.0",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    // Nagłówek %PDF chroni przed zapisaniem strony błędu jako „faktury".
    if (!is_string($raw) || !str_starts_with($raw, '%PDF')) return null;
    return $raw;
}

function fakturownia_test_connection(string $account, string $token): array {
    if (!$account || !$token) return ['ok' => false, 'error' => 'Brak konfiguracji'];
    $url = "https://{$account}.fakturownia.pl/invoices.json?api_token=" . urlencode($token) . "&page=1&per_page=1";
    $ctx = stream_context_create(['http' => [
        'timeout'       => 8,
        'ignore_errors' => true,
        'header'        => "Accept: application/json\r\nUser-Agent: RejestrUmow/1.0",
    ]]);
    $raw  = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $http_response_header[0] ?? '', $m);
        $code = (int)($m[1] ?? 0);
    }
    if ($code === 200) return ['ok' => true];
    if ($code === 401) return ['ok' => false, 'error' => 'Błędny token API (401)'];
    if ($code === 404) return ['ok' => false, 'error' => 'Nie znaleziono konta (404) — sprawdź subdomenę'];
    return ['ok' => false, 'error' => $raw ? (json_decode($raw, true)['error'] ?? "HTTP $code") : "Brak odpowiedzi"];
}

function fakturownia_create_invoice(string $account, string $token, array $inv): array {
    if (!$account || !$token) return ['success' => false, 'error' => 'Brak konfiguracji API'];

    $body = json_encode([
        'api_token' => $token,
        'invoice'   => [
            'kind'             => $inv['kind']            ?? 'vat',
            'number'           => $inv['number']          ?? null,
            'sell_date'        => $inv['sell_date']       ?? date('Y-m-d'),
            'issue_date'       => $inv['issue_date']      ?? date('Y-m-d'),
            'payment_to_kind'  => 'off',
            'payment_to'       => $inv['payment_to']      ?? date('Y-m-d', strtotime('+14 days')),
            'buyer_name'       => $inv['buyer_name']      ?? '',
            'buyer_tax_no'     => $inv['buyer_tax_no']    ?? '',
            'buyer_post_code'  => $inv['buyer_post_code'] ?? '',
            'buyer_city'       => $inv['buyer_city']      ?? '',
            'buyer_street'     => $inv['buyer_street']    ?? '',
            'buyer_email'      => $inv['buyer_email']     ?? '',
            'currency'         => $inv['currency']        ?? 'PLN',
            'positions'        => $inv['positions']       ?? [],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 15,
        'ignore_errors' => true,
        'header'        => "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: RejestrUmow/1.0",
        'content'       => $body,
    ]]);

    $raw  = @file_get_contents("https://{$account}.fakturownia.pl/invoices.json", false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $http_response_header[0] ?? '', $m);
        $code = (int)($m[1] ?? 0);
    }
    $data = $raw ? (json_decode($raw, true) ?? []) : [];
    if ($code === 201 && !empty($data['id'])) {
        return [
            'success'     => true,
            'invoice_id'  => $data['id'],
            'invoice_url' => "https://{$account}.fakturownia.pl/invoices/{$data['id']}",
            'number'      => $data['number'] ?? '',
        ];
    }
    $err = $data['error'] ?? ($data['message'] ?? ($raw ? substr($raw, 0, 200) : "HTTP $code"));
    return ['success' => false, 'error' => $err];
}
