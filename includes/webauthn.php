<?php
/**
 * WebAuthn / FIDO2 — klucze sprzętowe (YubiKey, certyfikaty x509-bound)
 * Czyste PHP 8.1, bez Composera. Wymaga ext-openssl.
 *
 * Obsługuje:
 *   - rejestrację kluczy FIDO2 (EC P-256 alg=-7 i RSA-SHA256 alg=-257)
 *   - uwierzytelnianie jako drugi czynnik po haśle (2FA)
 *   - format attestation "none" (najbardziej przenośny)
 */

if (defined('WEBAUTHN_LOADED')) return;
define('WEBAUTHN_LOADED', true);

// ── Helpers ───────────────────────────────────────────────────────────────────

function webauthn_rp_id(): string {
    return parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost';
}

function webauthn_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS webauthn_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            credential_id TEXT NOT NULL UNIQUE,
            public_key TEXT NOT NULL,
            sign_count INTEGER NOT NULL DEFAULT 0,
            alg INTEGER NOT NULL DEFAULT -7,
            name TEXT NOT NULL DEFAULT 'Klucz sprzętowy',
            created_at TEXT NOT NULL,
            last_used_at TEXT NULL
        )");
    } catch (\Throwable $e) {
        error_log('[webauthn] migrate: ' . $e->getMessage());
    }
}

function webauthn_b64u_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function webauthn_b64u_decode(string $data): string {
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

// ── CBOR decoder ──────────────────────────────────────────────────────────────

function webauthn_cbor_decode(string $data): mixed {
    $offset = 0;
    return _cbor_parse($data, $offset);
}

function _cbor_parse(string $data, int &$offset): mixed {
    if ($offset >= strlen($data)) {
        throw new \RuntimeException('CBOR: unexpected end of data');
    }
    $byte     = ord($data[$offset++]);
    $major    = ($byte >> 5) & 0x07;
    $info     = $byte & 0x1f;

    switch ($major) {
        case 0: // unsigned int
            return _cbor_additional($data, $offset, $info);

        case 1: // negative int
            return -1 - _cbor_additional($data, $offset, $info);

        case 2: // byte string
            $len = _cbor_additional($data, $offset, $info);
            $val = substr($data, $offset, $len);
            $offset += $len;
            return $val;

        case 3: // text string
            $len = _cbor_additional($data, $offset, $info);
            $val = substr($data, $offset, $len);
            $offset += $len;
            return $val;

        case 4: // array
            $len = _cbor_additional($data, $offset, $info);
            $arr = [];
            for ($i = 0; $i < $len; $i++) {
                $arr[] = _cbor_parse($data, $offset);
            }
            return $arr;

        case 5: // map
            $len = _cbor_additional($data, $offset, $info);
            $map = [];
            for ($i = 0; $i < $len; $i++) {
                $key        = _cbor_parse($data, $offset);
                $map[$key]  = _cbor_parse($data, $offset);
            }
            return $map;

        case 7: // simple / float
            switch ($info) {
                case 20: return false;
                case 21: return true;
                case 22: return null;
                default:
                    // skip over floats we don't need
                    if ($info === 25) { $offset += 2; return null; }
                    if ($info === 26) { $offset += 4; return null; }
                    if ($info === 27) { $offset += 8; return null; }
                    return null;
            }

        default:
            throw new \RuntimeException("CBOR: unsupported major type $major");
    }
}

function _cbor_additional(string $data, int &$offset, int $info): int {
    if ($info < 24) return $info;
    if ($info === 24) { return ord($data[$offset++]); }
    if ($info === 25) {
        $v = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;
        return $v;
    }
    if ($info === 26) {
        $v = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;
        return $v;
    }
    if ($info === 27) {
        // 64-bit — use GMP if available, else hope it fits in int
        $hi = unpack('N', substr($data, $offset, 4))[1];
        $lo = unpack('N', substr($data, $offset + 4, 4))[1];
        $offset += 8;
        return ($hi << 32) | $lo;
    }
    throw new \RuntimeException("CBOR: reserved additional info $info");
}

// ── COSE → PEM ────────────────────────────────────────────────────────────────

function webauthn_cose_to_pem(array $cose): string {
    $kty = $cose[1] ?? null;
    if ($kty === 2) {
        // EC P-256
        $x = $cose[-2] ?? null;
        $y = $cose[-3] ?? null;
        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new \RuntimeException('WebAuthn: invalid EC key coordinates');
        }
        return _ec_p256_to_pem($x, $y);
    }
    if ($kty === 3) {
        // RSA
        $n = $cose[-1] ?? null;
        $e = $cose[-2] ?? null;
        if (!is_string($n) || !is_string($e)) {
            throw new \RuntimeException('WebAuthn: invalid RSA key components');
        }
        return _rsa_to_pem($n, $e);
    }
    throw new \RuntimeException("WebAuthn: unsupported COSE kty=$kty");
}

