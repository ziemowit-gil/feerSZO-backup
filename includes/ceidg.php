<?php
function ceidg_api_key(): string {
    try {
        return db_one("SELECT value FROM settings WHERE key_='ceidg_api_key'")['value'] ?? '';
    } catch (\Exception $e) {
        return '';
    }
}

function ceidg_lookup(string $nip): array {
    $nip = preg_replace('/\D/', '', $nip);
    if (strlen($nip) !== 10) {
        return ['error' => 'NIP musi mieć 10 cyfr.'];
    }

    $key = ceidg_api_key();
    if (!$key) {
        return ['error' => 'Brak klucza API CEIDG. Skonfiguruj go w Administracja → Ustawienia CEIDG.'];
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Authorization: Bearer {$key}\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
        'timeout'       => 10,
    ]]);

    $url      = 'https://dane.biznes.gov.pl/api/ceidg/v2/firma?nip=' . urlencode($nip);
    $response = @file_get_contents($url, false, $ctx);

    if ($response === false) {
        return ['error' => 'Nie udało się połączyć z API CEIDG. Sprawdź połączenie internetowe serwera.'];
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('/HTTP\/\S+ (\d{3})/', $h, $m)) {
            $status = (int) $m[1];
            break;
        }
    }

    $data = json_decode($response, true);

    if ($status === 401 || $status === 403) {
        return ['error' => 'Nieprawidłowy klucz API CEIDG (błąd autoryzacji).'];
    }

    if ($status === 404 || (isset($data['firmy']) && count($data['firmy']) === 0)) {
        return ['error' => 'Nie znaleziono firmy o podanym NIP w CEIDG.'];
    }

    if ($status !== 200 || !$data) {
        $msg = $data['komunikat'] ?? $data['message'] ?? ('Błąd API CEIDG (HTTP ' . $status . ')');
        return ['error' => $msg];
    }

    // Obsługa obu formatów odpowiedzi: { firma: {} } i { firmy: [{...}] }
    $firma = $data['firma'] ?? ($data['firmy'][0] ?? null);
    if (!$firma) {
        return ['error' => 'Brak danych w odpowiedzi API CEIDG.'];
    }

    $imie     = trim($firma['imie'] ?? '');
    $nazwisko = trim($firma['nazwisko'] ?? '');
    $nazwa    = trim($firma['nazwa'] ?? ($imie && $nazwisko ? "$imie $nazwisko" : ''));

    return [
        'nip'      => $firma['nip'] ?? $nip,
        'regon'    => $firma['regon'] ?? '',
        'nazwa'    => $nazwa,
        'imie'     => $imie,
        'nazwisko' => $nazwisko,
        'adres'    => _ceidg_format_address($firma['adresDzialalnosci'] ?? $firma['adresGlowny'] ?? []),
        'status'   => $firma['statusDzialalnosci'] ?? '',
        'aktywna'  => ($firma['statusDzialalnosci'] ?? '') === 'AKTYWNA',
    ];
}

function _ceidg_format_address(array $a): string {
    if (empty($a)) return '';

    $ulica   = trim($a['ulica'] ?? '');
    $budynek = trim($a['nrBudynku'] ?? $a['budynek'] ?? '');
    $lokal   = trim($a['nrLokalu'] ?? $a['lokal'] ?? '');
    $kod     = trim($a['kodPocztowy'] ?? '');
    $miasto  = trim($a['miejscowosc'] ?? $a['siedziba'] ?? '');

    $street = $ulica;
    if ($budynek) $street .= ' ' . $budynek;
    if ($lokal)   $street .= '/' . $lokal;

    $parts = array_filter([$street, $kod ? "$kod $miasto" : $miasto]);
    return implode(', ', $parts);
}
