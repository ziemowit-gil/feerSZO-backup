<?php
/**
 * cli/generatorCertyfikatu.php
 *
 * Generuje certyfikat instalacyjny x509 (RSA-2048, self-signed, 2 lata)
 * powiązany z numerem KRS organizacji i zapisuje:
 *   certs/app.crt  — certyfikat PEM
 *   certs/app.key  — klucz prywatny PEM (chmod 600)
 *   certs/app.sig  — HMAC-SHA256(cert_pem, APP_KEY)
 *
 * Użycie:
 *   php cli/generatorCertyfikatu.php [KRS] ["Nazwa organizacji"]
 *   php cli/generatorCertyfikatu.php              # odczyt KRS i nazwy z bazy
 *   php cli/generatorCertyfikatu.php --status     # stan aktualnego certyfikatu
 *
 * Wymaga: PHP z rozszerzeniami openssl + pdo
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Tylko CLI.');
}

// ── Ładowanie konfiguracji ────────────────────────────────────────────────────
$root = dirname(__DIR__);
require_once $root . '/config.php';

// ── Sprawdzenie OpenSSL ───────────────────────────────────────────────────────
if (!extension_loaded('openssl')) {
    fwrite(STDERR, "[BLAD] Rozszerzenie openssl jest wymagane.\n");
    exit(1);
}

// ── Katalog certyfikatów ──────────────────────────────────────────────────────
$certs_dir = $root . '/certs';
if (!is_dir($certs_dir)) {
    mkdir($certs_dir, 0755, true);
    echo "[INFO] Utworzono katalog: {$certs_dir}\n";
}

$crt_file = $certs_dir . '/app.crt';
$key_file  = $certs_dir . '/app.key';
$sig_file  = $certs_dir . '/app.sig';

// ── Sprawdzenie --status ──────────────────────────────────────────────────────
if (in_array('--status', $argv, true)) {
    if (!file_exists($crt_file)) {
        echo "[STATUS] Brak certyfikatu ({$crt_file}).\n";
        exit(0);
    }
    $pem    = file_get_contents($crt_file);
    $parsed = openssl_x509_parse($pem);
    if (!$parsed) {
        echo "[STATUS] Plik certyfikatu jest nieprawidłowy.\n";
        exit(1);
    }
    $from      = date('Y-m-d H:i', $parsed['validFrom_time_t']);
    $to        = date('Y-m-d H:i', $parsed['validTo_time_t']);
    $days_left = (int)ceil(($parsed['validTo_time_t'] - time()) / 86400);
    $krs       = preg_replace('/^KRS:/', '', $parsed['subject']['serialNumber'] ?? '—');
    $cn        = $parsed['subject']['CN'] ?? '—';

    echo "┌─ Certyfikat instalacyjny ─────────────────────────────────────────\n";
    echo "│ KRS:         {$krs}\n";
    echo "│ Organizacja: {$cn}\n";
    echo "│ Ważny od:    {$from}\n";
    echo "│ Ważny do:    {$to}";
    if ($days_left < 0) {
        echo "  [WYGASŁ " . abs($days_left) . " dni temu]\n";
    } elseif ($days_left <= 30) {
        echo "  [UWAGA: wygasa za {$days_left} dni]\n";
    } else {
        echo "  [OK: {$days_left} dni]\n";
    }

    // HMAC check
    if (file_exists($sig_file)) {
        $stored  = trim(file_get_contents($sig_file));
        $expect  = hash_hmac('sha256', $pem, APP_KEY);
        $hmac_ok = hash_equals($expect, $stored);
        echo "│ HMAC:        " . ($hmac_ok ? "OK (powiązany z tą instalacją)" : "NIEZGODNY — certyfikat obcy lub APP_KEY zmieniony") . "\n";
    } else {
        echo "│ HMAC:        Brak pliku app.sig\n";
    }
    echo "└───────────────────────────────────────────────────────────────────\n";
    exit(0);
}

// ── Odczyt KRS i nazwy ────────────────────────────────────────────────────────
$arg_krs  = $argv[1] ?? null;
$arg_name = $argv[2] ?? null;

$krs  = '';
$name = '';

// Argument z CLI
if ($arg_krs !== null && $arg_krs !== '') {
    $krs  = preg_replace('/\D/', '', $arg_krs);
    $name = $arg_name ?? '';
}

// Fallback: baza danych
if ($krs === '' || $name === '') {
    echo "[INFO] Pobieranie danych z bazy danych...\n";
    try {
        require_once $root . '/includes/db.php';

        if ($krs === '') {
            $row = db_one("SELECT value FROM settings WHERE key_='org_krs'");
            $krs = preg_replace('/\D/', '', $row['value'] ?? '');
        }
        if ($name === '') {
            $row  = db_one("SELECT value FROM settings WHERE key_='org_name'");
            $name = trim($row['value'] ?? '');
        }
        if ($name === '' && defined('ORG_NAME')) {
            $name = ORG_NAME;
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[BLAD] Nie można odczytać danych z bazy: " . $e->getMessage() . "\n");
    }
}

if ($krs === '') {
    fwrite(STDERR, "[BLAD] Numer KRS jest wymagany.\n");
    fwrite(STDERR, "Użycie: php cli/generatorCertyfikatu.php <KRS> [\"Nazwa organizacji\"]\n");
    exit(1);
}

if ($name === '') {
    fwrite(STDERR, "[BLAD] Nazwa organizacji jest wymagana.\n");
    fwrite(STDERR, "Użycie: php cli/generatorCertyfikatu.php <KRS> \"Nazwa organizacji\"\n");
    exit(1);
}

echo "[INFO] KRS:         {$krs}\n";
echo "[INFO] Organizacja: {$name}\n";

// ── Generowanie klucza prywatnego RSA-2048 ────────────────────────────────────
echo "[INFO] Generowanie klucza RSA-2048...\n";
$pkey = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'encrypt_key'      => false,
]);

if (!$pkey) {
    fwrite(STDERR, "[BLAD] Generowanie klucza nie powiodło się: " . openssl_error_string() . "\n");
    exit(1);
}

// ── Dane podmiotu certyfikatu ─────────────────────────────────────────────────
$dn = [
    'C'            => 'PL',
    'ST'           => 'Polska',
    'O'            => $name,
    'OU'           => 'Rejestr Umow',
    'CN'           => $name,
    'serialNumber' => 'KRS:' . $krs,
];

// ── CSR ───────────────────────────────────────────────────────────────────────
$csr = openssl_csr_new($dn, $pkey, ['digest_alg' => 'sha256']);
if (!$csr) {
    fwrite(STDERR, "[BLAD] Tworzenie CSR nie powiodło się: " . openssl_error_string() . "\n");
    exit(1);
}

// ── Self-signed certificate (730 dni = ~2 lata) ───────────────────────────────
echo "[INFO] Podpisywanie certyfikatu (730 dni)...\n";
$cert = openssl_csr_sign($csr, null, $pkey, 730, ['digest_alg' => 'sha256'], (int)(microtime(true) * 1000) & 0x7FFFFFFF);
if (!$cert) {
    fwrite(STDERR, "[BLAD] Podpisywanie certyfikatu nie powiodło się: " . openssl_error_string() . "\n");
    exit(1);
}

// ── Eksport PEM ───────────────────────────────────────────────────────────────
$cert_pem = '';
$key_pem  = '';

openssl_x509_export($cert, $cert_pem);
openssl_pkey_export($pkey, $key_pem);

if ($cert_pem === '' || $key_pem === '') {
    fwrite(STDERR, "[BLAD] Eksport PEM nie powiodł się.\n");
    exit(1);
}

// ── Zapis plików ──────────────────────────────────────────────────────────────
// app.crt
if (file_put_contents($crt_file, $cert_pem) === false) {
    fwrite(STDERR, "[BLAD] Zapis {$crt_file} nie powiodł się.\n");
    exit(1);
}
echo "[OK]   Zapisano: {$crt_file}\n";

// app.key (chmod 600)
if (file_put_contents($key_file, $key_pem) === false) {
    fwrite(STDERR, "[BLAD] Zapis {$key_file} nie powiodł się.\n");
    exit(1);
}
chmod($key_file, 0600);
echo "[OK]   Zapisano: {$key_file}  (chmod 600)\n";

// app.sig — HMAC(cert_pem, APP_KEY)
$sig = hash_hmac('sha256', $cert_pem, APP_KEY);
if (file_put_contents($sig_file, $sig) === false) {
    fwrite(STDERR, "[BLAD] Zapis {$sig_file} nie powiodł się.\n");
    exit(1);
}
echo "[OK]   Zapisano: {$sig_file}\n";

// ── Podsumowanie ──────────────────────────────────────────────────────────────
$parsed    = openssl_x509_parse($cert_pem);
$valid_to  = $parsed['validTo_time_t'] ?? 0;
$days_left = (int)ceil(($valid_to - time()) / 86400);

echo "\n┌─ Certyfikat wygenerowany pomyślnie ──────────────────────────────\n";
echo "│ KRS:         KRS:{$krs}\n";
echo "│ Organizacja: {$name}\n";
echo "│ Ważny do:    " . date('Y-m-d', $valid_to) . " (za {$days_left} dni)\n";
echo "│ HMAC:        {$sig}\n";
echo "└──────────────────────────────────────────────────────────────────\n\n";
echo "[OK] Gotowe. Uruchom ponownie serwer www, jeśli header.php był już załadowany.\n";
