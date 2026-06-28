<?php
/**
 * includes/ti_reschedule.php — Zmiana terminu lekcji TI.
 *
 * Dwie ścieżki:
 *   • Prowadzący / admin  → zmienia termin bezpośrednio (k30_ti_do_reschedule).
 *   • Kursant / opiekun    → proponuje nowy termin (k30_ti_request_reschedule),
 *                            prowadzący akceptuje lub odrzuca (k30_ti_reschedule_decide).
 *
 * Tabela:
 *   k30_ti_reschedule_requests — propozycje + log zmian terminu.
 */

require_once __DIR__ . '/karty30.php';

function k30_ti_reschedule_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_reschedule_requests (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id        INTEGER NOT NULL,
        course_id         INTEGER NOT NULL DEFAULT 0,
        client_id         INTEGER NOT NULL DEFAULT 0,
        requested_by_role TEXT    NOT NULL DEFAULT 'beneficjent',
        requested_by      TEXT    NOT NULL DEFAULT '',
        old_date          TEXT    NOT NULL DEFAULT '',
        old_from          TEXT    NOT NULL DEFAULT '',
        old_to            TEXT    NOT NULL DEFAULT '',
        proposed_date     TEXT    NOT NULL DEFAULT '',
        proposed_from     TEXT    NOT NULL DEFAULT '',
        proposed_to       TEXT    NOT NULL DEFAULT '',
        reason            TEXT    NOT NULL DEFAULT '',
        status            TEXT    NOT NULL DEFAULT 'pending',
        decided_by        TEXT    NOT NULL DEFAULT '',
        decided_at        DATETIME,
        decision_note     TEXT    NOT NULL DEFAULT '',
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_resch_sess ON k30_ti_reschedule_requests(session_id, status)");
}

/** Wylicza czas trwania (min) z godzin od–do; gdy brak — zwraca null (nie zmieniaj). */
function _k30_ti_dur_min(string $tf, string $tt): ?int {
    if ($tf === '' || $tt === '') return null;
    $m = (strtotime('1970-01-01 ' . $tt) - strtotime('1970-01-01 ' . $tf)) / 60;
    return $m > 0 ? (int)$m : null;
}

/**
 * Bezpośrednia zmiana terminu lekcji (prowadzący / admin).
 * Zwraca snapshot starego terminu: ['lesson_date','time_from','time_to'] albo null gdy lekcja nie istnieje.
 */
function k30_ti_do_reschedule(int $session_id, string $date, string $tf, string $tt): ?array {
    k30_ti_reschedule_migrate();
    $old = db_one("SELECT lesson_date, time_from, time_to, duration_min FROM k30_ti_sessions WHERE id=?", [$session_id]);
    if (!$old) return null;
    $dur = _k30_ti_dur_min($tf, $tt) ?? (int)($old['duration_min'] ?? 60);
    db()->prepare(
        "UPDATE k30_ti_sessions
         SET lesson_date=?, time_from=?, time_to=?, duration_min=?, updated_at=datetime('now')
         WHERE id=?"
    )->execute([$date, $tf, $tt, $dur, $session_id]);
    return [
        'lesson_date' => (string)($old['lesson_date'] ?? ''),
        'time_from'   => (string)($old['time_from'] ?? ''),
        'time_to'     => (string)($old['time_to'] ?? ''),
    ];
}

/**
 * Propozycja nowego terminu od kursanta / opiekuna — NIE zmienia lekcji od razu,
 * tylko zapisuje wniosek „pending" i powiadamia prowadzącego mailem.
 */
