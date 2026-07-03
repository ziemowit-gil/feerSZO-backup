<?php
/**
 * includes/ejbca.php — integracja z EJBCA (wewnętrzny CA) przez EjbcaWS (SOAP).
 *
 * Uwierzytelnienie do WS przez mutual TLS — certyfikat klienta aplikacji
 * (certs/ejbca_client.pem, bez hasła, chroniony .htaccess+chmod 600, patrz
 * app_cert_dir() w includes/app_cert.php) wystawiony jednorazowo skryptem
 * docker/scripts/ejbca_provision_app_cert.sh.
 *
 * Konfiguracja w settings (prefix ejbca_): adres WSDL, nazwa CA, profile.
 * Pola userDataVOWS/pkcs12Req zweryfikowane wprost ze schematu WSDL EJBCA
 * (nie z pamięci) — zob. docker/EJBCA.md.
 */

// ── Ustawienia ────────────────────────────────────────────────────────────────
function ejbca_setting(string $key, string $default = ''): string {
    static $cache = [];
    $fk = 'ejbca_' . $key;
    if (!array_key_exists($fk, $cache)) {
        try {
            $r = db_one("SELECT value FROM settings WHERE key_=?", [$fk]);
            $cache[$fk] = $r['value'] ?? $default;
        } catch (\Throwable $e) { $cache[$fk] = $default; }
    }
    return $cache[$fk] !== '' ? $cache[$fk] : $default;
}

function ejbca_save_setting(string $key, string $value): void {
    $fk = 'ejbca_' . $key;
    try {
        db()->prepare("INSERT INTO settings(key_,value) VALUES(?,?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
           ->execute([$fk, $value]);
    } catch (\Throwable $e) {
        $ex = db_one("SELECT key_ FROM settings WHERE key_=?", [$fk]);
        if ($ex) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $fk]);
        else     db()->prepare("INSERT INTO settings(key_,value) VALUES(?,?)")->execute([$fk, $value]);
    }
}

function ejbca_enabled(): bool {
    return ejbca_setting('enabled') === '1' && ejbca_configured();
}

function ejbca_configured(): bool {
    return is_file(ejbca_client_cert_path()) && is_file(ejbca_ca_cert_path());
}

function ejbca_client_cert_path(): string {
    require_once __DIR__ . '/app_cert.php';
    return app_cert_dir() . '/ejbca_client.pem';
}

function ejbca_ca_cert_path(): string {
    require_once __DIR__ . '/app_cert.php';
    return app_cert_dir() . '/ejbca_ca.pem';
}

// ── Klient SOAP ───────────────────────────────────────────────────────────────
function ejbca_soap_client(): SoapClient {
    if (!extension_loaded('soap')) {
        throw new RuntimeException('Rozszerzenie PHP soap nie jest zainstalowane.');
    }
    $wsdl        = ejbca_setting('ws_url', 'https://ca.feer.org.pl:8443/ejbca/ejbcaws/ejbcaws?wsdl');
    $client_cert = ejbca_client_cert_path();
    $ca_cert     = ejbca_ca_cert_path();
    if (!is_file($client_cert)) {
        throw new RuntimeException('Brak certyfikatu klienta EJBCA (' . $client_cert . ') — uruchom docker/scripts/ejbca_provision_app_cert.sh na serwerze.');
    }
    if (!is_file($ca_cert)) {
        throw new RuntimeException('Brak certyfikatu ManagementCA (' . $ca_cert . ') — uruchom docker/scripts/ejbca_provision_app_cert.sh na serwerze.');
    }

    $ctx = stream_context_create(['ssl' => [
        'local_cert'        => $client_cert,   // PEM: cert + klucz prywatny, bez hasła
        'cafile'            => $ca_cert,
        'verify_peer'       => true,
        'verify_peer_name'  => true,
    ]]);

    return new SoapClient($wsdl, [
        'stream_context' => $ctx,
        'cache_wsdl'     => WSDL_CACHE_DISK,
        'connection_timeout' => 20,
        'exceptions'     => true,
    ]);
}

