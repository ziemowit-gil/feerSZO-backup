<?php
/**
 * includes/kdok_archive.php — eArchiwum EOD Dokumentów Księgowych
 *
 * Obsługuje wysyłkę finalnych PDF-ów na zdalne repozytoria:
 *   - FTP / SFTP  (klasyczne PHP ftp_*)
 *   - Cloudflare R2 (S3-compatible, AWS Signature V4)
 *
 * Ustawienia przez org_setting():
 *   kdok_archive_enabled  — '0'/'1'
 *   kdok_ftp_enabled      — '0'/'1'
 *   kdok_ftp_host         — hostname
 *   kdok_ftp_port         — port (domyślnie 21)
 *   kdok_ftp_user         — użytkownik
 *   kdok_ftp_pass         — hasło
 *   kdok_ftp_path         — ścieżka bazowa (domyślnie /archive)
 *   kdok_ftp_passive      — tryb pasywny 0/1 (domyślnie 1)
 *   kdok_r2_enabled       — '0'/'1'
 *   kdok_r2_account_id    — Cloudflare account ID
 *   kdok_r2_access_key    — R2 Access Key ID
 *   kdok_r2_secret_key    — R2 Secret Access Key
 *   kdok_r2_bucket        — nazwa bucketu
 *   kdok_r2_prefix        — prefiks ścieżki (domyślnie 'kdok')
 *
 * Zależności: kdok_get(), kdok_log(), kdok_generate_final_pdf(),
 *             kdok_one(), org_setting(), UPLOAD_DIR
 */

// ── 1. Ścieżka zdalna ────────────────────────────────────────────────────────

/**
 * Buduje zdalną ścieżkę pliku archiwum dla dokumentu.
 *
 * Format: {prefix}/{rok}/{miesiac_padded}/{doc_number_safe}.pdf
 * Np.    kdok/2026/06/KDOK_0001_2026.pdf
 *
 * @param array $doc  Rekord z kdok_documents (pola: number, rok, miesiac, created_at)
 * @return string
 */
function kdok_archive_remote_path(array $doc): string {
    $prefix = org_setting('kdok_r2_prefix');
    if ($prefix === '') {
        $prefix = 'kdok';
    }

    // Rok i miesiąc — z pól dokumentu lub z created_at
    $rok     = (int)($doc['rok'] ?? 0);
    $miesiac = (int)($doc['miesiac'] ?? 0);

    if ($rok === 0 || $miesiac === 0) {
        $ts      = strtotime($doc['created_at'] ?? 'now');
        $rok     = $rok     ?: (int)date('Y', $ts);
        $miesiac = $miesiac ?: (int)date('n', $ts);
    }

    $miesiac_padded = sprintf('%02d', $miesiac);

    // Bezpieczna nazwa pliku (zastąp znaki specjalne podkreślnikiem)
    $doc_number_safe = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $doc['number'] ?? 'DOC');

    return "{$prefix}/{$rok}/{$miesiac_padded}/{$doc_number_safe}.pdf";
}

// ── 2. Wysyłka FTP ───────────────────────────────────────────────────────────

/**
 * Wysyła plik na serwer FTP.
 *
 * @param string $local_path   Pełna lokalna ścieżka do pliku
 * @param string $remote_path  Ścieżka zdalna względem katalogu bazowego (kdok_ftp_path)
 * @return array ['ok' => bool, 'msg' => string]
 */
function kdok_archive_ftp(string $local_path, string $remote_path): array {
    $host    = org_setting('kdok_ftp_host');
    $port    = (int)(org_setting('kdok_ftp_port') ?: 21);
    $user    = org_setting('kdok_ftp_user');
    $pass    = org_setting('kdok_ftp_pass');
    $base    = rtrim(org_setting('kdok_ftp_path') ?: '/archive', '/');
    $passive = org_setting('kdok_ftp_passive') !== '0';

    if ($host === '' || $user === '') {
        return ['ok' => false, 'msg' => 'FTP: brak konfiguracji (host lub użytkownik)'];
    }

    // Połącz
    $conn = @ftp_connect($host, $port, 15);
    if ($conn === false) {
        return ['ok' => false, 'msg' => "FTP: nie można połączyć z {$host}:{$port}"];
    }

    // Logowanie
    if (!@ftp_login($conn, $user, $pass)) {
        ftp_close($conn);
        return ['ok' => false, 'msg' => "FTP: błąd logowania dla użytkownika {$user}"];
    }

    // Tryb pasywny
    ftp_pasv($conn, $passive);

    // Pełna zdalna ścieżka
    $full_remote = $base . '/' . ltrim($remote_path, '/');
    $dir_part    = dirname($full_remote);

    // Utwórz katalogi rekurencyjnie
    $segments   = explode('/', ltrim($dir_part, '/'));
    $current    = '';
    foreach ($segments as $seg) {
        if ($seg === '') {
            continue;
        }
        $current .= '/' . $seg;
        try {
            @ftp_mkdir($conn, $current);
        } catch (\Throwable $e) {
            // Katalog już istnieje — ignorujemy
        }
    }

    // Wyślij plik (tryb binarny)
    $ok = @ftp_put($conn, $full_remote, $local_path, FTP_BINARY);
    ftp_close($conn);

    if ($ok) {
        return ['ok' => true, 'msg' => "FTP: przesłano → {$full_remote}"];
    }
    return ['ok' => false, 'msg' => "FTP: błąd przesyłania pliku → {$full_remote}"];
}

