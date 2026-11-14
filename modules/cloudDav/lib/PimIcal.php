<?php
declare(strict_types=1);

/**
 * modules/cloudDav/lib/PimIcal.php — wydarzenia z Microsoft Graph → iCalendar (RFC 5545) do importu w Nextcloud (occ calendar:import).
 *
 * Zasady (polecenie 2026-11-14 „zrób po API graph import kalendarzy i kontaktów z MS365”):
 *   - czasy w strefie Europe/Warsaw (GraphPim prosi o nią nagłówkiem Prefer), DTSTART;TZID=… z blokiem VTIMEZONE; całodniowe jako DATE;
 *   - UID = iCalUId z Outlooka (ponowny import nadpisuje, nie dubluje); serie: RRULE z wzorca Graph, wyjątki jako osobne VEVENT
 *     z RECURRENCE-ID, wystąpienia usunięte jako EXDATE (oczekiwane wystąpienia z expand() porównane z /instances);
 *   - BEZ ORGANIZER i ATTENDEE: Nextcloud przy zapisie wydarzeń z uczestnikami mógłby wysłać im zaproszenia, więc organizatora
 *     i listę uczestników dopisujemy do opisu; przypomnienie (VALARM) zostaje, poufność → CLASS, „wolny” → TRANSP:TRANSPARENT.
 */
