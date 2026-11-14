<?php
declare(strict_types=1);

namespace Feer\Szo\Warden;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

require_once dirname(__DIR__) . '/lib/WardenDatabase.php';
require_once dirname(__DIR__) . '/lib/WardenTemplates.php';
require_once dirname(__DIR__) . '/lib/Gateways.php';
require_once dirname(__DIR__) . '/lib/SzoDirectory.php';

/**
 * modules/warden/logic/WardenEngine.php — maszyna stanów strażnika nieaktywnych kont (Logto + SZO).
 *
 * effective_last_activity = max(ostatnie logowanie w SZO, lastSignInAt z Logto, activity_floor_at), a gdy wszystkie puste:
 * users.created_at. activity_floor_at ustawia odblokowanie konta (w panelu albo ręcznie w SZO), żeby świeżo odblokowana
 * osoba nie dostała od razu nowego cyklu.
 *
 * Cykl (dni od cycle_started_at, deadline_at = start + 7 dni):
 *   brak aktywności > próg (domyślnie 60 dni)  → warning_1_sent + e-mail #1
 *   dzień 4                                       → warning_2_sent + e-mail #2
 *   dzień 6, jest numer telefonu                  → sms_sent + SMS (bez numeru: sms_skipped, status bez zmian)
 *   po deadline_at                                → zawieszenie w Logto (PATCH is-suspended) + blokada w SZO → deactivated
 *   logowanie po starcie cyklu (SZO albo Logto)    → resolved_by_login, źródło i resolved_at; dalszych wiadomości nie ma.
 * Zabezpieczenia: jeden krok na cykl na przebieg; e-mail #2 wysłany po terminie (np. cron nie działał) przesuwa termin
 * na co najmniej +3 dni, SMS na co najmniej +1 dzień; blokada tylko po e-mailu #2; błąd Logto przy odczycie pomija osobę
 * (nigdy nie uznajemy jej za nieaktywną), błąd zawieszenia zostawia cykl otwarty do ponowienia; limit nowych cykli na przebieg;
 * blokada pliku przed równoległym przebiegiem. Tryb „preview” niczego nie zapisuje ani nie wysyła, tylko raportuje plan.
 */
final class WardenEngine
{
    public const GRACE_DAYS = 7;
    public const WARN2_DAY = 4;
    public const SMS_DAY = 6;
    public const MODES = ['off', 'preview', 'live'];

    private DateTimeImmutable $now;
    private bool $dry = true;
    private array $report = [];
    private array $counts = [];

    public function __construct(
        private LogtoGatewayInterface $logto,
        private MailerInterface $mailer,
        private SmsGatewayInterface $sms,
        private SzoDirectory $szo,
        ?DateTimeImmutable $now = null,
    ) {
        $this->now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
    }

    public static function fromSzo(): self
    {
        return new self(LogtoManagementGateway::fromSzo(), new SzoMailer(), new SzoSmsGateway(), new SzoDirectory());
    }

    // ── Ustawienia ───────────────────────────────────────────────────────────────────────

    public static function mode(): string
    {
        $m = WardenDatabase::setting('mode', 'off');
        return in_array($m, self::MODES, true) ? $m : 'off';
    }

    public static function inactivityDays(): int
    {
        $d = (int)WardenDatabase::setting('inactivity_days', '60');
        return $d >= 14 && $d <= 365 ? $d : 60;
    }

    public static function maxNewPerRun(): int
    {
        $n = (int)WardenDatabase::setting('max_new_per_run', '30');
        return $n >= 1 && $n <= 1000 ? $n : 30;
    }

    public static function loginUrl(): string
    {
        $u = WardenDatabase::setting('login_url', 'https://szo.feer.org.pl');
        return filter_var($u, FILTER_VALIDATE_URL) ? $u : 'https://szo.feer.org.pl';
    }

    // ── Przebieg ─────────────────────────────────────────────────────────────────────────

