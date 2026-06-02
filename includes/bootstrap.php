<?php
/**
 * includes/bootstrap.php — weryfikacja certyfikatu instalacyjnego x509.
 *
 * Ładowany na początku header.php.
 * Wymaga:
 *   - certs/app.crt  — certyfikat x509 z KRS i nazwą organizacji w Subject
 *   - certs/app.sig  — HMAC(cert_pem, APP_KEY) — wiąże certyfikat z instalacją
 *
 * Generowanie: php cli/generatorCertyfikatu.php
 */

if (defined('BOOTSTRAP_CHECKED')) return;
define('BOOTSTRAP_CHECKED', true);

// Pomiń weryfikację dla CLI
if (php_sapi_name() === 'cli') return;

// ── Ścieżki ───────────────────────────────────────────────────────────────────
$_boot_crt = dirname(__DIR__) . '/certs/app.crt';
$_boot_sig = dirname(__DIR__) . '/certs/app.sig';

// ── Błąd blokujący ────────────────────────────────────────────────────────────
function _bootstrap_halt(string $title, string $message, string $hint = ''): never {
    http_response_code(503);
    $org      = defined('ORG_NAME') ? htmlspecialchars(ORG_NAME) : 'Rejestr Umow';
    $hint_html = $hint ? '<div class="hint">' . $hint . '</div>' : '';
    $css = '*{box-sizing:border-box;margin:0;padding:0}'
         . 'body{font-family:system-ui,sans-serif;background:#f8f9fa;display:flex;'
         . 'align-items:center;justify-content:center;min-height:100vh;padding:1rem}'
         . '.card{background:#fff;border-radius:.75rem;box-shadow:0 4px 24px rgba(0,0,0,.1);'
         . 'max-width:520px;width:100%;padding:2.5rem 2rem;text-align:center}'
         . '.icon{font-size:3rem;margin-bottom:1rem}'
         . 'h1{font-size:1.3rem;font-weight:700;color:#212529;margin-bottom:.75rem}'
         . 'p{color:#495057;font-size:.95rem;line-height:1.6;margin-bottom:.75rem}'
         . '.hint{background:#fff3cd;border:1px solid #ffc107;border-radius:.5rem;'
         . 'padding:.75rem 1rem;font-size:.82rem;color:#856404;text-align:left;'
         . 'font-family:monospace;margin-top:1rem;word-break:break-all}'
         . '.badge{display:inline-block;background:#dc3545;color:#fff;border-radius:.3rem;'
         . 'padding:.25rem .6rem;font-size:.75rem;font-weight:600;margin-bottom:1rem}';
    echo '<!doctype html><html lang="pl"><head>'
       . '<meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . "<title>Blad autoryzacji &mdash; {$org}</title>"
       . "<style>{$css}</style>"
       . '</head><body><div class="card">'
       . '<div class="icon">&#128274;</div>'
       . '<span class="badge">BRAK AUTORYZACJI</span>'
       . "<h1>{$title}</h1>"
       . "<p>{$message}</p>"
       . $hint_html
       . '</div></body></html>';
    exit;
}

// ── Sprawdzenie pliku certyfikatu ─────────────────────────────────────────────
if (!file_exists($_boot_crt) || !is_readable($_boot_crt)) {
    _bootstrap_halt(
        'Brak certyfikatu instalacyjnego',
        'Aplikacja wymaga certyfikatu x509 powiązanego z numerem KRS i nazwą organizacji. Certyfikat nie został jeszcze wygenerowany.',
        'Uruchom: <strong>php cli/generatorCertyfikatu.php</strong>'
    );
}

if (!file_exists($_boot_sig) || !is_readable($_boot_sig)) {
    _bootstrap_halt(
        'Brak pliku podpisu certyfikatu',
        'Plik podpisu HMAC certyfikatu (certs/app.sig) nie istnieje lub jest nieczytelny.',
        'Uruchom ponownie: <strong>php cli/generatorCertyfikatu.php</strong>'
    );
}

if (!extension_loaded('openssl')) {
    _bootstrap_halt(
        'Brak rozszerzenia OpenSSL',
        'PHP musi być skompilowane z obsługą OpenSSL, aby zweryfikować certyfikat instalacyjny.',
        'Zainstaluj: <strong>php-openssl</strong>'
    );
}

// ── Odczyt i parsowanie certyfikatu ───────────────────────────────────────────
$_boot_pem = file_get_contents($_boot_crt);
$_boot_parsed = @openssl_x509_parse($_boot_pem);

if (!$_boot_parsed) {
    _bootstrap_halt(
        'Nieprawidłowy certyfikat',
        'Plik certs/app.crt nie jest prawidłowym certyfikatem x509 lub jest uszkodzony.',
        'Wygeneruj nowy: <strong>php cli/generatorCertyfikatu.php</strong>'
    );
}

// ── Weryfikacja HMAC (powiązanie certyfikatu z APP_KEY tej instalacji) ─────────
$_boot_stored_sig  = trim(file_get_contents($_boot_sig));
$_boot_expected_sig = hash_hmac('sha256', $_boot_pem, APP_KEY);

