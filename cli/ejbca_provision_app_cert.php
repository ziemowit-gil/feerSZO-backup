<?php
/**
 * cli/ejbca_provision_app_cert.php — jednorazowy bootstrap certyfikatu aplikacji dla EJBCA.
 *
 * Tworzy dedykowany end entity 'feer-app-ra' w EJBCA, wystawia mu PKCS#12,
 * dodaje go do roli administracyjnej (przyszłe wywołania WS z tym certem będą
 * autoryzowane), pobiera łańcuch CA i zapisuje wszystko w certs/
 * (ejbca_client.pem, ejbca_ca.pem — oba chronione .htaccess + chmod 600,
 * zob. includes/app_cert.php::app_cert_dir()).
 *
 * Wymaga TYMCZASOWO certyfikatu administratora (np. SuperAdmin) do
 * jednorazowej autoryzacji tej operacji — plik .p12 nigdzie nie jest
 * zapisywany na stałe, kasowany od razu po użyciu.
 *
 * Użycie (przez docker exec, żeby mieć dostęp do rozszerzenia PHP soap):
 *   docker exec feer-app php cli/ejbca_provision_app_cert.php \
 *     --admin-p12=/var/www/html/bootstrap_admin.p12 --admin-pass=XXXX
 *
 * Wywoływane też przez docker/scripts/ejbca_provision_app_cert.sh (wygodny wrapper).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Ten skrypt można uruchomić tylko z CLI.\n"); }

$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/app_cert.php';
require_once $base . '/includes/ejbca.php';

function arg(string $name, ?string $default = null): ?string {
    foreach ($GLOBALS['argv'] as $a) {
        if (str_starts_with($a, "--{$name}=")) return substr($a, strlen($name) + 3);
    }
    return $default;
}
function cli_ok(string $s): void   { fwrite(STDOUT, "  ✔ {$s}\n"); }
function cli_die(string $s): void  { fwrite(STDERR, "  ✖ {$s}\n"); exit(1); }

$admin_p12  = arg('admin-p12');
$admin_pass = arg('admin-pass');
$ws_url     = arg('ws-url',  'https://ca.feer.org.pl:8443/ejbca/ejbcaws/ejbcaws?wsdl');
$ca_name    = arg('ca-name', 'ManagementCA');
$role_name  = arg('role',    'Super Administrator Role');
$app_username = 'feer-app-ra';

if (!$admin_p12 || !$admin_pass) {
    fwrite(STDERR, "Użycie: php cli/ejbca_provision_app_cert.php --admin-p12=<ścieżka> --admin-pass=<hasło> [--ws-url=...] [--ca-name=ManagementCA] [--role=\"Super Administrator Role\"]\n");
    exit(2);
}
if (!is_file($admin_p12)) cli_die("Nie znaleziono pliku: {$admin_p12}");
if (!extension_loaded('soap')) cli_die('Rozszerzenie PHP soap nie jest zainstalowane — dodaj do Dockerfile i przebuduj obraz (docker/scripts/rebuild.sh --no-cache).');

// ── 1. Konwersja p12 administratora na PEM (jednorazowa, w /tmp kontenera) ────
$certs = [];
if (!openssl_pkcs12_read((string)file_get_contents($admin_p12), $certs, $admin_pass)) {
    cli_die('Nie można odczytać podanego pliku .p12 (złe hasło?).');
}
$admin_pem_tmp = tempnam(sys_get_temp_dir(), 'ejbca_admin_');
file_put_contents($admin_pem_tmp, $certs['cert'] . $certs['pkey']);
chmod($admin_pem_tmp, 0600);
cli_ok('Certyfikat administratora gotowy do jednorazowej autoryzacji.');

$ca_pem = '';
$app_p12 = '';
$app_password = bin2hex(random_bytes(16));

try {
    $ctx = stream_context_create(['ssl' => [
        'local_cert'       => $admin_pem_tmp,
        'verify_peer'      => false,   // bootstrap — jeszcze nie mamy lokalnie zapisanego CA cert
        'verify_peer_name' => false,
    ]]);
    $client = new SoapClient($ws_url, [
        'stream_context'     => $ctx,
        'cache_wsdl'         => WSDL_CACHE_NONE,
        'connection_timeout' => 20,
        'exceptions'         => true,
    ]);
    cli_ok('Połączono z ' . $ws_url);

    // ── 2. Utwórz end entity dla aplikacji ─────────────────────────────────────
    $u = new stdClass();
    $u->username               = $app_username;
    $u->password                = $app_password;
    $u->clearPwd                = true;
    $u->subjectDN               = "CN={$app_username},O=FEER SZO (aplikacja)";
    $u->caName                  = $ca_name;
    $u->certificateProfileName  = 'ENDUSER';
    $u->endEntityProfileName    = 'EMPTY';
    $u->tokenType                = 'P12';
    $u->status                   = 10; // NEW
    $u->keyRecoverable           = false;
    $u->sendNotification         = false;
    $client->editUser($u);
    cli_ok("End entity '{$app_username}' utworzony/zaktualizowany.");

    // ── 3. Wystaw p12 dla aplikacji ─────────────────────────────────────────────
    $ks = $client->pkcs12Req($app_username, $app_password, '', '2048', 'RSA');
    $app_p12 = $ks->return->keystoreData ?? '';
    if ($app_p12 === '') cli_die('EJBCA nie zwróciło danych PKCS#12 dla aplikacji.');
    cli_ok('Certyfikat aplikacji wystawiony.');

    // ── 4. Dodaj do roli administracyjnej — przyszłe wywołania WS tym certem
    //      będą autoryzowane bez potrzeby certu SuperAdmina.
    $client->addSubjectToRole($role_name, $ca_name, 'WITH_COMMONNAME', 'TYPE_EQUALCASE', $app_username);
    cli_ok("Dodano '{$app_username}' do roli '{$role_name}'.");

    // ── 5. Pobierz łańcuch CA (do walidacji certów logowania w x509_login.php)
    $chain = $client->getLastCAChain($ca_name);
    foreach ((array)($chain ?? []) as $entry) {
        $der = $entry->certificateData ?? '';
        if ($der === '') continue;
        $x = @openssl_x509_read($der);
        if (!$x) continue;
        $pem = '';
        openssl_x509_export($x, $pem);
        $ca_pem .= $pem;
    }
    if ($ca_pem === '') cli_die('Nie udało się pobrać łańcucha CA (' . $ca_name . ').');
    cli_ok('Łańcuch CA pobrany.');
} catch (\Throwable $e) {
    @unlink($admin_pem_tmp);
    cli_die('Błąd SOAP: ' . $e->getMessage());
}
@unlink($admin_pem_tmp);

// ── 6. Zapisz pliki w certs/ ─────────────────────────────────────────────────
$appcerts = [];
if (!openssl_pkcs12_read($app_p12, $appcerts, $app_password)) {
    cli_die('Nie można odczytać wygenerowanego PKCS#12 aplikacji.');
}
$client_pem_path = ejbca_client_cert_path();
$ca_pem_path     = ejbca_ca_cert_path();

if (file_put_contents($client_pem_path, $appcerts['cert'] . $appcerts['pkey']) === false) {
    cli_die("Zapis {$client_pem_path} nie powiódł się.");
}
chmod($client_pem_path, 0600);
if (file_put_contents($ca_pem_path, $ca_pem) === false) {
    cli_die("Zapis {$ca_pem_path} nie powiódł się.");
}
cli_ok("Zapisano: {$client_pem_path}");
cli_ok("Zapisano: {$ca_pem_path}");

// ── 7. Włącz integrację w ustawieniach aplikacji ─────────────────────────────
ejbca_save_setting('enabled',  '1');
ejbca_save_setting('ws_url',   $ws_url);
ejbca_save_setting('ca_name',  $ca_name);
cli_ok('Ustawienia EJBCA zapisane (ejbca_enabled=1).');

fwrite(STDOUT, "\n✔ Gotowe. Certyfikat aplikacji '{$app_username}' aktywny.\n");
fwrite(STDOUT, "  Rola nadana: '{$role_name}' — rozważ docelowo zawężenie uprawnień (patrz docker/EJBCA.md).\n");
