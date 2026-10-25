<?php
/**
 * modules/helpdeskTime/lib/helpdeskTime.php — godziny pracy w SLA i rejestr czasu pracy nad zgłoszeniem.
 *
 * Tryb SLA (ustawienie organizacji hd_sla_mode): 'calendar' (domyślnie — jak dotąd, czas kalendarzowy) albo 'business' —
 * cele reakcji/rozwiązania liczone tylko w godzinach pracy (hd_work_start/hd_work_end „HH:MM”, hd_work_days „1,2,3,4,5”
 * = pn–pt). Zgłoszenie z piątku po godzinach nie „przekracza” celu w weekend. Święta pomijamy (brak kalendarza świąt).
 * Rejestr czasu: helpdesk_time(ticket_id, user_id, minutes, note) — suma na karcie zgłoszenia i w raportach.
 */

function hdWorkConfig(): array
{
    $mode = org_setting('hd_sla_mode') === 'business' ? 'business' : 'calendar';
    $start = preg_match('/^\d{1,2}:\d{2}$/', org_setting('hd_work_start')) ? org_setting('hd_work_start') : '08:00';
    $end = preg_match('/^\d{1,2}:\d{2}$/', org_setting('hd_work_end')) ? org_setting('hd_work_end') : '16:00';
    $days = array_values(array_filter(array_map('intval', explode(',', org_setting('hd_work_days') ?: '1,2,3,4,5')), fn($d) => $d >= 1 && $d <= 7));
    return ['mode' => $mode, 'start' => $start, 'end' => $end, 'days' => $days ?: [1, 2, 3, 4, 5]];
}

function hdWorkConfigSave(array $in): array
{
    $e = [];
    $start = trim((string)($in['hd_work_start'] ?? '')); $end = trim((string)($in['hd_work_end'] ?? ''));
    if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $end)) $e[] = 'Podaj godziny w formacie GG:MM.';
    elseif (strtotime("1970-01-01 $end") <= strtotime("1970-01-01 $start")) $e[] = 'Koniec pracy musi być po jej początku.';
    $days = array_values(array_unique(array_filter(array_map('intval', (array)($in['hd_work_days'] ?? [])), fn($d) => $d >= 1 && $d <= 7)));
    if (!$days) $e[] = 'Wybierz co najmniej jeden dzień pracy.';
    if ($e) return $e;
    org_setting_set('hd_sla_mode', ($in['hd_sla_mode'] ?? '') === 'business' ? 'business' : 'calendar');
    org_setting_set('hd_work_start', $start); org_setting_set('hd_work_end', $end); org_setting_set('hd_work_days', implode(',', $days));
    return [];
}

/** Początek i koniec okna pracy danego dnia (timestampy) albo null, gdy dzień wolny. */
function hdWorkWindow(int $dayTs, array $cfg): ?array
{
    if (!in_array((int)date('N', $dayTs), $cfg['days'], true)) return null;
    $d = date('Y-m-d', $dayTs);
    return [strtotime("$d {$cfg['start']}"), strtotime("$d {$cfg['end']}")];
}

/** Termin = chwila, w której upłynie $minutes minut PRACY licząc od $from (tryb calendar: zwykłe dodanie minut). */
function hdSlaDeadline(int $from, int $minutes): int
{
    $cfg = hdWorkConfig();
    if ($cfg['mode'] !== 'business') return $from + $minutes * 60;
    $left = $minutes * 60;
    $cursor = $from;
    for ($i = 0; $i < 400 && $left > 0; $i++) { // limit: ~rok roboczy, zabezpieczenie przed pętlą
        $w = hdWorkWindow($cursor, $cfg);
        if ($w) {
            $from0 = max($cursor, $w[0]);
            if ($from0 < $w[1]) {
                $avail = $w[1] - $from0;
                if ($left <= $avail) return $from0 + $left;
                $left -= $avail;
            }
        }
        $cursor = strtotime(date('Y-m-d', $cursor) . ' +1 day 00:00');
    }
    return $cursor;
}

// ── Rejestr czasu ────────────────────────────────────────────────────────────

function hdTimeMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    helpdesk_migrate();
    db()->exec("CREATE TABLE IF NOT EXISTS helpdesk_time (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id  INTEGER NOT NULL REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
        user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        minutes    INTEGER NOT NULL,
        note       TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_hd_time_ticket ON helpdesk_time(ticket_id)");
}

/** „1h 30m”, „45”, „1,5h”, „90m” → minuty (1–1440) albo null. */
function hdTimeParse(string $s): ?int
{
    $s = trim(mb_strtolower(str_replace(',', '.', $s)));
    if ($s === '') return null;
    if (preg_match('/^(?:(\d+(?:\.\d+)?)\s*h)?\s*(?:(\d+)\s*m(?:in)?)?$/', $s, $m) && ($m[1] ?? '') . ($m[2] ?? '') !== '') {
        $min = (int)round(((float)($m[1] ?? 0)) * 60) + (int)($m[2] ?? 0);
    } elseif (preg_match('/^\d+$/', $s)) {
        $min = (int)$s;
    } else {
        return null;
    }
    return $min >= 1 && $min <= 1440 ? $min : null;
}

function hdTimeFmt(int $min): string
{
    return $min >= 60 ? intdiv($min, 60) . ' h' . ($min % 60 ? ' ' . ($min % 60) . ' min' : '') : $min . ' min';
}

function hdTimeAdd(int $ticketId, int $userId, string $input, string $note): ?string
{
    hdTimeMigrate();
    $min = hdTimeParse($input);
    if ($min === null) return 'Podaj czas, np. 30, 1h 15m albo 1,5h (max 24 h).';
    db_insert('helpdesk_time', ['ticket_id' => $ticketId, 'user_id' => $userId ?: null, 'minutes' => $min, 'note' => mb_substr(trim($note), 0, 300)]);
    return null;
}

function hdTimeList(int $ticketId): array
{
    hdTimeMigrate();
    return db_all("SELECT t.*, u.name AS user_name FROM helpdesk_time t LEFT JOIN users u ON u.id=t.user_id WHERE t.ticket_id=? ORDER BY t.id DESC", [$ticketId]);
}

function hdTimeTotal(int $ticketId): int
{
    hdTimeMigrate();
    return (int)(db_one("SELECT COALESCE(SUM(minutes),0) AS m FROM helpdesk_time WHERE ticket_id=?", [$ticketId])['m'] ?? 0);
}
