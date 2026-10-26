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

/** Powody, dla których zatwierdzenie jest niemożliwe (robocza wersja bez terminu). @return string[] */
function ti_appr_blockers(array $sessions): array {
    $n = count(array_filter($sessions, fn($s) => (string)$s['lesson_date'] === ''));
    return $n ? ["$n z wybranych wersji roboczych nie ma daty — uzupełnij termin (edycja lekcji), zanim je zatwierdzisz. Takie lekcje zostaną pominięte."] : [];
}

/** Ostrzeżenia przed zatwierdzeniem (kolizje, braki, daty wstecz, brak adresatów/plików). */
function ti_appr_warnings(int $course_id, array $sessions, array $recipients, array $mat): array {
    $w = [];
    $course = db_one("SELECT * FROM k30_ti_courses WHERE id=?", [$course_id]) ?: [];
    $today = date('Y-m-d');
    foreach ($sessions as $s) {
        if ((string)$s['lesson_date'] === '') continue;
        $lbl = date('d.m.Y', strtotime((string)$s['lesson_date'])) . ' ' . substr((string)$s['time_from'], 0, 5);
        if ((string)$s['lesson_date'] < $today) $w[] = "Lekcja $lbl ma datę z przeszłości — kursanci zobaczą ją jako już minioną.";
        if (trim((string)$s['topic']) === '') $w[] = "Lekcja $lbl nie ma tematu.";
        $room = (int)($s['room_id'] ?? 0);
        $instr = (int)($s['instructor_id'] ?? 0) ?: (int)(db_one("SELECT instructor_id FROM k30_ti_courses WHERE id=?", [$course_id])['instructor_id'] ?? 0);
        $sql = "SELECT s2.lesson_date, s2.time_from, c.name AS course_name FROM k30_ti_sessions s2
                JOIN k30_ti_courses c ON c.id=s2.course_id
                WHERE s2.id<>? AND s2.lesson_date=? AND s2.status NOT IN ('draft','cancelled')
                  AND s2.time_from < ? AND s2.time_to > ? AND COALESCE(c.status,'active')!='archived'";
        $par = [(int)$s['id'], (string)$s['lesson_date'], (string)$s['time_to'], (string)$s['time_from']];
        $cond = [];
        if ($room)  { $cond[] = "s2.room_id=?"; $par[] = $room; }
        if ($instr) { $cond[] = "COALESCE(s2.instructor_id, c.instructor_id)=?"; $par[] = $instr; }
        if ($cond) {
            foreach (db_all($sql . ' AND (' . implode(' OR ', $cond) . ')', $par) as $k) {
                $w[] = "Kolizja terminu $lbl z lekcją „{$k['course_name']}” o " . substr((string)$k['time_from'], 0, 5) . ' (ta sama sala lub prowadzący).';
            }
        }
        if (ti_appr_is_online($s) && ti_appr_meeting_url($s, $course) === '') {
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

/** Czy lekcja odbywa się online (Zoom lub inny kanał zdalny). */
function ti_appr_is_online(array $s): bool {
    return in_array((string)($s['lesson_method'] ?? ''), ['zdalna_zoom', 'zdalna_inne'], true);
}

/** Link do spotkania: z lekcji, a gdy pusty — domyślny link kursu. */
function ti_appr_meeting_url(array $s, array $course): string {
    $u = trim((string)($s['meeting_url'] ?? ''));
    return $u !== '' ? $u : trim((string)($course['default_meeting_url'] ?? ''));
}

/** Wiersze harmonogramu do plików (data, dzień, godziny, temat, forma, miejsce/link). */
function ti_appr_schedule_rows(array $course, array $sessions): array {
    $days = ['niedziela','poniedziałek','wtorek','środa','czwartek','piątek','sobota'];
    $rows = [];
    foreach ($sessions as $s) {
        if ((string)$s['lesson_date'] === '') continue;
        $ts = strtotime((string)$s['lesson_date']);
        $online = ti_appr_is_online($s);
        $rows[] = [date('d.m.Y', $ts), $days[(int)date('w', $ts)], substr((string)$s['time_from'], 0, 5) . '–' . substr((string)$s['time_to'], 0, 5),
            trim((string)$s['topic']) !== '' ? (string)$s['topic'] : '—', $online ? 'online' : 'stacjonarnie',
            $online ? (ti_appr_meeting_url($s, $course) ?: '(link zostanie przesłany osobno)') : (string)($s['room_name'] ?: '—')];
    }
    return $rows;
}

function ti_appr_schedule_basename(array $course): string {
    $slug = preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$course['name']) ?: 'kurs');
    return 'harmonogram_' . trim($slug, '_') . '_' . date('Ymd');
}

/** Nazwy plików harmonogramu (do podglądu — bez generowania). */
function ti_appr_schedule_names(array $course): array {
    $b = ti_appr_schedule_basename($course);
    return [$b . '.pdf', $b . '.xlsx', $b . '.txt'];
}

/** Generuje harmonogram jako PDF + XLSX + TXT (ten sam zestaw dla wszystkich adresatów jednego maila). */
function ti_appr_schedule_files(array $course, array $sessions): array {
    return ti_appr_files_from_rows($course, ['Data', 'Dzień tygodnia', 'Godziny', 'Temat', 'Forma', 'Miejsce / link'], ti_appr_schedule_rows($course, $sessions), 'Harmonogram zajęć');
}

/** Pliki harmonogramu (TXT, XLSX, PDF) z gotowych wierszy tabeli. */
function ti_appr_files_from_rows(array $course, array $head, array $rows, string $title_prefix): array {
    $dir = rtrim(UPLOAD_DIR, '/') . '/ti_harmonogram_mail';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $base = ti_appr_schedule_basename($course); $uniq = bin2hex(random_bytes(4));
    $title = $title_prefix . ' — ' . $course['name'];
    $out = [];
    $add = function (string $ext, string $mime, string $data) use (&$out, $dir, $base, $uniq) {
        $path = $dir . '/' . $base . '_' . $uniq . '.' . $ext;
        if (file_put_contents($path, $data) === false) return;
        $out[] = ['title' => 'Harmonogram', 'name' => $base . '.' . $ext, 'path' => $path, 'size' => (int)filesize($path), 'exists' => true, 'mime' => $mime, 'generated' => true];
    };
    // TXT — prosty, czytelny także dla czytników ekranu
    $txt = $title . "\r\nWygenerowano: " . date('d.m.Y H:i') . "\r\n\r\n";
    foreach ($rows as $i => $r) {
        $parts = []; foreach ($r as $k => $v) $parts[] = $head[$k] . ': ' . $v;
        $txt .= ($i + 1) . '. ' . implode('; ', $parts) . "\r\n";
    }
    $add('txt', 'text/plain; charset=UTF-8', "\xEF\xBB\xBF" . $txt);
    // XLSX
    try {
        require_once dirname(dirname(dirname(__DIR__))) . '/includes/xlsx.php';
        $x = new XlsxWriter(); $x->addSheet('Harmonogram');
        $x->writeRow($head, ['header']);
        foreach ($rows as $r) $x->writeRow($r);
        $tmp = tempnam(sys_get_temp_dir(), 'hx'); $x->save($tmp);
        $add('xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', (string)file_get_contents($tmp)); @unlink($tmp);
    } catch (\Throwable $e) { error_log('[ti_appr xlsx] ' . $e->getMessage()); }
    // PDF (mPDF — natywne UTF-8)
    try {
        require_once dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';
        $tmpd = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp'; if (!is_dir($tmpd)) @mkdir($tmpd, 0755, true);
        // Kody w stopce (rejestr dokumentów: QR + Code128 + kod) — ten sam harmonogram (kurs + treść wierszy) ma ten sam kod; błąd kodów nie blokuje PDF
        require_once dirname(__DIR__, 3) . '/includes/ti_terms.php';
        $codes = ti_pdf_print_codes('ti_harmonogram_mail', (int)($course['id'] ?? 0) . '|' . substr(md5($title_prefix . json_encode($rows)), 0, 12), $title);
        $m = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'default_font' => 'dejavusans', 'tempDir' => $tmpd,
                             'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => $codes ? 26 : 12, 'margin_footer' => 5]);
        $m->SetTitle($title);
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        require_once dirname(__DIR__, 3) . '/modules/ti_pdf/logic/tiMetroCss.php';
        $m->SetHTMLFooter(ti_mpdf_codes_html($codes, false, ti_metro_pdf_footer('Harmonogram · ' . date('d.m.Y H:i'))));
        $m->WriteHTML(ti_metro_pdf_css(), \Mpdf\HTMLParserMode::HEADER_CSS);
        $html = '<h2>' . $h($title) . '</h2><table class="zebra"><thead><tr>';
        foreach ($head as $c) $html .= '<th>' . $h($c) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $r) { $html .= '<tr>'; foreach ($r as $c) $html .= '<td>' . $h($c) . '</td>'; $html .= '</tr>'; }
        $m->WriteHTML($html . '</tbody></table><p class="s">Wygenerowano: ' . date('d.m.Y H:i') . '</p>');
        $add('pdf', 'application/pdf', $m->Output('', \Mpdf\Output\Destination::STRING_RETURN));
    } catch (\Throwable $e) { error_log('[ti_appr pdf] ' . $e->getMessage()); }
    return $out;
}

