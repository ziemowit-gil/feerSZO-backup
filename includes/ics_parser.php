<?php
/**
 * includes/ics_parser.php — Lekki parser iCalendar (RFC 5545)
 *
 * Klasa IcsParser:
 *   IcsParser::fetch(url, ttl)  — pobierz + cache w /tmp/
 *   IcsParser::parse(ics_text) — zwraca tablicę eventów
 *
 * Obsługuje: VEVENT, DATE-TIME, DATE, TZID, line-folding, SUMMARY,
 *   DESCRIPTION, LOCATION, DTSTART, DTEND, STATUS, RRULE, UID.
 */

class IcsParser
{
    // ── Pobieranie i cache ─────────────────────────────────────────────────────

    /**
     * Pobiera ICS z URL, cache'uje na $ttl sekund.
     * Zwraca treść ICS lub null przy błędzie.
     */
    public static function fetch(string $url, int $ttl = 3600): ?string
    {
        if (!$url) return null;

        $cache_file = sys_get_temp_dir() . '/feer_org_cal_'
            . substr(md5($url), 0, 12) . '.ics';

        // Serwuj z cache jeśli świeży
        if (is_file($cache_file) && (time() - filemtime($cache_file)) < $ttl) {
            $cached = @file_get_contents($cache_file);
            if ($cached !== false) return $cached;
        }

        // Pobierz zdalnie
        $ctx = stream_context_create([
            'http' => [
                'timeout'          => 15,
                'follow_location'  => true,
                'max_redirects'    => 5,
                'user_agent'       => 'FEER-CRM-ICS/1.0',
                'header'           => self::auth_header($url),
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $ctx);
        if ($content === false || !str_contains($content, 'BEGIN:VCALENDAR')) {
            // Zwróć stary cache jeśli istnieje
            if (is_file($cache_file)) {
                return @file_get_contents($cache_file) ?: null;
            }
            return null;
        }

        @file_put_contents($cache_file, $content);
        return $content;
    }

    /**
     * Unieważnia cache dla danego URL.
     */
    public static function invalidate_cache(string $url): void
    {
        $cache_file = sys_get_temp_dir() . '/feer_org_cal_'
            . substr(md5($url), 0, 12) . '.ics';
        @unlink($cache_file);
    }

    // ── Parser ─────────────────────────────────────────────────────────────────

    /**
     * Parsuje tekst ICS i zwraca tablicę eventów posortowanych po dacie.
     *
     * Każdy event to asocjacyjna tablica:
     *   uid, summary, description, location, status,
     *   dtstart (DateTimeImmutable), dtend (DateTimeImmutable|null),
     *   all_day (bool), rrule (string|null)
     *
     * @return array<int, array>
     */
    public static function parse(string $ics): array
    {
        if (!$ics) return [];

        // Unfold: linie kontynuacyjne (RFC 5545 §3.1)
        $ics = preg_replace("/\r\n[ \t]/", '', $ics);
        $ics = preg_replace("/\r/", '', $ics);

        $lines  = explode("\n", $ics);
        $events = [];
        $in_event = false;
        $current  = [];

        foreach ($lines as $line) {
            $line = rtrim($line);

            if ($line === 'BEGIN:VEVENT') {
                $in_event = true;
                $current  = [];
                continue;
            }
            if ($line === 'END:VEVENT') {
                $in_event = false;
                if (!empty($current['dtstart'])) {
                    $events[] = $current;
                }
                continue;
            }
            if (!$in_event) continue;

            // Rozdziel property name (z params) od wartości
            $colon_pos = strpos($line, ':');
            if ($colon_pos === false) continue;

            $prop_raw = substr($line, 0, $colon_pos);
            $value    = substr($line, $colon_pos + 1);

            // Wyciągnij nazwę właściwości i parametry (np. TZID=...)
            $params = [];
            $parts  = explode(';', $prop_raw);
            $prop   = strtoupper(array_shift($parts));
            foreach ($parts as $p) {
                [$pk, $pv] = array_pad(explode('=', $p, 2), 2, '');
                $params[strtoupper($pk)] = $pv;
            }

            // Odkoduj wartość (escaping RFC 5545)
            $value = self::unescape($value);

            switch ($prop) {
                case 'UID':
                    $current['uid'] = $value;
                    break;
                case 'SUMMARY':
                    $current['summary'] = $value;
                    break;
                case 'DESCRIPTION':
                    $current['description'] = $value;
                    break;
                case 'LOCATION':
                    $current['location'] = $value;
                    break;
                case 'STATUS':
                    $current['status'] = $value; // CONFIRMED|CANCELLED|TENTATIVE
                    break;
                case 'RRULE':
                    $current['rrule'] = $value;
                    break;
                case 'DTSTART':
                    $current['dtstart']  = self::parse_dt($value, $params['TZID'] ?? null);
                    $current['all_day']  = !str_contains($value, 'T');
                    break;
                case 'DTEND':
                    $current['dtend'] = self::parse_dt($value, $params['TZID'] ?? null);
                    break;
                case 'DURATION':
                    $current['duration_raw'] = $value;
                    break;
                case 'URL':
                    $current['url'] = $value;
                    break;
                case 'CATEGORIES':
                    $current['categories'] = $value;
                    break;
                case 'COLOR':
                case 'X-APPLE-CALENDAR-COLOR':
                case 'X-MICROSOFT-CDO-BUSYSTATUS':
                    $current[strtolower($prop)] = $value;
                    break;
            }
        }

        // Uzupełnij dtend z DURATION jeśli brakuje
        foreach ($events as &$ev) {
            if (empty($ev['dtend']) && !empty($ev['dtstart']) && !empty($ev['duration_raw'])) {
                try {
                    $interval = new \DateInterval(self::parse_duration($ev['duration_raw']));
                    $end = \DateTimeImmutable::createFromInterface($ev['dtstart']);
                    $ev['dtend'] = $end->add($interval);
                } catch (\Throwable $e) {}
            }
            // Domyślny status
            $ev['status']      ??= 'CONFIRMED';
            $ev['summary']     ??= '(brak tytułu)';
            $ev['description'] ??= '';
            $ev['location']    ??= '';
            $ev['uid']         ??= uniqid('', true);
            $ev['rrule']       ??= null;
            $ev['url']         ??= '';
        }
        unset($ev);

        // Filtruj CANCELLED jeśli potrzeba (opcjonalne)
        $events = array_filter($events, fn($e) => $e['status'] !== 'CANCELLED');

        // Sortuj po dtstart rosnąco
        usort($events, function ($a, $b) {
            $ta = $a['dtstart'] instanceof \DateTimeInterface ? $a['dtstart']->getTimestamp() : 0;
            $tb = $b['dtstart'] instanceof \DateTimeInterface ? $b['dtstart']->getTimestamp() : 0;
            return $ta <=> $tb;
        });

        return array_values($events);
    }

    // ── Helpers prywatne ───────────────────────────────────────────────────────

    /**
     * Parsuje wartość DTSTART/DTEND do DateTimeImmutable.
     */
    private static function parse_dt(string $value, ?string $tzid): ?\DateTimeImmutable
    {
        $value = trim($value);
        if (!$value) return null;

        try {
            // Usuń "Z" suffix — UTC
            $is_utc = str_ends_with($value, 'Z');
            $clean  = rtrim($value, 'Z');

            if (!str_contains($clean, 'T')) {
                // DATE tylko (całodniowy) — np. 20260610
                $dt = \DateTimeImmutable::createFromFormat('Ymd', $clean,
                    new \DateTimeZone($tzid ?: 'UTC'));
                return $dt ? $dt->setTime(0, 0, 0) : null;
            }

            // DATE-TIME — np. 20260610T090000
            $tz = $is_utc
                ? new \DateTimeZone('UTC')
                : new \DateTimeZone($tzid ?: 'UTC');

            $dt = \DateTimeImmutable::createFromFormat('Ymd\THis', $clean, $tz);
            if (!$dt) {
                $dt = \DateTimeImmutable::createFromFormat('Ymd\THis', substr($clean, 0, 15), $tz);
            }
            if (!$dt) return null;

            // Konwertuj do lokalnej strefy
            $local = org_setting('timezone') ?: 'Europe/Warsaw';
            return $dt->setTimezone(new \DateTimeZone($local));

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Unescape wartości RFC 5545 (\\n \, itp.).
     */
    private static function unescape(string $v): string
    {
        return str_replace(
            ['\\n', '\\N', '\\,', '\\;', '\\\\'],
            ["\n",  "\n",  ',',   ';',   '\\'],
            $v
        );
    }

    /**
     * Konwertuje DURATION (np. P1DT2H) do formatu DateInterval.
     * Uproszczone: obsługuje P[n]D, PT[n]H[n]M[n]S, P[n]W.
     */
    private static function parse_duration(string $dur): string
    {
        // RFC 5545 DURATION już jest ISO 8601 — zwróć jak jest
        return strtoupper($dur);
    }

    /**
     * Buduje nagłówek autoryzacji jeśli URL zawiera user:pass.
     */
    private static function auth_header(string $url): string
    {
        $parsed = parse_url($url);
        if (!empty($parsed['user'])) {
            $creds = urldecode($parsed['user']) . ':' . urldecode($parsed['pass'] ?? '');
            return 'Authorization: Basic ' . base64_encode($creds);
        }
        return '';
    }
}
