<?php
/**
 * cli/generatorCertyfikatu.php
 *
 * Generuje certyfikat instalacyjny x509 (RSA-2048, self-signed, 30 dni)
 * powiązany z numerem KRS organizacji i zapisuje:
 *   certs/app.crt   — certyfikat PEM
 *   certs/app.key   — klucz prywatny PEM (chmod 600)
 *   certs/app.sig   — HMAC-SHA256(cert_pem, APP_KEY)
 *   certs/app.salt  — losowy salt 64-hex (dostępny przez cert-salt.php)
 *   certs/.htaccess — blokada bezpośredniego HTTP do katalogu
 *
 * Logika generowania/zapisu jest w includes/app_cert.php (wspólna z
 * cli/refresh_cert.php — jedno źródło prawdy dla plików app.*).
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
require_once $root . '/includes/app_cert.php';

// ── Sprawdzenie OpenSSL ───────────────────────────────────────────────────────
if (!extension_loaded('openssl')) {
    fwrite(STDERR, "[BLAD] Rozszerzenie openssl jest wymagane.\n");
    exit(1);
}

// ── Katalog certyfikatów (+ .htaccess) ────────────────────────────────────────
$certs_dir = app_cert_dir();
$paths     = app_cert_paths();
$crt_file  = $paths['crt'];
$key_file  = $paths['key'];
$sig_file  = $paths['sig'];
$salt_file = $paths['salt'];

// ── Sprawdzenie --status ──────────────────────────────────────────────────────
if (in_array('--status', $argv, true)) {
    $st = app_cert_status();
    if (!$st) {
        echo "[STATUS] Brak certyfikatu lub plik nieprawidłowy ({$crt_file}).\n";
        exit(0);
    }
    $from      = date('Y-m-d H:i', $st['valid_from']);
    $to        = date('Y-m-d H:i', $st['valid_to']);
    $days_left = $st['days_left'];

    echo "┌─ Certyfikat instalacyjny ─────────────────────────────────────────\n";
    echo "│ KRS:         " . ($st['krs'] ?: '—') . "\n";
    echo "│ Organizacja: " . ($st['cn'] ?: '—') . "\n";
    echo "│ Ważny od:    {$from}\n";
    echo "│ Ważny do:    {$to}";
    if ($days_left < 0) {
        echo "  [WYGASŁ " . abs($days_left) . " dni temu]\n";
    } elseif ($days_left <= 30) {
        echo "  [UWAGA: wygasa za {$days_left} dni]\n";
    } else {
        echo "  [OK: {$days_left} dni]\n";
    }
    echo "│ HMAC:        " . ($st['hmac_ok'] ? "OK (powiązany z tą instalacją)" : "NIEZGODNY / brak app.sig") . "\n";
    echo "│ Salt:        " . ($st['has_salt'] ? "dostępny (przez /cert-salt.php)" : "brak — wygeneruj ponownie") . "\n";
    echo "└───────────────────────────────────────────────────────────────────\n";
    exit(0);
}

// ── Odczyt KRS i nazwy (argumenty lub baza) ───────────────────────────────────
if (($argv[1] ?? '') !== '') {
    echo "[INFO] Pobieranie danych z argumentów...\n";
} else {
    echo "[INFO] Pobieranie danych z bazy danych...\n";
}
$id   = app_cert_identity((string)($argv[1] ?? ''), (string)($argv[2] ?? ''));
$krs  = $id['krs'];
$name = $id['name'];

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

// ── Generowanie (30 dni) przez wspólny helper ─────────────────────────────────
echo "[INFO] Generowanie certyfikatu (RSA-2048, 30 dni)...\n";
$res = app_cert_generate($krs, $name, 30);
if (!$res['ok']) {
    fwrite(STDERR, "[BLAD] " . $res['msg'] . "\n");
    exit(1);
}
echo "[OK]   Zapisano: {$crt_file}\n";
echo "[OK]   Zapisano: {$key_file}  (chmod 600)\n";
echo "[OK]   Zapisano: {$sig_file}\n";
echo "[OK]   Zapisano: {$salt_file}  (dostępny przez /cert-salt.php)\n";

// ── Podsumowanie ──────────────────────────────────────────────────────────────
$valid_to  = $res['valid_to'];
$days_left = (int)ceil(($valid_to - time()) / 86400);

echo "\n┌─ Certyfikat wygenerowany pomyślnie ──────────────────────────────\n";
echo "│ KRS:         KRS:{$krs}\n";
echo "│ Organizacja: {$name}\n";
echo "│ Ważny do:    " . date('Y-m-d', $valid_to) . " (za {$days_left} dni)\n";
echo "│ HMAC:        {$res['sig']}\n";
echo "└──────────────────────────────────────────────────────────────────\n\n";
echo "[OK] Gotowe. Uruchom ponownie serwer www, jeśli header.php był już załadowany.\n";
