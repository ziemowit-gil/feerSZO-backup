<?php
/**
 * modules/ti_scheduler/logic/schedulerWorkflow.php — workflow niedostępności i zastępstw.
 *
 * Trener zgłasza niedostępność (zakres dat) albo wniosek o zmianę terminu lekcji →
 * kierownik dostaje powiadomienie e-mail i pozycję na pulpicie → zatwierdza jednym
 * kliknięciem (z automatycznym doborem zastępców) lub rozstrzyga lekcje po jednej:
 * zastępstwo / przełożenie / odwołanie / pozostawienie. Decyzje wracają mailem do trenera.
 */
require_once __DIR__ . '/schedulerFeed.php';

const SC_REQ_STATUSES = ['pending' => 'Oczekuje', 'approved' => 'Zatwierdzone', 'rejected' => 'Odrzucone', 'cancelled' => 'Wycofane'];
const SC_RES_LABELS   = ['pending' => 'Do rozstrzygnięcia', 'substitute' => 'Zastępstwo', 'reschedule' => 'Przełożona', 'cancel' => 'Odwołana', 'keep' => 'Bez zmian'];

function sc_user_name(int $id): string {
    $u = db_one("SELECT COALESCE(NULLIF(TRIM(first_name||' '||last_name),''), name) AS n FROM users WHERE id=?", [$id]);
    return (string)($u['n'] ?? ('#' . $id));
}

function sc_mail(string $email, string $name, string $subject, string $body, string $ctx, int $ctx_id): void {
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    if (!function_exists('mail_queue_add')) require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
    $url = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/dydaktyk/';
    $html = '<p>' . $body . '</p><p><a href="' . htmlspecialchars($url . ($ctx === 'sc_request_mgr' ? 'zgloszenia.php' : 'mojeZgloszenia.php'), ENT_QUOTES) . '">Otwórz panel dydaktyka</a></p>'
          . '<p style="color:#888;font-size:12px">Wiadomość automatyczna — planista zajęć.</p>';
    try { mail_queue_add($email, $name, $subject, $html, '', $ctx, $ctx_id, '', false); } catch (\Throwable $e) {}
}

