<?php
/**
 * includes/ti_rebook.php — „Do ustalenia nowego terminu” po odwołaniu zajęć.
 *
 * Każde odwołanie z ti_cancel_sessions_notify() zostawia ślad w k30_ti_rebook (powiązany z nieobecnością,
 * jeśli wynika z niej). Odwołanie z prośbą o kontakt ma status 'open' i trafia na ekran nowyTermin.php
 * (kierownik): skontaktowano → zaproponuj termin (przywraca lekcję w nowym terminie i powiadamia uczestników)
 * albo rezygnacja. Odwołanie bez prośby ma status 'info' — służy tylko do przywracania z ekranu nieobecności.
 *
 * Statusy: info | open | contacted | scheduled | dropped | restored.
 */

const TI_REBOOK_STATUSES = [
    'info'      => 'Odwołane (bez prośby o kontakt)',
    'open'      => 'Do ustalenia terminu',
    'contacted' => 'Skontaktowano',
    'scheduled' => 'Nowy termin ustalony',
    'dropped'   => 'Zrezygnowano z terminu',
    'restored'  => 'Przywrócone w pierwotnym terminie',
];
const TI_REBOOK_ACTIVE = ['open', 'contacted'];

function ti_rebook_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (szo_schema_current('ti_rebook_migrate', __FILE__)) return;   // raz na wersję pliku
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_rebook (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id   INTEGER NOT NULL UNIQUE,
        course_id    INTEGER NOT NULL DEFAULT 0,
        leave_id     INTEGER NOT NULL DEFAULT 0,
        reason       TEXT    NOT NULL DEFAULT '',
        ask_contact  INTEGER NOT NULL DEFAULT 0,
        status       TEXT    NOT NULL DEFAULT 'info',
        note         TEXT    NOT NULL DEFAULT '',
        orig_date    TEXT    NOT NULL DEFAULT '',
        orig_from    TEXT    NOT NULL DEFAULT '',
        orig_to      TEXT    NOT NULL DEFAULT '',
        decided_by   TEXT    NOT NULL DEFAULT '',
        decided_at   DATETIME,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_rebook_status ON k30_ti_rebook(status)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_rebook_leave  ON k30_ti_rebook(leave_id)");
}

/** Zapisuje odwołaną lekcję ($s: wiersz z id, course_id, lesson_date, time_from, time_to). Ponowne odwołanie nadpisuje ślad. */
function ti_rebook_record(array $s, string $reason, int $leave_id, bool $ask_contact): void {
    ti_rebook_migrate();
    db()->prepare(
        "INSERT INTO k30_ti_rebook (session_id, course_id, leave_id, reason, ask_contact, status, orig_date, orig_from, orig_to)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON CONFLICT(session_id) DO UPDATE SET course_id=excluded.course_id, leave_id=excluded.leave_id, reason=excluded.reason,
            ask_contact=excluded.ask_contact, status=excluded.status, note='', decided_by='', decided_at=NULL,
            orig_date=excluded.orig_date, orig_from=excluded.orig_from, orig_to=excluded.orig_to, created_at=CURRENT_TIMESTAMP"
    )->execute([(int)$s['id'], (int)$s['course_id'], $leave_id, $reason, $ask_contact ? 1 : 0, $ask_contact ? 'open' : 'info',
        (string)$s['lesson_date'], (string)$s['time_from'], (string)$s['time_to']]);
}