/** Treść maila (temat + HTML, osobno dla podglądu i wysyłki). */
function ti_appr_compose(array $course, array $sessions, array $mat, string $first_name = '{imię}', string $kind = 'approve'): array {
    $org  = defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta';
    $days = ['niedziela','poniedziałek','wtorek','środa','czwartek','piątek','sobota'];
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $n_online = count(array_filter($sessions, 'ti_appr_is_online'));
    $all_online = $n_online === count($sessions) && $n_online > 0;
    $subject = ($kind === 'method' ? 'Zmiana zajęć: ' : 'Nowe zajęcia: ') . $course['name']
             . (count($sessions) > 1 ? ' (' . count($sessions) . ' terminów)' : '')
             . ($all_online ? ' — online' : ($n_online ? ' — w tym online' : ''));
    $cell = 'padding:4px 8px;border:1px solid #ccc';
    $rows = '';
    foreach ($sessions as $s) {
        if ((string)$s['lesson_date'] === '') continue;
        $ts = strtotime((string)$s['lesson_date']);
        if (ti_appr_is_online($s)) {
            $u = ti_appr_meeting_url($s, $course);
            $where = '<strong>ONLINE</strong>' . ($u !== '' ? '<br>Link: <a href="' . $e($u) . '">' . $e($u) . '</a>' : '<br>(link zostanie przesłany osobno)');
        } else {
            $where = $s['room_name'] ? $e($s['room_name']) : 'stacjonarnie';
        }
        $rows .= '<tr><td style="' . $cell . '">' . $e(date('d.m.Y', $ts) . ' (' . $days[(int)date('w', $ts)] . ')') . '</td>'
              . '<td style="' . $cell . '">' . $e(substr((string)$s['time_from'], 0, 5) . '–' . substr((string)$s['time_to'], 0, 5)) . '</td>'
              . '<td style="' . $cell . '">' . $e($s['topic'] !== '' ? $s['topic'] : '—') . '</td>'
              . '<td style="' . $cell . '">' . $where . '</td></tr>';
    }
    $lead = $kind === 'method'
        ? 'W kursie <strong>' . $e($course['name']) . '</strong> zmieniły się parametry zajęć (termin, godziny, forma lub miejsce). Aktualne dane:'
        : 'W kursie <strong>' . $e($course['name']) . '</strong> zaplanowano nowe zajęcia:';
    $body = '<p>' . $lead . '</p>';
    if ($n_online) {
        $body .= '<p><strong>' . ($all_online ? 'Zajęcia odbędą się online.' : 'Część zajęć odbędzie się online.')
              . '</strong> Link do spotkania jest przy każdym terminie online.</p>';
    }
    $th = 'padding:4px 8px;border:1px solid #ccc;text-align:left';
    $body .= '<table style="border-collapse:collapse"><thead><tr><th style="' . $th . '">Data</th><th style="' . $th . '">Godziny</th><th style="' . $th . '">Temat</th><th style="' . $th . '">Miejsce / forma</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    if ($mat['links']) {
        $body .= '<p>Materiały online:</p><ul>';
        foreach ($mat['links'] as $l) $body .= '<li><a href="' . $e($l['url']) . '">' . $e($l['title'] ?: $l['url']) . '</a></li>';
        $body .= '</ul>';
    }
    if (!empty($mat['sched'])) $body .= '<p><strong>Harmonogram znajdziesz także w załączniku</strong> (' . $e(implode(', ', $mat['sched'])) . ').</p>';
    if ($mat['files']) $body .= '<p>W załączniku: ' . $e(implode(', ', array_map(fn($f) => $f['name'], $mat['files']))) . '.</p>';
    $body .= '<p>Szczegóły znajdziesz w panelu kursanta.</p>';
    return ti_appr_wrap($org, $subject, $body, $first_name, $e);
}

