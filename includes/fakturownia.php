<?php
/**
 * Helper API fakturownia.pl — używany przez panel SaaS do fakturowania tenantów.
 * Credentials przekazywane jako parametry (nie z bazy tenanta).
 */

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
