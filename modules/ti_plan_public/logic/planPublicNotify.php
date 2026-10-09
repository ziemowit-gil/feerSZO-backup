<?php
/**
 * modules/ti_plan_public/logic/planPublicNotify.php — wysyłka publicznego linku do planu grupy
 * do kursantów (i opiekunów małoletnich) e-mailem / SMS-em.
 *
 * Odbiorcy: aktywne zapisy grupy (k30_ti_enrollments.status='active'); dla każdego kursanta
 * te same reguły zgody i kontaktu co przy licencjach — TiLicencjeNotifier::recipients()
 * (kursant: e-mail chyba że wyłączył „e-mail od dydaktyki”, SMS tylko gdy włączył „SMS od
 * dydaktyki”; opiekun małoletniego: wg parent_notify_*). Kursant wirtualny nic nie dostaje.
 * Szablony edytowalne w panelu: ti_plan_link (SMS i e-mail). Rodzaj SMS = klucz szablonu.
 * Każda próba trafia do k30_ti_plan_public_sends; link dostaje notified_at.
 */
require_once __DIR__ . '/planPublicManager.php';
require_once dirname(__DIR__, 3) . '/modules/ti_licencje/logic/tiLicencjeNotify.php';

final class TiPlanPublicNotifier
{
    public const TEMPLATE = 'ti_plan_link';
    public const CHANNELS = ['email' => 'E-mail', 'sms' => 'SMS'];

    /**
     * Odbiorcy dla grupy: lista [client_id, kind, name, email, phones, email_skip, sms_skip].
     * @return list<array<string,mixed>>
     */
    public static function recipients(int $courseId): array
    {
        $rows = db_all(
            "SELECT e.client_id FROM k30_ti_enrollments e JOIN k30_clients c ON c.id = e.client_id
              WHERE e.course_id = ? AND e.status = 'active' ORDER BY c.name",
            [$courseId]
        );
        $out = [];
        foreach ($rows as $r) {
            foreach (TiLicencjeNotifier::recipients((int)$r['client_id']) as $rc) {
                $rc['client_id'] = (int)$r['client_id'];
                $out[] = $rc;
            }
        }
        return $out;
    }

    /** Podsumowanie odbiorców do pokazania przed wysyłką: ilu dostanie e-mail / SMS, ilu pominiętych i czemu. */
    public static function preview(int $courseId): array
    {
        $p = ['students' => 0, 'email_ok' => 0, 'sms_ok' => 0, 'email_skip' => [], 'sms_skip' => []];
        $seen = [];
        foreach (self::recipients($courseId) as $rc) {
            if ($rc['kind'] === 'kursant') { $seen[$rc['client_id']] = true; }
            if ($rc['email_skip'] === '') $p['email_ok']++; else $p['email_skip'][$rc['email_skip']] = ($p['email_skip'][$rc['email_skip']] ?? 0) + 1;
            if ($rc['sms_skip'] === '')   $p['sms_ok']++;   else $p['sms_skip'][$rc['sms_skip']]     = ($p['sms_skip'][$rc['sms_skip']] ?? 0) + 1;
        }
        $p['students'] = count($seen);
        return $p;
    }

