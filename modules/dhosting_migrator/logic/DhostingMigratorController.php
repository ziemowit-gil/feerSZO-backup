<?php
declare(strict_types=1);

/**
 * modules/dhosting_migrator/logic/DhostingMigratorController.php — ekrany migratora kont Microsoft 365 → dhosting.pl.
 *
 * Administrator (role:admin + ważna sesja IKA): GET / (lista kont, ustawienia), POST /zaloz, /ustawienia, /test.
 * Użytkownik (auth): GET/POST /moja-skrzynka — własna skrzynka, hasło SMS-em.
 * Wyniki i komunikaty idą przez sesję (nie przez adres); wygenerowane hasła admin widzi tylko raz, zaraz po zapisie.
 */
require_once __DIR__ . '/DhostingMigrator.php';
require_once dirname(__DIR__) . '/lib/DhostingGraphSource.php';
require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
require_once dirname(__DIR__, 3) . '/includes/admin_audit.php';

final class DhostingMigratorController
{
    private const VIEW = __DIR__ . '/../view/';
    private const K_RESULT = 'dhmResult';
    private const K_FLASH  = 'dhmFlash';
    private const K_TEST   = 'dhmTest';

    // ── Administrator ─────────────────────────────────────────────────────────────────────────

    public function index(): string
    {
        ika_require();
        @set_time_limit(120);
        $overview = DhostingMigrator::adminOverview();
        $data = [
            'overview' => $overview,
            'result'   => $this->take(self::K_RESULT),
            'flash'    => $this->take(self::K_FLASH),
            'test'     => $this->take(self::K_TEST),
            'cfg'      => [
                'api_url'      => DhostingConfig::get('dhosting_api_url'),
                'api_user'     => DhostingConfig::get('dhosting_api_user'),
                'has_pass'     => DhostingConfig::hasPassword(),
                'domains'      => DhostingConfig::get('dhosting_domains'),
                'quota'        => DhostingConfig::get('dhosting_quota'),
                'self_enabled' => DhostingConfig::selfEnabled(),
                'webmail_url'  => DhostingConfig::webmail(),
            ],
        ];
        return Router::view(self::VIEW . 'admin.php', $data);
    }

    public function create(): ?string
    {
        $back = route('dhm.index');
        if (!ika_ok($back)) return $this->flash($back, 'warning', 'Sesja kodu IKA wygasła — skrzynek NIE założono. Potwierdź kod IKA i powtórz.');
        $ids = array_values(array_filter(array_map('strval', (array)($_POST['ids'] ?? []))));
        if (!$ids) return $this->flash($back, 'warning', 'Zaznacz co najmniej jedno konto.');
        if (count($ids) > DhostingMigrator::BATCH_MAX) {
            return $this->flash($back, 'warning', 'Naraz można przenieść najwyżej ' . DhostingMigrator::BATCH_MAX . ' kont — zaznacz mniej i powtórz.');
        }
        @set_time_limit(600);
        @ignore_user_abort(true);
        $me = current_user();
        try {
            $results = DhostingMigrator::adminCreate($ids, !empty($_POST['reset_existing']), !empty($_POST['send_sms']), (string)($me['email'] ?? ''));
        } catch (\Throwable $e) {
            return $this->flash($back, 'danger', $e->getMessage());
        }
        $ok  = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
        $err = count(array_filter($results, fn($r) => $r['status'] === 'error'));
        $log = array_map(fn($r) => ['mailbox' => $r['mailbox'], 'status' => $r['status'], 'sms' => $r['sms'], 'msg' => $r['status'] === 'error' ? $r['msg'] : ''], $results);
        try { audit_log('dhosting.mailboxes_create', ['ok' => $ok, 'errors' => $err, 'items' => $log]); } catch (\Throwable $e) { error_log('[dhosting] audit_log: ' . $e->getMessage()); }
        admin_audit_migrate();
        admin_audit('dhosting_create', 'dhosting', 'Skrzynki dhosting: ' . $ok . ' ustawionych, ' . $err . ' błędów', 0, implode(', ', array_column($results, 'mailbox')));

        $_SESSION[self::K_RESULT] = $results;
        return Router::redirect($back . '#wynik');
    }

