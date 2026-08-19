<?php
/**
 * Klient REST EU DSS (Digital Signature Service) — walidacja podpisów eIDAS.
 *
 * Wspiera PAdES, XAdES, CAdES, ASiC. Zwraca ustrukturyzowane wyniki:
 * wskazanie eIDAS (TOTAL_PASSED / INDETERMINATE / TOTAL_FAILED),
 * format podpisu (PAdES-BASELINE-LT, XAdES-BASELINE-B, …),
 * poziom kwalifikacji (QESIG / ADESIG / …) i trust anchor (wystawca CA).
 *
 * Konfiguracja:
 *   dss_enabled  = '1' / '0'   (org_setting)
 *   dss_url      = 'http://localhost:8765'
 *   dss_timeout  = '30'  (sekundy)
 *
 * REST API DSS: POST {dss_url}/services/rest/validation/validateSignature
 * Dokumentacja: https://ec.europa.eu/digital-building-blocks/DSS/webapp-demo/doc/restdoc.html
 */

if (!function_exists('dss_is_enabled')) :

function dss_is_enabled(): bool {
    return org_setting('dss_enabled') === '1' && dss_url() !== '';
}

function dss_url(): string {
    return rtrim((string)org_setting('dss_url'), '/');
}

/**
 * Waliduje plik przez serwer EU DSS.
 *
 * Zwraca tablicę z kluczami:
 *   ok              bool
 *   error           ?string   (tylko przy błędzie połączenia)
 *   signatures      array     (każda: id, format, indication, sub_indication,
 *                              signed_by, signing_time, best_time, trust_anchor,
 *                              qualification, errors[], warnings[], infos[])
 *   valid_count     int
 *   total_count     int
 *   validation_time string    (Y-m-d H:i)
 *   document_name   string
 */