    /**
     * Wysyła link do planu wszystkim odbiorcom grupy wybranymi kanałami.
     * @param list<string> $channels 'email' i/lub 'sms'
     * @return array{ok:bool,sent:array{email:int,sms:int},failed:array{email:int,sms:int},skipped:list<array{who:string,channel:string,reason:string}>,msg:string}
     */
    public static function send(int $courseId, array $channels, ?int $by = null, string $extraNote = ''): array
    {
        tiPlanPublicMigrate();
        $res = ['ok' => false, 'sent' => ['email' => 0, 'sms' => 0], 'failed' => ['email' => 0, 'sms' => 0], 'skipped' => [], 'msg' => ''];
        $channels = array_values(array_intersect(['email', 'sms'], $channels));
        $link = tiPlanPublicGet($courseId);
        if (!$link || (int)$link['is_enabled'] !== 1) { $res['msg'] = 'Plan tej grupy nie jest opublikowany — najpierw włącz link.'; return $res; }
        if (!$channels) { $res['msg'] = 'Nie wybrano żadnego kanału wysyłki.'; return $res; }
        $course = db_one("SELECT c.name, COALESCE(u.name,'') AS iname FROM k30_ti_courses c LEFT JOIN users u ON u.id = c.instructor_id WHERE c.id = ?", [$courseId]);
        if (!$course) { $res['msg'] = 'Nie znaleziono grupy.'; return $res; }

        $batch = date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $url   = tiPlanPublicUrl($link);
        $short = tiPlanPublicShortUrl($link);
        $seenEmail = []; $seenPhone = [];

        foreach (self::recipients($courseId) as $rc) {
            if (in_array('email', $channels, true)) {
                $to = $rc['email'];
                if ($rc['email_skip'] !== '') {
                    self::note($res, $courseId, $batch, 'email', $rc, TiLicencjeNotifier::maskEmail($to), 'skipped', $rc['email_skip'], $by);
                } elseif (isset($seenEmail[strtolower($to)])) {
                    // ten sam adres co poprzedni odbiorca (np. rodzic = kursant) — jedna wiadomość
                } elseif (ti_virtual_blocked($to)) {
                    self::note($res, $courseId, $batch, 'email', $rc, TiLicencjeNotifier::maskEmail($to), 'skipped', 'kursant wirtualny — bez wiadomości', $by);
                } else {
                    $seenEmail[strtolower($to)] = true;
                    $r = email_tpl_render(self::TEMPLATE, self::emailVars($course, $rc, $url, $extraNote));
                    if (empty($r['enabled'])) {
                        self::note($res, $courseId, $batch, 'email', $rc, TiLicencjeNotifier::maskEmail($to), 'skipped', 'szablon e-mail jest wyłączony', $by);
                    } else {
                        try {
                            $mid = mail_queue_add($to, $rc['name'], $r['subject'], $r['html'], '', self::TEMPLATE, $courseId, '', false);
                            if ($mid > 0) self::note($res, $courseId, $batch, 'email', $rc, TiLicencjeNotifier::maskEmail($to), 'sent', '', $by);
                            else          self::note($res, $courseId, $batch, 'email', $rc, TiLicencjeNotifier::maskEmail($to), 'skipped', 'zablokowane (kontakt kursanta wirtualnego)', $by);
                        } catch (\Throwable $e) {
                            self::note($res, $courseId, $batch, 'email', $rc, TiLicencjeNotifier::maskEmail($to), 'failed', $e->getMessage(), $by);
                        }
                    }
                }
            }

            if (in_array('sms', $channels, true)) {
                if ($rc['sms_skip'] !== '') {
                    self::note($res, $courseId, $batch, 'sms', $rc, '', 'skipped', $rc['sms_skip'], $by);
                    continue;
                }
                $r = sms_tpl_render(self::TEMPLATE, self::smsVars($course, $rc, $short ?: $url));
                if (empty($r['enabled']) || trim($r['message']) === '') {
                    self::note($res, $courseId, $batch, 'sms', $rc, '', 'skipped', 'szablon SMS jest wyłączony', $by);
                    continue;
                }
                foreach ($rc['phones'] as $phone) {
                    $key = substr((string)preg_replace('/\D/', '', $phone), -9);
                    if (isset($seenPhone[$key])) continue;
                    $seenPhone[$key] = true;
                    if (ti_virtual_blocked('', $phone)) {
                        self::note($res, $courseId, $batch, 'sms', $rc, TiLicencjeNotifier::maskPhone($phone), 'skipped', 'kursant wirtualny — bez wiadomości', $by);
                        continue;
                    }
                    try {
                        sms_send($phone, $r['message'], 'ti_plan_link');
                        self::note($res, $courseId, $batch, 'sms', $rc, TiLicencjeNotifier::maskPhone($phone), 'sent', '', $by);
                    } catch (\Throwable $e) {
                        self::note($res, $courseId, $batch, 'sms', $rc, TiLicencjeNotifier::maskPhone($phone), 'failed', $e->getMessage(), $by);
                    }
                }
            }
        }

        $total = $res['sent']['email'] + $res['sent']['sms'];
        if ($total > 0) db()->prepare("UPDATE k30_ti_plan_public_links SET notified_at = datetime('now') WHERE course_id = ?")->execute([$courseId]);
        $res['ok']  = $total > 0;
        $res['msg'] = self::summary($res, $channels);
        return $res;
    }

