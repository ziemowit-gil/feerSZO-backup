<?php
/**
 * modules/ti_consult_reports/logic/consultReports.php — zestawienia dla konsultacji i szkoleń krótkich (SzK).
 *
 * Tylko odczyt. Zestaw danych jak w Analityce TI (AnalyticsExport): ['title','subtitle','headers','rows','num','dec','sheet']
 * — ta sama tablica trafia do tabeli na ekranie, widoku listy (czytniki ekranu) i eksportu CSV / Excel.
 * Źródła: k30_consultations (karty KON.XXX.MM.RRRR + dane zgłoszenia), k30_schedules (rezerwacje terminów),
 * k30_ti_courses z simple_mode > 0 (szkolenia krótkie SzK) z lekcjami i obecnością.
 * Zakres: ?from&to (RRRR-MM-DD) albo ?m=RRRR-MM (domyślnie bieżący miesiąc). Kadra widzi wszystko, pozostali — swoje.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consultations/logic/consultations.php';

final class TiConsultReports
{
    public const REPORTS = [
        'karty'        => ['Rejestr kart konsultacji', 'clipboard2-check', 'Każda karta w okresie: numer, data, beneficjent, konsultant, czas, temat, forma, status.'],
        'konsultanci'  => ['Konsultacje wg konsultantów', 'person-badge', 'Liczba kart i godzin na konsultanta, zatwierdzone i robocze, liczba beneficjentów.'],
        'tematy'       => ['Tematy konsultacji', 'tags', 'Ile razy zgłaszano każdy temat (z danych zgłoszenia na kartach).'],
        'beneficjenci' => ['Beneficjenci konsultacji', 'people', 'Na beneficjenta: liczba konsultacji, godziny, ostatnia wizyta, rezerwacje odbyte i nieobecności.'],
        'frekwencja'   => ['Frekwencja rezerwacji', 'calendar-check', 'Rezerwacje wg statusu na konsultanta: odbyte, nieobecności, odwołania (kto odwołał).'],
        'rozliczenia'  => ['Rozliczenie godzin konsultacji', 'cash-coin', 'Rezerwacje (bez odwołanych) wg rodzaju rozliczenia: godziny bezpłatne, płatne, PFRON i kwoty.'],
        'szk'          => ['Rejestr szkoleń krótkich (SzK)', 'lightning-charge', 'Szkolenia krótkie z terminami w okresie: prowadzący, uczestnicy, terminy odbyte, karty, zamknięcie.'],
        'szk_uczestnicy' => ['Uczestnicy szkoleń krótkich', 'person-lines-fill', 'Na uczestnika: szkolenia SzK, terminy, obecności i godziny w okresie.'],
    ];

    public static function range(array $in): array
    {
        $ok = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
        if ($ok($in['from'] ?? null) && $ok($in['to'] ?? null) && $in['to'] >= $in['from'])
            return ['from' => $in['from'], 'to' => $in['to'], 'm' => '', 'label' => date('d.m.Y', strtotime($in['from'])) . ' – ' . date('d.m.Y', strtotime($in['to']))];
        $m = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($in['m'] ?? '')) ? (string)$in['m'] : date('Y-m');
        return ['from' => $m . '-01', 'to' => date('Y-m-t', strtotime($m . '-01')), 'm' => $m, 'label' => $m];
    }

    public static function dataset(string $key, array $r, int $uid, bool $staff): array
    {
        if (!isset(self::REPORTS[$key])) throw new \InvalidArgumentException('Nieznane zestawienie.');
        $ds = self::{'r_' . $key}($r, $uid, $staff);
        $ds += ['num' => [], 'dec' => [], 'sheet' => mb_substr(self::REPORTS[$key][0], 0, 31)];
        $ds['title'] = self::REPORTS[$key][0];
        $ds['subtitle'] = 'Okres: ' . $r['label'] . (($ds['subtitle'] ?? '') !== '' ? ' · ' . $ds['subtitle'] : '') . ($staff ? '' : ' · tylko Twoje');
        return $ds;
    }

    /** Warunek „swoje” dla kart: konsultant albo prowadzący terminu/grupy. */
    private static function mineCards(int $uid, bool $staff, array &$p): string
    {
        if ($staff) return '';
        array_push($p, $uid, $uid);
        return " AND (co.consultant_id = ? OR co.course_id IN (SELECT id FROM k30_ti_courses WHERE instructor_id = ?))";
    }

    private static function cards(array $r, int $uid, bool $staff): array
    {
        $p = [$r['from'], $r['to']];
        $mine = self::mineCards($uid, $staff, $p);
        return db_all("SELECT co.*, c.name AS client_name, u.name AS consultant_name, k.name AS course_name
                         FROM k30_consultations co LEFT JOIN k30_clients c ON c.id = co.client_id
                         LEFT JOIN users u ON u.id = co.consultant_id LEFT JOIN k30_ti_courses k ON k.id = co.course_id
                        WHERE DATE(co.consultation_datetime) BETWEEN ? AND ? AND co.status <> 'cancelled' $mine
                        ORDER BY co.consultation_datetime, co.id", $p);
    }

    private static function r_karty(array $r, int $uid, bool $staff): array
    {
        $L = TiConsultations::INTAKE; $out = []; $min = 0;
        foreach (self::cards($r, $uid, $staff) as $c) {
            $i = TiConsultations::intake($c);
            $topics = implode('; ', array_map(fn($t) => explode(' (', $L['topics'][$t] ?? $t)[0], (array)($i['topics'] ?? [])));
            $out[] = [(string)$c['card_no'], date('d.m.Y H:i', strtotime((string)$c['consultation_datetime'])), (string)$c['client_name'], (string)($c['consultant_name'] ?? ''),
                      (int)$c['duration_minutes'], $topics, $L['form'][$i['form'] ?? ''] ?? '', (string)($c['course_name'] ?? ''), K30_CONSULTATION_STATUSES[$c['status']]['label'] ?? $c['status']];
            $min += (int)$c['duration_minutes'];
        }
        return ['subtitle' => count($out) . ' kart, ' . round($min / 60, 1) . ' h', 'headers' => ['Numer', 'Data', 'Beneficjent', 'Konsultant', 'Min', 'Temat', 'Forma', 'Szkolenie SzK', 'Status'],
                'rows' => $out, 'num' => [4], 'sheet' => 'Karty'];
    }

    private static function r_konsultanci(array $r, int $uid, bool $staff): array
    {
        $g = [];
        foreach (self::cards($r, $uid, $staff) as $c) {
            $k = (string)($c['consultant_name'] ?? '(nie przypisano)');
            $g[$k] ??= ['n' => 0, 'min' => 0, 'ok' => 0, 'draft' => 0, 'cl' => [], 'szk' => 0];
            $g[$k]['n']++; $g[$k]['min'] += (int)$c['duration_minutes']; $g[$k]['cl'][(int)$c['client_id']] = 1;
            $g[$k][$c['status'] === 'completed' ? 'ok' : 'draft']++;
            if ((int)$c['course_id']) $g[$k]['szk']++;
        }
        ksort($g); $out = [];
        foreach ($g as $k => $x) $out[] = [$k, $x['n'], round($x['min'] / 60, 2), $x['ok'], $x['draft'], count($x['cl']), $x['szk']];
        return ['headers' => ['Konsultant', 'Karty', 'Godziny', 'Zatwierdzone', 'Robocze', 'Beneficjenci', 'W tym karty SzK'], 'rows' => $out, 'num' => [1, 2, 3, 4, 5, 6], 'dec' => [2 => 2]];
    }

    private static function r_tematy(array $r, int $uid, bool $staff): array
    {
        $L = TiConsultations::INTAKE; $cnt = array_fill_keys(array_keys($L['topics']), 0); $sys = array_fill_keys(array_keys($L['systems']), 0); $with = 0; $all = 0;
        foreach (self::cards($r, $uid, $staff) as $c) {
            $all++; $i = TiConsultations::intake($c);
            if (!$i) continue;
            $with++;
            foreach ((array)($i['topics'] ?? []) as $t) if (isset($cnt[$t])) $cnt[$t]++;
            foreach ((array)($i['systems'] ?? []) as $t) if (isset($sys[$t])) $sys[$t]++;
        }
        $out = [];
        foreach ($cnt as $k => $n) $out[] = ['Temat', $L['topics'][$k], $n, $with ? round($n / $with * 100, 1) : null];
        foreach ($sys as $k => $n) $out[] = ['System', $L['systems'][$k], $n, $with ? round($n / $with * 100, 1) : null];
        return ['subtitle' => 'karty z danymi zgłoszenia: ' . $with . ' z ' . $all, 'headers' => ['Rodzaj', 'Pozycja', 'Liczba kart', '% kart ze zgłoszeniem'], 'rows' => $out, 'num' => [2, 3], 'dec' => [3 => 1]];
    }

    private static function r_beneficjenci(array $r, int $uid, bool $staff): array
    {
        $g = [];
        foreach (self::cards($r, $uid, $staff) as $c) {
            $k = (int)$c['client_id'];
            $g[$k] ??= ['name' => (string)$c['client_name'], 'n' => 0, 'min' => 0, 'last' => ''];
            $g[$k]['n']++; $g[$k]['min'] += (int)$c['duration_minutes']; $g[$k]['last'] = max($g[$k]['last'], (string)$c['consultation_datetime']);
        }
        $p = [$r['from'], $r['to']]; $mine = $staff ? '' : ' AND s.assigned_to = ' . (int)$uid;
        foreach (db_all("SELECT s.client_id, c.name, SUM(s.status='attended') AS att, SUM(s.status='no_show') AS ns
                           FROM k30_schedules s JOIN k30_clients c ON c.id = s.client_id
                          WHERE DATE(s.start_time) BETWEEN ? AND ? $mine GROUP BY s.client_id", $p) as $s) {
            $k = (int)$s['client_id'];
            $g[$k] ??= ['name' => (string)$s['name'], 'n' => 0, 'min' => 0, 'last' => ''];
            $g[$k]['att'] = (int)$s['att']; $g[$k]['ns'] = (int)$s['ns'];
        }
        uasort($g, fn($a, $b) => strcasecmp($a['name'], $b['name'])); $out = [];
        foreach ($g as $x) $out[] = [$x['name'], $x['n'], round($x['min'] / 60, 2), $x['last'] !== '' ? date('d.m.Y', strtotime($x['last'])) : '', $x['att'] ?? 0, $x['ns'] ?? 0];
        return ['headers' => ['Beneficjent', 'Karty', 'Godziny', 'Ostatnia konsultacja', 'Rezerwacje odbyte', 'Nie pojawił się'], 'rows' => $out, 'num' => [1, 2, 4, 5], 'dec' => [2 => 2]];
    }

    private static function r_frekwencja(array $r, int $uid, bool $staff): array
    {
        $p = [$r['from'], $r['to']]; $mine = $staff ? '' : ' AND s.assigned_to = ' . (int)$uid;
        $cols = ['preliminary', 'confirmed', 'attended', 'no_show', 'cancelled_by_client', 'cancelled_by_feer', 'cancelled'];
        $out = []; $tot = array_fill_keys($cols, 0);
        foreach (db_all("SELECT COALESCE(u.name,'(nie przypisano)') AS k, s.status, COUNT(*) AS n FROM k30_schedules s LEFT JOIN users u ON u.id = s.assigned_to
                          WHERE DATE(s.start_time) BETWEEN ? AND ? $mine GROUP BY k, s.status ORDER BY k", $p) as $x) {
            $out[$x['k']] ??= array_fill_keys($cols, 0);
            if (isset($out[$x['k']][$x['status']])) { $out[$x['k']][$x['status']] += (int)$x['n']; $tot[$x['status']] += (int)$x['n']; }
        }
        $rows = [];
        foreach ($out + ($out ? ['Razem' => $tot] : []) as $k => $v) {
            $done = $v['attended'] + $v['no_show'];
            $rows[] = array_merge([$k], array_values($v), [$done ? round($v['attended'] / $done * 100, 1) : null]);
        }
        return ['headers' => array_merge(['Konsultant'], array_map(fn($c) => K30_SCHEDULE_STATUSES[$c]['label'], $cols), ['Frekwencja %']),
                'rows' => $rows, 'num' => range(1, 8), 'dec' => [8 => 1], 'subtitle' => 'frekwencja = odbyte / (odbyte + nie pojawił się)'];
    }

    private static function r_rozliczenia(array $r, int $uid, bool $staff): array
    {
        $p = [$r['from'], $r['to']]; $mine = $staff ? '' : ' AND assigned_to = ' . (int)$uid;
        $out = [];
        foreach (db_all("SELECT strftime('%Y-%m', start_time) AS m, billing_type, COUNT(*) AS n, SUM(billed_hours) AS bh, SUM(free_hours) AS fh,
                                SUM(charged_hours) AS ch, SUM(amount_due) AS amt
                           FROM k30_schedules WHERE DATE(start_time) BETWEEN ? AND ? AND status NOT IN ('cancelled','cancelled_by_feer','cancelled_by_client','rejected') $mine
                          GROUP BY m, billing_type ORDER BY m, billing_type", $p) as $x)
            $out[] = [(string)$x['m'], K30_BILLING_TYPES[$x['billing_type']]['label'] ?? (string)$x['billing_type'], (int)$x['n'], round((float)$x['bh'], 2), round((float)$x['fh'], 2), round((float)$x['ch'], 2), round((float)$x['amt'], 2)];
        return ['headers' => ['Miesiąc', 'Rozliczenie', 'Rezerwacje', 'Godziny', 'Bezpłatne (h)', 'Płatne (h)', 'Kwota (zł)'], 'rows' => $out,
                'num' => [2, 3, 4, 5, 6], 'dec' => [3 => 2, 4 => 2, 5 => 2, 6 => 2], 'subtitle' => 'bez odwołanych'];
    }

    private static function szkCourses(array $r, int $uid, bool $staff): array
    {
        $mine = $staff ? '' : ' AND (c.instructor_id = ' . (int)$uid . ' OR EXISTS (SELECT 1 FROM k30_ti_sessions s2 WHERE s2.course_id = c.id AND s2.instructor_id = ' . (int)$uid . '))';
        return db_all("SELECT c.*, u.name AS instructor_name FROM k30_ti_courses c LEFT JOIN users u ON u.id = c.instructor_id
                        WHERE c.simple_mode > 0 $mine AND EXISTS (SELECT 1 FROM k30_ti_sessions s WHERE s.course_id = c.id AND s.draft_id IS NULL AND s.lesson_date BETWEEN ? AND ?)
                        ORDER BY c.name", [$r['from'], $r['to']]);
    }

    private static function r_szk(array $r, int $uid, bool $staff): array
    {
        $out = []; $modes = [1 => 'tylko terminy', 2 => 'terminy + płatności', 3 => 'płatności uproszczone'];
        foreach (self::szkCourses($r, $uid, $staff) as $c) {
            $s = db_one("SELECT COUNT(*) AS n, SUM(status IN ('held','individual_change','remote_material')) AS held, SUM(status='cancelled') AS canc,
                                SUM(lesson_method LIKE 'zdalna%') AS onl, MIN(lesson_date) AS d1, MAX(lesson_date) AS d2
                           FROM k30_ti_sessions WHERE course_id=? AND draft_id IS NULL AND status NOT IN ('draft','reserved')", [(int)$c['id']]);
            $people = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [(int)$c['id']])['n'] ?? 0);
            $cards = db_one("SELECT COUNT(*) AS n, SUM(status='completed') AS ok FROM k30_consultations WHERE course_id=? AND status<>'cancelled'", [(int)$c['id']]);
            $out[] = [(string)$c['name'], (string)$c['internal_desc'], (string)($c['instructor_name'] ?? ''), $c['class_type'] === 'hybrid' ? 'hybryda krótka' : ((int)$s['onl'] === (int)$s['n'] && (int)$s['n'] ? 'online' : 'stacjonarnie'),
                      $people, (int)$s['held'] . ' / ' . ((int)$s['n'] - (int)$s['canc']), $s['d1'] ? date('d.m', strtotime((string)$s['d1'])) . '–' . date('d.m.Y', strtotime((string)$s['d2'])) : '',
                      (int)($cards['ok'] ?? 0) . ' / ' . (int)($cards['n'] ?? 0), $modes[(int)$c['simple_mode']] ?? '', !empty($c['closed_at']) ? date('d.m.Y', strtotime((string)$c['closed_at'])) : 'otwarte'];
        }
        return ['headers' => ['Szkolenie', 'Opis (kadra)', 'Prowadzący', 'Forma', 'Uczestnicy', 'Terminy odbyte / wszystkie', 'Daty', 'Karty zatwierdz. / wszystkie', 'Panel uczestników', 'Zamknięte'],
                'rows' => $out, 'num' => [4], 'sheet' => 'SzK'];
    }

    private static function r_szk_uczestnicy(array $r, int $uid, bool $staff): array
    {
        $ids = array_map(fn($c) => (int)$c['id'], self::szkCourses($r, $uid, $staff));
        if (!$ids) return ['headers' => ['Uczestnik', 'Szkolenia', 'Terminy', 'Obecności', 'Godziny obecności'], 'rows' => []];
        $in = implode(',', $ids); $g = [];
        foreach (db_all("SELECT cl.id, cl.name, c.name AS course, s.id AS sid, a.attended,
                                COALESCE(NULLIF(s.duration_min,0),60) AS dur
                           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id
                           JOIN k30_ti_attendance a ON a.session_id = s.id JOIN k30_clients cl ON cl.id = a.client_id
                          WHERE s.course_id IN ($in) AND s.draft_id IS NULL AND s.status NOT IN ('draft','reserved','cancelled') AND s.lesson_date BETWEEN ? AND ?",
                         [$r['from'], $r['to']]) as $x) {
            $k = (int)$x['id'];
            $g[$k] ??= ['name' => (string)$x['name'], 'c' => [], 'n' => 0, 'att' => 0, 'min' => 0];
            $g[$k]['c'][(string)$x['course']] = 1; $g[$k]['n']++;
            if ((int)$x['attended'] === 1) { $g[$k]['att']++; $g[$k]['min'] += (int)$x['dur']; }
        }
        uasort($g, fn($a, $b) => strcasecmp($a['name'], $b['name'])); $out = [];
        foreach ($g as $x) $out[] = [$x['name'], implode(', ', array_keys($x['c'])), $x['n'], $x['att'], round($x['min'] / 60, 2)];
        return ['headers' => ['Uczestnik', 'Szkolenia', 'Terminy', 'Obecności', 'Godziny obecności'], 'rows' => $out, 'num' => [2, 3, 4], 'dec' => [4 => 2]];
    }

    // ── Wydruki ──────────────────────────────────────────────────────────────

    /** Id kart do zbiorczego PDF (zatwierdzone + robocze, bez anulowanych) w okresie. */
    public static function cardIds(array $r, int $uid, bool $staff): array
    {
        return array_map(fn($c) => (int)$c['id'], self::cards($r, $uid, $staff));
    }

    /** Lista obecności szkolenia krótkiego do podpisu — HTML do PDF (jedna strona: terminy × uczestnicy). */
    public static function attendanceSheetPdf(int $courseId): string
    {
        $c = db_one("SELECT c.name, c.location, u.name AS instructor FROM k30_ti_courses c LEFT JOIN users u ON u.id=c.instructor_id WHERE c.id=?", [$courseId]) ?: ['name' => ''];
        $sess = db_all("SELECT lesson_date, time_from, time_to, lesson_method, topic FROM k30_ti_sessions WHERE course_id=? AND draft_id IS NULL AND status NOT IN ('draft','reserved','cancelled') ORDER BY lesson_date, time_from", [$courseId]);
        $people = db_all("SELECT cl.name FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id=e.client_id WHERE e.course_id=? AND e.status='active' ORDER BY cl.name", [$courseId]);
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $h = '<style>body{font-family:dejavusans;font-size:9.5pt}h1{font-size:14pt;margin:0 0 2mm}table{border-collapse:collapse;width:100%}
              th,td{border:0.3mm solid #444;padding:2mm;vertical-align:top}th{background:#eef2f7;font-size:8.5pt}td.sig{height:11mm}.m{color:#555;font-size:8.5pt}</style>'
           . '<h1>Lista obecności — szkolenie krótkie ' . $e($c['name']) . '</h1>'
           . '<p class="m">Prowadzący: ' . $e($c['instructor'] ?? '—') . ($c['location'] ? ' · miejsce: ' . $e($c['location']) : '') . ' · wydruk ' . date('d.m.Y') . '</p>'
           . '<table><thead><tr><th style="width:6mm">#</th><th style="width:45mm">Uczestnik</th>';
        foreach ($sess as $i => $s) $h .= '<th>' . ($i + 1) . '. ' . $e(date('d.m.Y', strtotime((string)$s['lesson_date']))) . '<br>' . $e(substr((string)$s['time_from'], 0, 5) . '–' . substr((string)$s['time_to'], 0, 5))
                                         . (str_starts_with((string)$s['lesson_method'], 'zdalna') ? '<br>online' : '') . '</th>';
        $h .= '</tr></thead><tbody>';
        foreach ($people as $i => $p) { $h .= '<tr><td>' . ($i + 1) . '</td><td>' . $e($p['name']) . '</td>'; foreach ($sess as $_) $h .= '<td class="sig"></td>'; $h .= '</tr>'; }
        $h .= '<tr><td></td><td><strong>Podpis prowadzącego</strong></td>'; foreach ($sess as $_) $h .= '<td class="sig"></td>'; $h .= '</tr></tbody></table>';
        $h .= '<p class="m">Temat zajęć: ' . implode(' · ', array_map(fn($s, $i) => ($i + 1) . '. ' . $e($s['topic'] ?: '…………………'), $sess, array_keys($sess))) . '</p>';
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmp = (defined('UPLOAD_DIR') ? UPLOAD_DIR : sys_get_temp_dir() . '/') . 'mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => count($sess) > 3 ? 'A4-L' : 'A4', 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 12, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
        $pdf->SetTitle('Lista obecności — ' . $c['name']);
        $pdf->WriteHTML($h);
        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
