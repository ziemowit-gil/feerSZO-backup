<?php
declare(strict_types=1);

/**
 * modules/login_sentry/tests/run_tests.php — testy LoginSentry („Staszek”) bez sieci i bez bazy SZO.
 * Atrapy: SZO (FakeDirectory), Logto (FakeLogto), poczta (FakeMailer); baza Staszka w sqlite::memory:.
 * Uruchomienie: php modules/login_sentry/tests/run_tests.php   (kod wyjścia 0 = wszystko przeszło)
 */

namespace Feer\Szo\LoginSentry\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Feer\Szo\LoginSentry\{DirectoryInterface, GeoLookupInterface, IpTools, LoginEvent, LoginSentryConfig, LoginSentryDatabase,
    LoginSentryEngine, LoginSentryTemplates, LogtoGatewayInterface, MailerInterface, NullGeoLookup};

define('LOGIN_SENTRY_STORAGE_DIR', sys_get_temp_dir() . '/login_sentry_test_' . getmypid());
@mkdir(LOGIN_SENTRY_STORAGE_DIR, 0700, true);
require_once dirname(__DIR__) . '/logic/LoginSentryEngine.php';

final class FakeDirectory implements DirectoryInterface
{
    /** @var list<LoginEvent> */ public array $events = [];
    public array $users = [];
    public array $blocked = [];
    public array $unblocked = [];
    public ?int $explodeFor = null;

    public function szoLogins(DateTimeImmutable $since, ?int $onlyUserId = null): array
    {
        return array_values(array_filter($this->events, fn(LoginEvent $e) => $e->at >= $since && ($onlyUserId === null || $e->userId === $onlyUserId)));
    }
    public function linkedByLogtoId(): array
    {
        $o = [];
        foreach ($this->users as $u) if ($u['logto_id'] !== '') $o[$u['logto_id']] = ['id' => $u['id'], 'email' => $u['email'], 'name' => $u['name'], 'role' => $u['role']];
        return $o;
    }
    public function user(int $id): ?array
    {
        if ($id === $this->explodeFor) throw new \RuntimeException('awaria testowa');
        return $this->users[$id] ?? null;
    }
    public function admins(): array { return [['email' => 'admin@feer.test', 'name' => 'Admin']]; }
    public function block(int $userId): void { $this->blocked[] = $userId; }
    public function unblock(int $userId): void { $this->unblocked[] = $userId; }
}

final class FakeLogto implements LogtoGatewayInterface
{
    public array $rows = [];
    public array $suspended = [];
    public function signIns(DateTimeImmutable $since, array $logKeys): array { return array_values(array_filter($this->rows, fn($r) => $r['at'] >= $since)); }
    public function setSuspended(string $logtoUserId, bool $suspended): void { $this->suspended[] = [$logtoUserId, $suspended]; }
}

final class FakeMailer implements MailerInterface
{
    public array $sent = [];
    public function send(string $toEmail, string $toName, string $subject, string $html, string $text, string $context): int
    {
        $this->sent[] = compact('toEmail', 'subject', 'html', 'text');
        return count($this->sent);
    }
}

$fails = 0;
$ok = 0;
function check(bool $cond, string $what): void
{
    global $fails, $ok;
    if ($cond) { $ok++; echo "  ok   $what\n"; } else { $fails++; echo "  FAIL $what\n"; }
}

const WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
const CURL = 'curl/8.4.0';

$now = new DateTimeImmutable('2026-11-15 12:00:00', new DateTimeZone('UTC'));
$ago = fn(string $spec) => $now->modify($spec);
$seq = 0;
$ev = function (int $uid, string $email, string $ip, string $ua, bool $ok, DateTimeImmutable $at) use (&$seq) {
    return new LoginEvent('szo', (string)++$seq, $uid, '', $email, $ip, $ua, $ok, $at);
};

function fresh(): void
{
    LoginSentryDatabase::useConnection(new \PDO('sqlite::memory:'));
    LoginSentryConfig::override(['mode' => 'live']);
}

