<?php
declare(strict_types=1);

/**
 * modules/nowe_haslo/logic/NoweHasloAdminReset.php — reset WSZYSTKICH haseł osoby przez administratora, po adresie e-mail z SZO
 * (polecenie 2026-11-14 „CLI do resetowania haseł wszędzie użytkownikowi na podstawie maila w SZO”). Wejście: cli/resetHasel.php.
 *
 * Części (każda niezależna, błąd jednej nie zatrzymuje pozostałych):
 *  • sso    — logowanie jednokrotne (Logto) + chmura plików: jedno nowe, losowe hasło (SsoAccounts::resetPassword);
 *  • szo    — konto SZO: jeśli ma hasło lokalne, dostaje TO SAMO hasło co logowanie jednokrotne (jeden login i hasło, jak na /nowehaslo),
 *             a bez konta logowania jednokrotnego — własne losowe; zawsze wylogowanie wszystkich sesji (user_sessions);
 *  • poczta — skrzynka na dhosting: osobne losowe hasło (poczta ma ZAWSZE inne hasło) + przekazanie do kopiowania w tle.
 * Powiadomienia: hasła tylko SMS-em (jak /nowehaslo; poczta osobnym SMS-em), e-mail „zmieniliśmy hasła” BEZ haseł, notatka w Helpdesku,
 * audit_log 'haslo.reset_all'. Gdy nie ma numeru komórkowego albo SMS-y są wyłączone, hasła wolno tylko wyświetlić ($show).
 * Hasła nigdy nie trafiają do dzienników, notatek ani e-maili.
 */
require_once __DIR__ . '/NoweHaslo.php';
require_once dirname(__DIR__, 2) . '/dhosting_migrator/logic/DhostingMigrator.php';
require_once dirname(__DIR__, 2) . '/dhosting_migrator/logic/DhostingCopy.php';

final class NoweHasloAdminReset
{
    public const PARTS = ['szo', 'sso', 'poczta'];

    /** Atrapy w testach: fn($phone, $message, $kind): bool oraz fn($to, $name, $subject, $html, $text): bool. */
    public static ?\Closure $sendSms = null;
    public static ?\Closure $sendMail = null;

