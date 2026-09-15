<?php
/**
 * x509_login.php — X.509 certificate authentication for admin users.
 *
 * Generates password-protected PKCS#12 certs, stores fingerprints in
 * admin_x509_certs, and verifies uploaded .p12 files at login.
 */

function x509_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS admin_x509_certs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL,
            fingerprint TEXT NOT NULL UNIQUE,
            subject_cn  TEXT NOT NULL,
            valid_from  DATETIME NOT NULL,
            valid_to    DATETIME NOT NULL,
            revoked_at  DATETIME DEFAULT NULL,
            issued_by   INTEGER DEFAULT NULL,
            issued_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Throwable $e) {
        error_log('[x509_login] migrate: ' . $e->getMessage());
    }
}

/**
 * Generates a self-signed X.509 cert + PKCS#12 for the given admin user.
 * Returns array with p12_data, key_pem, cert_pem, fingerprint, valid_to.
 * Throws RuntimeException on failure.
 */
function x509_generate_for_user(
    int    $user_id,
    string $cert_password,
    string $cn,
    string $org      = '',
    string $country  = 'PL',
    int    $bits     = 2048,
    int    $days     = 730
): array {
    x509_init();

    $pkey = openssl_pkey_new([
        'private_key_bits' => $bits,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if (!$pkey) {
        throw new RuntimeException('Nie można wygenerować klucza: ' . openssl_error_string());
    }

    $dn = ['CN' => $cn, 'C' => $country];
    if ($org !== '') $dn['O'] = $org;

    $csr = openssl_csr_new($dn, $pkey, ['digest_alg' => 'sha256']);
    if (!$csr) throw new RuntimeException('Błąd CSR: ' . openssl_error_string());

    $x509 = openssl_csr_sign($csr, null, $pkey, $days, ['digest_alg' => 'sha256']);
    if (!$x509) throw new RuntimeException('Błąd podpisywania: ' . openssl_error_string());

    openssl_x509_export($x509, $cert_pem);

    $fp = openssl_x509_fingerprint($x509, 'sha256');
    if (!$fp) throw new RuntimeException('Błąd fingerprint.');

    $info       = openssl_x509_parse($x509);
    $valid_from = date('Y-m-d H:i:s', (int)$info['validFrom_time_t']);
    $valid_to   = date('Y-m-d H:i:s', (int)$info['validTo_time_t']);

    $issuer_id = (int)(current_user()['id'] ?? 0);

    db()->prepare(
        "INSERT INTO admin_x509_certs
             (user_id, fingerprint, subject_cn, valid_from, valid_to, issued_by)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$user_id, $fp, $cn, $valid_from, $valid_to, $issuer_id ?: null]);

    $p12_data = '';
    openssl_pkcs12_export($x509, $p12_data, $pkey,
        $cert_password !== '' ? $cert_password : 'changeme',
        ['friendly_name' => $cn]
    );

    openssl_pkey_export($pkey, $key_pem);

    return [
        'fingerprint' => $fp,
        'valid_from'  => $valid_from,
        'valid_to'    => $valid_to,
        'cert_pem'    => $cert_pem,
        'key_pem'     => $key_pem,
        'p12_data'    => $p12_data,
        'p12_pass'    => $cert_password !== '' ? $cert_password : 'changeme',
    ];
}

/**
 * Verifies a PKCS#12 blob + password and returns the matching user row or null.
 * Only active admin/editor users with non-revoked, non-expired certs pass.
 */
function x509_verify_login(string $p12_data, string $cert_password): ?array {
    x509_init();

    $certs = [];
    if (!@openssl_pkcs12_read($p12_data, $certs, $cert_password)) {
        return null;
    }

    $cert_pem = $certs['cert'] ?? '';
    $cert = @openssl_x509_read($cert_pem);
    if (!$cert) return null;

    $fp = openssl_x509_fingerprint($cert, 'sha256');
    if (!$fp) return null;

    $row = db_one(
        "SELECT u.*, c.issuer_type
         FROM admin_x509_certs c
         JOIN users u ON u.id = c.user_id
         WHERE c.fingerprint  = ?
           AND c.revoked_at  IS NULL
           AND c.valid_to     > datetime('now')
           AND u.is_active    = 1",
        [$fp]
    );
    if (!$row) return null;

    if (!in_array($row['role'] ?? '', ['admin', 'editor', 'superadmin'], true)) return null;

    if (($row['issuer_type'] ?? 'self') === 'ejbca') {
        // Integracja z EJBCA została wycofana — certyfikaty historycznie wystawione
        // przez CA nie mają już kogo/czego weryfikować w łańcuchu zaufania.
        error_log('[x509_login] odrzucono logowanie certyfikatem EJBCA (integracja wycofana) dla fp=' . $fp);
        return null;
    }

    return $row;
}

/**
 * Revokes a cert (soft delete by timestamp).
 */
function x509_revoke(int $cert_id): void {
    x509_init();
    db()->prepare("UPDATE admin_x509_certs SET revoked_at = datetime('now') WHERE id = ?")
        ->execute([$cert_id]);
}

/**
 * Returns all certs joined with user info, newest first.
 */
function x509_list_all(): array {
    x509_init();
    return db_all(
        "SELECT c.*, u.name AS user_name, u.email AS user_email, u.role AS user_role
         FROM admin_x509_certs c
         JOIN users u ON u.id = c.user_id
         ORDER BY c.issued_at DESC"
    );
}

/**
 * Returns certs for one user, newest first.
 */
function x509_list_for_user(int $user_id): array {
    x509_init();
    return db_all(
        "SELECT * FROM admin_x509_certs WHERE user_id = ? ORDER BY issued_at DESC",
        [$user_id]
    );
}

/**
 * True if at least one active (non-revoked, non-expired) cert exists —
 * used to decide whether to show the X.509 tab on the login page.
 */
function x509_any_active(): bool {
    x509_init();
    $r = db_one(
        "SELECT 1 FROM admin_x509_certs
         WHERE revoked_at IS NULL AND valid_to > datetime('now')
         LIMIT 1"
    );
    return (bool)$r;
}

// ── Logowanie challenge-response (aplikacja kliencka SzoCert) ───────────────
//
// Zamiast przesyłać cały plik .p12 + hasło przy każdym logowaniu, aplikacja
// kliencka (bin/szocert-app) trzyma klucz prywatny LOKALNIE na komputerze
// użytkownika (nigdy nie trafia na serwer) i tylko PODPISUJE jednorazowe
// wyzwanie (nonce). Serwer weryfikuje podpis kluczem publicznym z certyfikatu
// (cert_pem — dane publiczne, bezpieczne do przesłania) i sprawdza, że
// fingerprint pasuje do aktywnego, niewycofanego wpisu w admin_x509_certs
// (ten sam wpis, który dziś tworzy admin/x509_login.php).

function x509_challenge_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS x509_login_challenges (
            id         CHAR(32) PRIMARY KEY,
            nonce_b64  TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            used_at    DATETIME DEFAULT NULL
        )");
    } catch (\Throwable $e) {
        error_log('[x509_login] challenge migrate: ' . $e->getMessage());
    }
}

