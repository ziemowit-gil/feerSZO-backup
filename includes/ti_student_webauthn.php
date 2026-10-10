<?php
/**
 * includes/ti_student_webauthn.php — klucze sprzętowe (WebAuthn / passkey) dla kont kursantów TI.
 * Osobna tabela (k30_ti_student_webauthn), bo webauthn_credentials wiąże się z users.id.
 * Parsowanie CBOR / COSE / authData bierze z includes/webauthn.php.
 */
require_once __DIR__ . '/webauthn.php';

function ti_sw_migrate(): void {
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_student_webauthn (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        account_id    INTEGER NOT NULL,
        credential_id TEXT NOT NULL UNIQUE,
        public_key    TEXT NOT NULL,
        sign_count    INTEGER NOT NULL DEFAULT 0,
        alg           INTEGER NOT NULL DEFAULT -7,
        name          TEXT NOT NULL DEFAULT 'Klucz',
        created_at    TEXT NOT NULL,
        last_used_at  TEXT
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_k30_ti_sw_account ON k30_ti_student_webauthn(account_id)");
    $done = true;
}

function ti_sw_list(int $account_id): array {
    ti_sw_migrate();
    return db_all("SELECT id, name, created_at, last_used_at FROM k30_ti_student_webauthn WHERE account_id = ? ORDER BY id", [$account_id]);
}

function ti_sw_count(int $account_id): int {
    ti_sw_migrate();
    $r = db_one("SELECT COUNT(*) AS c FROM k30_ti_student_webauthn WHERE account_id = ?", [$account_id]);
    return (int)($r['c'] ?? 0);
}

function ti_sw_delete(int $account_id, int $key_id): void {
    ti_sw_migrate();
    db()->prepare("DELETE FROM k30_ti_student_webauthn WHERE id = ? AND account_id = ?")->execute([$key_id, $account_id]);
}

function ti_sw_begin_register(int $account_id, string $user_name): array {
    ti_sw_migrate();
    $challenge = random_bytes(32);
    $_SESSION['ti_sw_reg_challenge'] = webauthn_b64u_encode($challenge);
    $_SESSION['ti_sw_reg_account']   = $account_id;

    $exclude = array_map(fn($r) => ['id' => $r['credential_id'], 'type' => 'public-key'],
        db_all("SELECT credential_id FROM k30_ti_student_webauthn WHERE account_id = ?", [$account_id]));

    return [
        'challenge' => webauthn_b64u_encode($challenge),
        'rp'        => ['name' => defined('ORG_NAME') ? ORG_NAME : 'System', 'id' => webauthn_rp_id()],
        'user'      => ['id' => webauthn_b64u_encode((string)$account_id), 'name' => $user_name, 'displayName' => $user_name],
        'pubKeyCredParams'       => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
        'timeout'                => 60000,
        'attestation'            => 'none',
        'excludeCredentials'     => $exclude,
        'authenticatorSelection' => ['userVerification' => 'preferred'],
    ];
}

function ti_sw_complete_register(array $response, string $key_name): void {
    ti_sw_migrate();
    $challenge_b64u = $_SESSION['ti_sw_reg_challenge'] ?? null;
    $account_id     = (int)($_SESSION['ti_sw_reg_account'] ?? 0);
    if (!$challenge_b64u || !$account_id) throw new \RuntimeException('Brak sesji rejestracji klucza.');

    $client_data_raw = webauthn_b64u_decode($response['clientDataJSON'] ?? '');
    $client_data     = json_decode($client_data_raw, true);
    if (!$client_data || ($client_data['type'] ?? '') !== 'webauthn.create') throw new \RuntimeException('Nieprawidłowe dane klucza.');
    if (!hash_equals($challenge_b64u, rtrim((string)($client_data['challenge'] ?? ''), '='))) throw new \RuntimeException('Niezgodność challenge.');
    if (($client_data['origin'] ?? '') !== rtrim(APP_URL, '/')) throw new \RuntimeException('Nieprawidłowy adres strony.');

    $attestation = webauthn_cbor_decode(webauthn_b64u_decode($response['attestationObject'] ?? ''));
    $auth_raw    = $attestation['authData'] ?? null;
    if (!is_string($auth_raw)) throw new \RuntimeException('Brak authData.');
    $auth = webauthn_parse_auth_data($auth_raw);

    if (!hash_equals(hash('sha256', webauthn_rp_id(), true), $auth['rp_id_hash'])) throw new \RuntimeException('Niezgodność rpId.');
    if (!$auth['up'] || !$auth['at'] || $auth['credential_id'] === null || $auth['cose_key'] === null) {
        throw new \RuntimeException('Klucz nie zwrócił wymaganych danych.');
    }

    $pem = webauthn_cose_to_pem($auth['cose_key']);
    db_insert('k30_ti_student_webauthn', [
        'account_id'    => $account_id,
        'credential_id' => webauthn_b64u_encode($auth['credential_id']),
        'public_key'    => $pem,
        'sign_count'    => (int)$auth['sign_count'],
        'alg'           => (int)($auth['cose_key'][3] ?? -7),
        'name'          => mb_substr($key_name !== '' ? $key_name : 'Klucz', 0, 60),
        'created_at'    => date('Y-m-d H:i:s'),
        'last_used_at'  => null,
    ]);
    unset($_SESSION['ti_sw_reg_challenge'], $_SESSION['ti_sw_reg_account']);
}

/** Opcje logowania kluczem dla konkretnego konta (tylko jego klucze). */
function ti_sw_begin_auth(int $account_id): array {
    ti_sw_migrate();
    $challenge = random_bytes(32);
    $_SESSION['ti_sw_auth_challenge'] = webauthn_b64u_encode($challenge);
    $_SESSION['ti_sw_auth_account']   = $account_id;

    $allow = array_map(fn($r) => ['id' => $r['credential_id'], 'type' => 'public-key', 'transports' => ['usb', 'nfc', 'ble', 'internal']],
        db_all("SELECT credential_id FROM k30_ti_student_webauthn WHERE account_id = ?", [$account_id]));

    return [
        'challenge'        => webauthn_b64u_encode($challenge),
        'rpId'             => webauthn_rp_id(),
        'allowCredentials' => $allow,
        'userVerification' => 'preferred',
        'timeout'          => 60000,
    ];
}

/** Sprawdza podpis i zwraca id konta kursanta. */
function ti_sw_complete_auth(array $response): int {
    ti_sw_migrate();
    $challenge_b64u = $_SESSION['ti_sw_auth_challenge'] ?? null;
    $account_id     = (int)($_SESSION['ti_sw_auth_account'] ?? 0);
    if (!$challenge_b64u || !$account_id) throw new \RuntimeException('Brak sesji logowania kluczem.');

    $cred_id = (string)($response['id'] ?? ($response['rawId'] ?? ''));
    $cred = $cred_id !== '' ? db_one(
        "SELECT * FROM k30_ti_student_webauthn WHERE credential_id = ? AND account_id = ?", [$cred_id, $account_id]) : null;
    if (!$cred) throw new \RuntimeException('Nieznany klucz dla tego konta.');

    $client_data_raw = webauthn_b64u_decode($response['clientDataJSON'] ?? '');
    $client_data     = json_decode($client_data_raw, true);
    if (!$client_data || ($client_data['type'] ?? '') !== 'webauthn.get') throw new \RuntimeException('Nieprawidłowe dane klucza.');
    $recv = (string)($client_data['challenge'] ?? '');
    if (!hash_equals($challenge_b64u, $recv) && !hash_equals($challenge_b64u, rtrim($recv, '='))) throw new \RuntimeException('Niezgodność challenge.');
    if (($client_data['origin'] ?? '') !== rtrim(APP_URL, '/')) throw new \RuntimeException('Nieprawidłowy adres strony.');

    $auth_raw = webauthn_b64u_decode($response['authenticatorData'] ?? '');
    $auth     = webauthn_parse_auth_data($auth_raw);
    if (!hash_equals(hash('sha256', webauthn_rp_id(), true), $auth['rp_id_hash'])) throw new \RuntimeException('Niezgodność rpId.');
    if (!$auth['up']) throw new \RuntimeException('Brak potwierdzenia obecności.');

    $sig    = webauthn_b64u_decode($response['signature'] ?? '');
    $signed = $auth_raw . hash('sha256', $client_data_raw, true);
    if (openssl_verify($signed, $sig, $cred['public_key'], OPENSSL_ALGO_SHA256) !== 1) throw new \RuntimeException('Nieprawidłowy podpis klucza.');

    $stored = (int)$cred['sign_count'];
    $new    = (int)$auth['sign_count'];
    if ($stored > 0 && $new > 0 && $new <= $stored) throw new \RuntimeException('Podejrzany licznik klucza.');

    db()->prepare("UPDATE k30_ti_student_webauthn SET sign_count = ?, last_used_at = ? WHERE id = ?")
        ->execute([$new, date('Y-m-d H:i:s'), (int)$cred['id']]);
    unset($_SESSION['ti_sw_auth_challenge'], $_SESSION['ti_sw_auth_account']);
    return $account_id;
}
