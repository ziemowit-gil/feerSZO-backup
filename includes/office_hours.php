<?php
/**
 * includes/office_hours.php — godziny pracy organizacji (wzór: moduł Office Hours z FreeScout).
 *
 * Jedna konfiguracja (org_setting office_hours_json): strefa czasowa, przedział pracy na każdy dzień tygodnia (puste = dzień wolny), dni wolne własne
 * oraz opcjonalnie polskie święta państwowe (liczone, łącznie z ruchomymi: Wielkanoc, Zielone Świątki, Boże Ciało; od 2025 także 24 XII).
 * Użycie: oh_is_open() — czy teraz (albo w podanej chwili) jest czas pracy; oh_next_open() — kiedy znów otwarte (do treści autoodpowiedzi);
 * oh_acks_allowed() — bramka automatycznych potwierdzeń: gdy włączona (office_hours_gate_acks=1), potwierdzenia przyjęcia wysyłamy TYLKO poza godzinami pracy.
 */

const OH_DAYS = [1 => 'Poniedziałek', 2 => 'Wtorek', 3 => 'Środa', 4 => 'Czwartek', 5 => 'Piątek', 6 => 'Sobota', 7 => 'Niedziela'];
const OH_TIMEZONES = ['Europe/Warsaw' => 'Polska (Europe/Warsaw)', 'Europe/London' => 'Wielka Brytania', 'Europe/Berlin' => 'Niemcy', 'UTC' => 'UTC'];

function oh_defaults(): array
{
    $d = [];
    foreach ([1, 2, 3, 4, 5] as $n) $d[$n] = ['08:00', '16:00'];
    $d[6] = null; $d[7] = null;
    return ['tz' => 'Europe/Warsaw', 'days' => $d, 'holidays' => [], 'pl_holidays' => true];
}

/** Konfiguracja z ustawień (z domyślnymi dla braków). */
function oh_config(): array
{
    $cfg = oh_defaults();
    $raw = json_decode((string)(function_exists('org_setting') ? org_setting('office_hours_json') : ''), true);
    if (is_array($raw)) {
        if (isset($raw['tz']) && isset(OH_TIMEZONES[$raw['tz']])) $cfg['tz'] = $raw['tz'];
        if (isset($raw['days']) && is_array($raw['days'])) {
            foreach (OH_DAYS as $n => $_) if (array_key_exists($n, $raw['days'])) $cfg['days'][$n] = oh_clean_interval($raw['days'][$n]);
        }
        if (isset($raw['holidays']) && is_array($raw['holidays'])) $cfg['holidays'] = oh_clean_dates($raw['holidays']);
        if (array_key_exists('pl_holidays', $raw)) $cfg['pl_holidays'] = (bool)$raw['pl_holidays'];
    }
    return $cfg;
}

/** [od, do] w formacie HH:MM (od < do) albo null = dzień wolny. */
function oh_clean_interval($iv): ?array
{
    if (!is_array($iv) || count($iv) < 2) return null;
    [$a, $b] = [trim((string)$iv[0]), trim((string)$iv[1])];
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $a) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $b) || $a >= $b) return null;
    return [$a, $b];
}

function oh_clean_dates(array $in): array
{
    $out = [];
    foreach ($in as $d) { $d = trim((string)$d); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) $out[$d] = $d; }
    ksort($out);
    return array_values($out);
}

