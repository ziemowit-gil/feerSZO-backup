#!/usr/bin/env php
<?php
/**
 * cli/nowehaslo_test_user.php — konto testowe do sprawdzenia /nowehaslo (polecenie 2026-11-13).
 *
 * Zakłada w SZO użytkownika (rola viewer, bez znanego hasła — ustawisz je „Nie pamiętasz hasła?” kodem SMS) z adresem służbowym,
 * numerem komórkowym i alternatywnym (prywatnym) e-mailem. Gdy konto o tym adresie już jest — aktualizuje numer i e-mail.
 * Skrzynki pocztowej ani kont logowania jednokrotnego / chmury NIE zakłada (te są w zewnętrznych systemach): założysz je
 * na /migracjapoczty albo /migracjaadmin. Dane osobowe tylko w argumentach — nic nie jest zapisane w repozytorium.
 *
 * Użycie (na serwerze, w katalogu aplikacji):
 *   php cli/nowehaslo_test_user.php --phone="535 231 923" --alt=prywatny@example.com [--email=test.nowehaslo@feer.org.pl] [--name="Test Nowe Hasło"] [--dry-run]
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sms.php';

$o = getopt('', ['phone:', 'alt:', 'email::', 'name::', 'dry-run']);
$email = strtolower(trim((string)($o['email'] ?? 'test.nowehaslo@feer.org.pl')));
$alt   = strtolower(trim((string)($o['alt'] ?? '')));
$name  = trim((string)($o['name'] ?? 'Test Nowe Hasło'));
$d     = preg_replace('/\D/', '', (string)($o['phone'] ?? ''));
if (strlen($d) === 11 && str_starts_with($d, '48')) $d = substr($d, 2);
$phone = strlen($d) === 9 ? '+48 ' . substr($d, 0, 3) . ' ' . substr($d, 3, 3) . ' ' . substr($d, 6) : '';
if ($phone === '' || !sms_is_mobile($phone)) exit("Podaj numer komórkowy: --phone=\"600 100 200\"\n");
if (!filter_var($alt, FILTER_VALIDATE_EMAIL)) exit("Podaj alternatywny (prywatny) e-mail: --alt=adres@example.com\n");
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) exit("Zły adres służbowy (--email).\n");
$dry = isset($o['dry-run']);

[$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
$data = ['name' => $name, 'first_name' => $first, 'last_name' => $last, 'phone_number' => $phone, 'alt_email' => $alt,
         'm365_login' => $email, 'role' => 'viewer', 'is_active' => 1, 'user_status' => 'active'];
$u = db_one("SELECT id FROM users WHERE LOWER(email)=?", [$email]);
echo ($dry ? 'PODGLĄD (bez zapisu)' : 'ZAPIS') . ' — baza: ' . DB_PATH . "\n";
if ($dry) { echo ($u ? "Zaktualizuję konto #{$u['id']}" : 'Założę konto') . " {$email}: tel. {$phone}, alternatywny e-mail {$alt}\n"; exit; }

if ($u) {
    $id = (int)$u['id'];
    $set = implode(', ', array_map(fn($k) => $k . '=?', array_keys($data)));
    db()->prepare("UPDATE users SET {$set} WHERE id=?")->execute([...array_values($data), $id]);
    $what = 'zaktualizowane';
} else {
    $id = db_insert('users', $data + ['email' => $email, 'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                                      'created_at' => date('Y-m-d H:i:s')]);
    $what = 'założone';
}
try { require_once dirname(__DIR__) . '/modules/audit_logs/logic/audit_logs.php'; audit_log('nowehaslo.test_user', ['user_id' => $id, 'email' => $email]); } catch (\Throwable $e) {}
echo "Konto testowe {$what}: #{$id} {$name} <{$email}>, tel. {$phone}, alternatywny e-mail {$alt}\n";
echo "Test bez logowania: " . rtrim(APP_URL, '/') . "/nowehaslo → prywatny e-mail {$alt} + adres {$email} → kod.\n";