// ── 3. Wysyłka Cloudflare R2 (S3-compatible, AWS Sig V4) ─────────────────────

/**
 * Wysyła plik do Cloudflare R2 przy użyciu AWS Signature Version 4.
 *
 * @param string $local_path   Pełna lokalna ścieżka do pliku
 * @param string $remote_path  Klucz obiektu w buckecie (bez wiodącego /)
 * @return array ['ok' => bool, 'msg' => string]
 */
function kdok_archive_r2(string $local_path, string $remote_path): array {
    $account_id = org_setting('kdok_r2_account_id');
    $access_key = org_setting('kdok_r2_access_key');
    $secret_key = org_setting('kdok_r2_secret_key');
    $bucket     = org_setting('kdok_r2_bucket');

    if ($account_id === '' || $access_key === '' || $secret_key === '' || $bucket === '') {
        return ['ok' => false, 'msg' => 'R2: brak konfiguracji (account_id, access_key, secret_key lub bucket)'];
    }

    if (!is_file($local_path)) {
        return ['ok' => false, 'msg' => "R2: plik lokalny nie istnieje: {$local_path}"];
    }

    $body         = file_get_contents($local_path);
    if ($body === false) {
        return ['ok' => false, 'msg' => "R2: nie można odczytać pliku: {$local_path}"];
    }

    $payload_hash = hash('sha256', $body);
    $content_type = 'application/pdf';
    $method       = 'PUT';
    $service      = 's3';
    $region       = 'auto';
    $endpoint     = "https://{$account_id}.r2.cloudflarestorage.com";
    $object_key   = '/' . $bucket . '/' . ltrim($remote_path, '/');
    $host         = "{$account_id}.r2.cloudflarestorage.com";

    $now        = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $amz_date   = $now->format('Ymd\THis\Z');  // e.g. 20260604T120000Z
    $date_stamp = $now->format('Ymd');           // e.g. 20260604

    // ── Canonical Request ──────────────────────────────────────────────────
    // Headers muszą być posortowane alfabetycznie
    $canonical_headers =
        "content-type:{$content_type}\n" .
        "host:{$host}\n" .
        "x-amz-content-sha256:{$payload_hash}\n" .
        "x-amz-date:{$amz_date}\n";

    $signed_headers = 'content-type;host;x-amz-content-sha256;x-amz-date';

    $canonical_uri   = $object_key;
    $canonical_query = '';  // PUT nie używa query string

    $canonical_request = implode("\n", [
        $method,
        $canonical_uri,
        $canonical_query,
        $canonical_headers,
        $signed_headers,
        $payload_hash,
    ]);

    // ── String to Sign ─────────────────────────────────────────────────────
    $algorithm    = 'AWS4-HMAC-SHA256';
    $credential_scope = "{$date_stamp}/{$region}/{$service}/aws4_request";
    $string_to_sign = implode("\n", [
        $algorithm,
        $amz_date,
        $credential_scope,
        hash('sha256', $canonical_request),
    ]);

    // ── Signing Key ────────────────────────────────────────────────────────
    $signing_key = _kdok_r2_signing_key($secret_key, $date_stamp, $region, $service);

    // ── Signature ──────────────────────────────────────────────────────────
    $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

    $authorization =
        "{$algorithm} " .
        "Credential={$access_key}/{$credential_scope}, " .
        "SignedHeaders={$signed_headers}, " .
        "Signature={$signature}";

    // ── cURL PUT ───────────────────────────────────────────────────────────
    $url = $endpoint . $object_key;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            "Authorization: {$authorization}",
            "Content-Type: {$content_type}",
            "Host: {$host}",
            "x-amz-content-sha256: {$payload_hash}",
            "x-amz-date: {$amz_date}",
            "Content-Length: " . strlen($body),
        ],
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err !== '') {
        return ['ok' => false, 'msg' => "R2: błąd cURL: {$curl_err}"];
    }

    if ($http_code === 200 || $http_code === 204) {
        return ['ok' => true, 'msg' => "R2: przesłano ({$http_code}) → {$bucket}/{$remote_path}"];
    }

    // Spróbuj wyciągnąć komunikat XML z odpowiedzi R2
    $error_msg = _kdok_r2_parse_xml_error($response);
    return ['ok' => false, 'msg' => "R2: HTTP {$http_code}{$error_msg}"];
}