/** Opakowanie treści szablonem e-mail „ti_dydaktyka" (z awaryjnym prostym HTML-em). */
function ti_appr_wrap(string $org, string $subject, string $body, string $first_name, callable $e): array {
    $url = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/kursant/index.php?tab=lekcje';
    if (!function_exists('email_tpl_render')) @require_once dirname(dirname(dirname(__DIR__))) . '/includes/email_templates.php';
    $r = function_exists('email_tpl_render') ? email_tpl_render('ti_dydaktyka', [
        'org' => $org, 'name' => $e($first_name), 'subject' => $e($subject), 'body_html' => $body, 'url' => $e($url),
    ]) : ['subject' => "$org: $subject", 'html' => '<p>Cześć ' . $e($first_name) . ',</p>' . $body];
    return ['subject' => (string)$r['subject'], 'html' => (string)$r['html']];
}

/** Wspólny wysyłacz: ten sam mail (z personalizacją imienia) do wszystkich adresatów kursu. */
function ti_appr_send_mails(int $course_id, array $course, array $sessions, array $mat, string $kind, string $ctx): int {
    $att = []; $sum = 0;
    foreach ($mat['files'] as $f) if ($f['exists']) $sum += $f['size'];
    if ($sum <= TI_APPR_MAX_ATTACH_BYTES) {
        foreach ($mat['files'] as $f) if ($f['exists']) $att[] = ['path' => $f['path'], 'name' => $f['name'], 'mime' => $f['mime'], 'size' => $f['size']];
    }
    $gen = $sessions ? ti_appr_schedule_files($course, $sessions) : [];
    foreach ($gen as $g) $att[] = ['path' => $g['path'], 'name' => $g['name'], 'mime' => $g['mime'], 'size' => $g['size']];
    $mat['sched'] = array_map(fn($g) => $g['name'], $gen);
    if (!function_exists('mail_queue_add')) require_once dirname(dirname(dirname(__DIR__))) . '/includes/mail_queue.php';
    $n = 0;
    foreach (ti_appr_recipients($course_id) as $rc) {
        $first = explode(' ', trim($rc['name']))[0] ?: $rc['name'];
        $m = ti_appr_compose($course, $sessions, $mat, $first, $kind);
        try {
            if (mail_queue_add($rc['email'], $rc['name'], $m['subject'], $m['html'], '', $ctx, $course_id, '', true, $att)) $n++;
        } catch (\Throwable $e) { error_log('[ti_appr] ' . $e->getMessage()); }
    }
    return $n;
}

