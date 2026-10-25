<?php
/**
 * modules/ti_hours_insights/logic/hoursInsights.php — Zestawienia godzinowe prowadzącego („Moje godziny”).
 *
 * Tylko odczyt, bez tabel i migracji. Podstawa: lekcje (k30_ti_sessions) prowadzone przez danego
 * prowadzącego — COALESCE(s.instructor_id, c.instructor_id) — bez wersji roboczych, rezerwacji i
 * projektów planisty (draft_id). Godzina lekcji = duration_min/60 (gdy brak: różnica time_to − time_from).
 * „Odbyte” = K30_TI_HELD_STATUSES; „zaplanowane” = planned; „odwołane” = cancelled.
 *
 * Każde zestawienie zwraca zestaw danych jak Analityka TI (AnalyticsExport):
 *   ['title','subtitle','headers','rows','num'=>[kolumny liczbowe],'dec'=>[kolumna=>miejsca],'sheet', 'notes'=>[zdania pod tabelą]]
 * — ta sama tablica trafia do tabeli na ekranie, listy zdań i eksportu CSV/XLSX.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_leaves.php';
require_once dirname(__DIR__, 3) . '/modules/ti_test_accounts/logic/testFilter.php';   // wykluczanie kont testowych z raportów

final class TiHoursInsights
{
    /** Klucz => [etykieta, ikona bi-*, opis jednym zdaniem]. Kolejność = menu. */
    public const REPORTS = [
        'podsumowanie' => ['Podsumowanie okresu',          'speedometer2',      'Godziny odbyte, zaplanowane i odwołane, średnia tygodniowa, limit, szacunek wypłaty, dokumentacja.'],
        'grupy'        => ['Godziny wg grup',              'people',            'Ile godzin w każdej grupie — odbyte, zaplanowane, odwołane, udział procentowy, forma zajęć.'],
        'tygodnie'     => ['Godziny wg tygodni',           'calendar-week',     'Tydzień po tygodniu: odbyte, zaplanowane, odwołane i narastająco.'],
        'siatka'       => ['Kiedy pracuję (dzień × pora)', 'grid-3x3',          'Godziny zajęć w siatce dzień tygodnia × godzina rozpoczęcia — gdzie jest najwięcej obciążenia.'],
        'formy'        => ['Formy zajęć',                  'laptop',            'Stacjonarnie, online, zajęcia indywidualne, praca własna prowadzącego — godziny i udział.'],
        'odwolania'    => ['Odwołania i zmiany terminów',  'calendar-x',        'Godziny utracone przez odwołania (kto odwołał) i lekcje przełożone — per grupa.'],
        'dostepnosc'   => ['Wykorzystanie dostępności',    'clock-history',     'Godziny z okien dostępności vs godziny faktycznie zaplanowane — per dzień tygodnia.'],
        'kursanci'     => ['Godziny z kursantami',         'person-lines-fill', 'Dla każdego kursanta: godziny obecności, nieobecności i odwołań na Twoich lekcjach.'],
        'dokumentacja' => ['Dokumentacja i zaległości',    'journal-check',     'Odbyte lekcje bez kompletnej dokumentacji oraz minione lekcje wciąż „zaplanowane”.'],
        'trend'        => ['Trend 12 miesięcy',            'graph-up',          'Miesiąc po miesiącu (12 wstecz od wybranego): odbyte, zaplanowane, odwołane, szacunek wypłaty.'],
    ];

    private const HELD = "'held','individual_change','remote_material'";
    private const BASE_EXCL = "s.draft_id IS NULL AND s.status NOT IN ('draft','reserved')";
    private const HOURS = "COALESCE(NULLIF(s.duration_min,0), (strftime('%s','2000-01-01 '||s.time_to) - strftime('%s','2000-01-01 '||s.time_from))/60.0, 0) / 60.0";

    /** Zakres dat z filtrów: m=YYYY-MM (domyślnie bieżący miesiąc) albo from/to (YYYY-MM-DD). */
    public static function range(array $in): array
    {
        $from = (string)($in['from'] ?? ''); $to = (string)($in['to'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $to >= $from) {
            $label = date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to));
            return ['from' => $from, 'to' => $to, 'm' => '', 'label' => $label];
        }
        $m = (string)($in['m'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) $m = date('Y-m');
        $from = $m . '-01'; $to = date('Y-m-t', strtotime($from));
        return ['from' => $from, 'to' => $to, 'm' => $m, 'label' => self::monthLabel($m)];
    }

    public static function monthLabel(string $m): string
    {
        $n = ['', 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
        return ($n[(int)substr($m, 5, 2)] ?? '') . ' ' . substr($m, 0, 4);
    }

    public static function dataset(string $key, int $uid, array $r): array
    {
        if (!isset(self::REPORTS[$key])) throw new \InvalidArgumentException('Nieznane zestawienie.');
        $ds = self::{'r_' . $key}($uid, $r);
        $ds += ['num' => [], 'dec' => [], 'notes' => [], 'sheet' => mb_substr(self::REPORTS[$key][0], 0, 31)];
        $ds['title'] = self::REPORTS[$key][0];
        $ds['subtitle'] = ($ds['subtitle'] ?? '') . ($ds['subtitle'] ?? '' ? ' · ' : '') . 'okres: ' . $r['label'];
        return $ds;
    }

    /** Lekcje prowadzącego w zakresie (surowe wiersze z godzinami). */
    private static function sessions(int $uid, array $r, string $extra = ''): array
    {
        return db_all(
            "SELECT s.*, " . self::HOURS . " AS h, c.name AS course_name, c.class_type, c.is_online, c.lesson_payout_bb, c.track_attendance,
                    " . k30_ti_docs_complete_sql() . " AS docs_ok
               FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id
              WHERE COALESCE(s.instructor_id, c.instructor_id) = CAST(? AS INTEGER) AND s.lesson_date BETWEEN ? AND ? AND " . self::BASE_EXCL . " AND " . ti_test_course_sql('s.course_id') . " $extra
              ORDER BY s.lesson_date, s.time_from",
            [$uid, $r['from'], $r['to']]
        );
    }

    private static function isHeld(array $s): bool { return in_array((string)$s['status'], K30_TI_HELD_STATUSES, true); }

    /** Sumy: held/planned/cancelled (godziny i lekcje), przeszłe „planned”, przełożone. */
    private static function totals(array $rows): array
    {
        $t = ['held_h' => 0.0, 'held_n' => 0, 'planned_h' => 0.0, 'planned_n' => 0, 'cancelled_h' => 0.0, 'cancelled_n' => 0,
              'overdue_n' => 0, 'overdue_h' => 0.0, 'resched_n' => 0, 'docs_missing' => 0, 'payout_bb' => 0.0];
        $today = date('Y-m-d');
        foreach ($rows as $s) {
            $h = (float)$s['h'];
            if (self::isHeld($s)) { $t['held_h'] += $h; $t['held_n']++; $t['payout_bb'] += (float)($s['lesson_payout_bb'] ?? 0); if (!(int)$s['docs_ok']) $t['docs_missing']++; }
            elseif ($s['status'] === 'planned') { $t['planned_h'] += $h; $t['planned_n']++; if ((string)$s['lesson_date'] < $today) { $t['overdue_n']++; $t['overdue_h'] += $h; } }
            elseif ($s['status'] === 'cancelled') { $t['cancelled_h'] += $h; $t['cancelled_n']++; }
            if (!empty($s['rescheduled_from_date'])) $t['resched_n']++;
        }
        return $t;
    }

    private static function pct(float $part, float $whole): ?float { return $whole > 0 ? round($part / $whole * 100, 1) : null; }

    /** „1 lekcja”, „2 lekcje”, „5 lekcji”. */
    public static function lessons(int $n): string
    {
        $m10 = $n % 10; $m100 = $n % 100;
        $w = $n === 1 ? 'lekcja' : (($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? 'lekcje' : 'lekcji');
        return $n . ' ' . $w;
    }

    // ── 1. Podsumowanie ──────────────────────────────────────────────────────
    private static function r_podsumowanie(int $uid, array $r): array
    {
        $rows = self::sessions($uid, $r);
        $t = self::totals($rows);
        $days = max(1, (int)((strtotime($r['to']) - strtotime($r['from'])) / 86400) + 1);
        $weeks = max(1, $days / 7);
        $planned_total = $t['held_h'] + $t['planned_h'];
        // Najbardziej obciążony dzień i tydzień
        $byDay = []; $byWeek = [];
        foreach ($rows as $s) if (self::isHeld($s) || $s['status'] === 'planned') {
            $byDay[(string)$s['lesson_date']] = ($byDay[(string)$s['lesson_date']] ?? 0) + (float)$s['h'];
            $wk = date('o-\WW', strtotime((string)$s['lesson_date']));
            $byWeek[$wk] = ($byWeek[$wk] ?? 0) + (float)$s['h'];
        }
        arsort($byDay); arsort($byWeek);
        $topDay = $byDay ? array_key_first($byDay) : null; $topWeek = $byWeek ? array_key_first($byWeek) : null;
        // Limit miesięczny (planista)
        $lim = db_one("SELECT max_month_min, max_week_min, max_day_min FROM k30_sc_instructor_limits WHERE instructor_id=?", [$uid]) ?: null;
        $limM = $lim && (int)$lim['max_month_min'] > 0 ? (int)$lim['max_month_min'] / 60 : null;
        // Urlopy w okresie
        $leaveDays = 0;
        foreach (db_all("SELECT date_from, date_to FROM k30_ti_instructor_leaves WHERE instructor_id=? AND date_to >= ? AND date_from <= ?", [$uid, $r['from'], $r['to']]) as $l) {
            $a = max(strtotime((string)$l['date_from']), strtotime($r['from'])); $b = min(strtotime((string)$l['date_to']), strtotime($r['to']));
            if ($b >= $a) $leaveDays += (int)(($b - $a) / 86400) + 1;
        }
        $bd = k30_ti_payout_breakdown($t['payout_bb']);
        $wkCount = (int)ceil($weeks);
        $out = [
            ['Godziny odbyte',                       round($t['held_h'], 1),      self::lessons($t['held_n'])],
            ['Godziny zaplanowane (jeszcze nieodbyte)', round($t['planned_h'], 1), self::lessons($t['planned_n'])],
            ['Razem w planie (odbyte + zaplanowane)', round($planned_total, 1),  self::lessons($t['held_n'] + $t['planned_n'])],
            ['Godziny odwołane',                     round($t['cancelled_h'], 1), self::lessons($t['cancelled_n']) . ($planned_total + $t['cancelled_h'] > 0 ? ' (' . self::pct($t['cancelled_h'], $planned_total + $t['cancelled_h']) . '% planu)' : '')],
            ['Lekcje przełożone na inny termin',     $t['resched_n'],            ''],
            ['Średnio godzin tygodniowo',            round($planned_total / $weeks, 1), 'okres ' . $days . ' dni ≈ ' . round($weeks, 1) . ' tyg.'],
            ['Średnia długość lekcji (min)',         ($t['held_n'] + $t['planned_n']) > 0 ? (int)round($planned_total * 60 / ($t['held_n'] + $t['planned_n'])) : null, ''],
            ['Najbardziej obciążony dzień',          $topDay ? round($byDay[$topDay], 1) : null, $topDay ? date('d.m.Y', strtotime($topDay)) . ' (' . K30_TI_DAYS[(int)date('w', strtotime($topDay))] . ')' : 'brak zajęć'],
            ['Najbardziej obciążony tydzień',        $topWeek ? round($byWeek[$topWeek], 1) : null, $topWeek ? 'tydzień ' . substr($topWeek, 6) . ' / ' . substr($topWeek, 0, 4) : 'brak zajęć'],
            ['Limit miesięczny (planista)',          $limM !== null ? round($limM, 1) : null, $limM !== null ? 'wykorzystanie: ' . self::pct($planned_total, $limM) . '%' : 'brak ustawionego limitu'],
            ['Dni urlopu / nieobecności w okresie',  $leaveDays,                 ''],
            ['Minione lekcje wciąż „zaplanowane”',   $t['overdue_n'],            $t['overdue_n'] ? round($t['overdue_h'], 1) . ' godz. do potwierdzenia lub odwołania' : 'wszystko rozliczone'],
            ['Odbyte lekcje bez pełnej dokumentacji', $t['docs_missing'],        $t['docs_missing'] ? 'brak tematu lub obecności' : 'komplet'],
            ['Szacunek wypłaty — brutto-brutto (zł)', round($bd['brutto_brutto'], 2), 'stała stawka za lekcję wg grupy, tylko lekcje odbyte'],
            ['Szacunek wypłaty — netto (zł)',         round($bd['netto'], 2),   'orientacyjnie, wg stawek potrąceń z ustawień'],
        ];
        return ['subtitle' => 'liczby dotyczą lekcji prowadzonych przez Ciebie (także w zastępstwie)', 'headers' => ['Wskaźnik', 'Wartość', 'Komentarz'], 'rows' => $out, 'num' => [1], 'dec' => [1 => 1], 'sheet' => 'Podsumowanie'];
    }

    // ── 2. Grupy ─────────────────────────────────────────────────────────────
    private static function r_grupy(int $uid, array $r): array
    {
        $rows = self::sessions($uid, $r); $g = [];
        foreach ($rows as $s) {
            $k = (int)$s['course_id'];
            $g[$k] ??= ['name' => (string)$s['course_name'], 'type' => (string)$s['class_type'], 'held' => 0.0, 'held_n' => 0, 'planned' => 0.0, 'cancelled' => 0.0, 'online' => 0.0, 'stac' => 0.0, 'payout' => 0.0];
            $h = (float)$s['h'];
            if (self::isHeld($s)) { $g[$k]['held'] += $h; $g[$k]['held_n']++; $g[$k]['payout'] += (float)($s['lesson_payout_bb'] ?? 0); }
            elseif ($s['status'] === 'planned') $g[$k]['planned'] += $h;
            elseif ($s['status'] === 'cancelled') { $g[$k]['cancelled'] += $h; continue; }
            if (str_starts_with((string)$s['lesson_method'], 'zdalna') || ($s['lesson_method'] === '' && (int)$s['is_online'] === 1)) $g[$k]['online'] += $h; else $g[$k]['stac'] += $h;
        }
        $totalPlan = array_sum(array_map(fn($x) => $x['held'] + $x['planned'], $g));
        uasort($g, fn($a, $b) => ($b['held'] + $b['planned']) <=> ($a['held'] + $a['planned']));
        $out = []; $types = ['individual' => 'indywidualne', 'group' => 'grupowe', 'hybrid' => 'hybrydowe'];
        foreach ($g as $x) {
            $plan = $x['held'] + $x['planned'];
            $out[] = [$x['name'], $types[$x['type']] ?? $x['type'], round($x['held'], 1), $x['held_n'], round($x['planned'], 1), round($plan, 1), self::pct($plan, $totalPlan), round($x['cancelled'], 1),
                      round($x['stac'], 1), round($x['online'], 1), $x['held_n'] ? (int)round($x['held'] * 60 / $x['held_n']) : null, round($x['payout'], 2)];
        }
        return ['subtitle' => 'posortowane wg godzin w planie', 'headers' => ['Grupa', 'Rodzaj', 'Odbyte (h)', 'Lekcje odbyte', 'Zaplanowane (h)', 'Razem w planie (h)', 'Udział %', 'Odwołane (h)', 'Stacjonarnie (h)', 'Online (h)', 'Śr. lekcja (min)', 'Wypłata BB (zł)'],
                'rows' => $out, 'num' => [2, 3, 4, 5, 6, 7, 8, 9, 10, 11], 'dec' => [2 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 11 => 2], 'sheet' => 'Grupy'];
    }

    // ── 3. Tygodnie ──────────────────────────────────────────────────────────
    private static function r_tygodnie(int $uid, array $r): array
    {
        $rows = self::sessions($uid, $r); $w = [];
        foreach ($rows as $s) {
            $ts = strtotime((string)$s['lesson_date']); $k = date('o-\WW', $ts);
            $w[$k] ??= ['from' => date('Y-m-d', strtotime('monday this week', $ts)), 'held' => 0.0, 'planned' => 0.0, 'cancelled' => 0.0, 'n' => 0, 'days' => []];
            $h = (float)$s['h'];
            if (self::isHeld($s)) { $w[$k]['held'] += $h; $w[$k]['n']++; $w[$k]['days'][(string)$s['lesson_date']] = 1; }
            elseif ($s['status'] === 'planned') { $w[$k]['planned'] += $h; $w[$k]['n']++; $w[$k]['days'][(string)$s['lesson_date']] = 1; }
            elseif ($s['status'] === 'cancelled') $w[$k]['cancelled'] += $h;
        }
        ksort($w); $out = []; $cum = 0.0;
        foreach ($w as $k => $x) {
            $plan = $x['held'] + $x['planned']; $cum += $plan;
            $out[] = ['tydz. ' . (int)substr($k, 6), date('d.m', strtotime($x['from'])) . ' – ' . date('d.m.Y', strtotime($x['from'] . ' +6 days')), round($x['held'], 1), round($x['planned'], 1), round($plan, 1), count($x['days']), $x['n'], round($x['cancelled'], 1), round($cum, 1)];
        }
        return ['subtitle' => 'tygodnie od poniedziałku', 'headers' => ['Tydzień', 'Daty', 'Odbyte (h)', 'Zaplanowane (h)', 'Razem (h)', 'Dni z zajęciami', 'Lekcje', 'Odwołane (h)', 'Narastająco (h)'],
                'rows' => $out, 'num' => [2, 3, 4, 5, 6, 7, 8], 'dec' => [2 => 1, 3 => 1, 4 => 1, 7 => 1, 8 => 1], 'sheet' => 'Tygodnie'];
    }

    // ── 4. Siatka dzień × pora ───────────────────────────────────────────────
    private static function r_siatka(int $uid, array $r): array
    {
        $rows = self::sessions($uid, $r, "AND s.status <> 'cancelled'"); $grid = []; $hours = [];
        foreach ($rows as $s) {
            $hh = (int)substr((string)$s['time_from'], 0, 2); $dow = (int)date('w', strtotime((string)$s['lesson_date']));
            $grid[$hh][$dow] = ($grid[$hh][$dow] ?? 0) + (float)$s['h']; $hours[$hh] = 1;
        }
        ksort($hours); $out = []; $dows = [1, 2, 3, 4, 5, 6, 0]; $colTot = array_fill_keys($dows, 0.0);
        foreach (array_keys($hours) as $hh) {
            $row = [sprintf('%02d:00–%02d:59', $hh, $hh)]; $sum = 0.0;
            foreach ($dows as $d) { $v = $grid[$hh][$d] ?? 0.0; $row[] = $v > 0 ? round($v, 1) : null; $sum += $v; $colTot[$d] += $v; }
            $row[] = round($sum, 1); $out[] = $row;
        }
        if ($out) { $tot = ['Razem']; foreach ($dows as $d) $tot[] = round($colTot[$d], 1); $tot[] = round(array_sum($colTot), 1); $out[] = $tot; }
        $hdr = ['Godzina rozpoczęcia']; foreach ($dows as $d) $hdr[] = mb_substr(K30_TI_DAYS[$d], 0, 3); $hdr[] = 'Razem';
        return ['subtitle' => 'godziny lekcji (odbyte + zaplanowane) wg dnia tygodnia i godziny rozpoczęcia', 'headers' => $hdr, 'rows' => $out, 'num' => range(1, 8), 'dec' => array_fill(1, 8, 1), 'sheet' => 'Siatka'];
    }

    // ── 5. Formy ─────────────────────────────────────────────────────────────
    private static function r_formy(int $uid, array $r): array
    {
        $rows = self::sessions($uid, $r, "AND s.status <> 'cancelled'"); $f = []; $tot = 0.0;
        $lbl = fn(array $s) => $s['status'] === 'remote_material' ? 'Praca własna prowadzącego (materiał zdalny)'
            : ($s['status'] === 'individual_change' ? 'Zajęcia indywidualne (zmiana)'
            : (str_starts_with((string)$s['lesson_method'], 'zdalna') ? 'Online' . ($s['lesson_method'] === 'zdalna_zoom' ? ' (Zoom)' : ' (inne)')
            : ((string)$s['lesson_method'] === 'stacjonarna' || (int)$s['is_online'] === 0 ? 'Stacjonarnie' : 'Online')));
        foreach ($rows as $s) { $k = $lbl($s); $f[$k] ??= ['h' => 0.0, 'n' => 0, 'held' => 0.0]; $f[$k]['h'] += (float)$s['h']; $f[$k]['n']++; if (self::isHeld($s)) $f[$k]['held'] += (float)$s['h']; $tot += (float)$s['h']; }
        arsort($f); $out = [];
        foreach ($f as $k => $x) $out[] = [$k, round($x['h'], 1), self::pct($x['h'], $tot), $x['n'], round($x['held'], 1), $x['n'] ? (int)round($x['h'] * 60 / $x['n']) : null];
        return ['subtitle' => 'odbyte + zaplanowane', 'headers' => ['Forma', 'Godziny', 'Udział %', 'Lekcje', 'W tym odbyte (h)', 'Śr. lekcja (min)'], 'rows' => $out, 'num' => [1, 2, 3, 4, 5], 'dec' => [1 => 1, 2 => 1, 4 => 1], 'sheet' => 'Formy'];
    }

    // ── 6. Odwołania ─────────────────────────────────────────────────────────
    private static function r_odwolania(int $uid, array $r): array
    {
        $rows = self::sessions($uid, $r); $g = []; $who = ['instructor' => 'prowadzący', 'student' => 'kursant', 'parent' => 'opiekun', 'admin' => 'kierownik/administracja', 'staff' => 'kierownik/administracja', 'system' => 'system'];
        foreach ($rows as $s) {
            $k = (int)$s['course_id']; $g[$k] ??= ['name' => (string)$s['course_name'], 'plan' => 0.0, 'canc' => 0.0, 'canc_n' => 0, 'by' => [], 'resch' => 0, 'short' => 0];
            if ($s['status'] === 'cancelled') { $g[$k]['canc'] += (float)$s['h']; $g[$k]['canc_n']++; $w = $who[(string)($s['cancelled_by_role'] ?? '')] ?? 'nie podano'; $g[$k]['by'][$w] = ($g[$k]['by'][$w] ?? 0) + 1;
                if (!empty($s['cancelled_at']) && (strtotime((string)$s['lesson_date'] . ' ' . (string)$s['time_from']) - strtotime((string)$s['cancelled_at'])) < 86400) $g[$k]['short']++; }
            else { $g[$k]['plan'] += (float)$s['h']; }
            if (!empty($s['rescheduled_from_date'])) $g[$k]['resch']++;
        }
        uasort($g, fn($a, $b) => $b['canc'] <=> $a['canc']); $out = [];
        foreach ($g as $x) {
            if ($x['canc_n'] === 0 && $x['resch'] === 0) continue;
            $by = []; arsort($x['by']); foreach ($x['by'] as $w => $n) $by[] = $w . ' ' . $n;
            $out[] = [$x['name'], round($x['canc'], 1), $x['canc_n'], self::pct($x['canc'], $x['canc'] + $x['plan']), implode(', ', $by), $x['short'], $x['resch']];
        }
        return ['subtitle' => 'grupy bez odwołań i zmian pominięte', 'headers' => ['Grupa', 'Odwołane (h)', 'Odwołane lekcje', '% planu grupy', 'Kto odwołał', 'Odwołane < 24 h przed', 'Przełożone lekcje'],
                'rows' => $out, 'num' => [1, 2, 3, 5, 6], 'dec' => [1 => 1, 3 => 1], 'sheet' => 'Odwołania',
                'notes' => ['„Odwołane < 24 h przed” — odwołanie zarejestrowane później niż dobę przed rozpoczęciem lekcji.']];
    }

    // ── 7. Dostępność ────────────────────────────────────────────────────────
    private static function r_dostepnosc(int $uid, array $r): array
    {
        $win = db_all("SELECT day_of_week, time_from, time_to, valid_from, valid_to FROM k30_ti_instructor_availability WHERE instructor_id=? AND is_active=1 AND status='approved'", [$uid]);
        $avail = array_fill(0, 7, 0.0); $days = array_fill(0, 7, 0);
        for ($d = strtotime($r['from']); $d <= strtotime($r['to']); $d += 86400) {
            $dow = (int)date('w', $d); $ymd = date('Y-m-d', $d); $days[$dow]++;
            foreach ($win as $w) {
                if ((int)$w['day_of_week'] !== $dow) continue;
                if (!empty($w['valid_from']) && $ymd < $w['valid_from']) continue;
                if (!empty($w['valid_to']) && $ymd > $w['valid_to']) continue;
                $avail[$dow] += max(0, (strtotime('2000-01-01 ' . $w['time_to']) - strtotime('2000-01-01 ' . $w['time_from'])) / 3600);
            }
        }
        $used = array_fill(0, 7, 0.0); $n = array_fill(0, 7, 0);
        foreach (self::sessions($uid, $r, "AND s.status <> 'cancelled'") as $s) { $dow = (int)date('w', strtotime((string)$s['lesson_date'])); $used[$dow] += (float)$s['h']; $n[$dow]++; }
        $out = []; $ta = 0.0; $tu = 0.0;
        foreach ([1, 2, 3, 4, 5, 6, 0] as $d) {
            if ($avail[$d] == 0 && $used[$d] == 0) continue;
            $ta += $avail[$d]; $tu += $used[$d];
            $out[] = [K30_TI_DAYS[$d], $days[$d], round($avail[$d], 1), round($used[$d], 1), $n[$d], $avail[$d] > 0 ? self::pct($used[$d], $avail[$d]) : null, round(max(0, $avail[$d] - $used[$d]), 1), $used[$d] > $avail[$d] ? round($used[$d] - $avail[$d], 1) : null];
        }
        if ($out) $out[] = ['Razem', array_sum($days), round($ta, 1), round($tu, 1), array_sum($n), $ta > 0 ? self::pct($tu, $ta) : null, round(max(0, $ta - $tu), 1), $tu > $ta ? round($tu - $ta, 1) : null];
        return ['subtitle' => 'okna dostępności zatwierdzone, z uwzględnieniem ich ważności; zajęcia odbyte + zaplanowane', 'headers' => ['Dzień tygodnia', 'Dni w okresie', 'Dostępność (h)', 'Zajęcia (h)', 'Lekcje', 'Wykorzystanie %', 'Wolne (h)', 'Poza oknami (h)'],
                'rows' => $out, 'num' => [1, 2, 3, 4, 5, 6, 7], 'dec' => [2 => 1, 3 => 1, 5 => 1, 6 => 1, 7 => 1], 'sheet' => 'Dostępność',
                'notes' => $win ? [] : ['Nie masz zatwierdzonych okien dostępności — kolumna „Dostępność” jest pusta. Okna ustawisz w zakładce Dostępność.']];
    }

    // ── 8. Kursanci ──────────────────────────────────────────────────────────
    private static function r_kursanci(int $uid, array $r): array
    {
        $rows = db_all(
            "SELECT cl.id AS client_id, cl.name AS client, c.name AS course_name, s.status, a.attended, a.cancelled, a.no_show, " . self::HOURS . " AS h
               FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id
               JOIN k30_ti_attendance a ON a.session_id = s.id JOIN k30_clients cl ON cl.id = a.client_id
              WHERE COALESCE(s.instructor_id, c.instructor_id) = CAST(? AS INTEGER) AND s.lesson_date BETWEEN ? AND ? AND " . self::BASE_EXCL . "
                AND " . ti_test_client_sql('cl.id') . " AND " . ti_test_course_sql('s.course_id') . "
              ORDER BY cl.name", [$uid, $r['from'], $r['to']]);
        $g = [];
        foreach ($rows as $x) {
            $k = (int)$x['client_id']; $g[$k] ??= ['name' => (string)$x['client'], 'courses' => [], 'present' => 0.0, 'absent' => 0.0, 'absent_n' => 0, 'noshow' => 0, 'cancelled' => 0.0, 'planned' => 0.0];
            $g[$k]['courses'][(string)$x['course_name']] = 1; $h = (float)$x['h'];
            if ($x['status'] === 'cancelled') { $g[$k]['cancelled'] += $h; continue; }
            if ($x['status'] === 'planned') { $g[$k]['planned'] += $h; continue; }
            if (!in_array((string)$x['status'], K30_TI_HELD_STATUSES, true)) continue;
            if ((int)$x['cancelled'] === 1) { $g[$k]['cancelled'] += $h; }
            elseif ((int)$x['attended'] === 1) { $g[$k]['present'] += $h; }
            else { $g[$k]['absent'] += $h; $g[$k]['absent_n']++; if ((int)($x['no_show'] ?? 0) === 1) $g[$k]['noshow']++; }
        }
        uasort($g, fn($a, $b) => $b['present'] <=> $a['present']); $out = [];
        foreach ($g as $x) {
            $held = $x['present'] + $x['absent'];
            $out[] = [$x['name'], implode(', ', array_keys($x['courses'])), round($x['present'], 1), round($x['absent'], 1), $x['absent_n'], $x['noshow'], $held > 0 ? self::pct($x['present'], $held) : null, round($x['cancelled'], 1), round($x['planned'], 1)];
        }
        return ['subtitle' => 'wg obecności na Twoich odbytych lekcjach', 'headers' => ['Kursant', 'Grupy', 'Obecny (h)', 'Nieobecny (h)', 'Nieobecności', 'W tym bez uprzedzenia', 'Frekwencja %', 'Odwołane dla kursanta (h)', 'Zaplanowane (h)'],
                'rows' => $out, 'num' => [2, 3, 4, 5, 6, 7, 8], 'dec' => [2 => 1, 3 => 1, 6 => 1, 7 => 1, 8 => 1], 'sheet' => 'Kursanci'];
    }

    // ── 9. Dokumentacja i zaległości ─────────────────────────────────────────
    private static function r_dokumentacja(int $uid, array $r): array
    {
        $today = date('Y-m-d'); $out = [];
        foreach (self::sessions($uid, $r) as $s) {
            $issue = '';
            if (self::isHeld($s) && !(int)$s['docs_ok']) {
                $miss = []; if (trim((string)$s['topic']) === '') $miss[] = 'brak tematu';
                if ($s['status'] !== 'remote_material' && (int)($s['track_attendance'] ?? 1) === 1 && !db_one("SELECT 1 FROM k30_ti_attendance WHERE session_id=?", [(int)$s['id']])) $miss[] = 'brak obecności';
                $issue = 'Niekompletna dokumentacja: ' . ($miss ? implode(', ', $miss) : 'oznacz ręcznie');
            } elseif ($s['status'] === 'planned' && (string)$s['lesson_date'] < $today) {
                $issue = 'Minęła, a wciąż „zaplanowana” — potwierdź obecność albo odwołaj';
            }
            if ($issue === '') continue;
            $out[] = [date('d.m.Y', strtotime((string)$s['lesson_date'])), substr((string)$s['time_from'], 0, 5) . '–' . substr((string)$s['time_to'], 0, 5), (string)$s['course_name'], K30_TI_SESSION_STATUSES[(string)$s['status']]['label'] ?? (string)$s['status'], round((float)$s['h'], 1), $issue];
        }
        return ['subtitle' => count($out) ? count($out) . ' lekcji do uzupełnienia' : 'wszystko uzupełnione', 'headers' => ['Data', 'Godziny', 'Grupa', 'Status', 'Godz.', 'Co brakuje'], 'rows' => $out, 'num' => [4], 'dec' => [4 => 1], 'sheet' => 'Dokumentacja'];
    }

    // ── 10. Trend 12 miesięcy ────────────────────────────────────────────────
    private static function r_trend(int $uid, array $r): array
    {
        $end = $r['m'] !== '' ? $r['m'] : substr($r['to'], 0, 7);
        $start = date('Y-m-01', strtotime($end . '-01 -11 months'));
        $rows = self::sessions($uid, ['from' => $start, 'to' => date('Y-m-t', strtotime($end . '-01'))]);
        $m = [];
        for ($i = 0; $i < 12; $i++) { $k = date('Y-m', strtotime($start . " +$i months")); $m[$k] = ['held' => 0.0, 'held_n' => 0, 'planned' => 0.0, 'cancelled' => 0.0, 'groups' => [], 'payout' => 0.0]; }
        foreach ($rows as $s) {
            $k = substr((string)$s['lesson_date'], 0, 7); if (!isset($m[$k])) continue; $h = (float)$s['h'];
            if (self::isHeld($s)) { $m[$k]['held'] += $h; $m[$k]['held_n']++; $m[$k]['payout'] += (float)($s['lesson_payout_bb'] ?? 0); $m[$k]['groups'][(int)$s['course_id']] = 1; }
            elseif ($s['status'] === 'planned') { $m[$k]['planned'] += $h; $m[$k]['groups'][(int)$s['course_id']] = 1; }
            elseif ($s['status'] === 'cancelled') $m[$k]['cancelled'] += $h;
        }
        $out = []; $vals = [];
        foreach ($m as $k => $x) { $plan = $x['held'] + $x['planned']; $vals[] = $plan; $out[] = [self::monthLabel($k), round($x['held'], 1), $x['held_n'], round($x['planned'], 1), round($plan, 1), round($x['cancelled'], 1), count($x['groups']), round($x['payout'], 2)]; }
        $nz = array_filter($vals, fn($v) => $v > 0);
        $notes = $nz ? ['Średnio ' . number_format(array_sum($nz) / count($nz), 1, ',', ' ') . ' h w miesiącu z zajęciami; najwięcej ' . number_format(max($nz), 1, ',', ' ') . ' h, najmniej ' . number_format(min($nz), 1, ',', ' ') . ' h.'] : [];
        return ['subtitle' => 'od ' . self::monthLabel(substr($start, 0, 7)) . ' do ' . self::monthLabel($end), 'headers' => ['Miesiąc', 'Odbyte (h)', 'Lekcje odbyte', 'Zaplanowane (h)', 'Razem (h)', 'Odwołane (h)', 'Grupy', 'Wypłata BB (zł)'],
                'rows' => $out, 'num' => [1, 2, 3, 4, 5, 6, 7], 'dec' => [1 => 1, 3 => 1, 4 => 1, 5 => 1, 7 => 2], 'sheet' => 'Trend', 'notes' => $notes];
    }

    /* ── Raport instytucji: godziny w miesiącach (wszystkie grupy i prowadzący; ekran raportGodzin.php) ───────── */

    /** Lata, w których są jakiekolwiek lekcje (malejąco); zawsze zawiera bieżący rok. */
    public static function orgYears(): array
    {
        $y = array_map('intval', array_column(db_all(
            "SELECT DISTINCT substr(s.lesson_date,1,4) AS y FROM k30_ti_sessions s WHERE " . self::BASE_EXCL . " AND s.lesson_date GLOB '[0-9][0-9][0-9][0-9]-*' AND " . ti_test_course_sql('s.course_id')
        ), 'y'));
        $y[] = (int)date('Y');
        $y = array_values(array_unique($y)); rsort($y);
        return $y;
    }

    /** Agregat godzin per $groupExpr (SQL) w zakresie dat: odbyte (lekcje, godziny), zaplanowane, odwołane. */
    private static function orgAgg(string $from, string $to, string $groupExpr, string $join = ''): array
    {
        return db_all(
            "SELECT $groupExpr AS k,
                    SUM(CASE WHEN s.status IN (" . self::HELD . ") THEN 1 ELSE 0 END)                 AS held_n,
                    SUM(CASE WHEN s.status IN (" . self::HELD . ") THEN " . self::HOURS . " ELSE 0 END) AS held_h,
                    SUM(CASE WHEN s.status = 'planned'   THEN " . self::HOURS . " ELSE 0 END)           AS planned_h,
                    SUM(CASE WHEN s.status = 'cancelled' THEN " . self::HOURS . " ELSE 0 END)           AS cancelled_h
               FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id $join
              WHERE s.lesson_date BETWEEN ? AND ? AND " . self::BASE_EXCL . " AND " . ti_test_course_sql('s.course_id') . "
              GROUP BY k",
            [$from, $to]
        );
    }

    private const ORG_HEADERS = ['Lekcje odbyte', 'Godziny odbyte', 'Godziny zaplanowane', 'Godziny odwołane', 'Godziny razem (odbyte + zaplanowane)'];

    /** Wiersz zestawu: [etykieta, lekcje odbyte, godz. odbyte, zaplanowane, odwołane, razem]. */
    private static function orgRow(string $label, ?array $a): array
    {
        $held = round((float)($a['held_h'] ?? 0), 1); $pl = round((float)($a['planned_h'] ?? 0), 1);
        return [$label, (int)($a['held_n'] ?? 0), $held, $pl, round((float)($a['cancelled_h'] ?? 0), 1), round($held + $pl, 1)];
    }

    private static function orgTotal(array $rows): array
    {
        $t = ['Razem', 0, 0.0, 0.0, 0.0, 0.0];
        foreach ($rows as $r) for ($i = 1; $i <= 5; $i++) $t[$i] += $r[$i];
        for ($i = 2; $i <= 5; $i++) $t[$i] = round($t[$i], 1);
        return $t;
    }

    /** Zestaw: godziny w poszczególnych miesiącach roku (12 wierszy + Razem). */
    public static function orgYear(int $year): array
    {
        $by = [];
        foreach (self::orgAgg($year . '-01-01', $year . '-12-31', "substr(s.lesson_date,1,7)") as $a) $by[(string)$a['k']] = $a;
        $rows = [];
        for ($m = 1; $m <= 12; $m++) { $k = sprintf('%04d-%02d', $year, $m); $rows[] = self::orgRow(self::monthLabel($k), $by[$k] ?? null); }
        $rows[] = self::orgTotal($rows);
        return ['title' => 'Godziny TI w miesiącach — ' . $year, 'subtitle' => 'wszystkie grupy i prowadzący, rok ' . $year,
            'headers' => array_merge(['Miesiąc'], self::ORG_HEADERS), 'rows' => $rows, 'num' => [1, 2, 3, 4, 5], 'dec' => [2 => 1, 3 => 1, 4 => 1, 5 => 1], 'sheet' => 'Godziny ' . $year,
            'notes' => ['Odbyte = lekcje zakończone (w tym zajęcia indywidualne i praca prowadzącego); zaplanowane = jeszcze nieodbyte; odwołane nie wchodzą do sumy „razem”. Bez wersji roboczych, rezerwacji i kont testowych.']];
    }

    /** Zestaw: godziny jednego miesiąca z podziałem na prowadzących ('prowadzacy') albo grupy ('grupa'). */
    public static function orgMonthBy(string $m, string $by): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) $m = date('Y-m');
        $from = $m . '-01'; $to = date('Y-m-t', strtotime($from));
        if ($by === 'grupa') {
            $agg = self::orgAgg($from, $to, "c.name"); $label = 'Grupa';
        } else {
            $by = 'prowadzacy'; $label = 'Prowadzący';
            $agg = self::orgAgg($from, $to, "COALESCE(NULLIF(TRIM(COALESCE(u.first_name,'')||' '||COALESCE(u.last_name,'')),''), u.name, '— bez prowadzącego —')",
                "LEFT JOIN users u ON u.id = COALESCE(s.instructor_id, c.instructor_id)");
        }
        usort($agg, fn($a, $b) => ((float)$b['held_h'] + (float)$b['planned_h']) <=> ((float)$a['held_h'] + (float)$a['planned_h']) ?: strcmp((string)$a['k'], (string)$b['k']));
        $rows = array_map(fn($a) => self::orgRow((string)$a['k'], $a), $agg);
        if ($rows) $rows[] = self::orgTotal($rows);
        return ['title' => 'Godziny TI — ' . self::monthLabel($m) . ' wg ' . ($by === 'grupa' ? 'grup' : 'prowadzących'), 'subtitle' => self::monthLabel($m),
            'headers' => array_merge([$label], self::ORG_HEADERS), 'rows' => $rows, 'num' => [1, 2, 3, 4, 5], 'dec' => [2 => 1, 3 => 1, 4 => 1, 5 => 1], 'sheet' => mb_substr(self::monthLabel($m), 0, 31), 'notes' => []];
    }
}
