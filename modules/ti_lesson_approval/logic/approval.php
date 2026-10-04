<?php
/**
 * modules/ti_lesson_approval/logic/approval.php — zatwierdzanie roboczych wersji lekcji (status 'draft').
 *
 * Zatwierdzenie = draft → planned (lekcja staje się widoczna dla kursantów) + opcjonalnie
 * e-mail do kursantów (i opiekunów małoletnich) z listą terminów i materiałami z lekcji.
 * Przed zatwierdzeniem pokazywany jest podgląd maila, załączników i ostrzeżeń.
 * Roboczych lekcji nie sprawdzano pod kątem kolizji przy zapisie (is_draft pomija bramki),
 * więc kolizje sali / prowadzącego sprawdzamy tu.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';

const TI_APPR_MAX_ATTACH_BYTES = 15 * 1024 * 1024;   // łącznie na jeden e-mail

/** Robocze lekcje kursu (bez szkiców serii planera, draft_id IS NULL), od najbliższej. */
function ti_appr_drafts(int $course_id): array {
    return db_all(
        "SELECT s.*, r.name AS room_name FROM k30_ti_sessions s
         LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
         WHERE s.course_id=? AND s.status='draft' AND s.draft_id IS NULL
         ORDER BY s.lesson_date, s.time_from", [$course_id]);
}

/** @return array<int,array> wybrane robocze lekcje kursu (nieznane / już zatwierdzone są pomijane) */
function ti_appr_sessions(int $course_id, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return db_all(
        "SELECT s.*, r.name AS room_name FROM k30_ti_sessions s
         LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
         WHERE s.course_id=? AND s.status='draft' AND s.draft_id IS NULL AND s.id IN ($ph)
         ORDER BY s.lesson_date, s.time_from", array_merge([$course_id], $ids));
}

/** Adresaci: kursant + opiekun małoletniego. [['email','name','kind']] */
function ti_appr_recipients(int $course_id): array {
    $rows = db_all(
        "SELECT cl.name, cl.email, a.is_minor, a.guardian_email, a.guardian_name
         FROM k30_ti_enrollments e
         JOIN k30_clients cl ON cl.id = e.client_id
         LEFT JOIN k30_ti_student_accounts a ON a.client_id = e.client_id
         WHERE e.course_id=? AND e.status='active' ORDER BY cl.name", [$course_id]);
    $out = []; $seen = [];
    $add = function (string $mail, string $name, string $kind) use (&$out, &$seen) {
        $mail = trim($mail); $k = strtolower($mail);
        if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL) || isset($seen[$k])) return;
        $seen[$k] = 1; $out[] = ['email' => $mail, 'name' => $name, 'kind' => $kind];
    };
    foreach ($rows as $r) {
        $add((string)$r['email'], (string)$r['name'], 'kursant');
        if (!empty($r['is_minor'])) $add((string)($r['guardian_email'] ?? ''), (string)($r['guardian_name'] ?: 'Opiekun: ' . $r['name']), 'opiekun');
    }
    return $out;
}

/** Materiały przypisane do wybranych lekcji: pliki (załączniki) i linki. */
function ti_appr_materials(array $session_ids): array {
    $session_ids = array_values(array_unique(array_map('intval', $session_ids)));
    $files = []; $links = [];
    if (!$session_ids) return ['files' => $files, 'links' => $links];
    $ph = implode(',', array_fill(0, count($session_ids), '?'));
    foreach (db_all("SELECT * FROM k30_ti_materials WHERE is_active=1 AND session_id IN ($ph) ORDER BY id", $session_ids) as $m) {
        if ((string)$m['attach_path'] !== '') {
            $path = rtrim(UPLOAD_DIR, '/') . '/ti_homework/' . basename((string)$m['attach_path']);
            $ok = is_file($path);
            $files[] = ['title' => (string)$m['title'], 'name' => (string)($m['attach_name'] ?: basename($path)), 'path' => $path,
                        'size' => $ok ? (int)filesize($path) : 0, 'exists' => $ok,
                        'mime' => $ok ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream'];
        } elseif (trim((string)$m['url']) !== '') {
            $links[] = ['title' => (string)$m['title'], 'url' => (string)$m['url']];
        }
    }
    return ['files' => $files, 'links' => $links];
}

/** Ostrzeżenia przed zatwierdzeniem (kolizje, braki, daty wstecz, brak adresatów/plików). */
function ti_appr_warnings(int $course_id, array $sessions, array $recipients, array $mat): array {
    $w = [];
    $today = date('Y-m-d');
    foreach ($sessions as $s) {
        $lbl = date('d.m.Y', strtotime((string)$s['lesson_date'])) . ' ' . substr((string)$s['time_from'], 0, 5);
        if ((string)$s['lesson_date'] < $today) $w[] = "Lekcja $lbl ma datę z przeszłości — kursanci zobaczą ją jako już minioną.";
        if (trim((string)$s['topic']) === '') $w[] = "Lekcja $lbl nie ma tematu.";
        $room = (int)($s['room_id'] ?? 0);
        $instr = (int)($s['instructor_id'] ?? 0) ?: (int)(db_one("SELECT instructor_id FROM k30_ti_courses WHERE id=?", [$course_id])['instructor_id'] ?? 0);
        $sql = "SELECT s2.lesson_date, s2.time_from, c.name AS course_name FROM k30_ti_sessions s2
                JOIN k30_ti_courses c ON c.id=s2.course_id
                WHERE s2.id<>? AND s2.lesson_date=? AND s2.status NOT IN ('draft','cancelled')
                  AND s2.time_from < ? AND s2.time_to > ?";
        $par = [(int)$s['id'], (string)$s['lesson_date'], (string)$s['time_to'], (string)$s['time_from']];
        $cond = [];
        if ($room)  { $cond[] = "s2.room_id=?"; $par[] = $room; }
        if ($instr) { $cond[] = "COALESCE(s2.instructor_id, c.instructor_id)=?"; $par[] = $instr; }
        if ($cond) {
            foreach (db_all($sql . ' AND (' . implode(' OR ', $cond) . ')', $par) as $k) {
                $w[] = "Kolizja terminu $lbl z lekcją „{$k['course_name']}” o " . substr((string)$k['time_from'], 0, 5) . ' (ta sama sala lub prowadzący).';
            }
        }
        if (in_array((string)$s['lesson_method'], ['zdalna_zoom', 'zdalna_inne'], true) && trim((string)$s['meeting_url']) === '') {
            $w[] = "Lekcja online $lbl nie ma linku do spotkania.";
        }
    }
    if (!$recipients) $w[] = 'Żaden kursant w tym kursie nie ma poprawnego adresu e-mail — mail nie zostanie do nikogo wysłany.';
    $total = 0;
    foreach ($mat['files'] as $f) {
        if (!$f['exists']) $w[] = 'Brak pliku na dysku: ' . $f['name'] . ' — nie zostanie dołączony.';
        $total += $f['size'];
    }
    if ($total > TI_APPR_MAX_ATTACH_BYTES) $w[] = 'Załączniki przekraczają ' . (TI_APPR_MAX_ATTACH_BYTES / 1048576) . ' MB — zamiast plików w mailu pójdzie wzmianka o materiałach w panelu.';
    return array_values(array_unique($w));
}

/** Treść maila (temat + HTML, osobno dla podglądu i wysyłki). */
function ti_appr_compose(array $course, array $sessions, array $mat, string $first_name = '{imię}'): array {
    $org  = defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta';
    $days = ['niedziela','poniedziałek','wtorek','środa','czwartek','piątek','sobota'];
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $subject = 'Nowe zajęcia: ' . $course['name'] . (count($sessions) > 1 ? ' (' . count($sessions) . ' terminów)' : '');
    $rows = '';
    foreach ($sessions as $s) {
        $ts = strtotime((string)$s['lesson_date']);
        $where = in_array((string)$s['lesson_method'], ['zdalna_zoom', 'zdalna_inne'], true)
            ? 'online' . (trim((string)$s['meeting_url']) !== '' ? ' — <a href="' . $e($s['meeting_url']) . '">link do spotkania</a>' : '')
            : ($s['room_name'] ? $e($s['room_name']) : 'stacjonarnie');
        $rows .= '<tr><td style="padding:4px 8px;border:1px solid #ccc">' . $e(date('d.m.Y', $ts) . ' (' . $days[(int)date('w', $ts)] . ')') . '</td>'
              . '<td style="padding:4px 8px;border:1px solid #ccc">' . $e(substr((string)$s['time_from'], 0, 5) . '–' . substr((string)$s['time_to'], 0, 5)) . '</td>'
              . '<td style="padding:4px 8px;border:1px solid #ccc">' . $e($s['topic'] !== '' ? $s['topic'] : '—') . '</td>'
              . '<td style="padding:4px 8px;border:1px solid #ccc">' . $where . '</td></tr>';
    }
    $body = '<p>W kursie <strong>' . $e($course['name']) . '</strong> zaplanowano '
          . (count($sessions) > 1 ? 'nowe zajęcia:' : 'nowe zajęcia:') . '</p>'
          . '<table style="border-collapse:collapse"><thead><tr><th style="padding:4px 8px;border:1px solid #ccc;text-align:left">Data</th><th style="padding:4px 8px;border:1px solid #ccc;text-align:left">Godziny</th><th style="padding:4px 8px;border:1px solid #ccc;text-align:left">Temat</th><th style="padding:4px 8px;border:1px solid #ccc;text-align:left">Miejsce</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    if ($mat['links']) {
        $body .= '<p>Materiały online:</p><ul>';
        foreach ($mat['links'] as $l) $body .= '<li><a href="' . $e($l['url']) . '">' . $e($l['title'] ?: $l['url']) . '</a></li>';
        $body .= '</ul>';
    }
    if ($mat['files']) $body .= '<p>W załączniku: ' . $e(implode(', ', array_map(fn($f) => $f['name'], $mat['files']))) . '.</p>';
    $body .= '<p>Szczegóły znajdziesz w panelu kursanta.</p>';
    $url = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/kursant/index.php?tab=lekcje';
    if (!function_exists('email_tpl_render')) @require_once dirname(dirname(dirname(__DIR__))) . '/includes/email_templates.php';
    $r = function_exists('email_tpl_render') ? email_tpl_render('ti_dydaktyka', [
        'org' => $org, 'name' => $e($first_name), 'subject' => $e($subject), 'body_html' => $body, 'url' => $e($url),
    ]) : ['subject' => "$org: $subject", 'html' => '<p>Cześć ' . $e($first_name) . ',</p>' . $body];
    return ['subject' => (string)$r['subject'], 'html' => (string)$r['html']];
}

/** Zatwierdza wybrane robocze lekcje; opcjonalnie wysyła mail. @return array{approved:int,mails:int} */
function ti_appr_approve(int $course_id, array $ids, bool $send_mail, int $uid): array {
    $sessions = ti_appr_sessions($course_id, $ids);
    if (!$sessions) return ['approved' => 0, 'mails' => 0];
    foreach ($sessions as $s) {
        db_exec("UPDATE k30_ti_sessions SET status='planned', updated_at=datetime('now') WHERE id=? AND status='draft'", [(int)$s['id']]);
    }
    $mails = 0;
    if ($send_mail) {
        $course = db_one("SELECT * FROM k30_ti_courses WHERE id=?", [$course_id]) ?: ['name' => ''];
        $mat = ti_appr_materials(array_column($sessions, 'id'));
        $att = []; $sum = 0;
        foreach ($mat['files'] as $f) if ($f['exists']) { $sum += $f['size']; }
        if ($sum <= TI_APPR_MAX_ATTACH_BYTES) {
            foreach ($mat['files'] as $f) if ($f['exists']) $att[] = ['path' => $f['path'], 'name' => $f['name'], 'mime' => $f['mime'], 'size' => $f['size']];
        }
        if (!function_exists('mail_queue_add')) require_once dirname(dirname(dirname(__DIR__))) . '/includes/mail_queue.php';
        foreach (ti_appr_recipients($course_id) as $rc) {
            $first = explode(' ', trim($rc['name']))[0] ?: $rc['name'];
            $m = ti_appr_compose($course, $sessions, $mat, $first);
            try {
                if (mail_queue_add($rc['email'], $rc['name'], $m['subject'], $m['html'], '', 'ti_lesson_approval', $course_id, '', true, $att)) $mails++;
            } catch (\Throwable $e) { error_log('[ti_appr] ' . $e->getMessage()); }
        }
    }
    return ['approved' => count($sessions), 'mails' => $mails];
}
