<?php
/**
 * modules/account_security/lib/accountSecurityNotify.php — powiadomienia o zdarzeniach bezpieczeństwa konta (System Tożsamości).
 *
 * Zdarzenia: new_login (logowanie z nowego urządzenia/miejsca), password (zmiana/reset hasła), mfa (wyłączenie weryfikacji
 * dwuskładnikowej, usunięcie klucza), phone (zmiana/usunięcie numeru), lockdown („To nie ja” — zawsze e-mail), test.
 * Kanały: e-mail (domyślnie włączony) i SMS (domyślnie wyłączony; tylko na zweryfikowany numer, rodzaj SMS security_alert).
 * Preferencje: tabela account_security_notify (user_id, event, email, sms) — brak wiersza = ustawienia domyślne.
 *
 * Wpięcie: accountSecurityNotifyFromLog() w authlog_write (includes/auth_security.php) oraz log_user_action /
 * log_system_action (includes/approval.php) — zdarzenia z mapy LOG_EVENTS; new_login wprost z auth_new_login_check.
 * Powiadomienie NIGDY nie przerywa operacji, która je wywołała (wszystko w try/catch), nie zawiera hasła ani kodów,
 * a w jednym żądaniu dane zdarzenie konta wysyła się najwyżej raz.
 */
require_once dirname(__DIR__, 3) . '/includes/auth_security.php';

final class AccountSecurityNotify
{
    /** Zdarzenia konfigurowalne: klucz => [etykieta, czy SMS dopuszczalny]. */
    public const EVENTS = [
        'new_login' => ['Logowanie z nowego urządzenia lub miejsca', true],
        'password'  => ['Zmiana albo reset hasła', true],
        'mfa'       => ['Wyłączenie weryfikacji dwuskładnikowej lub usunięcie klucza bezpieczeństwa', true],
        'phone'     => ['Zmiana lub usunięcie numeru telefonu', false],
    ];

    /** Akcje dzienników → zdarzenie. Źródło: authlog_write, log_user_action, log_system_action. */
    public const LOG_EVENTS = [
        'pwd_changed'         => 'password',
        'pwd_set_via_link'    => 'password',
        'password_reset'      => 'password',   // user/verify_reset.php (log_system_action)
        'user_password_reset' => 'password',   // admin/users.php — reset przez administratora
        '2fa_disabled'        => 'mfa',
        '2fa_disabled_admin'  => 'mfa',
        'webauthn_deleted'    => 'mfa',
        'webauthn_reset'      => 'mfa',
        'phone_changed'       => 'phone',
        'phone_removed'       => 'phone',
        'phone_verified'      => 'phone',
    ];

    /** Rejestr wysłanych w tym żądaniu (user:zdarzenie) — bez dubli, gdy jedna operacja zapisuje kilka dzienników. */
    private static array $sent = [];

    /** Testy: wysyłkę można przechwycić (zamiast mail_queue_add / sms_send). */
    public static ?\Closure $mailer = null;
    public static ?\Closure $smsSender = null;

    public static function migrate(): void
    {
        if (function_exists('szo_schema_current') && szo_schema_current('account_security_notify_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS account_security_notify (
            user_id    INTEGER NOT NULL,
            event      VARCHAR(40) NOT NULL,
            email      INTEGER NOT NULL DEFAULT 1,
            sms        INTEGER NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, event)
        )");
    }

    /** Preferencje konta: zdarzenie => ['email' => bool, 'sms' => bool] (domyślnie e-mail tak, SMS nie). */
    public static function prefs(int $uid): array
    {
        self::migrate();
        $out = [];
        foreach (self::EVENTS as $k => $_) $out[$k] = ['email' => true, 'sms' => false];
        foreach (db_all("SELECT event, email, sms FROM account_security_notify WHERE user_id=?", [$uid]) as $r) {
            if (!isset($out[$r['event']])) continue;
            $out[$r['event']] = ['email' => (bool)$r['email'], 'sms' => (bool)$r['sms'] && self::EVENTS[$r['event']][1]];
        }
        return $out;
    }