/** Zatwierdza wybrane robocze lekcje; opcjonalnie wysyła mail. @return array{approved:int,mails:int} */
function ti_appr_approve(int $course_id, array $ids, bool $send_mail, int $uid): array {
    $sessions = array_values(array_filter(ti_appr_sessions($course_id, $ids), fn($x) => (string)$x['lesson_date'] !== ''));   // bez daty nie zatwierdzamy
    if (!$sessions) return ['approved' => 0, 'mails' => 0];
    foreach ($sessions as $s) {
        db_exec("UPDATE k30_ti_sessions SET status='planned', updated_at=datetime('now') WHERE id=? AND status='draft'", [(int)$s['id']]);
    }
    $mails = 0;
    if ($send_mail) {
        $course = db_one("SELECT * FROM k30_ti_courses WHERE id=?", [$course_id]) ?: ['name' => ''];
        $mails = ti_appr_send_mails($course_id, $course, $sessions, ti_appr_materials(array_column($sessions, 'id')), 'approve', 'ti_lesson_approval');
    }
    return ['approved' => count($sessions), 'mails' => $mails];
}


/* ── Masowa zmiana wszystkich parametrów terminu ──────────────────────────── */

const TI_MM_METHODS = ['' => '— bez formy —', 'stacjonarna' => 'Stacjonarna', 'zdalna_zoom' => 'Zdalna — Zoom', 'zdalna_inne' => 'Zdalna — inna (link własny)'];
const TI_MM_STATUSES = ['draft' => 'Wersja robocza', 'reserved' => 'Rezerwacja', 'planned' => 'Zaplanowana'];
/** Pola do zmiany: nazwa pola formularza (chg_<nazwa> = „zmień to pole"). */
const TI_MM_FIELDS = ['status', 'lesson_method', 'meeting_url', 'room_id', 'time_from', 'time_to', 'shift_days',
                      'topic', 'notes', 'has_homework', 'self_prep_remote', 'date_flag', 'instructor_id'];

/** Wybór lekcji do zmiany: zakres dat + obecna forma + obecny typ terminu. Tylko nadchodzące, niezakończone. */
function ti_mm_candidates(int $course_id, array $f): array {
    // Jawna lista lekcji (np. zaznaczone wersje robocze) — bez filtra dat, obejmuje też szkice bez terminu.
    $ids = $f['ids'] ?? [];
    if (is_string($ids)) $ids = explode(',', $ids);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return db_all("SELECT s.*, r.name AS room_name FROM k30_ti_sessions s LEFT JOIN k30_pl_rooms r ON r.id=s.room_id
                       WHERE s.course_id=? AND s.draft_id IS NULL AND s.status IN ('planned','reserved','draft') AND s.id IN ($ph)
                       ORDER BY s.lesson_date='', s.lesson_date, s.time_from", array_merge([$course_id], $ids));
    }
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['from'] ?? '')) ? $f['from'] : date('Y-m-d');
    $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['to'] ?? '')) ? $f['to'] : date('Y-m-d', strtotime('+90 days'));
    $sql = "SELECT s.*, r.name AS room_name FROM k30_ti_sessions s LEFT JOIN k30_pl_rooms r ON r.id=s.room_id
            WHERE s.course_id=? AND s.draft_id IS NULL AND s.status IN ('planned','reserved','draft')
              AND s.lesson_date BETWEEN ? AND ?";
    $par = [$course_id, max($from, date('Y-m-d')), $to];
    $om = (string)($f['only_method'] ?? 'any');
    if ($om !== 'any') { $sql .= " AND COALESCE(s.lesson_method,'')=?"; $par[] = $om === 'none' ? '' : $om; }
    $os = (string)($f['only_status'] ?? 'any');
    if (isset(TI_MM_STATUSES[$os])) { $sql .= " AND s.status=?"; $par[] = $os; }
    return db_all($sql . " ORDER BY s.lesson_date, s.time_from", $par);
}

