<?php
/**
 * modules/user_merge/logic/UserMergeService.php — kontrolowane scalanie zduplikowanych kont (Tożsamość).
 *
 * merge($primaryId, $dupIds, $opts, $byUid):
 *   1. walidacja po stronie serwera (te same reguły co w oknie): konta z jednej, aktualnej grupy duplikatów, nie scalone,
 *      nie serwis@local, nie własne konto wykonującego; różne PESEL-e blokują; brak wspólnego PESEL-u wymaga potwierdzenia;
 *   2. JEDNA transakcja: przepięcie powiązań (userMergeRepoint), e-mail w umowach, uzupełnienie pustych pól konta
 *      głównego, przeniesienie konta Microsoft (microsoft_id, m365_login), hash nowego hasła + wymuszona zmiana,
 *      archiwizacja duplikatów (merged_into_id, is_active = 0, bez hasła), koniec sesji, ślad w audit_logs i historii kont;
 *   3. po zapisie: SMS z hasłem → (gdy SMS dotarł i admin zaznaczył) hasło w Entra ID → e-maile bez hasła.
 *      Błąd Graph / SMS / e-mail nie cofa scalenia — trafia do wyniku i do audit_logs.
 *
 * Hasło tymczasowe żyje tylko w pamięci tego żądania: nie trafia do bazy (tylko hash), logów, audit_logs, kolejki SMS
 * (cisza nocna pominięta — sms_deferred) ani kolejki maili (bez sms_send_with_fallback, e-maile bez hasła).
 * Graph jest wołany dopiero, gdy hasło dotarło SMS-em — inaczej użytkownik straciłby dostęp do Microsoft 365.
 *
 * Współpracownicy wstrzykiwani (testy hermetyczne): $graphFactory(): ?obiekt z set_password/last_status/last_error,
 * $smsSender(string $phone, string $msg): string (dostawca, '' = nie wysłano), $mailer(string $to, string $subj, string $html): bool.
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__) . '/lib/userMergeDetect.php';
require_once dirname(__DIR__) . '/lib/userMergeRelations.php';
require_once dirname(__DIR__) . '/lib/userMergePassword.php';
require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';

final class UserMergeService
{
    /** Pola konta głównego uzupełniane z duplikatu, gdy puste (NULL / ''). Celowo bez sekretów i czynników logowania. */
    public const FILLABLE = ['first_name', 'last_name', 'name', 'phone_number', 'alt_phone_number', 'alt_email',
        'rpts_pesel', 'rpts_data_urodzenia', 'rpts_miejsce_urodzenia', 'rpts_nazwisko_rodowe', 'rpts_imie_ojca',
        'rpts_imie_matki', 'rpts_consent', 'rpts_consent_at', 'rpts_ezd_sprawa_id', 'guardian_name', 'guardian_email',
        'guardian_phone', 'guardian_user_id', 'org_unit_id', 'm365_login', 'm365_security_group_id',
        'm365_security_group_name', 'crm_job_title', 'crm_display_phone', 'crm_email_signature', 'crm_sms_signature',
        'phone_verified_at', 'consent_accepted_at', 'consent_text_version_hash', 'gdpr_statement_signed_at',
        'gdpr_statement_ip', 'panel_color', 'panel_theme'];

    /** @var callable */ private $graphFactory;
    /** @var callable */ private $smsSender;
    /** @var callable */ private $mailer;

    public function __construct(?callable $graphFactory = null, ?callable $smsSender = null, ?callable $mailer = null)
    {
        $this->graphFactory = $graphFactory ?? [self::class, 'defaultGraph'];
        $this->smsSender    = $smsSender ?? [self::class, 'defaultSms'];
        $this->mailer       = $mailer ?? [self::class, 'defaultMail'];
    }

    // ───────────────────────── Domyślni współpracownicy ─────────────────────────

    public static function defaultGraph(): ?object
    {
        require_once dirname(__DIR__, 3) . '/includes/m365.php';
        if (m365_setting('m365_enabled') !== '1') return null;
        $g = new M365Graph();
        return $g->is_configured() ? $g : null;
    }

    /** SMS z hasłem: rodzaj access_data, z pominięciem ciszy nocnej (inaczej treść czekałaby w sms_deferred). */
    public static function defaultSms(string $phone, string $message): string
    {
        require_once dirname(__DIR__, 3) . '/includes/sms.php';
        $had  = array_key_exists('__sms_quiet_bypass', $GLOBALS);
        $prev = $GLOBALS['__sms_quiet_bypass'] ?? null;
        $GLOBALS['__sms_quiet_bypass'] = true;
        try {
            return sms_send($phone, $message, 'access_data');
        } finally {
            if ($had) $GLOBALS['__sms_quiet_bypass'] = $prev; else unset($GLOBALS['__sms_quiet_bypass']);
        }
    }

    public static function defaultMail(string $to, string $subject, string $html): bool
    {
        require_once dirname(__DIR__, 3) . '/includes/approval.php';
        return approval_send_email($to, $subject, $html, 'user_merge');
    }

    // ───────────────────────── Scalanie ─────────────────────────

    /**
     * @param int[] $dupIds  konta do zarchiwizowania (scalane w konto główne)
     * @param array{members?:int[], sync_m365?:bool, confirm_same?:bool} $opts  members = skład grupy widziany w oknie
     * @return array{ok:bool, error?:string, primary?:array, merged?:array, moved?:int, left?:int, contracts?:int,
     *               filled?:string[], ms?:array, sms?:array, email?:array, m365?:array}
     */
    public function merge(int $primaryId, array $dupIds, array $opts, int $byUid): array
    {
        userMergeMigrate();
        $dupIds = array_values(array_unique(array_filter(array_map('intval', $dupIds), fn($id) => $id > 0 && $id !== $primaryId)));
        $check  = $this->validate($primaryId, $dupIds, $opts, $byUid);
        if (isset($check['error'])) return ['ok' => false, 'error' => $check['error']];
        [$primary, $dups] = [$check['primary'], $check['dups']];

        $password = userMergeGeneratePassword(16, userMergePasswordAvoid($primary));
        $now      = date('Y-m-d H:i:s');
        $pdo      = db();
        $summary  = ['moved' => [], 'left' => [], 'contracts' => [], 'filled' => [], 'ms' => ['moved_from' => null, 'clash' => []]];

        // Samonaprawy schematu (CREATE TABLE IF NOT EXISTS) PRZED transakcją: w MySQL DDL w transakcji = niejawny COMMIT.
        require_once dirname(__DIR__, 3) . '/includes/approval.php';
        require_once dirname(__DIR__, 3) . '/includes/auth_security.php';
        audit_logs_migrate();
        if (function_exists('_auth_security_init')) _auth_security_init();
        userMergeRelationColumns();
        userMergeContractEmailColumns();
        $keepToken = (string)($_SESSION['_session_token'] ?? '');   // własne konto jako główne — nie wylogowuj bieżącej sesji

        $pdo->beginTransaction();
        try {
            $userCols = userMergeTableColumns('users');
            $fill     = [];
            $cur      = $primary;                                   // stan konta głównego z uzupełnieniami
            foreach ($dups as $d) {
                // konto Microsoft: zachowaj — przenieś, gdy główne go nie ma; różne konta → główne wygrywa, ślad w audycie
                $pm = trim((string)($cur['microsoft_id'] ?? ''));
                $dm = trim((string)($d['microsoft_id'] ?? ''));
                if ($dm !== '') {
                    if ($pm === '') {
                        $fill['microsoft_id'] = $dm; $cur['microsoft_id'] = $dm;
                        $summary['ms']['moved_from'] = (int)$d['id'];
                        $pdo->prepare("UPDATE users SET microsoft_id = NULL WHERE id = ?")->execute([(int)$d['id']]);
                    } elseif ($pm === $dm) {
                        $pdo->prepare("UPDATE users SET microsoft_id = NULL WHERE id = ?")->execute([(int)$d['id']]);
                    } else {
                        $summary['ms']['clash'][] = (int)$d['id'];
                    }
                }
                foreach (self::FILLABLE as $col) {
                    if (!in_array($col, $userCols, true)) continue;
                    $pv = $cur[$col] ?? null; $dv = $d[$col] ?? null;
                    if (($pv === null || trim((string)$pv) === '') && $dv !== null && trim((string)$dv) !== '') {
                        $fill[$col] = $dv; $cur[$col] = $dv;
                    }
                }

                $r = userMergeRepoint((int)$d['id'], (int)$primary['id']);
                foreach ($r['moved'] as $k => $n) $summary['moved'][$k] = ($summary['moved'][$k] ?? 0) + $n;
                foreach ($r['left'] as $k => $n)  $summary['left'][$k]  = ($summary['left'][$k] ?? 0) + $n;
                foreach (userMergeRewriteContractEmails((string)$d['email'], (string)$primary['email'], $byUid, (int)$d['id'], (int)$primary['id']) as $k => $n) {
                    $summary['contracts'][$k] = ($summary['contracts'][$k] ?? 0) + $n;
                }

                // archiwum: nieaktywne, oznaczone, bez hasła i kodów logowania — nic nie kasujemy
                $arch = ['is_active' => 0, 'merged_into_id' => (int)$primary['id'], 'merged_at' => $now, 'password' => null,
                         'login_code' => null, 'activation_token' => null, 'sms_fallback_code' => null, 'must_change_password' => 0];
                if (in_array('user_status', $userCols, true)) $arch['user_status'] = 'merged';
                $arch = array_intersect_key($arch, array_flip($userCols));
                $set  = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($arch)));
                $pdo->prepare("UPDATE users SET {$set} WHERE id = ?")->execute([...array_values($arch), (int)$d['id']]);
            }

            $upd = $fill + ['password' => password_hash($password, PASSWORD_BCRYPT), 'must_change_password' => 1, 'is_active' => 1];
            $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($upd)));
            $pdo->prepare("UPDATE users SET {$set} WHERE id = ?")->execute([...array_values($upd), (int)$primary['id']]);
            $summary['filled'] = array_keys($fill);

            // sesje: hasło konta głównego się zmieniło, archiwa nie mogą działać dalej
            foreach ([...$dups, $primary] as $u) {
                if (function_exists('session_destroy_all')) session_destroy_all((int)$u['id'], (int)$u['id'] === $byUid ? $keepToken : '');
            }

            $dupList = implode(', ', array_map(fn($d) => '#' . $d['id'] . ' (' . $d['email'] . ')', $dups));
            audit_log('tozsamosc.users_merged', [
                'primary_id'   => (int)$primary['id'],
                'merged_ids'   => array_map(fn($d) => (int)$d['id'], $dups),
                'reason'       => $check['strong'] ? 'pesel' : 'name_confirmed',
                'filled'       => $summary['filled'],                   // tylko nazwy pól — wartości nie logujemy
                'microsoft'    => $summary['ms'],
                'moved'        => $summary['moved'],
                'left_on_archive' => $summary['left'],
                'contracts_email' => $summary['contracts'],
                'password'     => 'temporary, hash only, must_change',
                'm365_sync_requested' => !empty($opts['sync_m365']),
            ], $byUid);
            log_user_action((int)$primary['id'], $byUid, 'user_merge',
                'Scalono z kontem ' . $dupList . '. Przepięto powiązań: ' . array_sum($summary['moved'])
                . ($summary['filled'] ? '; uzupełniono: ' . implode(', ', $summary['filled']) : '') . '. Nowe hasło tymczasowe (zmiana wymuszona).');
            foreach ($dups as $d) {
                log_user_action((int)$d['id'], $byUid, 'user_merge',
                    'Konto zarchiwizowane — scalone z kontem #' . $primary['id'] . ' (' . $primary['email'] . ').');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[user_merge] scalenie #' . $primaryId . ' nieudane: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Scalenie nie powiodło się, nic nie zmieniono: ' . $e->getMessage()];
        }

        $fresh = db_one("SELECT * FROM users WHERE id = ?", [(int)$primary['id']]) ?: $cur;
        $notify = $this->notify($fresh, $dups, $password, !empty($opts['sync_m365']), $byUid);
        $password = '';   // nie trzymamy dłużej, niż trzeba

        return ['ok' => true, 'primary' => ['id' => (int)$fresh['id'], 'name' => (string)$fresh['name'], 'email' => (string)$fresh['email']],
                'merged' => array_map(fn($d) => ['id' => (int)$d['id'], 'name' => (string)$d['name'], 'email' => (string)$d['email']], $dups),
                'moved' => array_sum($summary['moved']), 'left' => array_sum($summary['left']), 'contracts' => array_sum($summary['contracts']),
                'filled' => $summary['filled'], 'ms' => $summary['ms']] + $notify;
    }

    /** @return array{error:string}|array{primary:array, dups:array, strong:bool} */
    public function validate(int $primaryId, array $dupIds, array $opts, int $byUid): array
    {
        if ($primaryId < 1 || !$dupIds) return ['error' => 'Wybierz konto główne i co najmniej jedno konto do scalenia.'];
        $all   = userMergeGroups(true);
        $gid   = $all['byUser'][$primaryId] ?? null;
        if ($gid === null) return ['error' => 'Konto główne nie należy już do żadnej grupy duplikatów — odśwież listę.'];
        $group = $all['groups'][$gid]['members'];
        foreach ($dupIds as $id) {
            if (!in_array($id, $group, true)) return ['error' => 'Konto #' . $id . ' nie jest duplikatem konta głównego — scalenie odrzucone.'];
        }
        if (isset($opts['members'])) {
            $seen = array_values(array_unique(array_map('intval', (array)$opts['members']))); sort($seen);
            if ($seen !== $group) return ['error' => 'Skład grupy duplikatów zmienił się od otwarcia okna — odśwież listę i porównaj konta ponownie.'];
        }
        if (in_array($byUid, $dupIds, true)) return ['error' => 'Nie możesz zarchiwizować własnego konta — wybierz je jako konto główne.'];

        $ids  = [$primaryId, ...$dupIds];
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $rows = [];
        foreach (db_all("SELECT * FROM users WHERE id IN ({$ph})", $ids) as $r) $rows[(int)$r['id']] = $r;
        foreach ($ids as $id) {
            if (!isset($rows[$id])) return ['error' => 'Konto #' . $id . ' nie istnieje.'];
            if (!empty($rows[$id]['merged_into_id'])) return ['error' => 'Konto #' . $id . ' zostało już scalone.'];
            if (($rows[$id]['email'] ?? '') === 'serwis@local') return ['error' => 'Konto systemowe jest chronione.'];
        }
        $primary = $rows[$primaryId];
        $strong  = true;
        foreach ($dupIds as $id) {
            if (userMergePeselConflict($primary, $rows[$id])) {
                return ['error' => 'Konto #' . $id . ' ma inny numer PESEL niż konto główne — to różne osoby. Scalenie zablokowane.'];
            }
            if (!userMergePeselMatch($primary, $rows[$id])) $strong = false;
        }
        if (!$strong && empty($opts['confirm_same'])) {
            return ['error' => 'Konta nie mają wspólnego numeru PESEL — potwierdź, że to ta sama osoba.'];
        }
        return ['primary' => $primary, 'dups' => array_map(fn($id) => $rows[$id], $dupIds), 'strong' => $strong];
    }

    // ───────────────────────── Powiadomienia ─────────────────────────

    /** Telefon do powiadomień: phone_number, a gdy pusty — alt_phone_number. */
    private static function phoneOf(array $u): string
    {
        $p = trim((string)($u['phone_number'] ?? ''));
        return $p !== '' ? $p : trim((string)($u['alt_phone_number'] ?? ''));
    }

    private static function maskPhone(string $p): string
    {
        $d = preg_replace('/\D+/', '', $p);
        return '••• ••• ' . substr($d, -3);
    }

    private static function maskEmail(string $e): string
    {
        require_once dirname(__DIR__, 3) . '/modules/field_crypto/lib/fieldCrypto.php';
        return fcMask($e, 'email');
    }

    /** Wynik: sms / m365 / email — bez hasła; każdy komunikat błędu oczyszczony z hasła. */
    private function notify(array $primary, array $dups, string $password, bool $syncM365, int $byUid): array
    {
        require_once dirname(__DIR__, 3) . '/includes/sms.php';                       // sms_normalize_phone, sms_ascii, sms_scrub
        $redact = fn(string $s): string => $password !== '' ? str_replace($password, '********', $s) : $s;
        $org    = defined('ORG_NAME') && ORG_NAME !== '' ? (string)ORG_NAME : 'SZO';

        // 1. SMS (z hasłem) — na telefony wszystkich scalanych kont, każdy numer raz
        $phones = [];
        foreach ([$primary, ...$dups] as $u) {
            $p = self::phoneOf($u);
            if ($p === '') continue;
            $n = sms_normalize_phone($p);
            if (strlen($n) >= 9) $phones[$n] = $p;
        }
        $msg = sms_ascii(mb_substr($org, 0, 24) . ': Twoje konta w systemie zostaly scalone w jedno.')
             . "\nLogin: " . $primary['email'] . "\nHaslo tymczasowe: " . $password . "\nZmien je po zalogowaniu.";
        $sms = []; $smsOk = 0;
        foreach ($phones as $n => $raw) {
            try {
                $via = (string)call_user_func($this->smsSender, $n, $msg);
                $ok  = $via !== '' && $via !== 'odlozony';
                $sms[] = ['to' => self::maskPhone($n), 'ok' => $ok, 'info' => $ok ? $via : 'SMS nie został wysłany (numer zablokowany lub odłożony).'];
                if ($ok) $smsOk++;
            } catch (\Throwable $e) {
                $sms[] = ['to' => self::maskPhone($n), 'ok' => false, 'info' => $redact(sms_scrub($e->getMessage()))];
            }
        }
        $msg = '';

        // 2. Entra ID — tylko gdy hasło dotarło SMS-em (inaczej odcięlibyśmy dostęp do Microsoft 365)
        $oid  = trim((string)($primary['microsoft_id'] ?? ''));
        $m365 = ['status' => 'none'];
        if ($oid !== '') {
            if (!$syncM365)      $m365 = ['status' => 'skipped', 'info' => 'Administrator nie zaznaczył synchronizacji hasła z Microsoft 365.'];
            elseif ($smsOk === 0) $m365 = ['status' => 'skipped', 'info' => 'Hasło nie dotarło SMS-em, więc nie zmieniono go w Microsoft 365.'];
            else {
                try {
                    $g = call_user_func($this->graphFactory);
                    if (!$g) {
                        $m365 = ['status' => 'skipped', 'info' => 'Integracja Microsoft 365 jest wyłączona lub nieskonfigurowana.'];
                    } else {
                        $g->set_password($oid, $password, true);
                        $st = (int)$g->last_status();
                        if ($st >= 200 && $st < 300) {
                            $m365 = ['status' => 'ok', 'http' => $st];
                        } else {
                            $err  = $g->last_error();
                            $text = trim((string)(($err['code'] ?? '') . ' ' . ($err['message'] ?? '')));
                            $m365 = ['status' => 'error', 'http' => $st, 'info' => $redact($text !== '' ? $text : 'brak odpowiedzi Graph API')];
                        }
                    }
                } catch (\Throwable $e) {
                    $m365 = ['status' => 'error', 'info' => $redact($e->getMessage())];
                }
                if ($m365['status'] === 'error') error_log('[user_merge] Graph set_password #' . $primary['id'] . ': ' . ($m365['http'] ?? '-') . ' ' . $m365['info']);
            }
        }

        // 3. E-maile (bez hasła) — na adresy wszystkich scalanych kont, każdy adres raz; osobny wariant dla archiwum
        $smsTo = implode(', ', array_map(fn($s) => $s['to'], array_filter($sms, fn($s) => $s['ok'])));
        $mails = []; $sent = [];
        foreach ([['primary', $primary], ...array_map(fn($d) => ['archived', $d], $dups)] as [$variant, $u]) {
            $to = trim((string)$u['email']);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL) || isset($sent[mb_strtolower($to)])) continue;
            $sent[mb_strtolower($to)] = true;
            try {
                $ok = (bool)call_user_func($this->mailer, $to, 'Twoje konta w systemie ' . $org . ' zostały scalone',
                    $this->mailHtml($variant, $primary, $u, $dups, $smsTo, $m365['status'], $org));
                $mails[] = ['to' => self::maskEmail($to), 'variant' => $variant, 'ok' => $ok];
            } catch (\Throwable $e) {
                $mails[] = ['to' => self::maskEmail($to), 'variant' => $variant, 'ok' => false, 'info' => $redact($e->getMessage())];
            }
        }

        try {
            audit_log('tozsamosc.users_merge_notify', ['primary_id' => (int)$primary['id'], 'sms' => $sms, 'email' => $mails, 'm365' => $m365], $byUid);
        } catch (\Throwable $e) {
            error_log('[user_merge] audit powiadomień: ' . $e->getMessage());
        }
        return ['sms' => $sms, 'email' => $mails, 'm365' => $m365];
    }

    /** Treść e-maila (HTML w szablonie SZO). Wariant 'primary' — konto główne, 'archived' — konto zarchiwizowane. */
    private function mailHtml(string $variant, array $primary, array $recipient, array $dups, string $smsTo, string $m365Status, string $org): string
    {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $p = 'style="margin:0 0 14px;color:#334155;font-size:14px;line-height:1.65"';
        $name = trim((string)($recipient['first_name'] ?? '')) ?: trim((string)$recipient['name']);
        $html = '<p style="margin:0 0 14px;font-size:15px">Dzień dobry' . ($name !== '' ? ' <strong>' . $e($name) . '</strong>' : '') . ',</p>';
        if ($variant === 'primary') {
            $others = implode(', ', array_map(fn($d) => $d['email'], $dups));
            $html .= '<p ' . $p . '>Twoje konta w systemie <strong>' . $e($org) . '</strong> zostały scalone w jedno. '
                   . 'Kontem głównym jest konto <strong>' . $e($primary['email']) . '</strong>. '
                   . 'Pozostałe konto (' . $e($others) . ') zostało zarchiwizowane, a jego umowy, dokumenty i historia są teraz na koncie głównym.</p>';
        } else {
            $html .= '<p ' . $p . '>Konto <strong>' . $e($recipient['email']) . '</strong> w systemie <strong>' . $e($org) . '</strong> '
                   . 'zostało scalone z Twoim kontem głównym <strong>' . $e($primary['email']) . '</strong> i zarchiwizowane. '
                   . 'Od teraz loguj się adresem <strong>' . $e($primary['email']) . '</strong>.</p>';
        }
        $html .= $smsTo !== ''
            ? '<p ' . $p . '>Nowe hasło tymczasowe wysłaliśmy <strong>SMS-em</strong> na numer ' . $e($smsTo) . '. '
              . 'Przy pierwszym logowaniu system poprosi o ustawienie własnego hasła.</p>'
            : '<p ' . $p . '>Nowe hasło tymczasowe przekaże Ci administrator — nie udało się wysłać go SMS-em.</p>';
        if (trim((string)($primary['microsoft_id'] ?? '')) !== '') {
            $html .= '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:12px 16px;margin:0 0 16px;font-size:13px;color:#1e3a8a;line-height:1.55">'
                   . 'Logowanie kontem <strong>Microsoft</strong> pozostaje aktywne. '
                   . ($m365Status === 'ok'
                        ? 'Hasło do Microsoft 365 zsynchronizowaliśmy z nowym hasłem tymczasowym — przy pierwszym logowaniu system wymusi jego zmianę.'
                        : 'Hasło do Microsoft 365 nie zmieniło się.')
                   . '</div>';
        }
        $html .= '<p style="margin:0;color:#64748b;font-size:13px;line-height:1.5">Ze względów bezpieczeństwa hasło nigdy nie jest wysyłane e-mailem. '
               . 'Jeśli nie rozpoznajesz tej zmiany, skontaktuj się z administratorem.</p>';
        if (function_exists('_feer_email_tpl')) return _feer_email_tpl($html, 'Konta zostały scalone');
        return $html;
    }
}