    /** Zapis preferencji z formularza: $in = [zdarzenie => ['email' => 0|1, 'sms' => 0|1]]. */
    public static function savePrefs(int $uid, array $in): void
    {
        self::migrate();
        $st = db()->prepare("DELETE FROM account_security_notify WHERE user_id=? AND event=?");
        $ins = db()->prepare("INSERT INTO account_security_notify (user_id, event, email, sms, updated_at) VALUES (?,?,?,?,?)");
        foreach (self::EVENTS as $k => [, $smsOk]) {
            $st->execute([$uid, $k]);
            $ins->execute([$uid, $k, !empty($in[$k]['email']) ? 1 : 0, ($smsOk && !empty($in[$k]['sms'])) ? 1 : 0, date('Y-m-d H:i:s')]);
        }
    }

    /** Krótki opis stanu do nagłówka ustawienia w Zabezpieczeniach. */
    public static function summary(array $prefs): string
    {
        $e = count(array_filter($prefs, fn($p) => $p['email']));
        $s = count(array_filter($prefs, fn($p) => $p['sms']));
        return 'E-mail: ' . $e . ' z ' . count($prefs) . ' · SMS: ' . ($s ? $s . ' z ' . count(array_filter(self::EVENTS, fn($x) => $x[1])) : 'wyłączone');
    }

    /** Treść powiadomienia: [temat, akapit „co się stało”, zdanie SMS]. */
    private static function texts(string $event, array $ctx): array
    {
        $byAdmin = !empty($ctx['by_admin']);
        return match ($event) {
            'new_login' => ['Nowe logowanie na Twoje konto',
                            'Na Twoje konto zalogowano się z ' . (!empty($ctx['new_dev']) ? 'nowego urządzenia' : 'nowego miejsca') . '.',
                            'nowe logowanie na Twoje konto'],
            'password'  => ['Hasło do Twojego konta zostało zmienione',
                            $byAdmin ? 'Administrator zresetował hasło do Twojego konta.' : 'Hasło do Twojego konta zostało zmienione.',
                            'zmieniono haslo do Twojego konta'],
            'mfa'       => ['Zmieniono zabezpieczenia logowania',
                            $byAdmin ? 'Administrator wyłączył weryfikację dwuskładnikową albo usunął klucz bezpieczeństwa na Twoim koncie.'
                                     : 'Na Twoim koncie wyłączono weryfikację dwuskładnikową albo usunięto klucz bezpieczeństwa.',
                            'wylaczono weryfikacje dwuskladnikowa lub usunieto klucz'],
            'phone'     => ['Zmieniono numer telefonu przy koncie',
                            'Zmieniono albo usunięto numer telefonu, na który wysyłamy kody SMS.', ''],
            'lockdown'  => ['Konto zostało zabezpieczone',
                            'W Systemie Tożsamości wybrano „To nie ja”: wylogowaliśmy pozostałe urządzenia i zapomnieliśmy nieznane miejsca logowania. Jeśli hasło nie zostało jeszcze zmienione — zmień je teraz.',
                            ''],
            'test'      => ['Powiadomienie testowe', 'To jest wiadomość testowa z Systemu Tożsamości — powiadomienia o bezpieczeństwie działają.',
                            'powiadomienie testowe dziala'],
            default     => ['Zdarzenie bezpieczeństwa na koncie', 'Na Twoim koncie zaszło zdarzenie bezpieczeństwa.', 'zdarzenie bezpieczenstwa na koncie'],
        };
    }

