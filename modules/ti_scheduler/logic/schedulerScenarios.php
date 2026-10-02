<?php
/**
 * modules/ti_scheduler/logic/schedulerScenarios.php — scenariusze „co jeśli" i auto-dopasowanie.
 *
 * Scenariusz to nakładka zmian (k30_sc_scenario_changes) na oficjalny plan: lekcje w
 * k30_ti_sessions nie są ruszane do publikacji, więc wypłaty, billing i plany kursantów
 * widzą tylko wersję oficjalną. Publikacja stosuje zmiany przez te same reguły co przesunięcie.
 */
require_once __DIR__ . '/schedulerFeed.php';

function sc_scenarios_list(): array {
    ti_sched_migrate();
    return db_all("SELECT sc.*, (SELECT COUNT(*) FROM k30_sc_scenario_changes c WHERE c.scenario_id=sc.id) AS n_changes
                     FROM k30_sc_scenarios sc WHERE sc.status='open' ORDER BY sc.id DESC");
}

function sc_scenario_create(string $title, string $note, int $by): int {
    ti_sched_migrate();
    return (int)db_insert('k30_sc_scenarios', ['title' => substr(trim($title), 0, 80) ?: 'Scenariusz', 'note' => substr(trim($note), 0, 300), 'created_by' => $by]);
}

function sc_scenario_open(int $id): ?array {
    return db_one("SELECT * FROM k30_sc_scenarios WHERE id=? AND status='open'", [$id]) ?: null;
}

function sc_scenario_discard(int $id): void {
    db_exec("UPDATE k30_sc_scenarios SET status='discarded' WHERE id=? AND status='open'", [$id]);
}

/** Zapis zmiany w scenariuszu (po walidacji względem planu oficjalnego). Zmiana równa stanowi bieżącemu kasuje wpis. */
function sc_scenario_set_change(int $scn, int $session_id, string $date, string $tf, string $tt, int $instr, int $room): void {
    $s = db_one("SELECT s.*, c.instructor_id AS ci FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id WHERE s.id=?", [$session_id]);
    if (!$s) return;
    $cur_i = (int)($s['instructor_id'] ?: $s['ci']);
    $same = $date === $s['lesson_date'] && $tf === substr((string)$s['time_from'], 0, 5) && $tt === substr((string)$s['time_to'], 0, 5)
        && $instr === $cur_i && $room === (int)$s['room_id'];
    if ($same) { db_exec("DELETE FROM k30_sc_scenario_changes WHERE scenario_id=? AND session_id=?", [$scn, $session_id]); return; }
    db_exec("INSERT INTO k30_sc_scenario_changes (scenario_id,session_id,new_date,new_from,new_to,new_instructor_id,new_room_id,updated_at)
             VALUES (?,?,?,?,?,?,?,datetime('now'))
             ON CONFLICT(scenario_id,session_id) DO UPDATE SET new_date=excluded.new_date,new_from=excluded.new_from,new_to=excluded.new_to,
               new_instructor_id=excluded.new_instructor_id,new_room_id=excluded.new_room_id,updated_at=datetime('now')",
        [$scn, $session_id, $date, $tf, $tt, $instr, $room]);
}

/** Różnice scenariusza względem planu oficjalnego. */
function sc_scenario_diff(int $scn): array {
    $out = [];
    foreach (db_all("SELECT ch.*, s.lesson_date, s.time_from, s.time_to, s.instructor_id AS s_i, s.room_id AS s_r, c.name AS course_name, c.instructor_id AS ci
                       FROM k30_sc_scenario_changes ch JOIN k30_ti_sessions s ON s.id=ch.session_id JOIN k30_ti_courses c ON c.id=s.course_id
                      WHERE ch.scenario_id=? ORDER BY ch.new_date, ch.new_from", [$scn]) as $r) {
        $oi = (int)($r['s_i'] ?: $r['ci']);
        $name = fn($id) => $id ? (string)(db_one("SELECT COALESCE(NULLIF(TRIM(first_name||' '||last_name),''), name) AS n FROM users WHERE id=?", [$id])['n'] ?? '') : '—';
        $rname = fn($id) => $id ? (string)(db_one("SELECT name FROM k30_pl_rooms WHERE id=?", [$id])['name'] ?? '') : '—';
        $out[] = ['session_id' => (int)$r['session_id'], 'course' => $r['course_name'],
            'from' => $r['lesson_date'] . ' ' . substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5),
            'to' => $r['new_date'] . ' ' . $r['new_from'] . '–' . $r['new_to'],
            'instructor' => $oi !== (int)$r['new_instructor_id'] ? $name($oi) . ' → ' . $name((int)$r['new_instructor_id']) : '',
            'room' => (int)$r['s_r'] !== (int)$r['new_room_id'] ? $rname((int)$r['s_r']) . ' → ' . $rname((int)$r['new_room_id']) : ''];
    }
    return $out;
}

/**
 * Publikacja: najpierw walidacja WSZYSTKICH zmian względem planu oficjalnego (twarde blokady
 * przerywają bez zmian), potem zastosowanie. @return array{ok:bool,msg:string,issues?:list<string>}
 */
function sc_scenario_publish(int $scn, int $by, bool $force = false, bool $notify = false): array {
    if (!sc_scenario_open($scn)) return ['ok' => false, 'msg' => 'Scenariusz nie jest otwarty.'];
    $chs = db_all("SELECT ch.*, s.course_id, s.lesson_date, s.time_from, s.time_to, s.instructor_id AS s_i, s.room_id AS s_r, s.status, s.lesson_method, c.instructor_id AS ci
                     FROM k30_sc_scenario_changes ch JOIN k30_ti_sessions s ON s.id=ch.session_id JOIN k30_ti_courses c ON c.id=s.course_id
                    WHERE ch.scenario_id=?", [$scn]);
    if (!$chs) return ['ok' => false, 'msg' => 'Scenariusz nie zawiera zmian.'];
    $issues = []; $blocked = false; $warn = false;
    foreach ($chs as $ch) {
        if (!sc_session_movable((string)$ch['status'])) { $issues[] = 'Lekcja #' . $ch['session_id'] . ' jest już odbyta lub odwołana.'; $blocked = true; continue; }
        $own = ((int)$ch['new_instructor_id'] && (int)$ch['new_instructor_id'] !== (int)$ch['ci']) ? (int)$ch['new_instructor_id'] : 0;
        $r = sc_validate(['course_id' => (int)$ch['course_id'], 'lesson_date' => $ch['new_date'], 'time_from' => $ch['new_from'], 'time_to' => $ch['new_to'],
            'instructor_id' => $own, 'room_id' => (int)$ch['new_room_id'], 'lesson_method' => (string)$ch['lesson_method'], 'skip_id' => (int)$ch['session_id']]);
        foreach ($r['hard'] as $h) { $issues[] = '#' . $ch['session_id'] . ': ' . $h['msg']; if (empty($h['overridable'])) $blocked = true; else $warn = true; }
        foreach ($r['soft'] as $h) { $issues[] = '#' . $ch['session_id'] . ': ' . $h['msg']; $warn = true; }
    }
    if ($blocked) return ['ok' => false, 'msg' => 'Publikacja zablokowana — zmiana koliduje z planem oficjalnym.', 'issues' => $issues];
    if ($warn && !$force) return ['ok' => false, 'needs_ack' => true, 'msg' => 'Publikacja ma ostrzeżenia.', 'issues' => $issues];
    // Zmiany w scenariuszu mogą się nawzajem zastępować (zamiana miejsc) — kolizje liczymy już po zastosowaniu całości,
    // więc najpierw zdejmujemy lekcje z oficjalnych slotów, potem układamy. SQLite: jedna transakcja.
    $pdo = db(); $pdo->beginTransaction();
    try {
        foreach ($chs as $ch) {
            $own = ((int)$ch['new_instructor_id'] && (int)$ch['new_instructor_id'] !== (int)$ch['ci']) ? (int)$ch['new_instructor_id'] : 0;
            $old = null;
            if ($ch['new_date'] !== $ch['lesson_date'] || $ch['new_from'] !== substr((string)$ch['time_from'], 0, 5) || $ch['new_to'] !== substr((string)$ch['time_to'], 0, 5)) {
                $old = k30_ti_do_reschedule((int)$ch['session_id'], $ch['new_date'], $ch['new_from'], $ch['new_to']);
                db_exec("DELETE FROM k30_sc_session_state WHERE session_id=?", [(int)$ch['session_id']]);
            }
            db_exec("UPDATE k30_ti_sessions SET instructor_id=?, room_id=?, updated_at=datetime('now') WHERE id=?", [$own ?: null, (int)$ch['new_room_id'] ?: null, (int)$ch['session_id']]);
            pl_audit('session', (int)$ch['session_id'], 'sc_scenario_publish', ['date' => $ch['lesson_date'], 'from' => $ch['time_from'], 'instructor_id' => $ch['s_i'], 'room_id' => $ch['s_r']],
                ['scenario' => $scn, 'date' => $ch['new_date'], 'from' => $ch['new_from'], 'instructor_id' => $own ?: null, 'room_id' => (int)$ch['new_room_id'] ?: null], $by);
            if ($old && $notify) { try { k30_ti_reschedule_notify_parties((int)$ch['session_id'], $old, false); } catch (\Throwable $e) {} }
        }
        db_exec("UPDATE k30_sc_scenarios SET status='published', published_by=?, published_at=datetime('now') WHERE id=?", [$by, $scn]);
        $pdo->commit();
    } catch (\Throwable $e) { $pdo->rollBack(); return ['ok' => false, 'msg' => 'Błąd publikacji: ' . $e->getMessage()]; }
    return ['ok' => true, 'msg' => 'Opublikowano scenariusz (' . count($chs) . ' zmian).'];
}
