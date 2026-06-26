<?php
/**
 * Panel kursanta TI — dashboard: moje lekcje, rozliczenia, VLab.
 * UI: Bootstrap 5.3 (motyw ciemny) + WCAG 2.1 AA.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/vlab.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_messages.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_moodle.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/pfron.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpdesk.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_leaves.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_terms.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
pfron_migrate();
helpdesk_migrate();

// Zakończenie podglądu administratora („zaloguj jako") — wróć do listy kont kursantów.
if (isset($_GET['stop_impersonation'])) {
    $was_imp = student_impersonator() !== null;
    student_logout();
    header('Location: ' . ($was_imp ? rtrim(APP_URL, '/') . '/karty30/ti/kursant/accounts.php' : 'login.php'));
    exit;
}

// Wylogowanie (przed jakimkolwiek wyjściem)
if (isset($_GET['logout'])) { student_logout(); header('Location: login.php'); exit; }

$student    = student_require();

// Eksport PDF wykazu ocen kursanta
if (isset($_GET['grades_pdf'])) {
    $cl = db_one("SELECT name FROM k30_clients WHERE id=?", [$student['client_id']]);
    require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_grades_pdf.php';
    ti_grades_pdf_student((int)$student['client_id'], $cl['name'] ?? '');
}
$tab        = $_GET['tab'] ?? 'dane';
$vlab_token = student_token();

// Dane kursanta
$account = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$student['id']]);
// Konto usunięte/zablokowane w trakcie sesji → wyloguj
if (!$account || empty($account['is_active'])) { student_logout(); header('Location: login.php'); exit; }
$client  = db_one("SELECT * FROM k30_clients WHERE id=?", [$student['client_id']]) ?: [];

// ── Odwołanie / przywrócenie udziału w lekcji przez Beneficjenta ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op  = $_POST['_op'] ?? '';
    $tok = $_POST['_token'] ?? '';
    if (!hash_equals(student_token(), (string)$tok)) { http_response_code(403); exit('Nieprawidłowy token sesji.'); }

    // Akceptacja regulaminu TI
    if ($op === 'accept_term') {
        $term_id = (int)($_POST['term_id'] ?? 0);
        if ($term_id > 0) {
            ti_term_accept((int)$student['client_id'], $term_id, (int)$student['id']);
        }
        $redirect_tab = (string)($_POST['redirect_tab'] ?? 'regulaminy');
        header('Location: index.php?tab=' . urlencode($redirect_tab) . '&accepted=1'); exit;
    }

    if ($op === 'cancel_lesson' || $op === 'uncancel_lesson') {
        $sid = (int)($_POST['session_id'] ?? 0);
        // Lekcja musi należeć do kursu, do którego kursant jest aktywnie zapisany, i być zaplanowana
        $own = db_one(
            "SELECT s.id, s.status FROM k30_ti_sessions s
             JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=? AND e.status='active'
             WHERE s.id=?",
            [$student['client_id'], $sid]
        );
        $cancel_flag = '';
        if ($own && $own['status'] === 'planned') {
            if ($op === 'cancel_lesson') {
                $reason = trim($_POST['reason'] ?? '');
                // Prośba o odwołanie — czeka na potwierdzenie prowadzącego (mail do niego)
                k30_ti_request_cancel_attendance(
                    $sid, $student['client_id'],
                    $reason !== '' ? $reason : 'Odwołane przez beneficjenta',
                    'beneficjent', $client['name'] ?? ''
                );
                $cancel_flag = 'requested';
            } else {
                k30_ti_uncancel_attendance($sid, $student['client_id']);
                $cancel_flag = 'withdrawn';
            }
        }
        header('Location: index.php?tab=lekcje' . ($cancel_flag ? '&cancel=' . $cancel_flag : '')); exit;
    }

    // Ocena odbytej lekcji (1–5) przez kursanta
    if ($op === 'rate_lesson') {
        $sid    = (int)($_POST['session_id'] ?? 0);
        $rating = (int)($_POST['rating'] ?? 0);
        $cmt    = (string)($_POST['comment'] ?? '');
        if ($sid && $rating >= 1 && $rating <= 5) {
            if (k30_ti_rate_lesson($sid, (int)$student['client_id'], $rating, $cmt)) {
                header('Location: index.php?tab=lekcje&rated=1'); exit;
            }
        }
        header('Location: index.php?tab=lekcje'); exit;
    }

    // Oddanie zadania domowego (treść + opcjonalny plik)
    if ($op === 'submit_homework') {
        $hwid = (int)($_POST['homework_id'] ?? 0);
        $hw = $hwid ? db_one(
            "SELECT h.* FROM k30_ti_homework h
             WHERE h.id=? AND h.is_active=1
               AND h.course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')",
            [$hwid, $student['client_id']]
        ) : null;
        if (!$hw) { $_SESSION['hw_flash'] = ['err', 'Nie znaleziono zadania.']; header('Location: index.php?tab=zadania'); exit; }
        // Okno dostępności (otwarcie/zamknięcie) ustawione przez prowadzącego
        $av = k30_ti_avail_status($hw['open_at'] ?? null, $hw['close_at'] ?? null);
        if ($av['state'] === 'upcoming') { $_SESSION['hw_flash'] = ['err', 'To zadanie jest jeszcze niedostępne (' . $av['label'] . ').']; header('Location: index.php?tab=zadania'); exit; }
        if ($av['state'] === 'closed')   { $_SESSION['hw_flash'] = ['err', 'Termin oddania tego zadania został zamknięty (' . $av['label'] . ').']; header('Location: index.php?tab=zadania'); exit; }

        $body = trim((string)($_POST['body'] ?? ''));
        try { $up = k30_ti_homework_upload('file', 'sub' . (int)$student['client_id']); }
        catch (\Throwable $e) { $_SESSION['hw_flash'] = ['err', $e->getMessage()]; header('Location: index.php?tab=zadania'); exit; }

        $existing = db_one("SELECT * FROM k30_ti_homework_submissions WHERE homework_id=? AND client_id=?",
                           [$hwid, (int)$student['client_id']]);
        if ($existing) {
            $sets = ['body' => mb_substr($body, 0, 5000), 'status' => 'submitted',
                     'submitted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
            if ($up) {
                if ($existing['file_path'] !== '') k30_ti_homework_delete_file($existing['file_path']);
                $sets['file_name'] = $up['name']; $sets['file_path'] = $up['stored'];
            }
            $cols=[];$p=[]; foreach ($sets as $k=>$v){$cols[]="$k=?";$p[]=$v;} $p[]=(int)$existing['id'];
            db()->prepare("UPDATE k30_ti_homework_submissions SET ".implode(',',$cols)." WHERE id=?")->execute($p);
        } else {
            db_insert('k30_ti_homework_submissions', [
                'homework_id' => $hwid, 'client_id' => (int)$student['client_id'],
                'body' => mb_substr($body, 0, 5000),
                'file_name' => $up['name'] ?? '', 'file_path' => $up['stored'] ?? '',
                'status' => 'submitted',
            ]);
        }
        $_SESSION['hw_flash'] = ['ok', 'Zadanie zostało oddane.'];
        header('Location: index.php?tab=zadania'); exit;
    }

    // PFRON — 2FA (nr telefonu + nr umowy) odblokowuje sekcję na czas sesji
    if ($op === 'pfron_auth') {
        if (pfron_is_locked()) {
            $_SESSION['pfron_msg'] = ['err', 'Zbyt wiele prób. Spróbuj ponownie za kilka minut.'];
        } else {
            $cid = pfron_authenticate((string)($_POST['contract_no'] ?? ''), (string)($_POST['phone'] ?? ''));
            if ($cid > 0) {
                pfron_unlock($cid);
                pfron_log($cid, 'ok');
                $_SESSION['pfron_msg'] = ['ok', 'Dostęp do danych PFRON odblokowany.'];
            } else {
                pfron_register_fail();
                pfron_log(0, 'fail');
                $_SESSION['pfron_msg'] = ['err', 'Nieprawidłowy numer umowy lub telefon.'];
            }
        }
        header('Location: index.php?tab=pfron'); exit;
    }
    if ($op === 'pfron_lock') {
        pfron_lock_all();
        header('Location: index.php?tab=pfron'); exit;
    }

    if ($op === 'reset_calendar_token') {
        k30_ti_calendar_token_reset((int)$student['id']);
        header('Location: index.php?tab=ustawienia&cal=reset'); exit;
    }

    if ($op === 'toggle_sms_lessons') {
        $on = !empty($_POST['enabled']) ? 1 : 0;
        db_update('k30_ti_student_accounts', ['notify_sms_lessons' => $on], (int)$student['id']);
        header('Location: index.php?tab=ustawienia&sms=' . ($on ? 'on' : 'off')); exit;
    }

    // Zapis ustawień powiadomień o wiadomościach (e-mail / SMS)
    if ($op === 'msg_prefs') {
        db_update('k30_ti_student_accounts', [
            'notify_email_messages' => !empty($_POST['email']) ? 1 : 0,
            'notify_sms_messages'   => !empty($_POST['sms'])   ? 1 : 0,
        ], (int)$student['id']);
        header('Location: index.php?tab=ustawienia&prefs=1'); exit;
    }

    // Zapis ustawień powiadomień o zmianach w dydaktyce/eLearningu (e-mail / SMS)
    if ($op === 'dyd_prefs') {
        db_update('k30_ti_student_accounts', [
            'notify_email_dydaktyka' => !empty($_POST['email']) ? 1 : 0,
            'notify_sms_dydaktyka'   => !empty($_POST['sms'])   ? 1 : 0,
        ], (int)$student['id']);
        header('Location: index.php?tab=ustawienia&dyd=1'); exit;
    }

    // Odpowiedź kursanta w wątku wiadomości
    if ($op === 'msg_reply') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body !== '') {
            ti_msg_student_reply((int)$student['id'], mb_substr($body, 0, 4000));
            header('Location: index.php?tab=wiadomosci&sent=1'); exit;
        }
        header('Location: index.php?tab=wiadomosci'); exit;
    }

    // Zapis dodatkowych numerów telefonu do powiadomień SMS
    if ($op === 'notify_phones') {
        db_update('k30_ti_student_accounts', [
            'notify_phone2' => mb_substr(trim((string)($_POST['phone2'] ?? '')), 0, 30),
            'notify_phone3' => mb_substr(trim((string)($_POST['phone3'] ?? '')), 0, 30),
        ], (int)$student['id']);
        header('Location: index.php?tab=ustawienia&phones=1'); exit;
    }

    // Zmiana hasła do panelu (samoobsługa oraz wymuszona po nadaniu hasła przez admina)
    if ($op === 'change_password') {
        $cur = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $cnf = (string)($_POST['confirm'] ?? '');
        $acc = db_one("SELECT password_hash FROM k30_ti_student_accounts WHERE id=?", [(int)$student['id']]);
        $forced = !empty($account['must_change_password']);
        $err = '';
        if (!$acc || !password_verify($cur, $acc['password_hash'])) {
            $err = 'Aktualne hasło jest nieprawidłowe.';
        } elseif (mb_strlen($new) < 8) {
            $err = 'Nowe hasło musi mieć co najmniej 8 znaków.';
        } elseif ($new !== $cnf) {
            $err = 'Nowe hasła nie są identyczne.';
        } elseif ($new === $cur) {
            $err = 'Nowe hasło musi różnić się od dotychczasowego.';
        }
        if ($err !== '') {
            header('Location: index.php?tab=ustawienia' . ($forced ? '&force_pw=1' : '') . '&pwerr=' . rawurlencode($err)); exit;
        }
        db()->prepare("UPDATE k30_ti_student_accounts SET password_hash=?, must_change_password=0, updated_at=datetime('now') WHERE id=?")
           ->execute([password_hash($new, PASSWORD_BCRYPT), (int)$student['id']]);
        header('Location: index.php?tab=ustawienia&pwok=1'); exit;
    }

    // Ustawienie / zmiana aliasu logowania przez kursanta
    if ($op === 'set_alias') {
        $alias = mb_strtolower(trim((string)($_POST['alias'] ?? '')));
        $err = '';
        if ($alias === '') {
            // Usunięcie aliasu
            db()->prepare("UPDATE k30_ti_student_accounts SET login_alias='', updated_at=datetime('now') WHERE id=?")
               ->execute([(int)$student['id']]);
            header('Location: index.php?tab=ustawienia&alias=removed'); exit;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,28}[a-z0-9]$/', $alias)) {
            $err = 'Alias może zawierać tylko litery a–z, cyfry, kropki, myślniki i podkreślenia (3–30 znaków, bez spacji).';
        } elseif (db_one("SELECT id FROM k30_ti_student_accounts WHERE login=? AND id!=?", [$alias, (int)$student['id']])) {
            $err = 'Ta nazwa jest już zajęta jako login innego kursanta.';
        } elseif (db_one("SELECT id FROM k30_ti_student_accounts WHERE login_alias=? AND id!=?", [$alias, (int)$student['id']])) {
            $err = 'Ten alias jest już zajęty przez kogoś innego.';
        }
        if ($err !== '') {
            header('Location: index.php?tab=ustawienia&aliaserr=' . rawurlencode($err)); exit;
        }
        db()->prepare("UPDATE k30_ti_student_accounts SET login_alias=?, updated_at=datetime('now') WHERE id=?")
           ->execute([$alias, (int)$student['id']]);
        header('Location: index.php?tab=ustawienia&alias=ok'); exit;
    }

    // Zgłoszenie problemu technicznego → ticket helpdesk z prefiksem KUR
    if ($op === 'report_issue') {
        $title = trim((string)($_POST['title'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $cat   = (string)($_POST['category'] ?? 'it_inne');
        if (!isset(HD_CATEGORIES[$cat])) $cat = 'it_inne';
        if ($title === '' || mb_strlen($desc) < 5) {
            header('Location: index.php?tab=problem&err=1'); exit;
        }
        $rname  = trim((string)($client['name'] ?? '')) ?: (string)($account['login'] ?? 'Kursant');
        $remail = trim((string)($client['email'] ?? ''));
        $rphone = (!empty($account['is_minor']) && !empty($account['guardian_phone']))
                ? (string)$account['guardian_phone'] : trim((string)($client['phone'] ?? ''));

        $number    = hd_next_number('KUR');
        $ticket_id = db_insert('helpdesk_tickets', [
            'number'          => $number,
            'title'           => mb_substr($title, 0, 200),
            'description'     => mb_substr($desc, 0, 5000),
            'category'        => $cat,
            'priority'        => 'normalny',
            'status'          => 'nowe',
            'requester_id'    => null,
            'requester_name'  => $rname,
            'requester_email' => $remail !== '' ? $remail : null,
            'requester_phone' => $rphone !== '' ? $rphone : null,
            'source'          => 'panel_kursanta',
        ]);
        db_insert('helpdesk_messages', [
            'ticket_id' => $ticket_id, 'user_id' => null,
            'user_name' => $rname, 'body' => mb_substr($desc, 0, 5000), 'is_internal' => 0,
        ]);
        // Notatka wewnętrzna z kontekstem kursanta (tylko dla operatorów)
        db_insert('helpdesk_messages', [
            'ticket_id' => $ticket_id, 'user_id' => null, 'user_name' => 'System',
            'body' => 'Zgłoszenie z panelu kursanta TI. Login: ' . (string)($account['login'] ?? '—')
                    . (!empty($account['student_no']) ? ', nr kursanta: ' . $account['student_no'] : '') . '.',
            'is_internal' => 1,
        ]);
        // Powiadom operatorów helpdesku
        try {
            require_once dirname(dirname(dirname(__DIR__))) . '/includes/mail_queue.php';
            $ops = db_all("SELECT email, name FROM users WHERE helpdesk_operator=1 AND is_active=1 AND email IS NOT NULL");
            $url_op = rtrim(APP_URL, '/') . '/helpdesk/view.php?id=' . $ticket_id;
            foreach ($ops as $opx) {
                if (empty($opx['email'])) continue;
                mail_queue_add($opx['email'], $opx['name'] ?? '', "[{$number}] Problem techniczny (kursant): " . $rname,
                    '<p><strong style="font-family:monospace">' . h($number) . '</strong> — ' . h($title) . '</p>'
                    . '<p>' . nl2br(h($desc)) . '</p>'
                    . '<p><a href="' . h($url_op) . '">Otwórz zgłoszenie →</a></p>');
            }
        } catch (\Throwable $e) {}
        header('Location: index.php?tab=problem&sent=1&num=' . rawurlencode($number)); exit;
    }
}

// Kursy i lekcje kursanta
$courses = k30_ti_client_courses($student['client_id']);

// Następna zaplanowana lekcja (do widgetu na dashboardzie)
$next_lesson = db_one(
    "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.topic, c.name AS course_name
     FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id=s.course_id
     JOIN k30_ti_enrollments e ON e.course_id=c.id AND e.client_id=? AND e.status='active'
     WHERE s.lesson_date >= date('now') AND (s.status IS NULL OR s.status NOT IN ('cancelled','removed'))
     ORDER BY s.lesson_date, s.time_from LIMIT 1",
    [$student['client_id']]
);
$homeworks_student = k30_ti_homework_for_client($student['client_id']);
$hw_pending = array_values(array_filter($homeworks_student, fn($h) => empty($h['sub_id'])));
$materials_student = k30_ti_materials_for_client($student['client_id']);
// Oceny (e-dziennik) — pogrupowane wg kursu, ze średnią ważoną
// Respektuj wyłączenie ocen: globalnie dla osoby oraz per kurs.
$grades_student = k30_ti_client_grades($student['client_id']);
if (!k30_ti_client_grades_enabled((int)$student['client_id'])) {
    $grades_student = [];
} else {
    $grades_student = array_values(array_filter($grades_student, fn($g) => k30_ti_course_grades_enabled((int)$g['course_id'])));
}
$grades_by_course = [];
foreach ($grades_student as $g) { $grades_by_course[$g['course_name']][] = $g; }

// Dydaktyka pogrupowana wg lekcji (materiały + zadania); klucz 0 = bez przypisanej lekcji
$dyd_groups = [];
$dyd_add = function(array $item, bool $isHw) use (&$dyd_groups) {
    $sid = (int)($item['session_id'] ?? 0);
    if (!isset($dyd_groups[$sid])) {
        $dyd_groups[$sid] = ['session_id'=>$sid, 'course_name'=>$item['course_name'] ?? '',
            'date'=>$item['session_date'] ?? '', 'topic'=>$item['session_topic'] ?? '',
            'materials'=>[], 'homeworks'=>[]];
    }
    $dyd_groups[$sid][$isHw ? 'homeworks' : 'materials'][] = $item;
};
foreach ($materials_student as $m) { $dyd_add($m, false); }
foreach ($homeworks_student as $h) { $dyd_add($h, true); }
uasort($dyd_groups, function($a, $b) {
    if ($a['session_id'] === 0) return 1;   // grupa „bez lekcji" na końcu
    if ($b['session_id'] === 0) return -1;
    return strcmp((string)$b['date'], (string)$a['date']); // lekcje od najnowszej
});
$moodle_courses_student = ti_moodle_courses_for_client($student['client_id']);

// Zadania z Moodle — odśwież z serwera tylko przy wejściu na zakładkę (TTL wewnątrz);
// listę czytamy z cache (tanio) na potrzeby licznika na innych zakładkach.
if ($tab === 'zadania') {
    ti_moodle_sync_client_assignments($student['client_id']);
}
$moodle_assignments = ti_moodle_assignments_for_client($student['client_id']);
$moodle_hw_pending  = array_values(array_filter($moodle_assignments,
    fn($a) => ($a['sub_status'] ?? '') !== 'submitted' && empty($a['sub_graded'])));
$hw_pending_total   = count($hw_pending) + count($moodle_hw_pending);

$lessons = k30_ti_client_lessons($student['client_id'], 40);
// Do diagnozy pustej listy lekcji: czy kursant ma aktywny zapis na jakikolwiek kurs?
$active_enroll_count = (int)(db_one(
    "SELECT COUNT(*) n FROM k30_ti_enrollments WHERE client_id=? AND status='active'",
    [$student['client_id']]
)['n'] ?? 0);
$my_licenses = k30_ti_client_licenses($student['client_id']);
$active_lesson = k30_ti_active_lesson_link($student['client_id']);

// Regulaminy TI — oczekujące akceptacje + historia
$terms_pending  = ti_terms_pending((int)$student['client_id']);
$terms_accepted = ti_terms_accepts_for_client((int)$student['client_id']);
$vlab_term_ok   = ti_term_accepted((int)$student['client_id'], 'vlab');
$online_term_ok = ti_term_accepted((int)$student['client_id'], 'szkolenia');

// Urlopy / nieobecności prowadzących kursanta (trwające + nadchodzące 30 dni)
$instructor_leaves = ti_leaves_for_client((int)$student['client_id'], 30);

// Statystyki
$total_lessons  = count($lessons);
$attended_count = count(array_filter($lessons, fn($l) => $l['attended']));
$pct = $total_lessons > 0 ? round($attended_count / $total_lessons * 100) : 0;

// Zadania domowe — lekcje odbyte z oznaczonym zadaniem (do bloku na stronie głównej)
$homework_lessons = array_values(array_filter($lessons, fn($l) =>
    !empty($l['has_homework'])
    && ($l['status'] ?? '') === 'held'
    && (int)($l['att_cancelled'] ?? 0) !== 1
    && empty($l['self_prep_remote'])));

// Powiadomienia SMS o zajęciach — zgoda beneficjenta (opt-in)
$sms_pref       = (int)($account['notify_sms_lessons'] ?? 0);
$sms_phone      = trim((string)($client['phone'] ?? ''));
$sms_global_on  = function_exists('sms_is_enabled') && sms_is_enabled();

// Wiadomości — licznik nieprzeczytanych + ustawienia powiadomień
$msg_unread     = ti_msg_unread_for_student((int)$student['id']);
$msg_pref_email = (int)($account['notify_email_messages'] ?? 1);
$msg_pref_sms   = (int)($account['notify_sms_messages'] ?? 0);
$dyd_pref_email = (int)($account['notify_email_dydaktyka'] ?? 1);
$dyd_pref_sms   = (int)($account['notify_sms_dydaktyka'] ?? 0);
$msg_email_addr = trim((string)($client['email'] ?? ''));

// Prywatny kanał iCal lekcji (subskrypcja w Kalendarzu Google / Apple / Outlook)
$cal_token  = k30_ti_calendar_token((int)$account['id']);
$cal_https  = rtrim(APP_URL, '/') . '/karty30/ti/kursant/ical.php?id=' . (int)$account['id'] . '&t=' . $cal_token;
$cal_webcal = preg_replace('#^https?://#i', 'webcal://', $cal_https);
$cal_gcal   = 'https://calendar.google.com/calendar/r?cid=' . rawurlencode($cal_webcal);

$org        = defined('ORG_NAME') ? ORG_NAME : 'Zajęcia TI';
$is_minor   = !empty($account['is_minor']);
$months_pl  = [1=>'Sty',2=>'Lut',3=>'Mar',4=>'Kwi',5=>'Maj',6=>'Cze',
               7=>'Lip',8=>'Sie',9=>'Wrz',10=>'Paź',11=>'Lis',12=>'Gru'];

$KP_TITLE  = 'Panel kursanta';
$KP_TOPBAR = [
    'brand'  => $org,
    'icon'   => 'pc-display',
    'user'   => $client['name'] ?? $account['login'],
    'logout' => 'index.php?logout=1',
];
include __DIR__ . '/_layout_head.php';
?>

<?php if (!empty($account['must_change_password'])):
  // Wymuszona zmiana hasła (np. po nadaniu/zresetowaniu hasła przez admina) — blokuje panel.
  $pwerr = (string)($_GET['pwerr'] ?? '');
?>
<main id="main" class="container-xl px-3 py-4" style="max-width:480px">
  <div class="card shadow-sm border-0">
    <div class="card-body p-4">
      <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-2"><i class="bi bi-shield-lock text-primary" aria-hidden="true"></i>Ustaw nowe hasło</h1>
      <p class="text-body-secondary small mb-3">Aby kontynuować, ustaw własne hasło do panelu. To wymagane po nadaniu hasła przez administratora.</p>
      <?php if ($pwerr !== ''): ?>
      <div class="alert alert-danger py-2 small" role="alert"><i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i><?= h($pwerr) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
        <input type="hidden" name="_op" value="change_password">
        <div class="mb-3">
          <label class="form-label" for="cp-cur">Aktualne hasło</label>
          <input type="password" class="form-control" id="cp-cur" name="current" required autocomplete="current-password" autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label" for="cp-new">Nowe hasło</label>
          <input type="password" class="form-control" id="cp-new" name="new" required minlength="8" autocomplete="new-password" placeholder="min. 8 znaków">
        </div>
        <div class="mb-3">
          <label class="form-label" for="cp-cnf">Powtórz nowe hasło</label>
          <input type="password" class="form-control" id="cp-cnf" name="confirm" required minlength="8" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-semibold"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz nowe hasło</button>
      </form>
      <p class="text-center mt-3 mb-0"><a href="index.php?logout=1" class="small text-body-secondary">Wyloguj się</a></p>
    </div>
  </div>
</main>
<?php include __DIR__ . '/_layout_foot.php'; exit; endif; ?>

<?php
  // Grupy menu — spłaszczone w dropdowny (Nauka / Dostępy / Pomoc)
  $nauka_tabs     = ['lekcje','zadania','oceny','plan','testy'];
  $dostepy_tabs   = ['online','vlab','licencje','pfron'];
  $pomoc_tabs     = ['problem','ustawienia'];
  $nauka_active   = in_array($tab, $nauka_tabs, true);
  $dostepy_active = in_array($tab, $dostepy_tabs, true);
  $pomoc_active   = in_array($tab, $pomoc_tabs, true);
?>
<nav class="container-xl px-3 pt-3" aria-label="Sekcje panelu">
  <ul class="nav nav-tabs">

    <li class="nav-item">
      <a class="nav-link <?= $tab==='dane'?'active':'' ?>" href="?tab=dane" <?= $tab==='dane'?'aria-current="page"':'' ?>>
        <i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Dane kursanta
      </a>
    </li>

    <!-- Nauka: lekcje, dydaktyka/eLearning, oceny -->
    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle <?= $nauka_active?'active':'' ?>" href="#" role="button"
         data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-mortarboard me-1" aria-hidden="true"></i>Nauka
        <?php if ($hw_pending_total > 0): ?><span class="badge text-bg-warning ms-1"><?= $hw_pending_total ?><span class="visually-hidden"> zadań do oddania</span></span><?php endif; ?>
      </a>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item <?= $tab==='lekcje'?'active':'' ?>" href="?tab=lekcje" <?= $tab==='lekcje'?'aria-current="page"':'' ?>>
          <i class="bi bi-calendar-check me-2" aria-hidden="true"></i>Moje lekcje</a></li>
        <li><a class="dropdown-item <?= $tab==='zadania'?'active':'' ?>" href="?tab=zadania" <?= $tab==='zadania'?'aria-current="page"':'' ?>>
          <i class="bi bi-journal-check me-2" aria-hidden="true"></i>Dydaktyka / eLearning
          <?php if ($hw_pending_total > 0): ?><span class="badge text-bg-warning ms-2"><?= $hw_pending_total ?></span><?php endif; ?></a></li>
        <li><a class="dropdown-item <?= $tab==='oceny'?'active':'' ?>" href="?tab=oceny" <?= $tab==='oceny'?'aria-current="page"':'' ?>>
          <i class="bi bi-table me-2" aria-hidden="true"></i>Oceny</a></li>
        <li><a class="dropdown-item <?= $tab==='plan'?'active':'' ?>" href="?tab=plan" <?= $tab==='plan'?'aria-current="page"':'' ?>>
          <i class="bi bi-list-check me-2" aria-hidden="true"></i>Plan nauczania</a></li>
        <li><a class="dropdown-item <?= $tab==='testy'?'active':'' ?>" href="?tab=testy" <?= $tab==='testy'?'aria-current="page"':'' ?>>
          <i class="bi bi-card-checklist me-2" aria-hidden="true"></i>Testy</a></li>
      </ul>
    </li>

    <li class="nav-item">
      <a class="nav-link <?= $tab==='wiadomosci'?'active':'' ?>" href="?tab=wiadomosci" <?= $tab==='wiadomosci'?'aria-current="page"':'' ?>>
        <i class="bi bi-envelope me-1" aria-hidden="true"></i>Wiadomości
        <?php if ($msg_unread > 0): ?><span class="badge text-bg-danger ms-1"><?= $msg_unread ?><span class="visually-hidden"> nieprzeczytanych</span></span><?php endif; ?>
      </a>
    </li>

    <?php if (!$is_minor): ?>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='rozliczenia'?'active':'' ?>" href="?tab=rozliczenia" <?= $tab==='rozliczenia'?'aria-current="page"':'' ?>>
        <i class="bi bi-receipt me-1" aria-hidden="true"></i>Rozliczenia
      </a>
    </li>
    <?php endif; ?>

    <!-- Dostępy i narzędzia -->
    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle <?= $dostepy_active?'active':'' ?>" href="#" role="button"
         data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-grid me-1" aria-hidden="true"></i>Dostępy
      </a>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item <?= $tab==='online'?'active':'' ?>" href="?tab=online" <?= $tab==='online'?'aria-current="page"':'' ?>>
          <i class="bi bi-camera-video me-2" aria-hidden="true"></i>Szkolenia online</a></li>
        <li><a class="dropdown-item <?= $tab==='vlab'?'active':'' ?>" href="?tab=vlab" <?= $tab==='vlab'?'aria-current="page"':'' ?>>
          <i class="bi bi-code-square me-2" aria-hidden="true"></i>VLab</a></li>
        <li><a class="dropdown-item <?= $tab==='licencje'?'active':'' ?>" href="?tab=licencje" <?= $tab==='licencje'?'aria-current="page"':'' ?>>
          <i class="bi bi-key me-2" aria-hidden="true"></i>Licencje
          <?php if (!empty($my_licenses)): ?><span class="badge text-bg-secondary ms-2"><?= count($my_licenses) ?></span><?php endif; ?></a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item <?= $tab==='pfron'?'active':'' ?>" href="?tab=pfron" <?= $tab==='pfron'?'aria-current="page"':'' ?>>
          <i class="bi bi-shield-lock me-2" aria-hidden="true"></i>PFRON (konsultacje)</a></li>
      </ul>
    </li>

    <!-- Pomoc -->
    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle <?= $pomoc_active?'active':'' ?>" href="#" role="button"
         data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-life-preserver me-1" aria-hidden="true"></i>Pomoc
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item <?= $tab==='problem'?'active':'' ?>" href="?tab=problem" <?= $tab==='problem'?'aria-current="page"':'' ?>>
          <i class="bi bi-wrench-adjustable me-2" aria-hidden="true"></i>Zgłoś problem techniczny</a></li>
        <li><a class="dropdown-item <?= $tab==='ustawienia'?'active':'' ?>" href="?tab=ustawienia" <?= $tab==='ustawienia'?'aria-current="page"':'' ?>>
          <i class="bi bi-gear me-2" aria-hidden="true"></i>Ustawienia</a></li>
      </ul>
    </li>

    <li class="nav-item">
      <a class="nav-link <?= $tab==='regulaminy'?'active':'' ?>" href="?tab=regulaminy" <?= $tab==='regulaminy'?'aria-current="page"':'' ?>>
        <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Regulaminy
        <?php if (!empty($terms_pending)): ?><span class="badge text-bg-danger ms-1"><?= count($terms_pending) ?><span class="visually-hidden"> do akceptacji</span></span><?php endif; ?>
      </a>
    </li>

  </ul>
</nav>

<main id="main" class="container-xl px-3 py-4">

<?php if ($active_lesson): ?>
<!-- ── Aktywny link do zajęć — widoczny od razu po wejściu do panelu ─────────── -->
<div class="alert alert-success d-flex align-items-center gap-3 shadow-sm mb-4" role="alert">
  <i class="bi bi-camera-video-fill fs-2 flex-shrink-0" aria-hidden="true"></i>
  <div class="flex-grow-1 min-width-0">
    <div class="fw-bold">Zajęcia online są dostępne — możesz dołączyć</div>
    <div class="small">
      <?= h($active_lesson['course_name']) ?>
      <?php if (!empty($active_lesson['time_from'])): ?>
      · <?= h($active_lesson['time_from']) ?><?= !empty($active_lesson['time_to']) ? '–'.h($active_lesson['time_to']) : '' ?>
      <?php endif; ?>
      <?php if (!empty($active_lesson['topic'])): ?> · <?= h($active_lesson['topic']) ?><?php endif; ?>
    </div>
  </div>
  <a href="<?= h($active_lesson['eff_link']) ?>" target="_blank" rel="noopener" class="btn btn-success btn-lg flex-shrink-0">
    <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Dołącz teraz
  </a>
</div>
<?php endif; ?>

<?php if ($hw_pending_total > 0):
  // Etykieta pierwszego oczekującego zadania (lokalne mają pierwszeństwo, potem Moodle)
  if (!empty($hw_pending))           { $hw_first_t = $hw_pending[0]['title']; $hw_first_c = $hw_pending[0]['course_name']; }
  else                               { $hw_first_t = $moodle_hw_pending[0]['name']; $hw_first_c = $moodle_hw_pending[0]['ti_course_name']; }
?>
<!-- ── Zadania do oddania — zwięzły banner widoczny po zalogowaniu ───────────── -->
<div class="alert alert-warning d-flex align-items-center gap-2 py-2 mb-3" role="alert">
  <i class="bi bi-journal-check flex-shrink-0" aria-hidden="true"></i>
  <div class="flex-grow-1 min-width-0 small">
    <span class="fw-semibold">Zadania do oddania (<?= $hw_pending_total ?>)</span>
    <span class="text-body-secondary">
      · <?= h($hw_first_t) ?> (<?= h($hw_first_c) ?>)<?php if ($hw_pending_total > 1): ?> i <?= $hw_pending_total - 1 ?> więcej<?php endif; ?>
    </span>
  </div>
  <a href="?tab=zadania" class="btn btn-sm btn-outline-warning flex-shrink-0">Zobacz</a>
</div>
<?php endif; ?>

<?php if ($instructor_leaves):
  $today_d = date('Y-m-d');
  // Fingerprint = posortowane ID urlopów; nowe urlopy = nowy popup
  $leave_ids = array_map(fn($lv) => (int)$lv['id'], $instructor_leaves);
  sort($leave_ids);
  $leave_fp = implode(',', $leave_ids);
?>
<!-- ── Nieobecność prowadzącego — wyskakujące okno (jednorazowe) ─────────────── -->

<!-- Fallback bez JS: klasyczny alert -->
<noscript>
<div class=”alert alert-info d-flex gap-2 mb-3” role=”note”>
  <i class=”bi bi-airplane fs-5 flex-shrink-0 mt-1” aria-hidden=”true”></i>
  <div class=”flex-grow-1 min-width-0”>
    <div class=”fw-semibold mb-1”>Zaplanowana nieobecność prowadzącego</div>
    <ul class=”list-unstyled small mb-0 d-flex flex-column gap-1”>
      <?php foreach ($instructor_leaves as $lv):
        $ongoing = $lv['date_from'] <= $today_d && $lv['date_to'] >= $today_d;
        $df = date('d.m.Y', strtotime($lv['date_from']));
        $dt = date('d.m.Y', strtotime($lv['date_to']));
        $range = $df === $dt ? $df : ($df . ' – ' . $dt);
        $lvcourses = trim((string)($lv['course_names'] ?? ''));
      ?>
      <li>
        <strong><?= h($lv['instructor_name']) ?></strong>
        — <?= h(mb_strtolower(ti_leave_type_label($lv['type']))) ?>,
        <span class=”text-nowrap”><?= h($range) ?></span>
        <span class=”badge <?= $ongoing ? 'text-bg-warning' : 'text-bg-secondary' ?> ms-1”><?= $ongoing ? 'trwa teraz' : 'wkrótce' ?></span>
        <?php if ($lvcourses !== ''): ?><span class=”d-block text-body-secondary”>Dotyczy zajęć: <?= h(str_replace(',', ', ', $lvcourses)) ?></span><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
</noscript>

<!-- Wyskakujące okno — position:fixed, prawa dolna część ekranu ──────────── -->
<div id="leavePopup"
     role="dialog" aria-modal="true" aria-labelledby="leavePopupTitle"
     data-leave-fp="<?= h($leave_fp) ?>"
     tabindex="-1"
     style="display:none;position:fixed;z-index:1080;bottom:1.5rem;right:1.5rem;
            width:min(420px,calc(100vw - 2rem));
            background:#1e293b;color:#f1f5f9;
            border:2px solid #f59e0b;border-radius:.6rem;
            box-shadow:0 8px 32px rgba(0,0,0,.5);">
  <!-- Pasek tytułu -->
  <div style="background:#f59e0b;color:#1c1917;border-radius:.45rem .45rem 0 0;
              padding:.55rem 1rem;display:flex;align-items:center;gap:.5rem;">
    <i class="bi bi-airplane-fill" aria-hidden="true"></i>
    <h2 class="mb-0 fw-bold" id="leavePopupTitle" style="font-size:1rem">Nieobecność prowadzącego</h2>
  </div>
  <!-- Treść -->
  <div style="padding:.9rem 1rem .5rem">
    <ul class="list-unstyled mb-2 d-flex flex-column gap-2">
      <?php foreach ($instructor_leaves as $lv):
        $ongoing   = $lv['date_from'] <= $today_d && $lv['date_to'] >= $today_d;
        $df        = date('d.m.Y', strtotime($lv['date_from']));
        $dt        = date('d.m.Y', strtotime($lv['date_to']));
        $range     = $df === $dt ? $df : ($df . ' – ' . $dt);
        $lvcourses = trim((string)($lv['course_names'] ?? ''));
      ?>
      <li style="border:1px solid #334155;border-radius:.4rem;padding:.5rem .75rem;font-size:.875rem">
        <div>
          <strong><?= h($lv['instructor_name']) ?></strong>
          — <?= h(mb_strtolower(ti_leave_type_label($lv['type']))) ?>,
          <span><?= h($range) ?></span>
          <?php if ($ongoing): ?>
          <span class="badge text-bg-warning ms-1">trwa teraz</span>
          <?php else: ?>
          <span class="badge text-bg-secondary ms-1">wkrótce</span>
          <?php endif; ?>
        </div>
        <?php if ($lvcourses !== ''): ?>
        <div style="color:#94a3b8;margin-top:.25rem;font-size:.8rem">Dotyczy: <?= h(str_replace(',', ', ', $lvcourses)) ?></div>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <p style="font-size:.8rem;color:#94a3b8;margin:0">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>W tym czasie zajęcia mogą zostać odwołane lub przełożone — sprawdź zakładkę „Moje lekcje".
    </p>
  </div>
  <!-- Stopka -->
  <div style="padding:.5rem 1rem .8rem;text-align:right">
    <button type="button" id="leavePopupOk"
            style="background:#f59e0b;color:#1c1917;border:none;border-radius:.375rem;
                   padding:.3rem .9rem;font-weight:600;cursor:pointer;font-size:.875rem">
      <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Rozumiem
    </button>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var STORE_KEY = 'ti_leave_seen';
  var popup = document.getElementById('leavePopup');
  if (!popup) return;
  var fp = popup.getAttribute('data-leave-fp');
  try { if (localStorage.getItem(STORE_KEY) === fp) return; } catch(e) {}

  var prevFocus = document.activeElement;

  // Pokaż
  popup.style.display = 'block';
  var title = document.getElementById('leavePopupTitle');
  if (title) { title.setAttribute('tabindex', '-1'); title.focus(); }

  // Pułapka Tab — fokus kręci się wewnątrz okna (a11y)
  popup.addEventListener('keydown', function(e) {
    if (e.key !== 'Tab') return;
    var els = popup.querySelectorAll('button,[tabindex]:not([tabindex="-1"])');
    var focusable = Array.prototype.filter.call(els, function(el){ return !el.disabled; });
    if (!focusable.length) return;
    var first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  // Zamknij
  document.getElementById('leavePopupOk').addEventListener('click', function() {
    try { localStorage.setItem(STORE_KEY, fp); } catch(e) {}
    popup.style.display = 'none';
    if (prevFocus && prevFocus.focus) { prevFocus.focus(); }
  });
});
</script>

<?php endif; ?>

<?php if ($tab === 'dane'): ?>

  <!-- ── Dane kursanta — strona startowa panelu ─────────────────────────────── -->
  <h1 class="h5 fw-bold mb-3"><i class="bi bi-person-vcard text-primary me-1" aria-hidden="true"></i>Dane kursanta</h1>

  <!-- ── Duży skrót do eLearning — widoczny po zalogowaniu ────────────────────── -->
  <div class="card border-0 shadow-sm mb-4 text-bg-primary">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <i class="bi bi-mortarboard-fill flex-shrink-0" style="font-size:2.6rem" aria-hidden="true"></i>
      <div class="flex-grow-1 min-width-0">
        <h2 class="h5 fw-bold mb-1">Dydaktyka / eLearning</h2>
        <p class="mb-1">Materiały do nauki, zadania domowe i oceny — wszystko w jednym miejscu.</p>
        <p class="small mb-0"><i class="bi bi-stars me-1" aria-hidden="true"></i>Nowość — docelowo eLearning prawdopodobnie zastąpi zadania z Moodle.</p>
      </div>
      <a href="?tab=zadania" class="btn btn-light btn-lg fw-semibold flex-shrink-0">
        <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Przejdź do eLearning
        <?php if ($hw_pending_total > 0): ?><span class="badge text-bg-warning ms-2"><?= $hw_pending_total ?><span class="visually-hidden"> zadań do oddania</span></span><?php endif; ?>
      </a>
    </div>
  </div>
  <?php
    $contact_emails = [];
    if (!empty($client['email']))          $contact_emails[] = ['Główny', $client['email']];
    if (!empty($account['ms_upn']))         $contact_emails[] = ['Szkoleniowy (MS)', $account['ms_upn']];
    if (!empty($account['guardian_email'])) $contact_emails[] = ['Opiekun', $account['guardian_email']];

    $contact_phones = [];
    if (!empty($client['phone']))              $contact_phones[] = ['Główny', $client['phone']];
    if (!empty($account['notify_phone2']))     $contact_phones[] = ['Dodatkowy', $account['notify_phone2']];
    if (!empty($account['notify_phone3']))     $contact_phones[] = ['Dodatkowy 2', $account['notify_phone3']];
    if (!empty($account['guardian_phone']))    $contact_phones[] = ['Opiekun', $account['guardian_phone']];
  ?>
  <div class="card mb-4">
    <div class="card-body">
      <div class="d-flex align-items-center gap-3 mb-3">
        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 text-bg-primary"
             style="width:56px;height:56px;font-size:1.4rem" aria-hidden="true">
          <i class="bi bi-person-fill"></i>
        </div>
        <div>
          <div class="h4 mb-0 fw-bold"><?= h($client['name'] ?? $student['login']) ?></div>
          <div class="text-body-secondary">
            Nr kursanta:
            <?php if (!empty($account['student_no'])): ?>
            <span class="font-monospace fw-semibold"><?= h($account['student_no']) ?></span>
            <?php else: ?><span class="fst-italic">nie nadano</span><?php endif; ?>
          </div>
        </div>
      </div>
      <dl class="row mb-0">
        <dt class="col-sm-3 text-body-secondary fw-normal">Grupy</dt>
        <dd class="col-sm-9">
          <?php if ($courses): foreach ($courses as $c): ?>
          <span class="badge text-bg-primary fw-normal me-1 mb-1"><i class="bi bi-pc-display me-1" aria-hidden="true"></i><?= h($c['course_name']) ?></span>
          <?php endforeach; else: ?><span class="text-body-secondary">— brak —</span><?php endif; ?>
        </dd>
        <dt class="col-sm-3 text-body-secondary fw-normal">E-maile kontaktowe</dt>
        <dd class="col-sm-9">
          <?php if ($contact_emails): foreach ($contact_emails as $em): ?>
          <div><span class="text-body-secondary small"><?= h($em[0]) ?>:</span> <a href="mailto:<?= h($em[1]) ?>" class="font-monospace"><?= h($em[1]) ?></a></div>
          <?php endforeach; else: ?><span class="text-body-secondary">— brak —</span><?php endif; ?>
        </dd>
        <dt class="col-sm-3 text-body-secondary fw-normal">Numery telefonu</dt>
        <dd class="col-sm-9">
          <?php if ($contact_phones): foreach ($contact_phones as $ph): ?>
          <div><span class="text-body-secondary small"><?= h($ph[0]) ?>:</span> <a href="tel:<?= h(preg_replace('/\s+/', '', $ph[1])) ?>" class="font-monospace"><?= h($ph[1]) ?></a></div>
          <?php endforeach; else: ?><span class="text-body-secondary">— brak —</span><?php endif; ?>
        </dd>
      </dl>
    </div>
  </div>

  <!-- Następna lekcja -->
  <?php if ($next_lesson):
    $nl_date  = date('d.m.Y', strtotime($next_lesson['lesson_date']));
    $nl_day   = ['Mon'=>'Pon','Tue'=>'Wt','Wed'=>'Śr','Thu'=>'Czw','Fri'=>'Pt','Sat'=>'Sob','Sun'=>'Nd'][date('D', strtotime($next_lesson['lesson_date']))] ?? '';
    $nl_today = $next_lesson['lesson_date'] === date('Y-m-d');
    $nl_tom   = $next_lesson['lesson_date'] === date('Y-m-d', strtotime('+1 day'));
    $nl_when  = $nl_today ? 'Dziś' : ($nl_tom ? 'Jutro' : $nl_day . ', ' . $nl_date);
    $nl_time  = $next_lesson['time_from'] ? ' o ' . substr($next_lesson['time_from'], 0, 5) : '';
    if ($nl_today || $nl_tom) $nl_when .= $nl_time;
  ?>
  <a href="?tab=lekcje" class="card border-0 shadow-sm mb-4 text-decoration-none <?= $nl_today ? 'border-start border-4 border-warning' : '' ?>"
     aria-label="Następna lekcja: <?= h($nl_when) ?>">
    <div class="card-body d-flex align-items-center gap-3 py-3">
      <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 <?= $nl_today ? 'text-bg-warning' : 'text-bg-primary' ?>"
           style="width:48px;height:48px;font-size:1.4rem" aria-hidden="true">
        <i class="bi bi-calendar-event"></i>
      </div>
      <div class="flex-grow-1 min-width-0">
        <div class="small text-body-secondary mb-0">Następna lekcja</div>
        <div class="fw-bold"><?= h($nl_when) ?><?= $nl_today || $nl_tom ? '' : ($nl_time ? h($nl_time) : '') ?>
          <span class="text-body-secondary fw-normal ms-1 small"><?= h($next_lesson['course_name']) ?></span>
        </div>
        <?php if ($next_lesson['topic']): ?>
        <div class="small text-body-secondary text-truncate"><?= h($next_lesson['topic']) ?></div>
        <?php endif; ?>
      </div>
      <i class="bi bi-chevron-right text-body-secondary flex-shrink-0" aria-hidden="true"></i>
    </div>
  </a>
  <?php endif; ?>

  <!-- Skróty -->
  <div class="row g-3">
    <div class="col-6 col-lg-3">
      <a href="?tab=lekcje" class="card h-100 text-decoration-none"><div class="card-body">
        <div class="fs-3 fw-bold lh-1"><?= $total_lessons ?></div>
        <div class="text-body-secondary small mt-1">Lekcji · <?= $pct ?>% frekwencji</div>
      </div></a>
    </div>
    <div class="col-6 col-lg-3">
      <a href="?tab=zadania" class="card h-100 text-decoration-none"><div class="card-body">
        <div class="fs-3 fw-bold lh-1 <?= $hw_pending_total > 0 ? 'text-warning' : '' ?>"><?= $hw_pending_total ?></div>
        <div class="text-body-secondary small mt-1">Zadań do oddania</div>
      </div></a>
    </div>
    <div class="col-6 col-lg-3">
      <a href="?tab=wiadomosci" class="card h-100 text-decoration-none"><div class="card-body">
        <div class="fs-3 fw-bold lh-1 <?= $msg_unread > 0 ? 'text-danger' : '' ?>"><?= (int)$msg_unread ?></div>
        <div class="text-body-secondary small mt-1">Nowych wiadomości</div>
      </div></a>
    </div>
    <div class="col-6 col-lg-3">
      <a href="?tab=licencje" class="card h-100 text-decoration-none"><div class="card-body">
        <div class="fs-3 fw-bold lh-1"><?= count($my_licenses) ?></div>
        <div class="text-body-secondary small mt-1">Licencji</div>
      </div></a>
    </div>
  </div>

<?php elseif ($tab === 'lekcje'): ?>

  <h1 class="h5 fw-bold mb-3">Moje lekcje</h1>

  <?php if (isset($_GET['rated'])): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Dziękujemy za ocenę zajęć!
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
  <?php endif; ?>
  <?php if (($_GET['cancel'] ?? '') === 'requested'): ?>
  <div class="alert alert-info alert-dismissible fade show" role="alert">
    <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Prośba o odwołanie została wysłana do prowadzącego i czeka na potwierdzenie.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
  <?php elseif (($_GET['cancel'] ?? '') === 'withdrawn'): ?>
  <div class="alert alert-secondary alert-dismissible fade show" role="alert">
    <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Prośba o odwołanie została wycofana.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
  <?php endif; ?>

  <!-- Statystyki -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
      <div class="card h-100"><div class="card-body">
        <div class="fs-3 fw-bold lh-1"><?= $total_lessons ?></div>
        <div class="text-body-secondary small mt-1">Wszystkich lekcji</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card h-100"><div class="card-body">
        <div class="fs-3 fw-bold lh-1 text-success"><?= $attended_count ?></div>
        <div class="text-body-secondary small mt-1">Obecności</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card h-100"><div class="card-body">
        <div class="fs-3 fw-bold lh-1"><?= $pct ?>%</div>
        <div class="text-body-secondary small mt-1 mb-1">Frekwencja</div>
        <div class="progress" role="progressbar" aria-label="Frekwencja"
             aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" style="height:6px">
          <div class="progress-bar" style="width:<?= $pct ?>%"></div>
        </div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card h-100"><div class="card-body">
        <div class="fs-3 fw-bold lh-1"><?= count($courses) ?></div>
        <div class="text-body-secondary small mt-1">Kursów/grup</div>
      </div></div>
    </div>
  </div>


  <!-- Lista lekcji — zwijane grupy: nadchodzące / minione -->
  <?php
    // Podział lekcji względem dziś (okno ±7 dni):
    //   near     – ostatnie i najbliższe (±7 dni) → zawsze widoczne
    //   up_far   – nadchodzące dalej niż 7 dni → zwinięte
    //   past_far – minione wcześniej niż 7 dni → zwinięte
    $today_ymd = date('Y-m-d');
    $d_soon = date('Y-m-d', strtotime('+7 days'));
    $d_ago  = date('Y-m-d', strtotime('-7 days'));
    $near = $up_far = $past_far = [];
    $cal_by_date = [];                       // widok kalendarza: 'Y-m-d' => [lekcje]
    foreach ($lessons as $l) {
        $ld = (string)($l['lesson_date'] ?? '');
        $cal_by_date[$ld][] = $l;
        if ($ld >= $d_ago && $ld <= $d_soon) $near[] = $l;
        elseif ($ld > $d_soon)               $up_far[] = $l;
        else                                 $past_far[] = $l;
    }
    usort($near,   fn($a, $b) => strcmp((string)$b['lesson_date'], (string)$a['lesson_date'])); // najnowsze na górze
    usort($up_far, fn($a, $b) => strcmp((string)$b['lesson_date'], (string)$a['lesson_date'])); // najnowsze na górze
    // $past_far zostaje malejąco (z zapytania) — od najnowszej

    // Zadania domowe podpięte pod konkretną lekcję (session_id => [zadania])
    $hw_by_session = [];
    foreach ($homeworks_student as $hw) { $sid = (int)($hw['session_id'] ?? 0); if ($sid) $hw_by_session[$sid][] = $hw; }

    // Wiersz pojedynczej lekcji — współdzielony przez obie grupy
    $lessonRow = function(array $l) use ($months_pl, $vlab_token, $hw_by_session) {
            $d   = new DateTime($l['lesson_date']);
            $dow = ['Nd','Pn','Wt','Śr','Czw','Pt','Sb'][(int)$d->format('w')];
            $eff_link = trim((string)($l['meeting_url'] ?? '')) !== '' ? $l['meeting_url'] : (string)($l['course_meeting_url'] ?? '');
          ?>
          <tr>
            <td class="text-nowrap">
              <span class="text-body-secondary small"><?= $dow ?></span>
              <span class="fw-semibold"><?= $d->format('d') ?></span>
              <span class="text-body-secondary small"><?= $months_pl[(int)$d->format('n')] ?> <?= $d->format('Y') ?></span>
            </td>
            <td class="text-body-secondary small"><?= h($l['course_name']) ?></td>
            <td class="text-nowrap small">
              <?= $l['time_from'] ? h($l['time_from']).'–'.h($l['time_to']) : ((int)$l['duration_min']).' min' ?>
            </td>
            <td style="max-width:240px">
              <?php if ($l['topic']): ?>
              <?= h($l['topic']) ?>
              <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
            </td>
            <td style="max-width:220px">
              <?php $lesson_hws = $hw_by_session[(int)$l['id']] ?? [];
                if ($lesson_hws): ?>
              <div class="d-flex flex-column gap-1">
                <?php foreach ($lesson_hws as $lh):
                  $hdone = !empty($lh['sub_id']); $hgraded = ($lh['sub_status'] ?? '') === 'graded';
                  $hcls  = $hgraded ? 'text-bg-success' : ($hdone ? 'text-bg-secondary' : 'text-bg-warning');
                  $hlbl  = $hgraded ? 'ocenione' : ($hdone ? 'oddane' : 'do oddania'); ?>
                <a href="?tab=zadania" class="text-decoration-none small d-flex align-items-center gap-1 flex-wrap" title="<?= h($lh['title']) ?>">
                  <span class="text-body text-break"><?= h($lh['title']) ?></span>
                  <span class="badge <?= $hcls ?>"><?= $hlbl ?></span>
                </a>
                <?php endforeach; ?>
              </div>
              <?php elseif (($l['has_homework'] ?? 0) && empty($l['self_prep_remote'])): ?>
              <a href="?tab=zadania" class="badge text-bg-warning text-decoration-none">do oddania</a>
              <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
            </td>
            <td>
              <?php if (!empty($l['self_prep_remote'])): ?>
              <span class="badge text-bg-info"><i class="bi bi-laptop me-1" aria-hidden="true"></i>Przygotowanie materiałów</span>
              <?php else: ?>
              <span class="badge text-bg-secondary"><i class="bi bi-person-video3 me-1" aria-hidden="true"></i>Lekcja z uczestnikiem</span>
              <?php endif; ?>
            </td>
            <?php $att_cancelled = (int)($l['att_cancelled'] ?? 0) === 1; $att_pending = (int)($l['att_cancel_pending'] ?? 0) === 1; ?>
            <td class="text-center">
              <?php if ($att_pending): ?>
              <span class="badge text-bg-warning" title="<?= h($l['att_cancel_reason'] ?? '') ?>"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Czeka na potwierdzenie</span>
              <?php elseif ($att_cancelled): ?>
              <span class="badge text-bg-danger" title="<?= h($l['att_cancel_reason'] ?? '') ?>"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>odwołane</span>
              <?php elseif ($l['status'] !== 'held'): ?>
              <span class="badge text-bg-secondary"><?= $l['status']==='planned'?'planowana':h($l['status']) ?></span>
              <?php elseif ($l['attended']): ?>
              <span class="badge text-bg-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>obecny</span>
              <?php else: ?>
              <span class="badge text-bg-danger"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>nieobecny</span>
              <?php endif; ?>
            </td>
            <td class="small text-body-secondary">
              <?php if ($att_cancelled && !empty($l['att_cancel_reason'])): ?>
              <span class="text-danger">Powód odwołania: <?= h(mb_substr($l['att_cancel_reason'],0,60)) ?></span>
              <?php else: ?>
              <?= $l['ind_notes'] ? h(mb_substr($l['ind_notes'],0,60)) : '' ?>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
              <?php if ($eff_link !== '' && !$att_cancelled && in_array($l['status'], ['planned','held'], true)): ?>
              <a href="<?= h($eff_link) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success"
                 title="Dołącz do lekcji online">
                <i class="bi bi-camera-video me-1" aria-hidden="true"></i>Dołącz
              </a>
              <?php endif; ?>
              <?php if ($l['status'] === 'held' && $l['attended'] && !$att_cancelled):
                $has_rating = !empty($l['my_rating']); ?>
              <button type="button" class="btn btn-sm <?= $has_rating ? 'btn-outline-warning' : 'btn-warning' ?>"
                      data-rate-session="<?= (int)$l['id'] ?>"
                      data-rate-value="<?= (int)($l['my_rating'] ?? 0) ?>"
                      data-rate-comment="<?= h($l['my_comment'] ?? '') ?>"
                      data-lesson-label="<?= h($l['course_name'].' — '.$d->format('d.m.Y')) ?>"
                      title="Oceń zajęcia">
                <?php if ($has_rating): ?>
                <i class="bi bi-star-fill me-1" aria-hidden="true"></i><?= (int)$l['my_rating'] ?>/5
                <?php else: ?>
                <i class="bi bi-star me-1" aria-hidden="true"></i>Oceń
                <?php endif; ?>
              </button>
              <?php endif; ?>
              <?php if ($l['status'] === 'planned' && $att_pending): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Wycofać prośbę o odwołanie i potwierdzić udział?')">
                <input type="hidden" name="_token"     value="<?= h($vlab_token) ?>">
                <input type="hidden" name="_op"         value="uncancel_lesson">
                <input type="hidden" name="session_id"  value="<?= (int)$l['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Cofnij prośbę
                </button>
              </form>
              <?php elseif ($l['status'] === 'planned' && !$att_cancelled): ?>
              <button type="button" class="btn btn-sm btn-outline-danger"
                      data-cancel-session="<?= (int)$l['id'] ?>"
                      data-lesson-label="<?= h($l['course_name'].' — '.$d->format('d.m.Y')) ?>">
                <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Odwołaj
              </button>
              <?php endif; ?>
              </div>
            </td>
          </tr>
    <?php }; // $lessonRow

    // Tabela lekcji z nagłówkiem — współdzielona przez obie grupy
    $lessonTable = function(array $rows) use ($lessonRow) { ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <caption class="visually-hidden">Lista lekcji z obecnością</caption>
        <thead>
          <tr>
            <th scope="col">Data</th><th scope="col">Kurs</th><th scope="col">Godziny</th>
            <th scope="col">Temat</th><th scope="col">Zadanie</th><th scope="col">Typ lekcji</th>
            <th scope="col" class="text-center">Obecność</th><th scope="col">Uwagi</th>
            <th scope="col" class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody><?php foreach ($rows as $l) $lessonRow($l); ?></tbody>
      </table>
    </div>
    <?php }; ?>

  <?php if (!$lessons): ?>
  <div class="card"><div class="card-body text-center text-body-secondary py-4">
    <?php if ($active_enroll_count === 0): ?>
    <i class="bi bi-person-x me-1" aria-hidden="true"></i>Nie masz aktywnego zapisu na żaden kurs, dlatego nie ma jeszcze lekcji.
    <span class="d-block mt-1">Jeśli to pomyłka — skontaktuj się z prowadzącym.</span>
    <?php else: ?>
    <i class="bi bi-calendar-x me-1" aria-hidden="true"></i>Twój kurs nie ma jeszcze zaplanowanych lekcji. Pojawią się tutaj, gdy prowadzący je doda.
    <?php endif; ?>
  </div></div>
  <?php else: ?>

  <!-- Pasek narzędzi: widok kalendarza -->
  <div class="d-flex justify-content-end mb-2">
    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#lessonsCalModal">
      <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Widok kalendarza
    </button>
  </div>

  <!-- Najbliższe (±7 dni) — zawsze widoczne -->
  <div class="card mb-3">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-calendar2-week text-primary" aria-hidden="true"></i><span>Najbliższe lekcje</span>
      <span class="text-body-secondary fw-normal small">(ostatnie i nadchodzące 7 dni)</span>
      <?php if ($near): ?><span class="badge text-bg-secondary ms-auto"><?= count($near) ?></span><?php endif; ?>
    </div>
    <?php if ($near) { $lessonTable($near); } else { ?>
    <div class="card-body text-body-secondary small">Brak lekcji w oknie ±7 dni — rozwiń sekcje poniżej, aby zobaczyć dalsze terminy.</div>
    <?php } ?>
  </div>

  <!-- Nadchodzące dalej niż 7 dni — zwinięte -->
  <?php if ($up_far): ?>
  <details class="card dyd-hw mb-3">
    <summary class="card-header fw-semibold dyd-hw-summary d-flex align-items-center gap-2">
      <i class="bi bi-calendar-plus text-primary" aria-hidden="true"></i><span>Nadchodzące później (ponad 7 dni)</span>
      <span class="badge text-bg-secondary"><?= count($up_far) ?></span>
      <i class="bi bi-chevron-down dyd-hw-chevron ms-auto text-body-secondary" aria-hidden="true"></i>
    </summary>
    <?php $lessonTable($up_far); ?>
  </details>
  <?php endif; ?>

  <!-- Minione wcześniej niż 7 dni — zwinięte -->
  <?php if ($past_far): ?>
  <details class="card dyd-hw">
    <summary class="card-header fw-semibold dyd-hw-summary d-flex align-items-center gap-2">
      <i class="bi bi-clock-history text-body-secondary" aria-hidden="true"></i><span>Minione (wcześniej niż 7 dni)</span>
      <span class="badge text-bg-secondary"><?= count($past_far) ?></span>
      <i class="bi bi-chevron-down dyd-hw-chevron ms-auto text-body-secondary" aria-hidden="true"></i>
    </summary>
    <?php $lessonTable($past_far); ?>
  </details>
  <?php endif; ?>

  <?php
    // ── Widok kalendarza (popup) — miesiące z lekcjami ──────────────────────
    $cal_months = [];
    foreach (array_keys($cal_by_date) as $ld) { if ($ld !== '') $cal_months[substr($ld, 0, 7)] = true; }
    $cal_months = array_keys($cal_months); sort($cal_months);
    $months_full = [1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',
                    7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień'];
    $wd_short = ['Pn','Wt','Śr','Cz','Pt','So','Nd'];
    $wd_full  = ['Poniedziałek','Wtorek','Środa','Czwartek','Piątek','Sobota','Niedziela'];
  ?>
  <div class="modal fade" id="lessonsCalModal" tabindex="-1" aria-labelledby="lessonsCalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title h5" id="lessonsCalTitle"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>Kalendarz lekcji</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <?php foreach ($cal_months as $ym):
            $year = (int)substr($ym, 0, 4); $mon = (int)substr($ym, 5, 2);
            $first = mktime(0, 0, 0, $mon, 1, $year);
            $daysIn = (int)date('t', $first);
            $startDow = (int)date('N', $first);   // 1=Pn … 7=Nd
          ?>
          <table class="table table-bordered kp-cal mb-4">
            <caption class="fw-semibold text-body mb-1"><?= $months_full[$mon] ?> <?= $year ?></caption>
            <thead><tr>
              <?php foreach ($wd_short as $i => $w): ?><th scope="col" class="text-center small text-body-secondary" abbr="<?= h($wd_full[$i]) ?>"><?= $w ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody><tr>
              <?php
                for ($i = 1; $i < $startDow; $i++) echo '<td class="kp-cal-empty" aria-hidden="true"></td>';
                $col = $startDow - 1;
                for ($day = 1; $day <= $daysIn; $day++):
                    $ymd = sprintf('%04d-%02d-%02d', $year, $mon, $day);
                    $dayLessons = $cal_by_date[$ymd] ?? [];
                    $isToday = $ymd === $today_ymd;
              ?>
              <td class="kp-cal-day<?= $dayLessons ? ' has-lesson' : '' ?><?= $isToday ? ' is-today' : '' ?>"<?= $isToday ? ' aria-current="date"' : '' ?>>
                <div class="kp-cal-num <?= $isToday ? 'fw-bold' : '' ?>"><?= $day ?></div>
                <?php foreach ($dayLessons as $dl):
                  $canc = (string)($dl['status'] ?? '') === 'cancelled' || (int)($dl['att_cancelled'] ?? 0) === 1; ?>
                <div class="kp-cal-ev<?= $canc ? ' cancelled' : '' ?>" title="<?= h(($dl['time_from'] ? substr($dl['time_from'],0,5).' ' : '').($dl['topic'] ?: $dl['course_name'])) ?>">
                  <?php if ($dl['time_from']): ?><span class="fw-semibold"><?= h(substr($dl['time_from'],0,5)) ?></span> <?php endif; ?><?= h($dl['topic'] ?: $dl['course_name']) ?>
                </div>
                <?php endforeach; ?>
              </td>
              <?php
                  $col++;
                  if ($col % 7 === 0 && $day < $daysIn) echo '</tr><tr>';
                endfor;
                while ($col % 7 !== 0) { echo '<td class="kp-cal-empty" aria-hidden="true"></td>'; $col++; }
              ?>
            </tr></tbody>
          </table>
          <?php endforeach; ?>
          <p class="text-body-secondary small mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Widoczne są miesiące z zaplanowanymi lekcjami; dzisiejszy dzień jest wyróżniony.</p>
        </div>
      </div>
    </div>
  </div>

  <?php endif; ?>

  <p class="text-body-secondary small mt-2">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
    Zaplanowaną lekcję możesz poprosić o odwołanie, podając powód — prośba czeka na potwierdzenie prowadzącego. Po potwierdzeniu udział nie jest liczony do ceny.
  </p>

  <!-- Modal: prośba o odwołanie udziału przez beneficjenta -->
  <div class="modal fade" id="cancelLessonModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <input type="hidden" name="_token"      value="<?= h($vlab_token) ?>">
        <input type="hidden" name="_op"          value="cancel_lesson">
        <input type="hidden" name="session_id"   id="cl_session_id" value="">
        <div class="modal-header">
          <h2 class="modal-title h5"><i class="bi bi-hourglass-split text-warning me-2" aria-hidden="true"></i>Prośba o odwołanie lekcji</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">Lekcja: <strong id="cl_lesson_label"></strong></p>
          <p class="text-body-secondary small mb-2">Prośba zostanie wysłana do prowadzącego i będzie czekać na jego potwierdzenie. Po potwierdzeniu udział nie zostanie policzony do ceny. Podaj powód.</p>
          <label class="form-label fw-semibold" for="cl_reason">Powód odwołania</label>
          <textarea class="form-control" id="cl_reason" name="reason" rows="3" required
                    placeholder="np. choroba, kolizja z innymi obowiązkami…"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij prośbę o odwołanie</button>
        </div>
      </form>
    </div>
  </div>

  <script>
  (function(){
    var modalEl = document.getElementById('cancelLessonModal');
    if (!modalEl) return;
    document.querySelectorAll('[data-cancel-session]').forEach(function(btn){
      btn.addEventListener('click', function(){
        document.getElementById('cl_session_id').value = btn.getAttribute('data-cancel-session');
        document.getElementById('cl_lesson_label').textContent = btn.getAttribute('data-lesson-label') || '';
        document.getElementById('cl_reason').value = '';
        new bootstrap.Modal(modalEl).show();
      });
    });
  })();
  </script>

  <!-- Modal: ocena zajęć (1–5) -->
  <div class="modal fade" id="rateLessonModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <input type="hidden" name="_token"     value="<?= h($vlab_token) ?>">
        <input type="hidden" name="_op"         value="rate_lesson">
        <input type="hidden" name="session_id"  id="rl_session_id" value="">
        <input type="hidden" name="rating"      id="rl_rating" value="0">
        <div class="modal-header">
          <h2 class="modal-title h5"><i class="bi bi-star-fill text-warning me-2" aria-hidden="true"></i>Oceń zajęcia</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">Lekcja: <strong id="rl_lesson_label"></strong></p>
          <p class="text-body-secondary small mb-2">Jak oceniasz te zajęcia w skali od 1 do 5?</p>
          <div class="d-flex gap-1 mb-3 fs-2" id="rl_stars" role="radiogroup" aria-label="Ocena w gwiazdkach">
            <?php for ($i=1;$i<=5;$i++): ?>
            <button type="button" class="btn btn-link p-0 text-warning" data-star="<?= $i ?>"
                    aria-label="<?= $i ?> z 5" style="line-height:1;text-decoration:none">
              <i class="bi bi-star" aria-hidden="true"></i>
            </button>
            <?php endfor; ?>
          </div>
          <label class="form-label fw-semibold" for="rl_comment">Komentarz <span class="text-body-secondary fw-normal">(opcjonalnie)</span></label>
          <textarea class="form-control" id="rl_comment" name="comment" rows="3" maxlength="1000"
                    placeholder="Co było dobre, co można poprawić…"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning" id="rl_submit" disabled><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz ocenę</button>
        </div>
      </form>
    </div>
  </div>

  <script>
  (function(){
    var modalEl = document.getElementById('rateLessonModal');
    if (!modalEl) return;
    var stars  = modalEl.querySelectorAll('#rl_stars [data-star]');
    var field  = document.getElementById('rl_rating');
    var submit = document.getElementById('rl_submit');
    function paint(v){
      stars.forEach(function(b){
        var on = parseInt(b.getAttribute('data-star'),10) <= v;
        b.querySelector('i').className = on ? 'bi bi-star-fill' : 'bi bi-star';
      });
      field.value = v;
      submit.disabled = !(v >= 1 && v <= 5);
    }
    stars.forEach(function(b){
      b.addEventListener('click', function(){ paint(parseInt(b.getAttribute('data-star'),10)); });
    });
    document.querySelectorAll('[data-rate-session]').forEach(function(btn){
      btn.addEventListener('click', function(){
        document.getElementById('rl_session_id').value = btn.getAttribute('data-rate-session');
        document.getElementById('rl_lesson_label').textContent = btn.getAttribute('data-lesson-label') || '';
        document.getElementById('rl_comment').value = btn.getAttribute('data-rate-comment') || '';
        paint(parseInt(btn.getAttribute('data-rate-value'),10) || 0);
        new bootstrap.Modal(modalEl).show();
      });
    });
  })();
  </script>

<?php elseif ($tab === 'zadania'): ?>

  <h1 class="h5 fw-bold mb-1"><i class="bi bi-mortarboard text-primary me-1" aria-hidden="true"></i>Dydaktyka / eLearning</h1>
  <p class="text-body-secondary small mb-3">Materiały do nauki i zadania domowe — pogrupowane według lekcji. Oceny znajdziesz w zakładce „Oceny”.</p>

  <?php
    $hwf = $_SESSION['hw_flash'] ?? null; unset($_SESSION['hw_flash']);
    if ($hwf): ?>
  <div class="alert alert-<?= $hwf[0]==='ok'?'success':'danger' ?> alert-dismissible fade show" role="alert">
    <i class="bi bi-<?= $hwf[0]==='ok'?'check-circle':'exclamation-triangle' ?> me-1" aria-hidden="true"></i><?= h($hwf[1]) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
  <?php endif; ?>

  <?php if (!$dyd_groups): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= $moodle_assignments ? 'Brak materiałów i zadań od prowadzącego — sprawdź zadania z Moodle poniżej.' : 'Brak materiałów i zadań.' ?></div>
  <?php else: $now = date('Y-m-d H:i:s'); ?>
    <?php foreach ($dyd_groups as $grp): ?>
    <section class="mb-4">
      <h2 class="h6 fw-bold d-flex flex-wrap align-items-center gap-2 mb-2 pb-1 border-bottom">
        <?php if ($grp['session_id']): ?>
        <i class="bi bi-calendar-event text-primary" aria-hidden="true"></i>
        <span>Lekcja <?= h(substr($grp['date'],0,10)) ?></span>
        <?php if ($grp['topic']): ?><span class="text-body-secondary fw-normal">· <?= h($grp['topic']) ?></span><?php endif; ?>
        <span class="text-body-secondary fw-normal small ms-1"><?= h($grp['course_name']) ?></span>
        <?php else: ?>
        <i class="bi bi-folder2-open text-primary" aria-hidden="true"></i><span>Bez przypisanej lekcji</span>
        <?php endif; ?>
      </h2>

      <?php if ($grp['materials']): ?>
      <details class="dyd-hw mb-3">
        <summary class="dyd-hw-summary text-body-secondary small fw-semibold mb-1 d-flex align-items-center gap-2">
          <i class="bi bi-collection-play" aria-hidden="true"></i><span>Materiały</span>
          <span class="badge text-bg-secondary"><?= count($grp['materials']) ?></span>
          <i class="bi bi-chevron-down dyd-hw-chevron ms-auto" aria-hidden="true"></i>
        </summary>
        <div class="d-flex flex-column gap-2 mt-1">
          <?php foreach ($grp['materials'] as $m) { include __DIR__ . '/_dyd_material.php'; } ?>
        </div>
      </details>
      <?php endif; ?>

      <?php if ($grp['homeworks']): ?>
      <div class="text-body-secondary small fw-semibold mb-1"><i class="bi bi-journal-check me-1" aria-hidden="true"></i>Zadania domowe</div>
      <div class="d-flex flex-column gap-3">
        <?php foreach ($grp['homeworks'] as $h) { include __DIR__ . '/_dyd_homework.php'; } ?>
      </div>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- ── Zadania z Moodle (pobierane z serwera) ───────────────────────────── -->
  <?php if ($moodle_assignments): $now_m = date('Y-m-d H:i:s'); ?>
  <h2 class="h6 fw-bold d-flex align-items-center gap-2 mt-4 mb-1">
    <i class="bi bi-mortarboard text-primary" aria-hidden="true"></i>Zadania z Moodle
    <?php if (!empty($moodle_hw_pending)): ?><span class="badge text-bg-warning"><?= count($moodle_hw_pending) ?> do zrobienia</span><?php endif; ?>
  </h2>
  <p class="text-body-secondary small mb-3">Zadania z kursów Moodle Twoich grup. Oddajesz je bezpośrednio w Moodle — tutaj pilnujesz terminów i statusu.</p>
  <div class="d-flex flex-column gap-3">
    <?php foreach ($moodle_assignments as $a):
      $submitted = ($a['sub_status'] ?? '') === 'submitted';
      $graded    = !empty($a['sub_graded']);
      $due       = $a['due_at'] ?? '';
      $overdue   = $due && $due < $now_m && !$submitted && !$graded;
      // „Dodano”: data otwarcia z Moodle, a w razie braku — pierwsze pojawienie się w systemie
      $added     = !empty($a['open_at']) ? $a['open_at'] : ($a['first_seen_at'] ?? '');
      $link      = ti_moodle_assign_url((string)$a['base_url'], (int)$a['cmid']);
    ?>
    <div class="card <?= $graded ? 'border-success' : ($overdue ? 'border-danger' : '') ?>">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-start gap-2 mb-1">
          <div class="flex-grow-1 min-width-0">
            <div class="fw-bold"><?= h($a['name']) ?></div>
            <div class="small text-body-secondary d-flex flex-wrap gap-2 mt-1">
              <span><i class="bi bi-pc-display me-1" aria-hidden="true"></i><?= h($a['ti_course_name']) ?></span>
              <?php if ($added): ?>
              <span><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>dodano: <?= h(substr($added,0,10)) ?></span>
              <?php endif; ?>
              <?php if ($due): ?>
              <span class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>"><i class="bi bi-alarm me-1" aria-hidden="true"></i>termin: <?= h(substr($due,0,16)) ?></span>
              <?php else: ?>
              <span><i class="bi bi-alarm me-1" aria-hidden="true"></i>termin: brak</span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($graded): ?>
          <span class="badge text-bg-success">Ocena<?= $a['sub_grade'] !== '' ? ': '.h($a['sub_grade']) : '' ?></span>
          <?php elseif ($submitted): ?>
          <span class="badge text-bg-secondary">Oddane<?= !empty($a['sub_submitted_at']) ? ' '.h(substr($a['sub_submitted_at'],0,10)) : '' ?></span>
          <?php elseif ($overdue): ?>
          <span class="badge text-bg-danger">Po terminie</span>
          <?php else: ?>
          <span class="badge text-bg-warning">Do zrobienia</span>
          <?php endif; ?>
        </div>
        <?php if (!empty($a['intro'])): ?>
        <p class="small mb-2" style="white-space:pre-wrap"><?= h(mb_substr($a['intro'],0,300)) ?></p>
        <?php endif; ?>
        <a href="<?= h($link) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz w Moodle
        </a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php elseif ($tab === 'oceny'): ?>

  <h1 class="h5 fw-bold mb-1 d-flex align-items-center gap-2">
    <i class="bi bi-table text-primary" aria-hidden="true"></i>Oceny
    <?php if ($grades_student): ?><a href="?grades_pdf=1" class="btn btn-sm btn-outline-danger ms-auto"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Pobierz PDF</a><?php endif; ?>
  </h1>
  <p class="text-body-secondary small mb-3">Oceny wystawione przez prowadzących, ze średnią ważoną dla każdego kursu.</p>

  <?php if (!$grades_student): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak ocen. Pojawią się tutaj, gdy prowadzący je wystawi.</div>
  <?php else: ?>
    <?php foreach ($grades_by_course as $cname => $cgr):
      $avg = k30_ti_grades_average($cgr);
      [$abg,$afg] = k30_ti_grade_color($avg);
    ?>
    <div class="card mb-3">
      <div class="card-header d-flex flex-wrap align-items-center gap-2 py-2">
        <span class="fw-semibold"><i class="bi bi-pc-display me-1" aria-hidden="true"></i><?= h($cname) ?></span>
        <span class="badge text-bg-secondary"><?= count($cgr) ?> ocen</span>
        <?php if ($avg !== null): ?>
        <span class="ms-auto small text-body-secondary">Średnia ważona:</span>
        <span class="badge" style="background:<?= $abg ?>;color:<?= $afg ?>;font-size:.9rem"><?= number_format($avg, 2, ',', '') ?></span>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
          <thead class="table-light">
            <tr><th>Data</th><th>Ocena</th><th>Waga</th><th>Kategoria</th><th>Za co</th><th>Wystawił(a)</th></tr>
          </thead>
          <tbody>
            <?php foreach ($cgr as $g): ?>
            <tr>
              <td class="text-nowrap small"><?= h(substr($g['graded_at'],0,10)) ?></td>
              <td><?= k30_ti_grade_badge($g) ?></td>
              <td class="small"><?= h(rtrim(rtrim(number_format((float)$g['weight'],2,'.',''),'0'),'.') ?: '1') ?></td>
              <td class="small"><?= h(k30_ti_grade_category_label($g['category'])) ?></td>
              <td class="small"><?= $g['description'] ? h($g['description']) : '<span class="text-body-secondary">—</span>' ?></td>
              <td class="small text-nowrap"><?= !empty($g['graded_by_name']) ? h($g['graded_by_name']) : '<span class="text-body-secondary">—</span>' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

<?php elseif ($tab === 'rozliczenia' && !$is_minor):
  $rv_client_id    = $student['client_id'];
  $rv_show_lessons = false;
  include __DIR__ . '/_rozliczenia_view.php';
?>

<?php elseif ($tab === 'vlab'): ?>

  <?php if (!$vlab_term_ok):
    $vlab_term = ti_term_get('vlab'); ?>
  <div class="row justify-content-center">
    <div class="col-lg-7">
      <?= _ti_terms_acceptance_block($vlab_term, $vlab_token, 'vlab') ?>
    </div>
  </div>
  <?php else: ?>

  <div id="vlab-root" data-token="<?= h($vlab_token) ?>">
    <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-1">
      <i class="bi bi-hdd-stack text-primary" aria-hidden="true"></i>VLab — Twoje maszyny
    </h1>
    <p class="text-body-secondary small mb-3">
      Twórz własne środowiska (kontenery Docker) do ćwiczeń. Dostęp przez terminal w przeglądarce lub po SSH.
    </p>
    <div id="vlab-content" aria-live="polite">
      <div class="text-body-secondary py-4 text-center">Ładowanie…</div>
    </div>
  </div>

  <!-- Modal: dane logowania do maszyny (pokazywane jednorazowo po utworzeniu) -->
  <div class="modal fade" id="vlabCredsModal" tabindex="-1" aria-labelledby="vlabCredsLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title h5" id="vlabCredsLabel"><i class="bi bi-key-fill text-warning me-2" aria-hidden="true"></i>Dane logowania do maszyny</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body" id="vlabCredsBody"></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Zamknij</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal: tworzenie maszyny (nazwa + porty do wystawienia) -->
  <div class="modal fade" id="vlabCreateModal" tabindex="-1" aria-labelledby="vlabCreateLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" id="vlabCreateForm">
        <div class="modal-header">
          <h2 class="modal-title h5" id="vlabCreateLabel"><i class="bi bi-plus-circle text-primary me-2" aria-hidden="true"></i>Nowa maszyna</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="vc-tpl">
          <p class="small text-body-secondary mb-3">Szablon: <strong id="vc-tplname"></strong></p>
          <div class="mb-3">
            <label class="form-label" for="vc-label">Nazwa maszyny</label>
            <input type="text" class="form-control" id="vc-label" value="lab" maxlength="32" placeholder="np. projekt-www">
            <div class="form-text">Litery, cyfry i myślniki.</div>
          </div>
          <div class="mb-2">
            <label class="form-label" for="vc-ports">Porty do wystawienia</label>
            <input type="text" class="form-control" id="vc-ports" placeholder="np. 80, 443, 8080">
            <div class="form-text">Porty usług w kontenerze, które chcesz wystawić (oddziel przecinkami). SSH i terminal są dostępne zawsze. Możesz zostawić puste.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary" id="vc-submit"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Utwórz maszynę</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: zarządzanie portami maszyny -->
  <div class="modal fade" id="vlabPortsModal" tabindex="-1" aria-labelledby="vlabPortsLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title h5" id="vlabPortsLabel"><i class="bi bi-hdd-network text-primary me-2" aria-hidden="true"></i>Porty maszyny</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body" id="vlabPortsBody"></div>
      </div>
    </div>
  </div>

  <script>
  (function(){
    const root = document.getElementById('vlab-root');
    const box  = document.getElementById('vlab-content');
    const token = root.dataset.token;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function api(action, params){
      const body = new URLSearchParams(Object.assign({action, _token: token}, params || {}));
      const r = await fetch('vlab_api.php', {method:'POST', headers:{'X-CSRF-Token':token}, body});
      return r.json();
    }
    const stMap = {running:['success','działa'], stopped:['secondary','zatrzymana'], error:['danger','błąd'], provisioning:['warning','tworzenie']};
    const lastCreds = {}; // pełne dane logowania pokazywane JEDEN raz po utworzeniu: {id: creds}

    // Wiersz „etykieta + wartość + kopiuj" w modalu danych logowania
    function credRow(label, value, mono){
      if (!value) return '';
      return '<div class="mb-2"><label class="form-label small text-body-secondary mb-1">'+esc(label)+'</label>'
        + '<div class="input-group input-group-sm">'
        + '<input type="text" class="form-control '+(mono?'font-monospace':'')+'" readonly value="'+esc(value)+'" onclick="this.select()">'
        + '<button type="button" class="btn btn-outline-secondary" data-copy="'+esc(value)+'" aria-label="Kopiuj"><i class="bi bi-clipboard" aria-hidden="true"></i></button>'
        + '</div></div>';
    }
    function showCredsModal(c){
      if (!c) return;
      const ssh = c.host_user && c.ssh_host ? 'ssh '+c.host_user+'@'+c.ssh_host+' -p '+(c.ssh_port||22) : '';
      let h = '<p class="small text-body-secondary mb-3"><i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>Te dane pokazujemy <strong>tylko teraz</strong>. Zapisz je — wysłaliśmy je również e-mailem.</p>';
      h += credRow('Połączenie SSH', ssh, true);
      h += credRow('Hasło SSH', c.host_password, true);
      if (c.ttyd_url) h += credRow('Terminal w przeglądarce', c.ttyd_url, true);
      if (c.ttyd_user) h += credRow('Login terminala', c.ttyd_user+' / '+c.ttyd_password, true);
      if (c.force_change) h += '<div class="alert alert-warning py-2 small mb-0"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>Przy pierwszym logowaniu SSH system poprosi o ustawienie własnego hasła.</div>';
      document.getElementById('vlabCredsBody').innerHTML = h;
      if (window.bootstrap) new bootstrap.Modal(document.getElementById('vlabCredsModal')).show();
    }

    // ── Porty maszyny (samoobsługa kursanta) ──────────────────────────────
    let portsForId = null;
    const portsBody = () => document.getElementById('vlabPortsBody');
    function renderPorts(d){
      const openMap = {};
      (d.open || []).forEach(p => openMap[p.host_port + '/' + p.proto] = p);

      let h = '<p class="small text-body-secondary mb-3">Złóż wniosek o otwarcie portu — administrator zatwierdza zmiany w zaporze. Podaj numer portu lub wybierz z mapowań kontenera.</p>';

      // Formularz ręcznego wpisania portu
      h += '<div class="d-flex gap-2 mb-3 align-items-end flex-wrap">'
        + '<div><label class="form-label small mb-1">Port hosta</label>'
        + '<input type="number" id="port-manual-nr" class="form-control form-control-sm" min="1" max="65535" placeholder="np. 8080" style="width:110px"></div>'
        + '<div><label class="form-label small mb-1">Protokół</label>'
        + '<select id="port-manual-proto" class="form-select form-select-sm" style="width:80px"><option value="tcp">TCP</option><option value="udp">UDP</option></select></div>'
        + '<div><label class="form-label small mb-1">Opis (opcja)</label>'
        + '<input type="text" id="port-manual-note" class="form-control form-control-sm" maxlength="100" placeholder="np. serwer WWW" style="width:160px"></div>'
        + '<button type="button" id="port-manual-submit" class="btn btn-primary btn-sm">'
        + '<i class="bi bi-send me-1" aria-hidden="true"></i>Zgłoś wniosek</button>'
        + '</div>';

      // Mapowania kontenera jako szybkie przyciski (gdy maszyna działa)
      if (d.mappings && d.mappings.length) {
        h += '<p class="small fw-semibold mb-1">Porty kontenera (szybkie zgłoszenie):</p>';
        h += '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Kontener</th><th>Host</th><th>Status</th><th></th></tr></thead><tbody>';
        for (const mp of d.mappings){
          const proto = mp.cport.indexOf('udp') >= 0 ? 'udp' : 'tcp';
          const key = mp.host + '/' + proto;
          const isOpen = !!openMap[key];
          h += '<tr><td><code>'+esc(mp.cport)+'</code></td><td><code>'+mp.host+'</code></td>'
            + '<td>'+(isOpen ? '<span class="badge text-bg-success">otwarty</span>' : '<span class="badge text-bg-secondary">zamknięty</span>')+'</td><td class="text-end">';
          if (isOpen){
            h += '<button type="button" class="btn btn-sm btn-outline-warning py-0" data-port-close="'+openMap[key].id+'" title="Zgłoś zamknięcie"><i class="bi bi-lock me-1" aria-hidden="true"></i>Wniosek o zamknięcie</button>';
          } else {
            h += '<button type="button" class="btn btn-sm btn-outline-primary py-0" data-port-open="'+mp.host+'" data-proto="'+proto+'"><i class="bi bi-send me-1" aria-hidden="true"></i>Zgłoś otwarcie</button>';
          }
          h += '</td></tr>';
        }
        h += '</tbody></table></div>';
      } else {
        h += '<div class="alert alert-light border small py-2">Maszyna nie zgłasza aktualnie żadnych mapowań portów (może być zatrzymana lub nieposiadać konfiguracji portów). Możesz mimo to złożyć wniosek wpisując numer portu ręcznie.</div>';
      }

      // Otwarte porty (istniejące)
      if (d.open && d.open.length) {
        h += '<hr><p class="small fw-semibold mb-1">Aktualnie otwarte porty:</p>'
          + '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Port</th><th>Proto</th><th>Opis</th><th></th></tr></thead><tbody>';
        for (const p of d.open){
          h += '<tr><td class="fw-semibold font-monospace">'+p.host_port+'</td><td class="text-uppercase small">'+esc(p.proto)+'</td><td class="small text-muted">'+esc(p.note||'')+'</td><td class="text-end">'
            + '<button type="button" class="btn btn-sm btn-outline-warning py-0" data-port-close="'+p.id+'" title="Zgłoś zamknięcie"><i class="bi bi-lock me-1" aria-hidden="true"></i>Wniosek</button></td></tr>';
        }
        h += '</tbody></table></div>';
      }

      // Historia wniosków kursanta
      if (d.requests && d.requests.length) {
        const statusLabel = {pending:'Oczekuje',approved:'Zatwierdzone',rejected:'Odrzucone'};
        const statusBadge = {pending:'bg-warning text-dark',approved:'bg-success',rejected:'bg-danger'};
        const actionLabel = {open:'otwarcie','close':'zamknięcie'};
        h += '<hr><p class="small fw-semibold mb-1">Moje wnioski:</p>'
          + '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light">'
          + '<tr><th>Port</th><th>Akcja</th><th>Status</th><th>Data</th></tr></thead><tbody>';
        for (const r of d.requests) {
          const badge = '<span class="badge '+(statusBadge[r.status]||'bg-secondary')+'">'+esc(statusLabel[r.status]||r.status)+'</span>';
          const reason = r.status === 'rejected' && r.reject_reason
            ? ' <span class="text-muted small">— '+esc(r.reject_reason)+'</span>' : '';
          const dt = r.created_at ? r.created_at.slice(0,16) : '';
          h += '<tr>'
            + '<td class="fw-semibold font-monospace">'+esc(String(r.host_port))+'/'+esc(r.proto)+'</td>'
            + '<td class="small">'+esc(actionLabel[r.action]||r.action)+'</td>'
            + '<td>'+badge+reason+'</td>'
            + '<td class="small text-muted text-nowrap">'+esc(dt)+'</td>'
            + '</tr>';
        }
        h += '</tbody></table></div>';
      }

      portsBody().innerHTML = h;

      // Obsługa przycisku ręcznego zgłoszenia
      document.getElementById('port-manual-submit')?.addEventListener('click', async () => {
        const nr = parseInt(document.getElementById('port-manual-nr').value, 10);
        const proto = document.getElementById('port-manual-proto').value;
        const note  = document.getElementById('port-manual-note').value.trim();
        if (!nr || nr < 1 || nr > 65535) { alert('Podaj prawidłowy numer portu (1–65535).'); return; }
        const r = await api('port_request_open', {id: portsForId, host_port: nr, proto, note});
        if (r.ok) { document.getElementById('port-manual-nr').value = ''; document.getElementById('port-manual-note').value = ''; }
        showPortMsg(r.ok ? 'success' : 'danger', r.msg || (r.ok ? 'Wniosek złożony.' : 'Błąd.'));
        if (r.ok) { const fresh = await api('ports', {id: portsForId}); if (fresh.ok) renderPorts(fresh); }
      });
    }

    function showPortMsg(type, msg) {
      const b = portsBody();
      const div = document.createElement('div');
      div.className = 'alert alert-'+type+' py-2 small mt-2';
      div.textContent = msg;
      b.prepend(div);
      setTimeout(() => div.remove(), 6000);
    }
    async function loadPorts(id){
      portsForId = id;
      portsBody().innerHTML = '<div class="text-body-secondary py-3 text-center">Ładowanie…</div>';
      if (window.bootstrap) new bootstrap.Modal(document.getElementById('vlabPortsModal')).show();
      const d = await api('ports', {id});
      if (d.ok) renderPorts(d); else portsBody().innerHTML = '<div class="alert alert-danger py-2 small mb-0">'+esc(d.msg||'Błąd.')+'</div>';
    }

    function render(d){
      if (d.disabled){
        box.innerHTML = '<div class="alert alert-warning d-flex align-items-start gap-2" role="alert">'
          + '<i class="bi bi-pause-circle-fill mt-1" aria-hidden="true"></i>'
          + '<span style="white-space:pre-wrap">'+esc(d.notice || 'Moduł VLab jest chwilowo niedostępny.')+'</span></div>';
        return;
      }
      if (!d.enabled){
        box.innerHTML = '<div class="alert alert-warning d-flex align-items-center gap-2" role="alert">'
          + '<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>'
          + '<span>Moduł VLab nie został jeszcze skonfigurowany przez administratora.</span></div>';
        return;
      }
      let html = '<h2 class="h6 fw-bold d-flex align-items-center mb-2"><i class="bi bi-pc-display me-2" aria-hidden="true"></i>'
        + 'Moje maszyny <span class="badge text-bg-secondary ms-2">'+d.count+' / '+d.max+'</span></h2>';

      if (!d.machines.length){
        html += '<div class="border border-secondary-subtle rounded p-4 text-center text-body-secondary mb-4">'
          + 'Nie masz jeszcze żadnej maszyny. Utwórz ją z szablonu poniżej.</div>';
      } else {
        for (const m of d.machines){
          const [col,lbl] = stMap[m.status] || ['secondary', m.status];
          html += '<div class="card mb-3"><div class="card-body">'
            + '<div class="d-flex align-items-center gap-2 mb-2">'
            + '<span class="fw-semibold">'+esc(m.label)+'</span>'
            + '<span class="badge text-bg-'+col+'">'+lbl+'</span>'
            + (m.force_pw ? '<span class="badge text-bg-warning"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>zmień hasło przy logowaniu</span>' : '')
            + '</div>';
          if (m.status === 'error' && m.error){
            html += '<p class="text-danger small mb-2">'+esc(m.error)+'</p>';
          }
          // Efektywne dane SSH: konto hosta (preferowane) lub fallback na bezpośredni port kontenera.
          const sshUser = m.host_user || m.ssh_user || '';
          const sshPort = m.host_user ? m.host_port : (m.ssh_port || 0);
          const sshOk   = m.status === 'running' && m.ssh_host && sshUser && sshPort;
          if (sshOk){
            const c = lastCreds[m.id]; // pełne dane logowania, tylko bezpośrednio po utworzeniu
            html += '<div class="bg-body-tertiary border rounded p-2 mb-2 small font-monospace">'
              + '<div class="fw-semibold mb-1" style="font-family:inherit"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Dane logowania (Docker)</div>'
              + '<div><span class="text-body-secondary">SSH:</span> ssh '+esc(sshUser)+'@'+esc(m.ssh_host)+' -p '+sshPort+'</div>';
            if (c){
              html += '<div class="mt-1" style="font-family:inherit"><button type="button" class="btn btn-sm btn-outline-primary py-0" data-show-creds="'+m.id+'"><i class="bi bi-key me-1" aria-hidden="true"></i>Pokaż dane logowania</button>'
                + '<span class="text-body-secondary ms-2"><i class="bi bi-envelope me-1" aria-hidden="true"></i>wysłane też e-mailem</span></div>';
            } else if (m.host_user){
              html += '<div class="mt-1 text-body-secondary" style="font-family:inherit"><i class="bi bi-envelope me-1" aria-hidden="true"></i>Dane logowania wysłaliśmy e-mailem przy tworzeniu maszyny.</div>';
            } else {
              html += '<div class="mt-1 text-body-secondary" style="font-family:inherit"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Dane logowania zgodne z obrazem maszyny (hasło lub klucz SSH).</div>';
            }
            html += '</div>';
          }
          html += '<div class="d-flex flex-wrap gap-2">';
          if (m.ttyd_url && m.status === 'running'){
            html += '<a class="btn btn-primary btn-sm" href="'+esc(m.ttyd_url)+'" target="_blank" rel="noopener"><i class="bi bi-terminal me-1" aria-hidden="true"></i>Otwórz terminal</a>';
          }
          if (sshOk){
            html += '<a class="btn btn-outline-primary btn-sm" href="ssh://'+esc(sshUser)+'@'+esc(m.ssh_host)+':'+sshPort+'" title="Otwiera klienta SSH zainstalowanego w systemie"><i class="bi bi-hdd-network me-1" aria-hidden="true"></i>Połącz po SSH</a>';
          }
          if (d.ports_self && m.status === 'running'){
            html += '<button type="button" class="btn btn-outline-primary btn-sm" data-ports="'+m.id+'"><i class="bi bi-hdd-network me-1" aria-hidden="true"></i>Porty</button>';
          }
          if (m.status === 'running'){
            html += '<button type="button" class="btn btn-outline-secondary btn-sm" data-act="stop" data-id="'+m.id+'"><i class="bi bi-stop-circle me-1" aria-hidden="true"></i>Zatrzymaj</button>';
            html += '<button type="button" class="btn btn-outline-secondary btn-sm" data-act="restart" data-id="'+m.id+'"><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Restart</button>';
          } else if (m.status === 'stopped'){
            html += '<button type="button" class="btn btn-outline-success btn-sm" data-act="start" data-id="'+m.id+'"><i class="bi bi-play-circle me-1" aria-hidden="true"></i>Uruchom</button>';
          }
          html += '<button type="button" class="btn btn-outline-danger btn-sm" data-act="remove" data-id="'+m.id+'"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń</button>';
          html += '</div></div></div>';
        }
      }

      html += '<h2 class="h6 fw-bold d-flex align-items-center mt-4 mb-2"><i class="bi bi-collection me-2" aria-hidden="true"></i>Utwórz nową maszynę</h2>';
      const canCreate = d.count < d.max;
      if (!canCreate){
        html += '<p class="text-body-secondary small">Osiągnięto limit maszyn ('+d.max+'). Usuń istniejącą, aby utworzyć nową.</p>';
      }
      if (!d.templates.length){
        html += '<div class="border border-secondary-subtle rounded p-4 text-center text-body-secondary">Brak dostępnych szablonów.</div>';
      } else {
        html += '<div class="row g-3">';
        for (const t of d.templates){
          html += '<div class="col-12 col-md-6 col-lg-4"><div class="card h-100"><div class="card-body d-flex flex-column">'
            + '<h3 class="h6 mb-1">'+esc(t.name)+'</h3>'
            + '<p class="text-body-secondary small flex-grow-1">'+esc(t.description||'')+'</p>'
            + '<button type="button" class="btn btn-primary btn-sm" data-create="'+t.id+'" data-tpl-name="'+esc(t.name)+'" data-tpl-ports="'+esc(t.default_ports||'')+'" '+(canCreate?'':'disabled')+'>'
            + '<i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Utwórz</button>'
            + '</div></div></div>';
        }
        html += '</div>';
      }
      box.innerHTML = html;
    }

    async function reload(){ const d = await api('list'); if (d.ok) render(d); }

    // Kopiowanie w obrębie modalu danych logowania
    document.getElementById('vlabCredsModal').addEventListener('click', (e)=>{
      const cp = e.target.closest('[data-copy]');
      if (cp){ navigator.clipboard?.writeText(cp.dataset.copy); const h=cp.innerHTML; cp.innerHTML='<i class="bi bi-check2" aria-hidden="true"></i>'; setTimeout(()=>{cp.innerHTML=h;},1200); }
    });

    // Otwieranie/zamykanie portów w obrębie modalu portów
    document.getElementById('vlabPortsModal').addEventListener('click', async (e)=>{
      const openBtn = e.target.closest('[data-port-open]');
      const closeBtn = e.target.closest('[data-port-close]');
      if (!openBtn && !closeBtn) return;
      const btn = openBtn || closeBtn;
      btn.disabled = true; const orig = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-hourglass-split" aria-hidden="true"></i>';
      let r;
      if (openBtn) r = await api('port_open', {id: portsForId, host_port: openBtn.dataset.portOpen, proto: openBtn.dataset.proto});
      else         r = await api('port_close', {id: portsForId, port_id: closeBtn.dataset.portClose});
      btn.disabled = false; btn.innerHTML = orig;
      showPortMsg(r.ok ? 'success' : 'danger', r.msg || (r.ok ? 'Wniosek złożony.' : 'Błąd.'));
      if (r.ok) { const fresh = await api('ports', {id: portsForId}); if (fresh.ok) renderPorts(fresh); }
    });

    // Utworzenie maszyny z okienka (nazwa + porty)
    document.getElementById('vlabCreateForm').addEventListener('submit', async (e)=>{
      e.preventDefault();
      const btn = document.getElementById('vc-submit');
      const tpl   = document.getElementById('vc-tpl').value;
      const label = document.getElementById('vc-label').value.trim() || 'lab';
      const ports = document.getElementById('vc-ports').value.trim();
      btn.disabled = true; const orig = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Tworzę…';
      const r = await api('create', {template_id: tpl, label, ports});
      btn.disabled = false; btn.innerHTML = orig;
      if (r.id && r.creds) lastCreds[r.id] = r.creds;
      if (window.bootstrap) bootstrap.Modal.getInstance(document.getElementById('vlabCreateModal'))?.hide();
      if (!r.ok) alert(r.msg || 'Błąd.');
      if (r.data) render(r.data); else reload();
      if (r.ok && r.creds) showCredsModal(r.creds);
    });

    box.addEventListener('click', async (e)=>{
      const copyBtn = e.target.closest('[data-copy]');
      if (copyBtn){ navigator.clipboard?.writeText(copyBtn.dataset.copy); copyBtn.innerHTML='<i class="bi bi-check2" aria-hidden="true"></i>'; return; }

      const showBtn = e.target.closest('[data-show-creds]');
      if (showBtn){ showCredsModal(lastCreds[showBtn.dataset.showCreds]); return; }

      const portsBtn = e.target.closest('[data-ports]');
      if (portsBtn){ loadPorts(portsBtn.dataset.ports); return; }

      const createBtn = e.target.closest('[data-create]');
      if (createBtn){
        // Otwórz okienko: nazwa + porty do wystawienia (podpowiedź z szablonu)
        document.getElementById('vc-tpl').value     = createBtn.dataset.create;
        document.getElementById('vc-tplname').textContent = createBtn.dataset.tplName || '';
        document.getElementById('vc-label').value    = 'lab';
        document.getElementById('vc-ports').value    = createBtn.dataset.tplPorts || '';
        if (window.bootstrap) new bootstrap.Modal(document.getElementById('vlabCreateModal')).show();
        return;
      }

      const actBtn = e.target.closest('[data-act]');
      if (actBtn){
        const act = actBtn.dataset.act;
        if (act === 'remove' && !confirm('Usunąć maszynę? Tej operacji nie można cofnąć.')) return;
        actBtn.disabled = true;
        const r = await api(act, {id: actBtn.dataset.id});
        if (!r.ok) alert(r.msg || 'Błąd.');
        if (r.data) render(r.data); else reload();
      }
    });

    reload();
  })();
  </script>

  <?php endif; // vlab_term_ok ?>

  <?php
  // Umowy VLab kursanta
  require_once dirname(dirname(dirname(__DIR__))) . '/includes/vlab_contracts.php';
  vlab_contracts_migrate();
  $my_vlab_contracts = vlab_contracts_for_client((int)$student['client_id']);
  if ($my_vlab_contracts): ?>
  <div class="card border-0 shadow-sm mt-4">
    <div class="card-header d-flex align-items-center gap-2 py-2">
      <i class="bi bi-file-earmark-lock text-primary" aria-hidden="true"></i>
      <span class="fw-semibold small">Moje umowy o dostęp do VLab</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 small">
        <thead class="table-light">
          <tr><th>Numer</th><th>Data zawarcia</th><th>Ważna do</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($my_vlab_contracts as $mvc):
            $mst = VLAB_CONTRACT_STATUSES[$mvc['status']] ?? ['label' => $mvc['status'], 'class' => 'secondary'];
          ?>
          <tr>
            <td class="font-monospace"><?= h($mvc['numer_umowy']) ?></td>
            <td><?= $mvc['data_zawarcia'] ? h(date('d.m.Y', strtotime($mvc['data_zawarcia']))) : '—' ?></td>
            <td><?= $mvc['data_waznosci'] ? h(date('d.m.Y', strtotime($mvc['data_waznosci']))) : '<span class="text-body-secondary">bezterm.</span>' ?></td>
            <td><span class="badge text-bg-<?= $mst['class'] ?>"><?= h($mst['label']) ?></span></td>
            <td>
              <a href="vlab_contract_pdf.php?id=<?= (int)$mvc['id'] ?>"
                 class="btn btn-sm btn-outline-secondary py-0" title="Pobierz PDF umowy">
                <i class="bi bi-filetype-pdf" aria-hidden="true"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

<?php elseif ($tab === 'plan'): ?>
  <h1 class="h5 fw-bold mb-3"><i class="bi bi-list-check text-primary me-2" aria-hidden="true"></i>Plan nauczania</h1>
  <p class="text-body-secondary small mb-3">Program kursu i postęp realizacji. Punkty oznaczone jako „zrealizowane” mają już odbytą lekcję.</p>
  <?php if (!$courses): ?>
    <div class="alert alert-secondary">Nie jesteś zapisany na żaden kurs.</div>
  <?php else: foreach ($courses as $c):
    $cid_p = (int)$c['course_id'];
    $plan  = k30_ti_curriculum_list($cid_p, true);
    if (!$plan) continue;
    // Status realizacji punktu: najlepsza z powiązanych lekcji (held > planned > brak)
    $cov = [];
    foreach (db_all(
        "SELECT sc.curriculum_id AS cidp, s.status AS st
         FROM k30_ti_session_curriculum sc JOIN k30_ti_sessions s ON s.id=sc.session_id
         WHERE s.course_id=?", [$cid_p]) as $row) {
        $k = (int)$row['cidp']; $st = $row['st'];
        $rank = $st === 'held' ? 2 : ($st === 'planned' ? 1 : 0);
        if (!isset($cov[$k]) || $rank > $cov[$k]) $cov[$k] = $rank;
    }
    $done = 0; foreach ($plan as $p) { if (($cov[(int)$p['id']] ?? 0) === 2) $done++; }
    $pct  = count($plan) > 0 ? (int)round($done * 100 / count($plan)) : 0;
  ?>
  <div class="card bg-body-tertiary border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex align-items-center flex-wrap gap-2">
      <span><i class="bi bi-mortarboard me-2" aria-hidden="true"></i><?= h($c['course_name']) ?></span>
      <span class="badge text-bg-secondary ms-auto"><?= $done ?>/<?= count($plan) ?> zrealizowane</span>
    </div>
    <div class="card-body py-2 border-bottom">
      <div class="progress" role="progressbar" aria-label="Postęp realizacji planu: <?= h($c['course_name']) ?>"
           aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" style="height:.6rem">
        <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : '' ?>" style="width:<?= $pct ?>%"></div>
      </div>
    </div>
    <ul class="list-group list-group-flush">
      <?php $last_sec = null; foreach ($plan as $p):
        $sec = (string)$p['section'];
        if ($sec !== $last_sec): $last_sec = $sec; ?>
        <li class="list-group-item bg-transparent fw-semibold small text-uppercase text-body-secondary py-1">
          <i class="bi bi-folder2 me-1" aria-hidden="true"></i><?= $sec !== '' ? h($sec) : 'Program' ?>
        </li>
      <?php endif;
        $rank = $cov[(int)$p['id']] ?? 0;
        [$badge_cls, $badge_txt, $badge_ico] = $rank === 2
          ? ['text-bg-success', 'Zrealizowane', 'bi-check-circle-fill']
          : ($rank === 1 ? ['text-bg-info', 'Zaplanowane', 'bi-calendar-event']
                         : ['text-bg-secondary', 'W planie', 'bi-hourglass']);
      ?>
      <li class="list-group-item bg-transparent d-flex align-items-start gap-2">
        <span class="badge <?= $badge_cls ?> flex-shrink-0 mt-1"><i class="bi <?= $badge_ico ?> me-1" aria-hidden="true"></i><?= $badge_txt ?></span>
        <span>
          <span class="fw-semibold"><?= h($p['title']) ?></span>
          <?php if (trim((string)$p['description']) !== ''): ?>
          <span class="d-block text-body-secondary small"><?= nl2br(h($p['description'])) ?></span>
          <?php endif; ?>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

<?php elseif ($tab === 'testy'): ?>
  <h1 class="h5 fw-bold mb-3"><i class="bi bi-card-checklist text-primary me-2" aria-hidden="true"></i>Testy</h1>
  <?php if (($_GET['err'] ?? '') === 'unavailable'): ?>
  <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Wybrany test jest niedostępny lub został ukryty.</div>
  <?php endif; ?>
  <?php
    $any_test = false;
    foreach ($courses as $c):
      $cid_t = (int)$c['course_id'];
      $clist = k30_ti_tests_list($cid_t, true);
      if (!$clist) continue;
      $any_test = true;
  ?>
  <div class="card bg-body-tertiary border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="bi bi-mortarboard me-2" aria-hidden="true"></i><?= h($c['course_name']) ?></div>
    <ul class="list-group list-group-flush">
      <?php foreach ($clist as $t):
        $best = k30_ti_test_best_attempt((int)$t['id'], (int)$student['client_id']);
        $open = db_one("SELECT id FROM k30_ti_test_attempts WHERE test_id=? AND client_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1", [(int)$t['id'], (int)$student['client_id']]);
        $nq   = (int)$t['n_questions'];
      ?>
      <li class="list-group-item bg-transparent d-flex align-items-center flex-wrap gap-2">
        <div class="me-auto">
          <span class="fw-semibold"><?= h($t['title']) ?></span>
          <?php if (trim((string)$t['description']) !== ''): ?><span class="d-block text-body-secondary small"><?= h($t['description']) ?></span><?php endif; ?>
          <span class="small text-body-secondary"><?= $nq ?> pytań<?php if ((int)$t['time_limit_min']>0): ?> · <?= (int)$t['time_limit_min'] ?> min<?php endif; ?><?php if ((int)$t['pass_pct']>0): ?> · próg <?= (int)$t['pass_pct'] ?>%<?php endif; ?></span>
        </div>
        <?php
          if ($best) {
            $mx = (float)$best['max_score']; $pct = $mx>0 ? round(100*(float)$best['score']/$mx) : 0;
            $passed = (int)$t['pass_pct']===0 || $pct >= (int)$t['pass_pct'];
            if ($best['status']==='submitted' && (int)$best['needs_review']===1) {
              echo '<span class="badge text-bg-info"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Czeka na ocenę</span>';
            } else {
              echo '<span class="badge '.($passed?'text-bg-success':'text-bg-secondary').'">Wynik: '.$pct.'%</span>';
            }
          }
        ?>
        <?php if ($nq > 0): ?>
        <a href="test.php?test=<?= (int)$t['id'] ?>" class="btn btn-sm btn-primary">
          <i class="bi bi-<?= $open ? 'play-fill' : ($best ? 'arrow-repeat' : 'pencil-square') ?> me-1" aria-hidden="true"></i><?= $open ? 'Kontynuuj' : ($best ? 'Rozwiąż ponownie' : 'Rozwiąż') ?>
        </a>
        <?php else: ?><span class="badge text-bg-light text-dark border">wkrótce</span><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endforeach; ?>
  <?php if (!$any_test): ?><div class="alert alert-secondary">Brak udostępnionych testów.</div><?php endif; ?>

<?php elseif ($tab === 'wiadomosci'):
  // Oznacz wiadomości od prowadzącego jako przeczytane przy wejściu na zakładkę
  ti_msg_mark_read_for_student((int)$student['id']);
  $messages = ti_msg_list_for_student((int)$student['id']);
?>

  <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-1">
    <i class="bi bi-envelope text-primary" aria-hidden="true"></i>Wiadomości
  </h1>
  <p class="text-body-secondary small mb-3">Wiadomości od prowadzącego. Możesz odpowiedzieć — odpowiedź trafi do prowadzącego.</p>

  <?php if (($_GET['sent'] ?? '') === '1'): ?>
  <div class="alert alert-success py-2 small" role="alert"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Wiadomość wysłana.</div>
  <?php endif; ?>

  <section class="card" aria-labelledby="msg-thread-h">
    <div class="card-body">
      <h2 id="msg-thread-h" class="h6 fw-bold mb-3"><i class="bi bi-chat-left-text me-2" aria-hidden="true"></i>Twój wątek</h2>
      <?php if (!$messages): ?>
        <div class="border border-secondary-subtle rounded p-4 text-center text-body-secondary">
          Brak wiadomości. Gdy prowadzący coś napisze, pojawi się tutaj.
        </div>
      <?php else: ?>
        <div class="d-flex flex-column gap-2 mb-3" style="max-height:60vh;overflow-y:auto">
          <?php foreach ($messages as $m):
            $mine = ($m['sender'] ?? '') === 'student';
            $ts   = $m['created_at'] ? date('d.m.Y H:i', strtotime($m['created_at'])) : '';
          ?>
          <div class="d-flex <?= $mine ? 'justify-content-end' : 'justify-content-start' ?>">
            <div class="p-2 px-3 rounded-3 <?= $mine ? 'bg-primary text-white' : 'bg-body-tertiary border' ?>" style="max-width:85%">
              <div class="small fw-semibold mb-1 <?= $mine ? 'text-white-50' : 'text-body-secondary' ?>">
                <?= $mine ? 'Ty' : h($m['sender_name'] !== '' ? $m['sender_name'] : 'Prowadzący') ?>
                <span class="ms-2 fw-normal"><?= h($ts) ?></span>
              </div>
              <?php if (!$mine && trim((string)$m['subject']) !== ''): ?>
              <div class="fw-bold mb-1"><?= h($m['subject']) ?></div>
              <?php endif; ?>
              <div style="white-space:pre-wrap;word-break:break-word"><?= nl2br(h($m['body'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" class="mt-2">
        <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
        <input type="hidden" name="_op" value="msg_reply">
        <label class="form-label small fw-semibold" for="msg-body">Napisz wiadomość do prowadzącego</label>
        <textarea class="form-control mb-2" id="msg-body" name="body" rows="3" maxlength="4000" required placeholder="Treść wiadomości…"></textarea>
        <button class="btn btn-primary btn-sm"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij</button>
      </form>
      <p class="text-body-secondary small mb-0 mt-3">
        <i class="bi bi-gear me-1" aria-hidden="true"></i>Powiadomienia o nowych wiadomościach ustawisz w zakładce
        <a href="?tab=ustawienia">Ustawienia</a>.
      </p>
    </div>
  </section>

<?php elseif ($tab === 'ustawienia'): ?>

  <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-1">
    <i class="bi bi-gear text-primary" aria-hidden="true"></i>Ustawienia i preferencje
  </h1>
  <p class="text-body-secondary small mb-3">Powiadomienia oraz synchronizacja lekcji z Twoim kalendarzem.</p>

  <?php if (($_GET['sms'] ?? '') === 'on'): ?>
  <div class="alert alert-success py-2 small" role="alert"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Włączono powiadomienia SMS o zajęciach.</div>
  <?php elseif (($_GET['sms'] ?? '') === 'off'): ?>
  <div class="alert alert-secondary py-2 small" role="alert">Wyłączono powiadomienia SMS o zajęciach.</div>
  <?php elseif (($_GET['prefs'] ?? '') === '1'): ?>
  <div class="alert alert-success py-2 small" role="alert"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Ustawienia powiadomień zapisane.</div>
  <?php elseif (($_GET['cal'] ?? '') === 'reset'): ?>
  <div class="alert alert-success py-2 small" role="alert"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Adres kalendarza został zmieniony. Poprzedni link przestał działać — zaktualizuj subskrypcję w swoim kalendarzu.</div>
  <?php elseif (($_GET['pwok'] ?? '') === '1'): ?>
  <div class="alert alert-success py-2 small" role="alert"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Hasło zostało zmienione.</div>
  <?php elseif (($_GET['phones'] ?? '') === '1'): ?>
  <div class="alert alert-success py-2 small" role="alert"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Numery do powiadomień SMS zapisane.</div>
  <?php endif; ?>
  <?php if (($_GET['pwerr'] ?? '') !== ''): ?>
  <div class="alert alert-danger py-2 small" role="alert"><i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i><?= h((string)$_GET['pwerr']) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- ── Powiadomienia o wiadomościach (e-mail / SMS) ──────────────────────── -->
    <div class="col-12 col-lg-6">
      <section class="card h-100" aria-labelledby="msg-prefs-h">
        <div class="card-body">
          <h2 id="msg-prefs-h" class="h6 fw-bold mb-2"><i class="bi bi-bell me-2 text-info" aria-hidden="true"></i>Powiadomienia o wiadomościach</h2>
          <p class="text-body-secondary small mb-3">Wybierz, jak chcesz być informowany o nowych wiadomościach od prowadzącego.</p>
          <form method="post" id="msgPrefsForm">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op" value="msg_prefs">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" role="switch" id="prefEmail" name="email" value="1"
                     <?= $msg_pref_email ? 'checked' : '' ?> onchange="document.getElementById('msgPrefsForm').submit()">
              <label class="form-check-label" for="prefEmail"><i class="bi bi-envelope me-1" aria-hidden="true"></i>E-mail</label>
            </div>
            <?php if ($msg_pref_email && $msg_email_addr === ''): ?>
            <p class="text-warning small ms-4 mb-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Brak adresu e-mail w Twoich danych.</p>
            <?php elseif ($msg_email_addr !== ''): ?>
            <p class="text-body-secondary small ms-4 mb-2" style="margin-top:-4px"><?= h($msg_email_addr) ?></p>
            <?php endif; ?>
            <div class="form-check form-switch mb-1">
              <input class="form-check-input" type="checkbox" role="switch" id="prefSms" name="sms" value="1"
                     <?= $msg_pref_sms ? 'checked' : '' ?> <?= $sms_global_on ? '' : 'disabled' ?>
                     onchange="document.getElementById('msgPrefsForm').submit()">
              <label class="form-check-label" for="prefSms"><i class="bi bi-chat-dots me-1" aria-hidden="true"></i>SMS</label>
            </div>
            <?php if (!$sms_global_on): ?>
            <p class="text-body-secondary small ms-4 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Powiadomienia SMS są obecnie niedostępne.</p>
            <?php elseif ($sms_phone === ''): ?>
            <p class="text-warning small ms-4 mb-0"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Brak numeru telefonu w Twoich danych.</p>
            <?php else: ?>
            <p class="text-body-secondary small ms-4 mb-0" style="margin-top:-2px"><i class="bi bi-telephone me-1" aria-hidden="true"></i>Numer: <?= h(preg_replace('/.(?=.{2})/u', '•', $sms_phone)) ?></p>
            <?php endif; ?>
          </form>
        </div>
      </section>
    </div>

    <!-- ── Powiadomienia o zmianach w dydaktyce / eLearningu ─────────────────── -->
    <div class="col-12 col-lg-6">
      <section class="card h-100" aria-labelledby="dyd-prefs-h">
        <div class="card-body">
          <h2 id="dyd-prefs-h" class="h6 fw-bold mb-2"><i class="bi bi-mortarboard me-2 text-info" aria-hidden="true"></i>Powiadomienia o materiałach i zadaniach</h2>
          <p class="text-body-secondary small mb-3">Daj znać, jak chcesz być informowany o nowych lub zmienionych materiałach i zadaniach domowych.</p>
          <form method="post" id="dydPrefsForm">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op" value="dyd_prefs">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" role="switch" id="dydEmail" name="email" value="1"
                     <?= $dyd_pref_email ? 'checked' : '' ?> onchange="document.getElementById('dydPrefsForm').submit()">
              <label class="form-check-label" for="dydEmail"><i class="bi bi-envelope me-1" aria-hidden="true"></i>E-mail</label>
            </div>
            <?php if ($dyd_pref_email && $msg_email_addr === ''): ?>
            <p class="text-warning small ms-4 mb-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Brak adresu e-mail w Twoich danych.</p>
            <?php elseif ($msg_email_addr !== ''): ?>
            <p class="text-body-secondary small ms-4 mb-2" style="margin-top:-4px"><?= h($msg_email_addr) ?></p>
            <?php endif; ?>
            <div class="form-check form-switch mb-1">
              <input class="form-check-input" type="checkbox" role="switch" id="dydSms" name="sms" value="1"
                     <?= $dyd_pref_sms ? 'checked' : '' ?> <?= $sms_global_on ? '' : 'disabled' ?>
                     onchange="document.getElementById('dydPrefsForm').submit()">
              <label class="form-check-label" for="dydSms"><i class="bi bi-chat-dots me-1" aria-hidden="true"></i>SMS</label>
            </div>
            <?php if (!$sms_global_on): ?>
            <p class="text-body-secondary small ms-4 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Powiadomienia SMS są obecnie niedostępne.</p>
            <?php endif; ?>
          </form>
        </div>
      </section>
    </div>

    <!-- ── Powiadomienia SMS o zajęciach ─────────────────────────────────────── -->
    <div class="col-12 col-lg-6">
      <section class="card h-100" aria-labelledby="sms-heading">
        <div class="card-body">
          <h2 id="sms-heading" class="h6 fw-bold mb-2"><i class="bi bi-chat-dots text-info me-2" aria-hidden="true"></i>Powiadomienia SMS o zajęciach</h2>
          <?php if (!$sms_global_on): ?>
          <p class="text-body-secondary small mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Powiadomienia SMS są obecnie niedostępne.</p>
          <?php else: ?>
          <p class="text-body-secondary small mb-2">Otrzymasz krótki SMS, gdy prowadzący doda Ci nowe zajęcia.</p>
          <form method="post" id="smsPrefForm">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op"     value="toggle_sms_lessons">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="smsToggle" name="enabled" value="1"
                     <?= $sms_pref ? 'checked' : '' ?>
                     onchange="document.getElementById('smsPrefForm').submit()">
              <label class="form-check-label" for="smsToggle">Chcę dostawać SMS o nowych zajęciach</label>
            </div>
          </form>
            <?php if ($sms_phone === ''): ?>
          <p class="text-warning small mb-0 mt-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Brak numeru telefonu w Twoich danych — SMS nie dotrą, dopóki administrator go nie uzupełni.</p>
            <?php else: ?>
          <p class="text-body-secondary small mb-0 mt-2"><i class="bi bi-telephone me-1" aria-hidden="true"></i>Numer: <?= h(preg_replace('/.(?=.{2})/u', '•', $sms_phone)) ?></p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <!-- ── Alias logowania ───────────────────────────────────────────────────── -->
    <div class="col-12 col-lg-6">
      <section class="card h-100" aria-labelledby="alias-heading">
        <div class="card-body">
          <h2 id="alias-heading" class="h6 fw-bold mb-2"><i class="bi bi-person-badge me-2 text-info" aria-hidden="true"></i>Własny alias logowania</h2>
          <p class="text-body-secondary small mb-3">
            Możesz ustawić własną, łatwą do zapamiętania nazwę logowania (alias) — zamiast przydzielonego loginu systemowego.
            Alias musi być unikalny (3–30 znaków: litery a–z, cyfry, kropki, myślniki, podkreślenia).
          </p>
          <?php
            $cur_alias = (string)($account['login_alias'] ?? '');
            $alias_msg = $_GET['alias'] ?? '';
            $alias_err = rawurldecode((string)($_GET['aliaserr'] ?? ''));
          ?>
          <?php if ($alias_msg === 'ok'): ?>
          <div class="alert alert-success py-2 small" role="alert"><i class="bi bi-check-circle me-1"></i>Alias logowania zapisany.</div>
          <?php elseif ($alias_msg === 'removed'): ?>
          <div class="alert alert-secondary py-2 small" role="alert">Alias logowania usunięty — logujesz się ponownie przydzielonym loginem.</div>
          <?php endif; ?>
          <?php if ($alias_err !== ''): ?>
          <div class="alert alert-danger py-2 small" role="alert"><i class="bi bi-exclamation-circle me-1"></i><?= h($alias_err) ?></div>
          <?php endif; ?>
          <?php if ($cur_alias !== ''): ?>
          <p class="small mb-2">
            Aktywny alias: <code class="text-info fw-bold"><?= h($cur_alias) ?></code>
          </p>
          <?php endif; ?>
          <form method="post" autocomplete="off">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op" value="set_alias">
            <div class="mb-2">
              <label class="form-label small" for="alias-input">Alias (pozostaw puste, by usunąć)</label>
              <input type="text" class="form-control form-control-sm font-monospace" id="alias-input" name="alias"
                     value="<?= h($cur_alias) ?>" maxlength="30" autocomplete="off"
                     placeholder="np. jan.kowalski" pattern="[a-z0-9][a-z0-9._\-]{1,28}[a-z0-9]"
                     aria-describedby="alias-hint">
              <div class="form-text" id="alias-hint">Twój login systemowy: <code><?= h((string)($account['login'] ?? '')) ?></code> — nadal działa.</div>
            </div>
            <button class="btn btn-primary btn-sm"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz alias</button>
          </form>
        </div>
      </section>
    </div>

    <!-- ── Zmiana hasła ──────────────────────────────────────────────────────── -->
    <div class="col-12 col-lg-6">
      <section class="card h-100" aria-labelledby="pw-heading">
        <div class="card-body">
          <h2 id="pw-heading" class="h6 fw-bold mb-2"><i class="bi bi-shield-lock me-2 text-info" aria-hidden="true"></i>Zmień hasło</h2>
          <p class="text-body-secondary small mb-3">Ustaw własne hasło do panelu kursanta.</p>
          <form method="post" autocomplete="off">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op" value="change_password">
            <div class="mb-2">
              <label class="form-label small" for="cps-cur">Aktualne hasło</label>
              <input type="password" class="form-control form-control-sm" id="cps-cur" name="current" required autocomplete="current-password">
            </div>
            <div class="mb-2">
              <label class="form-label small" for="cps-new">Nowe hasło</label>
              <input type="password" class="form-control form-control-sm" id="cps-new" name="new" required minlength="8" autocomplete="new-password" placeholder="min. 8 znaków">
            </div>
            <div class="mb-2">
              <label class="form-label small" for="cps-cnf">Powtórz nowe hasło</label>
              <input type="password" class="form-control form-control-sm" id="cps-cnf" name="confirm" required minlength="8" autocomplete="new-password">
            </div>
            <button class="btn btn-primary btn-sm"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz hasło</button>
          </form>
        </div>
      </section>
    </div>

    <!-- ── Dodatkowe numery do powiadomień SMS ───────────────────────────────── -->
    <div class="col-12 col-lg-6">
      <section class="card h-100" aria-labelledby="ph-heading">
        <div class="card-body">
          <h2 id="ph-heading" class="h6 fw-bold mb-2"><i class="bi bi-telephone-plus me-2 text-info" aria-hidden="true"></i>Dodatkowe numery do SMS</h2>
          <p class="text-body-secondary small mb-3">Powiadomienia SMS (o zajęciach i wiadomościach) wyślemy też na te numery — np. do rodzica lub opiekuna.</p>
          <?php if ($sms_phone !== ''): ?>
          <p class="text-body-secondary small mb-2"><i class="bi bi-telephone me-1" aria-hidden="true"></i>Numer główny: <?= h(preg_replace('/.(?=.{2})/u', '•', $sms_phone)) ?></p>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op" value="notify_phones">
            <div class="mb-2">
              <label class="form-label small" for="ph2">Drugi numer</label>
              <input type="tel" class="form-control form-control-sm" id="ph2" name="phone2" maxlength="30" value="<?= h((string)($account['notify_phone2'] ?? '')) ?>" placeholder="np. 600 700 800">
            </div>
            <div class="mb-2">
              <label class="form-label small" for="ph3">Trzeci numer</label>
              <input type="tel" class="form-control form-control-sm" id="ph3" name="phone3" maxlength="30" value="<?= h((string)($account['notify_phone3'] ?? '')) ?>" placeholder="np. 600 700 900">
            </div>
            <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz numery</button>
            <?php if (!$sms_global_on): ?>
            <p class="text-body-secondary small mb-0 mt-2"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Bramka SMS jest obecnie niedostępna.</p>
            <?php endif; ?>
          </form>
        </div>
      </section>
    </div>

    <!-- ── Synchronizacja z kalendarzem (Google / Apple / Outlook) ──────────── -->
    <div class="col-12">
      <section class="card" aria-labelledby="cal-heading">
        <div class="card-body">
          <h2 id="cal-heading" class="h6 fw-bold mb-2"><i class="bi bi-calendar-plus text-info me-2" aria-hidden="true"></i>Synchronizacja z kalendarzem</h2>
          <p class="text-body-secondary small mb-3">Dodaj swoje lekcje do Kalendarza Google, Apple lub Outlook. Kalendarz odświeża się automatycznie, gdy prowadzący doda lub zmieni terminy.</p>

          <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="<?= h($cal_gcal) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary"><i class="bi bi-google me-1" aria-hidden="true"></i>Dodaj do Google Calendar</a>
            <a href="<?= h($cal_webcal) ?>" class="btn btn-sm btn-info"><i class="bi bi-apple me-1" aria-hidden="true"></i>Subskrybuj (Apple / Outlook)</a>
            <a href="<?= h($cal_https) ?>" class="btn btn-sm btn-outline-secondary" download="lekcje.ics"><i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz plik .ics</a>
          </div>

          <label class="form-label small fw-semibold" for="cal-url">Adres kanału (do ręcznego dodania „z adresu URL")</label>
          <div class="input-group input-group-sm mb-2">
            <input type="text" class="form-control" id="cal-url" value="<?= h($cal_https) ?>" readonly aria-label="Adres kanału iCal" onclick="this.select()">
            <button type="button" class="btn btn-outline-secondary" id="cal-copy"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj</button>
          </div>

          <div class="d-flex flex-wrap align-items-center gap-2 justify-content-between">
            <p class="text-body-secondary mb-0" style="font-size:.78rem"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Adres jest prywatny — nie udostępniaj go innym. Jeśli wyciekł, zresetuj go.</p>
            <form method="post" class="m-0" onsubmit="return confirm('Zresetować adres kalendarza? Dotychczasowa subskrypcja przestanie działać i trzeba ją dodać ponownie.')">
              <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
              <input type="hidden" name="_op"     value="reset_calendar_token">
              <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Resetuj adres</button>
            </form>
          </div>
        </div>
      </section>
    </div>
  </div>

  <script>
  (function(){
    var btn = document.getElementById('cal-copy');
    var inp = document.getElementById('cal-url');
    if (!btn || !inp) return;
    btn.addEventListener('click', function(){
      inp.select();
      var done = function(){
        var html = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg me-1" aria-hidden="true"></i>Skopiowano';
        setTimeout(function(){ btn.innerHTML = html; }, 1500);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(inp.value).then(done, function(){ try { document.execCommand('copy'); done(); } catch(e){} });
      } else { try { document.execCommand('copy'); done(); } catch(e){} }
    });
  })();
  </script>

<?php elseif ($tab === 'licencje'): ?>

  <h1 class="h5 fw-bold mb-1"><i class="bi bi-key text-primary me-1" aria-hidden="true"></i>Moje licencje</h1>
  <p class="text-body-secondary small mb-3">
    Licencje na oprogramowanie (inne niż Microsoft&nbsp;365) przypisane do Ciebie. Klucze i hasła trzymaj w tajemnicy.
  </p>
  <?php $rv_client_id = $student['client_id']; include __DIR__ . '/_licencje_view.php'; ?>

<?php elseif ($tab === 'pfron'):
    $pf_msg      = $_SESSION['pfron_msg'] ?? null; unset($_SESSION['pfron_msg']);
    $pf_unlocked = pfron_unlocked_ids();
?>
  <h1 class="h5 fw-bold mb-1"><i class="bi bi-shield-lock text-primary me-1" aria-hidden="true"></i>PFRON — konsultacje</h1>
  <p class="text-body-secondary small mb-3">
    Sekcja chroniona. Dane PFRON są przechowywane oddzielnie od Twojego konta — aby je zobaczyć,
    potwierdź tożsamość numerem telefonu i numerem umowy PFRON.
  </p>

  <?php if ($pf_msg): ?>
  <div class="alert alert-<?= $pf_msg[0]==='ok'?'success':'danger' ?> alert-dismissible fade show" role="alert">
    <i class="bi bi-<?= $pf_msg[0]==='ok'?'check-circle':'exclamation-triangle' ?> me-1" aria-hidden="true"></i><?= h($pf_msg[1]) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
  <?php endif; ?>

  <?php if (!$pf_unlocked): ?>
  <div class="card" style="max-width:460px">
    <div class="card-body">
      <h2 class="h6 fw-bold mb-3"><i class="bi bi-lock me-1" aria-hidden="true"></i>Weryfikacja dwuskładnikowa</h2>
      <?php if (pfron_is_locked()): ?>
      <div class="alert alert-warning py-2 small">Zbyt wiele prób. Odczekaj kilka minut i spróbuj ponownie.</div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
        <input type="hidden" name="_op"    value="pfron_auth">
        <div class="mb-2">
          <label class="form-label" for="pf_phone">Numer telefonu</label>
          <input type="tel" class="form-control" id="pf_phone" name="phone" inputmode="tel" autocomplete="tel" required placeholder="np. 600 100 200">
        </div>
        <div class="mb-3">
          <label class="form-label" for="pf_contract">Numer umowy PFRON</label>
          <input type="text" class="form-control font-monospace" id="pf_contract" name="contract_no" required placeholder="np. PFRON/2026/0001">
        </div>
        <button type="submit" class="btn btn-primary w-100" <?= pfron_is_locked() ? 'disabled' : '' ?>>
          <i class="bi bi-unlock me-1" aria-hidden="true"></i>Odblokuj dostęp
        </button>
      </form>
      <p class="text-body-secondary small mt-3 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Dostęp jest aktywny przez 30 minut, po czym wymagamy ponownej weryfikacji.</p>
    </div>
  </div>
  <?php else: ?>
  <div class="d-flex justify-content-end mb-2">
    <form method="post">
      <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
      <input type="hidden" name="_op"    value="pfron_lock">
      <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-lock me-1" aria-hidden="true"></i>Zablokuj dostęp</button>
    </form>
  </div>
  <?php foreach ($pf_unlocked as $pf_cid):
    $pf_c = pfron_contract_get($pf_cid); if (!$pf_c) continue;
    $pf_cons = pfron_consultations($pf_cid);
    $pf_left = pfron_hours_left($pf_c);
    $pf_amt  = array_sum(array_map(fn($s) => (float)$s['amount_due'], $pf_cons));
  ?>
  <div class="card mb-3">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
      <span class="fw-semibold"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Umowa PFRON <?= h($pf_c['contract_number']) ?></span>
      <span class="badge text-bg-<?= ($pf_c['status'] ?? '')==='active' ? 'success' : 'secondary' ?>"><?= h($pf_c['status'] ?? '') ?></span>
      <?php if (!empty($pf_c['valid_from']) || !empty($pf_c['valid_to'])): ?>
      <span class="text-body-secondary small"><i class="bi bi-calendar-range me-1" aria-hidden="true"></i><?= h($pf_c['valid_from'] ?? '—') ?> – <?= h($pf_c['valid_to'] ?? '—') ?></span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-2">
        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center">
          <div class="fs-5 fw-bold lh-1"><?= number_format((float)$pf_c['hours_limit'],1,',','') ?> h</div>
          <div class="text-body-secondary small">Limit godzin</div>
        </div></div>
        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center">
          <div class="fs-5 fw-bold lh-1"><?= number_format((float)$pf_c['hours_used'],1,',','') ?> h</div>
          <div class="text-body-secondary small">Wykorzystane</div>
        </div></div>
        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center">
          <div class="fs-5 fw-bold lh-1 <?= $pf_left <= 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($pf_left,1,',','') ?> h</div>
          <div class="text-body-secondary small">Pozostało</div>
        </div></div>
        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center">
          <div class="fs-5 fw-bold lh-1"><?= number_format($pf_amt,2,',',' ') ?> zł</div>
          <div class="text-body-secondary small">Dopłaty (ponad limit)</div>
        </div></div>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <caption class="visually-hidden">Konsultacje rozliczane z PFRON dla umowy <?= h($pf_c['contract_number']) ?></caption>
        <thead><tr><th scope="col">Data</th><th scope="col">Prowadzący</th><th scope="col">Godz.</th><th scope="col">Rozliczenie</th></tr></thead>
        <tbody>
          <?php if (!$pf_cons): ?>
          <tr><td colspan="4" class="text-center text-body-secondary py-4">Brak konsultacji rozliczanych z PFRON.</td></tr>
          <?php endif; ?>
          <?php foreach ($pf_cons as $s):
            $sd = $s['start_time'] ? new DateTime($s['start_time']) : null; ?>
          <tr>
            <td class="text-nowrap small"><?= $sd ? h($sd->format('d.m.Y H:i')) : '—' ?></td>
            <td class="small"><?= $s['consultant_name'] ? h($s['consultant_name']) : '<span class="text-body-secondary">—</span>' ?></td>
            <td class="small text-nowrap"><?= number_format((float)$s['billed_hours'],2,',','') ?> h
              <?php if ((float)$s['charged_hours'] > 0): ?><span class="text-danger">(+<?= number_format((float)$s['charged_hours'],2,',','') ?> h płatne)</span><?php endif; ?>
            </td>
            <td class="small"><?= $s['pfron_status'] ? h($s['pfron_status']) : ((float)$s['amount_due']>0 ? number_format((float)$s['amount_due'],2,',',' ').' zł' : '<span class="text-success">w ramach PFRON</span>') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>
  <p class="text-body-secondary small"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Dane PFRON są chronione i widoczne wyłącznie po weryfikacji. Dostęp wygasa po 30 minutach.</p>
  <?php endif; ?>

<?php elseif ($tab === 'online'): ?>

  <?php if (!$online_term_ok):
    $online_term = ti_term_get('szkolenia'); ?>
  <div class="row justify-content-center">
    <div class="col-lg-7">
      <?= _ti_terms_acceptance_block($online_term, $vlab_token, 'online') ?>
    </div>
  </div>
  <?php else: ?>

  <?php if ($moodle_courses_student): ?>
  <section class="card mb-4" aria-labelledby="mdl-heading">
    <div class="card-body">
      <h2 id="mdl-heading" class="h6 fw-bold mb-1"><i class="bi bi-mortarboard text-primary me-2" aria-hidden="true"></i>Kursy Moodle</h2>
      <p class="text-body-secondary small mb-3">Kursy e-learningowe przypięte do Twoich grup. Kliknij, aby otworzyć kurs na platformie Moodle.</p>
      <div class="row g-2">
        <?php foreach ($moodle_courses_student as $mc): ?>
        <div class="col-md-6">
          <a href="<?= h(ti_moodle_course_url($mc['base_url'], (int)$mc['moodle_course_id'])) ?>" target="_blank" rel="noopener"
             class="d-flex align-items-center gap-2 text-decoration-none border rounded p-2 h-100">
            <i class="bi bi-mortarboard-fill text-primary fs-5 flex-shrink-0" aria-hidden="true"></i>
            <span class="flex-grow-1 min-width-0">
              <span class="fw-semibold d-block text-truncate"><?= $mc['fullname'] ? h($mc['fullname']) : ('Kurs #'.(int)$mc['moodle_course_id']) ?></span>
              <span class="small text-body-secondary"><?= h($mc['ti_course_name']) ?> · <?= h($mc['server_name']) ?></span>
            </span>
            <i class="bi bi-box-arrow-up-right text-body-secondary flex-shrink-0" aria-hidden="true"></i>
          </a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <div id="online-root" data-token="<?= h($vlab_token) ?>">
    <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-1">
      <i class="bi bi-camera-video text-primary" aria-hidden="true"></i>Szkolenia online
    </h1>
    <p class="text-body-secondary small mb-3">
      Twoje konto szkoleniowe Microsoft&nbsp;365, dostęp do platformy e-learningowej oraz linki do nadchodzących szkoleń (Zoom / MS&nbsp;Teams).
    </p>
    <div id="online-content" aria-live="polite">
      <div class="text-body-secondary py-4 text-center">Ładowanie…</div>
    </div>
  </div>

  <script>
  (function(){
    const root = document.getElementById('online-root');
    const box  = document.getElementById('online-content');
    const token = root.dataset.token;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function api(action, params){
      const body = new URLSearchParams(Object.assign({action, _token: token}, params || {}));
      const r = await fetch('ti_online_api.php', {method:'POST', headers:{'X-CSRF-Token':token}, body});
      return r.json();
    }

    function fmtDate(s){
      if (!s) return '';
      const d = new Date(s.replace(' ', 'T'));
      if (isNaN(d)) return esc(s);
      return d.toLocaleString('pl-PL', {day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit'});
    }
    const platMap = {zoom:['primary','camera-video','Zoom'], teams:['info','microsoft-teams','MS Teams'], other:['secondary','link-45deg','Link']};

    let lastCreds = null; // jednorazowe dane konta MS po utworzeniu

    function cardMS(d){
      let inner;
      if (!d.ms_enabled){
        inner = '<p class="text-body-secondary small mb-0">Moduł kont Microsoft nie został skonfigurowany przez administratora.</p>';
      } else if (d.ms_active){
        inner = '<p class="small mb-2">Twój login (działa też w Moodle):<br><span class="font-monospace fw-semibold">'+esc(d.ms_upn)+'</span></p>';
        if (lastCreds && lastCreds.upn === d.ms_upn){
          inner += '<div class="alert alert-warning small py-2"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>'
            + 'Hasło tymczasowe (zapisz teraz, zmienisz przy pierwszym logowaniu): <span class="font-monospace fw-bold">'+esc(lastCreds.password)+'</span></div>';
        }
        inner += '<button type="button" class="btn btn-outline-danger btn-sm" data-act="ms_delete"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń konto</button>';
      } else if (d.ms_external_upn){
        inner = '<div class="alert alert-info small py-2 mb-0">'
          + '<i class="bi bi-info-circle me-1" aria-hidden="true"></i>'
          + 'Konto Microsoft o loginie <span class="font-monospace fw-semibold">'+esc(d.ms_external_upn)+'</span> '
          + 'już istnieje (utworzone poza systemem). Zaloguj się nim — <strong>nie tworzymy nowego</strong>, aby go nie nadpisać. '
          + 'Jeśli to nie Twoje konto, skontaktuj się z administratorem.</div>';
      } else {
        inner = '<p class="text-body-secondary small mb-2">Nie masz jeszcze konta szkoleniowego. Utwórz je, aby korzystać z usług Microsoft i platformy e-learningowej.</p>'
          + '<button type="button" class="btn btn-primary btn-sm" data-act="ms_create"><i class="bi bi-microsoft me-1" aria-hidden="true"></i>Utwórz konto</button>';
      }
      return '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-body">'
        + '<h2 class="h6 fw-bold d-flex align-items-center mb-2"><i class="bi bi-microsoft me-2 text-primary" aria-hidden="true"></i>Konto Microsoft 365</h2>'
        + inner + '</div></div></div>';
    }

    function cardMoodle(d){
      let inner;
      if (!d.moodle_enabled){
        inner = '<p class="text-body-secondary small mb-0">Integracja z platformą e-learningową nie została skonfigurowana.</p>';
      } else if (d.moodle_active){
        inner = '<p class="small mb-2">Login: <span class="font-monospace fw-semibold">'+esc(d.moodle_login)+'</span><br>'
          + '<span class="text-body-secondary">Hasło: domyślnie takie samo jak do konta Microsoft — możesz ustawić własne poniżej.</span></p>'
          + (d.moodle_url ? '<a class="btn btn-success btn-sm mb-2" href="'+esc(d.moodle_url)+'" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz platformę</a>' : '')
          + '<details class="mt-1">'
          + '<summary class="small text-primary" style="cursor:pointer"><i class="bi bi-key me-1" aria-hidden="true"></i>Ustaw własne hasło do platformy</summary>'
          + '<div class="mt-2" style="max-width:340px">'
          + '<label class="form-label small mb-1" for="moodle-pwd">Nowe hasło</label>'
          + '<input type="password" class="form-control form-control-sm mb-2" id="moodle-pwd" autocomplete="new-password" minlength="8" placeholder="min. 8 znaków, A-z, cyfra, znak specjalny">'
          + '<button type="button" class="btn btn-primary btn-sm" data-act="moodle_password"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz hasło</button>'
          + '<p class="form-text small mb-0">Hasło musi mieć min. 8 znaków oraz zawierać małą i wielką literę, cyfrę i znak specjalny.</p>'
          + '</div></details>';
      } else if (!d.ms_active){
        inner = '<p class="text-body-secondary small mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Najpierw utwórz konto Microsoft — jego login posłuży jako login do platformy.</p>';
      } else {
        inner = '<p class="text-body-secondary small mb-2">Utwórz konto na platformie e-learningowej (login = Twój adres Microsoft).</p>'
          + '<button type="button" class="btn btn-primary btn-sm" data-act="moodle_create"><i class="bi bi-mortarboard me-1" aria-hidden="true"></i>Utwórz konto Moodle</button>';
      }
      return '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-body">'
        + '<h2 class="h6 fw-bold d-flex align-items-center mb-2"><i class="bi bi-mortarboard me-2 text-primary" aria-hidden="true"></i>Platforma e-learning</h2>'
        + inner + '</div></div></div>';
    }

    function meetingRow(m){
      const [col,icon,lbl] = platMap[m.platform] || platMap.other;
      return '<div class="list-group-item d-flex align-items-center gap-3 flex-wrap">'
        + '<span class="badge text-bg-'+col+'"><i class="bi bi-'+icon+' me-1" aria-hidden="true"></i>'+lbl+'</span>'
        + '<span class="flex-grow-1"><span class="fw-semibold">'+esc(m.title)+'</span>'
        + (m.starts_at ? ' <span class="text-body-secondary small d-block d-sm-inline">'+fmtDate(m.starts_at)+'</span>' : '')+'</span>'
        + '<a class="btn btn-outline-primary btn-sm" href="'+esc(m.join_url)+'" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Dołącz</a>'
        + '</div>';
    }

    function cardMeetings(d){
      let body;
      if (!d.meetings || !d.meetings.length){
        body = '<div class="border border-secondary-subtle rounded p-4 text-center text-body-secondary">Brak zaplanowanych szkoleń online.</div>';
      } else {
        // Grupuj linki pod konkretną grupą (course_name); wspólne/tenantowe na końcu.
        const groups = new Map();
        for (const m of d.meetings){
          const key = m.course_name || ' all';
          if (!groups.has(key)) groups.set(key, []);
          groups.get(key).push(m);
        }
        const keys = [...groups.keys()].sort((a,b)=>{
          if (a===' all') return 1; if (b===' all') return -1;
          return a.localeCompare(b,'pl');
        });
        body = '';
        for (const key of keys){
          const isAll = key===' all';
          const label = isAll ? 'Dla wszystkich grup' : key;
          const gicon = isAll ? 'broadcast' : 'people-fill';
          body += '<div class="mb-3">'
            + '<div class="fw-semibold small text-uppercase text-body-secondary mb-2">'
            + '<i class="bi bi-'+gicon+' me-1" aria-hidden="true"></i>'+esc(label)+'</div>'
            + '<div class="list-group">' + groups.get(key).map(meetingRow).join('') + '</div></div>';
        }
      }
      return '<div class="col-12"><div class="card"><div class="card-body">'
        + '<h2 class="h6 fw-bold d-flex align-items-center mb-3"><i class="bi bi-calendar-event me-2 text-primary" aria-hidden="true"></i>Nadchodzące szkolenia</h2>'
        + body + '</div></div></div>';
    }

    function render(d){
      box.innerHTML = '<div class="row g-3">' + cardMS(d) + cardMoodle(d) + cardMeetings(d) + '</div>';
    }

    async function reload(){ const d = await api('list'); if (d.ok) render(d); }

    box.addEventListener('click', async (e)=>{
      const btn = e.target.closest('[data-act]');
      if (!btn) return;
      const act = btn.dataset.act;
      if (act === 'ms_delete' && !confirm('Usunąć konto Microsoft? Stracisz dostęp do powiązanych usług.')) return;
      let params = {};
      if (act === 'moodle_password'){
        const inp = box.querySelector('#moodle-pwd');
        const pwd = inp ? inp.value : '';
        if (!pwd){ if (inp) inp.focus(); return; }
        params = {password: pwd};
      }
      btn.disabled = true;
      const orig = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Pracuję…';
      const r = await api(act, params);
      if (act === 'ms_create' && r.ok && r.password) lastCreds = {upn: r.upn, password: r.password};
      if (act === 'moodle_password' && r.ok) alert(r.msg || 'Hasło zmienione.');
      if (!r.ok) alert(r.msg || 'Błąd.');
      if (r.data) render(r.data); else { btn.disabled = false; btn.innerHTML = orig; reload(); }
    });

    reload();
  })();
  </script>

  <?php endif; // online_term_ok ?>

<?php elseif ($tab === 'problem'): ?>
<div class="row justify-content-center">
  <div class="col-lg-8">
    <h1 class="h5 fw-bold mb-1"><i class="bi bi-wrench-adjustable text-primary me-2" aria-hidden="true"></i>Zgłoś problem techniczny</h1>
    <p class="text-body-secondary small mb-3">
      Masz kłopot z logowaniem, kontem Microsoft 365, Moodle, VLab lub innym narzędziem?
      Opisz problem — zespół wsparcia IT zajmie się Twoim zgłoszeniem.
    </p>

    <?php if ((string)($_GET['sent'] ?? '') === '1'): ?>
    <div class="alert alert-success d-flex align-items-start gap-2" role="alert">
      <i class="bi bi-check-circle-fill mt-1" aria-hidden="true"></i>
      <div>Zgłoszenie zostało przyjęte<?php if (!empty($_GET['num'])): ?> pod numerem <strong><?= h($_GET['num']) ?></strong><?php endif; ?>.
        Skontaktujemy się z Tobą e-mailem lub telefonicznie.</div>
    </div>
    <?php endif; ?>
    <?php if ((string)($_GET['err'] ?? '') === '1'): ?>
    <div class="alert alert-danger py-2 small" role="alert"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Podaj temat oraz krótki opis problemu (min. 5 znaków).</div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
          <input type="hidden" name="_op" value="report_issue">
          <div class="mb-3">
            <label class="form-label fw-semibold" for="hd-title">Temat <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="hd-title" name="title" maxlength="200" required
                   placeholder="np. Nie mogę zalogować się do Microsoft 365" value="<?= h($_POST['title'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="hd-cat">Czego dotyczy</label>
            <select class="form-select" id="hd-cat" name="category">
              <?php foreach (['it_konto'=>'Konto / logowanie','it_m365'=>'Microsoft 365','it_oprogramowanie'=>'Oprogramowanie / Moodle','it_siec'=>'Sieć / Internet','it_inne'=>'Inne'] as $ck => $cl): ?>
              <option value="<?= h($ck) ?>" <?= ($_POST['category'] ?? '') === $ck ? 'selected' : '' ?>><?= h($cl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="hd-desc">Opis problemu <span class="text-danger">*</span></label>
            <textarea class="form-control" id="hd-desc" name="description" rows="6" required
                      placeholder="Co się dzieje? Kiedy wystąpiło? Jaki komunikat błędu widzisz? Czego już próbowałeś/aś?"><?= h($_POST['description'] ?? '') ?></textarea>
          </div>
          <div class="alert alert-light border small d-flex gap-2 mb-3">
            <i class="bi bi-info-circle text-primary mt-1" aria-hidden="true"></i>
            <div>Zgłoszenie wyślemy w Twoim imieniu jako <strong><?= h($client['name'] ?? ($account['login'] ?? '')) ?></strong><?php if (!empty($client['email'])): ?> (<?= h($client['email']) ?>)<?php endif; ?>. Odpowiedź otrzymasz tą samą drogą.</div>
          </div>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij zgłoszenie</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php elseif ($tab === 'regulaminy'): ?>

  <h1 class="h5 fw-bold mb-1"><i class="bi bi-file-earmark-text text-primary me-2" aria-hidden="true"></i>Regulaminy</h1>
  <p class="text-body-secondary small mb-3">Regulaminy, które musisz zaakceptować, aby korzystać z dostępnych narzędzi. Możesz pobrać PDF potwierdzenia każdej akceptacji.</p>

  <?php if (!empty($_GET['accepted'])): ?>
  <div class="alert alert-success py-2 small" role="alert">
    <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Regulamin zaakceptowany. Możesz teraz korzystać z wybranego narzędzia.
  </div>
  <?php endif; ?>

  <?php if ($terms_pending): ?>
  <div class="alert alert-warning d-flex align-items-start gap-2 mb-4" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
    <div>
      <strong>Wymagana akceptacja:</strong> poniższe regulaminy nie zostały jeszcze przez Ciebie zaakceptowane.
      Zaakceptuj je, aby uzyskać dostęp do powiązanych narzędzi.
    </div>
  </div>
  <?php foreach ($terms_pending as $tp): ?>
  <?= _ti_terms_acceptance_block($tp, $vlab_token, 'regulaminy') ?>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($terms_accepted): ?>
  <h2 class="h6 fw-semibold mt-3 mb-2"><i class="bi bi-check2-circle text-success me-1" aria-hidden="true"></i>Historia akceptacji</h2>
  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>Regulamin</th>
            <th>Data i godzina</th>
            <th>Adres IP</th>
            <th>Wersja</th>
            <th><span class="visually-hidden">Akcje</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($terms_accepted as $ta): ?>
          <tr>
            <td><?= h($ta['title']) ?></td>
            <td class="text-nowrap small"><?= h(date('d.m.Y H:i', strtotime($ta['accepted_at']))) ?></td>
            <td class="font-monospace small text-body-secondary"><?= h($ta['ip']) ?></td>
            <td class="small">v<?= (int)$ta['version'] ?></td>
            <td>
              <a href="terms_pdf.php?id=<?= (int)$ta['id'] ?>" class="btn btn-sm btn-outline-secondary py-0"
                 title="Pobierz PDF potwierdzenia">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php elseif (empty($terms_pending)): ?>
  <div class="text-body-secondary small"><i class="bi bi-inbox me-1" aria-hidden="true"></i>Brak akceptacji do wyświetlenia.</div>
  <?php endif; ?>

<?php endif; ?>

</main>

<?php include __DIR__ . '/_layout_foot.php'; ?>