    private static function ascii(string $s): string
    {
        return strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
                          'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z']);
    }

    /**
     * Wysyła powiadomienie wg preferencji konta. Zwraca, co poszło: ['email' => bool|null, 'sms' => bool|null]
     * (null = kanał wyłączony/niedostępny). $ctx: ip, ua (domyślnie z bieżącego żądania), new_dev, by_admin, force (test/lockdown).
     */
    public static function send(int $uid, string $event, array $ctx = []): array
    {
        $res = ['email' => null, 'sms' => null];
        if ($uid <= 0 || defined('ACCOUNT_SECURITY_NOTIFY_OFF')) return $res;
        $key = $uid . ':' . $event;
        if ($event !== 'test' && isset(self::$sent[$key])) return $res;
        self::$sent[$key] = true;

        $u = db_one("SELECT * FROM users WHERE id=?", [$uid]);
        if (!$u || (isset($u['is_active']) && (int)$u['is_active'] !== 1) || !empty($u['merged_into_id'])) return $res;
        if ((string)($u['email'] ?? '') === 'serwis@local') return $res;   // konto awaryjne

        $always = in_array($event, ['lockdown', 'test'], true);
        $p = $always ? ['email' => true, 'sms' => $event === 'test'] : (self::prefs($uid)[$event] ?? null);
        if ($p === null) return $res;
        if ($event === 'test') { $pp = self::prefs($uid); $p['sms'] = (bool)array_filter($pp, fn($x) => $x['sms']); }

        [$subject, $what, $smsWhat] = self::texts($event, $ctx);
        $ip    = (string)($ctx['ip'] ?? _auth_ip());
        $ua    = (string)($ctx['ua'] ?? _auth_ua());
        $when  = date('d.m.Y H:i');
        $geo   = '';
        if ($ip !== '') {
            require_once __DIR__ . '/accountSecurityDevices.php';
            $geo = AccountSecurityDevices::geoCached($ip);
        }
        $org   = defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : 'SZO';
        $short = (function_exists('org_setting') ? trim((string)org_setting('org_short_name')) : '') ?: $org;   // SMS: krótka nazwa
        $link  = APP_URL . '/tozsamosc/index.php#urzadzenia';

        // ── e-mail ──
        $to = trim((string)($u['email'] ?? ''));
        if ($p['email'] && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $rows = [['Kiedy', $when]];
            if ($ua !== '' && $event !== 'lockdown') $rows[] = ['Urządzenie', auth_device_label($ua)];
            if ($ip !== '' && $event !== 'lockdown') $rows[] = ['Adres IP', $ip . ($geo !== '' ? ' (' . $geo . ')' : '')];
            $html = self::html($org, (string)($u['name'] ?? ''), $subject, $what, $rows, $link, $event);
            $text = $subject . "\n\n" . $what . "\n\n" . implode("\n", array_map(fn($r) => $r[0] . ': ' . $r[1], $rows))
                  . "\n\nJeśli to Ty — nic nie musisz robić. Jeśli nie — otwórz System Tożsamości → Urządzenia i logowania i wybierz „To nie ja”: " . $link;
            try {
                if (self::$mailer) {
                    $res['email'] = (bool)(self::$mailer)($to, $subject, $html, $text, $event);
                } else {
                    require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
                    // Nowe logowanie przez kolejkę (cron) — wysyłka nie opóźnia samego logowania; reszta od razu.
                    $sched = $event === 'new_login' ? date('Y-m-d H:i:s') : '';
                    $res['email'] = mail_queue_add($to, (string)($u['name'] ?? ''), $subject, $html, $text,
                                                   'account_security', $uid, $sched) > 0;
                }
            } catch (\Throwable $e) {
                $res['email'] = false;
                error_log('[account_security] e-mail ' . $event . ' #' . $uid . ': ' . $e->getMessage());
            }
        }

        // ── SMS (tylko zweryfikowany numer) ──
        $phone = trim((string)($u['phone_number'] ?? ''));
        if ($p['sms'] && $smsWhat !== '' && $phone !== '' && !empty($u['phone_verified_at'])) {
            $msg = self::ascii(mb_substr($short, 0, 24)) . ': ' . $smsWhat . ' (' . date('d.m H:i') . ').'
                 . ($event === 'test' ? '' : ' Jesli to nie Ty: System Tozsamosci > Urzadzenia > To nie ja.');
            try {
                if (self::$smsSender) {
                    $res['sms'] = (bool)(self::$smsSender)($phone, $msg, $event);
                } else {
                    require_once dirname(__DIR__, 3) . '/includes/sms.php';
                    if (sms_is_enabled()) { sms_send(sms_normalize_phone($phone), $msg, 'security_alert'); $res['sms'] = true; }
                }
            } catch (\Throwable $e) {
                $res['sms'] = false;
                error_log('[account_security] SMS ' . $event . ' #' . $uid . ': ' . (function_exists('sms_scrub') ? sms_scrub($e->getMessage()) : 'błąd wysyłki'));
            }
        }
        return $res;
    }

    private static function html(string $org, string $name, string $subject, string $what, array $rows, string $link, string $event): string
    {
        $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $tr = '';
        foreach ($rows as [$k, $v]) {
            $tr .= '<tr><th scope="row" style="text-align:left;padding:6px 16px 6px 0;color:#424242;font-weight:600;vertical-align:top">' . $e($k)
                 . '</th><td style="padding:6px 0;color:#242424">' . $e($v) . '</td></tr>';
        }
        $advice = $event === 'test' ? ''
            : '<p style="margin:16px 0 0">Jeśli to Ty — nic nie musisz robić.</p>'
            . '<p style="margin:8px 0 0"><strong>Jeśli to nie Ty</strong> — od razu otwórz System Tożsamości, sekcję „Urządzenia i logowania”, i wybierz „To nie ja”. Wylogujemy wszystkie inne urządzenia, a Ty ustawisz nowe hasło.</p>'
            . '<p style="margin:20px 0 0"><a href="' . $e($link) . '" style="display:inline-block;background:#0f6cbd;color:#ffffff;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:4px">Otwórz Urządzenia i logowania</a></p>';
        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>' . $e($subject) . '</title></head>'
             . '<body style="margin:0;background:#f5f5f5;font-family:Segoe UI,Arial,sans-serif;font-size:15px;line-height:1.5;color:#242424">'
             . '<div style="max-width:560px;margin:0 auto;padding:24px 16px"><div style="background:#ffffff;border-radius:8px;padding:24px">'
             . '<p style="margin:0 0 4px;color:#616161;font-size:13px">' . $e($org) . ' · System Tożsamości</p>'
             . '<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;color:#242424">' . $e($subject) . '</h1>'
             . ($name !== '' ? '<p style="margin:0 0 12px">Dzień dobry, ' . $e($name) . '!</p>' : '')
             . '<p style="margin:0 0 12px">' . $e($what) . '</p>'
             . '<table role="presentation" style="border-collapse:collapse;margin:8px 0 0">' . $tr . '</table>'
             . $advice
             . '<p style="margin:24px 0 0;color:#616161;font-size:12px">Wiadomość automatyczna — nie zawiera hasła ani kodów i nigdy o nie nie prosimy. Ustawienia powiadomień: System Tożsamości → Zabezpieczenia → Powiadomienia o bezpieczeństwie.</p>'
             . '</div></div></body></html>';
    }
}

/** Wysyłka powiadomienia (skrót dla wpięć w kodzie legacy). Nigdy nie rzuca wyjątku. */
function accountSecurityNotify(int $uid, string $event, array $ctx = []): array
{
    try {
        return AccountSecurityNotify::send($uid, $event, $ctx);
    } catch (\Throwable $e) {
        error_log('[account_security] powiadomienie ' . $event . ': ' . $e->getMessage());
        return ['email' => null, 'sms' => null];
    }
}

/**
 * Wpięcie w dzienniki: akcja z mapy LOG_EVENTS → powiadomienie konta $uid. $byUid ≠ $uid = zmiana wykonana przez kogoś
 * innego (administrator). Nigdy nie rzuca wyjątku.
 */
function accountSecurityNotifyFromLog(?int $uid, string $action, ?int $byUid = null): void
{
    if (!$uid || !isset(AccountSecurityNotify::LOG_EVENTS[$action])) return;
    accountSecurityNotify((int)$uid, AccountSecurityNotify::LOG_EVENTS[$action], ['by_admin' => $byUid !== null && $byUid > 0 && $byUid !== $uid]);
}
