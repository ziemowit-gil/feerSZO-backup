<?php
/**
 * Web Push — czysta PHP bez Composera.
 * RFC 8292 VAPID (JWT ES256) + RFC 8291 aes128gcm content encryption.
 * Wymaga: PHP 8.1+ (openssl_pkey_derive), OpenSSL z prime256v1, cURL.
 */

// ── Base64url ─────────────────────────────────────────────────────────────────

function _push_b64u(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function _push_b64u_decode(string $data): string {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return (string)base64_decode(strtr($data, '-_', '+/'), true);
}

// ── HKDF (RFC 5869) ───────────────────────────────────────────────────────────

function _push_hkdf_extract(string $salt, string $ikm): string {
    return hash_hmac('sha256', $ikm, $salt, true);
}

function _push_hkdf_expand(string $prk, string $info, int $length): string {
    $out = '';
    $t   = '';
    for ($i = 1; strlen($out) < $length; $i++) {
        $t    = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
        $out .= $t;
    }
    return substr($out, 0, $length);
}

// ── EC key helpers ────────────────────────────────────────────────────────────

/**
 * Importuje surowy 65-bajtowy klucz publiczny EC P-256 (0x04‖x‖y) jako zasób OpenSSL.
 */
function _push_import_ec_pub(string $raw65): \OpenSSLAsymmetricKey|false {
    static $hdr = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
                . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";
    $pem = "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($hdr . $raw65), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

/**
 * Eksportuje klucz publiczny z zasobu OpenSSL do postaci 65-bajtowej (0x04‖x‖y).
 */
function _push_ec_pub_to_raw65(\OpenSSLAsymmetricKey $key): string {
    $d = openssl_pkey_get_details($key);
    $x = str_pad((string)($d['ec']['x'] ?? ''), 32, "\x00", STR_PAD_LEFT);
    $y = str_pad((string)($d['ec']['y'] ?? ''), 32, "\x00", STR_PAD_LEFT);
    return "\x04" . $x . $y;
}

// ── VAPID JWT (ES256) ─────────────────────────────────────────────────────────

/**
 * Konwertuje sygnaturę DER-ECDSA (OpenSSL) na 64-bajtowy format raw r‖s.
 */
function _push_der_ecdsa_to_raw(string $der): string {
    $off = 1;                              // pomiń tag 0x30 (SEQUENCE)
    $lb  = ord($der[$off++]);              // bajt długości
    if ($lb & 0x80) $off += $lb & 0x7f;   // pomiń wielobajtową długość
    // r
    $off++;                                // pomiń tag 0x02 (INTEGER)
    $rl  = ord($der[$off++]);
    $r   = substr($der, $off, $rl); $off += $rl;
    // s
    $off++;
    $sl  = ord($der[$off++]);
    $s   = substr($der, $off, $sl);
    // Normalizuj do 32 bajtów (INT może mieć wiodące 0x00 dla znaku)
    $r = substr(str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT), -32);
    $s = substr(str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT), -32);
    return $r . $s;
}