    /**
     * @param string|null $mode null = z ustawień; 'preview' = sam podgląd (panel)
     * @return array{mode:string, counts:array, items:list<array>, error?:string}
     */
    public function run(string $trigger = 'cron', ?string $mode = null): array
    {
        $mode ??= self::mode();
        if ($mode === 'off') return ['mode' => 'off', 'counts' => [], 'items' => []];
        // Po „Uruchom AccountWarden” ludzie najpierw dostają ogłoszenie; sprawdzanie kont rusza dobę później.
        $liveFrom = SzoDirectory::utc(WardenDatabase::setting('live_from'));
        if ($mode === 'live' && $liveFrom !== null && $this->now < $liveFrom) return ['mode' => 'waiting', 'counts' => [], 'items' => []];
        $this->dry = $mode !== 'live';
        $this->report = [];
        $this->counts = ['checked' => 0, 'exempt' => 0, 'started' => 0, 'warning_2' => 0, 'sms' => 0, 'sms_skipped' => 0,
                         'deactivated' => 0, 'resolved' => 0, 'cancelled' => 0, 'errors' => 0, 'limit_skipped' => 0, 'suspended_skipped' => 0];

        $lock = fopen(WardenDatabase::storageDir() . '/warden.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['mode' => $mode, 'counts' => $this->counts, 'items' => [], 'error' => 'Poprzedni przebieg jeszcze trwa.'];
        }
        $pdo = WardenDatabase::pdo();
        $runId = null;
        if (!$this->dry) {
            $pdo->prepare('INSERT INTO warden_runs (started_at, mode, trigger) VALUES (?, ?, ?)')->execute([$this->fmt($this->now), $mode, $trigger]);
            $runId = (int)$pdo->lastInsertId();
        }
        $error = null;
        try {
            $this->process();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $this->counts['errors']++;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $summary = ['counts' => $this->counts, 'items' => array_slice($this->report, 0, 500)] + ($error ? ['error' => $error] : []);
        if ($runId) {
            $pdo->prepare("UPDATE warden_runs SET finished_at = ?, summary = ? WHERE id = ?")
                ->execute([$this->fmt(new DateTimeImmutable('now', new DateTimeZone('UTC'))), json_encode($summary, JSON_UNESCAPED_UNICODE), $runId]);
        } else {
            WardenDatabase::setSetting('last_preview', json_encode($summary + ['at' => $this->fmt($this->now)], JSON_UNESCAPED_UNICODE));
        }
        return ['mode' => $mode] + $summary;
    }

    private function process(): void
    {
        $pdo = WardenDatabase::pdo();
        $users = $this->szo->activeLinkedUsers();
        $byId = [];
        foreach ($users as $u) $byId[$u['id']] = $u;
        $exempt = array_flip(array_map('intval', $pdo->query('SELECT user_id FROM warden_exemptions')->fetchAll(\PDO::FETCH_COLUMN)));
        $open = [];
        $st = $pdo->query("SELECT * FROM warden_cycles WHERE status IN ('warning_1_sent','warning_2_sent','sms_sent')");
        foreach ($st->fetchAll() as $c) $open[(int)$c['user_id']] = $c;

        // Otwarte cykle osób, które wypadły z listy (konto wyłączone inaczej, brak powiązania z Logto) albo dostały wyjątek.
        foreach ($open as $uid => $c) {
            if (!isset($byId[$uid]) || isset($exempt[$uid])) {
                $this->closeCycle($c, 'cancelled', isset($exempt[$uid]) ? 'wyjątek' : 'konto nieaktywne albo bez Logto');
                unset($open[$uid]);
            }
        }

        $szoLast = $this->szo->lastLogins(array_keys($byId));
        $stored = [];
        foreach ($pdo->query('SELECT * FROM warden_users')->fetchAll() as $r) $stored[(int)$r['user_id']] = $r;
        $threshold = $this->now->sub(new DateInterval('P' . self::inactivityDays() . 'D'));
        $newLeft = self::maxNewPerRun();

        foreach ($users as $u) {
            $uid = $u['id'];
            if (isset($exempt[$uid])) { $this->counts['exempt']++; continue; }
            $this->counts['checked']++;
            try {
                $lu = $this->logto->user($u['logto_id']);
            } catch (\Throwable $e) {
                $this->counts['errors']++;
                $this->item($u, 'error', 'Logto: ' . $e->getMessage());
                if (!$this->dry) $this->saveUser($u, $stored[$uid] ?? null, null, null, null, $e->getMessage());
                continue;
            }
            if ($lu === null) { $this->item($u, 'skip', 'Brak konta w Logto (404)'); continue; }

            $prev = $stored[$uid] ?? null;
            $szo = $this->max(SzoDirectory::utc($prev['szo_last_login_at'] ?? null), $szoLast[$uid] ?? null);
            $logto = $this->max(SzoDirectory::utc($prev['logto_last_login_at'] ?? null), $lu->lastSignInAt);
            $floor = SzoDirectory::utc($prev['activity_floor_at'] ?? null);
            // Konto odblokowane poza WARDEN po naszej blokadzie → od dziś liczymy od nowa.
            $last = isset($open[$uid]) ? null : $this->lastCycle($uid);
            if ($last && $last['status'] === 'deactivated') {
                $floor = $this->now;
                $this->item($u, 'reactivated', 'Konto odblokowane poza WARDEN, liczenie od nowa');
                if (!$this->dry) {
                    $this->update((int)$last['id'], ['status' => 'reactivated', 'resolved_at' => $this->fmt($this->now), 'resolved_source' => 'poza WARDEN']);
                    WardenDatabase::audit((int)$last['id'], $uid, 'reactivated_outside', ['email' => $u['email']]);
                }
            }
            $effective = $this->max($this->max($szo, $logto), $floor) ?? SzoDirectory::utc($u['created_at']) ?? $this->now;
            if (!$this->dry) $this->saveUser($u, $prev, $szo, $logto, $effective, '', $floor);

            if (isset($open[$uid])) {
                $this->advance($open[$uid], $u, $effective, $szo, $logto, $floor);
                continue;
            }
            if ($effective >= $threshold) continue;
            if ($lu->isSuspended) { $this->counts['suspended_skipped']++; $this->item($u, 'skip', 'Konto już zawieszone w Logto'); continue; }
            if ($newLeft <= 0) { $this->counts['limit_skipped']++; $this->item($u, 'limit', 'Limit nowych cykli na przebieg, następnym razem'); continue; }
            $newLeft--;
            $this->startCycle($u, $effective);
        }
    }

    private function startCycle(array $u, DateTimeImmutable $effective): void
    {
        $deadline = $this->now->add(new DateInterval('P' . self::GRACE_DAYS . 'D'));
        $this->counts['started']++;
        $this->item($u, 'warning_1', 'Brak aktywności od ' . $this->local($effective) . ', termin ' . $this->localDate($deadline));
        if ($this->dry) return;
        $pdo = WardenDatabase::pdo();
        $pdo->prepare("INSERT INTO warden_cycles (user_id, logto_id, email, status, cycle_started_at, deadline_at, last_activity_at_start, warning_1_sent_at)
                       VALUES (?, ?, ?, 'warning_1_sent', ?, ?, ?, ?)")
            ->execute([$u['id'], $u['logto_id'], $u['notify_email'], $this->fmt($this->now), $this->fmt($deadline), $this->fmt($effective), $this->fmt($this->now)]);
        $cid = (int)$pdo->lastInsertId();
        $mailId = $this->sendMail(1, $u, $deadline);
        WardenDatabase::audit($cid, $u['id'], 'warning_1_sent', ['email' => $u['notify_email'], 'mail_id' => $mailId, 'last_activity' => $this->fmt($effective)]);
    }

    private function advance(array $c, array $u, DateTimeImmutable $effective, ?DateTimeImmutable $szo, ?DateTimeImmutable $logto, ?DateTimeImmutable $floor): void
    {
        $start = SzoDirectory::utc($c['cycle_started_at']);
        $deadline = SzoDirectory::utc($c['deadline_at']);
        $cid = (int)$c['id'];

        // Ułaskawienie: logowanie po starcie cyklu.
        if ($effective > $start) {
            $source = match (true) {
                $logto !== null && $logto > $start && ($szo === null || $logto >= $szo) => 'Logto',
                $szo !== null && $szo > $start => 'SZO',
                $logto !== null && $logto > $start => 'Logto',
                default => 'admin',
            };
            $this->counts['resolved']++;
            $this->item($u, 'resolved', 'Logowanie (' . $source . ') ' . $this->local($effective));
            if ($this->dry) return;
            WardenDatabase::pdo()->prepare("UPDATE warden_cycles SET status = 'resolved_by_login', resolved_at = ?, resolved_source = ?, last_error = '', updated_at = ? WHERE id = ?")
                ->execute([$this->fmt($this->now), $source, $this->fmt($this->now), $cid]);
            WardenDatabase::audit($cid, $u['id'], 'resolved_by_login', ['source' => $source, 'at' => $this->fmt($effective)]);
            return;
        }

        $day = fn(int $d) => $start->add(new DateInterval('P' . $d . 'D'));
        $status = (string)$c['status'];

        if ($status === 'warning_1_sent') {
            if ($this->now < $day(self::WARN2_DAY) && $this->now < $deadline) return;
            $newDeadline = $this->max($deadline, $this->now->add(new DateInterval('P3D')));
            $this->counts['warning_2']++;
            $this->item($u, 'warning_2', 'Termin ' . $this->localDate($newDeadline));
            if ($this->dry) return;
            $this->update($cid, ['status' => 'warning_2_sent', 'warning_2_sent_at' => $this->fmt($this->now), 'deadline_at' => $this->fmt($newDeadline)]);
            $mailId = $this->sendMail(2, $u, $newDeadline);
            WardenDatabase::audit($cid, $u['id'], 'warning_2_sent', ['email' => $u['notify_email'], 'mail_id' => $mailId]);
            return;
        }

        $smsDue = $this->now >= $day(self::SMS_DAY) || $this->now >= $deadline->sub(new DateInterval('P1D'));
        if ($status === 'warning_2_sent' && (string)$c['sms_skipped'] === '' && $smsDue) {
            $phone = $u['phone'];
            if ($phone === '') {
                $this->counts['sms_skipped']++;
                $this->item($u, 'sms_skipped', 'Brak numeru telefonu');
                if ($this->dry) return;
                $this->update($cid, ['sms_skipped' => 'brak numeru']);
                WardenDatabase::audit($cid, $u['id'], 'sms_skipped', ['reason' => 'brak numeru']);
                return;
            }
            $newDeadline = $this->max($deadline, $this->now->add(new DateInterval('PT20H')));
            $this->counts['sms']++;
            $this->item($u, 'sms', 'SMS na ***' . substr(preg_replace('/\D/', '', $phone), -3));
            if ($this->dry) return;
            try {
                $text = AsciiSms::prepare(WardenTemplates::sms($this->vars($u, $newDeadline)));
                $provider = $this->sms->send($phone, $text);
                $this->update($cid, ['status' => 'sms_sent', 'sms_sent_at' => $this->fmt($this->now), 'deadline_at' => $this->fmt($newDeadline), 'last_error' => '']);
                WardenDatabase::audit($cid, $u['id'], 'sms_sent', ['provider' => $provider, 'length' => strlen($text)]);
            } catch (\Throwable $e) {
                // SMS się nie udał: nie blokujemy wiecznie, zapisujemy powód i idziemy dalej bez SMS-a.
                $this->counts['errors']++;
                $this->update($cid, ['sms_skipped' => 'błąd wysyłki', 'last_error' => mb_substr('SMS: ' . $e->getMessage(), 0, 500)]);
                WardenDatabase::audit($cid, $u['id'], 'sms_failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
            }
            return;
        }

        if ($this->now < $deadline) return;
        if ($status === 'warning_2_sent' && (string)$c['sms_skipped'] === '') return;   // SMS jeszcze przed nami

        $this->counts['deactivated']++;
        $this->item($u, 'deactivate', 'Zawieszenie w Logto i blokada w SZO');
        if ($this->dry) return;
        try {
            $this->logto->setSuspended($u['logto_id'], true);
        } catch (\Throwable $e) {
            $this->counts['errors']++;
            $this->update($cid, ['last_error' => mb_substr('Logto: ' . $e->getMessage(), 0, 500)]);
            WardenDatabase::audit($cid, $u['id'], 'deactivate_failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
            return;
        }
        $this->szo->block($u['id']);
        $this->update($cid, ['status' => 'deactivated', 'deactivated_at' => $this->fmt($this->now), 'last_error' => '']);
        WardenDatabase::audit($cid, $u['id'], 'deactivated', ['email' => $u['email'], 'logto_id' => $u['logto_id']]);
        if (\function_exists('audit_log')) {
            try { \audit_log('warden.deactivated', ['user_id' => $u['id'], 'email' => $u['email']], 0); } catch (\Throwable $e) {}
        }
    }

    // ── Akcje administratora ─────────────────────────────────────────────────────────────

    /** Odblokowanie: Logto isSuspended=false, SZO is_active=1, cykl „reactivated”, liczenie od nowa. */
    public function reactivate(int $userId, string $actor): void
    {
        $pdo = WardenDatabase::pdo();
        $st = $pdo->prepare("SELECT * FROM warden_cycles WHERE user_id = ? AND status = 'deactivated' ORDER BY id DESC LIMIT 1");
        $st->execute([$userId]);
        $c = $st->fetch();
        if (!$c) throw new \RuntimeException('Ta osoba nie ma konta zablokowanego przez WARDEN.');
        if ((string)$c['logto_id'] !== '') $this->logto->setSuspended((string)$c['logto_id'], false);
        $this->szo->unblock($userId);
        $now = $this->fmt($this->now);
        $this->update((int)$c['id'], ['status' => 'reactivated', 'resolved_at' => $now, 'resolved_source' => 'admin']);
        $pdo->prepare('UPDATE warden_users SET activity_floor_at = ? WHERE user_id = ?')->execute([$now, $userId]);
        WardenDatabase::audit((int)$c['id'], $userId, 'reactivated', [], $actor);
    }

    /** Ogłoszenie „Poznajcie Mietka” do wszystkich aktywnych kont (każdy raz). Zwraca liczbę dodanych do kolejki. */
    public function announce(string $actor): int
    {
        $pdo = WardenDatabase::pdo();
        $done = array_flip(array_map('intval', $pdo->query('SELECT user_id FROM warden_announcements')->fetchAll(\PDO::FETCH_COLUMN)));
        $msg = WardenTemplates::announcement(['inactivity_days' => self::inactivityDays()]);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO warden_announcements (user_id, email, mail_id) VALUES (?, ?, ?)');
        $n = 0;
        foreach ($this->szo->activeUsersForAnnouncement() as $u) {
            if (isset($done[$u['id']])) continue;
            $mailId = $this->mailer->send($u['notify_email'], $u['name'], $msg['subject'], $msg['html'], $msg['text'], 'warden_announce');
            $ins->execute([$u['id'], $u['notify_email'], $mailId ?: null]);
            $n++;
        }
        WardenDatabase::audit(null, null, 'announcement_sent', ['count' => $n], $actor);
        return $n;
    }

    // ── Pomocnicze ───────────────────────────────────────────────────────────────────────

    private function sendMail(int $stage, array $u, DateTimeImmutable $deadline): int
    {
        if (!SzoDirectory::deliverable($u['notify_email'])) return 0;
        $m = WardenTemplates::email($stage, $this->vars($u, $deadline));
        return $this->mailer->send($u['notify_email'], $u['name'], $m['subject'], $m['html'], $m['text'], 'warden_warning_' . $stage);
    }

    private function vars(array $u, DateTimeImmutable $deadline): array
    {
        $days = self::inactivityDays();
        return [
            'first_name' => $u['first_name'] !== '' ? $u['first_name'] : 'Cześć',
            'deadline_date' => $this->localDate($deadline),
            'login_url' => self::loginUrl(),
            'inactivity' => WardenTemplates::inactivityLabel($days),
            'inactivity_days' => (string)$days,
        ];
    }

    private function closeCycle(array $c, string $status, string $why): void
    {
        $this->counts['cancelled']++;
        $this->report[] = ['user_id' => (int)$c['user_id'], 'email' => (string)$c['email'], 'name' => '', 'action' => 'cancelled', 'note' => $why];
        if ($this->dry) return;
        $this->update((int)$c['id'], ['status' => $status, 'resolved_at' => $this->fmt($this->now), 'resolved_source' => $why]);
        WardenDatabase::audit((int)$c['id'], (int)$c['user_id'], 'cancelled', ['reason' => $why]);
    }

    private function update(int $cid, array $fields): void
    {
        $fields['updated_at'] = $this->fmt($this->now);
        $set = implode(', ', array_map(fn($k) => $k . ' = ?', array_keys($fields)));
        WardenDatabase::pdo()->prepare("UPDATE warden_cycles SET $set WHERE id = ?")->execute([...array_values($fields), $cid]);
    }

    private function saveUser(array $u, ?array $prev, ?DateTimeImmutable $szo, ?DateTimeImmutable $logto, ?DateTimeImmutable $eff, string $err, ?DateTimeImmutable $floor = null): void
    {
        $f = fn(?DateTimeImmutable $d, string $k) => $d ? $this->fmt($d) : ($prev[$k] ?? null);
        WardenDatabase::pdo()->prepare("INSERT INTO warden_users (user_id, logto_id, email, name, szo_last_login_at, logto_last_login_at, effective_last_activity, activity_floor_at, checked_at, last_error)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(user_id) DO UPDATE SET logto_id = excluded.logto_id, email = excluded.email, name = excluded.name,
              szo_last_login_at = excluded.szo_last_login_at, logto_last_login_at = excluded.logto_last_login_at,
              effective_last_activity = excluded.effective_last_activity, activity_floor_at = excluded.activity_floor_at,
              checked_at = excluded.checked_at, last_error = excluded.last_error")
            ->execute([$u['id'], $u['logto_id'], $u['email'], $u['name'], $f($szo, 'szo_last_login_at'), $f($logto, 'logto_last_login_at'),
                       $f($eff, 'effective_last_activity'), $f($floor, 'activity_floor_at'), $this->fmt($this->now), $err]);
    }

    private function lastCycle(int $uid): ?array
    {
        $st = WardenDatabase::pdo()->prepare('SELECT id, status FROM warden_cycles WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$uid]);
        $r = $st->fetch();
        return $r ?: null;
    }

    private function item(array $u, string $action, string $note): void
    {
        $this->report[] = ['user_id' => $u['id'], 'email' => $u['email'], 'name' => $u['name'], 'action' => $action, 'note' => $note];
    }

    private function max(?DateTimeImmutable $a, ?DateTimeImmutable $b): ?DateTimeImmutable
    {
        if ($a === null) return $b;
        if ($b === null) return $a;
        return $a > $b ? $a : $b;
    }

    private function fmt(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function local(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('d.m.Y H:i');
    }

    private function localDate(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('d.m.Y');
    }
}