/** Adresaci powiadomień kierownika (role panelu kierownik / zastępca). */
function sc_manager_recipients(): array {
    return db_all("SELECT u.id, u.email, COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS name
                     FROM k30_ti_panel_roles r JOIN users u ON u.id=r.user_id
                    WHERE r.role IN ('kierownik','zastepca') AND u.is_active=1 AND COALESCE(u.email,'')!=''");
}

function sc_notify_managers(string $subject, string $body, int $request_id): void {
    foreach (sc_manager_recipients() as $m) sc_mail((string)$m['email'], (string)$m['name'], $subject, $body, 'sc_request_mgr', $request_id);
}

function sc_pending_count(): int {
    try { ti_sched_migrate(); return (int)(db_one("SELECT COUNT(*) AS n FROM k30_sc_requests WHERE status='pending'")['n'] ?? 0)
        + (int)(db_one("SELECT COUNT(DISTINCT i.request_id) AS n FROM k30_sc_request_items i JOIN k30_sc_requests r ON r.id=i.request_id
                         WHERE r.status='approved' AND i.resolution='pending'")['n'] ?? 0); }
    catch (\Throwable $e) { return 0; }
}

/** Lekcje trenera (nieodbyte, nieodwołane) w zakresie dat; z $tf/$tt tylko nakładające się na okno godzin. */
function sc_instructor_sessions(int $iid, string $from, string $to, string $tf = '', string $tt = ''): array {
    $rows = db_all(
        "SELECT s.id, s.course_id, s.lesson_date, s.time_from, s.time_to, s.status, c.name AS course_name
           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
          WHERE COALESCE(s.instructor_id,c.instructor_id)=CAST(? AS INTEGER) AND s.lesson_date BETWEEN ? AND ?
            AND s.draft_id IS NULL AND s.status IN ('planned','reserved')
          ORDER BY s.lesson_date, s.time_from", [$iid, $from, $to]);
    if ($tf === '' || $tt === '') return $rows;
    $win = ['date_from' => $from, 'date_to' => $to, 'time_from' => $tf, 'time_to' => $tt];
    return array_values(array_filter($rows, fn($r) => ti_leave_covers($win, (string)$r['lesson_date'], (string)$r['time_from'], (string)$r['time_to'])));
}

/**
 * Nieobecność wpisana bezpośrednio (urlopy.php) → zatwierdzone zgłoszenie z pozycjami „lekcja do rozstrzygnięcia”.
 * Rozstrzyga się je na zgloszenia.php (zastępstwo / przełożenie / odwołanie / bez zmian), tak samo jak
 * zgłoszenie prowadzącego. Idempotentne: ponowny zapis (np. zmiana godzin) dokłada brakujące lekcje.
 * @return array{request_id:int, items:int, open:int}
 */
function sc_open_leave_resolution(int $leave_id, int $by): array {
    ti_sched_migrate();
    $l = ti_leave_get($leave_id);
    if (!$l) return ['request_id' => 0, 'items' => 0, 'open' => 0];
    $req = db_one("SELECT id FROM k30_sc_requests WHERE leave_id=? AND type='unavailability'", [$leave_id]);
    $fields = ['instructor_id' => (int)$l['instructor_id'], 'date_from' => (string)$l['date_from'], 'date_to' => (string)$l['date_to'],
               'time_from' => (string)($l['time_from'] ?? ''), 'time_to' => (string)($l['time_to'] ?? ''),
               'leave_type' => (string)$l['type'], 'reason' => (string)$l['note']];
    if ($req) {
        $rid = (int)$req['id'];
        db()->prepare("UPDATE k30_sc_requests SET instructor_id=?, date_from=?, date_to=?, time_from=?, time_to=?, leave_type=?, reason=? WHERE id=?")
            ->execute([...array_values($fields), $rid]);
    } else {
        $rid = (int)db_insert('k30_sc_requests', $fields + ['type' => 'unavailability', 'status' => 'approved', 'leave_id' => $leave_id,
            'decided_by' => $by, 'decided_at' => date('Y-m-d H:i:s'), 'decision_note' => 'Wpisane bezpośrednio przez kierownika']);
    }
    $conf = ti_leave_conflicts($l);
    foreach ($conf as $c) db_exec("INSERT OR IGNORE INTO k30_sc_request_items (request_id, session_id) VALUES (?,?)", [$rid, (int)$c['id']]);
    $open = (int)(db_one("SELECT COUNT(*) AS n FROM k30_sc_request_items WHERE request_id=? AND resolution='pending'", [$rid])['n'] ?? 0);
    return ['request_id' => $rid, 'items' => count($conf), 'open' => $open];
}

/** @return array{ok:bool,msg:string,id:int} */
function sc_request_unavailability(int $iid, string $from, string $to, string $leave_type, string $reason, string $tf = '', string $tt = ''): array {
    ti_sched_migrate();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) return ['ok' => false, 'msg' => 'Podaj poprawny zakres dat.', 'id' => 0];
    if ($to < $from) [$from, $to] = [$to, $from];
    if ($to < date('Y-m-d')) return ['ok' => false, 'msg' => 'Zakres dat jest w przeszłości.', 'id' => 0];
    if (!array_key_exists($leave_type, TI_LEAVE_TYPES)) $leave_type = 'inne';
    // Godziny: oba pola albo żadne (puste = cały dzień)
    $tf = trim($tf); $tt = trim($tt);
    if ($tf !== '' || $tt !== '') {
        if (!preg_match('/^\d{2}:\d{2}$/', $tf) || !preg_match('/^\d{2}:\d{2}$/', $tt) || $tt <= $tf)
            return ['ok' => false, 'msg' => 'Podaj poprawne godziny nieobecności (od wcześniejszej do późniejszej) albo zostaw oba pola puste.', 'id' => 0];
    }
    $id = db_insert('k30_sc_requests', ['type' => 'unavailability', 'instructor_id' => $iid, 'date_from' => $from, 'date_to' => $to,
        'time_from' => $tf, 'time_to' => $tt, 'leave_type' => $leave_type, 'reason' => substr(trim($reason), 0, 500)]);
    $items = sc_instructor_sessions($iid, $from, $to, $tf, $tt);
    foreach ($items as $it) db_exec("INSERT OR IGNORE INTO k30_sc_request_items (request_id, session_id) VALUES (?,?)", [$id, (int)$it['id']]);
    sc_notify_managers('Zgłoszenie niedostępności: ' . sc_user_name($iid),
        'Prowadzący <strong>' . h(sc_user_name($iid)) . '</strong> zgłasza niedostępność (' . h(ti_leave_type_label($leave_type)) . ') w terminie <strong>' . h($from . ' – ' . $to . ($tf !== '' ? ', ' . $tf . '–' . $tt : ''))
        . '</strong>. Lekcji do rozstrzygnięcia: <strong>' . count($items) . '</strong>.' . ($reason !== '' ? '<br>Powód: ' . h($reason) : ''), (int)$id);
    return ['ok' => true, 'msg' => 'Zgłoszenie wysłane do kierownika. Lekcji dotkniętych: ' . count($items) . '.', 'id' => (int)$id];
}

/** @return array{ok:bool,msg:string,id:int} */
function sc_request_reschedule(int $iid, int $session_id, string $date, string $tf, string $tt, string $reason): array {
    ti_sched_migrate();
    $s = db_one("SELECT s.*, c.instructor_id AS ci, c.name AS cname FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id WHERE s.id=?", [$session_id]);
    if (!$s || (int)($s['instructor_id'] ?: $s['ci']) !== $iid) return ['ok' => false, 'msg' => 'To nie jest Twoja lekcja.', 'id' => 0];
    if (!sc_session_movable((string)$s['status']) || $s['lesson_date'] < date('Y-m-d')) return ['ok' => false, 'msg' => 'Tej lekcji nie można już przełożyć.', 'id' => 0];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $tf) || !preg_match('/^\d{2}:\d{2}$/', $tt) || $tt <= $tf)
        return ['ok' => false, 'msg' => 'Podaj poprawną datę i godziny.', 'id' => 0];
    if (db_one("SELECT 1 FROM k30_sc_requests WHERE type='reschedule' AND session_id=? AND status='pending'", [$session_id]))
        return ['ok' => false, 'msg' => 'Dla tej lekcji jest już oczekujący wniosek.', 'id' => 0];
    $id = db_insert('k30_sc_requests', ['type' => 'reschedule', 'instructor_id' => $iid, 'session_id' => $session_id,
        'date_from' => (string)$s['lesson_date'], 'date_to' => (string)$s['lesson_date'],
        'proposed_date' => $date, 'proposed_from' => $tf, 'proposed_to' => $tt, 'reason' => substr(trim($reason), 0, 500)]);
    sc_notify_managers('Wniosek o zmianę terminu: ' . sc_user_name($iid),
        'Prowadzący <strong>' . h(sc_user_name($iid)) . '</strong> prosi o przełożenie lekcji <strong>' . h((string)$s['cname']) . '</strong> z '
        . h($s['lesson_date'] . ' ' . substr((string)$s['time_from'], 0, 5)) . ' na <strong>' . h("$date $tf–$tt") . '</strong>.' . ($reason !== '' ? '<br>Powód: ' . h($reason) : ''), (int)$id);
    return ['ok' => true, 'msg' => 'Wniosek wysłany do kierownika.', 'id' => (int)$id];
}

/** Kandydaci na zastępstwo: bez twardych konfliktów, uszeregowani wg ostrzeżeń i obciążenia tygodnia. */
function sc_suggest_substitutes(int $session_id, int $limit = 5): array {
    $s = db_one("SELECT s.*, c.instructor_id AS ci FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id WHERE s.id=?", [$session_id]);
    if (!$s) return [];
    $cur = (int)($s['instructor_id'] ?: $s['ci']);
    $mon = date('Y-m-d', strtotime('monday this week', strtotime($s['lesson_date'])));
    $sun = date('Y-m-d', strtotime('sunday this week', strtotime($s['lesson_date'])));
    $out = [];
    foreach (k30_get_consultants() as $u) {
        $cid = (int)$u['id'];
        if ($cid === $cur) continue;
        $r = sc_validate(['course_id' => (int)$s['course_id'], 'lesson_date' => $s['lesson_date'], 'time_from' => $s['time_from'], 'time_to' => $s['time_to'],
                          'instructor_id' => $cid, 'room_id' => (int)$s['room_id'], 'lesson_method' => (string)$s['lesson_method'], 'skip_id' => $session_id]);
        if ($r['hard']) continue;
        $week = sc_instructor_minutes($cid, $mon, $sun);
        $out[] = ['id' => $cid, 'name' => (string)$u['display_name'], 'soft' => array_column($r['soft'], 'msg'), 'week_min' => $week,
                  'score' => count($r['soft']) * 1000 + $week];
    }
    usort($out, fn($a, $b) => $a['score'] <=> $b['score']);
    return array_slice($out, 0, $limit);
}

/** Rozstrzygnięcie jednej lekcji. @return array{ok:bool,msg:string,needs_ack?:bool,issues?:list<string>} */
function sc_apply_item(int $item_id, string $action, array $p, int $by, bool $force = false): array {
    $it = db_one("SELECT i.*, r.instructor_id AS req_instr FROM k30_sc_request_items i JOIN k30_sc_requests r ON r.id=i.request_id WHERE i.id=?", [$item_id]);
    if (!$it) return ['ok' => false, 'msg' => 'Nie znaleziono pozycji.'];
    $sid = (int)$it['session_id'];
    $s = db_one("SELECT s.*, c.instructor_id AS ci FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id WHERE s.id=?", [$sid]);
    if (!$s) return ['ok' => false, 'msg' => 'Lekcja nie istnieje.'];
    if (!sc_session_movable((string)$s['status']) && $action !== 'keep') return ['ok' => false, 'msg' => 'Lekcja jest już odbyta lub odwołana.'];
    $note = '';
    if ($action === 'substitute') {
        $sub = (int)($p['substitute_id'] ?? 0);
        if (!$sub) return ['ok' => false, 'msg' => 'Wybierz zastępcę.'];
        $r = sc_validate(['course_id' => (int)$s['course_id'], 'lesson_date' => $s['lesson_date'], 'time_from' => $s['time_from'], 'time_to' => $s['time_to'],
                          'instructor_id' => $sub, 'room_id' => (int)$s['room_id'], 'lesson_method' => (string)$s['lesson_method'], 'skip_id' => $sid]);
        $fixed = array_filter($r['hard'], fn($h) => empty($h['overridable']));
        if ($fixed) return ['ok' => false, 'msg' => reset($fixed)['msg']];
        if (($r['hard'] || $r['soft']) && !$force) return ['ok' => false, 'needs_ack' => true, 'msg' => 'Zastępstwo ma ostrzeżenia.',
            'issues' => array_merge(array_column($r['hard'], 'msg'), array_column($r['soft'], 'msg'))];
        db_exec("UPDATE k30_ti_sessions SET instructor_id=?, updated_at=datetime('now') WHERE id=?", [$sub === (int)$s['ci'] ? null : $sub, $sid]);
        $u = db_one("SELECT email FROM users WHERE id=?", [$sub]);
        $c = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [(int)$s['course_id']]);
        sc_mail((string)($u['email'] ?? ''), sc_user_name($sub), 'Zastępstwo: ' . ($c['name'] ?? ''),
            'Przypisano Ci zastępstwo: <strong>' . h((string)($c['name'] ?? '')) . '</strong>, ' . h($s['lesson_date'] . ' ' . substr((string)$s['time_from'], 0, 5) . '–' . substr((string)$s['time_to'], 0, 5)) . '.', 'sc_request_instr', (int)$it['request_id']);
        pl_audit('session', $sid, 'sc_substitute', ['instructor_id' => $s['instructor_id']], ['instructor_id' => $sub], $by);
        if (function_exists('ti_substitute_notify_students')) { try { ti_substitute_notify_students($sid, $sub); } catch (\Throwable $e) {} }
        db_exec("UPDATE k30_sc_request_items SET resolution='substitute', substitute_id=?, resolved_by=?, resolved_at=datetime('now') WHERE id=?", [$sub, $by, $item_id]);
        return ['ok' => true, 'msg' => 'Przypisano zastępcę: ' . sc_user_name($sub) . '.'];
    }
    if ($action === 'cancel') {
        $cancel_reason = trim((string)($p['reason'] ?? '')) ?: 'Niedostępność prowadzącego';
        if (function_exists('ti_absence_cancel_sessions')) {
            ti_absence_cancel_sessions([$sid], $cancel_reason, sc_user_name($by), $by);   // e-mail + SMS do uczestników
        } else {
            k30_ti_cancel_session($sid, $cancel_reason, 'doradca', sc_user_name($by));
        }
        pl_audit('session', $sid, 'sc_cancel', [], [], $by);
        db_exec("UPDATE k30_sc_request_items SET resolution='cancel', resolved_by=?, resolved_at=datetime('now') WHERE id=?", [$by, $item_id]);
        return ['ok' => true, 'msg' => 'Lekcja odwołana.'];
    }
    if ($action === 'reschedule') {
        $date = (string)($p['date'] ?? ''); $tf = substr((string)($p['time_from'] ?? $s['time_from']), 0, 5); $tt = substr((string)($p['time_to'] ?? $s['time_to']), 0, 5);
        $r = sc_validate(['course_id' => (int)$s['course_id'], 'lesson_date' => $date, 'time_from' => $tf, 'time_to' => $tt,
                          'instructor_id' => (int)$s['instructor_id'], 'room_id' => (int)$s['room_id'], 'lesson_method' => (string)$s['lesson_method'], 'skip_id' => $sid]);
        $fixed = array_filter($r['hard'], fn($h) => empty($h['overridable']));
        if ($fixed) return ['ok' => false, 'msg' => reset($fixed)['msg']];
        if (($r['hard'] || $r['soft']) && !$force) return ['ok' => false, 'needs_ack' => true, 'msg' => 'Nowy termin ma ostrzeżenia.',
            'issues' => array_merge(array_column($r['hard'], 'msg'), array_column($r['soft'], 'msg'))];
        $old = k30_ti_do_reschedule($sid, $date, $tf, $tt);
        db_exec("DELETE FROM k30_sc_session_state WHERE session_id=?", [$sid]);
        if ($old) { try { k30_ti_reschedule_notify_parties($sid, $old, true); } catch (\Throwable $e) {} }
        pl_audit('session', $sid, 'sc_reschedule', $old ?: [], ['date' => $date, 'from' => $tf, 'to' => $tt], $by);
        db_exec("UPDATE k30_sc_request_items SET resolution='reschedule', resolved_by=?, resolved_at=datetime('now') WHERE id=?", [$by, $item_id]);
        return ['ok' => true, 'msg' => 'Lekcja przełożona.'];
    }
    if ($action === 'keep') {
        db_exec("UPDATE k30_sc_request_items SET resolution='keep', resolved_by=?, resolved_at=datetime('now') WHERE id=?", [$by, $item_id]);
        return ['ok' => true, 'msg' => 'Lekcja bez zmian.'];
    }
    return ['ok' => false, 'msg' => 'Nieznana akcja.'];
}

/**
 * Decyzja kierownika. approve: dla niedostępności zakłada urlop (blokuje trenera w planie i
 * powiadamia kursantów); $auto — jednym kliknięciem dobiera najlepszych zastępców.
 * @return array{ok:bool,msg:string}
 */
function sc_request_decide(int $id, string $decision, string $note, int $by, bool $auto = false, bool $force = false): array {
    $r = db_one("SELECT * FROM k30_sc_requests WHERE id=?", [$id]);
    if (!$r || $r['status'] !== 'pending') return ['ok' => false, 'msg' => 'Zgłoszenie nie oczekuje na decyzję.'];
    $iid = (int)$r['instructor_id'];
    $inst = db_one("SELECT email FROM users WHERE id=?", [$iid]);
    $mailto = fn(string $subj, string $body) => sc_mail((string)($inst['email'] ?? ''), sc_user_name($iid), $subj, $body, 'sc_request_instr', $id);
    $nt = trim($note) !== '' ? '<br>Uwagi kierownika: ' . h(trim($note)) : '';

    if ($decision === 'reject') {
        db_exec("UPDATE k30_sc_requests SET status='rejected', decided_by=?, decided_at=datetime('now'), decision_note=? WHERE id=?", [$by, trim($note), $id]);
        $mailto('Zgłoszenie odrzucone', 'Twoje zgłoszenie (' . ($r['type'] === 'reschedule' ? 'zmiana terminu lekcji' : 'niedostępność ' . h($r['date_from'] . ' – ' . $r['date_to'] . (($r['time_from'] ?? '') !== '' ? ', ' . $r['time_from'] . '–' . $r['time_to'] : ''))) . ') zostało <strong>odrzucone</strong>.' . $nt);
        return ['ok' => true, 'msg' => 'Zgłoszenie odrzucone.'];
    }
    if ($decision !== 'approve') return ['ok' => false, 'msg' => 'Nieznana decyzja.'];

    if ($r['type'] === 'reschedule') {
        $item = ['request_id' => $id, 'session_id' => (int)$r['session_id']];
        db_exec("INSERT OR IGNORE INTO k30_sc_request_items (request_id, session_id) VALUES (?,?)", [$id, (int)$r['session_id']]);
        $iid_item = (int)db_one("SELECT id FROM k30_sc_request_items WHERE request_id=? AND session_id=?", [$id, (int)$r['session_id']])['id'];
        $res = sc_apply_item($iid_item, 'reschedule', ['date' => $r['proposed_date'], 'time_from' => $r['proposed_from'], 'time_to' => $r['proposed_to']], $by, $force);
        if (!$res['ok']) return $res + ['msg' => $res['msg']];
        db_exec("UPDATE k30_sc_requests SET status='approved', decided_by=?, decided_at=datetime('now'), decision_note=? WHERE id=?", [$by, trim($note), $id]);
        $mailto('Zmiana terminu zatwierdzona', 'Wniosek o zmianę terminu lekcji został <strong>zatwierdzony</strong>: nowy termin ' . h($r['proposed_date'] . ' ' . $r['proposed_from'] . '–' . $r['proposed_to']) . '.' . $nt);
        return ['ok' => true, 'msg' => 'Wniosek zatwierdzony, lekcja przełożona.'];
    }

    // niedostępność
    ti_leaves_migrate();
    $lid = (int)db_insert('k30_ti_instructor_leaves', ['instructor_id' => $iid, 'date_from' => $r['date_from'], 'date_to' => $r['date_to'],
        'time_from' => (string)($r['time_from'] ?? ''), 'time_to' => (string)($r['time_to'] ?? ''),
        'type' => $r['leave_type'], 'note' => (string)$r['reason'], 'created_by' => $by]);
    db_exec("UPDATE k30_sc_requests SET status='approved', leave_id=?, decided_by=?, decided_at=datetime('now'), decision_note=? WHERE id=?", [$lid, $by, trim($note), $id]);
    try { ti_leaves_notify($lid); } catch (\Throwable $e) {}
    $done = 0; $left = 0;
    foreach (db_all("SELECT id, session_id FROM k30_sc_request_items WHERE request_id=? AND resolution='pending'", [$id]) as $it) {
        $best = $auto ? (sc_suggest_substitutes((int)$it['session_id'], 1)[0] ?? null) : null;
        if ($best && empty($best['soft']) && sc_apply_item((int)$it['id'], 'substitute', ['substitute_id' => $best['id']], $by, false)['ok']) $done++;
        else $left++;
    }
    $mailto('Niedostępność zatwierdzona', 'Zgłoszona niedostępność ' . h($r['date_from'] . ' – ' . $r['date_to'] . (($r['time_from'] ?? '') !== '' ? ', ' . $r['time_from'] . '–' . $r['time_to'] : '')) . ' została <strong>zatwierdzona</strong>. Lekcje, które wymagają zastępstwa lub zmiany, kierownik rozstrzyga osobno.' . $nt);
    return ['ok' => true, 'msg' => 'Zatwierdzono.' . ($auto ? " Automatycznie przypisano zastępstwa: $done." : '') . ($left ? " Do rozstrzygnięcia ręcznie: $left." : '')];
}

function sc_request_withdraw(int $id, int $iid): bool {
    $st = db()->prepare("UPDATE k30_sc_requests SET status='cancelled', decided_at=datetime('now') WHERE id=? AND instructor_id=? AND status='pending'");
    $st->execute([$id, $iid]);
    return $st->rowCount() > 0;
}