echo "IpTools\n";
check(IpTools::inCidr('83.1.2.50', '83.1.2.0/24'), 'IPv4 w /24');
check(!IpTools::inCidr('83.1.3.50', '83.1.2.0/24'), 'IPv4 poza /24');
check(IpTools::inCidr('2a01:110:8012:1010::1', '2a01:110::/32'), 'IPv6 w /32');
check(IpTools::network('2a01:110:8012:1010::1') === '2a01:110:8012::/48', 'sieć IPv6 /48');
check(IpTools::usable('104.16.1.1') === 'cloudflare', 'adres Cloudflare rozpoznany');
check(IpTools::usable('192.168.1.10') === 'private', 'adres prywatny rozpoznany');
check(IpTools::uaFamily(WIN) === 'Chrome / Windows' && IpTools::uaFamily(MAC) === 'Safari / macOS', 'rodziny przeglądarek');
check(IpTools::validCidr('203.0.113.0/24') && !IpTools::validCidr('10.0.0.0/4') && !IpTools::validCidr('abc'), 'walidacja CIDR');

echo "Silnik\n";
fresh();
$dir = new FakeDirectory();
$logto = new FakeLogto();
$mail = new FakeMailer();
$dir->users = [
    1 => ['id' => 1, 'email' => 'anna@feer.test', 'name' => 'Anna', 'role' => 'viewer', 'is_active' => 1, 'logto_id' => 'lt-anna'],
    2 => ['id' => 2, 'email' => 'bartek@feer.test', 'name' => 'Bartek', 'role' => 'viewer', 'is_active' => 1, 'logto_id' => 'lt-bartek'],
    3 => ['id' => 3, 'email' => 'celina@feer.test', 'name' => 'Celina', 'role' => 'editor', 'is_active' => 1, 'logto_id' => ''],
    4 => ['id' => 4, 'email' => 'darek@feer.test', 'name' => 'Darek', 'role' => 'admin', 'is_active' => 1, 'logto_id' => 'lt-darek'],
    5 => ['id' => 5, 'email' => 'ewa@feer.test', 'name' => 'Ewa', 'role' => 'viewer', 'is_active' => 1, 'logto_id' => ''],
    6 => ['id' => 6, 'email' => 'franek@feer.test', 'name' => 'Franek', 'role' => 'viewer', 'is_active' => 1, 'logto_id' => ''],
];
// Historia (wzorzec): każdy loguje się z sieci domowej przez Chrome na Windows.
foreach ([1 => '83.1.2.', 2 => '89.64.10.', 3 => '31.0.5.', 4 => '79.184.7.', 5 => '91.200.1.', 6 => '46.205.3.'] as $uid => $pfx) {
    for ($d = 2; $d <= 6; $d++) $dir->events[] = $ev($uid, $dir->users[$uid]['email'], $pfx . (10 + $d), WIN, true, $ago("-$d days"));
}
// Okno: Anna zwyczajnie; Bartek po 6 błędnych hasłach z obcego VPS przez curl; Celina z nowej sieci na Macu;
// Darek (admin) jak Bartek; Ewa wywoła awarię; Franek przez Cloudflare.
$dir->events[] = $ev(1, 'anna@feer.test', '83.1.2.77', WIN, true, $ago('-2 hours'));
for ($i = 0; $i < 6; $i++) $dir->events[] = $ev(2, 'bartek@feer.test', '45.9.9.9', CURL, false, $ago('-3 hours')->modify("+$i minutes"));
$dir->events[] = $ev(2, 'bartek@feer.test', '45.9.9.9', CURL, true, $ago('-3 hours')->modify('+7 minutes'));
$dir->events[] = $ev(3, 'celina@feer.test', '5.173.40.2', MAC, true, $ago('-1 hour'));
for ($i = 0; $i < 6; $i++) $dir->events[] = $ev(4, 'darek@feer.test', '45.9.9.10', CURL, false, $ago('-4 hours')->modify("+$i minutes"));
$dir->events[] = $ev(4, 'darek@feer.test', '45.9.9.10', CURL, true, $ago('-4 hours')->modify('+7 minutes'));
$dir->events[] = $ev(5, 'ewa@feer.test', '91.200.1.99', WIN, true, $ago('-1 hour'));
$dir->events[] = $ev(6, 'franek@feer.test', '104.16.5.5', WIN, true, $ago('-1 hour'));
$dir->explodeFor = 5;
// Logto: Anna loguje się też przez Logto z tej samej sieci.
$logto->rows[] = ['id' => 'lg1', 'user_id' => 'lt-anna', 'ip' => '83.1.2.78', 'ua' => WIN, 'success' => true, 'at' => $ago('-90 minutes')];
$logto->rows[] = ['id' => 'lg2', 'user_id' => 'lt-nieznany', 'ip' => '1.2.3.4', 'ua' => WIN, 'success' => true, 'at' => $ago('-90 minutes')];