/** Szybki test połączenia — pobiera wersję EJBCA. Zwraca ['ok'=>bool,'msg'=>...]. */
function ejbca_test_connection(): array {
    try {
        $client  = ejbca_soap_client();
        $version = $client->getEjbcaVersion();
        return ['ok' => true, 'msg' => 'Połączenie działa. ' . $version];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

/**
 * Wystawia certyfikat logowania przez EJBCA (End Entity + PKCS#12 w jednym wywołaniu).
 * Zwraca dokładnie taki sam kształt danych co x509_generate_for_user() w
 * includes/x509_login.php, żeby admin/x509_login.php mogło użyć wspólnego
 * flow pobierania (sesja → jednorazowy download → zapomnij).
 *
 * Ważność certyfikatu określa profil certyfikatu w EJBCA (ejbca_cert_profile) —
 * nie jest parametryzowana tutaj, żeby uniknąć zgadywania formatu daty w WS.
 *
 * @throws RuntimeException|SoapFault
 */
function ejbca_issue_login_cert(string $username, string $cn, string $password, string $org = '', string $country = 'PL'): array {
    if ($password === '') throw new RuntimeException('Hasło PKCS#12 jest wymagane.');

    $client = ejbca_soap_client();

    $dn = "CN={$cn}";
    if ($org !== '')     $dn .= ",O={$org}";
    if ($country !== '') $dn .= ",C={$country}";

    $u = new stdClass();
    $u->username               = $username;
    $u->password                = $password;
    $u->clearPwd                = true;
    $u->subjectDN               = $dn;
    $u->caName                  = ejbca_setting('ca_name', 'ManagementCA');
    $u->certificateProfileName  = ejbca_setting('cert_profile', 'ENDUSER');
    $u->endEntityProfileName    = ejbca_setting('ee_profile', 'EMPTY');
    $u->tokenType                = 'P12';
    $u->status                   = 10; // NEW
    $u->keyRecoverable           = false;
    $u->sendNotification         = false;

    $client->editUser($u);

    $ks = $client->pkcs12Req($username, $password, '', '2048', 'RSA');
    $p12_data = $ks->return->keystoreData ?? '';
    if ($p12_data === '') throw new RuntimeException('EJBCA nie zwróciło danych PKCS#12.');

    $certs = [];
    if (!openssl_pkcs12_read($p12_data, $certs, $password)) {
        throw new RuntimeException('Nie można odczytać wygenerowanego PKCS#12: ' . openssl_error_string());
    }
    $cert = openssl_x509_read($certs['cert']);
    $fp   = openssl_x509_fingerprint($cert, 'sha256');
    $parsed = openssl_x509_parse($cert);
    if (!$fp || !$parsed) throw new RuntimeException('Nie można sparsować wygenerowanego certyfikatu.');

    $serial_hex = strtoupper($parsed['serialNumberHex'] ?? dechex((int)($parsed['serialNumber'] ?? 0)));

    return [
        'fingerprint'     => $fp,
        'serial_hex'      => $serial_hex,
        'ejbca_username'  => $username,
        'valid_from'      => date('Y-m-d H:i:s', (int)$parsed['validFrom_time_t']),
        'valid_to'        => date('Y-m-d H:i:s', (int)$parsed['validTo_time_t']),
        'cert_pem'        => $certs['cert'],
        'key_pem'         => $certs['pkey'],
        'p12_data'        => $p12_data,
        'p12_pass'        => $password,
    ];
}

/**
 * Odwołuje End Entity w EJBCA po nazwie użytkownika (WS revokeUser) —
 * odwołuje wszystkie jego certyfikaty. Prostsze i pewniejsze niż revokeCert
 * (który wymaga dopasowania dokładnego issuerDN jako stringa).
 */
function ejbca_revoke_user(string $ejbca_username, int $reason = 0): void {
    $client = ejbca_soap_client();
    // reason 0 = UNSPECIFIED (RFC 5280); deleteUser=false — zostaw wpis end entity w EJBCA.
    $client->revokeUser($ejbca_username, $reason, false);
}
