<?php
/**
 * modules/strategy_actions/logic/ActionsReport.php — wyjścia rejestru działań:
 *   eksport CSV / Excel z wyborem kolumn (pliki przez wspólne AnalyticsExport: średnik + BOM, ochrona przed formułami),
 *   kalendarz ICS do subskrypcji (osobisty token, bez logowania — Outlook / Google / telefon),
 *   zestawienie realizacji celów statutowych §6 (liczba działań, statusy, finansowanie, wskaźniki, etapy).
 */
require_once __DIR__ . '/ActionsService.php';
require_once dirname(__DIR__, 2) . '/analytics/logic/analyticsExport.php';

final class ActionsReport
{
    /** Kolumny eksportu: klucz => [nagłówek, domyślnie zaznaczona]. */
    public const COLUMNS = [
        'id'        => ['ID', true],
        'nazwa'     => ['Nazwa', true],
        'typ'       => ['Typ', true],
        'status'    => ['Status', true],
        'koord'     => ['Koordynator', true],
        'od'        => ['Rozpoczęcie', true],
        'do'        => ['Zakończenie', true],
        'forma'     => ['Forma', false],
        'miejsce'   => ['Miejsce', false],
        'cel'       => ['Cel statutowy §6', false],
        'granty'    => ['Granty', true],
        'kwota'     => ['Kwota z grantów (zł)', true],
        'wlasne'    => ['Działanie własne', false],
        'wskPlan'   => ['Wskaźniki — plan (suma)', false],
        'wskReal'   => ['Wskaźniki — wykonanie (suma)', false],
        'pct'       => ['Realizacja wskaźników (%)', true],
        'etapy'     => ['Etapy zakończone / wszystkie', true],
        'nastepny'  => ['Najbliższy etap', false],
        'ostrzez'   => ['Ostrzeżenia', false],
        'opis'      => ['Opis', false],
    ];

    private const FLAG_LABELS = ['noGrant' => 'bez finansowania', 'overdue' => 'po terminie', 'ending' => 'kończy się ≤ 14 dni',
                                 'behind' => 'wskaźniki < 50%', 'msOverdue' => 'zaległy etap'];

    // ── Eksport ───────────────────────────────────────────────────────────────

    /**
     * Zestaw danych w formacie AnalyticsExport. $ids — kolejność i zakres z ekranu (po filtrach); puste = wszystkie.
     * @param list<string> $cols
     */
    public static function dataset(array $ids, array $cols): array
    {
        $cols = array_values(array_filter($cols, fn($c) => isset(self::COLUMNS[$c])));
        if (!$cols) $cols = array_keys(array_filter(self::COLUMNS, fn($c) => $c[1]));
        $byId = [];
        foreach (ActionsService::all() as $a) $byId[$a['id']] = $a;
        $order = $ids ? array_values(array_filter(array_map('intval', $ids), fn($i) => isset($byId[$i]))) : array_keys($byId);
        $goals = ActionsService::goals();
        $rows = [];
        foreach ($order as $id) {
            $a = $byId[$id];
            $row = [];
            foreach ($cols as $c) {
                $row[] = match ($c) {
                    'id'       => $a['id'],
                    'nazwa'    => $a['nazwa'],
                    'typ'      => ActionsService::typeInfo($a['typ'])['label'],
                    'status'   => ActionsService::statusLabel($a['status']),
                    'koord'    => $a['koordynator'],
                    'od'       => $a['od'] ? date('d.m.Y', strtotime($a['od'])) : '',
                    'do'       => $a['do'] ? date('d.m.Y', strtotime($a['do'])) : '',
                    'forma'    => ActionsService::FORMS[$a['forma']] ?? $a['forma'],
                    'miejsce'  => $a['lokalizacja'],
                    'cel'      => $a['cel'] ? $a['cel'] . '. ' . ($goals[$a['cel']] ?? '') : '',
                    'granty'   => implode(', ', array_column($a['grants'], 'nazwa')),
                    'kwota'    => (float)$a['kwota'],
                    'wlasne'   => $a['wlasne'] ? 'tak' : 'nie',
                    'wskPlan'  => (float)$a['indPlan'],
                    'wskReal'  => (float)$a['indReal'],
                    'pct'      => $a['pct'],
                    'etapy'    => $a['ms']['total'] ? $a['ms']['done'] . '/' . $a['ms']['total'] : '',
                    'nastepny' => $a['ms']['next'] ? $a['ms']['next']['title'] . ($a['ms']['next']['due'] ? ' (' . date('d.m.Y', strtotime($a['ms']['next']['due'])) . ')' : '') : '',
                    'ostrzez'  => implode(', ', array_map(fn($k) => self::FLAG_LABELS[$k], array_keys(array_filter($a['flags'])))),
                    'opis'     => $a['opis'],
                };
            }
            $rows[] = $row;
        }
        $dec = [];
        foreach ($cols as $i => $c) if (in_array($c, ['kwota', 'wskPlan', 'wskReal'], true)) $dec[$i] = 2;
        return [
            'title'    => 'Działania organizacji',
            'subtitle' => 'Eksport z ' . date('d.m.Y H:i') . ' — ' . count($rows) . ' działań' . ($ids ? ' (zgodnie z filtrami ekranu)' : ''),
            'sheet'    => 'Działania',
            'headers'  => array_map(fn($c) => self::COLUMNS[$c][0], $cols),
            'rows'     => $rows,
            'dec'      => $dec,
        ];
    }