function _push_vapid_jwt(string $endpoint, string $priv_pem, string $subject): string {
    $aud     = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
    $header  = _push_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
    $payload = _push_b64u(json_encode([
        'aud' => $aud,
        'exp' => time() + 43200,
        'sub' => $subject,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $input = $header . '.' . $payload;
    $priv  = openssl_pkey_get_private($priv_pem);
    openssl_sign($input, $der_sig, $priv, OPENSSL_ALGO_SHA256);
    return $input . '.' . _push_b64u(_push_der_ecdsa_to_raw($der_sig));
}

// ── Szyfrowanie (RFC 8291, content-encoding: aes128gcm) ──────────────────────

function _push_encrypt(
    string $plaintext,
    string $auth_b64u,
    string $client_pub_b64u,
    \OpenSSLAsymmetricKey $sender_key
): string {
    if (!function_exists('openssl_pkey_derive')) {
        throw new \RuntimeException('Web Push wymaga PHP 8.1+ (openssl_pkey_derive).');
    }

    $auth_secret    = _push_b64u_decode($auth_b64u);     // 16 bajtów
    $client_pub_raw = _push_b64u_decode($client_pub_b64u); // 65 bajtów (0x04‖x‖y)
    $sender_pub_raw = _push_ec_pub_to_raw65($sender_key);

    // ECDH shared secret
    $client_key    = _push_import_ec_pub($client_pub_raw);
    $shared_secret = (string)openssl_pkey_derive($client_key, $sender_key, 32);

    // Derywacja klucza (RFC 8291 §3.3)
    $key_info  = "WebPush: info\x00" . $client_pub_raw . $sender_pub_raw;
    $prk_key   = _push_hkdf_extract($auth_secret, $shared_secret);
    $ikm       = _push_hkdf_expand($prk_key, $key_info, 32);

    $salt  = random_bytes(16);
    $prk   = _push_hkdf_extract($salt, $ikm);
    $cek   = _push_hkdf_expand($prk, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = _push_hkdf_expand($prk, "Content-Encoding: nonce\x00", 12);

    // Padding + delimiter (RFC 8291 §4)
    $record_size = 4096;
    $max_plain   = $record_size - 16 - 1; // − tag (16) − delimiter (1)
    if (strlen($plaintext) > $max_plain) {
        $plaintext = substr($plaintext, 0, $max_plain);
    }
    $padded = $plaintext . "\x02" . str_repeat("\x00", $max_plain - strlen($plaintext));

    // AES-128-GCM
    $tag        = '';
    $ciphertext = (string)openssl_encrypt($padded, 'aes-128-gcm', $cek,
                      OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

    // Nagłówek RFC 8291: salt(16) + rs(4 BE) + idlen(1) + keyid(65) + ciphertext + tag(16)
    return $salt . pack('N', $record_size) . chr(65) . $sender_pub_raw . $ciphertext . $tag;
}

// ── VAPID keys ────────────────────────────────────────────────────────────────

function push_vapid_keys(): array {
    require_once __DIR__ . '/m365.php';
    $pub  = m365_setting('push_vapid_public');
    $priv = m365_setting('push_vapid_private');
    if ($pub !== '' && $priv !== '') {
        return ['public' => $pub, 'private' => $priv];
    }
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$key) return ['public' => '', 'private' => ''];
    $d = openssl_pkey_get_details($key);
    $x = str_pad((string)($d['ec']['x'] ?? ''), 32, "\x00", STR_PAD_LEFT);
    $y = str_pad((string)($d['ec']['y'] ?? ''), 32, "\x00", STR_PAD_LEFT);
    $pub_b64u = _push_b64u("\x04" . $x . $y);
    openssl_pkey_export($key, $priv_pem);
    m365_save_setting('push_vapid_public',  $pub_b64u);
    m365_save_setting('push_vapid_private', $priv_pem);
    return ['public' => $pub_b64u, 'private' => $priv_pem];
}

// ── Wysyłanie powiadomienia ───────────────────────────────────────────────────

/**
 * Wyślij powiadomienie push do jednego kursanta.
 *
 * @param  string $subscription_json  JSON z push_subscribe.php (endpoint + keys)
 * @param  string $title              Tytuł powiadomienia
 * @param  string $body               Treść
 * @param  string $url                Docelowy URL (opcjonalnie)
 * @return bool   true = sukces (HTTP 2xx/201), false = błąd
 */
function push_send(string $subscription_json, string $title, string $body, string $url = ''): bool {
    try {
        $sub = json_decode($subscription_json, true, 8, JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        error_log('[push_send] Invalid subscription JSON: ' . $e->getMessage());
        return false;
    }

    $endpoint    = (string)($sub['endpoint'] ?? '');
    $auth_b64u   = (string)($sub['keys']['auth'] ?? '');
    $p256dh_b64u = (string)($sub['keys']['p256dh'] ?? '');

    if (!$endpoint || !$auth_b64u || !$p256dh_b64u) {
        error_log('[push_send] Incomplete subscription (missing endpoint or keys).');
        return false;
    }

    $keys = push_vapid_keys();
    if (!$keys['public'] || !$keys['private']) {
        error_log('[push_send] VAPID keys not available.');
        return false;
    }

    // Efemeryczny klucz nadawcy
    $sender_key = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if (!$sender_key) {
        error_log('[push_send] Cannot generate ephemeral sender key.');
        return false;
    }

    // Szyfrowanie payload
    $payload_json = json_encode([
        'title' => $title,
        'body'  => $body,
        'url'   => $url ?: APP_URL . '/karty30/ti/kursant/index.php',
        'icon'  => APP_URL . '/assets/icon-192.png',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        $encrypted = _push_encrypt($payload_json, $auth_b64u, $p256dh_b64u, $sender_key);
    } catch (\Throwable $e) {
        error_log('[push_send] Encryption error: ' . $e->getMessage());
        return false;
    }

    // VAPID JWT
    $subject = 'mailto:' . (defined('MAIL_FROM_ADDR') ? MAIL_FROM_ADDR : 'noreply@feer.org.pl');
    $jwt     = _push_vapid_jwt($endpoint, $keys['private'], $subject);

    // HTTP POST do push service (cURL)
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $encrypted,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Authorization: vapid t=' . $jwt . ',k=' . $keys['public'],
            'TTL: 86400',
            'Urgency: normal',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("[push_send] cURL error for endpoint {$endpoint}: {$err}");
        return false;
    }

    // 201 Created = sukces (FCM/WebPush standard)
    // 410 Gone / 404 Not Found = subskrypcja wygasła — usuń z bazy
    if ($code === 410 || $code === 404) {
        error_log("[push_send] Subscription expired (HTTP {$code}), endpoint: {$endpoint}");
    } elseif ($code < 200 || $code >= 300) {
        error_log("[push_send] HTTP {$code} from push service, endpoint: {$endpoint}");
        return false;
    }

    return $code >= 200 && $code < 300;
}

/**
 * Wyślij powiadomienie push do wszystkich aktywnych subskrypcji kursanta.
 * Bezpieczna — catches all, loguje błędy, nie przerywa wywołującego.
 *
 * @param  int    $student_account_id  ID z k30_ti_student_accounts
 */
function push_notify_student(int $student_account_id, string $title, string $body, string $url = ''): void {
    try {
        require_once __DIR__ . '/db.php';
        $row = db_one(
            "SELECT push_subscription FROM k30_ti_student_accounts WHERE id=? AND is_active=1",
            [$student_account_id]
        );
        if (!$row || empty($row['push_subscription'])) return;
        push_send((string)$row['push_subscription'], $title, $body, $url);
    } catch (\Throwable $e) {
        error_log('[push_notify_student] id=' . $student_account_id . ' ' . $e->getMessage());
    }
}

/**
 * Wyślij powiadomienie push do kursanta po client_id (k30_clients.id).
 */
function push_notify_client(int $client_id, string $title, string $body, string $url = ''): void {
    try {
        require_once __DIR__ . '/db.php';
        $rows = db_all(
            "SELECT id FROM k30_ti_student_accounts WHERE client_id=? AND is_active=1 AND push_subscription IS NOT NULL",
            [$client_id]
        );
        foreach ($rows as $row) {
            push_notify_student((int)$row['id'], $title, $body, $url);
        }
    } catch (\Throwable $e) {
        error_log('[push_notify_client] client=' . $client_id . ' ' . $e->getMessage());
    }
}