$engine = new LoginSentryEngine($dir, $logto, $mail, new NullGeoLookup(), $now);

// Najpierw próbnie: nic nie może się zmienić.
$dry = $engine->run('test', true);
$pdo = LoginSentryDatabase::pdo();
check($dry['dry'] === true && $dry['counts']['suspend'] === 1 && $dry['counts']['warn'] === 2, 'dry-run: 1 blokada, 2 ostrzeżenia w raporcie');
check((int)$pdo->query('SELECT COUNT(*) FROM ls_incidents')->fetchColumn() === 0, 'dry-run: brak zdarzeń w bazie');
check((int)$pdo->query('SELECT COUNT(*) FROM ls_known')->fetchColumn() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM ls_logins')->fetchColumn() === 0, 'dry-run: brak wzorców i logowań w bazie');
check(!$mail->sent && !$dir->blocked && !$logto->suspended, 'dry-run: bez maili i blokad');

$r = $engine->run('test');
$c = $r['counts'];
$by = [];
foreach ($r['items'] as $it) $by[$it['user_id']] = $it;
check($c['baseline'] === 30, 'wzorzec z 30 logowań historii');
check(($by[1]['action'] ?? '') === 'clean' && $by[1]['score'] === 0, 'Anna: zwykłe logowanie, 0 pkt (SZO + Logto)');
check(($by[2]['action'] ?? '') === 'suspend' && $by[2]['score'] === 105, 'Bartek: blokada, 105 pkt');
check(in_array(['lt-bartek', true], $logto->suspended, true) && in_array(2, $dir->blocked, true), 'Bartek: zawieszony w Logto i zablokowany w SZO');
check(($by[3]['action'] ?? '') === 'warn' && $by[3]['score'] === 40, 'Celina: nowa sieć + przeglądarka = ostrzeżenie 40 pkt');
check(!in_array(3, $dir->blocked, true), 'Celina: bez blokady');
check(($by[4]['action'] ?? '') === 'warn' && !in_array(4, $dir->blocked, true), 'Darek (admin): tylko ostrzeżenie');
check(($by[5]['action'] ?? '') === 'error' && $c['errors'] === 1, 'Ewa: błąd zapisany, pętla idzie dalej');
check(($by[6]['action'] ?? '') === 'clean' && $c['cloudflare_ip'] === 1, 'Franek: adres Cloudflare pominięty przy ocenie sieci');
check($c['unlinked'] === 1, 'Logto: logowanie konta bez powiązania z SZO policzone osobno');
check(count($mail->sent) === 3, '3 maile do administratora (Bartek, Celina, Darek)');
check((int)$pdo->query("SELECT COUNT(*) FROM ls_audit WHERE level = 'ERROR' AND event = 'user_failed'")->fetchColumn() === 1, 'błąd Ewy w dzienniku jako ERROR');
$bm = array_values(array_filter($mail->sent, fn($m) => str_contains($m['subject'], 'bartek')))[0] ?? null;
check($bm !== null && str_contains($bm['text'], '6 błędnych haseł') && str_contains($bm['text'], 'Ziemowit Gil') && str_contains($bm['html'], '<ul'), 'mail o blokadzie: powody, podpis, lista HTML');

// Ponowny przebieg tego samego dnia: bez nowych maili i blokad.
$mailsBefore = count($mail->sent);
$suspBefore = count($logto->suspended);
$r2 = $engine->run('test');
check(count($mail->sent) === $mailsBefore && count($logto->suspended) === $suspBefore, 'powtórny przebieg: bez dubli');
check($r2['counts']['already_seen'] >= 5 && $r2['counts']['logins'] === 1, 'powtórny przebieg: ocenione pominięte, ponawia tylko logowanie Ewy (wcześniej błąd)');

// Wymuszone sprawdzenie Bartka: zdarzenie z dziś już obsłużone.
$r3 = $engine->run('test', false, 2);
check(($r3['items'][0]['action'] ?? '') === 'repeat' && count($mail->sent) === $mailsBefore, '--force-id: ponowna ocena bez dubla');

// Celina loguje się znowu, tym razem z serią błędnych haseł: eskalacja ostrzeżenia do blokady.
for ($i = 0; $i < 5; $i++) $dir->events[] = $ev(3, 'celina@feer.test', '5.173.40.2', MAC, false, $ago('-20 minutes')->modify("+$i minutes"));
$dir->events[] = $ev(3, 'celina@feer.test', '5.173.40.2', MAC, true, $ago('-10 minutes'));
$r4 = $engine->run('test');
check($r4['counts']['escalated'] === 1 && in_array(3, $dir->blocked, true), 'Celina: ostrzeżenie urosło do blokady');
$esc = end($mail->sent);
check(str_contains($esc['subject'], 'drugie podejrzane'), 'mail o eskalacji ma swój temat');

// Cofnięcie blokady Bartka: odwieszenie, odblokowanie, sieć zaufana.
$inc = (int)$pdo->query('SELECT id FROM ls_incidents WHERE user_id = 2')->fetchColumn();
$engine->revert($inc, 'admin@feer.test');
check(in_array(['lt-bartek', false], $logto->suspended, true) && in_array(2, $dir->unblocked, true), 'cofnięcie: konto wraca w Logto i SZO');
check((string)$pdo->query("SELECT status FROM ls_incidents WHERE id = $inc")->fetchColumn() === 'reverted', 'cofnięcie: stan reverted');
check((int)$pdo->query("SELECT trusted FROM ls_known WHERE user_id = 2 AND kind = 'net' AND value = '45.9.9.0/24'")->fetchColumn() === 1, 'cofnięcie: sieć zapamiętana jako zaufana');
$threw = false;
try { $engine->revert($inc, 'admin@feer.test'); } catch (\RuntimeException $e) { $threw = true; }
check($threw, 'drugie cofnięcie tego samego zdarzenia jest odrzucone');

// Wyjątek sieci: zaufane biuro daje 0 pkt.
$pdo->prepare('INSERT INTO ls_net_exemptions (cidr, reason) VALUES (?, ?)')->execute(['198.51.100.0/24', 'biuro']);
$dir->events[] = $ev(6, 'franek@feer.test', '198.51.100.20', CURL, true, $ago('-5 minutes'));
$r5 = $engine->run('test');
$f = array_values(array_filter($r5['items'], fn($i) => $i['user_id'] === 6))[0] ?? null;
check($f !== null && $f['score'] === 0 && str_contains($f['note'], 'zaufana sieć'), 'zaufana sieć: 0 pkt mimo curl');

// Limit blokad na przebieg = 0: tylko ostrzeżenie.
fresh();
LoginSentryConfig::override(['mode' => 'live', 'max_suspend_per_run' => 0]);
$dir->blocked = [];
$e2 = new LoginSentryEngine($dir, $logto, new FakeMailer(), new NullGeoLookup(), $now);
$r6 = $e2->run('test');
check($r6['counts']['limited'] >= 1 && !$dir->blocked, 'limit blokad: bez blokad, tylko powiadomienie');

// Tryb off: nic.
LoginSentryConfig::override(['mode' => 'off']);
check($e2->run('test')['mode'] === 'off', 'tryb off: przebieg nic nie robi');

echo "Teksty\n";
$all = LoginSentryTemplates::ANNOUNCE_BODY . LoginSentryTemplates::SUSPEND_BODY . LoginSentryTemplates::WARN_BODY . LoginSentryTemplates::ESCALATE_INTRO;
check(!preg_match('/[—–]/u', $all), 'bez myślników (humanizer)');
check(str_contains(LoginSentryTemplates::ANNOUNCE_BODY, 'https://feer.org.pl/uslugi-it') && str_contains(LoginSentryTemplates::ANNOUNCE_BODY, 'Mietek, Bronek i Gosposia')
      && str_contains(LoginSentryTemplates::ANNOUNCE_BODY, 'Cloudflare'), 'ogłoszenie: link, Mietek/Bronek/Gosposia, Cloudflare');
check(str_contains(LoginSentryTemplates::ANNOUNCE_BODY, "Ziemowit Gil\nZespół IT Fundacji FEER"), 'ogłoszenie: podpis');
$h = LoginSentryTemplates::html("Test <b>x</b>\n\nLista:\n- a & b\n- c");
check(str_contains($h, '&lt;b&gt;') && str_contains($h, '<li>a &amp; b</li>'), 'HTML escapowany, listy');

@array_map('unlink', glob(LOGIN_SENTRY_STORAGE_DIR . '/*') ?: []);
@array_map('unlink', glob(LOGIN_SENTRY_STORAGE_DIR . '/.htaccess') ?: []);
@rmdir(LOGIN_SENTRY_STORAGE_DIR);
echo "\n$ok ok, $fails błędów\n";
exit($fails ? 1 : 0);