/** Zapis z formularza: days[n][from|to], holidays (tekst: daty Y-m-d po przecinkach/liniach), pl_holidays, tz. */
function oh_save(array $post): array
{
    $cfg = oh_defaults();
    $cfg['tz'] = isset(OH_TIMEZONES[$post['tz'] ?? '']) ? $post['tz'] : 'Europe/Warsaw';
    foreach (OH_DAYS as $n => $_) {
        $on = !empty($post['days'][$n]['on']);
        $cfg['days'][$n] = $on ? oh_clean_interval([$post['days'][$n]['from'] ?? '', $post['days'][$n]['to'] ?? '']) : null;
    }
    $cfg['holidays'] = oh_clean_dates(preg_split('/[\s,;]+/', (string)($post['holidays'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $cfg['pl_holidays'] = !empty($post['pl_holidays']);
    org_setting_set('office_hours_json', json_encode($cfg, JSON_UNESCAPED_UNICODE));
    org_setting_set('office_hours_gate_acks', !empty($post['gate_acks']) ? '1' : '0');
    return $cfg;
}

/** Polskie święta państwowe (dni wolne od pracy) w roku: [Y-m-d => nazwa]. */
function oh_pl_holidays(int $year): array
{
    $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    $easter = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    $fmt = fn(DateTimeImmutable $x) => $x->format('Y-m-d');
    $out = [
        "$year-01-01" => 'Nowy Rok', "$year-01-06" => 'Trzech Króli', $fmt($easter) => 'Wielkanoc', $fmt($easter->modify('+1 day')) => 'Poniedziałek Wielkanocny',
        "$year-05-01" => 'Święto Pracy', "$year-05-03" => 'Święto Konstytucji 3 Maja', $fmt($easter->modify('+49 days')) => 'Zielone Świątki',
        $fmt($easter->modify('+60 days')) => 'Boże Ciało', "$year-08-15" => 'Wniebowzięcie NMP', "$year-11-01" => 'Wszystkich Świętych',
        "$year-11-11" => 'Święto Niepodległości', "$year-12-25" => 'Boże Narodzenie', "$year-12-26" => 'Drugi dzień Bożego Narodzenia',
    ];
    if ($year >= 2025) $out["$year-12-24"] = 'Wigilia';
    ksort($out);
    return $out;
}

/** Czy dzień (Y-m-d) jest dniem wolnym wg konfiguracji (własne daty + ewentualnie święta PL). */
function oh_is_holiday(string $ymd, ?array $cfg = null): bool
{
    $cfg ??= oh_config();
    if (in_array($ymd, $cfg['holidays'], true)) return true;
    return !empty($cfg['pl_holidays']) && isset(oh_pl_holidays((int)substr($ymd, 0, 4))[$ymd]);
}

/** Chwila w strefie organizacji. $at: timestamp, tekst daty albo DateTimeInterface; null = teraz. */
function oh_at($at, array $cfg): DateTimeImmutable
{
    $tz = new DateTimeZone($cfg['tz']);
    if ($at instanceof DateTimeInterface) return DateTimeImmutable::createFromInterface($at)->setTimezone($tz);
    if ($at === null) return new DateTimeImmutable('now', $tz);
    if (is_int($at)) return (new DateTimeImmutable('@' . $at))->setTimezone($tz);
    // tekst z bazy (czas lokalny serwera) — interpretujemy w strefie serwera i przeliczamy na strefę organizacji
    return (new DateTimeImmutable((string)$at, new DateTimeZone(date_default_timezone_get())))->setTimezone($tz);
}

/** Czy w danej chwili trwa czas pracy. */
function oh_is_open($at = null, ?array $cfg = null): bool
{
    $cfg ??= oh_config();
    $t = oh_at($at, $cfg);
    if (oh_is_holiday($t->format('Y-m-d'), $cfg)) return false;
    $iv = $cfg['days'][(int)$t->format('N')] ?? null;
    if (!$iv) return false;
    $hm = $t->format('H:i');
    return $hm >= $iv[0] && $hm < $iv[1];
}

/** Najbliższy początek czasu pracy po danej chwili jako tekst („jutro o 08:00”, „w poniedziałek 3.11 o 08:00”) albo '' (brak dni pracy). */
function oh_next_open($at = null, ?array $cfg = null): string
{
    $cfg ??= oh_config();
    $t = oh_at($at, $cfg);
    for ($i = 0; $i < 400; $i++) {
        $day = $t->setTime(0, 0)->modify("+$i day");
        $ymd = $day->format('Y-m-d');
        $iv = $cfg['days'][(int)$day->format('N')] ?? null;
        if (!$iv || oh_is_holiday($ymd, $cfg)) continue;
        $start = $day->setTime((int)substr($iv[0], 0, 2), (int)substr($iv[0], 3, 2));
        if ($start <= $t) {
            if ($t->format('H:i') < $iv[1] && $i === 0) return 'teraz';   // jesteśmy w godzinach pracy
            continue;
        }
        $names = [1 => 'poniedziałek', 2 => 'wtorek', 3 => 'środę', 4 => 'czwartek', 5 => 'piątek', 6 => 'sobotę', 7 => 'niedzielę'];
        $today = $t->format('Y-m-d');
        $when = $ymd === $today ? 'dzisiaj' : ($ymd === $t->modify('+1 day')->format('Y-m-d') ? 'jutro' : 'w ' . $names[(int)$day->format('N')] . ' ' . $day->format('j.m'));
        return $when . ' o ' . $iv[0];
    }
    return '';
}

/** Bramka automatycznych potwierdzeń: wyłączona = zawsze wolno; włączona = tylko poza godzinami pracy. */
function oh_acks_allowed($at = null): bool
{
    if (!function_exists('org_setting') || org_setting('office_hours_gate_acks') !== '1') return true;
    return !oh_is_open($at);
}
