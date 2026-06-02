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

    $org       = defined('ORG_NAME') ? htmlspecialchars(ORG_NAME) : '';
    $app_url   = defined('APP_URL')  ? htmlspecialchars(rtrim(APP_URL, '/')) : '';
    $app_key   = defined('APP_KEY')  ? substr(APP_KEY, 0, 8) . '…' : '—';
    $php_ver   = PHP_VERSION;
    $sapi      = php_sapi_name();
    $ts        = date('Y-m-d H:i:s T');
    $certs_dir = dirname(__DIR__) . '/certs';
    $crt_ok    = file_exists($certs_dir . '/app.crt') ? 'present' : 'MISSING';
    $sig_ok    = file_exists($certs_dir . '/app.sig') ? 'present' : 'MISSING';
    $ssl_ok    = extension_loaded('openssl') ? 'loaded' : 'MISSING';
    $creator   = $app_url ? $app_url . '/creator.php' : '/creator.php';

    $hint_html = $hint ? '<div class="cmd">' . $hint . '</div>' : '';

    echo '<!doctype html><html lang="pl"><head>'
       . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>503 License Error</title>'
       . '<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100vh;background:#0a0a0a;color:#c8c8c8;font-family:"Courier New",Courier,monospace;font-size:13px;line-height:1.7}
.wrap{max-width:720px;margin:0 auto;padding:3rem 1.5rem}
.top{color:#555;font-size:.8rem;margin-bottom:2rem}
.top b{color:#888}
h1{font-size:1rem;color:#e8e8e8;font-weight:700;margin-bottom:.25rem}
.err-code{color:#c0392b;font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;margin-bottom:1.5rem}
p{color:#888;font-size:.85rem;margin-bottom:.75rem}
.section{border-top:1px solid #1a1a1a;margin-top:1.5rem;padding-top:1rem}
.section-title{color:#555;font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;margin-bottom:.6rem}
.kv{width:100%;border-collapse:collapse;font-size:.82rem;margin-bottom:.75rem}
.kv td{padding:.25rem 0;vertical-align:top}
.kv td:first-child{color:#555;width:38%;padding-right:1rem;white-space:nowrap}
.ok{color:#3d9970}.err{color:#c0392b}.warn{color:#c9a227}
.cmd{background:#111;border-left:2px solid #333;padding:.6rem .9rem;font-size:.8rem;color:#7dd3fc;margin:.75rem 0;word-break:break-all}
.link{display:inline-block;margin-top:1rem;color:#7dd3fc;font-size:.82rem;text-decoration:none;border-bottom:1px solid #334}
.link:hover{color:#93c5fd}
.foot{color:#2a2a2a;font-size:.72rem;margin-top:2.5rem}
</style>'
       . '</head><body><div class="wrap">'
       . '<div class="top"><b>platforma-ngo</b> · ' . htmlspecialchars($org) . ' · ' . htmlspecialchars($ts) . '</div>'
       . '<div class="err-code">503 · license_error</div>'
       . '<h1>' . htmlspecialchars($title) . '</h1>'
       . '<p>' . htmlspecialchars($message) . '</p>'
       . $hint_html
       . '<div class="section"><div class="section-title">diagnostics</div>'
       . '<table class="kv">'
       . '<tr><td>certs/app.crt</td><td class="' . ($crt_ok === 'present' ? 'ok' : 'err') . '">' . $crt_ok . '</td></tr>'
       . '<tr><td>certs/app.sig</td><td class="' . ($sig_ok === 'present' ? 'ok' : 'err') . '">' . $sig_ok . '</td></tr>'
       . '<tr><td>php_openssl</td><td class="' . ($ssl_ok === 'loaded' ? 'ok' : 'err') . '">' . $ssl_ok . '</td></tr>'
       . '<tr><td>php</td><td>' . htmlspecialchars($php_ver) . ' (' . htmlspecialchars($sapi) . ')</td></tr>'
       . '<tr><td>app_key</td><td>' . htmlspecialchars($app_key) . '</td></tr>'
       . '<tr><td>app_url</td><td>' . htmlspecialchars($app_url ?: '—') . '</td></tr>'
       . '<tr><td>certs_dir</td><td style="font-size:.75rem">' . htmlspecialchars($certs_dir) . '</td></tr>'
       . '</table></div>'
       . '<div class="section"><div class="section-title">resolve</div>'
       . '<div class="cmd">// Opcja 1 — panel twórcy (przeglądarka)<br>'
       . '<a href="' . htmlspecialchars($creator) . '" class="link" style="margin:0">' . htmlspecialchars($creator) . '</a></div>'
       . '<div class="cmd">// Opcja 2 — CLI (SSH)<br>'
       . 'php ' . htmlspecialchars(dirname(__DIR__)) . '/cli/generatorCertyfikatu.php</div>'
       . '</div>'
       . '<div class="foot">platforma-ngo · bootstrap_halt · ' . htmlspecialchars($ts) . '</div>'
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