/** Tworzy jednorazowe wyzwanie logowania. Ważne 2 minuty. */
function x509_challenge_create(): array {
    x509_challenge_migrate();
    $id    = bin2hex(random_bytes(16));
    $nonce = random_bytes(32);
    db()->prepare("INSERT INTO x509_login_challenges (id, nonce_b64) VALUES (?, ?)")
        ->execute([$id, base64_encode($nonce)]);
    return ['challenge_id' => $id, 'nonce_b64' => base64_encode($nonce)];
}

/** Zużywa wyzwanie (jednorazowe) i zwraca nonce, albo null jeśli nieznane/wygasłe/już użyte. */
function x509_challenge_consume(string $challenge_id): ?string {
    x509_challenge_migrate();
    $row = db_one(
        "SELECT nonce_b64 FROM x509_login_challenges
         WHERE id = ? AND used_at IS NULL AND created_at > ?",
        [$challenge_id, date('Y-m-d H:i:s', time() - 120)]
    );
    if (!$row) return null;
    db()->prepare("UPDATE x509_login_challenges SET used_at = datetime('now') WHERE id = ?")
        ->execute([$challenge_id]);
    return $row['nonce_b64'];
}

/**
 * Weryfikuje odpowiedź na wyzwanie: podpis (SHA256) nonce'a kluczem prywatnym
 * pasującym do przesłanego certyfikatu publicznego. Zwraca wiersz usera albo
 * null. Te same reguły co x509_verify_login (rola, revoked, expired, EJBCA).
 */
function x509_verify_challenge(string $challenge_id, string $cert_pem, string $signature_b64): ?array {
    x509_init();

    $nonce_b64 = x509_challenge_consume($challenge_id);
    if ($nonce_b64 === null) return null;

    $cert = @openssl_x509_read($cert_pem);
    if (!$cert) return null;

    $fp = openssl_x509_fingerprint($cert, 'sha256');
    if (!$fp) return null;

    $row = db_one(
        "SELECT u.*, c.issuer_type
         FROM admin_x509_certs c
         JOIN users u ON u.id = c.user_id
         WHERE c.fingerprint  = ?
           AND c.revoked_at  IS NULL
           AND c.valid_to     > datetime('now')
           AND u.is_active    = 1",
        [$fp]
    );
    if (!$row) return null;

    if (!in_array($row['role'] ?? '', ['admin', 'editor', 'superadmin'], true)) return null;

    if (($row['issuer_type'] ?? 'self') === 'ejbca') {
        error_log('[x509_login] odrzucono logowanie (challenge) certyfikatem EJBCA dla fp=' . $fp);
        return null;
    }

    $pubkey = openssl_pkey_get_public($cert);
    if (!$pubkey) return null;

    $signature = base64_decode($signature_b64, true);
    $nonce     = base64_decode($nonce_b64, true);
    if ($signature === false || $nonce === false) return null;

    $ok = openssl_verify($nonce, $signature, $pubkey, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) return null;

    return $row;
}