/** Zmiany z żądania: tylko pola z zaznaczonym chg_<pole>. @return array<string,mixed> */
function ti_mm_parse_changes(array $in, bool $is_staff): array {
    $ch = [];
    foreach (TI_MM_FIELDS as $f) {
        if (empty($in['chg_' . $f])) continue;
        $v = $in['val_' . $f] ?? '';
        $v = is_string($v) ? trim($v) : $v;
        switch ($f) {
            case 'status':           if (isset(TI_MM_STATUSES[$v])) $ch[$f] = $v; break;
            case 'lesson_method':    if (isset(TI_MM_METHODS[$v])) $ch[$f] = $v; break;
            case 'meeting_url':      $ch[$f] = (string)$v; break;
            case 'room_id':          $ch[$f] = max(0, (int)$v); break;
            case 'time_from': case 'time_to': if (preg_match('/^\d{2}:\d{2}$/', (string)$v)) $ch[$f] = $v; break;
            case 'shift_days':       $ch[$f] = max(-365, min(365, (int)$v)); break;
            case 'topic':            $ch[$f] = mb_substr((string)$v, 0, 300); break;
            case 'notes':            $ch[$f] = mb_substr((string)$v, 0, 2000); break;
            case 'has_homework': case 'self_prep_remote': $ch[$f] = (int)!empty($v); break;
            case 'date_flag':        if (isset(K30_TI_DATE_FLAGS[$v])) $ch[$f] = $v; break;
            case 'instructor_id':    if ($is_staff) $ch[$f] = max(0, (int)$v); break;
        }
    }
    return $ch;
}

/** Wartości lekcji po zmianach (bez zapisu). */
function ti_mm_new_values(array $s, array $ch): array {
    $n = $s;
    foreach (['status', 'lesson_method', 'topic', 'notes', 'date_flag', 'has_homework', 'self_prep_remote'] as $k) if (array_key_exists($k, $ch)) $n[$k] = $ch[$k];
    $online = fn($m) => in_array($m, ['zdalna_zoom', 'zdalna_inne'], true);
    if (array_key_exists('lesson_method', $ch) && !$online($ch['lesson_method'])) $n['meeting_url'] = '';
    if (array_key_exists('meeting_url', $ch) && $online((string)$n['lesson_method'])) $n['meeting_url'] = $ch['meeting_url'];
    if (array_key_exists('room_id', $ch)) $n['room_id'] = $ch['room_id'] ?: null;
    if (!empty($ch['time_from'])) $n['time_from'] = $ch['time_from'];
    if (!empty($ch['time_to']))   $n['time_to']   = $ch['time_to'];
    if (!empty($ch['shift_days']) && (string)$n['lesson_date'] !== '') $n['lesson_date'] = date('Y-m-d', strtotime($n['lesson_date'] . ' ' . ($ch['shift_days'] > 0 ? '+' : '') . $ch['shift_days'] . ' days'));
    if (array_key_exists('instructor_id', $ch)) $n['instructor_id'] = $ch['instructor_id'] ?: null;
    $a = strtotime('2000-01-01 ' . $n['time_from']); $b = strtotime('2000-01-01 ' . $n['time_to']);
    $n['duration_min'] = ($a && $b && $b > $a) ? (int)(($b - $a) / 60) : (int)$n['duration_min'];
    return $n;
}

