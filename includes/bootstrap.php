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

    $rows = [
        'certs/app.crt' => [$crt_ok === 'present' ? 'OK' : 'FAIL', $crt_ok === 'present'],
        'certs/app.sig' => [$sig_ok === 'present' ? 'OK' : 'FAIL', $sig_ok === 'present'],
        'php openssl'   => [$ssl_ok === 'loaded'  ? 'OK' : 'FAIL', $ssl_ok === 'loaded'],
        'php version'   => [$php_ver, true],
        'app_key'       => [$app_key, true],
        'timestamp'     => [$ts, true],
    ];

    $rows_html = '';
    foreach ($rows as $k => $v) {
        $col   = $v[1] ? '#33ff33' : '#ff3333';
        $blink = !$v[1] ? ' class="blink"' : '';
        $rows_html .= '<tr><td>' . htmlspecialchars(str_pad($k, 16)) . '</td>'
                    . '<td>........</td>'
                    . '<td' . $blink . ' style="color:' . $col . '">' . htmlspecialchars($v[0]) . '</td></tr>';
    }

    echo '<!doctype html><html lang="pl"><head>'
       . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>** SYSTEM HALT **</title>'
       . '<style>
@import url("https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap");
*{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100vh;background:#000;color:#33ff33;font-family:"Share Tech Mono","Courier New",monospace;font-size:14px;line-height:1.8;overflow-x:hidden}
body::before{content:"";position:fixed;inset:0;background:repeating-linear-gradient(0deg,rgba(0,0,0,.15) 0px,rgba(0,0,0,.15) 1px,transparent 1px,transparent 2px);pointer-events:none;z-index:9999}
body::after{content:"";position:fixed;inset:0;background:radial-gradient(ellipse at center,transparent 60%,rgba(0,0,0,.7) 100%);pointer-events:none;z-index:9998}
.wrap{max-width:780px;margin:0 auto;padding:2.5rem 1.5rem;position:relative;z-index:1}
.scanline{position:fixed;top:0;left:0;width:100%;height:3px;background:rgba(51,255,51,.08);animation:scan 6s linear infinite;z-index:10000;pointer-events:none}
@keyframes scan{0%{top:0}100%{top:100%}}
.blink{animation:blink .8s step-end infinite}
@keyframes blink{50%{opacity:0}}
.dim{color:#1a8c1a}
.hi{color:#ccffcc;font-weight:bold}
.err{color:#ff3333}
.warn{color:#ffaa00}
pre{white-space:pre-wrap;word-break:break-all}
table{border-collapse:collapse;width:100%}
td{padding:.1rem 0;vertical-align:top;font-size:.88rem}
td:first-child{color:#1a8c1a;white-space:pre}
td:nth-child(2){color:#0d440d;padding:0 .5rem}
hr{border:none;border-top:1px solid #0d440d;margin:1rem 0}
a{color:#33ff33;text-decoration:none;border-bottom:1px solid #1a8c1a}
a:hover{color:#ccffcc;border-color:#33ff33}
.box{border:1px solid #1a8c1a;padding:.75rem 1rem;margin:.75rem 0}
.box-err{border-color:#ff3333}
</style>'
       . '</head><body>'
       . '<div class="scanline"></div>'
       . '<div class="wrap">'
       . '<pre class="dim">================================================================================</pre>'
       . '<pre class="hi">  PLATFORMA NGO &mdash; SYSTEM BOOT FAILURE                          v503</pre>'
       . '<pre class="dim">================================================================================</pre>'
       . '<br>'
       . '<pre class="dim">SYSTEM  : ' . htmlspecialchars($org) . '</pre>'
       . '<pre class="dim">HOST    : ' . htmlspecialchars($app_url ?: 'unknown') . '</pre>'
       . '<pre class="dim">TIME    : ' . htmlspecialchars($ts) . '</pre>'
       . '<br>'
       . '<pre class="box box-err">'
       . '  !! FATAL ERROR: LICENSE_VERIFICATION_FAILED' . "\n"
       . '  !! ' . htmlspecialchars(strtoupper($title)) . "\n"
       . '  !!' . "\n"
       . '  !! ' . htmlspecialchars($message)
       . '</pre>'
       . '<br>'
       . '<pre class="dim">POST DIAGNOSTICS:</pre>'
       . '<table>' . $rows_html . '</table>'
       . '<br>'
       . '<hr>'
       . '<pre class="dim">RECOMMENDED ACTION:</pre>'
       . '<br>'
       . '<pre class="dim">  [1] BROWSER (creator panel):</pre>'
       . '<pre>      <a href="' . htmlspecialchars($creator) . '">' . htmlspecialchars($creator) . '</a></pre>'
       . '<br>'
       . '<pre class="dim">  [2] SSH / CLI:</pre>'
       . '<pre>      php ' . htmlspecialchars(dirname(__DIR__)) . '/cli/generatorCertyfikatu.php</pre>'
       . '<br>'
       . '<hr>'
       . '<pre class="dim">System halted. Press [1] or run CLI to regenerate certificate.</pre>'
       . '<pre class="dim">================================================================================</pre>'
       . '<pre class="dim blink">_</pre>'
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
