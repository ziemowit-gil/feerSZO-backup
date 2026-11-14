<?php
declare(strict_types=1);

namespace Feer\Szo\Warden;

require_once __DIR__ . '/WardenEngine.php';

/**
 * modules/warden/logic/WardenController.php — panel AccountWarden („Mietek”) dla administratora.
 * Trasy w modules/warden/router.php (tryb query). Każdy POST: CSRF sprawdza Router.
 */
final class WardenController
{
    private const VIEW = __DIR__ . '/../view/';
    private const K_FLASH = 'wardenFlash';

    public function index(): string
    {
        if (\function_exists('ika_require')) \ika_require();
        $pdo = WardenDatabase::pdo();
        $open = $pdo->query("SELECT c.*, u.name, u.effective_last_activity FROM warden_cycles c LEFT JOIN warden_users u ON u.user_id = c.user_id
                              WHERE c.status IN ('warning_1_sent','warning_2_sent','sms_sent') ORDER BY c.deadline_at")->fetchAll();
        $blocked = $pdo->query("SELECT c.*, u.name FROM warden_cycles c LEFT JOIN warden_users u ON u.user_id = c.user_id
                                 WHERE c.status = 'deactivated' ORDER BY c.deactivated_at DESC LIMIT 200")->fetchAll();
        $history = $pdo->query("SELECT a.*, u.name, u.email FROM warden_audit a LEFT JOIN warden_users u ON u.user_id = a.user_id
                                 ORDER BY a.id DESC LIMIT 50")->fetchAll();
        $exemptions = $pdo->query('SELECT * FROM warden_exemptions ORDER BY created_at DESC')->fetchAll();
        $szo = new SzoDirectory();
        $names = [];
        foreach ($szo->activeUsersBrief() as $u) $names[(int)$u['id']] = $u;
        foreach ($exemptions as &$e) {
            $b = $names[(int)$e['user_id']] ?? $szo->userBrief((int)$e['user_id']);
            $e['name'] = (string)($b['name'] ?? ('#' . $e['user_id']));
            $e['email'] = (string)($b['email'] ?? '');
        }
        unset($e);
        $lastRun = $pdo->query('SELECT * FROM warden_runs ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        $announced = (int)$pdo->query('SELECT COUNT(*) FROM warden_announcements')->fetchColumn();
        $preview = json_decode(WardenDatabase::setting('last_preview', ''), true);

        return \Router::view(self::VIEW . 'index.php', [
            'flash' => $this->take(),
            'mode' => WardenEngine::mode(),
            'liveFrom' => WardenDatabase::setting('live_from'),
            'cfg' => [
                'inactivity_days' => WardenEngine::inactivityDays(),
                'login_url' => WardenEngine::loginUrl(),
                'max_new_per_run' => WardenEngine::maxNewPerRun(),
            ],
            'open' => $open, 'blocked' => $blocked, 'history' => $history, 'exemptions' => $exemptions,
            'users' => array_values($names), 'lastRun' => $lastRun, 'preview' => is_array($preview) ? $preview : null,
            'announced' => $announced, 'announceTargets' => count($szo->activeUsersForAnnouncement()),
            'logtoReady' => $this->logtoReady(),
        ]);
    }

    public function saveSettings(): ?string
    {
        $days = (int)($_POST['inactivity_days'] ?? 30);
        $max = (int)($_POST['max_new_per_run'] ?? 30);
        $url = trim((string)($_POST['login_url'] ?? ''));
        if ($days < 14 || $days > 365) return $this->flash('danger', 'Próg nieaktywności musi mieć od 14 do 365 dni.');
        if ($max < 1 || $max > 1000) return $this->flash('danger', 'Limit nowych cykli musi mieć od 1 do 1000.');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https://~', $url)) return $this->flash('danger', 'Adres logowania musi zaczynać się od https://.');
        WardenDatabase::setSetting('inactivity_days', (string)$days);
        WardenDatabase::setSetting('max_new_per_run', (string)$max);
        WardenDatabase::setSetting('login_url', $url);
        WardenDatabase::audit(null, null, 'settings_saved', ['inactivity_days' => $days, 'max_new_per_run' => $max, 'login_url' => $url], $this->actor());
        return $this->flash('success', 'Zapisano ustawienia.');
    }

    /** Podgląd: co zrobiłby przebieg teraz (nic nie wysyła, nic nie zmienia). */
    public function preview(): ?string
    {
        try {
            $r = WardenEngine::fromSzo()->run('panel', 'preview');
        } catch (\Throwable $e) {
            return $this->flash('danger', 'Podgląd nieudany: ' . $e->getMessage());
        }
        if (!empty($r['error'])) return $this->flash('danger', 'Podgląd przerwany: ' . $r['error']);
        return $this->flash('info', 'Podgląd gotowy: sprawdzono ' . (int)$r['counts']['checked'] . ' kont, nowych ostrzeżeń byłoby ' . (int)$r['counts']['started'] . '.');
    }

    /** „Uruchom AccountWarden”: tryb na żywo od jutra + ogłoszenie „Poznajcie Mietka” do wszystkich aktywnych kont. */
    public function launch(): ?string
    {
        if (!$this->logtoReady()) return $this->flash('danger', 'Najpierw skonfiguruj połączenie z Logto (Ustawienia logowania jednokrotnego).');
        if (($_POST['confirm'] ?? '') !== '1') return $this->flash('danger', 'Zaznacz potwierdzenie wysyłki ogłoszenia.');
        $from = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');
        WardenDatabase::setSetting('mode', 'live');
        WardenDatabase::setSetting('live_from', $from);
        $n = WardenEngine::fromSzo()->announce($this->actor());
        WardenDatabase::audit(null, null, 'launched', ['live_from' => $from, 'announced' => $n], $this->actor());
        return $this->flash('success', 'AccountWarden uruchomiony. Ogłoszenie trafiło do kolejki dla ' . $n . ' osób. Pierwsze sprawdzenie kont odbędzie się najwcześniej za dobę.');
    }

    public function stop(): ?string
    {
        WardenDatabase::setSetting('mode', 'off');
        WardenDatabase::audit(null, null, 'stopped', [], $this->actor());
        return $this->flash('success', 'AccountWarden zatrzymany. Otwarte cykle czekają, aż włączysz go ponownie.');
    }

    public function resume(): ?string
    {
        if (!$this->logtoReady()) return $this->flash('danger', 'Najpierw skonfiguruj połączenie z Logto.');
        WardenDatabase::setSetting('mode', 'live');
        WardenDatabase::audit(null, null, 'resumed', [], $this->actor());
        return $this->flash('success', 'AccountWarden znów działa (bez ponownego ogłoszenia).');
    }

    public function runNow(): ?string
    {
        if (WardenEngine::mode() !== 'live') return $this->flash('danger', 'AccountWarden jest zatrzymany.');
        try {
            $r = WardenEngine::fromSzo()->run('panel');
        } catch (\Throwable $e) {
            return $this->flash('danger', 'Przebieg nieudany: ' . $e->getMessage());
        }
        if (!empty($r['error'])) return $this->flash('danger', 'Przebieg przerwany: ' . $r['error']);
        if ($r['mode'] === 'waiting') return $this->flash('info', 'Sprawdzanie kont zacznie się ' . $this->localTime(WardenDatabase::setting('live_from')) . '.');
        $c = $r['counts'];
        return $this->flash('success', sprintf('Przebieg zakończony: sprawdzono %d, nowe ostrzeżenia %d, drugie e-maile %d, SMS %d, blokady %d, zalogowali się %d, błędy %d.',
            $c['checked'], $c['started'], $c['warning_2'], $c['sms'], $c['deactivated'], $c['resolved'], $c['errors']));
    }

    public function addExemption(): ?string
    {
        $uid = (int)($_POST['user_id'] ?? 0);
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200);
        if ($uid <= 0 || !(new SzoDirectory())->userBrief($uid)) return $this->flash('danger', 'Wybierz osobę z listy.', '#wyjatki');
        if ($reason === '') return $this->flash('danger', 'Podaj powód wyjątku, np. konto serwisowe albo zarząd.', '#wyjatki');
        WardenDatabase::pdo()->prepare('INSERT INTO warden_exemptions (user_id, reason, created_by) VALUES (?, ?, ?)
            ON CONFLICT(user_id) DO UPDATE SET reason = excluded.reason')->execute([$uid, $reason, $this->actor()]);
        WardenDatabase::audit(null, $uid, 'exemption_added', ['reason' => $reason], $this->actor());
        return $this->flash('success', 'Dodano wyjątek. Otwarty cykl tej osoby zamknie się przy najbliższym przebiegu.', '#wyjatki');
    }

    public function removeExemption(): ?string
    {
        $uid = (int)($_POST['user_id'] ?? 0);
        WardenDatabase::pdo()->prepare('DELETE FROM warden_exemptions WHERE user_id = ?')->execute([$uid]);
        WardenDatabase::audit(null, $uid, 'exemption_removed', [], $this->actor());
        return $this->flash('success', 'Usunięto wyjątek.', '#wyjatki');
    }

    public function reactivate(): ?string
    {
        $uid = (int)($_POST['user_id'] ?? 0);
        try {
            WardenEngine::fromSzo()->reactivate($uid, $this->actor());
        } catch (\Throwable $e) {
            return $this->flash('danger', 'Nie udało się odblokować: ' . $e->getMessage(), '#zablokowane');
        }
        return $this->flash('success', 'Konto odblokowane w Logto i w SZO. Liczenie nieaktywności zaczyna się od dziś.', '#zablokowane');
    }

    // ── Pomocnicze ───────────────────────────────────────────────────────────────────────

    private function logtoReady(): bool
    {
        require_once dirname(__DIR__, 2) . '/sso_logto/lib/SsoConfig.php';
        return \SsoConfig::logtoConfigured();
    }

    private function actor(): string
    {
        $u = \current_user();
        return (string)($u['email'] ?? 'admin');
    }

    private function localTime(string $utc): string
    {
        $d = SzoDirectory::utc($utc);
        return $d ? $d->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('d.m.Y H:i') : '';
    }

    private function take(): ?array
    {
        $v = $_SESSION[self::K_FLASH] ?? null;
        unset($_SESSION[self::K_FLASH]);
        return is_array($v) ? $v : null;
    }

    private function flash(string $type, string $msg, string $anchor = ''): ?string
    {
        $_SESSION[self::K_FLASH] = ['type' => $type, 'msg' => $msg];
        return \Router::redirect(route('warden.index') . $anchor);
    }
}