/**
 * Generuje klucz podpisu AWS Sig V4.
 * @internal
 */
function _kdok_r2_signing_key(string $secret, string $date, string $region, string $service): string {
    $k_date    = hash_hmac('sha256', $date,              'AWS4' . $secret, true);
    $k_region  = hash_hmac('sha256', $region,            $k_date,          true);
    $k_service = hash_hmac('sha256', $service,           $k_region,        true);
    return       hash_hmac('sha256', 'aws4_request',     $k_service,       true);
}

/**
 * Parsuje komunikat błędu z odpowiedzi XML S3/R2.
 * @internal
 */
function _kdok_r2_parse_xml_error(string $xml): string {
    if (empty($xml)) {
        return '';
    }
    try {
        $obj = @simplexml_load_string($xml);
        if ($obj && isset($obj->Message)) {
            return ': ' . (string)$obj->Message;
        }
        if ($obj && isset($obj->Code)) {
            return ': ' . (string)$obj->Code;
        }
    } catch (\Throwable $e) {
        // XML nieprawidłowy — ignorujemy
    }
    return '';
}

// ── 4. Główna funkcja wysyłki ─────────────────────────────────────────────────

/**
 * Wysyła dokument na wszystkie skonfigurowane backendy archiwum.
 *
 * @param int $doc_id  ID dokumentu w kdok_documents
 * @return array [
 *   'results' => [['backend'=>string,'ok'=>bool,'msg'=>string], ...],
 *   'any_ok'  => bool
 * ]
 */
function kdok_archive_push(int $doc_id): array {
    $results = [];

    // Załaduj dokument
    $doc = kdok_get($doc_id);
    if (!$doc) {
        return [
            'results' => [['backend' => 'system', 'ok' => false, 'msg' => "Dokument #{$doc_id} nie istnieje"]],
            'any_ok'  => false,
        ];
    }

    // Znajdź ostatni wygenerowany PDF
    $pdf_row = kdok_one(
        "SELECT * FROM kdok_generated_pdf WHERE doc_id = ? ORDER BY id DESC LIMIT 1",
        [$doc_id]
    );

    if (!$pdf_row) {
        // Spróbuj wygenerować PDF
        try {
            $generated_path = kdok_generate_final_pdf($doc_id);
            // Po generacji pobierz nowy rekord
            $pdf_row = kdok_one(
                "SELECT * FROM kdok_generated_pdf WHERE doc_id = ? ORDER BY id DESC LIMIT 1",
                [$doc_id]
            );
        } catch (\Throwable $e) {
            return [
                'results' => [['backend' => 'system', 'ok' => false, 'msg' => 'Brak PDF i nie udało się wygenerować: ' . $e->getMessage()]],
                'any_ok'  => false,
            ];
        }
    }

    if (!$pdf_row || empty($pdf_row['file_path'])) {
        return [
            'results' => [['backend' => 'system', 'ok' => false, 'msg' => 'Brak pliku PDF dla tego dokumentu']],
            'any_ok'  => false,
        ];
    }

    $local_path  = UPLOAD_DIR . ltrim($pdf_row['file_path'], '/');
    $remote_path = kdok_archive_remote_path($doc);

    if (!is_file($local_path)) {
        return [
            'results' => [['backend' => 'system', 'ok' => false, 'msg' => "Plik PDF nie istnieje na dysku: {$local_path}"]],
            'any_ok'  => false,
        ];
    }

    // ── FTP ───────────────────────────────────────────────────────────────
    if (org_setting('kdok_ftp_enabled') === '1') {
        $res = kdok_archive_ftp($local_path, $remote_path);
        $results[] = array_merge(['backend' => 'ftp'], $res);
    }

    // ── R2 ────────────────────────────────────────────────────────────────
    if (org_setting('kdok_r2_enabled') === '1') {
        $res = kdok_archive_r2($local_path, $remote_path);
        $results[] = array_merge(['backend' => 'r2'], $res);
    }

    if (empty($results)) {
        $results[] = ['backend' => 'system', 'ok' => false, 'msg' => 'Żaden backend archiwum nie jest włączony'];
    }

    $any_ok = (bool)array_filter($results, fn($r) => $r['ok']);

    // ── Zaloguj wynik w historii dokumentu ───────────────────────────────
    $note_parts = [];
    foreach ($results as $r) {
        $status       = $r['ok'] ? 'OK' : 'BŁĄD';
        $note_parts[] = "[{$r['backend']}] {$status}: {$r['msg']}";
    }
    $note = 'eArchiwum: ' . implode(' | ', $note_parts);

    kdok_log($doc_id, 'archiwizacja', $note);

    return [
        'results' => $results,
        'any_ok'  => $any_ok,
    ];
}

