<?php
declare(strict_types=1);

namespace Feer\Szo\LoginSentry;

require_once __DIR__ . '/LoginSentryEngine.php';

/**
 * modules/login_sentry/logic/LoginSentryController.php — panel LoginSentry („Staszek”) dla administratora.
 * Trasy w modules/login_sentry/router.php (tryb query). Każdy POST: CSRF sprawdza Router (csrf_check(), pole _csrf).
 */
final class LoginSentryController
{
    private const VIEW = __DIR__ . '/../view/login_sentry_dashboard.php';
    private const K_FLASH = 'loginSentryFlash';

    public function index(): string
    {
        if (\function_exists('ika_require')) \ika_require();
        $pdo = LoginSentryDatabase::pdo();
        $incidents = $pdo->query('SELECT * FROM ls_incidents ORDER BY id DESC LIMIT 300')->fetchAll();
        $logins = $pdo->query('SELECT * FROM ls_logins ORDER BY occurred_at DESC LIMIT 200')->fetchAll();
        $audit = $pdo->query('SELECT a.*, i.email AS inc_email FROM ls_audit a LEFT JOIN ls_incidents i ON i.id = a.incident_id ORDER BY a.id DESC LIMIT 100')->fetchAll();
        $userEx = $pdo->query('SELECT * FROM ls_user_exemptions ORDER BY created_at DESC')->fetchAll();
        $netEx = $pdo->query('SELECT * FROM ls_net_exemptions ORDER BY created_at DESC')->fetchAll();
        $szo = new SzoDirectory();
        $users = $szo->activeUsersBrief();
        $names = [];
        foreach ($users as $u) $names[(int)$u['id']] = $u;
        foreach ($userEx as &$e) {
            $b = $names[(int)$e['user_id']] ?? $szo->user((int)$e['user_id']);
            $e['name'] = (string)($b['name'] ?? ('#' . $e['user_id']));
            $e['email'] = (string)($b['email'] ?? '');
        }
        unset($e);
        $preview = json_decode(LoginSentryDatabase::setting('last_preview', ''), true);

        return \Router::view(self::VIEW, [
            'flash' => $this->take(),
            'cfg' => LoginSentryConfig::all(),
            'stats' => LoginSentryEngine::stats(),
            'incidents' => $incidents, 'logins' => $logins, 'audit' => $audit,
            'userEx' => $userEx, 'netEx' => $netEx, 'users' => $users,
            'preview' => is_array($preview) ? $preview : null,
            'logtoReady' => $this->logtoReady(),
        ]);
    }

