<?php
/**
 * KRS lookup via api-krs.ms.gov.pl (bezpłatne, bez klucza API).
 * Wyszukuje po numerze KRS. Próbuje kolejno: rejestr P (przedsiębiorcy),
 * potem S (stowarzyszenia, fundacje, org. społeczne).
 */
function krs_lookup(string $krs): array {
    $krs = preg_replace('/\D/', '', $krs);
    if (!$krs) {
        return ['error' => 'Podaj numer KRS.'];
    }
    // KRS zawsze 10 cyfr z wiodącymi zerami
    $krs = str_pad($krs, 10, '0', STR_PAD_LEFT);
    if (strlen($krs) > 10) {
        return ['error' => 'Numer KRS może mieć maksymalnie 10 cyfr.'];
    }

    foreach (['P', 'S'] as $rejestr) {
        $result = _krs_fetch($krs, $rejestr);
        if (!isset($result['error'])) {
            return $result;
        }
        // Jeśli to błąd „nie znaleziono" — spróbuj następnego rejestru
        $err = $result['error'];
        if (!str_contains($err, 'Nie znaleziono') && !str_contains($err, '404')) {
            return $result; // Inny błąd (sieć, parsowanie) — nie próbuj dalej
        }
    }

    return ['error' => "Nie znaleziono podmiotu o numerze KRS {$krs} ani w rejestrze przedsiębiorców, ani stowarzyszeń/fundacji."];
}

function _krs_fetch(string $krs, string $rejestr): array {
    $url = "https://api-krs.ms.gov.pl/api/krs/OdpisAktualny/{$krs}?rejestr={$rejestr}&format=json";

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Accept: application/json\r\n",
        'ignore_errors' => true,
        'timeout'       => 10,
    ]]);

    $response = @file_get_contents($url, false, $ctx);

    if ($response === false) {
        return ['error' => 'Nie udało się połączyć z API KRS. Sprawdź połączenie internetowe serwera.'];
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('/HTTP\/\S+ (\d{3})/', $h, $m)) {
            $status = (int) $m[1];
            break;
        }
    }

    if ($status === 404) {
        return ['error' => "Nie znaleziono KRS {$krs} w rejestrze {$rejestr}."];
    }
    if ($status !== 200) {
        return ['error' => "Błąd API KRS (HTTP {$status})."];
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['odpis']['dane']['dzial1'])) {
        return ['error' => 'Nieprawidłowa odpowiedź API KRS.'];
    }

    $odpis   = $data['odpis'];
    $dzial1  = $odpis['dane']['dzial1'];
    $podmiot = $dzial1['danePodmiotu'] ?? [];
    $identyf = $podmiot['identyfikatory'] ?? [];
    $adres   = $dzial1['siedzibaIAdres']['adres'] ?? [];

    $nip = preg_replace('/\D/', '', $identyf['nip'] ?? '');

    return [
        'krs'         => $odpis['naglowekA']['numerKRS'] ?? $krs,
        'nip'         => $nip,
        'regon'       => $identyf['regon'] ?? '',
        'nazwa'       => $podmiot['nazwa'] ?? '',
        'forma_prawna'=> $podmiot['formaPrawna'] ?? '',
        'adres'       => _krs_format_address($adres),
        'rejestr'     => $rejestr,
        'rejestr_label' => $rejestr === 'P' ? 'Rejestr Przedsiębiorców' : 'Rejestr Stowarzyszeń / Fundacji',
    ];
}

function _krs_format_address(array $a): string {
    if (empty($a)) return '';
    $ulica  = trim($a['ulica'] ?? '');
    $nr     = trim($a['nrDomu'] ?? '');
    $lok    = trim($a['nrLokalu'] ?? '');
    $kod    = trim($a['kodPocztowy'] ?? '');
    $miasto = trim($a['miejscowosc'] ?? '');

    $street = $ulica;
    if ($nr)  $street .= ' ' . $nr;
    if ($lok) $street .= '/' . $lok;
    $parts = array_filter([$street, $kod ? "$kod $miasto" : $miasto]);
    return implode(', ', $parts);
}