/** Liczba odwołanych zajęć czekających na ustalenie nowego terminu (do liczników w menu). */
function ti_rebook_open_count(): int {
    try { ti_rebook_migrate(); return (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_rebook WHERE status IN ('open','contacted')")['n'] ?? 0); }
    catch (\Throwable $e) { return 0; }
}

/** Lista wpisów ('active' = do ustalenia, 'closed' = zamknięte, 'all'), najnowsze pierwsze. */
function ti_rebook_list(string $filter = 'active', int $limit = 200): array {
    ti_rebook_migrate();
    $w = $filter === 'active' ? "r.status IN ('open','contacted')" : ($filter === 'closed' ? "r.status IN ('scheduled','dropped','restored')" : "r.status!='info'");
    return db_all(
        "SELECT r.*, c.name AS course_name, s.status AS session_status, s.lesson_date, s.time_from, s.time_to,
                COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS instructor_name
           FROM k30_ti_rebook r
           JOIN k30_ti_sessions s ON s.id=r.session_id
           JOIN k30_ti_courses c ON c.id=r.course_id
           LEFT JOIN users u ON u.id=COALESCE(s.instructor_id, c.instructor_id)
          WHERE $w ORDER BY r.created_at DESC, r.id DESC LIMIT " . (int)$limit
    );
}

/** Zmiana statusu (contacted | dropped) z notatką i podpisem decydenta. */
function ti_rebook_set(int $id, string $status, string $note, string $by): bool {
    ti_rebook_migrate();
    if (!in_array($status, ['open', 'contacted', 'dropped'], true)) return false;
    $st = db()->prepare("UPDATE k30_ti_rebook SET status=?, note=?, decided_by=?, decided_at=datetime('now') WHERE id=? AND status IN ('open','contacted')");
    $st->execute([$status, trim($note), $by, $id]);
    return $st->rowCount() > 0;
}

/**
 * Ustala nowy termin odwołanej lekcji: walidacja (dostępność, okres, Zoom, a gdy dostępny planista — też kolizje
 * trenera, kwalifikacje i limity), przywrócenie lekcji, zmiana terminu i powiadomienie uczestników (e-mail + SMS).
 * @return array{ok:bool, msg:string, needs_ack?:bool, issues?:string[]}
 */
function ti_rebook_schedule(int $id, string $date, string $tf, string $tt, string $by, bool $notify = true, bool $force = false): array {
    ti_rebook_migrate();
    require_once __DIR__ . '/ti_reschedule.php';
    $r = db_one("SELECT r.*, s.status AS sstatus, s.instructor_id AS s_instr, s.room_id, s.lesson_method, c.instructor_id AS c_instr
                   FROM k30_ti_rebook r JOIN k30_ti_sessions s ON s.id=r.session_id JOIN k30_ti_courses c ON c.id=r.course_id WHERE r.id=?", [$id]);
    if (!$r) return ['ok' => false, 'msg' => 'Nie znaleziono wpisu.'];
    if (!in_array($r['status'], TI_REBOOK_ACTIVE, true)) return ['ok' => false, 'msg' => 'Ten wpis jest już zamknięty.'];
    if ($r['sstatus'] !== 'cancelled') return ['ok' => false, 'msg' => 'Lekcja nie jest już odwołana.'];
    $tf = substr(trim($tf), 0, 5); $tt = substr(trim($tt), 0, 5);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $tf) || !preg_match('/^\d{2}:\d{2}$/', $tt) || $tt <= $tf)
        return ['ok' => false, 'msg' => 'Podaj poprawną datę oraz godziny (od wcześniejszej do późniejszej).'];
    if ($date < date('Y-m-d')) return ['ok' => false, 'msg' => 'Nowy termin nie może być w przeszłości.'];

    $sid = (int)$r['session_id'];
    $v = ti_validate_reschedule($sid, (int)$r['course_id'], $date, $tf, $tt);
    if (!$v['ok']) return ['ok' => false, 'msg' => $v['reason']];
    if (function_exists('sc_validate')) {
        $sv = sc_validate(['course_id' => (int)$r['course_id'], 'lesson_date' => $date, 'time_from' => $tf, 'time_to' => $tt,
            'instructor_id' => (int)$r['s_instr'], 'room_id' => (int)$r['room_id'], 'lesson_method' => (string)$r['lesson_method'], 'skip_id' => $sid]);
        $fixed = array_filter($sv['hard'], fn($h) => empty($h['overridable']));
        if ($fixed) return ['ok' => false, 'msg' => reset($fixed)['msg']];
        if (($sv['hard'] || $sv['soft']) && !$force)
            return ['ok' => false, 'needs_ack' => true, 'msg' => 'Nowy termin ma ostrzeżenia.', 'issues' => array_merge(array_column($sv['hard'], 'msg'), array_column($sv['soft'], 'msg'))];
    }

    // Lekcja wraca do planu (jak „Przywróć”), potem normalna zmiana terminu — zostaje ślad pierwotnego slotu
    db()->prepare("UPDATE k30_ti_sessions SET status='planned', cancel_reason='', cancelled_by_role='', cancelled_by='', cancelled_at=NULL, updated_at=datetime('now') WHERE id=?")->execute([$sid]);
    $old = k30_ti_do_reschedule($sid, $date, $tf, $tt);
    db()->prepare("UPDATE k30_ti_rebook SET status='scheduled', decided_by=?, decided_at=datetime('now') WHERE id=?")->execute([$by, $id]);
    if ($notify && $old !== null) { try { k30_ti_reschedule_notify_parties($sid, $old, true); } catch (\Throwable $e) {} }
    return ['ok' => true, 'msg' => 'Lekcja przywrócona w nowym terminie: ' . date('d.m.Y', strtotime($date)) . ", {$tf}–{$tt}." . ($notify ? ' Uczestnicy zostali powiadomieni.' : '')];
}
