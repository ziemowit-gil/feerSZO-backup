#!/usr/bin/env php
<?php
/**
 * cli/nowehaslo_test_user_delete.php — usuwa konto testowe /nowehaslo założone przez cli/nowehaslo_test_user.php (polecenie 2026-11-13).
 *
 * Usuwa: konto w SZO (users), wpis konta logowania jednokrotnego/chmury w SZO (sso_accounts), mapę zgłoszenia Helpdesku
 * (nowehaslo_helpdesk) i liczniki prób /nowehaslo (login_attempts). Zostawia historię: zgłoszenia w Helpdesku i audit_log.
 * Kont w zewnętrznych systemach (skrzynka pocztowa, Logto, chmura) NIE usuwa — skrypt je wypisze, jeśli były powiązane.
 *
 * Bezpiecznik: usuwa tylko konto, które założył skrypt testowy (wpis audit_log „nowehaslo.test_user”), i nigdy administratora.
 * --force pomija sprawdzenie wpisu testowego (administratora nadal nie usunie).
 *
 * Użycie (na serwerze, w katalogu aplikacji):
 *   php cli/nowehaslo_test_user_delete.php --dry-run                      # pokaż, co zniknie
 *   php cli/nowehaslo_test_user_delete.php [--email=test.nowehaslo@feer.org.pl]
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/audit_logs/logic/audit_logs.php';

$o = getopt('', ['email::', 'dry-run', 'force']);
$email = strtolower(trim((string)($o['email'] ?? 'test.nowehaslo@feer.org.pl')));
$dry = isset($o['dry-run']);
echo ($dry ? 'PODGLĄD (bez zapisu)' : 'ZAPIS') . ' — baza: ' . DB_PATH . "\n";

$u = db_one("SELECT id, name, email, role, m365_login FROM users WHERE LOWER(email)=?", [$email]);
if (!$u) exit("Nie ma konta {$email} — nic do usunięcia.\n");
$id = (int)$u['id'];
if (($u['role'] ?? '') === 'admin') exit("STOP: {$email} to administrator — tego skrypt nie usuwa.\n");
audit_logs_migrate();
$marked = (bool)db_one("SELECT id FROM audit_logs WHERE action='nowehaslo.test_user' AND details LIKE ?", ['%"user_id":' . $id . ',%']);
if (!$marked && !isset($o['force'])) {
    exit("STOP: konta #{$id} {$email} nie założył skrypt testowy (brak wpisu nowehaslo.test_user w dzienniku). Jeśli na pewno chcesz je usunąć, dodaj --force.\n");
}

$keys = array_values(array_unique(array_filter([$email, strtolower(trim((string)($u['m365_login'] ?? '')))])));
$in = implode(',', array_fill(0, count($keys), '?'));
$sso = [];
try { $sso = db_all("SELECT email, logto_id, cloud_uid FROM sso_accounts WHERE user_id=? OR email IN ({$in})", [$id, ...$keys]); } catch (\Throwable $e) {}
$limitIds = [];
foreach ($keys as $k) foreach (['addr:', 'code:', 'code:g:', 'chg:'] as $p) $limitIds[] = 'NOWEHASLO:' . $p . $k;

echo "Konto: #{$id} {$u['name']} <{$email}>\n";
foreach ($sso as $r) {
    $ext = array_filter(['Logto: ' . $r['logto_id'] => !empty($r['logto_id']), 'chmura: ' . $r['cloud_uid'] => !empty($r['cloud_uid'])]);
    echo "Wpis logowania jednokrotnego w SZO: {$r['email']}" . ($ext ? ' — konta zewnętrzne zostają (' . implode(', ', array_keys($ext)) . '), usuń je ręcznie' : '') . "\n";
}
foreach ($keys as $k) if (str_ends_with($k, '@feer.org.pl')) echo "Skrzynki pocztowej {$k} (jeśli jest) skrypt nie usuwa.\n";
if ($dry) exit("\nNic nie usunięto. Uruchom bez --dry-run, żeby usunąć.\n");

db_exec("DELETE FROM users WHERE id=?", [$id]);
try { db()->prepare("DELETE FROM sso_accounts WHERE user_id=? OR email IN ({$in})")->execute([$id, ...$keys]); } catch (\Throwable $e) {}
try { db()->prepare("DELETE FROM nowehaslo_helpdesk WHERE person IN ({$in})")->execute($keys); } catch (\Throwable $e) {}
try {
    $li = implode(',', array_fill(0, count($limitIds), '?'));
    db()->prepare("DELETE FROM login_attempts WHERE identifier IN ({$li})")->execute($limitIds);
} catch (\Throwable $e) {}
audit_log('nowehaslo.test_user_deleted', ['user_id' => $id, 'email' => $email, 'force' => isset($o['force'])]);
echo "\nUsunięto konto testowe #{$id} {$email}. Zgłoszenia w Helpdesku i dziennik zdarzeń zostały.\n";