// ── 5. Test połączenia FTP ────────────────────────────────────────────────────

/**
 * Testuje połączenie FTP i listuje katalog bazowy.
 *
 * @return array ['ok' => bool, 'msg' => string]
 */
function kdok_archive_test_ftp(): array {
    $host    = org_setting('kdok_ftp_host');
    $port    = (int)(org_setting('kdok_ftp_port') ?: 21);
    $user    = org_setting('kdok_ftp_user');
    $pass    = org_setting('kdok_ftp_pass');
    $base    = rtrim(org_setting('kdok_ftp_path') ?: '/archive', '/');
    $passive = org_setting('kdok_ftp_passive') !== '0';

    if ($host === '' || $user === '') {
        return ['ok' => false, 'msg' => 'FTP: brak konfiguracji (host lub użytkownik)'];
    }

    $conn = @ftp_connect($host, $port, 10);
    if ($conn === false) {
        return ['ok' => false, 'msg' => "FTP: nie można połączyć z {$host}:{$port}"];
    }

    if (!@ftp_login($conn, $user, $pass)) {
        ftp_close($conn);
        return ['ok' => false, 'msg' => "FTP: błąd logowania dla użytkownika {$user}"];
    }

    ftp_pasv($conn, $passive);

    $sys  = @ftp_systype($conn);
    $list = @ftp_nlist($conn, $base);
    ftp_close($conn);

    $list_info = ($list !== false) ? ('Lista katalogu: ' . count($list) . ' elementów') : "Katalog {$base} pusty lub niedostępny";

    return [
        'ok'  => true,
        'msg' => "FTP: połączono z {$host}:{$port} ({$sys}). {$list_info}",
    ];
}

// ── 6. Test połączenia R2 ─────────────────────────────────────────────────────

/**
 * Testuje połączenie z Cloudflare R2 przez pobranie listy obiektów w buckecie.
 *
 * @return array ['ok' => bool, 'msg' => string]
 */
function kdok_archive_test_r2(): array {
    $account_id = org_setting('kdok_r2_account_id');
    $access_key = org_setting('kdok_r2_access_key');
    $secret_key = org_setting('kdok_r2_secret_key');
    $bucket     = org_setting('kdok_r2_bucket');

    if ($account_id === '' || $access_key === '' || $secret_key === '' || $bucket === '') {
        return ['ok' => false, 'msg' => 'R2: brak konfiguracji (account_id, access_key, secret_key lub bucket)'];
    }

    $method      = 'GET';
    $service     = 's3';
    $region      = 'auto';
    $host        = "{$account_id}.r2.cloudflarestorage.com";
    $endpoint    = "https://{$host}";
    $object_key  = '/' . $bucket;
    $query       = 'list-type=2&max-keys=5';

    $now        = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $amz_date   = $now->format('Ymd\THis\Z');
    $date_stamp = $now->format('Ymd');

    $payload_hash = hash('sha256', '');  // GET — puste body

    $canonical_headers =
        "host:{$host}\n" .
        "x-amz-content-sha256:{$payload_hash}\n" .
        "x-amz-date:{$amz_date}\n";

    $signed_headers = 'host;x-amz-content-sha256;x-amz-date';

    $canonical_request = implode("\n", [
        $method,
        $object_key,
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

    $signing_key = _kdok_r2_signing_key($secret_key, $date_stamp, $region, $service);
    $signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

    $authorization =
        "{$algorithm} " .
        "Credential={$access_key}/{$credential_scope}, " .
        "SignedHeaders={$signed_headers}, " .
        "Signature={$signature}";

    $url = $endpoint . $object_key . '?' . $query;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            "Authorization: {$authorization}",
            "Host: {$host}",
            "x-amz-content-sha256: {$payload_hash}",
            "x-amz-date: {$amz_date}",
        ],
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err !== '') {
        return ['ok' => false, 'msg' => "R2: błąd cURL: {$curl_err}"];
    }

    if ($http_code === 200) {
        // Zlicz obiekty w odpowiedzi XML
        $count = substr_count($response, '<Key>');
        return ['ok' => true, 'msg' => "R2: połączono z buckietem '{$bucket}'. Znaleziono obiektów (próbka): {$count}"];
    }

    $error_msg = _kdok_r2_parse_xml_error($response);
    return ['ok' => false, 'msg' => "R2: HTTP {$http_code}{$error_msg}"];
}
