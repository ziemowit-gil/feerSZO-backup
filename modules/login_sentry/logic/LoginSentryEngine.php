<?php
declare(strict_types=1);

namespace Feer\Szo\LoginSentry;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

require_once dirname(__DIR__) . '/lib/IpTools.php';
require_once dirname(__DIR__) . '/lib/LoginSentryConfig.php';
require_once dirname(__DIR__) . '/lib/LoginSentryDatabase.php';
require_once dirname(__DIR__) . '/lib/LoginSentryTemplates.php';
require_once dirname(__DIR__) . '/lib/Gateways.php';
require_once dirname(__DIR__) . '/lib/SzoDirectory.php';

/**
 * modules/login_sentry/logic/LoginSentryEngine.php — LoginSentry („Staszek”): co 6 godzin ocenia adresy IP logowań
 * do SZO i do Logto, a przy podejrzanym logowaniu zawiesza konto i pisze do administratora.
 *
 * Przebieg:
 *   1. Czyta logowania z ostatnich window_hours (domyślnie 7 h): login_log SZO + dziennik Logto (/api/logs).
 *      Logowanie już ocenione (ls_logins, UNIQUE source+source_id) pomija, chyba że wymuszono sprawdzenie osoby.
 *   2. Każde udane logowanie dostaje punkty (LoginSentryConfig::DEFAULTS['points']):
 *        sieć z listy podejrzanych, seria nieudanych prób tuż przed sukcesem (zgadywanie hasła), kraj spoza listy (z GeoIP),
 *        nowa sieć (/24, /48) i nowa przeglądarka względem wzorca osoby, klient-skrypt (curl, python…), noc 1–5.
 *      Dla osoby w całym oknie: wiele różnych sieci naraz, zmiana kraju w ciągu 2 h.
 *      Wynik osoby = najwyższy wynik logowania + premie z okna. Zaufane sieci (wyjątki) dają 0.
 *   3. Wynik ≥ warn_score → e-mail do administratora. Wynik ≥ suspend_score → zawieszenie w Logto (PATCH is-suspended),
 *      blokada w SZO (is_active = 0, sesje usunięte) i e-mail do administratora.
 *   4. Logowania bez podejrzeń uczą wzorzec osoby (ls_known). Podejrzane nie uczą, dopóki administrator nie kliknie
 *      „To była ta osoba” (cofnięcie blokady albo odrzucenie ostrzeżenia), wtedy sieci i przeglądarka stają się zaufane.
 * Bezpieczniki: jedno zdarzenie na osobę na dzień (UNIQUE user_id+day, więc powtórny przebieg nie dubluje maili ani blokad;
 * ostrzeżenie może tylko urosnąć do blokady); konta administratorów SZO domyślnie tylko e-mail; limit blokad na przebieg;
 * adresy Cloudflare i prywatne nie liczą się jako sieć; błąd jednej osoby zapisany jako ERROR nie przerywa pętli;
 * blokada pliku przed równoległym przebiegiem; tryb próbny (--dry-run / preview) niczego nie zapisuje ani nie wysyła.
 * Pierwszy przebieg buduje wzorce z historii SZO (baseline_days), bez oceniania tej historii.
 */
final class LoginSentryEngine
{
    private DateTimeImmutable $now;
    private bool $dry = true;
    private array $cfg = [];
    private array $report = [];
    private array $counts = [];
    /** @var array<int, array<string, array<string, array{hits:int,trusted:int}>>> */
    private array $known = [];
    private int $suspendsLeft = 0;

    public function __construct(
        private DirectoryInterface $szo,
        private ?LogtoGatewayInterface $logto,
        private MailerInterface $mailer,
        private GeoLookupInterface $geo,
        ?DateTimeImmutable $now = null,
    ) {
        $this->now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
    }

    public static function fromSzo(): self
    {
        $cfg = LoginSentryConfig::all();
        $logto = null;
        if (!empty($cfg['logto_enabled'])) {
            try { $logto = LogtoManagementGateway::fromSzo(); } catch (\Throwable $e) { $logto = null; }
        }
        return new self(new SzoDirectory(), $logto, new SzoMailer(), MmdbGeoLookup::tryCreate((string)$cfg['geoip_mmdb']));
    }

    // ── Przebieg ─────────────────────────────────────────────────────────────────────────