function k30_ti_request_reschedule(
    int $session_id, int $client_id, string $date, string $tf, string $tt,
    string $reason, string $role, string $by_label
): int {
    k30_ti_reschedule_migrate();
    $s = db_one("SELECT course_id, lesson_date, time_from, time_to FROM k30_ti_sessions WHERE id=?", [$session_id]);
    if (!$s) return 0;
    // Zastąp ewentualny wcześniejszy oczekujący wniosek tego samego kursanta dla tej lekcji
    db()->prepare("UPDATE k30_ti_reschedule_requests SET status='superseded' WHERE session_id=? AND client_id=? AND status='pending'")
        ->execute([$session_id, $client_id]);
    $id = db_insert('k30_ti_reschedule_requests', [
        'session_id'        => $session_id,
        'course_id'         => (int)($s['course_id'] ?? 0),
        'client_id'         => $client_id,
        'requested_by_role' => $role !== '' ? $role : 'beneficjent',
        'requested_by'      => $by_label,
        'old_date'          => (string)($s['lesson_date'] ?? ''),
        'old_from'          => (string)($s['time_from'] ?? ''),
        'old_to'            => (string)($s['time_to'] ?? ''),
        'proposed_date'     => $date,
        'proposed_from'     => $tf,
        'proposed_to'       => $tt,
        'reason'            => mb_substr($reason, 0, 1000),
        'status'            => 'pending',
    ]);
    k30_ti_notify_instructor_reschedule_request((int)$id);
    return (int)$id;
}

/** Pobierz wniosek o zmianę terminu. */
function k30_ti_reschedule_get(int $id): ?array {
    k30_ti_reschedule_migrate();
    return db_one("SELECT * FROM k30_ti_reschedule_requests WHERE id=?", [$id]) ?: null;
}

/** Oczekujące propozycje zmiany terminu dla podanych kursów (panel prowadzącego). */
function k30_ti_reschedule_pending_for_session(int $session_id): array {
    k30_ti_reschedule_migrate();
    return db_all(
        "SELECT r.*, cl.name AS client_name
         FROM k30_ti_reschedule_requests r
         LEFT JOIN k30_clients cl ON cl.id=r.client_id
         WHERE r.session_id=? AND r.status='pending'
         ORDER BY r.created_at",
        [$session_id]
    );
}