/** Kolizje sali / prowadzącego z innymi, niezatwierdzonymi-jako-robocze lekcjami. @return string[] */
function ti_appr_collisions(int $course_id, array $s): array {
    $room = (int)($s['room_id'] ?? 0);
    $instr = (int)($s['instructor_id'] ?? 0) ?: (int)(db_one("SELECT instructor_id FROM k30_ti_courses WHERE id=?", [$course_id])['instructor_id'] ?? 0);
    $cond = []; $par = [(int)$s['id'], (string)$s['lesson_date'], (string)$s['time_to'], (string)$s['time_from']];
    if ($room)  { $cond[] = "s2.room_id=?"; $par[] = $room; }
    if ($instr) { $cond[] = "COALESCE(s2.instructor_id, c.instructor_id)=?"; $par[] = $instr; }
    if (!$cond) return [];
    $out = [];
    foreach (db_all("SELECT s2.time_from, c.name AS course_name FROM k30_ti_sessions s2 JOIN k30_ti_courses c ON c.id=s2.course_id
                     WHERE s2.id<>? AND s2.lesson_date=? AND s2.status NOT IN ('draft','cancelled') AND s2.time_from < ? AND s2.time_to > ?
                       AND COALESCE(c.status,'active')!='archived'
                       AND (" . implode(' OR ', $cond) . ")", $par) as $k) {
        $out[] = 'Kolizja terminu ' . date('d.m.Y', strtotime((string)$s['lesson_date'])) . ' ' . substr((string)$s['time_from'], 0, 5)
               . ' z lekcją „' . $k['course_name'] . '” o ' . substr((string)$k['time_from'], 0, 5) . ' (ta sama sala lub prowadzący).';
    }
    return $out;
}

/** @return array{blockers:string[],warnings:string[],items:string[]} */
function ti_mm_check(int $course_id, array $sessions, array $ch, bool $is_staff): array {
    $b = []; $w = []; $items = [];
    if (!$ch) $b[] = 'Zaznacz przynajmniej jedno pole do zmiany.';
    if (!$sessions) $b[] = 'Żadna lekcja nie pasuje do wybranych kryteriów.';
    $sched = dirname(dirname(__DIR__)) . '/ti_scheduler/logic/schedulerValidator.php';
    if (!$is_staff && is_file($sched)) {
        require_once $sched;
        if (function_exists('ti_course_is_hybrid') && ti_course_is_hybrid($course_id))
            $b[] = 'To grupa hybrydowa — terminy i formę zajęć (od nich zależy rozliczenie) zmienia kierownik.';
    }
    if (isset($ch['meeting_url']) && $ch['meeting_url'] !== '' && !preg_match('~^https?://~i', $ch['meeting_url'])) $b[] = 'Link do spotkania musi zaczynać się od http:// lub https://.';
    if ((isset($ch['time_from']) xor isset($ch['time_to'])) === false && isset($ch['time_from']) && $ch['time_to'] <= $ch['time_from']) $b[] = 'Godzina końca musi być późniejsza niż początku.';
    require_once dirname(__DIR__, 3) . '/includes/ti_periods.php';
    $course = db_one("SELECT * FROM k30_ti_courses WHERE id=?", [$course_id]) ?: [];
    $today = date('Y-m-d');
    foreach ($sessions as $s) {
        $n = ti_mm_new_values($s, $ch);
        $dateless = (string)$n['lesson_date'] === '';
        $lbl = $s['lesson_date'] !== '' ? date('d.m.Y', strtotime((string)$s['lesson_date'])) . ' ' . substr((string)$s['time_from'], 0, 5) : 'szkic bez terminu';
        $dn  = $dateless ? 'szkic bez terminu' : date('d.m.Y', strtotime((string)$n['lesson_date'])) . ' ' . substr((string)$n['time_from'], 0, 5) . '–' . substr((string)$n['time_to'], 0, 5);
        $items[] = $lbl . (!$dateless && ($lbl !== substr($dn, 0, strlen($lbl)) || (string)$n['time_to'] !== (string)$s['time_to']) ? ' → ' . $dn : '')
                 . ' — ' . (trim((string)$n['topic']) !== '' ? $n['topic'] : 'bez tematu');
        if ((string)$n['time_from'] !== '' && (string)$n['time_to'] !== '' && (string)$n['time_to'] <= (string)$n['time_from']) $b[] = "Termin $lbl: godzina końca musi być późniejsza niż początku po zmianie.";
        if ($dateless && (string)$n['status'] !== 'draft') $b[] = "Termin ({$lbl}) nie ma daty — uzupełnij ją (przesunięcie dat jej nie ustawia) albo zostaw typ „Wersja robocza”.";
        if ($dateless) {
            if (ti_appr_is_online($n) && ti_appr_meeting_url($n, $course) === '') $w[] = "Szkic „{$n['topic']}” jest online, ale nie ma linku do spotkania.";
            continue;
        }
        if (function_exists('ti_period_closed_for_date')) {
            if (($s['lesson_date'] !== '' && ti_period_closed_for_date((string)$s['lesson_date'])) || ti_period_closed_for_date((string)$n['lesson_date']))
                $b[] = "Termin $lbl lub jego nowa data leży w zamkniętym okresie rozliczeniowym.";
        }
        if ((string)$n['lesson_date'] < $today) $w[] = "Termin $lbl po zmianie wypada w przeszłości ($dn).";
        if ((string)$n['status'] === 'planned') {
            foreach (ti_appr_collisions($course_id, $n) as $c) $w[] = $c;
            if ((string)$n['lesson_method'] === 'zdalna_zoom' && function_exists('ti_zoom_slot_check')) {
                $z = ti_zoom_slot_check($course_id, 'zdalna_zoom', (string)$n['lesson_date'], (string)$n['time_from'], (string)$n['time_to'], (int)$s['id']);
                if (!$z['ok']) $w[] = $z['reason'];
            }
        }
        if (ti_appr_is_online($n) && ti_appr_meeting_url($n, $course) === '') $w[] = "Termin $dn jest online, ale nie ma linku do spotkania.";
        if ((string)$n['lesson_method'] === 'stacjonarna' && !(int)($n['room_id'] ?? 0)) $w[] = "Termin $dn jest stacjonarny, ale nie ma przypisanej sali.";
    }
    return ['blockers' => array_values(array_unique($b)), 'warnings' => array_values(array_unique($w)), 'items' => $items];
}

/** Opis zmian po polsku (do podsumowania w popupie). */
function ti_mm_describe(array $ch): string {
    $rooms = [];
    $d = [];
    $map = ['status' => fn($v) => 'typ terminu: ' . (TI_MM_STATUSES[$v] ?? $v),
            'lesson_method' => fn($v) => 'forma: ' . (TI_MM_METHODS[$v] ?: 'brak'),
            'meeting_url' => fn($v) => 'link: ' . ($v ?: 'wyczyść'),
            'room_id' => fn($v) => 'sala: ' . ($v ? ((db_one("SELECT name FROM k30_pl_rooms WHERE id=?", [$v])['name'] ?? '#' . $v)) : 'brak'),
            'time_from' => fn($v) => "początek: $v", 'time_to' => fn($v) => "koniec: $v",
            'shift_days' => fn($v) => 'przesunięcie o ' . $v . ' dni', 'topic' => fn($v) => 'temat: ' . ($v ?: 'wyczyść'),
            'notes' => fn($v) => 'notatki: ' . ($v ?: 'wyczyść'), 'has_homework' => fn($v) => 'zadanie domowe: ' . ($v ? 'tak' : 'nie'),
            'self_prep_remote' => fn($v) => 'praca własna zdalnie: ' . ($v ? 'tak' : 'nie'),
            'date_flag' => fn($v) => 'pewność terminu: ' . (K30_TI_DATE_FLAGS[$v]['label'] ?? $v),
            'instructor_id' => fn($v) => 'prowadzący: ' . ($v ? ((db_one("SELECT name FROM users WHERE id=?", [$v])['name'] ?? '#' . $v)) : 'domyślny kursu')];
    foreach ($ch as $k => $v) if (isset($map[$k])) $d[] = $map[$k]($v);
    return implode('; ', $d);
}

/** Zapisuje zmiany. Mail: lekcje, które właśnie stały się widoczne → „nowe zajęcia"; widoczne zmienione → „zmiana zajęć". */
function ti_mm_apply(int $course_id, array $ids, array $ch, bool $send_mail, bool $is_staff): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $sessions = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sessions = db_all("SELECT s.*, r.name AS room_name FROM k30_ti_sessions s LEFT JOIN k30_pl_rooms r ON r.id=s.room_id
            WHERE s.course_id=? AND s.draft_id IS NULL AND s.status IN ('planned','reserved','draft') AND s.id IN ($ph) ORDER BY s.lesson_date, s.time_from",
            array_merge([$course_id], $ids));
    }
    $chk = ti_mm_check($course_id, $sessions, $ch, $is_staff);
    if ($chk['blockers']) throw new \RuntimeException(implode(' ', $chk['blockers']));
    $cols = ['status', 'lesson_method', 'meeting_url', 'room_id', 'time_from', 'time_to', 'duration_min', 'lesson_date', 'topic', 'notes', 'date_flag', 'has_homework', 'self_prep_remote', 'instructor_id'];
    $new_vis = []; $chg_vis = [];
    foreach ($sessions as $s) {
        $n = ti_mm_new_values($s, $ch);
        $sets = []; $par = [];
        foreach ($cols as $c) { $sets[] = "$c=?"; $par[] = $n[$c] ?? null; }
        $par[] = (int)$s['id'];
        db_exec("UPDATE k30_ti_sessions SET " . implode(',', $sets) . ", updated_at=datetime('now') WHERE id=?", $par);
        if (array_key_exists('room_id', $ch)) $n['room_name'] = $n['room_id'] ? (db_one("SELECT name FROM k30_pl_rooms WHERE id=?", [$n['room_id']])['name'] ?? null) : null;
        if ($n['status'] === 'planned') { if ($s['status'] !== 'planned') $new_vis[] = $n; else $chg_vis[] = $n; }
    }
    $mails = 0;
    if ($send_mail) {
        $course = db_one("SELECT * FROM k30_ti_courses WHERE id=?", [$course_id]) ?: ['name' => ''];
        $empty = ['files' => [], 'links' => []];
        if ($new_vis) $mails += ti_appr_send_mails($course_id, $course, $new_vis, ti_appr_materials(array_column($new_vis, 'id')), 'approve', 'ti_lesson_approval');
        if ($chg_vis) $mails += ti_appr_send_mails($course_id, $course, $chg_vis, $empty, 'method', 'ti_lesson_change');
    }
    return ['changed' => count($sessions), 'mails' => $mails];
}

