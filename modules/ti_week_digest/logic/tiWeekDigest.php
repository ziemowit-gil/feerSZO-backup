<?php
/**
 * modules/ti_week_digest/logic/tiWeekDigest.php — poniedziałkowa wiadomość „Zajęcia w tym tygodniu” (e-mail + SMS).
 *
 * Co poniedziałek rano (agent CRON ti_week_digest, okno 7:00–8:00 — po ciszy nocnej SMS) każdy uczestnik z aktywnym
 * kontem i aktywnym zapisem dostaje jedną wiadomość z WSZYSTKIMI swoimi zajęciami od poniedziałku do niedzieli
 * (status „zaplanowana”, bez wersji roboczych i bez lekcji, z których sam się wypisał). Małoletni — także opiekun.
 *  • SMS: tylko osoby z włączonym „SMS o lekcjach” (+ opiekun małoletniego z parent_notify_lessons), numery jak w
 *    ti_lesson_sms_notify (k30_ti_sms_numbers: dodatkowe tylko zweryfikowane); treść = szablon SMS ti_week_digest.
 *  • E-mail: adres kursanta + adres opiekuna małoletniego; kolejka maili (kontekst ti_week_digest).
 * Idempotentne: dziennik k30_ti_week_digest_log (uczestnik × poniedziałek × kanał) — ponowny przebieg niczego nie dubluje.
 * Mechanizm jest domyślnie WYŁĄCZONY (ustawienie ti_week_digest_enabled); kanały osobno (ti_week_digest_sms / _email, domyślnie włączone
 * po włączeniu całości). Ekran: karty30/ti/dydaktyk/tygodniowaWysylka.php.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';

final class TiWeekDigest
{
    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('ti_week_digest_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_week_digest_log (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id  INTEGER NOT NULL,
            week_start TEXT    NOT NULL,
            channel    TEXT    NOT NULL,
            lessons    INTEGER NOT NULL DEFAULT 0,
            sent_by    TEXT    NOT NULL DEFAULT 'cron',
            sent_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(client_id, week_start, channel)
        )");
    }

    /** @return array{enabled:bool, sms:bool, email:bool} — odczyt wprost z settings (bez pamięci podręcznej). */
    public static function settings(): array
    {
        $g = fn(string $k, string $def) => (string)(db_one("SELECT value FROM settings WHERE key_=?", [$k])['value'] ?? $def);
        $on = $g('ti_week_digest_enabled', '0') === '1';
        return ['enabled' => $on, 'sms' => $g('ti_week_digest_sms', '1') === '1', 'email' => $g('ti_week_digest_email', '1') === '1'];
    }

    public static function saveSettings(array $in): void
    {
        foreach (['ti_week_digest_enabled' => 'enabled', 'ti_week_digest_sms' => 'sms', 'ti_week_digest_email' => 'email'] as $k => $f) {
            org_setting_set($k, !empty($in[$f]) ? '1' : '0');
        }
    }

    /** Poniedziałek tygodnia zawierającego $today. */
    public static function weekStart(string $today): string { return date('Y-m-d', strtotime('monday this week', strtotime($today))); }

    private static function ascii(string $s): string
    {
        return strtr($s, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z','Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z','–'=>'-','—'=>'-']);
    }

    /** Uczestnicy z aktywnym kontem i aktywnym zapisem (+ dane kontaktowe i zgody). */
    public static function students(?int $clientId = null): array
    {
        return db_all(
            "SELECT a.client_id, a.is_minor, a.guardian_email, a.guardian_phone, a.notify_sms_lessons, a.parent_notify_lessons,
                    a.notify_phone2, a.notify_phone2_verified, a.notify_phone3, a.notify_phone3_verified, cl.name, cl.email, cl.phone
               FROM k30_ti_student_accounts a JOIN k30_clients cl ON cl.id = a.client_id
              WHERE a.is_active = 1 AND EXISTS (SELECT 1 FROM k30_ti_enrollments e WHERE e.client_id = a.client_id AND e.status = 'active')"
            . ($clientId ? ' AND a.client_id = ' . (int)$clientId : '') . " ORDER BY cl.name"
        );
    }

    /** Zajęcia uczestnika w tygodniu (zaplanowane, bez wypisanych), posortowane. */
    public static function lessons(int $clientId, string $from, string $to): array
    {
        return db_all(
            "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.lesson_method, c.name AS course_name
               FROM k30_ti_enrollments e
               JOIN k30_ti_courses c  ON c.id = e.course_id AND c.status NOT IN ('cancelled','archived')
               JOIN k30_ti_sessions s ON s.course_id = c.id AND s.status = 'planned' AND s.draft_id IS NULL AND s.lesson_date BETWEEN ? AND ?
               LEFT JOIN k30_ti_attendance a ON a.session_id = s.id AND a.client_id = e.client_id
              WHERE e.client_id = ? AND e.status = 'active' AND COALESCE(a.cancelled, 0) = 0
              ORDER BY s.lesson_date, s.time_from, c.name",
            [$from, $to, $clientId]
        );
    }

    /** „26.10–01.11” (zakres tygodnia do SMS/tematu). */
    public static function rangeLabel(string $from, string $to): string { return date('d.m', strtotime($from)) . '-' . date('d.m', strtotime($to)); }

    /** Plan do SMS (ASCII, GSM-7): „Pn 26.10 10:00-11:00 Kurs; Wt 27.10 …”, do ~230 znaków, reszta „+N”. */
    public static function smsPlan(array $lessons): string
    {
        static $d = [1 => 'Pn', 2 => 'Wt', 3 => 'Sr', 4 => 'Czw', 5 => 'Pt', 6 => 'Sb', 0 => 'Nd'];
        $parts = [];
        foreach ($lessons as $l) {
            $tf = substr((string)$l['time_from'], 0, 5); $tt = substr((string)$l['time_to'], 0, 5);
            $parts[] = ($d[(int)date('w', strtotime((string)$l['lesson_date']))] ?? '?') . ' ' . date('d.m', strtotime((string)$l['lesson_date']))
                     . ($tf !== '' ? ' ' . $tf . ($tt !== '' ? '-' . $tt : '') : '') . ' ' . self::ascii(mb_substr((string)$l['course_name'], 0, 22));
        }
        $out = ''; $n = 0;
        foreach ($parts as $i => $p) {
            $cand = $out === '' ? $p : $out . '; ' . $p;
            if (strlen($cand) > 230 && $n > 0) { $out .= ' +' . (count($parts) - $n); break; }
            $out = $cand; $n++;
        }
        return $out;
    }

    /** E-mail HTML z planem tygodnia (po dniach). */
    public static function emailHtml(string $name, array $lessons, string $from, string $to): string
    {
        static $dn = [1 => 'Poniedziałek', 2 => 'Wtorek', 3 => 'Środa', 4 => 'Czwartek', 5 => 'Piątek', 6 => 'Sobota', 0 => 'Niedziela'];
        $esc = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
        $by = [];
        foreach ($lessons as $l) $by[(string)$l['lesson_date']][] = $l;
        $rows = '';
        foreach ($by as $date => $ls) {
            $rows .= '<tr><td colspan="3" style="background:#f2f2f2;padding:6px 10px;font-weight:bold">' . $esc(($dn[(int)date('w', strtotime($date))] ?? '') . ', ' . date('d.m.Y', strtotime($date))) . '</td></tr>';
            foreach ($ls as $l) {
                $tf = substr((string)$l['time_from'], 0, 5); $tt = substr((string)$l['time_to'], 0, 5);
                $meth = ['zdalna_zoom' => 'online (Zoom)', 'zdalna_inne' => 'online', 'stacjonarna' => 'stacjonarnie'][(string)($l['lesson_method'] ?? '')] ?? '';
                $rows .= '<tr><td style="padding:5px 10px;white-space:nowrap">' . $esc($tf . ($tt !== '' ? '–' . $tt : '')) . '</td><td style="padding:5px 10px">' . $esc((string)$l['course_name']) . '</td>'
                       . '<td style="padding:5px 10px;color:#555">' . $esc($meth) . '</td></tr>';
            }
        }
        $url = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php?tab=lekcje';
        $org = defined('ORG_NAME') ? ORG_NAME : 'TI';
        $n = count($lessons);
        return '<p>Dzień dobry' . ($name !== '' ? ', ' . $esc(explode(' ', trim($name))[0]) : '') . ',</p>'
             . '<p>Zajęcia w tym tygodniu (' . $esc(date('d.m', strtotime($from)) . '–' . date('d.m.Y', strtotime($to))) . '): <strong>' . $n . '</strong>.</p>'
             . '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;border:1px solid #ddd;min-width:320px">' . $rows . '</table>'
             . '<p>Ewentualne zmiany i odwołania zajęć znajdziesz w <a href="' . $esc($url) . '">panelu uczestnika</a>.</p>'
             . '<p style="color:#888;font-size:12px">Wiadomość automatyczna z systemu ' . $esc($org) . '. Wysyłana w poniedziałki rano.</p>';
    }

    private static function logged(int $client, string $week, string $channel): bool
    {
        return (bool)db_one("SELECT 1 FROM k30_ti_week_digest_log WHERE client_id=? AND week_start=? AND channel=?", [$client, $week, $channel]);
    }
    private static function log(int $client, string $week, string $channel, int $lessons, string $by): void
    {
        db()->prepare("INSERT OR IGNORE INTO k30_ti_week_digest_log (client_id, week_start, channel, lessons, sent_by) VALUES (?,?,?,?,?)")->execute([$client, $week, $channel, $lessons, $by]);
    }

    /**
     * Przebieg. Opcje: 'today' (domyślnie dziś), 'dry_run', 'client_id', 'by' (podpis w dzienniku), 'only_monday' (cron: poza poniedziałkiem nic).
     * @return array{week:string, students:int, sent:array{email:int,sms:int}, skipped:array<string,int>, failed:int, errors:string[], preview:array}
     */
    public static function run(array $o = []): array
    {
        self::migrate();
        $o += ['today' => date('Y-m-d'), 'dry_run' => false, 'client_id' => null, 'by' => 'cron', 'only_monday' => false];
        $res = ['week' => '', 'students' => 0, 'sent' => ['email' => 0, 'sms' => 0], 'skipped' => ['nie_poniedzialek' => 0, 'wylaczone' => 0, 'brak_zajec' => 0, 'juz_wyslane' => 0, 'brak_kontaktu' => 0],
                'failed' => 0, 'errors' => [], 'preview' => []];
        $set = self::settings();
        if (!$set['enabled']) { $res['skipped']['wylaczone'] = 1; return $res; }
        if ($o['only_monday'] && (int)date('N', strtotime($o['today'])) !== 1) { $res['skipped']['nie_poniedzialek'] = 1; return $res; }
        $from = self::weekStart($o['today']); $to = date('Y-m-d', strtotime($from . ' +6 days'));
        $res['week'] = $from;
        if (!function_exists('mail_queue_add')) require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        require_once dirname(__DIR__, 3) . '/includes/ti_reschedule.php';   // _k30_ti_client_emails()
        require_once dirname(__DIR__, 3) . '/includes/sms.php';
        require_once dirname(__DIR__, 3) . '/includes/sms_templates.php';
        $org = defined('ORG_NAME') ? ORG_NAME : 'TI';
        $range = self::rangeLabel($from, $to);

        foreach (self::students($o['client_id'] ? (int)$o['client_id'] : null) as $s) {
            $res['students']++;
            $cid = (int)$s['client_id'];
            $ls = self::lessons($cid, $from, $to);
            if (!$ls) { $res['skipped']['brak_zajec']++; continue; }
            $row = ['client_id' => $cid, 'name' => (string)$s['name'], 'lessons' => count($ls), 'email' => 0, 'sms' => 0];

            if ($set['email']) {
                $emails = _k30_ti_client_emails($cid);
                if (!$emails) $res['skipped']['brak_kontaktu']++;
                elseif (self::logged($cid, $from, 'email')) $res['skipped']['juz_wyslane']++;
                else {
                    $row['email'] = count($emails);
                    if (!$o['dry_run']) {
                        try {
                            $html = self::emailHtml((string)$s['name'], $ls, $from, $to);
                            foreach ($emails as $addr => $nm) { mail_queue_add($addr, (string)$nm, "{$org}: zajęcia w tym tygodniu ({$range})", $html, '', 'ti_week_digest', $cid); $res['sent']['email']++; }
                            self::log($cid, $from, 'email', count($ls), (string)$o['by']);
                        } catch (\Throwable $e) { $res['failed']++; $res['errors'][] = 'e-mail #' . $cid . ': ' . $e->getMessage(); }
                    }
                }
            }
            if ($set['sms']) {
                $nums = [];
                if (!empty($s['notify_sms_lessons'])) $nums = k30_ti_sms_numbers($s);
                if (!empty($s['is_minor']) && !empty($s['parent_notify_lessons'])) {
                    $gp = trim((string)($s['guardian_phone'] ?? ''));
                    if ($gp !== '' && !in_array($gp, $nums, true)) $nums[] = $gp;
                }
                if (!$nums) $res['skipped']['brak_kontaktu']++;
                elseif (self::logged($cid, $from, 'sms')) $res['skipped']['juz_wyslane']++;
                else {
                    $row['sms'] = count($nums);
                    if (!$o['dry_run']) {
                        $t = sms_tpl_render('ti_week_digest', ['range' => $range, 'plan' => self::smsPlan($ls)]);
                        if (!empty($t['enabled']) && trim((string)$t['message']) !== '') {
                            $sent = 0;
                            foreach ($nums as $num) {
                                try { sms_send($num, (string)$t['message'], 'ti_week_digest'); $sent++; }
                                catch (\Throwable $e) { $res['failed']++; $res['errors'][] = 'SMS #' . $cid . ': ' . $e->getMessage(); }
                            }
                            $res['sent']['sms'] += $sent;
                            if ($sent > 0) self::log($cid, $from, 'sms', count($ls), (string)$o['by']);
                        } else { $row['sms'] = 0; }   // szablon SMS wyłączony
                    }
                }
            }
            if ($row['email'] || $row['sms']) $res['preview'][] = $row;
        }
        return $res;
    }

    /** Ostatnie wysyłki (do ekranu): [data, uczestnik (maska), kanał, lekcje, kto]. */
    public static function recent(int $limit = 30): array
    {
        self::migrate();
        return db_all("SELECT l.sent_at, l.channel, l.lessons, l.sent_by, l.week_start, cl.name FROM k30_ti_week_digest_log l LEFT JOIN k30_clients cl ON cl.id = l.client_id ORDER BY l.id DESC LIMIT " . (int)$limit);
    }
}