/** Łączna liczba oczekujących propozycji dla kursów. */
function k30_ti_reschedule_pending_count(array $course_ids): int {
    k30_ti_reschedule_migrate();
    $ids = array_values(array_filter(array_map('intval', $course_ids)));
    if (!$ids) return 0;
    $in = implode(',', $ids);
    return (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_reschedule_requests WHERE status='pending' AND course_id IN ($in)")['n'] ?? 0);
}

/**
 * Decyzja prowadzącego ws. propozycji: akceptacja (zmienia termin lekcji) albo odrzucenie.
 * Zwraca true gdy wniosek istniał i był oczekujący.
 */
function k30_ti_reschedule_decide(int $req_id, bool $accept, string $by_label, string $note = ''): bool {
    k30_ti_reschedule_migrate();
    $r = db_one("SELECT * FROM k30_ti_reschedule_requests WHERE id=? AND status='pending'", [$req_id]);
    if (!$r) return false;
    if ($accept) {
        $old = k30_ti_do_reschedule((int)$r['session_id'], (string)$r['proposed_date'], (string)$r['proposed_from'], (string)$r['proposed_to']);
        // Pozostałe oczekujące wnioski dla tej lekcji stają się nieaktualne
        db()->prepare("UPDATE k30_ti_reschedule_requests SET status='superseded' WHERE session_id=? AND status='pending' AND id<>?")
            ->execute([(int)$r['session_id'], $req_id]);
        if ($old !== null) k30_ti_reschedule_notify_parties((int)$r['session_id'], $old, false);
    }
    db()->prepare(
        "UPDATE k30_ti_reschedule_requests SET status=?, decided_by=?, decided_at=datetime('now'), decision_note=? WHERE id=?"
    )->execute([$accept ? 'accepted' : 'rejected', $by_label, mb_substr($note, 0, 500), $req_id]);
    k30_ti_notify_requester_reschedule_decision($req_id, $accept);
    return true;
}

/* ── Powiadomienia e-mail ─────────────────────────────────────────────────── */

function _k30_ti_when_label(string $date, string $from): string {
    if ($date === '') return '';
    $w = date('d.m.Y', strtotime($date));
    if ($from !== '') $w .= ' o ' . substr($from, 0, 5);
    return $w;
}

/** Adresy e-mail kursanta (+ opiekun gdy małoletni) dla danej lekcji/klienta. */
function _k30_ti_client_emails(int $client_id): array {
    $row = db_one(
        "SELECT cl.name, cl.email, a.is_minor, a.guardian_email
         FROM k30_clients cl
         LEFT JOIN k30_ti_student_accounts a ON a.client_id=cl.id AND a.is_active=1
         WHERE cl.id=? LIMIT 1",
        [$client_id]
    );
    if (!$row) return [];
    $emails = [];
    $primary = trim((string)($row['email'] ?? ''));
    if ($primary !== '' && filter_var($primary, FILTER_VALIDATE_EMAIL)) $emails[$primary] = (string)$row['name'];
    $gemail = trim((string)($row['guardian_email'] ?? ''));
    if (!empty($row['is_minor']) && $gemail !== '' && filter_var($gemail, FILTER_VALIDATE_EMAIL)) $emails[$gemail] = (string)$row['name'];
    return $emails;
}

function _k30_ti_send(array $emails, string $subject, string $html, string $tag, int $ref): void {
    if (!$emails) return;
    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    if (!function_exists('mail_queue_add')) return;
    foreach ($emails as $addr => $nm) {
        try { mail_queue_add($addr, (string)$nm, $subject, $html, '', $tag, $ref, '', false); }
        catch (\Throwable $e) {}
    }
}

/** E-mail do prowadzącego o propozycji nowego terminu od kursanta/opiekuna. */
function k30_ti_notify_instructor_reschedule_request(int $req_id): void {
    $r = db_one(
        "SELECT r.*, c.name AS course_name, c.instructor_id,
                COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS instructor_name,
                u.email AS instructor_email, cl.name AS client_name
         FROM k30_ti_reschedule_requests r
         JOIN k30_ti_sessions s ON s.id=r.session_id
         JOIN k30_ti_courses c ON c.id=s.course_id
         LEFT JOIN users u ON u.id=c.instructor_id
         LEFT JOIN k30_clients cl ON cl.id=r.client_id
         WHERE r.id=?",
        [$req_id]
    );
    if (!$r) return;
    $email = trim((string)($r['instructor_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    $org  = defined('ORG_NAME') ? ORG_NAME : 'TI';
    $oldW = _k30_ti_when_label((string)$r['old_date'], (string)$r['old_from']);
    $newW = _k30_ti_when_label((string)$r['proposed_date'], (string)$r['proposed_from']);
    $url  = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/dydaktyk/index.php?tab=lekcje';
    $who  = htmlspecialchars((string)($r['client_name'] ?: $r['requested_by']), ENT_QUOTES);
    $crs  = htmlspecialchars((string)$r['course_name'], ENT_QUOTES);
    $reasonBlock = trim((string)$r['reason']) !== '' ? '<p style="color:#555">Uzasadnienie: ' . htmlspecialchars((string)$r['reason'], ENT_QUOTES) . '</p>' : '';
    $subject = "{$org}: propozycja zmiany terminu — {$crs}";
    $html = '<p><strong>' . $who . '</strong> proponuje zmianę terminu lekcji <strong>' . $crs . '</strong>.</p>'
          . '<p>Obecny termin: <strong>' . htmlspecialchars($oldW, ENT_QUOTES) . '</strong><br>'
          . 'Proponowany termin: <strong>' . htmlspecialchars($newW, ENT_QUOTES) . '</strong></p>'
          . $reasonBlock
          . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Otwórz panel prowadzącego</a>, aby zaakceptować lub odrzucić propozycję.</p>';
    $name = (string)($r['instructor_name'] ?? '');
    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    if (!function_exists('mail_queue_add')) return;
    try { mail_queue_add($email, $name, $subject, $html, '', 'ti_reschedule_req', (int)$r['session_id'], '', false); }
    catch (\Throwable $e) {}
}

/** Powiadom kursanta/opiekuna o decyzji prowadzącego ws. propozycji terminu. */
function k30_ti_notify_requester_reschedule_decision(int $req_id, bool $accepted): void {
    $r = db_one(
        "SELECT r.*, c.name AS course_name
         FROM k30_ti_reschedule_requests r
         JOIN k30_ti_sessions s ON s.id=r.session_id
         JOIN k30_ti_courses c ON c.id=s.course_id
         WHERE r.id=?",
        [$req_id]
    );
    if (!$r) return;
    $emails = _k30_ti_client_emails((int)$r['client_id']);
    if (!$emails) return;
    $org  = defined('ORG_NAME') ? ORG_NAME : 'TI';
    $crs  = htmlspecialchars((string)$r['course_name'], ENT_QUOTES);
    $newW = _k30_ti_when_label((string)$r['proposed_date'], (string)$r['proposed_from']);
    $url  = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php?tab=lekcje';
    if ($accepted) {
        $subject = "{$org}: zmiana terminu zaakceptowana — {$crs}";
        $html = '<p>Twoja propozycja zmiany terminu lekcji <strong>' . $crs . '</strong> została <strong>zaakceptowana</strong>.</p>'
              . '<p>Nowy termin: <strong>' . htmlspecialchars($newW, ENT_QUOTES) . '</strong>.</p>';
    } else {
        $subject = "{$org}: propozycja terminu odrzucona — {$crs}";
        $html = '<p>Twoja propozycja zmiany terminu lekcji <strong>' . $crs . '</strong> została <strong>odrzucona</strong> — termin pozostaje bez zmian.</p>';
    }
    $note = trim((string)$r['decision_note']);
    if ($note !== '') $html .= '<p style="color:#555">Komentarz prowadzącego: ' . htmlspecialchars($note, ENT_QUOTES) . '</p>';
    $html .= '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Otwórz panel kursanta</a></p>';
    _k30_ti_send($emails, $subject, $html, 'ti_reschedule_decision', (int)$r['session_id']);
}

/**
 * Powiadom wszystkich aktywnych uczestników lekcji (+ opiekunów małoletnich)
 * o zmianie terminu. $old = stary termin (lesson_date/time_from/time_to).
 */
function k30_ti_reschedule_notify_parties(int $session_id, array $old, bool $also_sms = false): void {
    $s = db_one(
        "SELECT s.lesson_date, s.time_from, s.course_id, c.name AS course_name
         FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
         WHERE s.id=?",
        [$session_id]
    );
    if (!$s) return;
    $org  = defined('ORG_NAME') ? ORG_NAME : 'TI';
    $crs  = htmlspecialchars((string)$s['course_name'], ENT_QUOTES);
    $oldW = _k30_ti_when_label((string)($old['lesson_date'] ?? ''), (string)($old['time_from'] ?? ''));
    $newW = _k30_ti_when_label((string)$s['lesson_date'], (string)$s['time_from']);
    $url  = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/index.php?tab=lekcje';
    $subject = "{$org}: zmiana terminu lekcji — {$crs}";
    $html = '<p>Termin lekcji <strong>' . $crs . '</strong> został zmieniony.</p>'
          . '<p>Poprzedni termin: <strong>' . htmlspecialchars($oldW, ENT_QUOTES) . '</strong><br>'
          . 'Nowy termin: <strong>' . htmlspecialchars($newW, ENT_QUOTES) . '</strong></p>'
          . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Otwórz panel kursanta</a></p>';
    $enrollees = db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [(int)$s['course_id']]);
    foreach ($enrollees as $e) {
        $emails = _k30_ti_client_emails((int)$e['client_id']);
        _k30_ti_send($emails, $subject, $html, 'ti_reschedule', $session_id);
    }
    if ($also_sms && function_exists('ti_lesson_sms_notify')) {
        try { ti_lesson_sms_notify((int)$s['course_id'], 'Zmiana terminu zajec: ' . (string)$s['course_name'] . ' -> ' . $newW . '. Szczegoly w panelu kursanta.'); }
        catch (\Throwable $e) {}
    }
}