    /**
     * @param bool     $dry          true = nic nie zapisuje i nie wysyła (CLI --dry-run, przycisk „Podgląd”)
     * @param int|null $forceUserId  sprawdź tę osobę jeszcze raz, także logowania już ocenione
     * @return array{mode:string, dry:bool, counts:array, items:list<array>, error?:string}
     */
    public function run(string $trigger = 'cron', bool $dry = false, ?int $forceUserId = null): array
    {
        $this->cfg = LoginSentryConfig::all();
        $mode = LoginSentryConfig::mode();
        if ($mode === 'off' && !$dry && $forceUserId === null) return ['mode' => 'off', 'dry' => false, 'counts' => [], 'items' => []];
        $this->dry = $dry || $mode !== 'live';
        $this->report = [];
        $this->counts = ['logins' => 0, 'failures' => 0, 'already_seen' => 0, 'unlinked' => 0, 'unusable_ip' => 0, 'cloudflare_ip' => 0,
                         'users' => 0, 'exempt' => 0, 'clean' => 0, 'warn' => 0, 'suspend' => 0, 'escalated' => 0, 'repeat_today' => 0,
                         'limited' => 0, 'admin_protected' => 0, 'mails' => 0, 'errors' => 0, 'baseline' => 0];
        $this->suspendsLeft = max(0, (int)$this->cfg['max_suspend_per_run']);

        $pdo = LoginSentryDatabase::pdo();   // zakłada też katalog storage
        $lock = @fopen(LoginSentryDatabase::storageDir() . '/login_sentry.lock', 'c');
        if (!$lock) return ['mode' => $mode, 'dry' => $this->dry, 'counts' => $this->counts, 'items' => [], 'error' => 'Nie mogę otworzyć pliku blokady.'];
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (\is_resource($lock)) fclose($lock);
            return ['mode' => $mode, 'dry' => $this->dry, 'counts' => $this->counts, 'items' => [], 'error' => 'Poprzedni przebieg jeszcze trwa.'];
        }
        $runId = null;
        if (!$this->dry) {
            $pdo->prepare('INSERT INTO ls_runs (started_at, mode, trigger) VALUES (?, ?, ?)')->execute([$this->fmt($this->now), $mode, $trigger]);
            $runId = (int)$pdo->lastInsertId();
        }
        $error = null;
        try {
            $this->process($forceUserId);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $this->counts['errors']++;
            if (!$this->dry) LoginSentryDatabase::audit(null, null, 'run_failed', ['error' => $error], 'staszek', 'ERROR');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $summary = ['counts' => $this->counts, 'items' => array_slice($this->report, 0, 500)] + ($error ? ['error' => $error] : []);
        if ($runId) {
            $pdo->prepare('UPDATE ls_runs SET finished_at = ?, summary = ? WHERE id = ?')
                ->execute([$this->fmt(new DateTimeImmutable('now', new DateTimeZone('UTC'))), json_encode($summary, JSON_UNESCAPED_UNICODE), $runId]);
        } elseif ($trigger === 'panel') {
            LoginSentryDatabase::setSetting('last_preview', json_encode($summary + ['at' => $this->fmt($this->now)], JSON_UNESCAPED_UNICODE));
        }
        return ['mode' => $mode, 'dry' => $this->dry] + $summary;
    }

    private function process(?int $forceUserId): void
    {
        $pdo = LoginSentryDatabase::pdo();
        $this->loadKnown();
        $windowStart = $this->now->sub(new DateInterval('PT' . max(1, (int)$this->cfg['window_hours']) . 'H'));
        $failStart = $windowStart->sub(new DateInterval('PT' . max(1, (int)$this->cfg['fail_window_min']) . 'M'));

        if (LoginSentryDatabase::setting('baseline_built') !== '1') $this->buildBaseline($windowStart);

        // 1. Logowania z SZO i z Logto.
        $events = $this->szo->szoLogins($failStart, $forceUserId);
        $linked = $this->szo->linkedByLogtoId();
        if ($this->logto === null) {
            $this->counts['logto_unavailable'] = 1;
            $this->report[] = ['user_id' => 0, 'email' => '', 'action' => 'info', 'score' => 0,
                               'note' => 'Logto niedostępne albo nieskonfigurowane: oceniam tylko logowania do SZO'];
        } else {
            foreach ($this->logto->signIns($failStart, (array)$this->cfg['logto_log_keys']) as $r) {
                $u = $linked[$r['user_id']] ?? null;
                if ($u === null) { if ($r['success']) $this->counts['unlinked']++; continue; }
                if ($forceUserId !== null && $u['id'] !== $forceUserId) continue;
                $events[] = new LoginEvent('logto', $r['id'], $u['id'], $r['user_id'], $u['email'], $r['ip'], $r['ua'], $r['success'], $r['at']);
            }
        }
        usort($events, fn(LoginEvent $a, LoginEvent $b) => $a->at <=> $b->at);

        $seen = [];
        $st = $pdo->prepare('SELECT source || \':\' || source_id FROM ls_logins WHERE occurred_at >= ?');
        $st->execute([$this->fmt($failStart)]);
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $k) $seen[$k] = true;

