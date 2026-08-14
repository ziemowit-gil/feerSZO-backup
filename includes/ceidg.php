<?php
function ceidg_api_key(): string {
    try {
        return db_one("SELECT value FROM settings WHERE key_='ceidg_api_key'")['value'] ?? '';
    } catch (\Exception $e) {
        return '';
    }
}

/**
 * Wyszukuje podmiot po NIP.
 * Priorytet: stare API CEIDG (jeśli klucz skonfigurowany) → Biała Lista VAT MF (bezklucz).
 */
function ceidg_lookup(string $nip): array {
    $nip = preg_replace('/\D/', '', $nip);
    if (strlen($nip) !== 10) {
        return ['error' => 'NIP musi mieć 10 cyfr.'];
    }

    $key = ceidg_api_key();
    if ($key) {
        $result = _ceidg_lookup_gov($nip, $key);
        if (!isset($result['error'])) return $result;
    }

    return _ceidg_lookup_mf($nip);
}

/** Stary endpoint CEIDG dane.biznes.gov.pl (może być wyłączony przez rząd). */
function _ceidg_lookup_gov(string $nip, string $key): array {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Authorization: Bearer {$key}\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
        'timeout'       => 8,
    ]]);
    $url      = 'https://dane.biznes.gov.pl/api/ceidg/v2/firma?nip=' . urlencode($nip);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return ['error' => 'Brak połączenia z API CEIDG.'];

    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('/HTTP\/\S+ (\d{3})/', $h, $m)) { $status = (int)$m[1]; break; }
    }
    $data = json_decode($response, true);

    if ($status === 401 || $status === 403) return ['error' => 'Nieprawidłowy klucz API CEIDG.'];
    if ($status === 404 || (isset($data['firmy']) && count($data['firmy']) === 0))
        return ['error' => 'Nie znaleziono w CEIDG.'];
    if ($status !== 200 || !$data) return ['error' => 'Błąd API CEIDG (HTTP ' . $status . ').'];

    $firma = $data['firma'] ?? ($data['firmy'][0] ?? null);
    if (!$firma) return ['error' => 'Brak danych w odpowiedzi CEIDG.'];

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

/** Biała Lista VAT — API MF, darmowe, bez klucza, dostępne publicznie. */
function _ceidg_lookup_mf(string $nip): array {
    $url = 'https://wl-api.mf.gov.pl/api/search/nip/' . urlencode($nip) . '?date=' . date('Y-m-d');
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Accept: application/json\r\n",
        'ignore_errors' => true,
        'timeout'       => 10,
    ]]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return ['error' => 'Nie udało się połączyć z serwisem weryfikacji NIP.'];

    $data = json_decode($response, true);
    $subj = $data['result']['subject'] ?? null;
    if (!$subj) {
        return ['error' => $data['message'] ?? 'Nie znaleziono podmiotu o podanym NIP.'];
    }

    $nazwa = trim($subj['name'] ?? '');
    $adres = trim($subj['workingAddress'] ?? $subj['residenceAddress'] ?? '');

    return [
        'nip'      => $subj['nip'] ?? $nip,
        'regon'    => $subj['regon'] ?? '',
        'nazwa'    => $nazwa,
        'imie'     => '',
        'nazwisko' => '',
        'adres'    => $adres,
        'status'   => $subj['statusVat'] ?? '',
        'aktywna'  => ($subj['statusVat'] ?? '') === 'Czynny',
    ];
}

function _ceidg_format_address(array $a): string {
    if (empty($a)) return '';
    $ulica   = trim($a['ulica'] ?? '');
    $budynek = trim($a['nrBudynku'] ?? $a['budynek'] ?? '');
    $lokal   = trim($a['nrLokalu'] ?? $a['lokal'] ?? '');
    $kod     = trim($a['kodPocztowy'] ?? '');
    $miasto  = trim($a['miejscowosc'] ?? $a['siedziba'] ?? '');
    $street  = $ulica;
    if ($budynek) $street .= ' ' . $budynek;
    if ($lokal)   $street .= '/' . $lokal;
    $parts = array_filter([$street, $kod ? "$kod $miasto" : $miasto]);
    return implode(', ', $parts);
}