    /** Konto SZO po adresie: e-mail konta, zapasowy e-mail albo login Microsoft. */
    public static function findUser(string $email): ?array
    {
        $e = strtolower(trim($email));
        if ($e === '') return null;
        return db_one("SELECT id, name, email, alt_email, phone_number, twofa_phone, microsoft_id, m365_login, password, is_active
                       FROM users WHERE lower(email) = ? OR lower(COALESCE(alt_email,'')) = ? OR lower(COALESCE(m365_login,'')) = ?
                       ORDER BY (lower(email) = ?) DESC LIMIT 1", [$e, $e, $e, $e]);
    }

    /** Osoba w kształcie NoweHasloAuth::person (telefon i prywatne adresy także z umów). */
    public static function person(array $u): array
    {
        $address = '';
        try { $address = DhostingMigrator::loginForUser($u); } catch (\Throwable $e) { error_log('[resetHasel] adres służbowy: ' . $e->getMessage()); }
        return NoweHasloAuth::person((int)$u['id'], (string)$u['name'], (string)$u['email'], $address,
            [(string)($u['phone_number'] ?? ''), (string)($u['twofa_phone'] ?? '')], [(string)($u['alt_email'] ?? ''), (string)$u['email']], 'admin');
    }

    /**
     * Co osoba ma (podgląd, bez zmian).
     * @return array{szo:string, sso:string, poczta:string, phone:string, sessions:int}
     */
    public static function plan(array $u, array $p): array
    {
        $acc = null;
        try { $acc = NoweHaslo::ssoAccount($p, true); } catch (\Throwable $e) { error_log('[resetHasel] konto IAM: ' . $e->getMessage()); }
        $sso = $acc ? trim((!empty($acc['logto_id']) ? 'logowanie jednokrotne' : '') . (!empty($acc['cloud_uid']) ? ' + chmura (' . $acc['cloud_uid'] . ')' : ''), ' +')
                    : 'brak konta';
        $box = (string)($p['mailbox'] ?? '');
        return [
            'szo'      => (string)($u['password'] ?? '') !== '' ? 'hasło lokalne' : 'bez hasła lokalnego (tylko wylogowanie sesji)',
            'sso'      => $sso,
            'poczta'   => $box !== '' ? $box : 'brak skrzynki',
            'phone'    => (string)$p['phone'] !== '' ? DhostingMigrator::maskPhone((string)$p['phone']) : '',
            'sessions' => (int)(db_one("SELECT COUNT(*) AS n FROM user_sessions WHERE user_id = ?", [(int)$u['id']])['n'] ?? 0),
        ];
    }

    /**
     * Reset. $parts — podzbiór PARTS; $sms — wysyłać hasła SMS-em; $by — kto (do dziennika).
     * $manual — hasła ustawione ręcznie przez administratora: ['sso' => …] (SZO + logowanie jednokrotne + chmura), ['poczta' => …];
     *           brak klucza = hasło losowe. Sprawdź je wcześniej validateManual().
     * @return array{done:list<string>, failed:list<string>, skipped:list<string>, passwords:array<string,string>, sms:list<string>, mail:string}
     *         passwords: ['sso' => …, 'szo' => …, 'poczta' => …] — tylko do wyświetlenia raz, gdy wywołujący na to pozwala
     */
    public static function run(array $u, array $p, array $parts, bool $sms, string $by, array $manual = []): array
    {
        $out = ['done' => [], 'failed' => [], 'skipped' => [], 'passwords' => [], 'sms' => [], 'mail' => ''];
        $email = (string)$u['email'];

        // 1) logowanie jednokrotne + chmura
        $ssoLogin = '';
        if (in_array('sso', $parts, true)) {
            $acc = null;
            try { $acc = NoweHaslo::ssoAccount($p, true); } catch (\Throwable $e) { error_log('[resetHasel] konto IAM: ' . $e->getMessage()); }
            if (!$acc) {
                $out['skipped'][] = 'logowanie jednokrotne i chmura: brak konta';
            } else {
                try {
                    if (isset($manual['sso'])) {
                        if (!empty($acc['logto_id']) && SsoConfig::logtoConfigured()) {
                            $r = SsoAccounts::setPassword((string)$acc['email'], $manual['sso'], $by);
                        } elseif (!empty($acc['cloud_uid']) && SsoConfig::cloudConfigured()) {
                            SsoConfig::cloud()->setPassword((string)$acc['cloud_uid'], $manual['sso']);
                            $r = ['logto' => 'pominięte', 'cloud' => 'synced'];
                        } else {
                            throw new \RuntimeException('logowanie jednokrotne i chmura są nieskonfigurowane w SZO');
                        }
                        $r['password'] = $manual['sso'];
                    } else {
                        $r = SsoAccounts::resetPassword((string)$acc['email'], $by);
                    }
                    $out['passwords']['sso'] = (string)$r['password'];
                    $ssoLogin = (string)$acc['email'];
                    $cloud = (string)($r['cloud'] ?? '');
                    $out['done'][] = 'logowanie jednokrotne: ' . ($r['logto'] === 'password' ? 'nowe hasło' : 'pominięte')
                        . (!empty($acc['cloud_uid']) ? '; chmura: ' . ($cloud !== '' ? $cloud : 'bez zmian') : '');
                    if (str_starts_with($cloud, 'błąd')) $out['failed'][] = 'chmura: ' . $cloud;
                } catch (\Throwable $e) {
                    $out['failed'][] = 'logowanie jednokrotne i chmura: ' . $e->getMessage();
                }
            }
        }

        // 2) SZO: to samo hasło co logowanie jednokrotne (gdy jest), wylogowanie sesji
        if (in_array('szo', $parts, true)) {
            try {
                if ((string)($u['password'] ?? '') !== '') {
                    $pw = $out['passwords']['sso'] ?? ($manual['sso'] ?? SsoConfig::generatePassword());
                    if (!isset($out['passwords']['sso'])) $out['passwords']['szo'] = $pw;
                    db_exec("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?", [password_hash($pw, PASSWORD_DEFAULT), (int)$u['id']]);
                    $out['done'][] = 'SZO: nowe hasło' . (isset($out['passwords']['sso']) ? ' (to samo co logowanie jednokrotne)' : '');
                }
                $n = (int)(db_one("SELECT COUNT(*) AS n FROM user_sessions WHERE user_id = ?", [(int)$u['id']])['n'] ?? 0);
                db_exec("DELETE FROM user_sessions WHERE user_id = ?", [(int)$u['id']]);
                $out['done'][] = 'SZO: wylogowano sesje (' . $n . ')';
            } catch (\Throwable $e) {
                $out['failed'][] = 'SZO: ' . $e->getMessage();
            }
        }

        // 3) poczta: osobne hasło
        if (in_array('poczta', $parts, true)) {
            $box = (string)($p['mailbox'] ?? '');
            $api = $box !== '' ? DhostingConfig::api() : null;
            if ($box === '') {
                $out['skipped'][] = 'poczta: brak skrzynki';
            } elseif (!$api->configured()) {
                $out['skipped'][] = 'poczta: API dostawcy poczty nieskonfigurowane';
            } else {
                try {
                    if (!in_array($box, $api->getMailboxList(substr($box, strrpos($box, '@') + 1)), true)) throw new \RuntimeException('skrzynka ' . $box . ' nie istnieje');
                    $pw = $manual['poczta'] ?? DhostingConfig::generatePassword();
                    $api->changeMailboxPassword($box, $pw);
                    $out['passwords']['poczta'] = $pw;
                    $copy = 0;
                    try { $copy = DhostingCopy::passwordChanged($box, $pw); } catch (\Throwable $e) { error_log('[resetHasel] kopiowanie ' . $box . ': ' . $e->getMessage()); }
                    $out['done'][] = 'poczta ' . $box . ': nowe hasło' . ($copy > 0 ? ' (kopiowanie w tle: ' . $copy . ' zad.)' : '');
                } catch (\Throwable $e) {
                    $out['failed'][] = 'poczta ' . $box . ': ' . $e->getMessage();
                }
            }
        }

        // Powiadomienia: SMS z hasłami (osobno poczta), e-mail bez haseł, Helpdesk, dziennik
        if ($sms && (string)$p['phone'] !== '') {
            $org = SsoConfig::smsPrefix();
            $when = date('d.m.y H:i');
            if (isset($out['passwords']['sso'])) {
                $what = in_array('szo', $parts, true) && (string)($u['password'] ?? '') !== '' ? 'SZO, logowanie jednokrotne i chmura' : 'logowanie jednokrotne i chmura';
                $out['sms'][] = 'hasło IAM: ' . self::sms((string)$p['phone'], $org . ': nowe haslo - ' . $what . '. Login: ' . $ssoLogin
                    . ' Haslo: ' . $out['passwords']['sso'] . ' (inne niz do poczty). Od ' . $when . '.');
            } elseif (isset($out['passwords']['szo'])) {
                $out['sms'][] = 'hasło SZO: ' . self::sms((string)$p['phone'], $org . ': nowe haslo do SZO. Login: ' . $email . ' Haslo: ' . $out['passwords']['szo'] . '. Od ' . $when . '.');
            }
            if (isset($out['passwords']['poczta'])) {
                $out['sms'][] = 'hasło poczty: ' . self::sms((string)$p['phone'], DhostingMigrator::passwordSmsText((string)$p['mailbox'], $out['passwords']['poczta']));
            }
        }
        $changed = array_keys($out['passwords']);
        if ($changed) $out['mail'] = self::notifyMail($p, $changed, $sms && (string)$p['phone'] !== '');

        $note = 'Reset haseł przez administratora (' . $by . '). Zrobione: ' . ($out['done'] ? implode('; ', $out['done']) : 'nic') . '.'
              . ($out['failed'] ? ' DO SPRAWDZENIA: ' . implode('; ', $out['failed']) . '.' : '')
              . ' SMS: ' . ($out['sms'] ? implode(', ', $out['sms']) : ($sms ? 'brak numeru' : 'wyłączone (hasła przekazane osobno)')) . '.'
              . ' E-mail o zmianie: ' . ($out['mail'] !== '' ? $out['mail'] : 'nie wysłano') . '.';
        try { NoweHasloHelpdesk::event($p, $note); } catch (\Throwable $e) { error_log('[resetHasel] Helpdesk: ' . $e->getMessage()); }
        if (!function_exists('audit_log')) require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
        audit_log('haslo.reset_all', ['email' => $email, 'user_id' => (int)$u['id'], 'by' => $by, 'parts' => $parts,
            'done' => $out['done'], 'failed' => $out['failed'], 'skipped' => $out['skipped'], 'sms' => $out['sms'], 'mail' => $out['mail']], null);
        return $out;
    }

    /**
     * Sprawdza hasła ręczne: te same wymagania co /nowehaslo (NoweHaslo::validate) i hasło poczty INNE niż hasło logowania jednokrotnego
     * (porównujemy, gdy podano oba; drugie losowe jest inne z definicji). Wyjątek = nic nie zmieniamy.
     */
    public static function validateManual(array $p, array $manual): void
    {
        foreach (['sso' => 'logowania jednokrotnego', 'poczta' => 'poczty'] as $k => $label) {
            if (!isset($manual[$k])) continue;
            try { NoweHaslo::validate($p, $manual[$k], $manual[$k]); }
            catch (\RuntimeException $e) { throw new \RuntimeException('Hasło ' . $label . ': ' . $e->getMessage()); }
        }
        if (isset($manual['sso'], $manual['poczta']) && $manual['sso'] === $manual['poczta']) {
            throw new \RuntimeException('Hasło do poczty musi być inne niż hasło logowania jednokrotnego.');
        }
    }

    private static function sms(string $phone, string $msg): string
    {
        $msg = function_exists('sms_ascii') ? sms_ascii($msg) : $msg;
        try {
            if (self::$sendSms) return (self::$sendSms)($phone, $msg, 'access_data') ? 'wysłany' : 'nie wyszedł';
            $prev = $GLOBALS['__sms_quiet_bypass'] ?? null;
            $GLOBALS['__sms_quiet_bypass'] = true;   // reset na żądanie administratora — od razu, także w nocy
            try { $prov = sms_send($phone, $msg, 'access_data'); }
            finally { if ($prev === null) unset($GLOBALS['__sms_quiet_bypass']); else $GLOBALS['__sms_quiet_bypass'] = $prev; }
            return $prov !== '' ? 'wysłany' : 'nie wyszedł';
        } catch (\Throwable $e) {
            error_log('[resetHasel] SMS: ' . $e->getMessage());
            return 'nie wyszedł';
        }
    }

    /** E-mail „zmieniliśmy Twoje hasła” — bez haseł. */
    private static function notifyMail(array $p, array $changed, bool $smsSent): string
    {
        $to = $p['contacts'] ?? [];
        if (!$to) return '';
        $names = ['sso' => 'logowanie jednokrotne i chmura plików', 'szo' => 'SZO', 'poczta' => 'poczta'];
        $list = array_map(fn($k) => $names[$k], $changed);
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $lines = [
            'Kiedy: ' . date('d.m.Y, H:i') . '.',
            'Zmienione hasła: ' . implode(', ', $list) . '.',
            $smsSent ? 'Nowe hasła wysłaliśmy SMS-em.' : 'Nowe hasła przekaże Ci administrator.',
            'Hasło do poczty jest inne niż hasło logowania jednokrotnego.',
            'Hasła nigdy nie wysyłamy e-mailem. Swoje hasło zmienisz w każdej chwili na stronie Nowe hasło.',
        ];
        $name = (string)$p['name'];
        $subject = 'Ustawiliśmy Ci nowe hasła';
        $html = '<p>Dzień dobry' . ($name !== '' ? ', ' . $e($name) : '') . ',</p><p>administrator ustawił Ci nowe hasła.</p><ul>';
        foreach ($lines as $l) $html .= '<li>' . $e($l) . '</li>';
        $html .= '</ul><p><strong>Jeśli o to nie prosiłeś(-aś), napisz do Helpdesku IT. Nikt z Fundacji nie poprosi Cię o hasło.</strong></p>';
        $text = "Administrator ustawił Ci nowe hasła.\n\n- " . implode("\n- ", $lines) . "\n\nJeśli o to nie prosiłeś(-aś), napisz do Helpdesku IT.";
        $send = self::$sendMail ?? (function (string $t, string $nm, string $s, string $h, string $x): bool {
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            return (bool)mail_queue_add($t, $nm, $s, $h, $x, 'reset_hasel');
        });
        $ok = [];
        foreach ($to as $addr) {
            try { if ($send($addr, $name, $subject, $html, $text)) $ok[] = DhostingGuest::maskEmail($addr); }
            catch (\Throwable $ex) { error_log('[resetHasel] e-mail: ' . $ex->getMessage()); }
        }
        return $ok ? 'wysłany na ' . implode(', ', $ok) : 'nie wyszedł';
    }
}
