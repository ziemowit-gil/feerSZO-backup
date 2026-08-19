#!/usr/bin/env php
<?php
/**
 * cli/ldap_info.php — Informacje o serwerze LDAP + gotowe bloki konfiguracyjne
 *                     dla innych usług używających tego samego katalogu.
 *
 * Testuje połączenie, wyświetla parametry i generuje snippety config dla:
 *   • SZO (config.local.php)
 *   • Gitea (Admin → Authentication → LDAP)
 *   • Nextcloud (LDAP User and Group Backend)
 *   • Nginx (auth_request / ngx_http_auth_ldap)
 *   • Generic .env / docker-compose
 *
 * Użycie:
 *   php cli/ldap_info.php                # pełny raport
 *   php cli/ldap_info.php --no-connect   # tylko parametry, bez testu połączenia
 *   php cli/ldap_info.php --env /opt/feer-szo/docker/.env  # wczytaj hasło z .env
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("Tylko CLI.\n"); }

define('APP_CLI', true);
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);

$root = dirname(__DIR__);
require_once $root . '/config.php';

// ── Opcje ─────────────────────────────────────────────────────────────────────
$opts       = getopt('', ['no-connect', 'env:']);
$no_connect = isset($opts['no-connect']);
$env_file   = $opts['env'] ?? null;

// Szukaj .env Docker gdy hasło nie skonfigurowane
if (!$env_file) {
    foreach ([
        $root . '/docker/.env',
        $root . '/../docker/.env',
        '/opt/feer-szo/docker/.env',
        dirname($root) . '/docker/.env',
    ] as $candidate) {
        if (is_readable($candidate)) { $env_file = $candidate; break; }
    }
}

// ── Kolory ────────────────────────────────────────────────────────────────────
$no_color = getenv('NO_COLOR') !== false && getenv('NO_COLOR') !== '';
$c  = fn(string $code, string $s) => $no_color ? $s : "\033[{$code}m{$s}\033[0m";
$h1 = fn(string $s) => print("\n" . $c('1;34', "━━ $s " . str_repeat('─', max(0, 55 - mb_strlen($s)))) . "\n");
$ok = fn(string $s) => print($c('0;32', "  ✔ $s") . "\n");
$er = fn(string $s) => print($c('0;31', "  ✖ $s") . "\n");
$kv = fn(string $k, string $v) => printf("  %-22s %s\n", $c('0;36', $k . ':'), $v);

// ── Czytaj .env ───────────────────────────────────────────────────────────────
$env = [];
if ($env_file && is_readable($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\r\n\"'");
    }
}

// ── Parametry LDAP ────────────────────────────────────────────────────────────
$host     = defined('LDAP_HOST')     ? LDAP_HOST     : ($env['LDAP_HOST'] ?? '127.0.0.1');
$port     = defined('LDAP_PORT')     ? (int)LDAP_PORT : (int)($env['LDAP_PORT'] ?? 389);
$base_dn  = defined('LDAP_BASE_DN')  ? LDAP_BASE_DN  : ($env['LDAP_BASE_DN']  ?? '');
$users_ou = defined('LDAP_USERS_OU') ? LDAP_USERS_OU : ($env['LDAP_USERS_OU'] ?? '');
$bind_dn  = defined('LDAP_BIND_DN')  ? LDAP_BIND_DN  : ($env['LDAP_BIND_DN']  ?? '');
$bind_pw  = defined('LDAP_BIND_PW')  ? LDAP_BIND_PW  : ($env['LDAP_ADMIN_PASSWORD'] ?? '');
$use_tls  = defined('LDAP_USE_TLS')  ? LDAP_USE_TLS  : false;

// Pochodne
$domain   = $env['LDAP_DOMAIN']   ?? preg_replace('/,dc=/i', '.', preg_replace('/^dc=/i', '', $base_dn));
$org      = $env['LDAP_ORGANISATION'] ?? 'Fundacja FEER';
$docker_host = 'ldap'; // nazwa usługi docker compose

// ── Nagłówek ──────────────────────────────────────────────────────────────────
echo "\n";
echo $c('1', "╔══════════════════════════════════════════════════════════════╗") . "\n";
echo $c('1', "║  FEER SZO — Informacje o serwerze LDAP                       ║") . "\n";
echo $c('1', "╚══════════════════════════════════════════════════════════════╝") . "\n";

if ($env_file) {
    echo $c('0;90', "  Plik .env: $env_file") . "\n";
}

// ── 1. Parametry serwera ──────────────────────────────────────────────────────
$h1('Parametry serwera LDAP');
$kv('Host (zewnętrzny)', "$host:$port");
$kv('Host (z Dockera)', "$docker_host:389");
$kv('Base DN', $base_dn ?: '(niezdefiniowane)');
$kv('Users OU', $users_ou ?: '(niezdefiniowane)');
$kv('Bind DN', $bind_dn ?: '(niezdefiniowane)');
$kv('Bind hasło', $bind_pw ? str_repeat('●', min(12, strlen($bind_pw))) : $c('0;33', '(brak — podaj --env lub ustaw LDAP_BIND_PW)'));
$kv('TLS (STARTTLS)', $use_tls ? 'tak' : 'nie');

// phpLDAPadmin
$pla_port = 8389;
echo "\n";
$kv('phpLDAPadmin URL', "http://127.0.0.1:{$pla_port}/");
$kv('phpLDAPadmin login', $bind_dn ?: 'cn=admin,' . $base_dn);

// ── 2. Test połączenia ─────────────────────────────────────────────────────────
$h1('Test połączenia');
if ($no_connect) {
    echo "  " . $c('0;33', "(pominięto —--no-connect)") . "\n";
} elseif (!function_exists('ldap_connect')) {
    $er("Rozszerzenie PHP ldap nie jest załadowane (apt install php-ldap).");
} elseif (!$bind_pw) {
    $er("Brak hasła — podaj --env /ścieżka/.env lub zdefiniuj LDAP_BIND_PW.");
} else {
    $conn = @ldap_connect("ldap://{$host}", $port);
    if (!$conn) {
        $er("Nie udało się nawiązać połączenia z ldap://{$host}:{$port}");
    } else {
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        $bound = @ldap_bind($conn, $bind_dn, $bind_pw);
        if (!$bound) {
            $er("Bind nieudany: " . ldap_error($conn));
        } else {
            $ok("Połączono i zbindowano: {$bind_dn}");
            // Policz wpisy w users OU
            if ($users_ou) {
                $res = @ldap_search($conn, $users_ou, '(objectClass=inetOrgPerson)', ['uid'], 0, 0);
                if ($res) {
                    $count = ldap_count_entries($conn, $res);
                    $ok("Wpisy w {$users_ou}: {$count} kont");
                }
            }
        }
        ldap_close($conn);
    }
}

// ── 3. config.local.php SZO ───────────────────────────────────────────────────
$h1('Blok config.local.php (SZO)');
echo $c('0;90', <<<EOT
  // ── LDAP ─────────────────────────────────────────────────────────────────
  define('LDAP_ENABLED',  true);
  define('LDAP_HOST',     '127.0.0.1');   // z Dockera: '{$docker_host}'
  define('LDAP_PORT',     {$port});
  define('LDAP_BIND_DN',  '{$bind_dn}');
  define('LDAP_BIND_PW',  '{$bind_pw}');  // LDAP_ADMIN_PASSWORD z .env
  define('LDAP_BASE_DN',  '{$base_dn}');
  define('LDAP_USERS_OU', '{$users_ou}');

EOT) . "\n";

// ── 4. Gitea ──────────────────────────────────────────────────────────────────
$h1('Gitea — Admin → Authentication → Add Auth Source → LDAP (Bind DN)');
printf("  %-30s %s\n", "Authentication Type:", "LDAP (Bind DN)");
printf("  %-30s %s\n", "Host:", $host);
printf("  %-30s %s\n", "Port:", $port);
printf("  %-30s %s\n", "Bind DN:", $bind_dn);
printf("  %-30s %s\n", "Bind Password:", $bind_pw ?: '(uzupełnij)');
printf("  %-30s %s\n", "User Search Base:", $users_ou);
printf("  %-30s %s\n", "User Filter:", '(&(objectClass=inetOrgPerson)(uid=%s))');
printf("  %-30s %s\n", "Username Attr:", 'uid');
printf("  %-30s %s\n", "Firstname Attr:", 'givenName');
printf("  %-30s %s\n", "Surname Attr:", 'sn');
printf("  %-30s %s\n", "Email Attr:", 'mail');

// ── 5. Nextcloud ──────────────────────────────────────────────────────────────
$h1('Nextcloud — Settings → LDAP/AD Integration');
printf("  %-30s %s\n", "Server:", "ldap://{$host}:{$port}");
printf("  %-30s %s\n", "Port:", $port);
printf("  %-30s %s\n", "User DN:", $bind_dn);
printf("  %-30s %s\n", "Base DN:", $base_dn);
printf("  %-30s %s\n", "Users filter:", '(|(objectclass=inetOrgPerson))');
printf("  %-30s %s\n", "Login attr:", 'uid');
printf("  %-30s %s\n", "Email attr:", 'mail');
printf("  %-30s %s\n", "Display name:", 'cn');

// ── 6. Generic .env / docker-compose ─────────────────────────────────────────
$h1('Generic .env (docker-compose, inne aplikacje)');
echo $c('0;90', <<<EOT
  LDAP_URL=ldap://{$docker_host}:389
  LDAP_BASE_DN={$base_dn}
  LDAP_BIND_DN={$bind_dn}
  LDAP_BIND_PASSWORD={$bind_pw}
  LDAP_USERS_BASE={$users_ou}
  LDAP_USER_FILTER=(objectClass=inetOrgPerson)
  LDAP_USER_LOGIN_ATTR=uid
  LDAP_USER_EMAIL_ATTR=mail
  LDAP_USER_DISPLAY_ATTR=cn

EOT) . "\n";

// ── 7. nginx auth_ldap (ngx_http_auth_ldap_module) ───────────────────────────
$h1('nginx — ngx_http_auth_ldap_module (nginx.conf)');
echo $c('0;90', <<<EOT
  ldap_server feer_ldap {
      url ldap://{$host}:{$port}/{$users_ou}?uid?sub?(objectClass=inetOrgPerson);
      binddn "{$bind_dn}";
      binddn_passwd "{$bind_pw}";
      group_attribute uniqueMember;
      group_attribute_is_dn on;
      require valid_user;
  }

EOT) . "\n";

// ── 8. Komendy diagnostyczne ──────────────────────────────────────────────────
$h1('Komendy diagnostyczne (z hosta / z kontenera PHP)');
$pw_arg = $bind_pw ? "-w " . escapeshellarg($bind_pw) : "-W";
echo "  # Lista wszystkich kont:\n";
echo $c('0;90', "  ldapsearch -x -H ldap://{$host}:{$port} -D \"{$bind_dn}\" {$pw_arg} \\\n    -b \"{$users_ou}\" \"(objectClass=inetOrgPerson)\" uid cn mail\n") . "\n";
echo "  # Test bind:\n";
echo $c('0;90', "  ldapwhoami -x -H ldap://{$host}:{$port} -D \"{$bind_dn}\" {$pw_arg}\n") . "\n";
echo "  # Z wewnątrz Dockera (php container):\n";
echo $c('0;90', "  docker exec feer-php ldapsearch -x -H ldap://ldap:389 -D \"{$bind_dn}\" {$pw_arg} -b \"{$users_ou}\" uid\n") . "\n";

echo $c('1;32', "\nGotowe.") . " Wszystkie bloki możesz bezpośrednio wkleić do odpowiednich konfiguracji.\n\n";
exit(0);