    public function saveSettings(): ?string
    {
        $back = route('dhm.index');
        try {
            DhostingConfig::save($_POST);
        } catch (\Throwable $e) {
            return $this->flash($back . '#ustawienia', 'danger', $e->getMessage());
        }
        admin_audit_migrate();
        admin_audit('dhosting_settings', 'dhosting', 'Zmieniono ustawienia migratora dhosting');
        return $this->flash($back . '#ustawienia', 'success', 'Zapisano ustawienia.');
    }

    public function test(): ?string
    {
        $back = route('dhm.index') . '#ustawienia';
        $api = DhostingConfig::api();
        $out = ['ok' => true, 'lines' => [], 'raw' => null];
        $domains = array_unique(array_values(DhostingConfig::domains()));
        if (!$domains) { $out['ok'] = false; $out['lines'][] = 'Najpierw wpisz domeny i zapisz ustawienia.'; }
        foreach ($domains as $d) {
            try {
                $list = $api->getMailboxList($d);
                $out['lines'][] = $d . ': połączenie OK, skrzynek: ' . count($list);
            } catch (\Throwable $e) {
                $out['ok'] = false;
                $out['lines'][] = $d . ': ' . $e->getMessage();
            }
            $out['raw'] = ['http' => $api->lastHttp, 'body' => $api->lastRaw];
        }
        try {
            $n = count(DhostingGraphSource::users(array_keys(DhostingConfig::domains())));
            $out['lines'][] = 'Microsoft 365: odczytano ' . $n . ' kont w tych domenach.';
        } catch (\Throwable $e) {
            $out['ok'] = false;
            $out['lines'][] = 'Microsoft 365: ' . $e->getMessage();
        }
        $_SESSION[self::K_TEST] = $out;
        return Router::redirect($back);
    }

    // ── Użytkownik ─────────────────────────────────────────────────────────────────────────────

    public function self(): string
    {
        $u = $this->meRow();
        return Router::view(self::VIEW . 'self.php', [
            'st'    => DhostingMigrator::selfState($u),
            'flash' => $this->take(self::K_FLASH),
            'web'   => DhostingConfig::webmail(),
        ]);
    }

    public function selfPost(): ?string
    {
        $back = route('dhm.self');
        if (empty($_POST['confirm'])) return $this->flash($back, 'warning', 'Zaznacz potwierdzenie, że numer telefonu w koncie jest Twój.');
        $u = $this->meRow();
        try {
            $msg = DhostingMigrator::selfProvision($u, (string)($u['email'] ?? ''));
        } catch (\Throwable $e) {
            try { audit_log('dhosting.self_failed', ['error' => $e->getMessage()]); } catch (\Throwable $e2) {}
            return $this->flash($back, 'danger', $e->getMessage());
        }
        try { audit_log('dhosting.self_provision', ['mailbox' => DhostingConfig::mailboxFor(DhostingMigrator::loginForUser($u))]); } catch (\Throwable $e) {}
        return $this->flash($back, 'success', $msg);
    }

    // ── Pomocnicze ─────────────────────────────────────────────────────────────────────────────

    /** Wiersz users zalogowanego (prawdziwego, nie kontekstu admina). */
    private function meRow(): array
    {
        $me = function_exists('ctx_real_user') ? (ctx_real_user() ?: current_user()) : current_user();
        $row = db_one("SELECT id, name, email, phone_number, microsoft_id, m365_login FROM users WHERE id=?", [(int)($me['id'] ?? 0)]);
        return $row ?: ['id' => (int)($me['id'] ?? 0), 'email' => (string)($me['email'] ?? ''), 'microsoft_id' => (string)($me['microsoft_id'] ?? '')];
    }

    private function flash(string $back, string $type, string $msg): ?string
    {
        $_SESSION[self::K_FLASH] = ['type' => $type, 'msg' => $msg];
        return Router::redirect($back);
    }

    private function take(string $key): mixed
    {
        $v = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $v;
    }
}