function dss_validate_file(string $path, string $name): array {
    $base = dss_url();
    if (!$base) {
        return ['ok' => false, 'error' => 'Serwer EU DSS nie jest skonfigurowany (brak dss_url).'];
    }
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'Plik nie istnieje na serwerze.'];
    }

    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'error' => 'Nie można odczytać pliku.'];
    }

    $payload = json_encode([
        'signedDocument' => [
            'bytes' => base64_encode($bytes),
            'name'  => $name,
        ],
        'policy'                  => null,
        'reportType'              => 'SIMPLE',
        'tokenExtractionStrategy' => 'NONE',
        'signatureId'             => null,
    ], JSON_UNESCAPED_UNICODE);

    $timeout = max(5, min(120, (int)(org_setting('dss_timeout') ?: 30)));

    $ctx = stream_context_create([
        'http' => [
            'method'         => 'POST',
            'header'         => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content'        => $payload,
            'timeout'        => $timeout,
            'ignore_errors'  => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $url  = $base . '/services/rest/validation/validateSignature';
    $resp = @file_get_contents($url, false, $ctx);
    $hdrs = $http_response_header ?? [];

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Brak połączenia z serwerem EU DSS (' . $base . '). Sprawdź, czy serwer działa.'];
    }

    preg_match('/HTTP\/\S+\s+(\d+)/', $hdrs[0] ?? '', $sm);
    if ((int)($sm[1] ?? 0) !== 200) {
        $code = (int)($sm[1] ?? 0);
        return ['ok' => false, 'error' => 'Serwer EU DSS zwrócił błąd HTTP ' . $code . '.'];
    }

    $data = @json_decode($resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Nieprawidłowa odpowiedź serwera EU DSS (błąd JSON).'];
    }

    return _dss_parse_simple_report($data);
}

/**
 * Parsuje SimpleReport z DSS REST API → ujednolicona struktura wyników.
 * DSS 5.x i 6.x używają różnych kluczy (camelCase vs PascalCase) — obsługujemy obie.
 */
function _dss_parse_simple_report(array $data): array {
    $sigs  = [];
    $items = $data['signatureOrTimestamp']
          ?? $data['SignatureOrTimestamp']
          ?? [];

    foreach ($items as $item) {
        $sig = $item['signature'] ?? $item['Signature']
            ?? $item['timestamp'] ?? $item['Timestamp']
            ?? $item;
        if (!is_array($sig)) continue;

        $indication = (string)($sig['indication'] ?? $sig['Indication'] ?? '');
        // Normalizacja: TOTAL-PASSED → TOTAL_PASSED
        $indication = strtoupper(str_replace('-', '_', $indication));

        $qual = (string)(
            $sig['qualification']   ?? $sig['Qualification']
         ?? $sig['signatureLevel']  ?? $sig['SignatureLevel']
         ?? $sig['QualificationDetails']['ValidationTime']['qualification']
         ?? ''
        );

        $sigs[] = [
            'id'             => (string)($sig['id']   ?? $sig['Id']   ?? ''),
            'format'         => (string)($sig['signatureFormat'] ?? $sig['SignatureFormat'] ?? ''),
            'indication'     => $indication,
            'sub_indication' => (string)($sig['subIndication'] ?? $sig['SubIndication'] ?? ''),
            'signed_by'      => (string)($sig['signedBy']      ?? $sig['SignedBy']      ?? ''),
            'signing_time'   => _dss_fmt_dt($sig['signingTime']         ?? $sig['SigningTime']         ?? ''),
            'best_time'      => _dss_fmt_dt($sig['bestSignatureTime']    ?? $sig['BestSignatureTime']   ?? ''),
            'trust_anchor'   => (string)($sig['trustAnchor'] ?? $sig['TrustAnchor'] ?? ''),
            'qualification'  => $qual,
            'errors'         => (array)($sig['errors']   ?? $sig['Errors']   ?? []),
            'warnings'       => (array)($sig['warnings'] ?? $sig['Warnings'] ?? []),
            'infos'          => (array)($sig['infos']    ?? $sig['Infos']    ?? []),
            'is_timestamp'   => isset($item['timestamp']) || isset($item['Timestamp']),
        ];
    }

    return [
        'ok'              => true,
        'error'           => null,
        'signatures'      => $sigs,
        'valid_count'     => (int)($data['validSignaturesCount'] ?? $data['ValidSignaturesCount'] ?? 0),
        'total_count'     => (int)($data['signaturesCount']      ?? $data['SignaturesCount']      ?? count($sigs)),
        'validation_time' => _dss_fmt_dt($data['validationTime'] ?? $data['ValidationTime'] ?? ''),
        'document_name'   => (string)($data['documentName'] ?? $data['DocumentName'] ?? ''),
    ];
}

function _dss_fmt_dt(string $dt): string {
    if (!$dt) return '';
    $ts = @strtotime($dt);
    return $ts ? date('Y-m-d H:i', $ts) : substr($dt, 0, 16);
}

/** Klasa Bootstrap dla wskazania DSS (do badge'ów w PHP). */
function dss_indication_class(string $ind): string {
    return match(true) {
        str_contains($ind, 'PASSED')        => 'success',
        str_contains($ind, 'FAILED')        => 'danger',
        str_contains($ind, 'INDETERMINATE') => 'warning',
        default                             => 'secondary',
    };
}

/** Etykieta poziomu kwalifikacji eIDAS. */
function dss_qualification_label(string $q): string {
    return match(strtoupper($q)) {
        'QESIG'                         => 'Kwalifikowany podpis elektroniczny (QES)',
        'QESEAL'                        => 'Kwalifikowana pieczęć elektroniczna (QESeal)',
        'ADESIG_QC', 'ADESSIG_QC',
        'ADES_QC'                       => 'Podpis zaawansowany z certyfikatem kwalifikowanym (AdES/QC)',
        'ADESIG', 'ADESSIG'             => 'Podpis zaawansowany (AdES)',
        'INDETERMINATE_ADESIG_QC',
        'INDETERMINATE_QESIG'           => 'Nieokreślony — weryfikacja niemożliwa',
        'NOT_ADES'                      => 'Niekwalifikowany / poza zakresem eIDAS',
        default                         => $q ?: '—',
    };
}

/** Test połączenia z serwerem DSS. Zwraca ['ok'=>bool, 'version'=>string, 'error'=>string]. */
function dss_test_connection(): array {
    $base = dss_url();
    if (!$base) return ['ok' => false, 'error' => 'Brak skonfigurowanego URL serwera DSS.'];

    $timeout = 10;
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);

    // Próba endpointu wersji (DSS 6.x), potem alternatywnego
    foreach (['/services/rest/server/v1', '/services/rest/server'] as $ep) {
        $resp = @file_get_contents($base . $ep, false, $ctx);
        $hdrs = $http_response_header ?? [];
        preg_match('/HTTP\/\S+\s+(\d+)/', $hdrs[0] ?? '', $sm);
        $code = (int)($sm[1] ?? 0);
        if ($resp !== false && in_array($code, [200, 204])) {
            $info = @json_decode($resp, true);
            $ver  = (string)($info['version'] ?? $info['Version'] ?? '');
            return ['ok' => true, 'version' => $ver ?: 'nieznana', 'error' => ''];
        }
    }

    // Ostatnia szansa: sprawdź czy root odpowiada (może być inne API)
    $resp = @file_get_contents($base . '/', false, $ctx);
    if ($resp !== false) {
        return ['ok' => true, 'version' => '', 'error' => ''];
    }

    return ['ok' => false, 'error' => 'Serwer EU DSS niedostępny pod adresem: ' . $base];
}

endif;