    public function saveSettings(): ?string
    {
        $mode = (string)($_POST['mode'] ?? 'preview');
        $warn = (int)($_POST['warn_score'] ?? 40);
        $susp = (int)($_POST['suspend_score'] ?? 70);
        $fails = (int)($_POST['fail_threshold'] ?? 5);
        $nets = (int)($_POST['many_networks'] ?? 3);
        $max = (int)($_POST['max_suspend_per_run'] ?? 5);
        $admins = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($_POST['admin_emails'] ?? '')) ?: [])));
        if (!in_array($mode, LoginSentryConfig::MODES, true)) return $this->flash('danger', 'Nieznany tryb.', '#ustawienia');
        if ($mode === 'live' && !$this->logtoReady()) return $this->flash('danger', 'Tryb na żywo wymaga połączenia z Logto (Ustawienia logowania jednokrotnego).', '#ustawienia');
        if ($warn < 10 || $warn > 200 || $susp <= $warn || $susp > 300) return $this->flash('danger', 'Próg blokady musi być wyższy niż próg ostrzeżenia (10–300 punktów).', '#ustawienia');
        if ($fails < 2 || $fails > 50) return $this->flash('danger', 'Liczba błędnych haseł: od 2 do 50.', '#ustawienia');
        if ($nets < 2 || $nets > 20) return $this->flash('danger', 'Liczba sieci: od 2 do 20.', '#ustawienia');
        if ($max < 0 || $max > 100) return $this->flash('danger', 'Limit blokad na przebieg: od 0 do 100.', '#ustawienia');
        foreach ($admins as $a) if (!filter_var($a, FILTER_VALIDATE_EMAIL)) return $this->flash('danger', 'To nie jest adres e-mail: ' . $a, '#ustawienia');
        $vals = ['mode' => $mode, 'warn_score' => $warn, 'suspend_score' => $susp, 'fail_threshold' => $fails, 'many_networks' => $nets,
                 'max_suspend_per_run' => $max, 'admin_emails' => $admins, 'suspend_admins' => !empty($_POST['suspend_admins'])];
        foreach ($vals as $k => $v) LoginSentryConfig::save($k, $v);
        LoginSentryDatabase::audit(null, null, 'settings_saved', $vals, $this->actor());
        return $this->flash('success', 'Zapisano ustawienia.', '#ustawienia');
    }

    /** Podgląd: co zrobiłby przebieg teraz (nic nie wysyła, nic nie zmienia). */
    public function preview(): ?string
    {
        try {
            $r = LoginSentryEngine::fromSzo()->run('panel', true);
        } catch (\Throwable $e) {
            return $this->flash('danger', 'Podgląd nieudany: ' . $e->getMessage());
        }
        if (!empty($r['error'])) return $this->flash('danger', 'Podgląd przerwany: ' . $r['error']);
        $c = $r['counts'];
        return $this->flash('info', sprintf('Podgląd gotowy: logowań %d, ostrzeżeń byłoby %d, blokad %d.', $c['logins'], $c['warn'], $c['suspend']), '#podglad');
    }

    public function runNow(): ?string
    {
        if (LoginSentryConfig::mode() === 'off') return $this->flash('danger', 'Staszek jest wyłączony. Włącz tryb podglądu albo na żywo w ustawieniach.');
        try {
            $r = LoginSentryEngine::fromSzo()->run('panel');
        } catch (\Throwable $e) {
            return $this->flash('danger', 'Przebieg nieudany: ' . $e->getMessage());
        }
        if (!empty($r['error'])) return $this->flash('danger', 'Przebieg przerwany: ' . $r['error']);
        $c = $r['counts'];
        return $this->flash('success', sprintf('Przebieg zakończony%s: logowań %d, ostrzeżenia %d, blokady %d, błędy %d.',
            $r['dry'] ? ' (tryb podglądu, bez działań)' : '', $c['logins'], $c['warn'], $c['suspend'], $c['errors']));
    }

    /** „Wymuś sprawdzenie” jednej osoby: także logowania już ocenione. W trybie innym niż live tylko raport. */
    public function check(): ?string
    {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid <= 0 || !(new SzoDirectory())->user($uid)) return $this->flash('danger', 'Wybierz osobę z listy.', '#sprawdz');
        try {
            $r = LoginSentryEngine::fromSzo()->run('panel-force', LoginSentryConfig::mode() !== 'live', $uid);
        } catch (\Throwable $e) {
            return $this->flash('danger', 'Sprawdzenie nieudane: ' . $e->getMessage(), '#sprawdz');
        }
        if (!empty($r['error'])) return $this->flash('danger', 'Sprawdzenie przerwane: ' . $r['error'], '#sprawdz');
        $notes = array_map(fn($i) => $i['note'] . ' (' . $i['score'] . ' pkt)', $r['items']);
        LoginSentryDatabase::audit(null, $uid, 'forced_check', ['items' => $r['items']], $this->actor());
        return $this->flash('info', 'Sprawdzono' . ($r['dry'] ? ' (bez działań)' : '') . ': ' . ($notes ? implode(' | ', $notes) : 'brak logowań w oknie.'), '#sprawdz');
    }

    /** „Cofnij”: odwieszenie konta (Logto + SZO) albo odrzucenie ostrzeżenia; sieci z tego zdarzenia stają się zaufane. */
    public function revert(): ?string
    {
        $id = (int)($_POST['incident_id'] ?? 0);
        try {
            LoginSentryEngine::fromSzo()->revert($id, $this->actor());
        } catch (\Throwable $e) {
            return $this->flash('danger', 'Nie udało się cofnąć: ' . $e->getMessage(), '#zdarzenia');
        }
        return $this->flash('success', 'Gotowe. Konto działa, a sieć i przeglądarka z tego logowania są zapamiętane jako zaufane.', '#zdarzenia');
    }

    public function addUserExemption(): ?string
    {
        $uid = (int)($_POST['user_id'] ?? 0);
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200);
        $days = (int)($_POST['days'] ?? 0);
        if ($uid <= 0 || !(new SzoDirectory())->user($uid)) return $this->flash('danger', 'Wybierz osobę z listy.', '#wyjatki');
        if ($reason === '') return $this->flash('danger', 'Podaj powód wyjątku, np. wyjazd służbowy albo konto serwisowe.', '#wyjatki');
        if ($days < 0 || $days > 365) return $this->flash('danger', 'Czas wyjątku: od 0 (bez końca) do 365 dni.', '#wyjatki');
        $exp = $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null;
        LoginSentryDatabase::pdo()->prepare('INSERT INTO ls_user_exemptions (user_id, reason, expires_at, created_by) VALUES (?, ?, ?, ?)
            ON CONFLICT(user_id) DO UPDATE SET reason = excluded.reason, expires_at = excluded.expires_at, created_by = excluded.created_by')
            ->execute([$uid, $reason, $exp, $this->actor()]);
        LoginSentryDatabase::audit(null, $uid, 'user_exemption_added', ['reason' => $reason, 'expires_at' => $exp], $this->actor());
        return $this->flash('success', 'Dodano wyjątek dla osoby.', '#wyjatki');
    }

    public function removeUserExemption(): ?string
    {
        $uid = (int)($_POST['user_id'] ?? 0);
        LoginSentryDatabase::pdo()->prepare('DELETE FROM ls_user_exemptions WHERE user_id = ?')->execute([$uid]);
        LoginSentryDatabase::audit(null, $uid, 'user_exemption_removed', [], $this->actor());
        return $this->flash('success', 'Usunięto wyjątek.', '#wyjatki');
    }

    public function addNetExemption(): ?string
    {
        $cidr = trim((string)($_POST['cidr'] ?? ''));
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200);
        if (!IpTools::validCidr($cidr)) return $this->flash('danger', 'Podaj adres IP albo zakres CIDR, np. 203.0.113.0/24 (IPv4 co najmniej /8, IPv6 co najmniej /16).', '#wyjatki');
        if ($reason === '') return $this->flash('danger', 'Podaj powód, np. biuro FEER albo VPN.', '#wyjatki');
        LoginSentryDatabase::pdo()->prepare('INSERT INTO ls_net_exemptions (cidr, reason, created_by) VALUES (?, ?, ?)
            ON CONFLICT(cidr) DO UPDATE SET reason = excluded.reason')->execute([$cidr, $reason, $this->actor()]);
        LoginSentryDatabase::audit(null, null, 'net_exemption_added', ['cidr' => $cidr, 'reason' => $reason], $this->actor());
        return $this->flash('success', 'Dodano zaufaną sieć ' . $cidr . '.', '#wyjatki');
    }

    public function removeNetExemption(): ?string
    {
        $id = (int)($_POST['id'] ?? 0);
        $st = LoginSentryDatabase::pdo()->prepare('SELECT cidr FROM ls_net_exemptions WHERE id = ?');
        $st->execute([$id]);
        $cidr = (string)$st->fetchColumn();
        LoginSentryDatabase::pdo()->prepare('DELETE FROM ls_net_exemptions WHERE id = ?')->execute([$id]);
        LoginSentryDatabase::audit(null, null, 'net_exemption_removed', ['cidr' => $cidr], $this->actor());
        return $this->flash('success', 'Usunięto zaufaną sieć.', '#wyjatki');
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

    private function take(): ?array
    {
        $v = $_SESSION[self::K_FLASH] ?? null;
        unset($_SESSION[self::K_FLASH]);
        return is_array($v) ? $v : null;
    }

    private function flash(string $type, string $msg, string $anchor = ''): ?string
    {
        $_SESSION[self::K_FLASH] = ['type' => $type, 'msg' => $msg];
        return \Router::redirect(route('login_sentry.index') . $anchor);
    }
}
