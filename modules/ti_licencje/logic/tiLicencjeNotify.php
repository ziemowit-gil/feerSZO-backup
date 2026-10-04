<?php
/**
 * modules/ti_licencje/logic/tiLicencjeNotify.php — powiadomienia o nowym przypisaniu licencji (SMS + e-mail).
 *
 * Odbiorcy: kursant oraz — gdy jest małoletni — jego opiekun. Zasady jak w istniejących
 * powiadomieniach TI (oceny: k30_ti_notify_grade, zajęcia: ti_lesson_sms_notify,
 * dydaktyka: k30_ti_notify_dydaktyka); szanujemy ustawienia powiadomień z konta kursanta:
 *   kursant  e-mail → chyba że wyłączył „e-mail od dydaktyki” (notify_email_dydaktyka = 0)
 *   kursant  SMS    → tylko gdy włączył „SMS od dydaktyki” (notify_sms_dydaktyka = 1);
 *                     numery wg k30_ti_sms_numbers() (główny + zweryfikowane dodatkowe)
 *   opiekun  e-mail → chyba że wyłączył „wiadomości od prowadzącego” (parent_notify_messages = 0)
 *   opiekun  SMS    → chyba że wyłączył SMS-y (parent_notify_lessons = 0)
 * Kursant „wirtualny” (ti_virtual) nie dostaje nic. Treść NIE zawiera loginu ani klucza —
 * te są w panelu kursanta, za oświadczeniem o poufności. Szablony (edytowalne w panelu
 * szablonów): ti_license_assigned i ti_license_assigned_guardian w rejestrach SMS i e-mail.
 * Wynik każdej próby (wysłano / błąd / pominięto + powód) trafia do k30_ti_license_notifications.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/sms.php';
require_once dirname(__DIR__, 3) . '/includes/sms_templates.php';
require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
require_once dirname(__DIR__, 3) . '/includes/email_templates.php';
require_once dirname(__DIR__, 3) . '/includes/ti_virtual.php';
require_once __DIR__ . '/tiLicencjeSchema.php';

final class TiLicencjeNotifier
{
    public const CHANNELS = ['email' => 'E-mail', 'sms' => 'SMS'];

    /**
     * Odbiorcy powiadomienia o licencji kursanta. Niepusty email_skip / sms_skip = kanał pominięty (powód).
     *
     * @return list<array{kind:string,name:string,email:string,phones:list<string>,email_skip:string,sms_skip:string}>
     */
    public static function recipients(int $clientId): array
    {
        $r = db_one(
            "SELECT cl.name, cl.email, cl.phone,
                    a.id AS account_id, a.is_minor, a.guardian_name, a.guardian_email, a.guardian_phone,
                    a.notify_email_dydaktyka, a.notify_sms_dydaktyka, a.parent_notify_messages, a.parent_notify_lessons,
                    a.notify_phone2, a.notify_phone2_verified, a.notify_phone3, a.notify_phone3_verified
               FROM k30_clients cl
               LEFT JOIN k30_ti_student_accounts a ON a.client_id = cl.id AND a.is_active = 1
              WHERE cl.id = ?
              ORDER BY a.id LIMIT 1",
            [$clientId]
        );
        if (!$r) return [];
        $hasAcc   = $r['account_id'] !== null;
        $smsReady = function_exists('sms_channel_ready') && sms_channel_ready();
        $out      = [];

        // ── kursant ──
        $email = trim((string)$r['email']);
        $es = '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $es = 'brak poprawnego adresu e-mail kursanta';
        elseif ($hasAcc && $r['notify_email_dydaktyka'] !== null && (int)$r['notify_email_dydaktyka'] === 0) $es = 'kursant wyłączył powiadomienia e-mail od dydaktyki';
        $phones = function_exists('k30_ti_sms_numbers') ? k30_ti_sms_numbers($r) : array_filter([trim((string)$r['phone'])]);
        $ss = '';
        if (!$smsReady)                         $ss = 'kanał SMS nie jest skonfigurowany';
        elseif (!$phones)                       $ss = 'brak numeru telefonu kursanta';
        elseif (!$hasAcc)                       $ss = 'kursant nie ma konta w panelu — SMS wymaga jego zgody';
        elseif (empty($r['notify_sms_dydaktyka'])) $ss = 'kursant nie włączył SMS od dydaktyki';
        $out[] = ['kind' => 'kursant', 'name' => (string)$r['name'], 'email' => $email, 'phones' => array_values($phones),
                  'email_skip' => $es, 'sms_skip' => $ss];

        // ── opiekun małoletniego ──
        if ($hasAcc && !empty($r['is_minor'])) {
            $gEmail = trim((string)$r['guardian_email']);
            $gPhone = trim((string)$r['guardian_phone']);
            $es = '';
            if ($gEmail === '' || !filter_var($gEmail, FILTER_VALIDATE_EMAIL)) $es = 'brak poprawnego adresu e-mail opiekuna';
            elseif ($r['parent_notify_messages'] !== null && (int)$r['parent_notify_messages'] === 0) $es = 'opiekun wyłączył powiadomienia e-mail';
            $ss = '';
            if (!$smsReady)                                                          $ss = 'kanał SMS nie jest skonfigurowany';
            elseif ($gPhone === '')                                                  $ss = 'brak numeru telefonu opiekuna';
            elseif ($r['parent_notify_lessons'] !== null && (int)$r['parent_notify_lessons'] === 0) $ss = 'opiekun wyłączył powiadomienia SMS';
            $out[] = ['kind' => 'opiekun', 'name' => trim((string)$r['guardian_name']), 'email' => $gEmail,
                      'phones' => $gPhone !== '' ? [$gPhone] : [], 'email_skip' => $es, 'sms_skip' => $ss];
        }
        return $out;
    }

    /**
     * Wysyła powiadomienie o przypisaniu licencji.
     *
     * Domyślnie tylko raz (gdy notified_at puste); $force = ponowna wysyłka.
     * 'ok' = wysłano co najmniej jedną wiadomość; 'msg' = opis do pokazania użytkownikowi.
     *
     * @param list<string> $channels 'email' i/lub 'sms'
     * @return array{ok:bool,sent:array{email:int,sms:int},failed:array{email:int,sms:int},skipped:list<array{who:string,channel:string,reason:string}>,msg:string}
     */
    public static function send(int $assignmentId, array $channels = ['email', 'sms'], ?int $by = null, bool $force = false): array
    {
        ti_licencje_migrate();
        $res = ['ok' => false, 'sent' => ['email' => 0, 'sms' => 0], 'failed' => ['email' => 0, 'sms' => 0], 'skipped' => [], 'msg' => ''];
        $channels = array_values(array_intersect(['email', 'sms'], $channels));
        $a = db_one(
            "SELECT cl.*, l.name AS software, l.vendor, c.name AS client_name
               FROM k30_ti_client_licenses cl
               JOIN k30_ti_licenses l ON l.id = cl.license_id
               JOIN k30_clients c ON c.id = cl.client_id
              WHERE cl.id = ?",
            [$assignmentId]
        );
        if (!$a)                        { $res['msg'] = 'Nie znaleziono przypisania.'; return $res; }
        if ($a['status'] === 'revoked') { $res['msg'] = 'Licencja jest unieważniona — powiadomienia nie wysłano.'; return $res; }
        if (!$channels)                 { $res['msg'] = 'Nie wybrano żadnego kanału powiadomienia.'; return $res; }
        if (!$force && !empty($a['notified_at'])) {
            $res['msg'] = 'O tym przypisaniu powiadomiono już ' . substr((string)$a['notified_at'], 0, 16) . '.';
            return $res;
        }

        $seenEmail = []; $seenPhone = [];
        foreach (self::recipients((int)$a['client_id']) as $rc) {
            $tpl = $rc['kind'] === 'opiekun' ? 'ti_license_assigned_guardian' : 'ti_license_assigned';

            if (in_array('email', $channels, true)) {
                $to = $rc['email'];
                if ($rc['email_skip'] !== '') {
                    self::note($res, $assignmentId, 'email', $rc, self::maskEmail($to), 'skipped', $rc['email_skip'], $by);
                } elseif (isset($seenEmail[strtolower($to)])) {
                    // ten sam adres co poprzedni odbiorca (np. rodzic = kursant) — jedna wiadomość wystarczy
                } elseif (ti_virtual_blocked($to)) {
                    self::note($res, $assignmentId, 'email', $rc, self::maskEmail($to), 'skipped', 'kursant wirtualny — bez wiadomości', $by);
                } else {
                    $seenEmail[strtolower($to)] = true;
                    $r = email_tpl_render($tpl, self::emailVars($a, $rc));
                    if (empty($r['enabled'])) {
                        self::note($res, $assignmentId, 'email', $rc, self::maskEmail($to), 'skipped', 'szablon e-mail jest wyłączony', $by);
                    } else {
                        try {
                            $mid = mail_queue_add($to, $rc['name'], $r['subject'], $r['html'], '', 'ti_license_assigned', $assignmentId, '', false);
                            if ($mid > 0) self::note($res, $assignmentId, 'email', $rc, self::maskEmail($to), 'sent', '', $by);
                            else          self::note($res, $assignmentId, 'email', $rc, self::maskEmail($to), 'skipped', 'zablokowane (kontakt kursanta wirtualnego)', $by);
                        } catch (\Throwable $e) {
                            self::note($res, $assignmentId, 'email', $rc, self::maskEmail($to), 'failed', $e->getMessage(), $by);
                        }
                    }
                }
            }

            if (in_array('sms', $channels, true)) {
                if ($rc['sms_skip'] !== '') {
                    self::note($res, $assignmentId, 'sms', $rc, '', 'skipped', $rc['sms_skip'], $by);
                } else {
                    $r = sms_tpl_render($tpl, self::smsVars($a, $rc));
                    if (empty($r['enabled']) || trim($r['message']) === '') {
                        self::note($res, $assignmentId, 'sms', $rc, '', 'skipped', 'szablon SMS jest wyłączony', $by);
                    } else {
                        foreach ($rc['phones'] as $phone) {
                            $key = substr((string)preg_replace('/\D/', '', $phone), -9);
                            if (isset($seenPhone[$key])) continue;
                            $seenPhone[$key] = true;
                            if (ti_virtual_blocked('', $phone)) {
                                self::note($res, $assignmentId, 'sms', $rc, self::maskPhone($phone), 'skipped', 'kursant wirtualny — bez wiadomości', $by);
                                continue;
                            }
                            try {
                                sms_send($phone, $r['message'], $tpl);
                                self::note($res, $assignmentId, 'sms', $rc, self::maskPhone($phone), 'sent', '', $by);
                            } catch (\Throwable $e) {
                                self::note($res, $assignmentId, 'sms', $rc, self::maskPhone($phone), 'failed', $e->getMessage(), $by);
                            }
                        }
                    }
                }
            }
        }

        $total = $res['sent']['email'] + $res['sent']['sms'];
        if ($total > 0) db_exec("UPDATE k30_ti_client_licenses SET notified_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $assignmentId]);
        $res['ok']  = $total > 0;
        $res['msg'] = self::summary($res, $channels);
        return $res;
    }

    /** Opis wyniku po polsku: „E-mail: wysłano 2. SMS: pominięto (powód).” */
    private static function summary(array $res, array $channels): string
    {
        $parts = [];
        foreach (self::CHANNELS as $ch => $label) {
            if (!in_array($ch, $channels, true)) continue;
            $bits = [];
            if ($res['sent'][$ch] > 0)   $bits[] = 'wysłano ' . $res['sent'][$ch];
            if ($res['failed'][$ch] > 0) $bits[] = 'błąd wysyłki ' . $res['failed'][$ch];
            $why = [];
            foreach ($res['skipped'] as $s) if ($s['channel'] === $ch) $why[$s['reason']] = true;
            if ($why) $bits[] = 'pominięto (' . implode('; ', array_keys($why)) . ')';
            if ($bits) $parts[] = $label . ': ' . implode(', ', $bits) . '.';
        }
        return $parts ? implode(' ', $parts) : 'Nie wysłano żadnego powiadomienia.';
    }

    /** Zapisuje próbę w dzienniku i aktualizuje liczniki wyniku. */
    private static function note(array &$res, int $assignmentId, string $channel, array $rc, string $to, string $status, string $detail, ?int $by): void
    {
        db()->prepare(
            "INSERT INTO k30_ti_license_notifications (assignment_id, channel, recipient_kind, recipient, status, detail, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$assignmentId, $channel, $rc['kind'], $to, $status, mb_substr($detail, 0, 300), $by, date('Y-m-d H:i:s')]);
        if ($status === 'sent')        $res['sent'][$channel]++;
        elseif ($status === 'failed')  $res['failed'][$channel]++;
        else                           $res['skipped'][] = ['who' => $rc['kind'], 'channel' => $channel, 'reason' => $detail];
    }

    /** Wspólne dane do szablonów (wartości surowe, bez HTML). */
    private static function common(array $a): array
    {
        $issued = trim((string)($a['issued_at'] ?? ''));
        if ($issued === '') $issued = substr((string)$a['assigned_at'], 0, 10);
        $exp = trim((string)($a['expires_at'] ?? ''));
        return [
            'org'      => defined('ORG_NAME') ? (string)ORG_NAME : 'Dydaktyka TI',
            'student'  => (string)$a['client_name'],
            'software' => (string)$a['software'],
            'vendor'   => trim((string)($a['vendor'] ?? '')),
            'issued'   => date('d.m.Y', strtotime($issued)),
            'expires'  => $exp !== '' ? date('d.m.Y', strtotime($exp)) : '',
            'secret'   => trim((string)$a['login']) !== '' || trim((string)$a['access_key']) !== '',
        ];
    }

    /** Zmienne szablonu e-mail (wartości już zabezpieczone przed HTML). */
    private static function emailVars(array $a, array $rc): array
    {
        $c        = self::common($a);
        $guardian = $rc['kind'] === 'opiekun';
        $name     = $guardian ? $rc['name'] : (string)(explode(' ', trim($rc['name']))[0] ?? '');
        $base     = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        $td       = 'padding:8px 12px;border:1px solid #e2e8f0';
        return [
            'org'         => h($c['org']),
            'greeting'    => $name !== '' ? ' ' . h($name) : '',
            'student'     => h($c['student']),
            'software'    => h($c['software']),
            'vendor_row'  => $c['vendor'] !== ''
                ? '<tr><td style="' . $td . ';background:#f8fafc"><strong>Producent</strong></td><td style="' . $td . '">' . h($c['vendor']) . '</td></tr>'
                : '',
            'issued'      => h($c['issued']),
            'expires'     => h($c['expires'] !== '' ? $c['expires'] : 'bezterminowo'),
            'secret_note' => $c['secret']
                ? 'Login i klucz licencyjny są dostępne wyłącznie w panelu — ze względów bezpieczeństwa nie przesyłamy ich e-mailem.'
                : '',
            'url'         => h($base . ($guardian ? '/karty30/ti/kursant/login.php' : '/karty30/ti/kursant/index.php?tab=licencje')),
        ];
    }

    /** Zmienne szablonu SMS (bez polskich znaków — bramki SMS). */
    private static function smsVars(array $a, array $rc): array
    {
        $c = self::common($a);
        return [
            'org'      => self::ascii($c['org']),
            'student'  => self::ascii($c['student']),
            'software' => self::ascii($c['software']),
            'expires'  => $c['expires'] !== '' ? ' (wazna do ' . $c['expires'] . ')' : '',
        ];
    }

    private static function ascii(string $s): string
    {
        return strtr($s, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
                          'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z']);
    }

    public static function maskEmail(string $e): string
    {
        if ($e === '' || !str_contains($e, '@')) return '';
        [$user, $domain] = explode('@', $e, 2);
        return mb_substr($user, 0, 1) . '***@' . $domain;
    }

    public static function maskPhone(string $p): string
    {
        $digits = (string)preg_replace('/\D/', '', $p);
        return $digits === '' ? '' : '***' . substr($digits, -3);
    }
}
