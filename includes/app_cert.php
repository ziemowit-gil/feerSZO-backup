<?php
/**
 * includes/app_cert.php — Certyfikat instalacyjny aplikacji (X.509, self-signed).
 *
 * Wspólna logika generowania i odczytu certu „app.*" (crt/key/sig/salt),
 * używana przez:
 *   - cli/generatorCertyfikatu.php  (domyślnie 30 dni, powiązany z KRS)
 *   - cli/refresh_cert.php          (odświeżenie, np. na 3 lata)
 * Pliki czyta także endpoint cert-salt.php (app.crt/app.sig/app.salt).
 *
 * Komplet plików (katalog certs/):
 *   app.crt  — certyfikat PEM
 *   app.key  — klucz prywatny PEM (chmod 600)
 *   app.sig  — HMAC-SHA256(cert_pem, APP_KEY)  (powiązanie z instalacją)
 *   app.salt — losowy salt 64-hex (udostępniany przez /cert-salt.php)
 */

/** Katalog certyfikatów (tworzy go + .htaccess, jeśli brak). */
function app_cert_dir(): string {
    $dir = dirname(__DIR__) . '/certs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\n");
    return $dir;
}

/** Ścieżki do plików certu instalacyjnego. */
function app_cert_paths(): array {
    $d = app_cert_dir();
    return [
        'crt'  => $d . '/app.crt',
        'key'  => $d . '/app.key',
        'sig'  => $d . '/app.sig',
        'salt' => $d . '/app.salt',
    ];
}

/** Stan certyfikatu lub null, gdy brak/niepoprawny. */
function app_cert_status(): ?array {
    $p = app_cert_paths();
    if (!is_file($p['crt'])) return null;
    $pem = file_get_contents($p['crt']);
    $parsed = $pem ? openssl_x509_parse($pem) : false;
    if (!$parsed) return null;
    $valid_to = (int)($parsed['validTo_time_t'] ?? 0);
    $hmac_ok = false;
    if (is_file($p['sig']) && defined('APP_KEY') && APP_KEY !== '') {
        $hmac_ok = hash_equals(hash_hmac('sha256', $pem, APP_KEY), trim((string)file_get_contents($p['sig'])));
    }
    return [
        'cn'         => $parsed['subject']['CN'] ?? '',
        'krs'        => preg_replace('/^KRS:/', '', $parsed['subject']['serialNumber'] ?? ''),
        'valid_from' => (int)($parsed['validFrom_time_t'] ?? 0),
        'valid_to'   => $valid_to,
        'days_left'  => (int)ceil(($valid_to - time()) / 86400),
        'hmac_ok'    => $hmac_ok,
        'has_salt'   => is_file($p['salt']),
    ];
}

/**
 * Ustala KRS i nazwę organizacji: z argumentów, a w razie braku z bazy
 * (settings org_krs / org_name) lub stałej ORG_NAME.
 */
function app_cert_identity(string $krs = '', string $name = ''): array {
    $krs  = preg_replace('/\D/', '', $krs);
    $name = trim($name);
    if ($krs === '' || $name === '') {
        try {
            require_once dirname(__DIR__) . '/includes/db.php';
            if ($krs === '') {
                $r = db_one("SELECT value FROM settings WHERE key_='org_krs'");
                $krs = preg_replace('/\D/', '', $r['value'] ?? '');
            }
            if ($name === '') {
                $r = db_one("SELECT value FROM settings WHERE key_='org_name'");
                $name = trim($r['value'] ?? '');
            }
        } catch (\Throwable $e) { /* baza może być niedostępna — użyj fallbacku */ }
        if ($name === '' && defined('ORG_NAME')) $name = ORG_NAME;
    }
    return ['krs' => $krs, 'name' => $name];
}

/**
 * Generuje (nadpisując) certyfikat instalacyjny app.* o zadanej ważności w dniach.
 * Spójnie zapisuje crt/key(600)/sig/salt(640) — tak jak oczekuje cert-salt.php.
 *
 * @return array ['ok'=>bool,'msg'=>string, oraz przy ok: 'cert_pem','sig','valid_to','days','krs','name']
 */
function app_cert_generate(string $krs, string $name, int $days = 30): array {
    if (!extension_loaded('openssl'))             return ['ok' => false, 'msg' => 'Rozszerzenie openssl jest wymagane.'];
    if (!defined('APP_KEY') || APP_KEY === '')    return ['ok' => false, 'msg' => 'Brak APP_KEY w konfiguracji — nie można podpisać HMAC.'];
    $krs  = preg_replace('/\D/', '', $krs);
    $name = trim($name);
    if ($krs === '')  return ['ok' => false, 'msg' => 'Numer KRS jest wymagany.'];
    if ($name === '') return ['ok' => false, 'msg' => 'Nazwa organizacji jest wymagana.'];
    if ($days < 1)    $days = 1;

    $pkey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'encrypt_key'      => false,
    ]);
    if (!$pkey) return ['ok' => false, 'msg' => 'Generowanie klucza nie powiodło się: ' . openssl_error_string()];

    $dn = [
        'C'            => 'PL',
        'ST'           => 'Polska',
        'O'            => $name,
        'OU'           => 'Rejestr Umow',
        'CN'           => $name,
        'serialNumber' => 'KRS:' . $krs,
    ];
    $csr = openssl_csr_new($dn, $pkey, ['digest_alg' => 'sha256']);
    if (!$csr) return ['ok' => false, 'msg' => 'Tworzenie CSR nie powiodło się: ' . openssl_error_string()];

    $cert = openssl_csr_sign($csr, null, $pkey, $days, ['digest_alg' => 'sha256'], (int)(microtime(true) * 1000) & 0x7FFFFFFF);
    if (!$cert) return ['ok' => false, 'msg' => 'Podpisywanie certyfikatu nie powiodło się: ' . openssl_error_string()];

    $cert_pem = ''; $key_pem = '';
    openssl_x509_export($cert, $cert_pem);
    openssl_pkey_export($pkey, $key_pem);
    if ($cert_pem === '' || $key_pem === '') return ['ok' => false, 'msg' => 'Eksport PEM nie powiódł się.'];

    $p = app_cert_paths();
    if (file_put_contents($p['crt'], $cert_pem) === false) return ['ok' => false, 'msg' => 'Zapis app.crt nie powiódł się.'];
    if (file_put_contents($p['key'], $key_pem)  === false) return ['ok' => false, 'msg' => 'Zapis app.key nie powiódł się.'];
    @chmod($p['key'], 0600);

    $sig = hash_hmac('sha256', $cert_pem, APP_KEY);
    if (file_put_contents($p['sig'], $sig) === false) return ['ok' => false, 'msg' => 'Zapis app.sig nie powiódł się.'];

    $salt = bin2hex(random_bytes(32));
    if (file_put_contents($p['salt'], $salt) === false) return ['ok' => false, 'msg' => 'Zapis app.salt nie powiódł się.'];
    @chmod($p['salt'], 0640);

    $parsed = openssl_x509_parse($cert_pem);
    return [
        'ok'       => true,
        'msg'      => 'Certyfikat wygenerowany.',
        'cert_pem' => $cert_pem,
        'sig'      => $sig,
        'valid_to' => (int)($parsed['validTo_time_t'] ?? 0),
        'days'     => $days,
        'krs'      => $krs,
        'name'     => $name,
    ];
}
