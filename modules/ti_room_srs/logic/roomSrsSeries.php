<?php
/**
 * modules/ti_room_srs/logic/roomSrsSeries.php — CYKLICZNE rezerwacje przestrzeni (sal bez grupy).
 *
 * Seria = reguła w ti_room_srs_series (dni tygodnia, co ile tygodni, koniec: data albo liczba terminów) +
 * jedna zwykła rezerwacja przestrzeni (ti_room_srs_forms, course_id = 0) na każdy termin, z kolumną series_id.
 * Dzięki temu terminy serii działają wszędzie tam, gdzie zwykłe rezerwacje (lista, SRS, wydruki), a seria dodaje
 * zarządzanie: odwołanie jednego terminu (cancelled_at) albo całej serii. Edycja jednego terminu = zwykła edycja
 * rezerwacji (series_id zostaje).
 *
 * Generowanie pomija terminy, w których sala jest zajęta (lekcje TI, inna rezerwacja przestrzeni) albo wypada dzień
 * wolny / przerwa (k30_ti_holidays — dni_wolne.php); pominięte wracają w komunikacie z powodem.
 * Akceptacja: jak przy pojedynczej rezerwacji, ale jedna dla całej serii i jeden e-mail do kierowników.
 *
 * Uwaga: zatwierdzenie z zaznaczonym „Złóż rezerwację w SRS” zakłada osobną rezerwację SRS na każdy termin
 * (i wysyła powiadomienia SRS) — tak samo jak przy ręcznym dodawaniu terminów.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/ti_periods.php';

const TI_ROOM_SRS_SERIES_MAX = 60;       // maks. terminów w jednej serii
const TI_ROOM_SRS_SERIES_HORIZON = 800;  // maks. dni od pierwszego terminu

function tiRoomSrsSeriesMigrate(): void
{
    if (szo_schema_current('ti_room_srs_series', __FILE__)) return;   // raz na wersję pliku (modules/selfrepairDB)
    db()->exec("CREATE TABLE IF NOT EXISTS ti_room_srs_series (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id        INTEGER NOT NULL,
        weekdays       TEXT NOT NULL DEFAULT '',
        interval_weeks INTEGER NOT NULL DEFAULT 1,
        start_date     TEXT NOT NULL,
        end_date       TEXT,
        max_count      INTEGER,
        time_from      TEXT NOT NULL,
        time_to        TEXT NOT NULL,
        created_by     INTEGER,
        created_at     TEXT,
        cancelled_at   TEXT
    )");
    $fcols = array_column(db_all("PRAGMA table_info(ti_room_srs_forms)"), 'name');
    if (!in_array('series_id', $fcols, true))    db()->exec("ALTER TABLE ti_room_srs_forms ADD COLUMN series_id INTEGER");
    if (!in_array('cancelled_at', $fcols, true)) db()->exec("ALTER TABLE ti_room_srs_forms ADD COLUMN cancelled_at TEXT");
    // Ręczne „Potwierdzone” (sala ustalona z operatorem poza SRS) — jak status lekcji w Wykazie sal do rezerwacji
    if (!in_array('manual_status', $fcols, true)) db()->exec("ALTER TABLE ti_room_srs_forms ADD COLUMN manual_status TEXT NOT NULL DEFAULT ''");
    db()->exec("CREATE INDEX IF NOT EXISTS ix_ti_room_srs_forms_series ON ti_room_srs_forms(series_id)");
}

/** Reguła z POST-u (rep_days[], rep_interval, rep_end = 'date'|'count', rep_until, rep_count) → znormalizowana albo wyjątek. */
function tiRoomSrsSeriesRuleFromPost(array $p, string $start, string $tf, string $tt): array
{
    $days = array_values(array_unique(array_filter(array_map('intval', (array)($p['rep_days'] ?? [])), fn($d) => $d >= 1 && $d <= 7)));
    sort($days);
    if (!$days) throw new RuntimeException('Zaznacz co najmniej jeden dzień tygodnia powtarzania.');
    $interval = max(1, min(8, (int)($p['rep_interval'] ?? 1)));
    $until = (string)($p['rep_until'] ?? '');
    $count = (int)($p['rep_count'] ?? 0);
    if (($p['rep_end'] ?? 'date') === 'count') {
        if ($count < 2 || $count > TI_ROOM_SRS_SERIES_MAX) throw new RuntimeException('Liczba powtórzeń: od 2 do ' . TI_ROOM_SRS_SERIES_MAX . '.');
        $until = '';
    } else {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < $start) throw new RuntimeException('Podaj datę końca serii (nie wcześniejszą niż pierwszy dzień).');
        $count = 0;
    }
    return ['weekdays' => $days, 'interval_weeks' => $interval, 'start_date' => $start, 'end_date' => $until, 'max_count' => $count, 'time_from' => $tf, 'time_to' => $tt];
}

