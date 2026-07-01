<?php
/**
 * includes/s3_client.php — generyczny klient S3 (AWS Signature V4, path-style)
 *
 * Działa z dowolnym magazynem zgodnym z S3:
 *   - Cloudflare R2  (https://{account}.r2.cloudflarestorage.com, region "auto")
 *   - AWS S3, MinIO, Backblaze B2 S3, Wasabi itp.
 *
 * Uogólnienie podpisu z includes/kdok_archive.php — bez zależności od
 * konkretnego modułu. Operuje na tablicy konfiguracji $cfg:
 *
 *   [
 *     'endpoint'   => 'https://<acc>.r2.cloudflarestorage.com', // bez / na końcu
 *     'region'     => 'auto',          // R2 = 'auto'; AWS np. 'eu-central-1'
 *     'bucket'     => 'moj-bucket',
 *     'access_key' => '...',
 *     'secret_key' => '...',
 *   ]
 *
 * Zależności: rozszerzenie cURL, hash_hmac (standard PHP).
 */

/** Generuje klucz podpisu AWS Sig V4. @internal */
function _s3_signing_key(string $secret, string $date, string $region, string $service): string {
    $k_date    = hash_hmac('sha256', $date,          'AWS4' . $secret, true);
    $k_region  = hash_hmac('sha256', $region,        $k_date,          true);
    $k_service = hash_hmac('sha256', $service,       $k_region,        true);
    return       hash_hmac('sha256', 'aws4_request', $k_service,       true);
}

/** Wyciąga komunikat błędu z odpowiedzi XML S3. @internal */
function _s3_parse_xml_error(?string $xml): string {
    if (empty($xml)) return '';
    try {
        $obj = @simplexml_load_string($xml);
        if ($obj && isset($obj->Message)) return ': ' . (string)$obj->Message;
        if ($obj && isset($obj->Code))    return ': ' . (string)$obj->Code;
    } catch (\Throwable $e) { /* nieprawidłowy XML — ignorujemy */ }
    return '';
}

/** Sprawdza, czy konfiguracja S3 jest kompletna. */
function s3_config_ready(array $cfg): bool {
    foreach (['endpoint', 'bucket', 'access_key', 'secret_key'] as $k) {
        if (empty($cfg[$k])) return false;
    }
    return true;
}

/**
 * Koduje klucz obiektu do postaci kanonicznej URI (każdy segment osobno,
 * zachowując "/"). Wymagane przez AWS Sig V4.
 * @internal
 */
function _s3_encode_key(string $key): string {
    $parts = explode('/', $key);
    $parts = array_map(fn($p) => rawurlencode($p), $parts);
    return implode('/', $parts);
}

/**
 * Wykonuje podpisane żądanie S3 (Sig V4, path-style) i zwraca surowy wynik.
 *
 * @param array  $cfg          Konfiguracja (zob. nagłówek pliku)
 * @param string $method       GET|PUT|DELETE|HEAD
 * @param string $key          Klucz obiektu (bez wiodącego /) — pusty dla operacji na buckecie
 * @param string $body         Treść (dla PUT)
 * @param string $content_type Typ MIME (dla PUT)
 * @param string $query        Canonical query string (np. 'list-type=2&max-keys=5')
 * @param int    $timeout      Timeout cURL w sekundach
 * @return array ['ok'=>bool, 'http'=>int, 'body'=>string, 'err'=>string]
 */