    /** Ostatnie wysyłki grupy (zgrupowane po partii): [batch, created_at, created_by, sent, failed, skipped]. */
    public static function history(int $courseId, int $limit = 5): array
    {
        tiPlanPublicMigrate();
        return db_all(
            "SELECT batch, MIN(created_at) AS created_at, MAX(created_by) AS created_by,
                    SUM(status='sent') AS sent, SUM(status='failed') AS failed, SUM(status='skipped') AS skipped,
                    SUM(status='sent' AND channel='email') AS sent_email, SUM(status='sent' AND channel='sms') AS sent_sms
               FROM k30_ti_plan_public_sends WHERE course_id = ?
              GROUP BY batch ORDER BY MIN(created_at) DESC LIMIT " . (int)$limit,
            [$courseId]
        );
    }

    private static function summary(array $res, array $channels): string
    {
        $parts = [];
        foreach (self::CHANNELS as $ch => $label) {
            if (!in_array($ch, $channels, true)) continue;
            $bits = [];
            if ($res['sent'][$ch] > 0)   $bits[] = 'wysłano ' . $res['sent'][$ch];
            if ($res['failed'][$ch] > 0) $bits[] = 'błąd wysyłki ' . $res['failed'][$ch];
            $why = [];
            foreach ($res['skipped'] as $s) if ($s['channel'] === $ch) $why[$s['reason']] = ($why[$s['reason']] ?? 0) + 1;
            if ($why) { $w = []; foreach ($why as $reason => $n) $w[] = $reason . ' (' . $n . ')'; $bits[] = 'pominięto: ' . implode('; ', $w); }
            if ($bits) $parts[] = $label . ': ' . implode(', ', $bits) . '.';
        }
        return $parts ? implode(' ', $parts) : 'Nie wysłano żadnej wiadomości — grupa nie ma aktywnych zapisów.';
    }

    private static function note(array &$res, int $courseId, string $batch, string $channel, array $rc, string $to, string $status, string $detail, ?int $by): void
    {
        db()->prepare(
            "INSERT INTO k30_ti_plan_public_sends (course_id, batch, channel, recipient_kind, recipient_name, recipient, status, detail, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        )->execute([$courseId, $batch, $channel, $rc['kind'], (string)$rc['name'], $to, $status, mb_substr($detail, 0, 300), $by, date('Y-m-d H:i:s')]);
        if ($status === 'sent')        $res['sent'][$channel]++;
        elseif ($status === 'failed')  $res['failed'][$channel]++;
        else                           $res['skipped'][] = ['who' => $rc['kind'], 'channel' => $channel, 'reason' => $detail];
    }

    private static function emailVars(array $course, array $rc, string $url, string $extraNote): array
    {
        $guardian = $rc['kind'] === 'opiekun';
        $name     = $guardian ? $rc['name'] : (string)(explode(' ', trim((string)$rc['name']))[0] ?? '');
        return [
            'org'        => h(defined('ORG_NAME') ? (string)ORG_NAME : 'Dydaktyka TI'),
            'greeting'   => $name !== '' ? ' ' . h($name) : '',
            'group'      => h((string)$course['name']),
            'instructor' => h((string)$course['iname'] !== '' ? (string)$course['iname'] : '—'),
            'url'        => h($url),
            'note_block' => trim($extraNote) !== '' ? '<p style="background:#fffbeb;border-left:4px solid #f59e0b;padding:8px 12px">' . nl2br(h($extraNote)) . '</p>' : '',
        ];
    }

    private static function smsVars(array $course, array $rc, string $url): array
    {
        $ascii = fn(string $s) => strtr($s, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
                                             'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z']);
        return [
            'org'   => $ascii(defined('ORG_NAME') ? (string)ORG_NAME : 'Dydaktyka TI'),
            'group' => $ascii((string)$course['name']),
            'url'   => $url,
        ];
    }
}