/**
 * Daty serii: tygodnie liczone od tygodnia (pon–ndz) pierwszego dnia, brane co $interval-ty; w tygodniu wybrane dni
 * nie wcześniejsze niż początek. Koniec: data końca (włącznie) albo liczba terminów. @return string[] Y-m-d
 */
function tiRoomSrsSeriesDates(string $start, array $weekdays, int $interval, string $until = '', int $count = 0): array
{
    $out = [];
    $limit = $count > 0 ? min($count, TI_ROOM_SRS_SERIES_MAX) : TI_ROOM_SRS_SERIES_MAX;
    $horizon = date('Y-m-d', strtotime($start . ' +' . TI_ROOM_SRS_SERIES_HORIZON . ' days'));
    $end = $until !== '' ? min($until, $horizon) : $horizon;
    sort($weekdays);
    $monday = date('Y-m-d', strtotime('monday this week', strtotime($start)));
    for ($week = 0; $week < 200; $week += max(1, $interval)) {
        $wm = date('Y-m-d', strtotime($monday . ' +' . ($week) . ' weeks'));
        if ($wm > $end) break;
        foreach ($weekdays as $dow) {
            $d = date('Y-m-d', strtotime($wm . ' +' . ($dow - 1) . ' days'));
            if ($d < $start) continue;
            if ($d > $end) break 2;
            $out[] = $d;
            if (count($out) >= $limit) break 2;
        }
    }
    return $out;
}

/** Wszystkie powody, dla których sala nie nadaje się na ten termin (pusta tablica = wolna). $skipFormId — pomija własną rezerwację. */
function tiRoomSrsConflicts(int $roomId, string $date, string $tf, string $tt, int $skipFormId = 0): array
{
    $out = [];
    if (ti_date_is_off($date)) {
        $h = db_one("SELECT name FROM k30_ti_holidays WHERE date_from <= ? AND date_to >= ? LIMIT 1", [$date, $date]);
        $out[] = 'dzień wolny' . ($h && trim((string)$h['name']) !== '' ? ' (' . $h['name'] . ')' : '');
    }
    foreach (pl_room_availability($roomId, $date, $tf, $tt) as $c) {
        $cn = db_one("SELECT name FROM k30_ti_courses WHERE id = ?", [(int)$c['course_id']]);
        $out[] = 'zajęcia TI „' . ($cn['name'] ?? '?') . '” ' . substr((string)$c['time_from'], 0, 5) . '–' . substr((string)$c['time_to'], 0, 5);
    }
    foreach (db_all(
        "SELECT id, form_data FROM ti_room_srs_forms
         WHERE course_id = 0 AND room_id = ? AND date_from = ? AND cancelled_at IS NULL AND id != ?",
        [$roomId, $date, $skipFormId]
    ) as $f) {
        $fd = json_decode((string)$f['form_data'], true) ?: [];
        $a = (string)($fd['timeFrom'] ?? ''); $b = (string)($fd['timeTo'] ?? '');
        if ($a !== '' && $b !== '' && $a < $tt && $b > $tf) $out[] = 'inna rezerwacja przestrzeni ' . $a . '–' . $b;
    }
    return $out;
}

/** Pierwszy powód niedostępności sali (pusty = wolna) — do generowania serii. */
function tiRoomSrsSeriesConflict(int $roomId, string $date, string $tf, string $tt, int $skipFormId = 0): string
{
    return tiRoomSrsConflicts($roomId, $date, $tf, $tt, $skipFormId)[0] ?? '';
}

/** Linie terminu do wniosku operatora: „Poniedziałek, 03.11.2026, 10:00–12:00”. */
function tiRoomSrsSeriesTermLines(array $dates, string $tf, string $tt): string
{
    return implode("\n", array_map(fn($d) => TI_DAYS_PL_FULL[(int)date('N', strtotime($d))] . ', ' . date('d.m.Y', strtotime($d)) . ', ' . $tf . '–' . $tt, $dates));
}