/** Dla podglądu maila: zwraca [sesje do maila, rodzaj] po zmianach — albo [] gdy nic nie idzie do kursantów. */
function ti_mm_mail_preview_sessions(array $sessions, array $ch): array {
    $new_vis = []; $chg_vis = [];
    foreach ($sessions as $s) {
        $n = ti_mm_new_values($s, $ch);
        if (array_key_exists('room_id', $ch)) $n['room_name'] = $n['room_id'] ? (db_one("SELECT name FROM k30_pl_rooms WHERE id=?", [$n['room_id']])['name'] ?? null) : null;
        if ($n['status'] === 'planned') { if ($s['status'] !== 'planned') $new_vis[] = $n; else $chg_vis[] = $n; }
    }
    return $new_vis ? [$new_vis, 'approve'] : ($chg_vis ? [$chg_vis, 'method'] : [[], '']);
}


/* ── Szkice z planera cyklicznego (k30_ti_weekly_plan, status draft/approved) ── */

/** Szkice slotów prowadzącego (staff: wszystkie). */
function ti_slot_drafts(int $uid, bool $staff): array {
    return db_all("SELECT wp.*, c.name AS course_name, c.default_meeting_url, c.is_online FROM k30_ti_weekly_plan wp
                   JOIN k30_ti_courses c ON c.id=wp.course_id WHERE wp.status='draft'" . ($staff ? '' : ' AND wp.instructor_id=?') . "
                   ORDER BY c.name, (wp.day_of_week + 6) % 7, wp.time_from", $staff ? [] : [$uid]);
}

function ti_slot_get(array $ids, int $uid, bool $staff): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return db_all("SELECT wp.*, c.name AS course_name, c.default_meeting_url, c.is_online FROM k30_ti_weekly_plan wp
                   JOIN k30_ti_courses c ON c.id=wp.course_id WHERE wp.status='draft' AND wp.id IN ($ph)" . ($staff ? '' : ' AND wp.instructor_id=?') . "
                   ORDER BY c.name, (wp.day_of_week + 6) % 7, wp.time_from", $staff ? $ids : array_merge($ids, [$uid]));
}

function ti_slot_range(array $sl): string {
    $a = substr((string)$sl['time_from'], 0, 5);
    $b = date('H:i', strtotime('2000-01-01 ' . $a . ' +' . (int)$sl['duration_min'] . ' minutes'));
    return $a . '–' . $b;
}

function ti_slot_rows(array $slots): array {
    $rows = [];
    foreach ($slots as $sl) {
        $online = !empty($sl['is_online']) || trim((string)$sl['default_meeting_url']) !== '';
        $rows[] = [K30_TI_DAYS[(int)$sl['day_of_week']] ?? '', ti_slot_range($sl), (string)$sl['course_name'],
                   $online ? 'online' : 'stacjonarnie', $online ? (trim((string)$sl['default_meeting_url']) ?: '(link zostanie przesłany osobno)') : '—', (string)($sl['notes'] ?? '')];
    }
    return $rows;
}

const TI_SLOT_HEAD = ['Dzień tygodnia', 'Godziny', 'Kurs', 'Forma', 'Link', 'Uwagi'];

/** Mail o stałym terminie zajęć (jeden kurs). */
function ti_slot_compose(array $course, array $slots, array $sched_names, string $first_name = '{imię}'): array {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $cell = 'padding:4px 8px;border:1px solid #ccc';
    $rows = ''; $any_online = false;
    foreach (ti_slot_rows($slots) as $r) {
        $on = $r[3] === 'online'; $any_online = $any_online || $on;
        $rows .= '<tr><td style="' . $cell . '">' . $e($r[0]) . '</td><td style="' . $cell . '">' . $e($r[1]) . '</td><td style="' . $cell . '">'
              . ($on ? '<strong>ONLINE</strong><br>' . (str_starts_with($r[4], 'http') ? 'Link: <a href="' . $e($r[4]) . '">' . $e($r[4]) . '</a>' : $e($r[4])) : 'stacjonarnie') . '</td></tr>';
    }
    $subject = 'Stały harmonogram zajęć: ' . $course['name'] . ($any_online ? ' — online' : '');
    $th = $cell . ';text-align:left';
    $body = '<p>W kursie <strong>' . $e($course['name']) . '</strong> ustalono cykliczny termin zajęć (co tydzień):</p>'
          . ($any_online ? '<p><strong>Zajęcia odbywają się online.</strong> Link do spotkania jest przy terminie.</p>' : '')
          . '<table style="border-collapse:collapse"><thead><tr><th style="' . $th . '">Dzień</th><th style="' . $th . '">Godziny</th><th style="' . $th . '">Forma</th></tr></thead><tbody>' . $rows . '</tbody></table>'
          . ($sched_names ? '<p><strong>Harmonogram znajdziesz także w załączniku</strong> (' . $e(implode(', ', $sched_names)) . ').</p>' : '')
          . '<p>Szczegółowe daty zajęć zobaczysz w panelu kursanta.</p>';
    return ti_appr_wrap(defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta', $subject, $body, $first_name, $e);
}

/** Zatwierdza sloty; opcjonalnie mail do kursantów każdego kursu (harmonogram PDF+XLSX+TXT w załączniku). */
function ti_slot_approve(array $ids, bool $send_mail, int $uid, bool $staff): array {
    $slots = ti_slot_get($ids, $uid, $staff);
    foreach ($slots as $sl) db_exec("UPDATE k30_ti_weekly_plan SET status='approved', updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='draft'", [(int)$sl['id']]);
    $mails = 0;
    if ($send_mail && $slots) {
        if (!function_exists('mail_queue_add')) require_once dirname(dirname(dirname(__DIR__))) . '/includes/mail_queue.php';
        $by = [];
        foreach ($slots as $sl) $by[(int)$sl['course_id']][] = $sl;
        foreach ($by as $cid => $cs) {
            $course = db_one("SELECT * FROM k30_ti_courses WHERE id=?", [$cid]) ?: ['name' => ''];
            $files = ti_appr_files_from_rows($course, TI_SLOT_HEAD, ti_slot_rows($cs), 'Stały harmonogram zajęć');
            $att = array_map(fn($g) => ['path' => $g['path'], 'name' => $g['name'], 'mime' => $g['mime'], 'size' => $g['size']], $files);
            $names = array_map(fn($g) => $g['name'], $files);
            foreach (ti_appr_recipients($cid) as $rc) {
                $m = ti_slot_compose($course, $cs, $names, explode(' ', trim($rc['name']))[0] ?: $rc['name']);
                try { if (mail_queue_add($rc['email'], $rc['name'], $m['subject'], $m['html'], '', 'ti_weekly_slot_approval', $cid, '', true, $att)) $mails++; }
                catch (\Throwable $e) { error_log('[ti_slot] ' . $e->getMessage()); }
            }
        }
    }
    return ['approved' => count($slots), 'mails' => $mails];
}
