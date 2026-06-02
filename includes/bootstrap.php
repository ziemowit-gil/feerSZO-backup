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
        $ok  = $v[1];
        $val = htmlspecialchars($v[0]);
        $rows_html .=
            '<tr>'
          . '<td style="color:#64748b;padding:.35rem 1rem .35rem 0;white-space:nowrap">' . htmlspecialchars($k) . '</td>'
          . '<td style="' . ($ok ? 'color:#94a3b8' : 'color:#f87171;font-weight:600') . ';padding:.35rem 0">' . $val . '</td>'
          . '</tr>';
    }

    echo '<!doctype html><html lang="pl"><head>'
       . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Błąd autoryzacji — ' . htmlspecialchars($org) . '</title>'
       . '<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100vh;background:#0f172a;color:#cbd5e1;font-family:system-ui,-apple-system,sans-serif;font-size:14px;line-height:1.6}
.page{display:flex;min-height:100vh}
.sidebar{width:4px;background:#dc2626;flex-shrink:0}
.body{flex:1;padding:3rem 2.5rem;max-width:760px}
.label{font-size:.68rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#dc2626;margin-bottom:.5rem}
h1{font-size:1.15rem;font-weight:600;color:#f1f5f9;margin-bottom:.35rem;line-height:1.4}
.sub{font-size:.85rem;color:#64748b;margin-bottom:2rem}
.divider{height:1px;background:#1e293b;margin:1.75rem 0}
.section-label{font-size:.68rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#334155;margin-bottom:.75rem}
table{border-collapse:collapse;width:100%;font-size:.82rem}
td{padding:.3rem 0;vertical-align:top;border-bottom:1px solid #1e293b}
tr:last-child td{border:none}
.action{background:#1e293b;border-left:3px solid #dc2626;padding:.85rem 1.1rem;margin-bottom:.75rem;font-size:.82rem}
.action-label{font-size:.7rem;color:#64748b;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem}
.action a{color:#93c5fd;text-decoration:none;font-family:monospace;font-size:.8rem;word-break:break-all}
.action a:hover{color:#dbeafe}
.action code{font-family:monospace;color:#7dd3fc;font-size:.78rem;word-break:break-all}
.meta{margin-top:2.5rem;font-size:.72rem;color:#1e293b;display:flex;gap:1.5rem;flex-wrap:wrap}
</style>'
       . '</head><body>'
       . '<div class="page"><div class="sidebar"></div><div class="body">'
       . '<div class="label">Błąd krytyczny &nbsp;·&nbsp; 503</div>'
       . '<h1>' . htmlspecialchars($title) . '</h1>'
       . '<p class="sub">' . htmlspecialchars($message) . '</p>'
       . '<div class="divider"></div>'
       . '<div class="section-label">Diagnostyka</div>'
       . '<table>' . $rows_html . '</table>'
       . '<div class="divider"></div>'
       . '<div class="section-label">Jak naprawić</div>'
       . '<div class="action"><div class="action-label">1 &mdash; Panel twórcy (przeglądarka)</div>'
       . '<a href="' . htmlspecialchars($creator) . '">' . htmlspecialchars($creator) . '</a></div>'
       . '<div class="action"><div class="action-label">2 &mdash; SSH / CLI</div>'
       . '<code>php ' . htmlspecialchars(dirname(__DIR__)) . '/cli/generatorCertyfikatu.php</code></div>'
       . '<div class="meta">'
       . '<span>' . htmlspecialchars($org) . '</span>'
       . '<span>' . htmlspecialchars($app_url) . '</span>'
       . '<span>' . htmlspecialchars($ts) . '</span>'
       . '</div>'
       . '</div></div></body></html>';
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