function s3_request(array $cfg, string $method, string $key = '', string $body = '',
                    string $content_type = '', string $query = '', int $timeout = 60): array {
    $endpoint = rtrim($cfg['endpoint'], '/');
    $region   = $cfg['region'] ?? 'auto';
    $service  = 's3';
    $bucket   = $cfg['bucket'];

    $host = parse_url($endpoint, PHP_URL_HOST);
    if ($host === null || $host === false) {
        return ['ok' => false, 'http' => 0, 'body' => '', 'err' => 'Nieprawidłowy endpoint S3'];
    }
    $port = parse_url($endpoint, PHP_URL_PORT);
    if ($port) $host .= ':' . $port;

    // Path-style: /{bucket}[/{key}]
    $canonical_uri = '/' . rawurlencode($bucket);
    if ($key !== '') {
        $canonical_uri .= '/' . _s3_encode_key(ltrim($key, '/'));
    }

    $payload_hash = hash('sha256', $body);

    $now        = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $amz_date   = $now->format('Ymd\THis\Z');
    $date_stamp = $now->format('Ymd');

    // Nagłówki kanoniczne — posortowane alfabetycznie po nazwie.
    $headers = [
        'host'                 => $host,
        'x-amz-content-sha256' => $payload_hash,
        'x-amz-date'           => $amz_date,
    ];
    if ($content_type !== '') {
        $headers['content-type'] = $content_type;
    }
    ksort($headers);

    $canonical_headers = '';
    foreach ($headers as $k => $v) {
        $canonical_headers .= $k . ':' . $v . "\n";
    }
    $signed_headers = implode(';', array_keys($headers));

    $canonical_request = implode("\n", [
        $method,
        $canonical_uri,
        $query,
        $canonical_headers,
        $signed_headers,
        $payload_hash,
    ]);

    $algorithm        = 'AWS4-HMAC-SHA256';
    $credential_scope = "{$date_stamp}/{$region}/{$service}/aws4_request";
    $string_to_sign   = implode("\n", [
        $algorithm,
        $amz_date,
        $credential_scope,
        hash('sha256', $canonical_request),
    ]);

    $signing_key = _s3_signing_key($cfg['secret_key'], $date_stamp, $region, $service);
    $signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

    $authorization = "{$algorithm} " .
        "Credential={$cfg['access_key']}/{$credential_scope}, " .
        "SignedHeaders={$signed_headers}, " .
        "Signature={$signature}";

    // Nagłówki HTTP do cURL
    $curl_headers = ["Authorization: {$authorization}"];
    foreach ($headers as $k => $v) {
        $curl_headers[] = $k . ': ' . $v;
    }

    $url = $endpoint . $canonical_uri . ($query !== '' ? '?' . $query : '');
    $ch  = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $curl_headers,
    ];
    if ($method === 'PUT' || $method === 'POST') {
        $opts[CURLOPT_POSTFIELDS] = $body;
        $curl_headers[]           = 'Content-Length: ' . strlen($body);
        $opts[CURLOPT_HTTPHEADER] = $curl_headers;
    }
    if ($method === 'HEAD') {
        $opts[CURLOPT_NOBODY] = true;
    }
    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);

    if ($err !== '') {
        return ['ok' => false, 'http' => $http, 'body' => (string)$response, 'err' => "błąd cURL: {$err}"];
    }
    return [
        'ok'   => ($http >= 200 && $http < 300),
        'http' => $http,
        'body' => (string)$response,
        'err'  => '',
    ];
}

/**
 * Wysyła obiekt do S3 (PUT).
 *
 * @return array ['ok'=>bool, 'msg'=>string, 'http'=>int]
 */
function s3_put_object(array $cfg, string $key, string $body, string $content_type = 'application/octet-stream'): array {
    if (!s3_config_ready($cfg)) {
        return ['ok' => false, 'msg' => 'Brak kompletnej konfiguracji S3 (endpoint, bucket, klucze).', 'http' => 0];
    }
    $r = s3_request($cfg, 'PUT', $key, $body, $content_type, '', 120);
    if ($r['ok']) {
        return ['ok' => true, 'msg' => "Przesłano ({$r['http']}) → {$cfg['bucket']}/{$key}", 'http' => $r['http']];
    }
    if ($r['err'] !== '') {
        return ['ok' => false, 'msg' => $r['err'], 'http' => $r['http']];
    }
    return ['ok' => false, 'msg' => "HTTP {$r['http']}" . _s3_parse_xml_error($r['body']), 'http' => $r['http']];
}

/**
 * Listuje obiekty w buckecie (ListObjectsV2).
 *
 * @return array ['ok'=>bool, 'msg'=>string, 'keys'=>array<array{key:string,size:int}>]
 */
function s3_list_objects(array $cfg, string $prefix = '', int $max_keys = 100): array {
    if (!s3_config_ready($cfg)) {
        return ['ok' => false, 'msg' => 'Brak kompletnej konfiguracji S3.', 'keys' => []];
    }
    $params = ['list-type' => '2', 'max-keys' => (string)max(1, min(1000, $max_keys))];
    if ($prefix !== '') {
        $params['prefix'] = $prefix;
    }
    ksort($params);
    $qparts = [];
    foreach ($params as $k => $v) {
        $qparts[] = rawurlencode($k) . '=' . rawurlencode($v);
    }
    $query = implode('&', $qparts);

    $r = s3_request($cfg, 'GET', '', '', '', $query, 30);
    if (!$r['ok']) {
        $msg = $r['err'] !== '' ? $r['err'] : ('HTTP ' . $r['http'] . _s3_parse_xml_error($r['body']));
        return ['ok' => false, 'msg' => $msg, 'keys' => []];
    }

    $keys = [];
    try {
        $xml = @simplexml_load_string($r['body']);
        if ($xml && isset($xml->Contents)) {
            foreach ($xml->Contents as $c) {
                $keys[] = [
                    'key'  => (string)$c->Key,
                    'size' => (int)$c->Size,
                ];
            }
        }
    } catch (\Throwable $e) { /* ignoruj */ }

    return ['ok' => true, 'msg' => 'OK', 'keys' => $keys];
}

/** Testuje połączenie z bucketem (próbny ListObjectsV2). */
function s3_test_connection(array $cfg): array {
    if (!s3_config_ready($cfg)) {
        return ['ok' => false, 'msg' => 'Brak kompletnej konfiguracji S3.'];
    }
    $r = s3_list_objects($cfg, '', 5);
    if ($r['ok']) {
        return ['ok' => true, 'msg' => "Połączono z bucketem „{$cfg['bucket']}”. Obiektów (próbka): " . count($r['keys'])];
    }
    return ['ok' => false, 'msg' => $r['msg']];
}