function _ec_p256_to_pem(string $x, string $y): string {
    // OIDs
    $oid_ecPublicKey = _asn1_oid("\x2a\x86\x48\xce\x3d\x02\x01");
    $oid_prime256v1  = _asn1_oid("\x2a\x86\x48\xce\x3d\x03\x01\x07");
    $alg_id          = _asn1_seq($oid_ecPublicKey . $oid_prime256v1);
    $point           = "\x04" . $x . $y;   // uncompressed point
    $bit_string      = _asn1_tag(0x03, "\x00" . $point);
    $spki            = _asn1_seq($alg_id . $bit_string);
    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($spki), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

function _rsa_to_pem(string $n, string $e): string {
    // Prepend 0x00 if MSB >= 0x80 to keep positive sign
    if (ord($n[0]) >= 0x80) {
        $n = "\x00" . $n;
    }
    if (ord($e[0]) >= 0x80) {
        $e = "\x00" . $e;
    }
    $int_n       = _asn1_tag(0x02, $n);
    $int_e       = _asn1_tag(0x02, $e);
    $rsa_key     = _asn1_seq($int_n . $int_e);
    $bit_string  = _asn1_tag(0x03, "\x00" . $rsa_key);

    $oid_rsa     = _asn1_oid("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01");
    $null        = "\x05\x00";
    $alg_id      = _asn1_seq($oid_rsa . $null);
    $spki        = _asn1_seq($alg_id . $bit_string);
    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($spki), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

function _asn1_len(string $content): string {
    $len = strlen($content);
    if ($len < 0x80) {
        return chr($len);
    }
    $enc = '';
    $tmp = $len;
    while ($tmp > 0) {
        $enc = chr($tmp & 0xff) . $enc;
        $tmp >>= 8;
    }
    return chr(0x80 | strlen($enc)) . $enc;
}

function _asn1_tag(int $tag, string $content): string {
    return chr($tag) . _asn1_len($content) . $content;
}

function _asn1_seq(string $content): string {
    return _asn1_tag(0x30, $content);
}

function _asn1_oid(string $oid_bytes): string {
    return _asn1_tag(0x06, $oid_bytes);
}

// ── Auth data parser ──────────────────────────────────────────────────────────

function webauthn_parse_auth_data(string $auth_data): array {
    if (strlen($auth_data) < 37) {
        throw new \RuntimeException('WebAuthn: authData too short');
    }
    $rp_id_hash  = substr($auth_data, 0, 32);
    $flags       = ord($auth_data[32]);
    $sign_count  = unpack('N', substr($auth_data, 33, 4))[1];

    $up  = (bool)($flags & 0x01); // User Presence
    $uv  = (bool)($flags & 0x04); // User Verification
    $at  = (bool)($flags & 0x40); // Attested Credential Data

    $result = [
        'rp_id_hash'    => $rp_id_hash,
        'flags'         => $flags,
        'up'            => $up,
        'uv'            => $uv,
        'at'            => $at,
        'sign_count'    => $sign_count,
        'aaguid'        => null,
        'credential_id' => null,
        'cose_key'      => null,
    ];

    if ($at && strlen($auth_data) > 37) {
        $pos    = 37;
        $aaguid = substr($auth_data, $pos, 16);
        $pos   += 16;

        $cred_id_len = unpack('n', substr($auth_data, $pos, 2))[1];
        $pos        += 2;

        $credential_id = substr($auth_data, $pos, $cred_id_len);
        $pos          += $cred_id_len;

        $cose_raw  = substr($auth_data, $pos);
        $cose_key  = webauthn_cbor_decode($cose_raw);

        $result['aaguid']        = $aaguid;
        $result['credential_id'] = $credential_id;
        $result['cose_key']      = $cose_key;
    }

    return $result;
}

// ── Registration ──────────────────────────────────────────────────────────────

function webauthn_begin_register(int $user_id, string $user_name, string $display_name): array {
    $challenge = random_bytes(32);
    $_SESSION['webauthn_reg_challenge'] = webauthn_b64u_encode($challenge);
    $_SESSION['webauthn_reg_uid']       = $user_id;

    $rp_id = webauthn_rp_id();

    // Exclude already registered credentials
    $existing = db_all(
        "SELECT credential_id FROM webauthn_credentials WHERE user_id=?",
        [$user_id]
    );
    $exclude = array_map(fn($r) => [
        'id'   => $r['credential_id'],
        'type' => 'public-key',
    ], $existing);

    return [
        'challenge' => webauthn_b64u_encode($challenge),
        'rp'        => [
            'name' => defined('ORG_NAME') ? ORG_NAME : 'System',
            'id'   => $rp_id,
        ],
        'user' => [
            'id'          => webauthn_b64u_encode((string)$user_id),
            'name'        => $user_name,
            'displayName' => $display_name,
        ],
        'pubKeyCredParams' => [
            ['type' => 'public-key', 'alg' => -7],   // ES256
            ['type' => 'public-key', 'alg' => -257],  // RS256
        ],
        'timeout'                => 60000,
        'attestation'            => 'none',
        'excludeCredentials'     => $exclude,
        'authenticatorSelection' => [
            'userVerification' => 'preferred',
        ],
    ];
}

function webauthn_complete_register(array $response, string $key_name = 'Klucz sprzętowy'): int {
    $challenge_b64u = $_SESSION['webauthn_reg_challenge'] ?? null;
    $user_id        = $_SESSION['webauthn_reg_uid']       ?? null;
    if (!$challenge_b64u || !$user_id) {
        throw new \RuntimeException('WebAuthn: brak sesji rejestracji');
    }

    // --- clientDataJSON ---
    $client_data_raw  = webauthn_b64u_decode($response['clientDataJSON'] ?? '');
    $client_data      = json_decode($client_data_raw, true);
    if (!$client_data) {
        throw new \RuntimeException('WebAuthn: nieprawidłowy clientDataJSON');
    }
    if (($client_data['type'] ?? '') !== 'webauthn.create') {
        throw new \RuntimeException('WebAuthn: nieprawidłowy typ clientData');
    }
    if (!hash_equals($challenge_b64u, rtrim($client_data['challenge'] ?? '', '='))) {
        // also try without padding strip
        $recv = $client_data['challenge'] ?? '';
        if (!hash_equals($challenge_b64u, $recv)) {
            throw new \RuntimeException('WebAuthn: niezgodność challenge');
        }
    }
    $expected_origin = rtrim(APP_URL, '/');
    if (($client_data['origin'] ?? '') !== $expected_origin) {
        throw new \RuntimeException('WebAuthn: nieprawidłowy origin: ' . ($client_data['origin'] ?? ''));
    }

    // --- attestationObject (CBOR) ---
    $attestation_raw = webauthn_b64u_decode($response['attestationObject'] ?? '');
    $attestation     = webauthn_cbor_decode($attestation_raw);
    $auth_data_raw   = $attestation['authData'] ?? null;
    if (!is_string($auth_data_raw)) {
        throw new \RuntimeException('WebAuthn: brak authData w attestationObject');
    }

    // --- parse authData ---
    $auth = webauthn_parse_auth_data($auth_data_raw);

    // Verify rpIdHash
    $expected_rp_hash = hash('sha256', webauthn_rp_id(), true);
    if (!hash_equals($expected_rp_hash, $auth['rp_id_hash'])) {
        throw new \RuntimeException('WebAuthn: niezgodność rpId');
    }
    if (!$auth['up']) {
        throw new \RuntimeException('WebAuthn: brak flagi User Presence');
    }
    if (!$auth['at']) {
        throw new \RuntimeException('WebAuthn: brak danych uwierzytelniającego (AT flag)');
    }
    if ($auth['credential_id'] === null || $auth['cose_key'] === null) {
        throw new \RuntimeException('WebAuthn: brak credential_id lub klucza COSE');
    }

    $credential_id_b64u = webauthn_b64u_encode($auth['credential_id']);
    $cose_key           = $auth['cose_key'];
    $alg                = $cose_key[3] ?? -7;

    // Validate PEM generation
    $pem = webauthn_cose_to_pem($cose_key);

    db_insert('webauthn_credentials', [
        'user_id'       => (int)$user_id,
        'credential_id' => $credential_id_b64u,
        'public_key'    => $pem,
        'sign_count'    => $auth['sign_count'],
        'alg'           => (int)$alg,
        'name'          => $key_name,
        'created_at'    => date('Y-m-d H:i:s'),
        'last_used_at'  => null,
    ]);

    unset($_SESSION['webauthn_reg_challenge'], $_SESSION['webauthn_reg_uid']);
    return (int)$user_id;
}

// ── Authentication ────────────────────────────────────────────────────────────

function webauthn_begin_auth(?int $user_id = null): array {
    $challenge = random_bytes(32);
    $_SESSION['webauthn_auth_challenge'] = webauthn_b64u_encode($challenge);

    $allow = [];
    if ($user_id !== null) {
        $rows = db_all(
            "SELECT credential_id FROM webauthn_credentials WHERE user_id=?",
            [$user_id]
        );
        foreach ($rows as $r) {
            $allow[] = [
                'id'         => $r['credential_id'],
                'type'       => 'public-key',
                'transports' => ['usb', 'nfc', 'ble', 'internal'],
            ];
        }
    }

    return [
        'challenge'        => webauthn_b64u_encode($challenge),
        'rpId'             => webauthn_rp_id(),
        'allowCredentials' => $allow,
        'userVerification' => 'preferred',
        'timeout'          => 60000,
    ];
}

function webauthn_complete_auth(array $response): int {
    $challenge_b64u = $_SESSION['webauthn_auth_challenge'] ?? null;
    if (!$challenge_b64u) {
        throw new \RuntimeException('WebAuthn: brak sesji uwierzytelniania');
    }

    $credential_id_b64u = $response['id'] ?? ($response['rawId'] ?? null);
    if (!$credential_id_b64u) {
        throw new \RuntimeException('WebAuthn: brak credential id');
    }

    // Load credential
    $cred = db_one(
        "SELECT wc.*, u.id AS uid FROM webauthn_credentials wc
         JOIN users u ON u.id = wc.user_id
         WHERE wc.credential_id = ?",
        [$credential_id_b64u]
    );
    if (!$cred) {
        throw new \RuntimeException('WebAuthn: nieznany klucz');
    }

    // --- clientDataJSON ---
    $client_data_raw = webauthn_b64u_decode($response['clientDataJSON'] ?? '');
    $client_data     = json_decode($client_data_raw, true);
    if (!$client_data) {
        throw new \RuntimeException('WebAuthn: nieprawidłowy clientDataJSON');
    }
    if (($client_data['type'] ?? '') !== 'webauthn.get') {
        throw new \RuntimeException('WebAuthn: nieprawidłowy typ clientData');
    }
    $recv_challenge = $client_data['challenge'] ?? '';
    if (!hash_equals($challenge_b64u, $recv_challenge) &&
        !hash_equals($challenge_b64u, rtrim($recv_challenge, '='))) {
        throw new \RuntimeException('WebAuthn: niezgodność challenge');
    }
    $expected_origin = rtrim(APP_URL, '/');
    if (($client_data['origin'] ?? '') !== $expected_origin) {
        throw new \RuntimeException('WebAuthn: nieprawidłowy origin');
    }

    // --- authData ---
    $auth_data_raw = webauthn_b64u_decode($response['authenticatorData'] ?? '');
    $auth          = webauthn_parse_auth_data($auth_data_raw);

    $expected_rp_hash = hash('sha256', webauthn_rp_id(), true);
    if (!hash_equals($expected_rp_hash, $auth['rp_id_hash'])) {
        throw new \RuntimeException('WebAuthn: niezgodność rpId');
    }
    if (!$auth['up']) {
        throw new \RuntimeException('WebAuthn: brak flagi User Presence');
    }

    // --- Verify signature ---
    $sig_raw          = webauthn_b64u_decode($response['signature'] ?? '');
    $client_data_hash = hash('sha256', $client_data_raw, true);
    $signed_data      = $auth_data_raw . $client_data_hash;

    $pem = $cred['public_key'];
    $alg_const = ($cred['alg'] == -257) ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA256;
    $ok = openssl_verify($signed_data, $sig_raw, $pem, $alg_const);
    if ($ok !== 1) {
        throw new \RuntimeException('WebAuthn: nieprawidłowy podpis');
    }

    // --- Sign count check ---
    $stored_count = (int)$cred['sign_count'];
    $new_count    = (int)$auth['sign_count'];
    if ($stored_count > 0 && $new_count > 0 && $new_count <= $stored_count) {
        throw new \RuntimeException('WebAuthn: podejrzane licznik podpisów (możliwy klon klucza)');
    }

    // --- Update ---
    db()->prepare(
        "UPDATE webauthn_credentials SET sign_count = ?, last_used_at = ? WHERE id = ?"
    )->execute([$new_count, date('Y-m-d H:i:s'), (int)$cred['id']]);

    unset($_SESSION['webauthn_auth_challenge']);
    return (int)$cred['user_id'];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Bramka logowania: jeśli user ma rolę admin/editor i ma zarejestrowany klucz
 * sprzętowy, przekierowuje do weryfikacji WebAuthn zamiast pozwolić
 * `login_user()` ustanowić sesję bezpośrednio — niezależnie od metody, którą
 * przeszedł uwierzytelnienie (hasło, Microsoft 365, SMS, X.509, kod dostępu).
 * Zwraca true, gdy wykonano redirect (wywołujący powinien wtedy `exit`).
 */
function webauthn_login_gate(array $user, string $redirect_after): bool {
    if (!in_array($user['role'] ?? '', ['admin', 'editor'], true)) return false;
    webauthn_migrate();
    if (!webauthn_user_has_keys((int)$user['id'])) return false;
    $_SESSION['webauthn_pending_uid'] = (int)$user['id'];
    header('Location: ' . APP_URL . '/auth/webauthn.php?redirect=' . urlencode($redirect_after));
    return true;
}

function webauthn_user_has_keys(int $user_id): bool {
    $r = db_one(
        "SELECT COUNT(*) AS c FROM webauthn_credentials WHERE user_id=?",
        [$user_id]
    );
    return (int)($r['c'] ?? 0) > 0;
}

function webauthn_get_credentials(int $user_id): array {
    return db_all(
        "SELECT id, credential_id, name, alg, sign_count, created_at, last_used_at
         FROM webauthn_credentials WHERE user_id=? ORDER BY created_at DESC",
        [$user_id]
    );
}

function webauthn_delete_credential(int $id, int $user_id): bool {
    $stmt = db()->prepare(
        "DELETE FROM webauthn_credentials WHERE id=? AND user_id=?"
    );
    $stmt->execute([$id, $user_id]);
    return $stmt->rowCount() > 0;
}