    public static function send(array $ds, string $format, string $by): void
    {
        $name = 'dzialania_' . date('Y-m-d');
        if ($format === 'xlsx') AnalyticsExport::xlsx($ds, $by, $name . '.xlsx');
        else AnalyticsExport::csv($ds, $name . '.csv');
    }

    // ── Kalendarz ICS ─────────────────────────────────────────────────────────

    /** Adres subskrypcji kalendarza zalogowanej osoby (token tworzony przy pierwszym użyciu; $reset — nowy token). */
    public static function icsUrl(int $userId, bool $reset = false): string
    {
        actionsSchemaEnsure();
        $row = db_one("SELECT token FROM action_ics_tokens WHERE user_id = ?", [$userId]);
        if (!$row || $reset) {
            $token = bin2hex(random_bytes(20));
            db()->prepare("INSERT OR REPLACE INTO action_ics_tokens (user_id, token, created_at) VALUES (?,?,?)")
                ->execute([$userId, $token, date('Y-m-d H:i:s')]);
        } else {
            $token = (string)$row['token'];
        }
        return ActionsService::url('ics/' . $token . '.ics');
    }

    /** Właściciel tokenu — tylko konto, które nadal ma dostęp do Działań (admin / redaktor). */
    public static function icsOwner(string $token): ?array
    {
        actionsSchemaEnsure();
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) return null;
        $u = db_one("SELECT u.id, u.role FROM action_ics_tokens t JOIN users u ON u.id = t.user_id WHERE t.token = ?", [$token]);
        return $u && in_array($u['role'], ['admin', 'editor'], true) ? $u : null;
    }

    /** Treść kalendarza: działania z datą (bez anulowanych) i otwarte etapy z terminem. */
    public static function ics(): string
    {
        $host = (string)(parse_url(APP_URL, PHP_URL_HOST) ?: 'szo');
        $stamp = gmdate('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//FEER SZO//Dzialania//PL', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
                  'X-WR-CALNAME:' . self::esc('Działania organizacji'), 'X-WR-TIMEZONE:Europe/Warsaw'];
        $names = [];
        foreach (ActionsService::all() as $a) {
            $names[$a['id']] = $a['nazwa'];
            if ($a['status'] === 'anulowane' || !$a['od']) continue;
            $end = date('Ymd', strtotime(($a['do'] ?: $a['od']) . ' +1 day'));
            $desc = ActionsService::statusLabel($a['status']) . ($a['koordynator'] ? ' · koordynator: ' . $a['koordynator'] : '')
                  . "\n" . ActionsService::url((string)$a['id']);
            array_push($lines, 'BEGIN:VEVENT', 'UID:action-' . $a['id'] . '@' . $host, 'DTSTAMP:' . $stamp,
                'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($a['od'])), 'DTEND;VALUE=DATE:' . $end,
                'SUMMARY:' . self::esc($a['nazwa']), 'DESCRIPTION:' . self::esc($desc),
                'URL:' . ActionsService::url((string)$a['id']),
                'STATUS:' . ($a['status'] === 'zakończone' ? 'CONFIRMED' : 'TENTATIVE'));
            if ($a['lokalizacja'] !== '') $lines[] = 'LOCATION:' . self::esc($a['lokalizacja']);
            $lines[] = 'END:VEVENT';
        }
        actionsSchemaEnsure();
        foreach (db_all("SELECT m.id, m.action_id, m.title, m.due_date, u.name AS owner FROM action_milestones m
                         LEFT JOIN users u ON u.id = m.owner_id WHERE m.done_at IS NULL AND m.due_date IS NOT NULL") as $m) {
            if (!isset($names[(int)$m['action_id']])) continue;
            array_push($lines, 'BEGIN:VEVENT', 'UID:action-ms-' . $m['id'] . '@' . $host, 'DTSTAMP:' . $stamp,
                'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($m['due_date'])),
                'DTEND;VALUE=DATE:' . date('Ymd', strtotime($m['due_date'] . ' +1 day')),
                'SUMMARY:' . self::esc('Etap: ' . $m['title'] . ' — ' . $names[(int)$m['action_id']]),
                'DESCRIPTION:' . self::esc(($m['owner'] ? 'Odpowiada: ' . $m['owner'] . "\n" : '') . ActionsService::url((string)$m['action_id'])),
                'END:VEVENT');
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    private static function esc(string $s): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s);
    }

    /** Zawijanie linii ICS do 75 bajtów (RFC 5545) bez przecinania znaków UTF-8. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) return $line;
        $out = ''; $cur = '';
        foreach (mb_str_split($line) as $ch) {
            if (strlen($cur . $ch) > ($out === '' ? 75 : 74)) { $out .= ($out === '' ? '' : "\r\n ") . $cur; $cur = ''; }
            $cur .= $ch;
        }
        return $out . "\r\n " . $cur;
    }

    // ── Raport celów statutowych §6 ───────────────────────────────────────────

    /** Lata, w których są działania (do przełącznika raportu). */
    public static function years(): array
    {
        $ys = [(int)date('Y')];
        foreach (ActionsService::all() as $a) {
            foreach ([$a['od'], $a['do']] as $d) if ($d) $ys[] = (int)substr($d, 0, 4);
        }
        if ($ys) $ys = range(min($ys), max($ys));
        rsort($ys);
        return $ys;
    }

    /**
     * Zestawienie §6: [cel => label, actions[], count, byStatus{}, active, done, kwota, plan, real, pct, msDone, msTotal].
     * $year null = wszystkie lata; inaczej działania, których okres zahacza o rok (bez dat — pominięte).
     */
    public static function goalsReport(?int $year): array
    {
        $goals = ActionsService::goals();
        $out = [];
        foreach ($goals as $k => $l) $out[$k] = self::emptyGoal($k . '. ' . $l);
        $out[0] = self::emptyGoal('Bez przypisanego celu §6');
        foreach (ActionsService::all() as $a) {
            if ($year !== null) {
                $s = $a['od'] ?: $a['do'];
                $e = $a['do'] ?: $a['od'];
                if (!$s || $s > $year . '-12-31' || $e < $year . '-01-01') continue;
            }
            $k = $a['cel'] && isset($out[$a['cel']]) ? $a['cel'] : 0;
            $g = &$out[$k];
            $g['actions'][] = $a;
            $g['count']++;
            $g['byStatus'][$a['status']] = ($g['byStatus'][$a['status']] ?? 0) + 1;
            if (in_array($a['status'], ActionsService::ACTIVE, true)) $g['active']++;
            if ($a['status'] === 'zakończone') $g['done']++;
            if ($a['status'] !== 'anulowane') {
                $g['kwota'] += $a['kwota'];
                $g['plan'] += $a['indPlan'];
                $g['real'] += $a['indReal'];
                $g['msDone'] += $a['ms']['done'];
                $g['msTotal'] += $a['ms']['total'];
            }
            unset($g);
        }
        foreach ($out as &$g) $g['pct'] = $g['plan'] > 0 ? (int)min(100, round($g['real'] / $g['plan'] * 100)) : null;
        unset($g);
        if (!$out[0]['count']) unset($out[0]);
        return $out;
    }

    private static function emptyGoal(string $label): array
    {
        return ['label' => $label, 'actions' => [], 'count' => 0, 'byStatus' => [], 'active' => 0, 'done' => 0,
                'kwota' => 0.0, 'plan' => 0.0, 'real' => 0.0, 'pct' => null, 'msDone' => 0, 'msTotal' => 0];
    }

    /** Zestawienie §6 jako zestaw danych do CSV / Excela. */
    public static function goalsDataset(array $report, ?int $year): array
    {
        $rows = [];
        foreach ($report as $g) {
            $rows[] = [$g['label'], $g['count'], $g['active'], $g['done'], $g['byStatus']['anulowane'] ?? 0,
                       $g['kwota'], $g['plan'], $g['real'], $g['pct'], $g['msTotal'] ? $g['msDone'] . '/' . $g['msTotal'] : ''];
        }
        return [
            'title'    => 'Realizacja celów statutowych §6 — ' . ($year ?? 'wszystkie lata'),
            'subtitle' => 'Zestawienie działań organizacji, ' . date('d.m.Y H:i'),
            'sheet'    => 'Cele §6',
            'headers'  => ['Cel statutowy', 'Działania', 'W toku', 'Zakończone', 'Anulowane', 'Kwota z grantów (zł)',
                           'Wskaźniki — plan', 'Wskaźniki — wykonanie', 'Realizacja (%)', 'Etapy zakończone / wszystkie'],
            'rows'     => $rows,
            'dec'      => [5 => 2, 6 => 2, 7 => 2],
        ];
    }
}