final class PimIcal
{
    public const TZ = 'Europe/Warsaw';
    private const DAYS = ['sunday' => 'SU', 'monday' => 'MO', 'tuesday' => 'TU', 'wednesday' => 'WE', 'thursday' => 'TH', 'friday' => 'FR', 'saturday' => 'SA'];
    private const DAYN = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
    private const IDX = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'last' => -1];
    private const WIN_TZ = ['UTC' => 'UTC', 'Central European Standard Time' => 'Europe/Warsaw', 'Central Europe Standard Time' => 'Europe/Warsaw',
        'W. Europe Standard Time' => 'Europe/Berlin', 'Romance Standard Time' => 'Europe/Paris', 'GMT Standard Time' => 'Europe/London',
        'FLE Standard Time' => 'Europe/Kiev', 'E. Europe Standard Time' => 'Europe/Bucharest', 'Eastern Standard Time' => 'America/New_York',
        'Pacific Standard Time' => 'America/Los_Angeles'];

    /** Cały plik .ics: nagłówek, strefa Europe/Warsaw, wydarzenia (wynik events() sklejony). */
    public static function calendar(string $name, string $events): string
    {
        return self::lines(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//FEER SZO//Import z Microsoft 365//PL', 'CALSCALE:GREGORIAN',
                'X-WR-CALNAME:' . self::esc($name), 'X-WR-TIMEZONE:' . self::TZ])
             . self::vtimezone() . $events . "END:VCALENDAR\r\n";
    }

    public static function vtimezone(): string
    {
        return self::lines(['BEGIN:VTIMEZONE', 'TZID:' . self::TZ,
            'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'TZNAME:CEST', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
            'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZNAME:CET', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
            'END:VTIMEZONE']);
    }

    /**
     * VEVENT-y jednego wydarzenia z listy Graph: pojedyncze albo seria (z wyjątkami i EXDATE, gdy podano $instances serii).
     * $instances — wynik GraphPim::instances() dla serii (occurrence + exception) w oknie [$winFrom, $winTo] (lokalnie).
     */
    public static function events(array $ev, array $instances = [], ?\DateTimeImmutable $winFrom = null, ?\DateTimeImmutable $winTo = null): string
    {
        $type = (string)($ev['type'] ?? 'singleInstance');
        if ($type !== 'seriesMaster' || empty($ev['recurrence']['pattern'])) return self::vevent($ev);
        $allDay = !empty($ev['isAllDay']);
        $start = self::dt((array)$ev['start']);
        $extra = ['RRULE:' . self::rrule((array)$ev['recurrence'], $start, $allDay)];
        $exceptions = '';
        if ($instances && $winFrom && $winTo) {
            $seen = [];
            foreach ($instances as $in) {
                $orig = self::originalLocal($in);
                if ($orig === null) continue;
                $seen[self::key($orig, $allDay)] = true;
                // Graph nadaje każdemu wystąpieniu własny iCalUId — wyjątek musi mieć UID serii (inaczej RECURRENCE-ID bez serii)
                if (($in['type'] ?? '') === 'exception') $exceptions .= self::vevent(['iCalUId' => self::uidOf($ev)] + $in, $orig, $allDay);
            }
            $ex = [];
            foreach (self::expand((array)$ev['recurrence'], $start, $winFrom, $winTo) as $occ) {
                if (!isset($seen[self::key($occ, $allDay)])) $ex[] = $allDay ? $occ->format('Ymd') : $occ->format('Ymd\THis');
            }
            foreach (array_chunk($ex, 40) as $chunk) $extra[] = ($allDay ? 'EXDATE;VALUE=DATE:' : 'EXDATE;TZID=' . self::TZ . ':') . implode(',', $chunk);
        }
        return self::vevent($ev, null, $allDay, $extra) . $exceptions;
    }

    /** Jeden VEVENT. $recurrenceId — wyjątek serii (pierwotny początek); $extra — linie RRULE/EXDATE serii. */
    private static function vevent(array $ev, ?\DateTimeImmutable $recurrenceId = null, ?bool $allDayOverride = null, array $extra = []): string
    {
        $allDay = $allDayOverride ?? !empty($ev['isAllDay']);
        $start = self::dt((array)($ev['start'] ?? []));
        $end = !empty($ev['end']) ? self::dt((array)$ev['end']) : $start->modify($allDay ? '+1 day' : '+1 hour');
        if ($end <= $start) $end = $start->modify($allDay ? '+1 day' : '+30 minutes');
        $l = ['BEGIN:VEVENT', 'UID:' . self::esc(self::uidOf($ev)), 'DTSTAMP:' . self::utc((string)($ev['lastModifiedDateTime'] ?? ''))];
        if (!empty($ev['createdDateTime'])) $l[] = 'CREATED:' . self::utc((string)$ev['createdDateTime']);
        if (!empty($ev['lastModifiedDateTime'])) $l[] = 'LAST-MODIFIED:' . self::utc((string)$ev['lastModifiedDateTime']);
        if ($recurrenceId) $l[] = $allDay ? 'RECURRENCE-ID;VALUE=DATE:' . $recurrenceId->format('Ymd') : 'RECURRENCE-ID;TZID=' . self::TZ . ':' . $recurrenceId->format('Ymd\THis');
        if ($allDay) { $l[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd'); $l[] = 'DTEND;VALUE=DATE:' . $end->format('Ymd'); }
        else { $l[] = 'DTSTART;TZID=' . self::TZ . ':' . $start->format('Ymd\THis'); $l[] = 'DTEND;TZID=' . self::TZ . ':' . $end->format('Ymd\THis'); }
        $l[] = 'SUMMARY:' . self::esc(trim((string)($ev['subject'] ?? '')) ?: '(bez tytułu)');
        $loc = trim((string)($ev['location']['displayName'] ?? ''));
        if ($loc !== '') $l[] = 'LOCATION:' . self::esc($loc);
        $desc = self::description($ev);
        if ($desc !== '') $l[] = 'DESCRIPTION:' . self::esc($desc);
        $join = (string)($ev['onlineMeeting']['joinUrl'] ?? '');
        if ($join !== '' && preg_match('~^https://[^\s"<>]+$~', $join)) $l[] = 'URL:' . $join;
        $cats = array_values(array_filter(array_map(fn($c) => trim((string)$c), (array)($ev['categories'] ?? []))));
        if ($cats) $l[] = 'CATEGORIES:' . implode(',', array_map([self::class, 'esc'], $cats));
        $l[] = 'CLASS:' . match ((string)($ev['sensitivity'] ?? 'normal')) { 'private', 'personal' => 'PRIVATE', 'confidential' => 'CONFIDENTIAL', default => 'PUBLIC' };
        $l[] = 'TRANSP:' . (($ev['showAs'] ?? '') === 'free' ? 'TRANSPARENT' : 'OPAQUE');
        if (!empty($ev['isCancelled'])) $l[] = 'STATUS:CANCELLED';
        elseif (($ev['showAs'] ?? '') === 'tentative') $l[] = 'STATUS:TENTATIVE';
        foreach ($extra as $x) $l[] = $x;
        if (!empty($ev['isReminderOn']) && empty($ev['isCancelled'])) {
            $m = max(0, (int)($ev['reminderMinutesBeforeStart'] ?? 15));
            array_push($l, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . self::esc(trim((string)($ev['subject'] ?? '')) ?: 'Przypomnienie'), 'TRIGGER:-PT' . $m . 'M', 'END:VALARM');
        }
        $l[] = 'END:VEVENT';
        return self::lines($l);
    }

    /** Opis: treść (tekst), organizator, uczestnicy, link do spotkania online. */
    private static function description(array $ev): string
    {
        $parts = [];
        $body = trim(str_replace(["\r\n", "\r"], "\n", (string)($ev['body']['content'] ?? '')));
        if (($ev['body']['contentType'] ?? 'text') === 'html') $body = trim(html_entity_decode(strip_tags((string)preg_replace('~<br\s*/?>|</p>~i', "\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $body = (string)preg_replace("/\n{3,}/", "\n\n", $body);
        if ($body !== '') $parts[] = mb_substr($body, 0, 20000);
        $who = static fn(array $e): string => trim(trim((string)($e['name'] ?? '')) . ((string)($e['address'] ?? '') !== '' ? ' <' . $e['address'] . '>' : ''));
        $meta = [];
        if (!empty($ev['organizer']['emailAddress'])) $meta[] = 'Organizator: ' . $who((array)$ev['organizer']['emailAddress']);
        $att = [];
        foreach ((array)($ev['attendees'] ?? []) as $a) if (!empty($a['emailAddress'])) $att[] = $who((array)$a['emailAddress']);
        if ($att) $meta[] = 'Uczestnicy: ' . implode(', ', array_slice($att, 0, 200));
        $join = (string)($ev['onlineMeeting']['joinUrl'] ?? '');
        if ($join !== '') $meta[] = 'Spotkanie online: ' . $join;
        if ($meta) $parts[] = implode("\n", $meta) . "\n(Przeniesione z kalendarza Microsoft 365)";
        return implode("\n\n", $parts);
    }

    /** RRULE z wzorca Graph (pattern + range). */
    public static function rrule(array $rec, \DateTimeImmutable $start, bool $allDay): string
    {
        $p = (array)($rec['pattern'] ?? []);
        $r = (array)($rec['range'] ?? []);
        $int = max(1, (int)($p['interval'] ?? 1));
        $days = array_values(array_filter(array_map(fn($d) => self::DAYS[strtolower((string)$d)] ?? null, (array)($p['daysOfWeek'] ?? []))));
        $idx = self::IDX[strtolower((string)($p['index'] ?? 'first'))] ?? 1;
        $relDays = count($days) === 1 ? 'BYDAY=' . $idx . $days[0] : 'BYDAY=' . implode(',', $days) . ';BYSETPOS=' . $idx;
        $rule = match ((string)($p['type'] ?? 'daily')) {
            'weekly'          => 'FREQ=WEEKLY;BYDAY=' . ($days ? implode(',', $days) : self::DAYS[strtolower($start->format('l'))]) . ';WKST=' . (self::DAYS[strtolower((string)($p['firstDayOfWeek'] ?? 'monday'))] ?? 'MO'),
            'absoluteMonthly' => 'FREQ=MONTHLY;BYMONTHDAY=' . max(1, (int)($p['dayOfMonth'] ?? $start->format('j'))),
            'relativeMonthly' => 'FREQ=MONTHLY;' . ($days ? $relDays : 'BYMONTHDAY=' . $start->format('j')),
            'absoluteYearly'  => 'FREQ=YEARLY;BYMONTH=' . max(1, (int)($p['month'] ?? $start->format('n'))) . ';BYMONTHDAY=' . max(1, (int)($p['dayOfMonth'] ?? $start->format('j'))),
            'relativeYearly'  => 'FREQ=YEARLY;BYMONTH=' . max(1, (int)($p['month'] ?? $start->format('n'))) . ';' . ($days ? $relDays : 'BYMONTHDAY=' . $start->format('j')),
            default           => 'FREQ=DAILY',
        };
        if ($int > 1) $rule .= ';INTERVAL=' . $int;
        $rt = (string)($r['type'] ?? 'noEnd');
        if ($rt === 'endDate' && !empty($r['endDate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$r['endDate'])) {
            if ($allDay) $rule .= ';UNTIL=' . str_replace('-', '', (string)$r['endDate']);
            else {
                $u = new \DateTimeImmutable((string)$r['endDate'] . ' ' . $start->format('H:i:s'), new \DateTimeZone(self::TZ));
                $rule .= ';UNTIL=' . $u->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
            }
        } elseif ($rt === 'numbered' && (int)($r['numberOfOccurrences'] ?? 0) > 0) {
            $rule .= ';COUNT=' . (int)$r['numberOfOccurrences'];
        }
        return $rule;
    }

    /**
     * Oczekiwane wystąpienia serii (czas lokalny Europe/Warsaw) w oknie [$from, $to], najwyżej $max. Ta sama semantyka co rrule():
     * miesiące bez danego dnia (np. 31.) pomijamy. COUNT liczy wystąpienia od początku serii, także te przed oknem.
     * @return list<\DateTimeImmutable>
     */
    public static function expand(array $rec, \DateTimeImmutable $start, \DateTimeImmutable $from, \DateTimeImmutable $to, int $max = 3000): array
    {
        $p = (array)($rec['pattern'] ?? []);
        $r = (array)($rec['range'] ?? []);
        $int = max(1, (int)($p['interval'] ?? 1));
        $limit = $to;
        if (($r['type'] ?? '') === 'endDate' && !empty($r['endDate'])) {
            $e = new \DateTimeImmutable((string)$r['endDate'] . ' 23:59:59', new \DateTimeZone(self::TZ));
            if ($e < $limit) $limit = $e;
        }
        $count = ($r['type'] ?? '') === 'numbered' ? max(0, (int)($r['numberOfOccurrences'] ?? 0)) : PHP_INT_MAX;
        $time = $start->format('H:i:s');
        $tz = new \DateTimeZone(self::TZ);
        $mk = static fn(int $y, int $m, int $d) => checkdate($m, $d, $y) ? new \DateTimeImmutable(sprintf('%04d-%02d-%02d %s', $y, $m, $d, $time), $tz) : null;
        $days = array_values(array_filter(array_map(fn($d) => self::DAYN[strtolower((string)$d)] ?? null, (array)($p['daysOfWeek'] ?? [])), fn($x) => $x !== null));
        $idx = self::IDX[strtolower((string)($p['index'] ?? 'first'))] ?? 1;
        $out = [];
        $n = 0;
        $emit = function (?\DateTimeImmutable $d) use (&$out, &$n, $start, $from, $limit, $count, $max): bool {
            if ($d === null || $d < $start) return true;
            if ($d > $limit || $n >= $count || count($out) >= $max) return false;
            $n++;
            if ($d >= $from) $out[] = $d;
            return true;
        };
        // dzień tygodnia w miesiącu: wszystkie dni z $days w miesiącu, wybór po indeksie (BYSETPOS)
        $relative = function (int $y, int $m) use ($days, $idx, $mk): ?\DateTimeImmutable {
            $c = [];
            $dim = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
            for ($d = 1; $d <= $dim; $d++) {
                $x = $mk($y, $m, $d);
                if ($x && in_array((int)$x->format('w'), $days, true)) $c[] = $x;
            }
            if (!$c) return null;
            return $idx === -1 ? end($c) : ($c[$idx - 1] ?? null);
        };
        $type = (string)($p['type'] ?? 'daily');
        $guard = 0;
        switch ($type) {
            case 'daily':
                for ($d = $start; $guard++ < 100000; $d = $d->modify('+' . $int . ' days')) if (!$emit($d)) break;
                break;
            case 'weekly':
                if (!$days) $days = [(int)$start->format('w')];
                $wkst = self::DAYN[strtolower((string)($p['firstDayOfWeek'] ?? 'monday'))] ?? 1;
                $back = ((int)$start->format('w') - $wkst + 7) % 7;
                $week = $start->modify('-' . $back . ' days');
                $offs = array_map(fn($d) => ($d - $wkst + 7) % 7, $days);
                sort($offs);
                for (; $guard++ < 20000; $week = $week->modify('+' . $int . ' weeks')) {
                    foreach ($offs as $o) if (!$emit($week->modify('+' . $o . ' days'))) break 2;
                }
                break;
            case 'absoluteMonthly':
            case 'relativeMonthly':
                $y = (int)$start->format('Y'); $m = (int)$start->format('n');
                for (; $guard++ < 5000; $m += $int) {
                    while ($m > 12) { $m -= 12; $y++; }
                    $d = $type === 'absoluteMonthly' ? $mk($y, $m, max(1, (int)($p['dayOfMonth'] ?? $start->format('j')))) : $relative($y, $m);
                    $first = $mk($y, $m, 1);
                    if ($first && $first > $limit) break;
                    if (!$emit($d)) break;
                }
                break;
            case 'absoluteYearly':
            case 'relativeYearly':
                $mo = max(1, min(12, (int)($p['month'] ?? $start->format('n'))));
                for ($y = (int)$start->format('Y'); $guard++ < 500; $y += $int) {
                    $d = $type === 'absoluteYearly' ? $mk($y, $mo, max(1, (int)($p['dayOfMonth'] ?? $start->format('j')))) : $relative($y, $mo);
                    $first = $mk($y, 1, 1);
                    if ($first && $first > $limit) break;
                    if (!$emit($d)) break;
                }
                break;
        }
        return $out;
    }

    /** Czas z Graph ({dateTime, timeZone}) jako Europe/Warsaw. */
    public static function dt(array $g): \DateTimeImmutable
    {
        $s = substr((string)($g['dateTime'] ?? ''), 0, 19);
        $zone = (string)($g['timeZone'] ?? self::TZ);
        try { $tz = new \DateTimeZone(self::WIN_TZ[$zone] ?? $zone); } catch (\Throwable $e) { $tz = new \DateTimeZone('UTC'); }
        try { $d = new \DateTimeImmutable($s !== '' ? $s : 'now', $tz); } catch (\Throwable $e) { $d = new \DateTimeImmutable('now', $tz); }
        return $d->setTimezone(new \DateTimeZone(self::TZ));
    }

    /** Pierwotny początek wystąpienia (originalStart w UTC; brak → start) jako Europe/Warsaw. */
    private static function originalLocal(array $in): ?\DateTimeImmutable
    {
        if (!empty($in['originalStart'])) {
            try { return (new \DateTimeImmutable((string)preg_replace('/\.\d+/', '', (string)$in['originalStart'])))->setTimezone(new \DateTimeZone(self::TZ)); } catch (\Throwable $e) {}
        }
        return !empty($in['start']) ? self::dt((array)$in['start']) : null;
    }

    /** UID wydarzenia: iCalUId z Outlooka, a bez niego stały skrót z id (ponowny import trafia w to samo wydarzenie). */
    private static function uidOf(array $ev): string
    {
        $u = trim((string)($ev['iCalUId'] ?? ''));
        return $u !== '' ? $u : 'graph-' . sha1((string)($ev['seriesMasterId'] ?? '') ?: (string)($ev['id'] ?? uniqid('', true)));
    }

    private static function key(\DateTimeImmutable $d, bool $allDay): string
    {
        return $allDay ? $d->format('Ymd') : (string)$d->getTimestamp();
    }

    private static function utc(string $s): string
    {
        try { $d = new \DateTimeImmutable($s !== '' ? (string)preg_replace('/\.\d+/', '', $s) : 'now', new \DateTimeZone('UTC')); }
        catch (\Throwable $e) { $d = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    public static function esc(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $s);
    }

    /** Linie z CRLF, zawinięte do 75 bajtów bez dzielenia znaków UTF-8. */
    public static function lines(array $lines): string
    {
        $out = '';
        foreach ($lines as $line) {
            $line = (string)$line;
            if (strlen($line) <= 75) { $out .= $line . "\r\n"; continue; }
            $cur = '';
            $first = true;
            foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                $lim = $first ? 75 : 74;
                if (strlen($cur) + strlen($ch) > $lim) { $out .= ($first ? '' : ' ') . $cur . "\r\n"; $cur = ''; $first = false; }
                $cur .= $ch;
            }
            $out .= ($first ? '' : ' ') . $cur . "\r\n";
        }
        return $out;
    }
}
