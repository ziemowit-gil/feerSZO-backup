<?php
/**
 * modules/ti_lesson_control/logic/lessonControlNotifier.php — powiadomienia Kontroli Lekcji i Wpisów.
 *
 *  - remindInstructors()     — JEDEN zbiorczy e-mail dziennie na prowadzącego (+ opcjonalnie SMS, rodzaj
 *                              'ti_lesson_entry_reminder'); max ti_lc_remind_max przypomnień o jednej lekcji,
 *                              zaległości starsze niż ti_lc_remind_max_age_days pomijane; po ti_lc_escalate_after
 *                              przypomnieniach lekcja trafia do „eskalacji” w raporcie nadzorującego,
 *  - notifyReturned()        — e-mail po decyzji „do poprawy” (ti_lc_notify_on_return),
 *  - sendSupervisorReport()  — raport dzienny (za wczoraj) albo tygodniowy (za poprzedni tydzień), raz na okres.
 * Wszystkie kanały domyślnie WYŁĄCZONE (LessonControlSettings). Tryb $dryRun: zero zapisów i wysyłek,
 * zwraca to, co by wyszło. Szablony: LessonControlMailTemplates (HTML ze stylami inline + tekst).
 */

require_once __DIR__ . '/lessonControlService.php';

final class LessonControlMailTemplates
{
    public static function panelUrl(string $query = ''): string {
        return (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/dydaktyk/kontrolaWpisow.php' . ($query !== '' ? '?' . $query : '');
    }

    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
    private static function org(): string { return defined('ORG_NAME') ? (string)ORG_NAME : 'System'; }

    private static function badge(string $status): string {
        $c = ['success' => ['#dcfce7', '#166534'], 'warning' => ['#fef3c7', '#92400e'], 'danger' => ['#fee2e2', '#991b1b'],
              'info' => ['#dbeafe', '#1e40af']][TiLcStatus::color($status)] ?? ['#e5e7eb', '#374151'];
        return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:' . $c[0] . ';color:' . $c[1]
             . ';font-size:12px;font-weight:600;white-space:nowrap">' . self::e(TiLcStatus::label($status)) . '</span>';
    }

    private static function layout(string $title, string $inner, string $ctaUrl, string $ctaLabel): string {
        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>' . self::e($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0"><tr><td align="center">'
            . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden">'
            . '<tr><td style="background:#1e3a8a;color:#ffffff;padding:18px 24px;font-size:18px;font-weight:bold">' . self::e($title) . '</td></tr>'
            . '<tr><td style="padding:24px;font-size:14px;line-height:1.5">' . $inner
            . '<p style="margin:24px 0 8px"><a href="' . self::e($ctaUrl) . '" style="display:inline-block;background:#1d4ed8;color:#ffffff;text-decoration:none;'
            . 'padding:10px 18px;border-radius:8px;font-weight:bold">' . self::e($ctaLabel) . '</a></p>'
            . '</td></tr><tr><td style="padding:14px 24px;background:#f9fafb;color:#6b7280;font-size:12px">Wiadomość automatyczna — Kontrola Lekcji i Wpisów, '
            . self::e(self::org()) . '. Nie odpowiadaj na nią; pytania kieruj do kierownika TI.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function th(string $t): string { return '<th align="left" style="padding:8px;border-bottom:2px solid #e5e7eb;font-size:12px;color:#374151">' . $t . '</th>'; }
    private static function td(string $t): string { return '<td style="padding:8px;border-bottom:1px solid #f3f4f6;vertical-align:top">' . $t . '</td>'; }

    private static function lessonWhen(array $i): string {
        $d = date('d.m.Y', strtotime((string)$i['lesson_date']));
        $t = trim(substr((string)$i['time_from'], 0, 5) . (($i['time_to'] ?? '') !== '' ? '–' . substr((string)$i['time_to'], 0, 5) : ''), '–');
        return $d . ($t !== '' ? ', ' . $t : '');
    }

    private static function missingLabels(array $i): string {
        $m = json_decode((string)($i['missing_json'] ?? '[]'), true) ?: [];
        return implode(', ', array_map(fn($x) => (string)($x['label'] ?? $x['code'] ?? ''), $m));
    }

    /**
     * Szablon A — przypomnienie / ostrzeżenie dla prowadzącego.
     * @param array $items wiersze k30_ti_lc_checks + course_name, time_from, time_to
     * @return array{subject:string,html:string,text:string}
     */
    public static function instructorReminder(string $name, array $items, array $ctx): array {
        $n = count($items);
        $oldest = $items ? min(array_column($items, 'lesson_date')) : date('Y-m-d');
        $subject = '[TI] Zaległe wpisy z zajęć: ' . $n . ' (najstarszy z ' . date('d.m.Y', strtotime($oldest)) . ')';
        $first = trim(explode(' ', trim($name))[0] ?? '') ?: 'Prowadząca/Prowadzący';

        $rows = ''; $txt = [];
        foreach ($items as $i) {
            $isReturn = ($i['review_status'] ?? '') === TiLcStatus::DO_POPRAWY;
            $status = $isReturn ? TiLcStatus::DO_POPRAWY : (string)$i['auto_status'];
            $what = $isReturn ? 'Uwaga audytora: „' . (string)$i['review_comment'] . '”' : self::missingLabels($i);
            $due  = $i['due_at'] ? date('d.m.Y, H:i', strtotime((string)$i['due_at'])) : '—';
            $rows .= '<tr>' . self::td(self::e(self::lessonWhen($i))) . self::td(self::e((string)$i['course_name'])) . self::td(self::badge($status))
                   . self::td(self::e($what)) . self::td(self::e($due)) . '</tr>';
            $txt[] = '- ' . self::lessonWhen($i) . ' | ' . $i['course_name'] . ' | ' . TiLcStatus::label($status) . ' | ' . $what . ' | termin: ' . $due;
        }
        $nr = (int)($ctx['reminder_no'] ?? 1); $esc = (int)($ctx['escalate_after'] ?? 2);
        $rule = (string)($ctx['deadline_label'] ?? 'do końca dnia zajęć');

        $inner = '<p>Dzień dobry ' . self::e($first) . ',</p>'
            . '<p>kontrola dziennika zajęć wykazała wpisy wymagające uzupełnienia:</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px">'
            . '<tr>' . self::th('Data i godzina') . self::th('Grupa') . self::th('Status') . self::th('Czego brakuje') . self::th('Termin minął') . '</tr>'
            . $rows . '</table>'
            . '<p style="margin-top:16px">Wpis należy dodać <strong>' . self::e($rule) . '</strong>. Kompletny wpis to temat zajęć, obecność'
            . ' oraz — jeśli grupa ma sylabus — zaznaczony zrealizowany materiał.</p>'
            . '<p style="color:#6b7280;font-size:12px">To przypomnienie nr ' . $nr . '. Po ' . $esc . ' przypomnieniach bez uzupełnienia'
            . ' informacja trafia do kierownika TI.</p>';
        $html = self::layout('Zaległe wpisy z zajęć', $inner, self::panelUrl(), 'Uzupełnij wpisy');

        $text = "Dzień dobry {$first},\n\nkontrola dziennika zajęć wykazała wpisy wymagające uzupełnienia:\n\n" . implode("\n", $txt)
              . "\n\nUzupełnij wpisy: " . self::panelUrl() . "\n\nWpis należy dodać {$rule}. Kompletny wpis to temat zajęć, obecność"
              . " oraz — jeśli grupa ma sylabus — zaznaczony zrealizowany materiał.\n"
              . "To przypomnienie nr {$nr}. Po {$esc} przypomnieniach bez uzupełnienia informacja trafia do kierownika TI.\n\n— Kontrola Lekcji i Wpisów, " . self::org();
        return compact('subject', 'html', 'text');
    }

    /**
     * Szablon B — raport z wyniku kontroli dla osoby nadzorującej.
     * @param array $r dane z LessonControlNotifier::reportData()
     * @return array{subject:string,html:string,text:string}
     */
    public static function supervisorReport(array $r): array {
        $a = $r['auto']; $v = $r['review'];
        $subject = '[TI] Kontrola wpisów ' . $r['period_label'] . ': ' . $r['pct_ok'] . '% prawidłowych, ' . $r['problems'] . ' do działania';
        $kpi = fn(string $label, int $n, string $color) => '<td align="center" style="padding:10px;border:1px solid #e5e7eb;border-radius:8px">'
            . '<div style="font-size:22px;font-weight:bold;color:' . $color . '">' . $n . '</div><div style="font-size:12px;color:#6b7280">' . $label . '</div></td>';

        $instr = ''; $instrTxt = [];
        foreach ($r['instructors'] as $i) {
            $instr .= '<tr>' . self::td(self::e($i['name'])) . self::td((string)$i['brak']) . self::td((string)$i['niepelne'])
                    . self::td((string)$i['opoznione']) . self::td($i['avg_delay_h'] !== null ? $i['avg_delay_h'] . ' h' : '—') . self::td((string)$i['reminders']) . '</tr>';
            $instrTxt[] = sprintf('- %s: braki %d, niepełne %d, opóźnione %d, śr. opóźnienie %s, przypomnienia %d', $i['name'], $i['brak'], $i['niepelne'],
                $i['opoznione'], $i['avg_delay_h'] !== null ? $i['avg_delay_h'] . ' h' : '—', $i['reminders']);
        }
        $list = function (array $items, string $empty) {
            if (!$items) return ['<p style="color:#6b7280">' . self::e($empty) . '</p>', ['  ' . $empty]];
            $h = '<ul style="padding-left:18px;margin:6px 0">'; $t = [];
            foreach ($items as $i) {
                $line = self::lessonWhen($i) . ' — ' . $i['course_name'] . ' (' . ($i['instructor_name'] ?: 'bez prowadzącego') . ')'
                      . (($i['review_comment'] ?? '') !== '' ? ': „' . $i['review_comment'] . '”' : '');
                $h .= '<li>' . self::e($line) . '</li>'; $t[] = '  - ' . $line;
            }
            return [$h . '</ul>', $t];
        };
        [$dispH, $dispT] = $list($r['disputed'], 'Brak zakwestionowanych wpisów.');
        [$escH, $escT]   = $list($r['escalations'], 'Brak eskalacji.');

        $inner = '<p>Raport kontroli wpisów za <strong>' . self::e($r['period_label']) . '</strong> (' . self::e($r['from_pl']) . ' – ' . self::e($r['to_pl']) . ').</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="6" style="border-collapse:separate"><tr>'
            . $kpi('skontrolowane', $r['total'], '#111827') . $kpi('prawidłowe', $a['PRAWIDLOWY'], '#166534') . $kpi('opóźnione', $a['OPOZNIONY'], '#92400e')
            . $kpi('niepełne', $a['NIEPELNY_WPIS'], '#92400e') . $kpi('brak wpisu', $a['BRAK_WPISU'], '#991b1b') . '</tr></table>'
            . '<p style="margin:12px 0 4px"><strong>Weryfikacja:</strong> zatwierdzone ' . $v['ZATWIERDZONY'] . ' · do poprawy ' . $v['DO_POPRAWY']
            . ' · zakwestionowane ' . $v['ZAKWESTIONOWANY'] . ' · czeka na weryfikację ' . $r['awaiting'] . '</p>'
            . '<h3 style="font-size:15px;margin:18px 0 6px">Prowadzący z zaległościami</h3>'
            . ($instr !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px"><tr>'
                . self::th('Prowadzący') . self::th('Braki') . self::th('Niepełne') . self::th('Opóźnione') . self::th('Śr. opóźnienie') . self::th('Przypomnienia')
                . '</tr>' . $instr . '</table>' : '<p style="color:#166534">Wszyscy prowadzący mają wpisy na bieżąco.</p>')
            . '<h3 style="font-size:15px;margin:18px 0 6px">Wymaga Twojej decyzji (zakwestionowane)</h3>' . $dispH
            . '<h3 style="font-size:15px;margin:18px 0 6px">Eskalacje (≥ ' . (int)$r['escalate_after'] . ' przypomnień bez reakcji)</h3>' . $escH;
        $html = self::layout('Raport kontroli wpisów — ' . $r['period_label'], $inner, self::panelUrl(), 'Otwórz panel audytora');

        $text = "Raport kontroli wpisów za {$r['period_label']} ({$r['from_pl']} – {$r['to_pl']})\n\n"
              . "Podsumowanie: skontrolowane {$r['total']} · PRAWIDŁOWY {$a['PRAWIDLOWY']} · OPÓŹNIONY {$a['OPOZNIONY']} · NIEPEŁNY WPIS {$a['NIEPELNY_WPIS']} · BRAK WPISU {$a['BRAK_WPISU']}\n"
              . "Weryfikacja: zatwierdzone {$v['ZATWIERDZONY']} · do poprawy {$v['DO_POPRAWY']} · zakwestionowane {$v['ZAKWESTIONOWANY']} · czeka na weryfikację {$r['awaiting']}\n\n"
              . "Prowadzący z zaległościami:\n" . ($instrTxt ? implode("\n", $instrTxt) : '  Wszyscy na bieżąco.') . "\n\n"
              . "Wymaga Twojej decyzji (zakwestionowane):\n" . implode("\n", $dispT) . "\n\n"
              . "Eskalacje (≥ {$r['escalate_after']} przypomnień bez reakcji):\n" . implode("\n", $escT) . "\n\n"
              . "Panel audytora: " . self::panelUrl() . "\n\n— Kontrola Lekcji i Wpisów, " . self::org();
        return compact('subject', 'html', 'text');
    }
}

final class LessonControlNotifier
{
    /** Zaległości do przypomnienia, pogrupowane po prowadzącym. */
    public function pendingReminders(\DateTimeImmutable $now): array {
        tiLcMigrate();
        $minDate = $now->modify('-' . max(1, LessonControlSettings::int('ti_lc_remind_max_age_days')) . ' days')->format('Y-m-d');
        $max = max(1, LessonControlSettings::int('ti_lc_remind_max'));
        $rows = db_all(
            "SELECT k.*, s.time_from, s.time_to, COALESCE(NULLIF(c.display_name,''), c.name) AS course_name
               FROM k30_ti_lc_checks k
               JOIN k30_ti_sessions s ON s.id = k.session_id
               JOIN k30_ti_courses c ON c.id = k.course_id
              WHERE k.instructor_id IS NOT NULL
                AND k.reminder_count < ?
                AND ((k.auto_status IN ('BRAK_WPISU','NIEPELNY_WPIS') AND k.lesson_date >= ?) OR k.review_status = 'DO_POPRAWY')
              ORDER BY k.lesson_date, s.time_from", [$max, $minDate]);
        $by = [];
        foreach ($rows as $r) $by[(int)$r['instructor_id']][] = $r;
        return $by;
    }

    /** @return array{sent:int,skipped:array,items:array} */
    public function remindInstructors(\DateTimeImmutable $now, bool $dryRun = false, bool $force = false): array {
        $out = ['sent' => 0, 'skipped' => [], 'items' => []];
        if (!$force && !LessonControlSettings::on('ti_lc_remind_enabled')) { $out['skipped'][] = 'przypomnienia wyłączone (ti_lc_remind_enabled=0)'; return $out; }
        if (!$force && (int)$now->format('G') < LessonControlSettings::int('ti_lc_remind_hour')) { $out['skipped'][] = 'przed godziną wysyłki'; return $out; }
        $today = $now->format('Y-m-d');
        $escAfter = max(1, LessonControlSettings::int('ti_lc_escalate_after'));
        $policyLabel = LessonControlPolicy::forCourse(0)->deadlineLabel();

        foreach ($this->pendingReminders($now) as $iid => $items) {
            $u = db_one("SELECT id, name, email, COALESCE(phone_number,'') AS phone FROM users WHERE id = ? AND is_active = 1", [$iid]);
            if (!$u || trim((string)$u['email']) === '') { $out['skipped'][] = "prowadzący #{$iid}: brak aktywnego konta z e-mailem"; continue; }
            $remindedToday = array_filter($items, fn($i) => $i['last_reminder_at'] && substr((string)$i['last_reminder_at'], 0, 10) === $today);
            if ($remindedToday) { $out['skipped'][] = "{$u['name']}: już przypomniano dziś"; continue; }

            $nr = max(array_map(fn($i) => (int)$i['reminder_count'], $items)) + 1;
            $mail = LessonControlMailTemplates::instructorReminder((string)$u['name'], $items,
                ['reminder_no' => $nr, 'escalate_after' => $escAfter, 'deadline_label' => $policyLabel]);
            $entry = ['instructor_id' => $iid, 'name' => $u['name'], 'email' => $u['email'], 'count' => count($items), 'subject' => $mail['subject'], 'mail_id' => null, 'sms' => false];
            if (!$dryRun) {
                require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
                $entry['mail_id'] = mail_queue_add((string)$u['email'], (string)$u['name'], $mail['subject'], $mail['html'], $mail['text'], 'ti_lesson_control', $iid);
                if (LessonControlSettings::on('ti_lc_sms_enabled') && trim((string)$u['phone']) !== '') {
                    $entry['sms'] = $this->sms((string)$u['phone'], 'Panel dydaktyka: masz ' . count($items) . ' zaległych wpisów z zajęć do uzupełnienia. ' . LessonControlMailTemplates::panelUrl());
                }
                $this->markReminded($items, $now, $escAfter, $entry);
            }
            $out['sent']++;
            $out['items'][] = $entry;
        }
        return $out;
    }

    private function markReminded(array $items, \DateTimeImmutable $now, int $escAfter, array $entry): void {
        $ts = $now->format('Y-m-d H:i:s');
        foreach ($items as $i) {
            $sid = (int)$i['session_id'];
            $cnt = (int)$i['reminder_count'] + 1;
            $escalate = $cnt >= $escAfter && empty($i['escalated_at']) && in_array($i['auto_status'], TiLcStatus::NEEDS_ACTION, true);
            db_exec("UPDATE k30_ti_lc_checks SET reminder_count = ?, last_reminder_at = ?" . ($escalate ? ", escalated_at = ?" : "") . " WHERE session_id = ?",
                $escalate ? [$cnt, $ts, $ts, $sid] : [$cnt, $ts, $sid]);
            TiLcEventLog::add($sid, 'reminder_sent', (string)$i['auto_status'], (string)$i['auto_status'], null, 'system', '',
                ['nr' => $cnt, 'mail_id' => $entry['mail_id'], 'sms' => $entry['sms']], (string)$i['entry_hash']);
            if ($escalate) TiLcEventLog::add($sid, 'escalated', (string)$i['auto_status'], (string)$i['auto_status'], null, 'system',
                'Brak reakcji po ' . $cnt . ' przypomnieniach — sprawa w raporcie dla kierownika.', [], (string)$i['entry_hash']);
        }
    }

    private function sms(string $phone, string $text): bool {
        try {
            require_once dirname(__DIR__, 3) . '/includes/sms.php';
            return sms_send($phone, $text, 'ti_lesson_entry_reminder') !== '';
        } catch (\Throwable $e) { return false; }
    }

    /** E-mail do prowadzącego po decyzji „do poprawy” (gdy włączone). */
    public function notifyReturned(int $sessionId): bool {
        if (!LessonControlSettings::on('ti_lc_notify_on_return')) return false;
        $i = db_one("SELECT k.*, s.time_from, s.time_to, COALESCE(NULLIF(c.display_name,''), c.name) AS course_name, u.name, u.email
                       FROM k30_ti_lc_checks k JOIN k30_ti_sessions s ON s.id = k.session_id JOIN k30_ti_courses c ON c.id = k.course_id
                       JOIN users u ON u.id = k.instructor_id WHERE k.session_id = ? AND u.is_active = 1", [$sessionId]);
        if (!$i || trim((string)$i['email']) === '') return false;
        $mail = LessonControlMailTemplates::instructorReminder((string)$i['name'], [$i],
            ['reminder_no' => (int)$i['reminder_count'] + 1, 'escalate_after' => LessonControlSettings::int('ti_lc_escalate_after'),
             'deadline_label' => LessonControlPolicy::forCourse((int)$i['course_id'])->deadlineLabel()]);
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $mid = mail_queue_add((string)$i['email'], (string)$i['name'], $mail['subject'], $mail['html'], $mail['text'], 'ti_lesson_control', (int)$i['instructor_id']);
        TiLcEventLog::add($sessionId, 'return_notified', TiLcStatus::DO_POPRAWY, TiLcStatus::DO_POPRAWY, null, 'system', '', ['mail_id' => $mid], (string)$i['entry_hash']);
        return true;
    }

    /** Okres raportu: dzienny = wczoraj, tygodniowy = poprzedni tydzień (pon–nd). */
    public static function reportPeriod(\DateTimeImmutable $now, ?string $freq = null): array {
        $freq ??= LessonControlSettings::get('ti_lc_report_frequency') === 'weekly' ? 'weekly' : 'daily';
        if ($freq === 'weekly') {
            $mon = $now->modify('monday this week')->modify('-7 days');
            return ['freq' => 'weekly', 'key' => 'W:' . $mon->format('o-\WW'), 'from' => $mon->format('Y-m-d'), 'to' => $mon->modify('+6 days')->format('Y-m-d'),
                    'label' => 'tydzień ' . $mon->format('W/o')];
        }
        $y = $now->modify('-1 day');
        return ['freq' => 'daily', 'key' => 'D:' . $y->format('Y-m-d'), 'from' => $y->format('Y-m-d'), 'to' => $y->format('Y-m-d'), 'label' => 'dzień ' . $y->format('d.m.Y')];
    }

    public function reportData(string $from, string $to, string $label): array {
        tiLcMigrate();
        $auto = array_fill_keys(array_keys(TiLcStatus::AUTO), 0);
        $rev  = array_fill_keys(array_keys(TiLcStatus::REVIEW), 0);
        $rows = db_all("SELECT k.*, u.name AS instructor_name FROM k30_ti_lc_checks k LEFT JOIN users u ON u.id = k.instructor_id
                         WHERE k.lesson_date BETWEEN ? AND ? AND k.auto_status NOT IN ('NIE_DOTYCZY')", [$from, $to]);
        $instr = []; $awaiting = 0;
        foreach ($rows as $r) {
            $auto[$r['auto_status']] = ($auto[$r['auto_status']] ?? 0) + 1;
            $rev[$r['review_status']] = ($rev[$r['review_status']] ?? 0) + 1;
            if (in_array($r['review_status'], [TiLcStatus::NIEZWERYFIKOWANY, TiLcStatus::POPRAWIONY], true) && $r['auto_status'] !== TiLcStatus::OCZEKUJE) $awaiting++;
            $k = (int)($r['instructor_id'] ?? 0);
            $instr[$k] ??= ['name' => $r['instructor_name'] ?: 'bez prowadzącego', 'brak' => 0, 'niepelne' => 0, 'opoznione' => 0, 'delays' => [], 'reminders' => 0];
            if ($r['auto_status'] === TiLcStatus::BRAK_WPISU) $instr[$k]['brak']++;
            if ($r['auto_status'] === TiLcStatus::NIEPELNY_WPIS) $instr[$k]['niepelne']++;
            if ($r['auto_status'] === TiLcStatus::OPOZNIONY) { $instr[$k]['opoznione']++; $instr[$k]['delays'][] = (int)$r['delay_min']; }
            $instr[$k]['reminders'] += (int)$r['reminder_count'];
        }
        $instr = array_values(array_filter($instr, fn($i) => $i['brak'] + $i['niepelne'] + $i['opoznione'] > 0));
        foreach ($instr as &$i) { $i['avg_delay_h'] = $i['delays'] ? round(array_sum($i['delays']) / count($i['delays']) / 60, 1) : null; unset($i['delays']); }
        unset($i);
        usort($instr, fn($a, $b) => ($b['brak'] + $b['niepelne']) <=> ($a['brak'] + $a['niepelne']) ?: strcasecmp($a['name'], $b['name']));
        $detail = "SELECT k.*, s.time_from, s.time_to, COALESCE(NULLIF(c.display_name,''), c.name) AS course_name, COALESCE(u.name,'') AS instructor_name
                     FROM k30_ti_lc_checks k JOIN k30_ti_sessions s ON s.id = k.session_id JOIN k30_ti_courses c ON c.id = k.course_id
                     LEFT JOIN users u ON u.id = k.instructor_id";
        $total = array_sum($auto) - $auto[TiLcStatus::OCZEKUJE];
        return [
            'period_label' => $label, 'from' => $from, 'to' => $to,
            'from_pl' => date('d.m.Y', strtotime($from)), 'to_pl' => date('d.m.Y', strtotime($to)),
            'auto' => $auto, 'review' => $rev, 'total' => $total, 'awaiting' => $awaiting,
            'pct_ok' => $total ? (int)round($auto[TiLcStatus::PRAWIDLOWY] * 100 / $total) : 100,
            'problems' => $auto[TiLcStatus::BRAK_WPISU] + $auto[TiLcStatus::NIEPELNY_WPIS] + $rev[TiLcStatus::ZAKWESTIONOWANY],
            'instructors' => $instr,
            'disputed' => db_all($detail . " WHERE k.review_status = 'ZAKWESTIONOWANY' ORDER BY k.lesson_date LIMIT 50"),
            'escalations' => db_all($detail . " WHERE k.escalated_at IS NOT NULL AND k.auto_status IN ('BRAK_WPISU','NIEPELNY_WPIS') ORDER BY k.lesson_date LIMIT 50"),
            'escalate_after' => LessonControlSettings::int('ti_lc_escalate_after'),
        ];
    }

    /** Odbiorcy raportu: ustawienie ti_lc_report_recipients, inaczej kierownicy TI, inaczej administratorzy. */
    public static function reportRecipients(): array {
        $raw = array_filter(array_map('trim', preg_split('/[,;\s]+/', LessonControlSettings::get('ti_lc_report_recipients'))));
        $out = [];
        foreach ($raw as $e) if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[strtolower($e)] = ['email' => $e, 'name' => ''];
        if ($out) return array_values($out);
        $rows = [];
        try {
            $rows = db_all("SELECT u.name, u.email FROM k30_ti_panel_roles r JOIN users u ON u.id = r.user_id
                             WHERE r.role = 'kierownik' AND u.is_active = 1 AND COALESCE(u.email,'') <> ''");
        } catch (\Throwable $e) {}
        if (!$rows) $rows = db_all("SELECT name, email FROM users WHERE role = 'admin' AND is_active = 1 AND COALESCE(email,'') <> ''");
        foreach ($rows as $r) $out[strtolower((string)$r['email'])] = ['email' => (string)$r['email'], 'name' => (string)$r['name']];
        return array_values($out);
    }

    /** @return array{sent:int,skipped:array,period:array,recipients:array,mail?:array} */
    public function sendSupervisorReport(\DateTimeImmutable $now, bool $dryRun = false, bool $force = false): array {
        $period = self::reportPeriod($now);
        $out = ['sent' => 0, 'skipped' => [], 'period' => $period, 'recipients' => []];
        if (!$force) {
            if (!LessonControlSettings::on('ti_lc_report_enabled')) { $out['skipped'][] = 'raport wyłączony (ti_lc_report_enabled=0)'; return $out; }
            if ((int)$now->format('G') < LessonControlSettings::int('ti_lc_report_hour')) { $out['skipped'][] = 'przed godziną raportu'; return $out; }
            if ($period['freq'] === 'weekly' && (int)$now->format('N') !== max(1, min(7, LessonControlSettings::int('ti_lc_report_weekday')))) { $out['skipped'][] = 'nie ten dzień tygodnia'; return $out; }
            if (LessonControlSettings::get('ti_lc_report_last_period') === $period['key']) { $out['skipped'][] = 'raport za ten okres już wysłany'; return $out; }
        }
        $mail = LessonControlMailTemplates::supervisorReport($this->reportData($period['from'], $period['to'], $period['label']));
        $out['mail'] = $mail;
        $out['recipients'] = self::reportRecipients();
        if (!$out['recipients']) { $out['skipped'][] = 'brak odbiorców'; return $out; }
        if ($dryRun) return $out;
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        foreach ($out['recipients'] as $r) {
            mail_queue_add($r['email'], $r['name'], $mail['subject'], $mail['html'], $mail['text'], 'ti_lesson_control_report', null);
            $out['sent']++;
        }
        LessonControlSettings::set('ti_lc_report_last_period', $period['key']);
        return $out;
    }
}