if (!hash_equals($_boot_expected_sig, $_boot_stored_sig)) {
    _bootstrap_halt(
        'Certyfikat nie należy do tej instalacji',
        'Podpis HMAC certyfikatu nie odpowiada kluczowi APP_KEY tej instalacji. Certyfikat mógł zostać wygenerowany dla innej instalacji lub APP_KEY uległ zmianie.',
        'Wygeneruj nowy certyfikat: <strong>php cli/generatorCertyfikatu.php</strong>'
    );
}

// ── Weryfikacja daty ważności ─────────────────────────────────────────────────
$_boot_valid_to = $_boot_parsed['validTo_time_t'] ?? 0;
$_boot_valid_from = $_boot_parsed['validFrom_time_t'] ?? 0;

if (time() < $_boot_valid_from) {
    _bootstrap_halt(
        'Certyfikat jeszcze nieważny',
        'Data ważności certyfikatu jeszcze nie nastąpiła. Sprawdź datę systemową serwera lub wygeneruj nowy certyfikat.',
        'Certyfikat ważny od: <strong>' . date('Y-m-d H:i', $_boot_valid_from) . '</strong>'
    );
}

if (time() > $_boot_valid_to) {
    $_boot_expired_days = (int)ceil((time() - $_boot_valid_to) / 86400);
    _bootstrap_halt(
        'Certyfikat instalacyjny wygasł',
        "Certyfikat x509 tej instalacji wygasł {$_boot_expired_days} " .
        ($_boot_expired_days === 1 ? 'dzień' : ($_boot_expired_days < 5 ? 'dni' : 'dni')) . " temu.",
        'Odnów certyfikat: <strong>php cli/generatorCertyfikatu.php ' .
        htmlspecialchars($_boot_parsed['subject']['serialNumber'] ?? '') . ' "' .
        htmlspecialchars($_boot_parsed['subject']['CN'] ?? '') . '"</strong>'
    );
}

// ── Weryfikacja KRS i nazwy organizacji ───────────────────────────────────────
$_boot_cert_krs  = preg_replace('/^KRS:/', '', $_boot_parsed['subject']['serialNumber'] ?? '');
$_boot_cert_name = $_boot_parsed['subject']['CN'] ?? '';

// Ostrzeżenie wewnętrzne (nie blokuje — KRS może być ustawiony po instalacji)
if (defined('APP_KEY') && class_exists('PDO')) {
    try {
        $__krs_row  = db_one("SELECT value FROM settings WHERE key_='org_krs'");
        $__name_row = db_one("SELECT value FROM settings WHERE key_='org_name'");

        $_db_krs  = preg_replace('/\D/', '', $__krs_row['value'] ?? '');
        $_db_name = trim($__name_row['value'] ?? (defined('ORG_NAME') ? ORG_NAME : ''));

        if ($_db_krs && $_boot_cert_krs && $_db_krs !== $_boot_cert_krs) {
            _bootstrap_halt(
                'Niezgodność numeru KRS',
                "Certyfikat został wystawiony dla KRS <strong>{$_boot_cert_krs}</strong>, " .
                "natomiast w ustawieniach aplikacji figuruje KRS <strong>{$_db_krs}</strong>.",
                'Wygeneruj nowy certyfikat dla właściwej organizacji: <strong>php cli/generatorCertyfikatu.php</strong>'
            );
        }

        if ($_db_name && $_boot_cert_name
            && strtolower($_db_name) !== strtolower($_boot_cert_name)) {
            // Ostrzeżenie — zapisz do sesji zamiast blokować (nazwa mogła ulec zmianie)
            $_SESSION['_boot_name_warn'] = "Nazwa w certyfikacie: \"{$_boot_cert_name}\" różni się od nazwy w ustawieniach: \"{$_db_name}\".";
        }
    } catch (\Throwable $e) {
        // Baza może nie być jeszcze dostępna (np. pierwszy start) — nie blokuj
    }
}

// ── Ostrzeżenie o zbliżającym się wygaśnięciu (≤30 dni) ──────────────────────
$_boot_days_left = (int)ceil(($_boot_valid_to - time()) / 86400);
if ($_boot_days_left <= 30) {
    $_SESSION['_boot_expiry_warn'] = $_boot_days_left <= 0
        ? 'Certyfikat instalacyjny wygasł!'
        : "Certyfikat instalacyjny wygasa za {$_boot_days_left} " .
          ($_boot_days_left === 1 ? 'dzień' : 'dni') . '. Odnów: php cli/generatorCertyfikatu.php';
}

// Sprzątanie zmiennych tymczasowych
unset($_boot_crt, $_boot_sig, $_boot_pem, $_boot_parsed,
      $_boot_stored_sig, $_boot_expected_sig,
      $_boot_valid_to, $_boot_valid_from, $_boot_cert_krs, $_boot_cert_name,
      $_boot_days_left, $__krs_row, $__name_row, $_db_krs, $_db_name);