/**
 * Tworzy serię: regułę + rezerwacje na wolne terminy. $data — dane wniosku (jak przy pojedynczej rezerwacji).
 * @return array{series_id:int, form_ids:int[], skipped:array<int,array{0:string,1:string}>, dates:string[]}
 */
function tiRoomSrsSaveSeries(int $roomId, array $rule, array $data, int $userId, bool $withSrs, string $source = 'dyd', bool $approved = true): array
{
    tiRoomSrsMigrate();
    if (!tiRoomSrsRoom($roomId)) throw new RuntimeException('Wybierz salę.');
    $tf = $rule['time_from']; $tt = $rule['time_to'];
    if (!preg_match('/^\d{2}:\d{2}$/', $tf) || !preg_match('/^\d{2}:\d{2}$/', $tt) || $tf >= $tt) throw new RuntimeException('Podaj godziny (od wcześniejszej do późniejszej).');
    $dates = tiRoomSrsSeriesDates($rule['start_date'], $rule['weekdays'], $rule['interval_weeks'], $rule['end_date'], $rule['max_count']);
    if (!$dates) throw new RuntimeException('Reguła nie daje żadnego terminu.');

    $ok = []; $skipped = [];
    foreach ($dates as $d) {
        $why = tiRoomSrsSeriesConflict($roomId, $d, $tf, $tt);
        if ($why === '') $ok[] = $d; else $skipped[] = [$d, $why];
    }
    if (!$ok) throw new RuntimeException('Wszystkie terminy serii są niedostępne: ' . implode('; ', array_map(fn($s) => date('d.m.Y', strtotime($s[0])) . ' — ' . $s[1], array_slice($skipped, 0, 5))) . '.');

    $now = date('Y-m-d H:i:s');
    $seriesId = db_insert('ti_room_srs_series', [
        'room_id' => $roomId, 'weekdays' => implode(',', $rule['weekdays']), 'interval_weeks' => $rule['interval_weeks'],
        'start_date' => $rule['start_date'], 'end_date' => $rule['end_date'] !== '' ? $rule['end_date'] : null,
        'max_count' => $rule['max_count'] ?: null, 'time_from' => $tf, 'time_to' => $tt, 'created_by' => $userId ?: null, 'created_at' => $now,
    ]);
    $ids = [];
    foreach ($ok as $d) {
        $inst = $data;
        $inst['terms'] = tiRoomSrsSeriesTermLines([$d], $tf, $tt);
        $ids[] = db_insert('ti_room_srs_forms', [
            'course_id' => 0, 'room_id' => $roomId, 'date_from' => $d, 'date_to' => $d, 'session_ids' => '[]',
            'form_data' => tiRoomSrsStoredJson($inst + ['timeFrom' => $tf, 'timeTo' => $tt, '_srs' => $withSrs ? 1 : 0]),
            'created_by' => $userId ?: null, 'source' => $source, 'series_id' => $seriesId, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    if ($approved) {
        tiRoomSrsSeriesBulk(true);
        foreach ($ids as $fid) tiRoomSrsApprove($fid, $userId, false);
        tiRoomSrsSeriesBulk(false);
        tiRoomSrsSubmitSeriesToSrs($seriesId, $userId);   // jedna rezerwacja SRS na całą serię
    } else {
        db_exec("UPDATE ti_room_srs_forms SET approval_status='do_akceptacji', notified_at=datetime('now') WHERE series_id=?", [$seriesId]);
        tiRoomSrsNotifyPending($ids[0]);   // jeden e-mail na całą serię
    }
    return ['series_id' => $seriesId, 'form_ids' => $ids, 'skipped' => $skipped, 'dates' => $ok];
}

function tiRoomSrsSeriesGet(int $id): ?array
{
    tiRoomSrsMigrate();
    return db_one(
        "SELECT s.*, r.name AS room_name, r.short_label AS room_short_label, u.name AS user_name
         FROM ti_room_srs_series s JOIN k30_pl_rooms r ON r.id = s.room_id LEFT JOIN users u ON u.id = s.created_by WHERE s.id = ?",
        [$id]
    );
}

/** Terminy serii (także odwołane) z danymi do wyświetlenia. */
function tiRoomSrsSeriesForms(int $seriesId): array
{
    tiRoomSrsMigrate();
    return db_all(
        "SELECT f.*, rr.status AS srs_status FROM ti_room_srs_forms f
         LEFT JOIN resource_reservations rr ON rr.id = f.srs_reservation_id
         WHERE f.series_id = ? ORDER BY f.date_from, f.id",
        [$seriesId]
    );
}

/** Opis reguły po polsku: „co tydzień: pon, śr · 10:00–12:00 · do 31.01.2027”. */
function tiRoomSrsSeriesRuleLabel(array $s): string
{
    $short = [1 => 'pon', 2 => 'wt', 3 => 'śr', 4 => 'czw', 5 => 'pt', 6 => 'sob', 7 => 'niedz'];
    $days = implode(', ', array_map(fn($d) => $short[(int)$d] ?? '', array_filter(explode(',', (string)$s['weekdays']))));
    $iv = (int)$s['interval_weeks'];
    $every = $iv <= 1 ? 'co tydzień' : 'co ' . $iv . ' tyg.';
    $end = !empty($s['end_date']) ? 'do ' . date('d.m.Y', strtotime((string)$s['end_date'])) : ((int)$s['max_count'] ? (int)$s['max_count'] . ' powtórzeń' : '');
    return $every . ': ' . $days . ' · ' . $s['time_from'] . '–' . $s['time_to'] . ($end !== '' ? ' · ' . $end : '');
}

/** Tryb zbiorczy: zatwierdzenie terminu serii nie składa rezerwacji SRS (robi to jednorazowo wywołujący). */
function tiRoomSrsSeriesBulk(?bool $set = null): bool
{
    static $bulk = false;
    if ($set !== null) $bulk = $set;
    return $bulk;
}

/**
 * Jedna rezerwacja SRS na całą serię (jak przy grupie): zakres od pierwszego do ostatniego terminu, godziny skrajne,
 * w opisie lista terminów. Nowa (jedno powiadomienie SRS) albo aktualizacja istniejącej; wszystkie terminy serii
 * wskazują tę samą rezerwację, więc jej status widać w każdym z nich. Gdy nie zostaje żaden termin — anuluje ją.
 */
function tiRoomSrsSubmitSeriesToSrs(int $seriesId, int $userId): ?int
{
    tiRoomSrsMigrate();
    $forms = tiRoomSrsSeriesForms($seriesId);
    $live = array_values(array_filter($forms, fn($f) => empty($f['cancelled_at']) && tiRoomSrsIsApproved($f)));
    $rid = 0;
    foreach ($forms as $f) if ((int)$f['srs_reservation_id']) { $rid = (int)$f['srs_reservation_id']; break; }
    if (!$live) {
        if ($rid) {
            $st = (string)(db_one("SELECT status FROM resource_reservations WHERE id=?", [$rid])['status'] ?? '');
            if ($st !== '' && !in_array($st, ['odmowa', 'anulowana'], true)) {
                try { res_change_status($rid, 'anulowana', 'Odwołano wszystkie terminy serii rezerwacji sali'); } catch (Throwable $e) { error_log('ti_room_srs series cancel: ' . $e->getMessage()); }
            }
        }
        return null;
    }
    $first = $live[0];
    if (empty((json_decode((string)$first['form_data'], true) ?: [])['_srs'])) return null;
    $tfs = []; $tts = []; $lines = [];
    foreach ($live as $f) {
        $fd = json_decode((string)$f['form_data'], true) ?: [];
        $a = (string)($fd['timeFrom'] ?? ''); $b = (string)($fd['timeTo'] ?? '');
        $tfs[] = $a; $tts[] = $b;
        $lines[] = TI_DAYS_PL_FULL[(int)date('N', strtotime((string)$f['date_from']))] . ', ' . date('d.m.Y', strtotime((string)$f['date_from'])) . ', ' . $a . '–' . $b;
    }
    $fd = json_decode((string)$first['form_data'], true) ?: [];
    $source = (string)($first['source'] ?? 'dyd');
    $purpose = 'Rezerwacja cykliczna przestrzeni (' . TI_ROOM_SRS_SOURCES[$source === 'szo' ? 'szo' : 'dyd'] . '): '
             . (($fd['topic'] ?? '') !== '' ? $fd['topic'] : 'spotkanie') . ' (' . count($live) . ' ' . tiRoomSrsTermWord(count($live)) . ").\n" . implode("\n", $lines);
    // reprezentant: pierwszy termin, który ma rezerwację (albo pierwszy)
    $rep = $first;
    foreach ($live as $f) if ((int)$f['srs_reservation_id']) { $rep = $f; break; }
    if ($rid && !(int)$rep['srs_reservation_id']) db_exec("UPDATE ti_room_srs_forms SET srs_reservation_id=? WHERE id=?", [$rid, (int)$rep['id']]);
    $resId = tiRoomSrsUpsertReservation((int)$rep['id'], (string)$live[0]['date_from'], (string)$live[count($live) - 1]['date_from'],
        min($tfs), max($tts), $purpose, $userId, $source);
    if ($resId) db_exec("UPDATE ti_room_srs_forms SET srs_reservation_id=? WHERE series_id=?", [$resId, $seriesId]);
    return $resId;
}

/** Odwołuje jeden termin serii (lub zwykłej rezerwacji): znacznik + SRS (zwykła — anulowanie, seria — aktualizacja wspólnej rezerwacji). */
function tiRoomSrsCancelForm(int $formId, int $userId, string $note = 'Odwołano termin w panelu rezerwacji sal'): bool
{
    tiRoomSrsMigrate();
    $f = tiRoomSrsFormGet($formId);
    if (!$f || (int)$f['course_id'] !== 0 || !empty($f['cancelled_at'])) return false;
    db_exec("UPDATE ti_room_srs_forms SET cancelled_at=datetime('now'), updated_at=datetime('now') WHERE id=?", [$formId]);
    if (!empty($f['series_id'])) {
        if (!tiRoomSrsSeriesBulk()) tiRoomSrsSubmitSeriesToSrs((int)$f['series_id'], $userId);
        return true;
    }
    $rid = (int)$f['srs_reservation_id'];
    if ($rid) {
        $st = (string)(db_one("SELECT status FROM resource_reservations WHERE id=?", [$rid])['status'] ?? '');
        if ($st !== '' && !in_array($st, ['odmowa', 'anulowana'], true)) {
            try { res_change_status($rid, 'anulowana', $note); } catch (Throwable $e) { error_log('ti_room_srs cancel: ' . $e->getMessage()); }
        }
    }
    return true;
}

/** Odwołuje całą serię (terminy od $fromDate; domyślnie wszystkie nieodwołane). Zwraca liczbę odwołanych. */
function tiRoomSrsCancelSeries(int $seriesId, int $userId, string $fromDate = ''): int
{
    tiRoomSrsMigrate();
    $n = 0;
    tiRoomSrsSeriesBulk(true);
    foreach (tiRoomSrsSeriesForms($seriesId) as $f) {
        if (!empty($f['cancelled_at']) || ($fromDate !== '' && $f['date_from'] < $fromDate)) continue;
        if (tiRoomSrsCancelForm((int)$f['id'], $userId)) $n++;
    }
    tiRoomSrsSeriesBulk(false);
    if ($n) tiRoomSrsSubmitSeriesToSrs($seriesId, $userId);
    if ($fromDate === '') db_exec("UPDATE ti_room_srs_series SET cancelled_at=datetime('now') WHERE id=?", [$seriesId]);
    return $n;
}

/** Zatwierdza wszystkie oczekujące terminy serii; jedna rezerwacja SRS i jeden e-mail do autora. */
function tiRoomSrsApproveSeries(int $seriesId, int $approverId): int
{
    tiRoomSrsMigrate();
    $n = 0; $author = 0; $first = 0;
    tiRoomSrsSeriesBulk(true);
    foreach (tiRoomSrsSeriesForms($seriesId) as $f) {
        if (!empty($f['cancelled_at']) || tiRoomSrsIsApproved($f)) continue;
        tiRoomSrsApprove((int)$f['id'], $approverId, false);
        $author = (int)$f['created_by']; $first = $first ?: (int)$f['id']; $n++;
    }
    tiRoomSrsSeriesBulk(false);
    if ($n) tiRoomSrsSubmitSeriesToSrs($seriesId, $approverId);
    if ($first && $author && $author !== $approverId) tiRoomSrsNotifyApproved($first, $approverId);
    return $n;
}

/**
 * Ręczny status „Potwierdzone” rezerwacji przestrzeni (np. sala ustalona z operatorem poza SRS).
 * $formIds — konkretne terminy; $seriesId — cała seria (nieodwołane terminy).
 */
function tiRoomSrsSetManualStatus(array $formIds, bool $confirmed, int $seriesId = 0): int
{
    tiRoomSrsMigrate();
    $v = $confirmed ? 'potwierdzone' : '';
    if ($seriesId) {
        db_exec("UPDATE ti_room_srs_forms SET manual_status=? WHERE series_id=? AND cancelled_at IS NULL", [$v, $seriesId]);
        return (int)(db_one("SELECT COUNT(*) n FROM ti_room_srs_forms WHERE series_id=? AND cancelled_at IS NULL", [$seriesId])['n'] ?? 0);
    }
    $n = 0;
    foreach (array_unique(array_map('intval', $formIds)) as $id) {
        if ($id <= 0) continue;
        db_exec("UPDATE ti_room_srs_forms SET manual_status=? WHERE id=? AND course_id=0", [$v, $id]);
        $n++;
    }
    return $n;
}

/**
 * Rezerwacje przestrzeni (także z serii) w zakresie dat jako wiersze zgodne z wykazem sal
 * (_ti_room_reservation_rows) — dzięki temu trafiają do wykazu HTML/PDF/DOCX/XLSX i raportu przestrzeni.
 * Odwołane pomijane; status: rezerwacja SRS ze zgodą/rezerwacją = potwierdzone, reszta = do rezerwacji.
 * @param int $roomId 0 = wszystkie sale
 */
function tiRoomSrsAdhocReportRows(string $from, string $to, int $roomId = 0): array
{
    tiRoomSrsMigrate();
    $rows = db_all(
        "SELECT f.id AS form_id, f.series_id, f.room_id, f.date_from, f.form_data, f.created_by, f.approval_status, f.manual_status, rr.status AS srs_status,
                r.name AS room_name, r.location AS room_location, r.short_label AS room_short_label, r.operator_label AS room_operator_label,
                u.name AS user_name
         FROM ti_room_srs_forms f
         JOIN k30_pl_rooms r ON r.id = f.room_id
         LEFT JOIN resource_reservations rr ON rr.id = f.srs_reservation_id
         LEFT JOIN users u ON u.id = f.created_by
         WHERE f.course_id = 0 AND f.cancelled_at IS NULL AND f.date_from BETWEEN ? AND ?" . ($roomId ? ' AND f.room_id = ?' : '') . "
         ORDER BY f.date_from, f.id",
        $roomId ? [$from, $to, $roomId] : [$from, $to]
    );
    $out = [];
    foreach ($rows as $r) {
        $fd = json_decode((string)$r['form_data'], true) ?: [];
        $topic = trim((string)($fd['topic'] ?? ''));
        $series = (int)$r['series_id'];
        $out[] = [
            'id' => 0, 'adhoc' => 1, 'form_id' => (int)$r['form_id'], 'series_id' => $series,
            'lesson_date' => (string)$r['date_from'], 'time_from' => (string)($fd['timeFrom'] ?? ''), 'time_to' => (string)($fd['timeTo'] ?? ''),
            'room_id' => (int)$r['room_id'],
            'room_reservation_status' => ($r['manual_status'] ?? '') === 'potwierdzone' || in_array((string)$r['srs_status'], TI_ROOM_SRS_CONFIRMED, true) ? 'potwierdzone' : 'do_rezerwacji',
            'course_id' => 0,
            'course_name' => 'Rezerwacja przestrzeni' . ($topic !== '' ? ': ' . mb_strimwidth($topic, 0, 70, '…') : '') . ($series ? ' (cykliczna)' : ''),
            'instructor_label' => (string)($r['user_name'] ?: '—'),
            'room_name' => $r['room_name'], 'room_location' => $r['room_location'], 'room_short_label' => $r['room_short_label'],
            'room_operator_label' => $r['room_operator_label'],
            'room_label' => ti_room_label(['name' => $r['room_name'], 'location' => $r['room_location'], 'short_label' => $r['room_short_label'] ?? '']),
        ];
    }
    return $out;
}