        $failures = [];
        $byUser = [];
        foreach ($events as $e) {
            if (!$e->success) { $failures[] = $e; $this->counts['failures']++; continue; }
            if ($e->at < $windowStart || $e->userId === null) continue;
            if (isset($seen[$e->source . ':' . $e->sourceId]) && $forceUserId === null) { $this->counts['already_seen']++; continue; }
            $this->counts['logins']++;
            $byUser[$e->userId][] = $e;
        }
        if ($forceUserId !== null && !isset($byUser[$forceUserId])) {
            $u = $this->szo->user($forceUserId);
            $this->report[] = ['user_id' => $forceUserId, 'email' => (string)($u['email'] ?? ''), 'action' => 'clean', 'score' => 0,
                               'note' => $u ? 'Brak logowań w oknie ' . (int)$this->cfg['window_hours'] . ' h' : 'Nie ma takiego konta w SZO'];
        }

        $exempt = $this->exemptUsers();
        foreach ($byUser as $uid => $list) {
            $this->counts['users']++;
            try {
                $this->evaluateUser((int)$uid, $list, $failures, isset($exempt[$uid]), $linked);
            } catch (\Throwable $e) {
                $this->counts['errors']++;
                $this->report[] = ['user_id' => $uid, 'email' => $list[0]->email, 'action' => 'error', 'score' => 0, 'note' => $e->getMessage()];
                if (!$this->dry) LoginSentryDatabase::audit(null, (int)$uid, 'user_failed', ['error' => $e->getMessage()], 'staszek', 'ERROR');
                error_log('[login_sentry] ERROR user ' . $uid . ': ' . $e->getMessage());
            }
        }
    }

    /** @param list<LoginEvent> $list  @param list<LoginEvent> $failures */
    private function evaluateUser(int $uid, array $list, array $failures, bool $exempt, array $linked): void
    {
        $user = $this->szo->user($uid);
        if ($user === null) throw new \RuntimeException('Nie ma konta SZO #' . $uid);
        if ($user['logto_id'] === '') {
            foreach ($list as $e) if ($e->logtoId !== '') { $user['logto_id'] = $e->logtoId; break; }
        }
        $p = (array)$this->cfg['points'];
        $trustedCidrs = $this->trustedCidrs();
        $blocked = (array)$this->cfg['blocked_cidrs'];
        $allowed = array_map('strtoupper', (array)$this->cfg['allowed_countries']);
        $mature = $this->knownHits($uid, 'net') >= (int)$this->cfg['baseline_min_logins'];

        $best = 0;
        $reasons = [];
        $ips = [];
        $nets = [];
        $countries = [];
        $evaluated = [];
        foreach ($list as $e) {
            $score = 0;
            $why = [];
            $state = IpTools::usable($e->ip);
            if ($state !== 'ok') {
                $this->counts[$state === 'cloudflare' ? 'cloudflare_ip' : 'unusable_ip']++;
            }
            $trusted = $state === 'ok' ? IpTools::inAny($e->ip, $trustedCidrs) : null;
            $net = $state === 'ok' ? IpTools::network($e->ip) : '';
            $country = $state === 'ok' ? $this->geo->country($e->ip) : '';
            $ua = IpTools::uaFamily($e->userAgent);
            if ($trusted !== null) {
                $why['trusted_net'] = $trusted;
            } else {
                if ($state === 'ok' && ($hit = IpTools::inAny($e->ip, $blocked)) !== null) { $score += (int)$p['blocked_net']; $why['blocked_net'] = $hit; }
                $fails = $this->failuresBefore($failures, $uid, strtolower($user['email']), $e->at);
                if ($fails >= (int)$this->cfg['fail_threshold']) { $score += (int)$p['fail_burst']; $why['fail_burst'] = $fails; }
                if ($country !== '' && $allowed && !in_array($country, $allowed, true)) { $score += (int)$p['foreign_country']; $why['foreign_country'] = $country; }
                if ($mature && $net !== '' && !$this->isKnown($uid, 'net', $net)) { $score += (int)$p['new_network']; $why['new_network'] = $net; }
                if ($mature && $ua !== '' && !$this->isKnown($uid, 'ua', $ua)) { $score += (int)$p['new_browser']; $why['new_browser'] = $ua; }
                if (str_starts_with($ua, 'Skrypt')) { $score += (int)$p['script_client']; $why['script_client'] = $ua; }
                $h = (int)$e->at->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('G');
                if ($h >= (int)$this->cfg['night_from'] && $h < (int)$this->cfg['night_to']) { $score += (int)$p['night']; $why['night'] = $this->local($e->at); }
                if ($net !== '') $nets[$net] = true;
                if ($country !== '') $countries[] = [$country, $e->at];
            }
            if ($state !== 'ok') $why['ip_' . $state] = $e->ip;
            if ($e->ip !== '') $ips[$e->ip] = true;
            $best = max($best, $score);
            foreach ($why as $k => $v) $reasons[$k] ??= $v;
            $evaluated[] = ['e' => $e, 'net' => $net, 'state' => $state, 'ua' => $ua, 'country' => $country, 'score' => $score, 'why' => $why];
        }

        // Premie z całego okna.
        $bonus = 0;
        if (count($nets) >= (int)$this->cfg['many_networks']) { $bonus += (int)$p['many_networks']; $reasons['many_networks'] = count($nets); }
        if ($this->countryHop($countries)) { $bonus += (int)$p['country_hop']; $reasons['country_hop'] = implode(' → ', array_unique(array_column($countries, 0))); }
        $score = $best + $bonus;

        $level = $score >= (int)$this->cfg['suspend_score'] ? 'suspend' : ($score >= (int)$this->cfg['warn_score'] ? 'warn' : '');
        $note = '';
        if ($exempt) {
            $this->counts['exempt']++;
            $note = 'Wyjątek: tylko zapis, bez działań';
            $level = '';
        }
        if ($level === 'suspend' && $user['role'] === 'admin' && empty($this->cfg['suspend_admins'])) {
            $level = 'warn';
            $reasons['admin_protected'] = true;
            $this->counts['admin_protected']++;
        }

        $incidentId = null;
        if ($level === '') {
            if (!$exempt) $this->counts['clean']++;
            $this->item($user, $exempt ? 'exempt' : 'clean', $score, $note ?: $this->shortReasons($reasons));
        } else {
            $incidentId = $this->handleIncident($user, $level, $score, $reasons, array_keys($ips), array_keys($nets));
        }

        // Zapis logowań i nauka wzorca (tylko gdy nic podejrzanego).
        $learn = $level === '' && $incidentId === null;
        if (!$this->dry) {
            $ins = LoginSentryDatabase::pdo()->prepare('INSERT INTO ls_logins (source, source_id, user_id, email, ip, network, ip_state, ua_family, country, occurred_at, score, reasons, incident_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(source, source_id) DO UPDATE SET score = excluded.score, reasons = excluded.reasons,
                    incident_id = COALESCE(excluded.incident_id, ls_logins.incident_id), processed_at = strftime(\'%Y-%m-%d %H:%M:%S\',\'now\')');
            foreach ($evaluated as $x) {
                $e = $x['e'];
                $ins->execute([$e->source, $e->sourceId, $uid, $e->email, $e->ip, $x['net'], $x['state'], $x['ua'], $x['country'],
                               $this->fmt($e->at), $x['score'], json_encode($x['why'], JSON_UNESCAPED_UNICODE), $incidentId]);
            }
        }
        if ($learn) {
            foreach ($evaluated as $x) {
                if ($x['net'] !== '') $this->remember($uid, 'net', $x['net'], $x['e']->at);
                if ($x['ua'] !== '') $this->remember($uid, 'ua', $x['ua'], $x['e']->at);
                if ($x['country'] !== '') $this->remember($uid, 'country', $x['country'], $x['e']->at);
            }
        }
    }

    /** Zdarzenie dnia: nowe, eskalacja ostrzeżenia do blokady albo powtórka (bez maili). */
    private function handleIncident(array $user, string $level, int $score, array $reasons, array $ips, array $nets): ?int
    {
        $pdo = LoginSentryDatabase::pdo();
        $day = $this->now->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
        $st = $pdo->prepare('SELECT * FROM ls_incidents WHERE user_id = ? AND day = ?');
        $st->execute([$user['id'], $day]);
        $existing = $st->fetch() ?: null;

        if ($existing && in_array($existing['status'], ['reverted', 'dismissed'], true)) {
            $this->counts['repeat_today']++;
            $this->item($user, 'repeat', $score, 'Administrator już dziś uznał konto za bezpieczne, bez działań');
            return (int)$existing['id'];
        }
        $escalate = $existing && $existing['level'] === 'warn' && $level === 'suspend';
        $retry = $existing && $existing['status'] === 'suspend_failed';
        if ($existing && !$escalate && !$retry) {
            $this->counts['repeat_today']++;
            $this->item($user, 'repeat', $score, 'Zdarzenie z dziś już obsłużone (' . $existing['status'] . ')');
            return (int)$existing['id'];
        }
        if ($level === 'suspend' && !$retry && $this->suspendsLeft <= 0) {
            $level = 'warn';
            $reasons['suspend_limit'] = (int)$this->cfg['max_suspend_per_run'];
            $this->counts['limited']++;
            if ($existing) { $this->item($user, 'repeat', $score, 'Limit blokad na przebieg'); return (int)$existing['id']; }
        }

        $this->counts[$level]++;
        if ($escalate) $this->counts['escalated']++;
        if ($level === 'suspend') $this->suspendsLeft--;
        $label = $level === 'suspend' ? 'suspend' : 'warn';
        if ($this->dry) {
            $this->item($user, $label, $score, ($escalate ? 'Eskalacja: ' : '') . $this->shortReasons($reasons));
            return null;
        }

        $enc = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($existing) {
            $id = (int)$existing['id'];
            $merged = array_replace(json_decode((string)$existing['reasons'], true) ?: [], $reasons);
            $pdo->prepare("UPDATE ls_incidents SET level = ?, score = MAX(score, ?), reasons = ?, ips = ?, networks = ?, updated_at = strftime('%Y-%m-%d %H:%M:%S','now') WHERE id = ?")
                ->execute([$level, $score, $enc($merged), $enc(array_values(array_unique([...json_decode((string)$existing['ips'], true) ?: [], ...$ips]))),
                           $enc(array_values(array_unique([...json_decode((string)$existing['networks'], true) ?: [], ...$nets]))), $id]);
            $reasons = $merged;
        } else {
            $pdo->prepare('INSERT INTO ls_incidents (user_id, day, logto_id, email, name, score, level, status, reasons, ips, networks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$user['id'], $day, $user['logto_id'], $user['email'], $user['name'], $score, $level, 'notified', $enc($reasons), $enc($ips), $enc($nets)]);
            $id = (int)$pdo->lastInsertId();
        }
        LoginSentryDatabase::audit($id, $user['id'], $escalate ? 'escalated' : ($retry ? 'suspend_retry' : 'incident_' . $level), ['score' => $score, 'reasons' => $reasons, 'ips' => $ips], 'staszek', 'WARN');

        $outcome = ['logto' => null, 'szo' => null, 'errors' => []];
        if ($level === 'suspend') $outcome = $this->suspend($id, $user);
        // Przy ponowieniu nieudanej blokady piszemy tylko, gdy tym razem się udało.
        if (!$retry || $outcome['logto'] || $outcome['szo']) $this->notifyAdmins($id, $user, $level, $score, $reasons, $ips, $outcome, $escalate);
        $this->item($user, $label, $score, ($escalate ? 'Eskalacja: ' : '') . $this->shortReasons($reasons) . ($outcome['errors'] ? ' | błędy: ' . implode('; ', $outcome['errors']) : ''));
        return $id;
    }

    /** @return array{logto:?bool, szo:?bool, errors:list<string>} */
    private function suspend(int $incidentId, array $user): array
    {
        $out = ['logto' => null, 'szo' => null, 'errors' => []];
        if ($user['logto_id'] !== '' && $this->logto !== null) {
            try { $this->logto->setSuspended($user['logto_id'], true); $out['logto'] = true; }
            catch (\Throwable $e) { $out['logto'] = false; $out['errors'][] = 'Logto: ' . $e->getMessage(); }
        }
        try { $this->szo->block($user['id']); $out['szo'] = true; }
        catch (\Throwable $e) { $out['szo'] = false; $out['errors'][] = 'SZO: ' . $e->getMessage(); }
        $ok = $out['logto'] || $out['szo'];
        LoginSentryDatabase::pdo()->prepare("UPDATE ls_incidents SET status = ?, suspended_at = ?, suspended_logto = ?, suspended_szo = ?, last_error = ?,
                updated_at = strftime('%Y-%m-%d %H:%M:%S','now') WHERE id = ?")
            ->execute([$ok ? 'suspended' : 'suspend_failed', $ok ? $this->fmt($this->now) : null, (int)(bool)$out['logto'], (int)(bool)$out['szo'], implode('; ', $out['errors']), $incidentId]);
        LoginSentryDatabase::audit($incidentId, $user['id'], $ok ? 'suspended' : 'suspend_failed', $out, 'staszek', $ok ? 'WARN' : 'ERROR');
        return $out;
    }

    private function notifyAdmins(int $incidentId, array $user, string $level, int $score, array $reasons, array $ips, array $outcome, bool $escalate): void
    {
        $vars = [
            'name' => $user['name'] ?: $user['email'], 'email' => $user['email'], 'score' => $score,
            'reasons' => LoginSentryTemplates::reasonLines($reasons), 'ips' => implode(', ', $ips) ?: 'brak',
            'when' => $this->local($this->now), 'panel_url' => (string)$this->cfg['panel_url'],
            'outcome' => LoginSentryTemplates::outcomeLines($outcome),
        ];
        $mail = LoginSentryTemplates::adminAlert($level, $vars, $escalate);
        $sent = 0;
        $lastId = null;
        foreach ($this->recipients() as $r) {
            try {
                $mid = $this->mailer->send($r['email'], $r['name'], $mail['subject'], $mail['html'], $mail['text'], 'login_sentry');
                if ($mid > 0) { $sent++; $lastId = $mid; }
            } catch (\Throwable $e) {
                LoginSentryDatabase::audit($incidentId, $user['id'], 'mail_failed', ['to' => $r['email'], 'error' => $e->getMessage()], 'staszek', 'ERROR');
            }
        }
        $this->counts['mails'] += $sent;
        LoginSentryDatabase::pdo()->prepare("UPDATE ls_incidents SET notified_at = ?, mail_id = ? WHERE id = ?")->execute([$this->fmt($this->now), $lastId, $incidentId]);
        LoginSentryDatabase::audit($incidentId, $user['id'], 'admin_notified', ['sent' => $sent]);
    }

    // ── Akcje z panelu ───────────────────────────────────────────────────────────────────

    /** „Cofnij”: odwieszenie w Logto, odblokowanie w SZO, sieci i przeglądarki z tego zdarzenia stają się zaufane. */
    public function revert(int $incidentId, string $actor): void
    {
        $pdo = LoginSentryDatabase::pdo();
        $st = $pdo->prepare('SELECT * FROM ls_incidents WHERE id = ?');
        $st->execute([$incidentId]);
        $inc = $st->fetch();
        if (!$inc) throw new \RuntimeException('Nie ma takiego zdarzenia.');
        if (in_array($inc['status'], ['reverted', 'dismissed'], true)) throw new \RuntimeException('To zdarzenie jest już zamknięte.');
        $uid = (int)$inc['user_id'];
        $errors = [];
        if ((int)$inc['suspended_logto'] === 1 && $inc['logto_id'] !== '' && $this->logto !== null) {
            try { $this->logto->setSuspended((string)$inc['logto_id'], false); } catch (\Throwable $e) { $errors[] = 'Logto: ' . $e->getMessage(); }
        }
        if ((int)$inc['suspended_szo'] === 1) {
            try { $this->szo->unblock($uid); } catch (\Throwable $e) { $errors[] = 'SZO: ' . $e->getMessage(); }
        }
        if ($errors) {
            LoginSentryDatabase::audit($incidentId, $uid, 'revert_failed', ['errors' => $errors], $actor, 'ERROR');
            throw new \RuntimeException(implode('; ', $errors));
        }
        $status = $inc['status'] === 'notified' ? 'dismissed' : 'reverted';
        $pdo->prepare("UPDATE ls_incidents SET status = ?, reverted_at = ?, reverted_by = ?, updated_at = strftime('%Y-%m-%d %H:%M:%S','now') WHERE id = ?")
            ->execute([$status, $this->fmt($this->now), $actor, $incidentId]);
        $this->trustIncident($incidentId, $uid);
        LoginSentryDatabase::audit($incidentId, $uid, $status, ['by' => $actor], $actor);
    }

    private function trustIncident(int $incidentId, int $uid): void
    {
        $st = LoginSentryDatabase::pdo()->prepare('SELECT network, ua_family, country, occurred_at FROM ls_logins WHERE incident_id = ?');
        $st->execute([$incidentId]);
        $this->loadKnown();
        foreach ($st->fetchAll() as $r) {
            $at = SzoDirectory::utc((string)$r['occurred_at']) ?? $this->now;
            foreach (['net' => 'network', 'ua' => 'ua_family', 'country' => 'country'] as $kind => $col) {
                if ((string)$r[$col] !== '') $this->remember($uid, $kind, (string)$r[$col], $at, true);
            }
        }
    }

    /** Statystyki do --stats i kart w panelu. */
    public static function stats(): array
    {
        $pdo = LoginSentryDatabase::pdo();
        $q = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchColumn(); })();
        $d1 = gmdate('Y-m-d H:i:s', time() - 86400);
        $d30 = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $byStatus = [];
        $s = $pdo->prepare('SELECT status, COUNT(*) AS n FROM ls_incidents WHERE created_at >= ? GROUP BY status');
        $s->execute([$d30]);
        foreach ($s->fetchAll() as $r) $byStatus[$r['status']] = (int)$r['n'];
        $last = $pdo->query('SELECT * FROM ls_runs ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        return [
            'mode' => LoginSentryConfig::mode(),
            'logins_24h' => (int)$q('SELECT COUNT(*) FROM ls_logins WHERE occurred_at >= ?', [$d1]),
            'flagged_24h' => (int)$q('SELECT COUNT(*) FROM ls_logins WHERE occurred_at >= ? AND score > 0', [$d1]),
            'cloudflare_24h' => (int)$q("SELECT COUNT(*) FROM ls_logins WHERE occurred_at >= ? AND ip_state = 'cloudflare'", [$d1]),
            'incidents_30d' => array_sum($byStatus),
            'incidents_by_status_30d' => $byStatus,
            'suspended_open' => (int)$q("SELECT COUNT(*) FROM ls_incidents WHERE status = 'suspended'"),
            'known_patterns' => (int)$q('SELECT COUNT(*) FROM ls_known'),
            'users_with_pattern' => (int)$q('SELECT COUNT(DISTINCT user_id) FROM ls_known'),
            'exempt_users' => (int)$q('SELECT COUNT(*) FROM ls_user_exemptions'),
            'trusted_networks' => (int)$q('SELECT COUNT(*) FROM ls_net_exemptions'),
            'last_run' => $last ? ['started_at' => $last['started_at'], 'mode' => $last['mode'], 'trigger' => $last['trigger'],
                                   'counts' => json_decode((string)$last['summary'], true)['counts'] ?? []] : null,
        ];
    }

    // ── Wzorzec (ls_known) ───────────────────────────────────────────────────────────────

    private function buildBaseline(DateTimeImmutable $windowStart): void
    {
        $since = $this->now->sub(new DateInterval('P' . max(1, (int)$this->cfg['baseline_days']) . 'D'));
        foreach ($this->szo->szoLogins($since) as $e) {
            if (!$e->success || $e->userId === null || $e->at >= $windowStart) continue;
            if (IpTools::usable($e->ip) === 'ok') $this->remember($e->userId, 'net', IpTools::network($e->ip), $e->at);
            $ua = IpTools::uaFamily($e->userAgent);
            if ($ua !== '') $this->remember($e->userId, 'ua', $ua, $e->at);
            $this->counts['baseline']++;
        }
        if (!$this->dry) {
            LoginSentryDatabase::setSetting('baseline_built', '1');
            LoginSentryDatabase::audit(null, null, 'baseline_built', ['logins' => $this->counts['baseline'], 'days' => (int)$this->cfg['baseline_days']]);
        }
    }

    private function loadKnown(): void
    {
        $this->known = [];
        foreach (LoginSentryDatabase::pdo()->query('SELECT user_id, kind, value, hits, trusted FROM ls_known')->fetchAll() as $r) {
            $this->known[(int)$r['user_id']][$r['kind']][$r['value']] = ['hits' => (int)$r['hits'], 'trusted' => (int)$r['trusted']];
        }
    }

    private function isKnown(int $uid, string $kind, string $value): bool
    {
        return isset($this->known[$uid][$kind][$value]);
    }

    private function knownHits(int $uid, string $kind): int
    {
        return array_sum(array_column($this->known[$uid][$kind] ?? [], 'hits'));
    }

    /** Zapamiętuje w pamięci zawsze (żeby tryb próbny liczył tak samo), w bazie tylko poza trybem próbnym. */
    private function remember(int $uid, string $kind, string $value, DateTimeImmutable $at, bool $trusted = false): void
    {
        $cur = $this->known[$uid][$kind][$value] ?? ['hits' => 0, 'trusted' => 0];
        $this->known[$uid][$kind][$value] = ['hits' => $cur['hits'] + 1, 'trusted' => max($cur['trusted'], (int)$trusted)];
        if ($this->dry && !$trusted) return;
        LoginSentryDatabase::pdo()->prepare('INSERT INTO ls_known (user_id, kind, value, first_seen, last_seen, hits, trusted) VALUES (?, ?, ?, ?, ?, 1, ?)
            ON CONFLICT(user_id, kind, value) DO UPDATE SET hits = hits + 1, last_seen = MAX(last_seen, excluded.last_seen), trusted = MAX(trusted, excluded.trusted)')
            ->execute([$uid, $kind, $value, $this->fmt($at), $this->fmt($at), (int)$trusted]);
    }

    // ── Pomocnicze ───────────────────────────────────────────────────────────────────────

    /** @param list<LoginEvent> $failures */
    private function failuresBefore(array $failures, int $uid, string $email, DateTimeImmutable $at): int
    {
        $from = $at->sub(new DateInterval('PT' . max(1, (int)$this->cfg['fail_window_min']) . 'M'));
        $n = 0;
        foreach ($failures as $f) {
            if ($f->at < $from || $f->at > $at) continue;
            if ($f->userId === $uid || ($email !== '' && $f->email === $email)) $n++;
        }
        return $n;
    }

    /** @param list<array{0:string,1:DateTimeImmutable}> $countries */
    private function countryHop(array $countries): bool
    {
        for ($i = 1; $i < count($countries); $i++) {
            [$c, $t] = $countries[$i];
            [$pc, $pt] = $countries[$i - 1];
            if ($c !== $pc && $t->getTimestamp() - $pt->getTimestamp() <= 7200) return true;
        }
        return false;
    }

    private function trustedCidrs(): array
    {
        $db = LoginSentryDatabase::pdo()->query('SELECT cidr FROM ls_net_exemptions')->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_unique([...(array)$this->cfg['trusted_cidrs'], ...$db]));
    }

    private function exemptUsers(): array
    {
        $st = LoginSentryDatabase::pdo()->prepare('SELECT user_id FROM ls_user_exemptions WHERE expires_at IS NULL OR expires_at > ?');
        $st->execute([$this->fmt($this->now)]);
        return array_flip(array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** @return list<array{email:string,name:string}> */
    private function recipients(): array
    {
        $cfg = array_filter(array_map('trim', (array)$this->cfg['admin_emails']), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($cfg) return array_map(fn($e) => ['email' => $e, 'name' => ''], array_values($cfg));
        return $this->szo->admins();
    }

    private function shortReasons(array $reasons): string
    {
        $parts = [];
        foreach ($reasons as $k => $v) $parts[] = LoginSentryTemplates::reasonLabel($k, $v);
        return $parts ? implode('; ', $parts) : 'bez uwag';
    }

    private function item(array $u, string $action, int $score, string $note): void
    {
        $this->report[] = ['user_id' => $u['id'], 'email' => $u['email'], 'action' => $action, 'score' => $score, 'note' => $note];
    }

    private function fmt(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function local(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('d.m.Y H:i');
    }
}
