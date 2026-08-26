<?php
/**
 * Panel kursanta TI — dashboard: moje lekcje, rozliczenia, VLab.
 * UI: Bootstrap 5.3 (motyw ciemny) + WCAG 2.1 AA.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/owncloud.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/vlab.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_messages.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_moodle.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_online.php'; // ti_moodle_enabled()
require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/pfron.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpdesk.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_leaves.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_terms.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_reschedule.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_notifications.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_notices.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_periods.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_blackout.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/push.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
k30_ti_reschedule_migrate();
k30_ti_notif_migrate();
ti_notices_migrate();
pfron_migrate();
helpdesk_migrate();

// Streak + push — migracje schematu (bezpieczne ALTER TABLE)
try { db()->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN login_streak INTEGER NOT NULL DEFAULT 0"); } catch(\Exception $e){}
try { db()->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN last_login_date TEXT"); } catch(\Exception $e){}
try { db()->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN push_subscription TEXT"); } catch(\Exception $e){}

// Zakończenie podglądu administratora („zaloguj jako") — wróć do listy kont kursantów.
if (isset($_GET['stop_impersonation'])) {
    $was_imp = student_impersonator() !== null;
    student_logout();
    header('Location: ' . ($was_imp ? rtrim(APP_URL, '/') . '/karty30/ti/kursant/accounts.php' : 'login.php'));
    exit;
}

// Wylogowanie (przed jakimkolwiek wyjściem)
if (isset($_GET['logout'])) {
    $_s = student_current(); if ($_s) ti_account_log((int)$_s['id'], 'logout', 'Wylogowanie z panelu.');
    student_logout(); header('Location: login.php'); exit;
}

$student    = student_require();

// Oznaczenie powiadomień jako przejrzanych
if (isset($_GET['notif_mark'])) {
    k30_ti_notif_mark_seen((int)$student['id']);
    $back = preg_replace('/[^a-z]/', '', (string)($_GET['tab'] ?? 'dane'));
    header('Location: index.php?tab=' . ($back ?: 'dane')); exit;
}

if (isset($_POST['_op']) && $_POST['_op'] === 'mark_notice_read') {
    if (hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) {
        $nid = (int)($_POST['notice_id'] ?? 0);
        if ($nid) ti_notices_mark_read($nid, (int)$student['id']);
    }
    header('Location: index.php?tab=komunikaty'); exit;
}
if (isset($_POST['_op']) && $_POST['_op'] === 'mark_notices_all_read') {
    if (hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) {
        ti_notices_mark_all_read((int)$student['id']);
    }
    header('Location: index.php?tab=komunikaty'); exit;
}

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

// ── Streak aktywności — aktualizuj raz dziennie ────────────────────────────
$today_str = date('Y-m-d');
if (($account['last_login_date'] ?? '') !== $today_str) {
    $yesterday  = date('Y-m-d', strtotime('-1 day'));
    $new_streak = ($account['last_login_date'] === $yesterday)
                ? (int)$account['login_streak'] + 1
                : 1;
    db_exec("UPDATE k30_ti_student_accounts SET login_streak=?, last_login_date=? WHERE id=?",
        [$new_streak, $today_str, (int)$account['id']]);
    $account['login_streak']    = $new_streak;
    $account['last_login_date'] = $today_str;
}
$streak = (int)($account['login_streak'] ?? 0);

// ── Odwołanie / przywrócenie udziału w lekcji przez Beneficjenta ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op  = $_POST['_op'] ?? '';
    $tok = $_POST['_token'] ?? '';
    if (!hash_equals(student_token(), (string)$tok)) { http_response_code(403); exit('Nieprawidłowy token sesji.'); }

    // Nowy adres subskrypcji kalendarza (unieważnia poprzedni)
    if ($op === 'cal_token_reset') {
        k30_ti_calendar_token_reset((int)$student['id']);
        header('Location: index.php?tab=lekcje'); exit;
    }

    // Akceptacja regulaminu TI — pomijamy regulaminy już podpisane (bieżąca wersja) i zakończone
    // (wycofane, is_active=0), żeby powtórne/spreparowane wysłanie formularza nic nie zmieniało.
    if ($op === 'accept_term') {
        $term_id  = (int)($_POST['term_id'] ?? 0);
        $term_row = $term_id > 0 ? db_one("SELECT * FROM k30_ti_terms WHERE id=?", [$term_id]) : null;
        // Regulamin VLab u małoletniego akceptuje wyłącznie opiekun w panelu rodzica — kursant nie może go
        // zaakceptować sam, nawet wysyłając formularz bezpośrednio.
        $is_minor_acc   = !empty($account['is_minor']);
        $already_signed = $term_row && ti_term_accepted((int)$student['client_id'], $term_row['type']);
        if ($term_row && !empty($term_row['is_active']) && !$already_signed && !($is_minor_acc && $term_row['type'] === 'vlab')) {
            ti_term_accept((int)$student['client_id'], $term_id, (int)$student['id']);
        }
        $redirect_tab = (string)($_POST['redirect_tab'] ?? 'regulaminy');
        header('Location: index.php?tab=' . urlencode($redirect_tab) . '&accepted=1'); exit;
    }

    // Zamówienie dedykowanego serwera u zewnętrznego partnera
    if ($op === 'order_dedicated_server') {
        $r = vlab_dedicated_server_request(
            (int)$student['id'],
            (string)($_POST['hostname_prefix'] ?? ''),
            (string)($_POST['server_username'] ?? ''),
            (string)($_POST['billing_period'] ?? 'monthly')
        );
        $_SESSION['k30_ds_msg'] = [$r['ok'] ? 'ok' : 'err', $r['msg']];
        header('Location: index.php?tab=vlab'); exit;
    }

    // Samodzielna rezygnacja z zamówionego/aktywnego VPS
    if ($op === 'cancel_dedicated_server') {
        $r = vlab_dedicated_server_self_cancel((int)($_POST['order_id'] ?? 0), (int)$student['id']);
        $_SESSION['k30_ds_msg'] = [$r['ok'] ? 'ok' : 'err', $r['msg']];
        header('Location: index.php?tab=vlab'); exit;
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

    // Samodzielne usprawiedliwienie nieobecności (lekcja odbyła się, kursant nieobecny)
    if ($op === 'excuse_absence') {
        $sid    = (int)($_POST['session_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $own = db_one(
            "SELECT s.status, COALESCE(a.attended,0) AS attended, COALESCE(a.cancelled,0) AS cancelled,
                    COALESCE(a.cancel_pending,0) AS pending
             FROM k30_ti_sessions s
             JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=? AND e.status='active'
             LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=?
             WHERE s.id=?",
            [$student['client_id'], $student['client_id'], $sid]
        );
        $flag = '';
        if ($own && in_array($own['status'], ['held','individual_change'], true)
            && (int)$own['attended'] === 0 && (int)$own['cancelled'] === 0 && (int)$own['pending'] === 0) {
            if ($reason === '') {
                $flag = 'need_reason';
            } else {
                // Prośba czeka na zatwierdzenie prowadzącego (mail do niego); po zatwierdzeniu = poza frekwencją
                k30_ti_request_cancel_attendance(
                    $sid, $student['client_id'],
                    'Usprawiedliwienie nieobecności: ' . $reason,
                    'beneficjent', $client['name'] ?? ''
                );
                $flag = 'requested';
            }
        }
        header('Location: index.php?tab=lekcje' . ($flag ? '&excuse=' . $flag : '')); exit;
    }

    // Propozycja nowego terminu lekcji — czeka na decyzję prowadzącego
    if ($op === 'propose_reschedule') {
        $sid  = (int)($_POST['session_id'] ?? 0);
        $date = trim($_POST['lesson_date'] ?? '');
        $tf   = trim($_POST['time_from'] ?? '');
        $tt   = trim($_POST['time_to'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $own = db_one(
            "SELECT s.id, s.status FROM k30_ti_sessions s
             JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=? AND e.status='active'
             WHERE s.id=?",
            [$student['client_id'], $sid]
        );
        $flag = '';
        if ($own && $own['status'] === 'planned' && $date !== '') {
            k30_ti_request_reschedule($sid, (int)$student['client_id'], $date, $tf, $tt, $reason, 'beneficjent', $client['name'] ?? '');
            $flag = 'reschedule';
        }
        header('Location: index.php?tab=lekcje' . ($flag ? '&cancel=' . $flag : '')); exit;
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

    // Odpowiedź / nowa wiadomość kursanta → prowadzący
    if ($op === 'msg_reply' || $op === 'msg_new') {
        $body    = trim((string)($_POST['body'] ?? ''));
        $subject = mb_substr(trim((string)($_POST['thread_subject'] ?? $_POST['subject'] ?? '')), 0, 200);
        if ($body !== '') {
            try {
                ti_msg_student_reply((int)$student['id'], mb_substr($body, 0, 4000), $subject);
                ti_account_log((int)$student['id'], 'msg_sent', mb_substr($body, 0, 100));
                header('Location: index.php?tab=wiadomosci&sent=1'); exit;
            } catch (\RuntimeException $e) {
                header('Location: index.php?tab=wiadomosci&blocked=1'); exit;
            }
        }
        header('Location: index.php?tab=wiadomosci'); exit;
    }

    // Wiadomość do KIS / admina (tylko e-mail, bez przechowywania)
    if ($op === 'msg_secretariat') {
        $body     = trim((string)($_POST['body'] ?? ''));
        $toRaw    = (string)($_POST['to_recip'] ?? 'KIS');
        if ($body !== '') {
            $fromName = trim((string)($account['client_name'] ?? $account['login'] ?? ''));
            $ok = false;
            if ($toRaw === 'KIS') {
                $ok = ti_secretariat_send($fromName, mb_substr($body, 0, 4000), (int)$student['id'], TI_KIS_EMAIL, TI_KIS_NAME);
            } elseif ($toRaw === 'all') {
                $admins = ti_admin_users();
                $ok = false;
                foreach ($admins as $_a) {
                    if (!empty($_a['email']) && filter_var($_a['email'], FILTER_VALIDATE_EMAIL)) {
                        $ok = ti_secretariat_send($fromName, mb_substr($body, 0, 4000), (int)$student['id'], $_a['email'], $_a['name']) || $ok;
                    }
                }
            } elseif (str_starts_with($toRaw, 'admin_')) {
                $adminId = (int)substr($toRaw, 6);
                $adm = $adminId ? db_one("SELECT name, email FROM users WHERE id=? AND role='admin' AND is_active=1", [$adminId]) : null;
                if ($adm && !empty($adm['email'])) {
                    $ok = ti_secretariat_send($fromName, mb_substr($body, 0, 4000), (int)$student['id'], $adm['email'], $adm['name']);
                }
            } elseif (str_starts_with($toRaw, 'sek_')) {
                $sekId = (int)substr($toRaw, 4);
                $sek = $sekId ? db_one("SELECT name, email FROM users WHERE id=? AND role='dydaktyk_sekretariat' AND is_active=1", [$sekId]) : null;
                if ($sek && !empty($sek['email'])) {
                    $ok = ti_secretariat_send($fromName, mb_substr($body, 0, 4000), (int)$student['id'], $sek['email'], $sek['name']);
                }
            }
            header('Location: index.php?tab=wiadomosci&sec=' . ($ok ? '1' : 'err')); exit;
        }
        header('Location: index.php?tab=wiadomosci'); exit;
    }

    // Zapis dodatkowych numerów telefonu do powiadomień SMS — zmiana numeru zeruje jego
    // weryfikację (numer musi zostać potwierdzony ponownie, zanim zaczną na niego iść SMS-y).
    if ($op === 'notify_phones') {
        $new_phone2 = mb_substr(trim((string)($_POST['phone2'] ?? '')), 0, 30);
        $new_phone3 = mb_substr(trim((string)($_POST['phone3'] ?? '')), 0, 30);
        $upd = ['notify_phone2' => $new_phone2, 'notify_phone3' => $new_phone3];
        if ($new_phone2 !== trim((string)($account['notify_phone2'] ?? ''))) {
            $upd += ['notify_phone2_verified' => 0, 'notify_phone2_otp' => '', 'notify_phone2_otp_expires' => ''];
        }
        if ($new_phone3 !== trim((string)($account['notify_phone3'] ?? ''))) {
            $upd += ['notify_phone3_verified' => 0, 'notify_phone3_otp' => '', 'notify_phone3_otp_expires' => ''];
        }
        db_update('k30_ti_student_accounts', $upd, (int)$student['id']);
        header('Location: index.php?tab=ustawienia&phones=1'); exit;
    }

    // Wysyłka kodu weryfikacyjnego SMS na dodatkowy numer (samoobsługowa weryfikacja własności numeru)
    if ($op === 'notify_phone_send_otp') {
        $which = ((string)($_POST['which'] ?? '')) === '3' ? 3 : 2;
        $phone = trim((string)($account["notify_phone{$which}"] ?? ''));
        if ($phone === '') { header('Location: index.php?tab=ustawienia&phones=err_missing'); exit; }
        if (!sms_is_enabled()) { header('Location: index.php?tab=ustawienia&phones=err_sms_off'); exit; }
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        db_update('k30_ti_student_accounts', [
            "notify_phone{$which}_otp"         => $code,
            "notify_phone{$which}_otp_expires" => date('Y-m-d H:i:s', time() + 600),
            "notify_phone{$which}_verified"    => 0,
        ], (int)$student['id']);
        $org = defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta';
        try { sms_send($phone, "{$org}: kod weryfikacyjny numeru do powiadomien: {$code} (wazny 10 min)."); } catch (\Throwable $e) {}
        header('Location: index.php?tab=ustawienia&phones=otp_sent_' . $which); exit;
    }

    // Weryfikacja kodu SMS wpisanego przez kursanta — dopiero teraz numer zaczyna otrzymywać powiadomienia
    if ($op === 'notify_phone_verify_otp') {
        $which = ((string)($_POST['which'] ?? '')) === '3' ? 3 : 2;
        $code  = trim((string)($_POST['code'] ?? ''));
        $exp   = (string)($account["notify_phone{$which}_otp_expires"] ?? '');
        $ok    = $code !== '' && $exp !== '' && $exp > date('Y-m-d H:i:s')
                 && hash_equals((string)($account["notify_phone{$which}_otp"] ?? "\0"), $code);
        if ($ok) {
            db_update('k30_ti_student_accounts', [
                "notify_phone{$which}_verified"    => 1,
                "notify_phone{$which}_otp"         => '',
                "notify_phone{$which}_otp_expires" => '',
            ], (int)$student['id']);
            header('Location: index.php?tab=ustawienia&phones=verified_' . $which); exit;
        }
        header('Location: index.php?tab=ustawienia&phones=err_code_' . $which); exit;
    }

    // Zmiana hasła do panelu (samoobsługa oraz wymuszona po nadaniu hasła przez admina)
    if ($op === 'change_password') {
        $cur = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $cnf = (string)($_POST['confirm'] ?? '');
        $acc = db_one("SELECT password_hash FROM k30_ti_student_accounts WHERE id=?", [(int)$student['id']]);
        $forced = !empty($account['must_change_password']);
        $err = '';
        // Przy pierwszym logowaniu (must_change_password) nie weryfikujemy aktualnego hasła
        if (!$forced && (!$acc || !password_verify($cur, $acc['password_hash']))) {
            $err = 'Aktualne hasło jest nieprawidłowe.';
        } elseif (mb_strlen($new) < 8) {
            $err = 'Nowe hasło musi mieć co najmniej 8 znaków.';
        } elseif ($new !== $cnf) {
            $err = 'Nowe hasła nie są identyczne.';
        } elseif (!$forced && $new === $cur) {
            $err = 'Nowe hasło musi różnić się od dotychczasowego.';
        }
        if ($err !== '') {
            header('Location: index.php?tab=ustawienia' . ($forced ? '&force_pw=1' : '') . '&pwerr=' . rawurlencode($err)); exit;
        }
        db()->prepare("UPDATE k30_ti_student_accounts SET password_hash=?, must_change_password=0, updated_at=datetime('now') WHERE id=?")
           ->execute([password_hash($new, PASSWORD_BCRYPT), (int)$student['id']]);
        ti_account_log((int)$student['id'], 'password_changed', $forced ? 'Ustawiono hasło przy pierwszym logowaniu.' : 'Zmieniono hasło.');
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
        ti_account_log((int)$student['id'], 'alias_changed', "Ustawiono alias logowania: {$alias}");
        header('Location: index.php?tab=ustawienia&alias=ok'); exit;
    }

    // Zmiana głównego adresu e-mail (kontakt/powiadomienia) — pole współdzielone z kartą klienta
    if ($op === 'change_email') {
        $new_email = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 190);
        if ($new_email === '' || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            header('Location: index.php?tab=ustawienia&emailerr=' . rawurlencode('Podaj prawidłowy adres e-mail.')); exit;
        }
        db()->prepare("UPDATE k30_clients SET email=?, updated_at=datetime('now') WHERE id=?")
           ->execute([$new_email, (int)$student['client_id']]);
        ti_account_log((int)$student['id'], 'email_changed', "Nowy e-mail: {$new_email}");
        header('Location: index.php?tab=ustawienia&email=ok'); exit;
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

    // Wypisanie się z kursu przez kursanta
    if ($op === 'unenroll_course') {
        $enroll_id = (int)($_POST['enrollment_id'] ?? 0);
        $reason    = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 1000);
        if ($enroll_id) {
            $result = k30_ti_unenroll_request($enroll_id, (int)$student['client_id'], $reason);
            if ($result === 'done') {
                header('Location: index.php?tab=dane&unenrolled=1'); exit;
            } elseif ($result === 'pending') {
                header('Location: index.php?tab=dane&unenroll_pending=1'); exit;
            }
        }
        header('Location: index.php?tab=dane'); exit;
    }

    // Samoobsługowe utworzenie konta ownCloud (2 GB) — zakładka „dysk"
    if ($op === 'owncloud_create') {
        $r = owncloud_create_student_account((int)$student['id']);
        if ($r['ok']) {
            $_SESSION['owncloud_reveal'] = $r;
        } else {
            $_SESSION['k30_oc_msg'] = ['err', $r['msg']];
        }
        header('Location: index.php?tab=dysk'); exit;
    }

    // Reset hasła istniejącego konta ownCloud kursanta
    if ($op === 'owncloud_reset') {
        $r = owncloud_reset_student_password((int)$student['id']);
        if ($r['ok']) {
            $_SESSION['owncloud_reveal'] = $r;
        } else {
            $_SESSION['k30_oc_msg'] = ['err', $r['msg']];
        }
        header('Location: index.php?tab=dysk'); exit;
    }

    // Zarządzanie osobami upoważnionymi (tylko pełnoletni)
}

// Kursy i lekcje kursanta
try { $courses = k30_ti_client_courses($student['client_id']); } catch (\Throwable $e) { $courses = []; }
$active_courses   = array_values(array_filter($courses, fn($c) => ($c['status'] ?? 'active') === 'active'));
$inactive_courses = array_values(array_filter($courses, fn($c) => ($c['status'] ?? 'active') !== 'active'));

// ── Pasek postępu kursu ────────────────────────────────────────────────────
$progress_total = 0;
$progress_done  = 0;
foreach ($courses as $c) {
    $cid = (int)$c['course_id'];
    try {
        $tot = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_curriculum WHERE course_id=?", [$cid])['n'] ?? 0);
    } catch (\Exception $e) { $tot = 0; }
    if ($tot === 0) {
        // Fallback: liczba sesji z k30_ti_sessions jako denominator
        try {
            $tot = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_sessions WHERE course_id=? AND status NOT IN ('cancelled','removed')", [$cid])['n'] ?? 0);
        } catch (\Throwable $e) { $tot = 0; }
    }
    try {
        $don = (int)(db_one(
            "SELECT COUNT(*) AS n FROM k30_ti_sessions s
             JOIN k30_ti_attendance a ON a.session_id=s.id
             WHERE s.course_id=? AND a.client_id=? AND a.attended=1
               AND s.status IN ('held','individual_change','remote_material')",
            [$cid, (int)$student['client_id']]
        )['n'] ?? 0);
    } catch (\Throwable $e) { $don = 0; }
    $progress_total += $tot;
    $progress_done  += $don;
}
$progress_pct = $progress_total > 0 ? round($progress_done / $progress_total * 100) : 0;

// Następna zaplanowana lekcja (do widgetu na dashboardzie)
try {
    $next_lesson = db_one(
        "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.topic, s.lesson_method, s.meeting_url,
                c.name AS course_name, c.default_meeting_url AS course_meeting_url,
                e.zoom_meeting_url AS enrollment_meeting_url,
                u.name AS instructor_name
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         LEFT JOIN users u ON u.id=c.instructor_id
         JOIN k30_ti_enrollments e ON e.course_id=c.id AND e.client_id=? AND e.status='active'
         WHERE s.lesson_date >= date('now') AND (s.status IS NULL OR s.status NOT IN ('cancelled','removed'))
         ORDER BY s.lesson_date, s.time_from LIMIT 1",
        [$student['client_id']]
    );
} catch (\Throwable $e) { $next_lesson = null; }
try { $homeworks_student = k30_ti_homework_for_client($student['client_id']); } catch (\Throwable $e) { $homeworks_student = []; }
$hw_pending = array_values(array_filter($homeworks_student, fn($h) => empty($h['sub_id'])));
try { $materials_student = k30_ti_materials_for_client($student['client_id']); } catch (\Throwable $e) { $materials_student = []; }
// Oceny (e-dziennik) — pogrupowane wg kursu, ze średnią ważoną
// Respektuj wyłączenie ocen: globalnie dla osoby oraz per kurs.
try { $grades_student = k30_ti_client_grades($student['client_id']); } catch (\Throwable $e) { $grades_student = []; }
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
try { $moodle_courses_student = ti_moodle_courses_for_client($student['client_id']); } catch (\Throwable $e) { $moodle_courses_student = []; }

// Zadania z Moodle — odśwież z serwera tylko przy wejściu na zakładkę (TTL wewnątrz);
// listę czytamy z cache (tanio) na potrzeby licznika na innych zakładkach.
if ($tab === 'zadania') {
    ti_moodle_sync_client_assignments($student['client_id']);
}
try { $moodle_assignments = ti_moodle_assignments_for_client($student['client_id']); } catch (\Throwable $e) { $moodle_assignments = []; }
$moodle_hw_pending  = array_values(array_filter($moodle_assignments,
    fn($a) => ($a['sub_status'] ?? '') !== 'submitted' && empty($a['sub_graded'])));
$hw_pending_total   = count($hw_pending) + count($moodle_hw_pending);

$lessons = k30_ti_client_lessons($student['client_id'], 40);
// Do diagnozy pustej listy lekcji: czy kursant ma aktywny zapis na jakikolwiek kurs?
try {
    $active_enroll_count = (int)(db_one(
        "SELECT COUNT(*) n FROM k30_ti_enrollments WHERE client_id=? AND status='active'",
        [$student['client_id']]
    )['n'] ?? 0);
} catch (\Throwable $e) { $active_enroll_count = 0; }
try { $my_licenses = k30_ti_client_licenses($student['client_id']); } catch (\Throwable $e) { $my_licenses = []; }
try { $active_lesson = k30_ti_active_lesson_link($student['client_id']); } catch (\Throwable $e) { $active_lesson = null; }
try {
    $today_lessons = db_all(
        "SELECT s.*, c.name AS course_name, c.default_meeting_url AS course_meeting_url
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=?
         WHERE s.course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')
           AND s.lesson_date=?
           AND (s.status IS NULL OR s.status NOT IN ('cancelled','removed','remote_material'))
           AND (a.cancelled IS NULL OR a.cancelled=0)
         ORDER BY s.time_from",
        [$student['client_id'], $student['client_id'], $today_str]
    );
} catch (\Throwable $e) { $today_lessons = []; }

// Regulaminy TI — oczekujące akceptacje + historia
$terms_pending  = ti_terms_pending((int)$student['client_id']);
$terms_accepted = ti_terms_accepts_for_client((int)$student['client_id']);
$vlab_term_ok   = ti_term_accepted((int)$student['client_id'], 'vlab');
$online_term_ok = ti_term_accepted((int)$student['client_id'], 'szkolenia');

// Urlopy / nieobecności prowadzących kursanta (trwające + nadchodzące 30 dni)
$instructor_leaves = ti_leaves_for_client((int)$student['client_id'], 30);

// Statystyki. Do frekwencji nie liczą się: praca własna prowadzącego (remote_material)
// oraz kursy z wyłączonym liczeniem frekwencji (track_attendance=0).
$fr_counts = fn($l) => ($l['status'] ?? '') !== 'remote_material'
                    && (int)($l['course_track_attendance'] ?? 1) === 1;
$total_lessons  = count($lessons);
$attended_count = count(array_filter($lessons, fn($l) => $l['attended'] && $fr_counts($l)));
$fr_lessons     = count(array_filter($lessons, $fr_counts));
$pct = $fr_lessons > 0 ? round($attended_count / $fr_lessons * 100) : 0;

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

// Komunikaty placówki — licznik nieprzeczytanych
$notices_unread = ti_notices_unread_count((int)$student['id']);

// Wiadomości — licznik nieprzeczytanych + ustawienia powiadomień
$msg_unread     = ti_msg_unread_for_student((int)$student['id']);
$msg_pref_email = (int)($account['notify_email_messages'] ?? 1);
$msg_pref_sms   = (int)($account['notify_sms_messages'] ?? 0);
$msg_blocked    = (bool)($account['msg_blocked'] ?? false);
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

// Centrum powiadomień — feed na żywo + dzwonek w pasku
$notif_items  = k30_ti_notifications_for_client((int)$student['client_id'], (int)$student['id']);
$notif_seen   = k30_ti_notif_seen_at((int)$student['id']);
$notif_unread = k30_ti_notif_unread_count($notif_items, $notif_seen);
ob_start(); ?>
<div class="dropdown">
  <button class="btn btn-outline-secondary btn-sm position-relative" type="button" id="kpNotifBtn"
          data-bs-toggle="dropdown" aria-expanded="false"
          aria-label="Powiadomienia<?= $notif_unread ? ' (' . $notif_unread . ' nowych)' : '' ?>" title="Powiadomienia">
    <i class="bi bi-bell" aria-hidden="true"></i>
    <?php if ($notif_unread > 0): ?>
    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger" style="font-size:.6rem">
      <?= $notif_unread > 9 ? '9+' : $notif_unread ?><span class="visually-hidden"> nowych powiadomień</span>
    </span>
    <?php endif; ?>
  </button>
  <div class="dropdown-menu dropdown-menu-end shadow" style="min-width:340px;max-width:92vw" aria-labelledby="kpNotifBtn">
    <div class="d-flex align-items-center px-3 py-2">
      <span class="fw-semibold"><i class="bi bi-bell me-1" aria-hidden="true"></i>Powiadomienia</span>
      <?php if ($notif_unread > 0): ?><span class="badge text-bg-danger ms-2"><?= $notif_unread ?> nowych</span><?php endif; ?>
      <?php if ($notif_items): ?><a href="?notif_mark=1&amp;tab=<?= h($tab) ?>" class="ms-auto small text-decoration-none">Oznacz jako przeczytane</a><?php endif; ?>
    </div>
    <div class="dropdown-divider my-0"></div>
    <div style="max-height:60vh;overflow-y:auto">
    <?php if (!$notif_items): ?>
    <div class="px-3 py-4 text-body-secondary small text-center"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Brak powiadomień.</div>
    <?php else: foreach ($notif_items as $it):
      $isnew = $notif_seen === '' || strcmp((string)$it['ts'], $notif_seen) > 0; ?>
    <a class="dropdown-item d-flex gap-2 py-2 <?= $isnew ? 'fw-semibold' : '' ?>" href="<?= h($it['url']) ?>" style="white-space:normal">
      <i class="bi bi-<?= h($it['icon']) ?> mt-1 <?= $isnew ? 'text-primary' : 'text-body-secondary' ?>" aria-hidden="true"></i>
      <span class="flex-grow-1">
        <span class="d-block"><?= h($it['title']) ?></span>
        <span class="d-block text-body-secondary small fw-normal"><?= h(k30_ti_notif_ago($it['ts'])) ?></span>
      </span>
      <?php if ($isnew): ?><span class="badge text-bg-primary align-self-start">nowe</span><?php endif; ?>
    </a>
    <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<?php
$KP_TOPBAR = [
    'brand'  => $org,
    'icon'   => 'pc-display',
    'user'   => $client['name'] ?? $account['login'],
    'logout' => 'index.php?logout=1',
    'notifications' => ob_get_clean(),
];
// VAPID public key — przekazywany do JS przez data-atrybut na <body>
$_vapid = push_vapid_keys();
$vapid_public_key = $_vapid['public'];
$KP_BODY_CLASS = ($KP_BODY_CLASS ?? '');
include __DIR__ . '/_layout_head.php';
?>

<?php if (!empty($account['must_change_password'])):
  // Wymuszona zmiana hasła (np. po nadaniu/zresetowaniu hasła przez admina) — blokuje panel.
  $pwerr  = (string)($_GET['pwerr'] ?? '');
  $forced = true;
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
        <?php if (!$forced): ?>
        <div class="mb-3">
          <label class="form-label" for="cp-cur">Aktualne hasło</label>
          <input type="password" class="form-control" id="cp-cur" name="current" required autocomplete="current-password" autofocus>
        </div>
        <?php endif; ?>
        <div class="mb-3">
          <label class="form-label" for="cp-new">Nowe hasło</label>
          <input type="password" class="form-control" id="cp-new" name="new" required minlength="8" autocomplete="new-password" placeholder="min. 8 znaków"
                 <?= $forced ? 'autofocus' : '' ?> oninput="kpPwMeter(this,'cp-new-meter')">
          <div class="progress mt-1" style="height:5px" aria-hidden="true">
            <div class="progress-bar" id="cp-new-meter-bar" style="width:0%"></div>
          </div>
          <div class="form-text" id="cp-new-meter-text" aria-live="polite"></div>
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
  $dostepy_tabs   = ['online','vlab','dysk','licencje','pfron'];
  $pomoc_tabs     = ['problem','ustawienia'];
  $nauka_active   = in_array($tab, $nauka_tabs, true);
  $dostepy_active = in_array($tab, $dostepy_tabs, true);
  $pomoc_active   = in_array($tab, $pomoc_tabs, true);

  // Kolory kafli w motywie Metro — własne klasy .kp-tile-1..8 (zdefiniowane w _layout_head.php),
  // dobrane pod kontrast WCAG AA (≥4.5:1 z białym tekstem kafla). Poza motywem Metro te klasy
  // nic nie stylują (bez efektu na innych schematach).
  $metro_tile_colors = ['kp-tile-1','kp-tile-2','kp-tile-3','kp-tile-4','kp-tile-5','kp-tile-6','kp-tile-7','kp-tile-8'];
  $metro_i = 0;
  $mc = function () use (&$metro_i, $metro_tile_colors) { return $metro_tile_colors[$metro_i++ % count($metro_tile_colors)]; };
?>
<nav class="container-xl px-3 pt-3 <?= $tab === 'dane' ? 'kp-nav-startpage' : '' ?>" aria-label="Sekcje panelu">
  <ul class="nav nav-tabs">

    <li class="nav-item">
      <a class="nav-link <?= $mc() ?> <?= $tab==='dane'?'active':'' ?>" href="?tab=dane" <?= $tab==='dane'?'aria-current="page"':'' ?>>
        <i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Dane kursanta
      </a>
    </li>

    <!-- Nauka: lekcje, dydaktyka/eLearning, oceny -->
    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle <?= $mc() ?> <?= $nauka_active?'active':'' ?>" href="#" role="button"
         data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-mortarboard me-1" aria-hidden="true"></i>Nauka
        <?php if ($hw_pending_total > 0): ?><span class="badge text-bg-warning ms-1"><?= $hw_pending_total ?><span class="visually-hidden"> zadań do oddania</span></span><?php endif; ?>
      </a>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item <?= $mc() ?> <?= $tab==='lekcje'?'active':'' ?>" href="?tab=lekcje" <?= $tab==='lekcje'?'aria-current="page"':'' ?>>
          <i class="bi bi-calendar-check me-2" aria-hidden="true"></i>Moje lekcje</a></li>
        <li><a class="dropdown-item <?= $mc() ?> <?= $tab==='zadania'?'active':'' ?>" href="?tab=zadania" <?= $tab==='zadania'?'aria-current="page"':'' ?>>
          <i class="bi bi-journal-check me-2" aria-hidden="true"></i>Dydaktyka / eLearning
          <?php if ($hw_pending_total > 0): ?><span class="badge text-bg-warning ms-2"><?= $hw_pending_total ?></span><?php endif; ?></a></li>
        <li><a class="dropdown-item <?= $mc() ?> <?= $tab==='oceny'?'active':'' ?>" href="?tab=oceny" <?= $tab==='oceny'?'aria-current="page"':'' ?>>
          <i class="bi bi-table me-2" aria-hidden="true"></i>Oceny</a></li>
        <li><a class="dropdown-item <?= $mc() ?> <?= $tab==='plan'?'active':'' ?>" href="?tab=plan" <?= $tab==='plan'?'aria-current="page"':'' ?>>
          <i class="bi bi-list-check me-2" aria-hidden="true"></i>Plan nauczania</a></li>
        <li><a class="dropdown-item <?= $mc() ?> <?= $tab==='testy'?'active':'' ?>" href="?tab=testy" <?= $tab==='testy'?'aria-current="page"':'' ?>>
          <i class="bi bi-card-checklist me-2" aria-hidden="true"></i>Testy</a></li>
      </ul>
    </li>

    <li class="nav-item">
      <a class="nav-link <?= $mc() ?> <?= $tab==='komunikaty'?'active':'' ?>" href="?tab=komunikaty" <?= $tab==='komunikaty'?'aria-current="page"':'' ?>>
        <i class="bi bi-megaphone me-1" aria-hidden="true"></i>Komunikaty
        <?php if ($notices_unread > 0): ?><span class="badge text-bg-danger ms-1"><?= $notices_unread ?><span class="visually-hidden"> nieprzeczytanych</span></span><?php endif; ?>
      </a>
    </li>

    <li class="nav-item">
      <a class="nav-link <?= $mc() ?> <?= $tab==='wiadomosci'?'active':'' ?>" href="?tab=wiadomosci" <?= $tab==='wiadomosci'?'aria-current="page"':'' ?>>
        <i class="bi bi-envelope me-1" aria-hidden="true"></i>Wiadomości
        <?php if ($msg_unread > 0): ?><span class="badge text-bg-danger ms-1"><?= $msg_unread ?><span class="visually-hidden"> nieprzeczytanych</span></span><?php endif; ?>
      </a>
    </li>

    <?php if (!$is_minor): ?>
    <li class="nav-item">
      <a class="nav-link <?= $mc() ?> <?= $tab==='rozliczenia'?'active':'' ?>" href="?tab=rozliczenia" <?= $tab==='rozliczenia'?'aria-current="page"':'' ?>>
        <i class="bi bi-receipt me-1" aria-hidden="true"></i>Rozliczenia
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $mc() ?> <?= $tab==='upowaznieni'?'active':'' ?>" href="?tab=upowaznieni" <?= $tab==='upowaznieni'?'aria-current="page"':'' ?>>
        <i class="bi bi-person-check me-1" aria-hidden="true"></i>Upoważnieni
      </a>
    </li>
    <?php endif; ?>

    <!-- Dostępy i narzędzia -->
    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle <?= $mc() ?> <?= $dostepy_active?'active':'' ?>" href="#" role="button"
         data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-grid me-1" aria-hidden="true"></i>Dostępy
      </a>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item <?= $mc() ?> <?= $tab==='online'?'active':'' ?>" href="?tab=online" <?= $tab==='online'?'aria-current="page"':'' ?>>
          <i class="bi bi-camera-video me-2" aria-hidden="true"></i>Szkolenia online</a></li>
        <li><a class="dropdown-item <?= $mc() ?> <?= $tab==='vlab'?'active':'' ?>" href="?tab=vlab" <?= $tab==='vlab'?'aria-current="page"':'' ?>>
          <i class="bi bi-code-square me-2" aria-hidden="true"></i>VLab</a></li>
        <li><a class="dropdown-item <?= $mc() ?> <?= $tab==='dysk'?'active':'' ?>" href="?tab=dysk" <?= $tab==='dysk'?'aria-current="page"':'' ?>>
          <i class="bi bi-hdd-network me-2" aria-hidden="true"></i>Mój dysk</a></li>
        <li><a class="dropdown-item <?= $mc() ?> <?= $tab==='licencje'?'active':'' ?>" href="?tab=licencje" <?= $tab==='licencje'?'aria-current="page"':'' ?>>
          <i class="bi bi-key me-2" aria-hidden="true"></i>Licencje
          <?php if (!empty($my_licenses)): ?><span class="badge text-bg-secondary ms-2"><?= count($my_licenses) ?></span><?php endif; ?></a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item <?= $mc() ?> <?= $tab==='pfron'?'active':'' ?>" href="?tab=pfron" <?= $tab==='pfron'?'aria-current="page"':'' ?>>
          <i class="bi bi-shield-lock me-2" aria-hidden="true"></i>PFRON (konsultacje)</a></li>
      </ul>
    </li>

    <!-- Pomoc / zgłoszenie problemu -->
    <li class="nav-item">
      <a class="nav-link <?= $mc() ?> <?= $tab==='problem'?'active':'' ?>" href="?tab=problem" <?= $tab==='problem'?'aria-current="page"':'' ?>>
        <i class="bi bi-life-preserver me-1" aria-hidden="true"></i><span class="d-none d-xl-inline">Pomoc</span>
      </a>
    </li>

    <!-- Aktywność — dziennik zdarzeń konta -->
    <li class="nav-item">
      <a class="nav-link <?= $mc() ?> <?= $tab==='aktywnosc'?'active':'' ?>" href="?tab=aktywnosc" <?= $tab==='aktywnosc'?'aria-current="page"':'' ?>
         title="Aktywność konta" aria-label="Aktywność konta">
        <i class="bi bi-clock-history me-1" aria-hidden="true"></i><span class="d-none d-xl-inline">Aktywność</span>
      </a>
    </li>

    <!-- Ustawienia — bezpośrednio w nawigacji -->
    <li class="nav-item">
      <a class="nav-link <?= $mc() ?> <?= $tab==='ustawienia'?'active':'' ?>" href="?tab=ustawienia" <?= $tab==='ustawienia'?'aria-current="page"':'' ?>
         title="Ustawienia" aria-label="Ustawienia">
        <i class="bi bi-gear<?= $tab==='ustawienia'?'-fill':'' ?> me-1" aria-hidden="true"></i><span class="d-none d-xl-inline">Ustawienia</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="nav-link <?= $mc() ?> <?= $tab==='regulaminy'?'active':'' ?>" href="?tab=regulaminy" <?= $tab==='regulaminy'?'aria-current="page"':'' ?>>
        <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Regulaminy
        <?php if (!empty($terms_pending)): ?><span class="badge text-bg-danger ms-1"><?= count($terms_pending) ?><span class="visually-hidden"> do akceptacji</span></span><?php endif; ?>
      </a>
    </li>

  </ul>
</nav>

<main id="main" class="container-xl px-3 py-4">

<!-- ── Pytanie o czytnik ekranu (jednorazowe, localStorage) ──────────── -->
<div id="kpSrBanner" class="alert alert-primary alert-dismissible d-flex align-items-center gap-3 mb-3" role="dialog"
     aria-labelledby="kpSrBannerTitle" aria-describedby="kpSrBannerDesc" style="display:none!important">
  <i class="bi bi-universal-access-circle fs-4 flex-shrink-0" aria-hidden="true"></i>
  <div class="flex-grow-1">
    <div class="fw-semibold mb-1" id="kpSrBannerTitle">Ułatwienia dostępu</div>
    <div id="kpSrBannerDesc" class="small mb-2">Czy korzystasz z czytnika ekranu (np. NVDA, JAWS, VoiceOver)?</div>
    <div class="d-flex gap-2 flex-wrap">
      <button type="button" class="btn btn-primary btn-sm" id="kpSrYes">Tak, korzystam</button>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="kpSrNo">Nie</button>
    </div>
  </div>
  <button type="button" class="btn-close" id="kpSrDismiss" aria-label="Zamknij"></button>
</div>
<script>
(function(){
  var KEY_SR  = 'kp_sr_mode';
  var KEY_CAL = 'kp_cal_collapsed';
  var banner  = document.getElementById('kpSrBanner');
  if (!banner) return;
  // Pokaż tylko raz (gdy brak odpowiedzi)
  if (localStorage.getItem(KEY_SR) === null) {
    banner.style.removeProperty('display');
  }
  function dismiss(sr){
    localStorage.setItem(KEY_SR, sr ? '1' : '0');
    if (sr) localStorage.setItem(KEY_CAL, '1'); // schowaj kalendarz dla SR
    banner.remove();
    // Aktualizuj stan kalendarza na żywo
    if (sr) {
      var body = document.getElementById('kpCalBody');
      var icon = document.querySelector('#kpCalToggle .kp-cal-icon');
      var btn  = document.getElementById('kpCalToggle');
      if (body) body.style.display = 'none';
      if (btn)  btn.setAttribute('aria-expanded','false');
      if (icon) { icon.classList.remove('bi-chevron-up'); icon.classList.add('bi-chevron-down'); }
    }
  }
  document.getElementById('kpSrYes').addEventListener('click',     function(){ dismiss(true);  });
  document.getElementById('kpSrNo').addEventListener('click',      function(){ dismiss(false); });
  document.getElementById('kpSrDismiss').addEventListener('click', function(){ dismiss(false); });
})();
</script>

<?php // ── Baner wakacyjny — gdy trwa okres typu vacation ─────────────────────────
$ti_vac = ti_current_vacation();
if ($ti_vac): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 shadow-sm mb-4" role="alert">
  <i class="bi bi-sun-fill fs-3 flex-shrink-0" aria-hidden="true"></i>
  <div>
    <div class="fw-bold">Trwają wakacje — przerwa w zajęciach</div>
    <div class="small"><?= h($ti_vac['name']) ?> ·
      <?= h(date('d.m.Y', strtotime($ti_vac['date_from']))) ?> – <?= h(date('d.m.Y', strtotime($ti_vac['date_to']))) ?>.
      Zajęcia wznawiamy <strong><?= h(date('d.m.Y', strtotime($ti_vac['resume_date']))) ?></strong>.</div>
    <?php if (!empty($ti_vac['note'])): ?><div class="small mt-1"><?= h($ti_vac['note']) ?></div><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($notices_unread > 0): ?>
<div class="alert alert-info d-flex align-items-center gap-3 shadow-sm mb-4" role="alert">
  <i class="bi bi-megaphone-fill fs-2 flex-shrink-0 text-primary" aria-hidden="true"></i>
  <div class="flex-grow-1 min-width-0">
    <div class="fw-bold"><?= $notices_unread === 1 ? '1 nieprzeczytany komunikat' : $notices_unread . ' nieprzeczytane komunikaty' ?> placówki</div>
    <div class="small text-body-secondary">Sprawdź nowe ogłoszenia w zakładce Komunikaty.</div>
  </div>
  <a href="?tab=komunikaty" class="btn btn-primary btn-sm flex-shrink-0">
    <i class="bi bi-megaphone me-1" aria-hidden="true"></i>Przejdź
  </a>
</div>
<?php endif; ?>

<?php if (!empty($today_lessons)): ?>
<!-- ── Dzisiejsze zajęcia — baner całodniowy ─────────────────────────────────── -->
<div class="card border-primary shadow-sm mb-4" role="region" aria-label="Zajęcia dzisiaj">
  <div class="card-body d-flex align-items-start gap-3 py-3">
    <i class="bi bi-calendar-check-fill fs-2 flex-shrink-0 text-primary mt-1" aria-hidden="true"></i>
    <div class="flex-grow-1 min-width-0">
      <div class="fw-bold fs-5 text-primary mb-2">Masz dziś zajęcia</div>
      <?php foreach ($today_lessons as $_tl): ?>
      <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
        <span class="fw-semibold"><?= h($_tl['course_name']) ?></span>
        <?php if (!empty($_tl['time_from'])): ?>
          <span class="text-body-secondary">
            <i class="bi bi-clock me-1" aria-hidden="true"></i><?= h($_tl['time_from']) ?><?= !empty($_tl['time_to']) ? '–'.h($_tl['time_to']) : '' ?>
          </span>
        <?php endif; ?>
        <?php if (!empty($_tl['topic'])): ?>
          <span class="text-body-secondary">· <?= h($_tl['topic']) ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

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
<div class="alert alert-info d-flex gap-2 mb-3" role="note">
  <i class="bi bi-airplane fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div class="flex-grow-1 min-width-0">
    <div class="fw-semibold mb-1">Zaplanowana nieobecność prowadzącego</div>
    <ul class="list-unstyled small mb-0 d-flex flex-column gap-1">
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
        <span class="text-nowrap"><?= h($range) ?></span>
        <span class="badge <?= $ongoing ? 'text-bg-warning' : 'text-bg-secondary' ?> ms-1"><?= $ongoing ? 'trwa teraz' : 'wkrótce' ?></span>
        <?php if ($lvcourses !== ''): ?><span class="d-block text-body-secondary">Dotyczy zajęć: <?= h(str_replace(',', ', ', $lvcourses)) ?></span><?php endif; ?>
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

<?php
  // ── Subskrypcja / pobranie kalendarza moich lekcji (iCal) — modal dostępny z każdej zakładki
  $kp_cal_tok    = k30_ti_calendar_token((int)$student['id']);
  $kp_cal_base   = rtrim(defined('APP_URL') ? APP_URL : '', '/')
                   . '/karty30/ti/kursant/ical.php?id=' . (int)$student['id'] . '&t=' . $kp_cal_tok;
  $kp_cal_webcal = preg_replace('#^https?://#i', 'webcal://', $kp_cal_base);
?>
<div class="modal fade" id="kpCalSubModal" tabindex="-1" aria-labelledby="kpCalSubTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title" id="kpCalSubTitle"><i class="bi bi-calendar-check me-2"></i>Kalendarz lekcji — subskrypcja i pobranie</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <p class="text-body-secondary small">
        Kalendarz obejmuje wszystkie Twoje lekcje. Dodaj go do Kalendarza Google, Apple Calendar
        lub Outlooka, a terminy zajęć będą aktualizować się automatycznie — bez ręcznego przepisywania.
      </p>

      <label class="form-label fw-semibold" for="kpCalUrl">Adres kanału (URL do subskrypcji)</label>
      <div class="input-group mb-1">
        <input type="text" class="form-control" id="kpCalUrl" value="<?= h($kp_cal_base) ?>" readonly
               onfocus="this.select()" aria-describedby="kpCalUrlHelp">
        <button type="button" class="btn btn-outline-secondary" id="kpCalCopy"
                data-copy-target="kpCalUrl"><i class="bi bi-clipboard me-1"></i>Kopiuj</button>
      </div>
      <p id="kpCalUrlHelp" class="form-text">
        Wklej ten adres w Kalendarzu Google („Inne kalendarze → Dodaj z adresu URL"),
        Apple Calendar lub Outlook, aby kalendarz aktualizował się automatycznie.
      </p>

      <div class="d-flex flex-wrap gap-2 my-3">
        <a class="btn btn-primary btn-sm" href="<?= h($kp_cal_base) ?>">
          <i class="bi bi-download me-1"></i>Pobierz plik .ics
        </a>
        <a class="btn btn-outline-primary btn-sm" href="<?= h($kp_cal_webcal) ?>">
          <i class="bi bi-calendar-plus me-1"></i>Subskrybuj (webcal)
        </a>
      </div>

      <hr>
      <div class="d-flex align-items-center flex-wrap gap-2">
        <span class="small text-body-secondary"><i class="bi bi-shield-lock me-1"></i>Adres jest prywatny — nie udostępniaj go osobom postronnym.</span>
        <form method="post" class="ms-auto" onsubmit="return confirm('Wygenerować nowy adres? Dotychczasowy link przestanie działać.')">
          <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
          <input type="hidden" name="_op" value="cal_token_reset">
          <button type="submit" class="btn btn-outline-danger btn-sm">
            <i class="bi bi-arrow-repeat me-1"></i>Wygeneruj nowy adres
          </button>
        </form>
      </div>
    </div>
  </div></div>
</div>

<!-- Szybki popup zachęcający do dodania kalendarza lekcji (iCal) — position:fixed, prawy dolny róg -->
<div id="kpIcalPopup"
     role="dialog" aria-modal="true" aria-labelledby="kpIcalPopupTitle"
     tabindex="-1"
     style="display:none;position:fixed;z-index:1080;bottom:1.5rem;right:1.5rem;
            width:min(380px,calc(100vw - 2rem));
            background:#1e293b;color:#f1f5f9;
            border:2px solid #2563eb;border-radius:.6rem;
            box-shadow:0 8px 32px rgba(0,0,0,.5);">
  <div style="background:#2563eb;color:#fff;border-radius:.45rem .45rem 0 0;
              padding:.55rem 1rem;display:flex;align-items:center;gap:.5rem;">
    <i class="bi bi-calendar-plus" aria-hidden="true"></i>
    <h2 class="mb-0 fw-bold" id="kpIcalPopupTitle" style="font-size:1rem">Dodaj lekcje do kalendarza</h2>
  </div>
  <div style="padding:.9rem 1rem .5rem">
    <p style="font-size:.875rem;margin:0 0 .5rem">
      Subskrybuj kanał iCal, aby terminy Twoich zajęć pojawiały się automatycznie
      w Kalendarzu Google, Apple Calendar lub Outlooku — bez ręcznego przepisywania.
    </p>
  </div>
  <div style="padding:.5rem 1rem .8rem;display:flex;justify-content:flex-end;gap:.5rem">
    <button type="button" id="kpIcalPopupLater"
            style="background:transparent;color:#cbd5e1;border:1px solid #475569;border-radius:.375rem;
                   padding:.3rem .8rem;font-size:.875rem;cursor:pointer">Nie teraz</button>
    <button type="button" id="kpIcalPopupGo" data-bs-toggle="modal" data-bs-target="#kpCalSubModal"
            style="background:#2563eb;color:#fff;border:none;border-radius:.375rem;
                   padding:.3rem .9rem;font-weight:600;cursor:pointer;font-size:.875rem">
      <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Dodaj kalendarz
    </button>
  </div>
</div>
<script>
document.addEventListener('click', function(e){
  var b = e.target.closest('[data-copy-target]'); if (!b) return;
  var inp = document.getElementById(b.getAttribute('data-copy-target')); if (!inp) return;
  var done = function(){
    var orig = b.innerHTML;
    b.innerHTML = '<i class="bi bi-check2 me-1"></i>Skopiowano';
    setTimeout(function(){ b.innerHTML = orig; }, 1500);
  };
  inp.focus(); inp.select();
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(inp.value).then(done, function(){ try { document.execCommand('copy'); done(); } catch(_){} });
  } else { try { document.execCommand('copy'); done(); } catch(_){} }
});
document.addEventListener('DOMContentLoaded', function() {
  var STORE_KEY = 'ti_ical_popup_seen_student';
  var popup = document.getElementById('kpIcalPopup');
  if (!popup) return;
  try { if (localStorage.getItem(STORE_KEY) === '1') return; } catch(e) {}

  var dismiss = function(){
    try { localStorage.setItem(STORE_KEY, '1'); } catch(e) {}
    popup.style.display = 'none';
  };
  setTimeout(function(){ popup.style.display = 'block'; }, 800);
  document.getElementById('kpIcalPopupLater').addEventListener('click', dismiss);
  document.getElementById('kpIcalPopupGo').addEventListener('click', dismiss);
});
</script>

<?php if ($tab === 'dane'):
  // ── Launcher „Start" (motyw Metro) — ściana dużych kafli widoczna od razu po zalogowaniu.
  // Poza motywem Metro sekcja jest niewidoczna (kp-startwall ma display:none) — bez wpływu
  // na pozostałe schematy kolorów.
  $kp_welcome_name  = trim((string)($client['name'] ?? ''));
  $kp_welcome_first = $kp_welcome_name !== '' ? explode(' ', $kp_welcome_name)[0] : ((string)($account['login'] ?? 'Kursancie'));
  // Kompletna lista — ta ściana ZASTĘPUJE (nie duplikuje) kompaktowy pasek kafli z góry strony:
  // na tej zakładce pasek jest ukrywany w motywie Metro (patrz .kp-nav-startpage niżej), więc
  // Start musi dawać dostęp do wszystkich sekcji, łącznie z tymi schowanymi wcześniej w rozwijanych
  // podgrupach „Nauka”/„Dostępy”.
  $kp_start_items = [
    ['tab' => 'lekcje',      'icon' => 'calendar-check',   'label' => 'Moje lekcje'],
    ['tab' => 'zadania',     'icon' => 'journal-check',    'label' => 'Dydaktyka / eLearning', 'badge' => $hw_pending_total],
    ['tab' => 'oceny',       'icon' => 'table',             'label' => 'Oceny'],
    ['tab' => 'plan',        'icon' => 'list-check',       'label' => 'Plan nauczania'],
    ['tab' => 'testy',       'icon' => 'card-checklist',   'label' => 'Testy'],
    ['tab' => 'komunikaty',  'icon' => 'megaphone',        'label' => 'Komunikaty',             'badge' => $notices_unread],
    ['tab' => 'wiadomosci',  'icon' => 'envelope',         'label' => 'Wiadomości',             'badge' => $msg_unread],
  ];
  if (!$is_minor) {
    $kp_start_items[] = ['tab' => 'rozliczenia',  'icon' => 'receipt',      'label' => 'Rozliczenia'];
    $kp_start_items[] = ['tab' => 'upowaznieni',  'icon' => 'person-check', 'label' => 'Upoważnieni'];
  }
  $kp_start_items[] = ['tab' => 'online',      'icon' => 'camera-video', 'label' => 'Szkolenia online'];
  $kp_start_items[] = ['tab' => 'vlab',        'icon' => 'code-square',  'label' => 'VLab'];
  $kp_start_items[] = ['tab' => 'licencje',    'icon' => 'key',          'label' => 'Licencje',   'badge' => !empty($my_licenses) ? count($my_licenses) : 0];
  $kp_start_items[] = ['tab' => 'pfron',       'icon' => 'shield-lock',  'label' => 'PFRON (konsultacje)'];
  $kp_start_items[] = ['tab' => 'problem',     'icon' => 'life-preserver', 'label' => 'Pomoc'];
  $kp_start_items[] = ['tab' => 'aktywnosc',   'icon' => 'clock-history', 'label' => 'Aktywność'];
  $kp_start_items[] = ['tab' => 'ustawienia',  'icon' => 'gear',         'label' => 'Ustawienia'];
  $kp_start_items[] = ['tab' => 'regulaminy',  'icon' => 'file-earmark-text', 'label' => 'Regulaminy', 'badge' => !empty($terms_pending) ? count($terms_pending) : 0];
  $kp_si = 0;
?>
<div class="kp-startwall">
  <div class="kp-metro-notice" role="note">
    <i class="bi bi-info-circle-fill fs-4 flex-shrink-0" aria-hidden="true"></i>
    <div class="flex-grow-1 small">
      <div class="fw-bold mb-1">Zmieniliśmy wygląd panelu</div>
      <div>Wypróbuj nowy motyw Metro — płaskie, kolorowe kafle zamiast dotychczasowych kart.
        Poprzedni wygląd przywrócisz w każdej chwili w menu dostępności (ikona po lewej krawędzi ekranu).</div>
    </div>
    <button type="button" id="kp-metro-notice-close" class="btn-close flex-shrink-0" aria-label="Zamknij ten komunikat"></button>
  </div>
  <div class="kp-startwall-welcome">
    <p class="kp-startwall-hello mb-1">Witaj, <?= h($kp_welcome_first) ?>!</p>
    <p class="kp-startwall-sub mb-0">Wybierz, co chcesz dziś zrobić.</p>
  </div>
  <nav class="kp-startwall-grid" aria-label="Szybki start">
    <?php foreach ($kp_start_items as $it): $kp_si++; ?>
    <a class="kp-tile-lg kp-tile-<?= (($kp_si - 1) % 8) + 1 ?>" href="?tab=<?= h($it['tab']) ?>">
      <i class="bi bi-<?= h($it['icon']) ?>" aria-hidden="true"></i>
      <span><?= h($it['label']) ?><?php if (!empty($it['badge'])): ?> <span class="badge text-bg-light text-dark"><?= (int)$it['badge'] ?><span class="visually-hidden"> nowych</span></span><?php endif; ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
</div>

  <!-- ── Dane kursanta — strona startowa panelu ─────────────────────────────── -->
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h1 class="h5 fw-bold mb-0"><i class="bi bi-person-vcard text-primary me-1" aria-hidden="true"></i>Dane kursanta</h1>
    <a href="<?= APP_URL ?>/karty30/clients/card_print.php?kursant=1" target="_blank"
       class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-printer me-1" aria-hidden="true"></i>Wydrukuj kartę beneficjenta
    </a>
  </div>

  <!-- ── Pasek postępu kursu ─────────────────────────────────────────────── -->
  <?php if ($progress_total > 0): ?>
  <div class="mb-3">
    <div class="d-flex justify-content-between small text-body-secondary mb-1">
      <span><i class="bi bi-graph-up me-1"></i>Postęp kursu</span>
      <span><?= $progress_done ?>/<?= $progress_total ?> lekcji (<?= $progress_pct ?>%)</span>
    </div>
    <div class="progress" style="height:8px" role="progressbar"
         aria-valuenow="<?= $progress_pct ?>" aria-valuemin="0" aria-valuemax="100"
         aria-label="Postęp kursu <?= $progress_pct ?>%">
      <div class="progress-bar bg-primary" style="width:<?= $progress_pct ?>%"></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Dashboard widżety ─────────────────────────────────────────────── -->
  <?php
    try {
        $instructors_for_quick = db_all(
            "SELECT DISTINCT u.id, u.name FROM users u
             JOIN k30_ti_courses c ON c.instructor_id=u.id
             JOIN k30_ti_sessions s ON s.course_id=c.id
             JOIN k30_ti_enrollments e ON e.course_id=c.id AND e.client_id=?
             WHERE s.lesson_date >= date('now','-30 days')
             ORDER BY s.lesson_date DESC LIMIT 5",
            [(int)$student['client_id']]
        );
    } catch (\Throwable $e) { $instructors_for_quick = []; }
  ?>
  <div class="row g-3 mb-4">
    <!-- Następna lekcja -->
    <div class="col-sm-6 col-lg-3">
      <div class="card h-100 border-0 shadow-sm kp-dash-card">
        <div class="card-body">
          <div class="text-body-secondary small mb-1"><i class="bi bi-calendar-check me-1"></i>Następna lekcja</div>
          <?php if ($next_lesson): ?>
            <div class="fw-bold"><?= date('d.m', strtotime($next_lesson['lesson_date'])) ?></div>
            <div class="small text-body-secondary"><?= h($next_lesson['course_name']) ?></div>
            <?php if (!empty($next_lesson['instructor_name'])): ?>
            <div class="small text-body-secondary"><i class="bi bi-person me-1" aria-hidden="true"></i><?= h($next_lesson['instructor_name']) ?></div>
            <?php endif; ?>
            <?php if (!empty($next_lesson['time_from'])): ?>
            <div class="small text-body-secondary"><?= substr((string)$next_lesson['time_from'], 0, 5) ?><?= !empty($next_lesson['time_to']) ? '–'.substr((string)$next_lesson['time_to'], 0, 5) : '' ?></div>
            <?php endif; ?>
          <?php else: ?>
            <div class="small text-body-secondary">Brak zaplanowanych</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <!-- Zadania do oddania -->
    <div class="col-sm-6 col-lg-3">
      <a href="?tab=zadania" class="card h-100 border-0 shadow-sm text-decoration-none text-body kp-dash-card">
        <div class="card-body">
          <div class="text-body-secondary small mb-1"><i class="bi bi-journal-check me-1"></i>Zadania do oddania</div>
          <div class="fw-bold fs-4 <?= $hw_pending_total > 0 ? 'text-warning' : '' ?>"><?= $hw_pending_total ?></div>
          <div class="small text-body-secondary"><?= $hw_pending_total > 0 ? 'Wymaga uwagi' : 'Wszystko oddane' ?></div>
        </div>
      </a>
    </div>
    <!-- Wiadomości -->
    <div class="col-sm-6 col-lg-3">
      <a href="?tab=wiadomosci" class="card h-100 border-0 shadow-sm text-decoration-none text-body kp-dash-card">
        <div class="card-body">
          <div class="text-body-secondary small mb-1"><i class="bi bi-envelope me-1"></i>Nowe wiadomości</div>
          <div class="fw-bold fs-4 <?= $msg_unread > 0 ? 'text-primary' : '' ?>"><?= (int)$msg_unread ?></div>
          <div class="small text-body-secondary"><?= $msg_unread > 0 ? 'Nieprzeczytane' : 'Brak nowych' ?></div>
        </div>
      </a>
    </div>
    <!-- Streak aktywności -->
    <div class="col-sm-6 col-lg-3">
      <div class="card h-100 border-0 shadow-sm kp-dash-card">
        <div class="card-body">
          <div class="text-body-secondary small mb-1"><i class="bi bi-fire me-1"></i>Streak aktywności</div>
          <div class="fw-bold fs-4 <?= $streak >= 7 ? 'text-danger' : ($streak >= 3 ? 'text-warning' : '') ?>"><?= $streak ?></div>
          <div class="small text-body-secondary"><?= $streak === 1 ? 'dzień z rzędu' : 'dni z rzędu' ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php if ($instructors_for_quick): ?>
  <div class="mb-4">
    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalQuickMsg">
      <i class="bi bi-chat-dots me-1"></i>Napisz do prowadzącego
    </button>
  </div>
  <?php endif; ?>

  <!-- ── Duży skrót do eLearning — widoczny po zalogowaniu ────────────────────── -->
  <div class="card border-0 shadow-sm mb-4 text-bg-primary">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <i class="bi bi-mortarboard-fill flex-shrink-0" style="font-size:2.6rem" aria-hidden="true"></i>
      <div class="flex-grow-1 min-width-0">
        <h2 class="h5 fw-bold mb-1">Dydaktyka / eLearning</h2>
        <p class="mb-1">Materiały do nauki, zadania domowe i oceny — wszystko w jednym miejscu.</p>
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
        <?php
          $cl_status_key = (string)($client['status'] ?? 'enrolled');
          $cl_status_cfg = K30_CLIENT_STATUSES[$cl_status_key] ?? K30_CLIENT_STATUSES['enrolled'];
        ?>
        <dt class="col-sm-3 text-body-secondary fw-normal">Status</dt>
        <dd class="col-sm-9">
          <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:2rem;font-size:.78rem;font-weight:600;background:<?= h($cl_status_cfg['bg']) ?>;color:<?= h($cl_status_cfg['color']) ?>">
            <?php if (!empty($cl_status_cfg['icon'])): ?><i class="bi <?= h($cl_status_cfg['icon']) ?>" aria-hidden="true"></i><?php endif; ?>
            <?= h($cl_status_cfg['label']) ?>
          </span>
        </dd>
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

  <!-- ── Moje kursy — z opcją wypisania się ──────────────────────────────────── -->
  <?php if (isset($_GET['unenrolled'])): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Zostałeś/aś wypisany/a z kursu. Potwierdzenie zostało wysłane na Twój adres e-mail.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
  <?php elseif (isset($_GET['unenroll_pending'])): ?>
  <div class="alert alert-info alert-dismissible fade show" role="alert">
    <i class="bi bi-envelope me-1" aria-hidden="true"></i><strong>Wniosek złożony.</strong>
    Ponieważ jesteś osobą niepełnoletnią, wypisanie wymaga zgody opiekuna prawnego i administratora.
    Na adres e-mail opiekuna zostało wysłane zapytanie o potwierdzenie.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
  <?php endif; ?>

  <?php
    // Dane kontaktowe prowadzących (tylko gdy share_contact=1)
    $_instr_contacts = [];
    if ($active_courses) {
        try {
            $_ic_rows = db_all(
                "SELECT c.id AS course_id, u.name AS instr_name,
                        COALESCE(u.alt_email, u.email) AS contact_email, u.phone_number
                 FROM k30_ti_enrollments e
                 JOIN k30_ti_courses c ON c.id=e.course_id
                 JOIN users u ON u.id=c.instructor_id AND COALESCE(u.share_contact,0)=1
                 WHERE e.client_id=? AND e.status='active'",
                [(int)$student['client_id']]
            );
            foreach ($_ic_rows as $_ic) {
                $_instr_contacts[(int)$_ic['course_id']] = $_ic;
            }
        } catch (\Throwable $e) {}
    }
  ?>

  <?php if ($active_courses): ?>
  <div class="card mb-4">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-pc-display text-primary" aria-hidden="true"></i>Moje kursy
    </div>
    <ul class="list-group list-group-flush" role="list">
      <?php foreach ($active_courses as $ec): ?>
      <?php $_ic = $_instr_contacts[(int)$ec['course_id']] ?? null; ?>
      <li class="list-group-item py-2">
        <div class="d-flex align-items-center gap-3">
          <div class="flex-grow-1 min-width-0">
            <span class="fw-semibold"><?= h($ec['course_name']) ?></span>
            <?php if (!empty($ec['instructor_name'])): ?>
            <span class="text-body-secondary small ms-2">· <?= h($ec['instructor_name']) ?></span>
            <?php endif; ?>
          </div>
          <button type="button" class="btn btn-outline-danger btn-sm flex-shrink-0"
                  data-bs-toggle="modal" data-bs-target="#modalUnenroll"
                  data-enroll-id="<?= (int)$ec['id'] ?>"
                  data-course-name="<?= h($ec['course_name']) ?>"
                  data-minor="<?= $is_minor ? '1' : '0' ?>"
                  aria-label="Wypisz się z kursu <?= h($ec['course_name']) ?>">
            <i class="bi bi-box-arrow-left me-1" aria-hidden="true"></i>Wypisz się
          </button>
        </div>
        <?php if ($_ic): /* Kontakt do prowadzącego — tylko gdy share_contact=1 */ ?>
        <div class="mt-1 small text-body-secondary d-flex flex-wrap gap-3">
          <?php if (!empty($_ic['contact_email'])): ?>
          <a href="mailto:<?= h($_ic['contact_email']) ?>" class="text-decoration-none">
            <i class="bi bi-envelope me-1" aria-hidden="true"></i><?= h($_ic['contact_email']) ?>
          </a>
          <?php endif; ?>
          <?php if (!empty($_ic['phone_number'])): ?>
          <a href="tel:<?= h($_ic['phone_number']) ?>" class="text-decoration-none">
            <i class="bi bi-telephone me-1" aria-hidden="true"></i><?= h($_ic['phone_number']) ?>
          </a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <!-- Modal potwierdzenia wypisania -->
  <div class="modal fade" id="modalUnenroll" tabindex="-1" aria-labelledby="modalUnenrollLabel" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header border-0 pb-0">
          <h2 class="modal-title h5 fw-bold" id="modalUnenrollLabel">
            <i class="bi bi-exclamation-triangle text-warning me-2" aria-hidden="true"></i>Wypisz się z kursu
          </h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <form method="post" id="formUnenroll">
          <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
          <input type="hidden" name="_op"    value="unenroll_course">
          <input type="hidden" name="enrollment_id" id="unenrollEnrollId" value="">
          <div class="modal-body">
            <p>Czy na pewno chcesz wypisać się z kursu <strong id="unenrollCourseName"></strong>?</p>
            <p class="text-body-secondary small mb-3">Ta operacja zmieni Twój status na nieaktywny. Jeśli to pomyłka, skontaktuj się z prowadzącym lub administracją.</p>
            <div class="mb-3">
              <label class="form-label" for="unenrollReason">Powód wypisania <span class="text-body-secondary fw-normal">(opcjonalnie)</span></label>
              <textarea class="form-control" id="unenrollReason" name="reason" rows="3" maxlength="1000"
                        placeholder="Np. zmiana planów, inne obowiązki…"></textarea>
            </div>
            <div id="unenrollNoteAdult" class="alert alert-warning py-2 small d-flex gap-2 align-items-start" role="note">
              <i class="bi bi-envelope me-1 flex-shrink-0 mt-1" aria-hidden="true"></i>
              Na Twój adres e-mail zostanie wysłane potwierdzenie wypisania.
            </div>
            <div id="unenrollNoteMinor" class="alert alert-info py-2 small d-flex gap-2 align-items-start" role="note" style="display:none!important">
              <i class="bi bi-shield-lock me-1 flex-shrink-0 mt-1" aria-hidden="true"></i>
              Ponieważ jesteś osobą niepełnoletnią, wypisanie wymaga potwierdzenia opiekuna prawnego oraz administratora. Wniosek zostanie wysłany e-mailem do opiekuna.
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
            <button type="submit" class="btn btn-danger fw-semibold">
              <i class="bi bi-box-arrow-left me-1" aria-hidden="true"></i>Wypisz się
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <script>
  (function() {
    var modal = document.getElementById('modalUnenroll');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function(e) {
      var btn = e.relatedTarget;
      var isMinor = btn.getAttribute('data-minor') === '1';
      document.getElementById('unenrollEnrollId').value   = btn.getAttribute('data-enroll-id') || '';
      document.getElementById('unenrollCourseName').textContent = btn.getAttribute('data-course-name') || '';
      document.getElementById('unenrollReason').value = '';
      document.getElementById('unenrollNoteAdult').style.display = isMinor ? 'none' : '';
      document.getElementById('unenrollNoteMinor').style.setProperty('display', isMinor ? 'flex' : 'none', 'important');
    });
  })();
  </script>

  <!-- Komunikat gdy brak aktywnych kursów -->
  <?php if (empty($active_courses)): ?>
  <div class="alert alert-info d-flex align-items-start gap-3 mb-4" role="status">
    <i class="bi bi-info-circle-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
    <div>
      <strong>Nie jesteś obecnie przypisany/a do żadnej grupy.</strong>
      <div class="small mt-1">
        Jeśli właśnie złożyłeś/aś rezygnację lub zmienił się Twój plan zajęć — skontaktuj się z prowadzącym lub administracją,
        aby zapisać się na kurs.
      </div>
      <?php if ($inactive_courses): ?>
      <div class="small mt-1 text-body-secondary">
        Twoje poprzednie kursy:
        <?= implode(', ', array_map(fn($c) => h($c['course_name']), $inactive_courses)) ?>.
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Następna lekcja -->
  <?php if ($next_lesson):
    $nl_date  = date('d.m.Y', strtotime($next_lesson['lesson_date']));
    $nl_day   = ['Mon'=>'Pon','Tue'=>'Wt','Wed'=>'Śr','Thu'=>'Czw','Fri'=>'Pt','Sat'=>'Sob','Sun'=>'Nd'][date('D', strtotime($next_lesson['lesson_date']))] ?? '';
    $nl_today = $next_lesson['lesson_date'] === date('Y-m-d');
    $nl_tom   = $next_lesson['lesson_date'] === date('Y-m-d', strtotime('+1 day'));
    $nl_when  = $nl_today ? 'Dziś' : ($nl_tom ? 'Jutro' : $nl_day . ', ' . $nl_date);
    $nl_time  = $next_lesson['time_from'] ? ' o ' . substr($next_lesson['time_from'], 0, 5) : '';
    if ($nl_today || $nl_tom) $nl_when .= $nl_time;
    $nl_lm    = (string)($next_lesson['lesson_method'] ?? '');
    $nl_enr   = trim((string)($next_lesson['enrollment_meeting_url'] ?? ''));
    $nl_link  = $nl_lm === 'stacjonarna' ? ''
              : (trim((string)($next_lesson['meeting_url'] ?? '')) !== '' ? trim((string)$next_lesson['meeting_url'])
              : ($nl_enr !== '' ? $nl_enr
              : trim((string)($next_lesson['course_meeting_url'] ?? ''))));
    $nl_lm_icon = match($nl_lm) {
        'stacjonarna' => '<i class="bi bi-geo-alt-fill me-1 text-success" aria-hidden="true"></i>Stacjonarna',
        'zdalna_zoom' => '<i class="bi bi-camera-video me-1 text-primary" aria-hidden="true"></i>Zdalna — Zoom',
        'zdalna_inne' => '<i class="bi bi-display me-1" style="color:#6B21A8" aria-hidden="true"></i>Zdalna — Inne',
        default       => '',
    };
  ?>
  <div class="card border-0 shadow-sm mb-4 <?= $nl_today ? 'border-start border-4 border-warning' : '' ?>">
    <div class="card-body d-flex align-items-center gap-3 py-3">
      <a href="?tab=lekcje" class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 text-decoration-none <?= $nl_today ? 'text-bg-warning' : 'text-bg-primary' ?>"
         style="width:48px;height:48px;font-size:1.4rem" aria-hidden="true">
        <i class="bi bi-calendar-event"></i>
      </a>
      <div class="flex-grow-1 min-width-0">
        <div class="small text-body-secondary mb-0">Następna lekcja</div>
        <div class="fw-bold">
          <a href="?tab=lekcje" class="text-decoration-none text-body"><?= h($nl_when) ?><?= $nl_today || $nl_tom ? '' : ($nl_time ? h($nl_time) : '') ?></a>
          <span class="text-body-secondary fw-normal ms-1 small"><?= h($next_lesson['course_name']) ?></span>
        </div>
        <?php if ($next_lesson['topic']): ?>
        <div class="small text-body-secondary text-truncate"><?= h($next_lesson['topic']) ?></div>
        <?php endif; ?>
        <?php if ($nl_lm_icon !== ''): ?>
        <div class="small mt-1"><?= $nl_lm_icon ?></div>
        <?php endif; ?>
      </div>
      <?php if ($nl_link !== ''): ?>
      <a href="<?= h($nl_link) ?>" target="_blank" rel="noopener"
         class="btn btn-success btn-sm flex-shrink-0 d-flex align-items-center gap-1"
         title="Dołącz do lekcji online">
        <i class="bi bi-camera-video" aria-hidden="true"></i>
        <span class="d-none d-sm-inline">Dołącz</span>
      </a>
      <?php else: ?>
      <a href="?tab=lekcje" class="text-body-secondary flex-shrink-0" aria-hidden="true">
        <i class="bi bi-chevron-right"></i>
      </a>
      <?php endif; ?>
    </div>
  </div>
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

  <!-- ── Usługi dodatkowe — podsumowanie (dedykowane IP, VPS, licencje) — na samym dole strony ── -->
  <?php
    $sum_dedips = vlab_dedicated_ip_for_student((int)$student['id']);
    $sum_vps    = vlab_dedicated_server_for_student((int)$student['id']);
    if ($sum_vps && $sum_vps['status'] === 'cancelled') $sum_vps = null;
    $sum_lic    = k30_ti_client_licenses((int)$student['client_id']);
  ?>
  <div class="card border-0 shadow-sm mt-4">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-stars text-primary" aria-hidden="true"></i>Usługi dodatkowe
    </div>
    <div class="card-body">
      <?php if (!$sum_dedips && !$sum_vps && !$sum_lic): ?>
      <p class="text-body-secondary small mb-0">
        Nie korzystasz jeszcze z żadnej usługi dodatkowej. Sprawdź zakładkę <a href="?tab=vlab">VLab</a> (dedykowane IP, VPS ze zniżką)
        lub <a href="?tab=licencje">Licencje</a>.
      </p>
      <?php else: ?>
      <div class="row g-3">
        <?php foreach ($sum_dedips as $dip): ?>
        <div class="col-12 col-md-4">
          <div class="border rounded p-2 h-100">
            <div class="small text-body-secondary mb-1"><i class="bi bi-globe me-1" aria-hidden="true"></i>Dedykowane IP — <?= h($dip['cont_label'] ?: $dip['container_name']) ?></div>
            <?php if ($dip['status'] === 'active'): ?>
            <span class="badge text-bg-success"><?= h($dip['ip_address']) ?></span>
            <?php else: ?>
            <span class="badge text-bg-warning"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>oczekuje na aktywację</span>
            <?php endif; ?>
            <div class="mt-1"><a href="?tab=vlab" class="small">Szczegóły →</a></div>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if ($sum_vps):
          $vps_labels = ['requested' => ['oczekuje na opłatę', 'text-bg-warning'], 'paid' => ['opłacony — realizacja', 'text-bg-info'], 'active' => ['aktywny', 'text-bg-success']];
          [$vlbl, $vcls] = $vps_labels[$sum_vps['status']] ?? [$sum_vps['status'], 'text-bg-secondary'];
        ?>
        <div class="col-12 col-md-4">
          <div class="border rounded p-2 h-100">
            <div class="small text-body-secondary mb-1"><i class="bi bi-hdd-rack me-1" aria-hidden="true"></i>VPS — <?= h($sum_vps['server_hostname'] ?: $sum_vps['hostname_prefix']) ?></div>
            <span class="badge <?= $vcls ?>"><?= h($vlbl) ?></span>
            <div class="mt-1"><a href="?tab=vlab" class="small">Szczegóły →</a></div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($sum_lic): ?>
        <div class="col-12 col-md-4">
          <div class="border rounded p-2 h-100">
            <div class="small text-body-secondary mb-1"><i class="bi bi-key me-1" aria-hidden="true"></i>Licencje</div>
            <span class="badge text-bg-primary"><?= count($sum_lic) ?> aktywnych</span>
            <div class="mt-1"><a href="?tab=licencje" class="small">Szczegóły →</a></div>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($tab === 'lekcje'):
  // ── Mini-kalendarz — dane ────────────────────────────────────────────────
  $cal_month = (int)($_GET['cal_m'] ?? date('n'));
  $cal_year  = (int)($_GET['cal_y'] ?? date('Y'));
  if ($cal_month < 1)  { $cal_month = 12; $cal_year--; }
  if ($cal_month > 12) { $cal_month = 1;  $cal_year++; }
  $cal_first = mktime(0, 0, 0, $cal_month, 1, $cal_year);
  $cal_days  = (int)date('t', $cal_first);
  try {
      $cal_lessons_raw = db_all(
          "SELECT s.lesson_date, CASE WHEN a.attended=1 THEN s.status ELSE 'absent' END AS status
           FROM k30_ti_sessions s
           JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=?
           LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=?
           WHERE s.lesson_date LIKE ?",
          [(int)$student['client_id'], (int)$student['client_id'], sprintf('%04d-%02d-%%', $cal_year, $cal_month)]
      );
  } catch (\Throwable $e) { $cal_lessons_raw = []; }
  $cal_by_day = [];
  foreach ($cal_lessons_raw as $r) {
      $d = (int)substr($r['lesson_date'], 8, 2);
      $cal_by_day[$d][] = $r['status'];
  }
  // Nawigacja miesiąc poprzedni / następny
  $cal_prev_m = $cal_month - 1; $cal_prev_y = $cal_year;
  if ($cal_prev_m < 1) { $cal_prev_m = 12; $cal_prev_y--; }
  $cal_next_m = $cal_month + 1; $cal_next_y = $cal_year;
  if ($cal_next_m > 12) { $cal_next_m = 1; $cal_next_y++; }
  $months_pl_long = [1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',
                     7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień'];
?>

  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h5 fw-bold mb-0">Moje lekcje</h1>
    <div class="ms-auto d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#kpCalSubModal">
        <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Subskrybuj / pobierz
      </button>
      <a href="lessons_pdf.php" class="btn btn-sm btn-outline-secondary" target="_blank">
        <i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj
      </a>
      <a href="lessons_pdf.php?all=1" class="btn btn-sm btn-outline-secondary" target="_blank">
        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Wszystkie (PDF)
      </a>
    </div>
  </div>

  <?php if (isset($_GET['rated'])): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Dziękujemy za ocenę zajęć!
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
  <?php endif; ?>
  <?php if (($_GET['excuse'] ?? '') === 'requested'): ?>
  <div class="alert alert-info alert-dismissible fade show" role="alert">
    <i class="bi bi-file-earmark-medical me-1" aria-hidden="true"></i>Usprawiedliwienie nieobecności zostało wysłane do prowadzącego i czeka na zatwierdzenie.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
  <?php elseif (($_GET['excuse'] ?? '') === 'need_reason'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Podaj powód usprawiedliwienia, aby wysłać zgłoszenie.
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
  <?php elseif (($_GET['cancel'] ?? '') === 'reschedule'): ?>
  <div class="alert alert-info alert-dismissible fade show" role="alert">
    <i class="bi bi-calendar2-range me-1" aria-hidden="true"></i>Propozycja nowego terminu została wysłana do prowadzącego i czeka na jego decyzję.
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

  <?php
  // ── Wykres frekwencji miesięcznej ────────────────────────────────────────
  $_kp_mon_lbl = [1=>'sty',2=>'lut',3=>'mar',4=>'kwi',5=>'maj',6=>'cze',7=>'lip',8=>'sie',9=>'wrz',10=>'paź',11=>'lis',12=>'gru'];
  $_kp_cdata = [];
  foreach ($lessons as $_kl) {
      if (!$fr_counts($_kl)) continue;
      $_kym = substr((string)$_kl['lesson_date'], 0, 7);
      if (!isset($_kp_cdata[$_kym])) $_kp_cdata[$_kym] = ['t' => 0, 'p' => 0];
      $_kp_cdata[$_kym]['t']++;
      if (!empty($_kl['attended'])) $_kp_cdata[$_kym]['p']++;
  }
  ksort($_kp_cdata);
  if (count($_kp_cdata) > 6) $_kp_cdata = array_slice($_kp_cdata, -6, 6, true);
  if ($_kp_cdata):
      $_kn = count($_kp_cdata);
      $kW = 300; $kH = 150;
      $kpL = 30; $kpR = 6; $kpT = 14; $kpB = 26;
      $kaW = $kW - $kpL - $kpR;
      $kaH = $kH - $kpT - $kpB;
      $kbW = ($kaW / $_kn) - 5;
      $kGap = ($kaW - $kbW * $_kn) / ($_kn + 1);
  ?>
  <div class="card mb-4">
    <div class="card-header bg-transparent d-flex align-items-center gap-2 py-2">
      <i class="bi bi-bar-chart-line text-primary" aria-hidden="true"></i>
      <span class="fw-semibold">Frekwencja miesięczna</span>
    </div>
    <div class="card-body py-3 px-3">
      <svg viewBox="0 0 <?= $kW ?> <?= $kH ?>" aria-hidden="true"
           class="w-100 d-block" style="max-height:150px">
        <?php foreach ([0, 50, 100] as $_kg): ?>
        <?php $_kgY = $kpT + $kaH - ($_kg * $kaH / 100); ?>
        <line x1="<?= $kpL ?>" y1="<?= number_format($_kgY,1) ?>" x2="<?= $kW - $kpR ?>" y2="<?= number_format($_kgY,1) ?>"
              stroke="currentColor" stroke-opacity="<?= $_kg === 50 ? '.1' : '.2' ?>" stroke-dasharray="<?= $_kg === 50 ? '3,3' : '0' ?>"/>
        <text x="<?= $kpL - 3 ?>" y="<?= number_format($_kgY + 3.5, 1) ?>" text-anchor="end"
              font-size="8.5" fill="currentColor" opacity=".55"><?= $_kg ?>%</text>
        <?php endforeach; ?>
        <?php $ki = 0; foreach ($_kp_cdata as $_kym => $_km): ?>
        <?php
            $_kpct = $_km['t'] > 0 ? round($_km['p'] / $_km['t'] * 100) : 0;
            $_kbh  = max($_kpct * $kaH / 100, $_kpct > 0 ? 3 : 0);
            $_kbx  = $kpL + $kGap + $ki * ($kbW + $kGap);
            $_kby  = $kpT + $kaH - $_kbh;
            $_kbc  = $_kpct >= 80 ? '#22c55e' : ($_kpct >= 60 ? '#f59e0b' : '#ef4444');
            $_klx  = $_kbx + $kbW / 2;
            $_kymp = explode('-', $_kym);
            $_kml  = ($_kp_mon_lbl[(int)$_kymp[1]] ?? '') . ' \'' . substr($_kymp[0], 2);
        ?>
        <?php if ($_kbh > 0): ?>
        <rect x="<?= number_format($_kbx,1) ?>" y="<?= number_format($_kby,1) ?>"
              width="<?= number_format($kbW,1) ?>" height="<?= number_format($_kbh,1) ?>"
              fill="<?= $_kbc ?>" rx="3" opacity=".85"/>
        <?php endif; ?>
        <text x="<?= number_format($_klx,1) ?>" y="<?= number_format($_kby - 3,1) ?>"
              text-anchor="middle" font-size="9" font-weight="600"
              fill="<?= $_kbc ?>"><?= $_kpct > 0 ? $_kpct . '%' : '' ?></text>
        <text x="<?= number_format($_klx,1) ?>" y="<?= $kH - $kpB + 12 ?>"
              text-anchor="middle" font-size="8.5" fill="currentColor" opacity=".65"><?= h($_kml) ?></text>
        <?php $ki++; endforeach; ?>
      </svg>
      <table class="visually-hidden">
        <caption>Frekwencja miesięczna — dane</caption>
        <thead><tr><th scope="col">Miesiąc</th><th scope="col">Lekcji</th><th scope="col">Obecności</th><th scope="col">Frekwencja</th></tr></thead>
        <tbody>
          <?php foreach ($_kp_cdata as $_kym => $_km): ?>
          <?php $_kpct2 = $_km['t'] > 0 ? round($_km['p'] / $_km['t'] * 100) : 0;
                $_kymp2 = explode('-', $_kym);
                $_kfull = ($_kp_mon_lbl[(int)$_kymp2[1]] ?? '') . ' ' . $_kymp2[0]; ?>
          <tr><th scope="row"><?= h($_kfull) ?></th><td><?= (int)$_km['t'] ?></td><td><?= (int)$_km['p'] ?></td><td><?= $_kpct2 ?>%</td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Mini-kalendarz lekcji ──────────────────────────────────────────── -->
  <div class="card mb-4" id="kpCalCard">
    <div class="card-header d-flex align-items-center gap-2 py-2">
      <i class="bi bi-calendar3 text-primary" aria-hidden="true"></i>
      <span class="fw-semibold flex-grow-1"><?= h($months_pl_long[$cal_month]) ?> <?= $cal_year ?></span>
      <a href="?tab=lekcje&cal_m=<?= $cal_prev_m ?>&cal_y=<?= $cal_prev_y ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 kp-cal-nav" aria-label="Poprzedni miesiąc">
        <i class="bi bi-chevron-left" aria-hidden="true"></i>
      </a>
      <a href="?tab=lekcje&cal_m=<?= $cal_next_m ?>&cal_y=<?= $cal_next_y ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 kp-cal-nav" aria-label="Następny miesiąc">
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
      </a>
      <button type="button" id="kpCalToggle" class="btn btn-sm btn-outline-secondary py-0 px-2" aria-expanded="true" aria-controls="kpCalBody" title="Schowaj/pokaż kalendarz">
        <i class="bi bi-chevron-up kp-cal-icon" aria-hidden="true"></i>
      </button>
    </div>
    <div class="card-body p-2" id="kpCalBody">
      <?php
        $cal_dow_first = (int)date('N', $cal_first); // 1=Pon .. 7=Nd
        $today_d = (int)date('d'); $today_m = (int)date('n'); $today_y = (int)date('Y');
        $cal_dow_names = ['Pn','Wt','Śr','Cz','Pt','Sb','Nd'];
        $col = 0;
      ?>
      <table class="kp-mini-cal" aria-label="Kalendarz <?= h($months_pl_long[$cal_month]) ?> <?= $cal_year ?>">
        <thead>
          <tr><?php foreach ($cal_dow_names as $dn): ?><th><?= h($dn) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
          <tr>
          <?php
          // Puste komórki przed pierwszym dniem
          for ($i = 1; $i < $cal_dow_first; $i++, $col++) { echo '<td></td>'; }
          for ($day = 1; $day <= $cal_days; $day++, $col++) {
              if ($col > 0 && $col % 7 === 0) echo '</tr><tr>';
              $is_today = ($day === $today_d && $cal_month === $today_m && $cal_year === $today_y);
              $statuses = $cal_by_day[$day] ?? [];
              echo '<td' . ($is_today ? ' class="cal-today"' : '') . '>';
              echo '<span>' . $day . '</span>';
              if ($statuses) {
                  echo '<div class="d-flex justify-content-center flex-wrap" style="gap:1px;margin-top:2px">';
                  foreach (array_slice($statuses, 0, 3) as $st) {
                      $dotcls = in_array($st, ['held','remote_material'], true) ? 'cal-dot-held'
                              : (in_array($st, ['cancelled','removed','excused'], true) ? 'cal-dot-cancelled' : 'cal-dot-planned');
                      echo '<span class="cal-dot ' . $dotcls . '" aria-hidden="true"></span>';
                  }
                  echo '</div>';
              }
              echo '</td>';
          }
          // Puste komórki do końca wiersza
          $remaining = 7 - ($col % 7);
          if ($remaining < 7) for ($i = 0; $i < $remaining; $i++) echo '<td></td>';
          ?>
          </tr>
        </tbody>
      </table>
      <div class="d-flex gap-3 mt-2 px-1" style="font-size:.72rem">
        <span class="d-flex align-items-center gap-1"><span class="cal-dot cal-dot-held"></span>odbyta</span>
        <span class="d-flex align-items-center gap-1"><span class="cal-dot cal-dot-planned"></span>zaplanowana</span>
        <span class="d-flex align-items-center gap-1"><span class="cal-dot cal-dot-cancelled"></span>odwołana</span>
      </div>
    </div>
  </div>
<script>
(function(){
  var body = document.getElementById('kpCalBody');
  var btn  = document.getElementById('kpCalToggle');
  var icon = btn ? btn.querySelector('.kp-cal-icon') : null;
  var KEY  = 'kp_cal_collapsed';
  function applyState(collapsed){
    if (!body || !btn) return;
    if (collapsed) {
      body.style.display = 'none';
      btn.setAttribute('aria-expanded','false');
      if (icon) { icon.classList.remove('bi-chevron-up'); icon.classList.add('bi-chevron-down'); }
    } else {
      body.style.display = '';
      btn.setAttribute('aria-expanded','true');
      if (icon) { icon.classList.remove('bi-chevron-down'); icon.classList.add('bi-chevron-up'); }
    }
  }
  applyState(localStorage.getItem(KEY) === '1');
  if (btn) btn.addEventListener('click', function(){
    var collapsed = localStorage.getItem(KEY) !== '1';
    localStorage.setItem(KEY, collapsed ? '1' : '0');
    applyState(collapsed);
  });
})();
</script>

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
    // Przyszłe lekcje dalej niż 7 dni — najbliższa nadchodząca na górze (rosnąco),
    // nie najdalsza w przyszłości (poprzednia wersja sortowała malejąco jak "near"/"past_far",
    // co dla listy przyszłych zajęć pokazywało najpierw najdalszy termin zamiast najbliższego).
    usort($up_far, fn($a, $b) => strcmp((string)$a['lesson_date'], (string)$b['lesson_date']));
    // $past_far zostaje malejąco (z zapytania) — od najnowszej

    // Zadania domowe podpięte pod konkretną lekcję (session_id => [zadania])
    $hw_by_session = [];
    foreach ($homeworks_student as $hw) { $sid = (int)($hw['session_id'] ?? 0); if ($sid) $hw_by_session[$sid][] = $hw; }

    // Lekcje z oczekującą propozycją zmiany terminu (od tego kursanta) → badge + ukrycie przycisku
    $resch_pending = [];
    foreach (db_all(
        "SELECT session_id, proposed_date, proposed_from FROM k30_ti_reschedule_requests
         WHERE client_id=? AND status='pending'", [(int)$student['client_id']]) as $rp) {
        $resch_pending[(int)$rp['session_id']] = $rp;
    }

    // Wiersz pojedynczej lekcji — współdzielony przez obie grupy
    $lessonRow = function(array $l) use ($months_pl, $vlab_token, $hw_by_session, $resch_pending) {
            $d   = new DateTime($l['lesson_date']);
            $dow = ['Nd','Pn','Wt','Śr','Czw','Pt','Sb'][(int)$d->format('w')];
            $enroll_link = trim((string)($l['enrollment_meeting_url'] ?? ''));
            $eff_link = ($l['lesson_method'] ?? '') === 'stacjonarna' ? ''
                      : (trim((string)($l['meeting_url'] ?? '')) !== '' ? $l['meeting_url']
                      : ($enroll_link !== '' ? $enroll_link
                      : (string)($l['course_meeting_url'] ?? '')));
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
              <?php $_sl = $l['lesson_method'] ?? '';
                if ($_sl === 'stacjonarna'): ?>
              <br><small class="text-success"><i class="bi bi-geo-alt-fill me-1" aria-hidden="true"></i>Stacjonarna</small>
              <?php elseif ($_sl === 'zdalna_zoom'): ?>
              <br><small class="text-primary"><i class="bi bi-camera-video me-1" aria-hidden="true"></i>Zdalna — Zoom</small>
              <?php elseif ($_sl === 'zdalna_inne'): ?>
              <br><small style="color:#6B21A8"><i class="bi bi-display me-1" aria-hidden="true"></i>Zdalna — Inne</small>
              <?php endif; ?>
            </td>
            <?php $att_cancelled = (int)($l['att_cancelled'] ?? 0) === 1; $att_pending = (int)($l['att_cancel_pending'] ?? 0) === 1; ?>
            <td class="text-center">
              <?php if ($att_pending): ?>
              <span class="badge text-bg-warning" title="<?= h($l['att_cancel_reason'] ?? '') ?>"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Czeka na potwierdzenie</span>
              <?php elseif ($att_cancelled): ?>
              <span class="badge text-bg-danger" title="<?= h($l['att_cancel_reason'] ?? '') ?>"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>odwołane</span>
              <?php elseif ((int)($l['course_track_attendance'] ?? 1) === 0): ?>
              <span class="badge text-bg-light text-secondary border" title="Kurs bez liczenia frekwencji">bez frekwencji</span>
              <?php elseif ($l['status'] !== 'held'):
                $lbl = $l['status']==='planned' ? 'planowana'
                     : ($l['status']==='remote_material' ? 'praca prowadzącego'
                     : (K30_TI_SESSION_STATUSES[$l['status']]['label'] ?? $l['status']));
              ?>
              <span class="badge text-bg-secondary"><?= h($lbl) ?></span>
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
              <?php
                $rp = $resch_pending[(int)$l['id']] ?? null;
                if ($l['status'] === 'planned' && $att_pending): ?>
              <!-- Czeka na potwierdzenie odwołania → tylko „Cofnij prośbę" -->
              <form method="post" class="d-inline" onsubmit="return confirm('Wycofać prośbę o odwołanie i potwierdzić udział?')">
                <input type="hidden" name="_token"    value="<?= h($vlab_token) ?>">
                <input type="hidden" name="_op"        value="uncancel_lesson">
                <input type="hidden" name="session_id" value="<?= (int)$l['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Cofnij prośbę
                </button>
              </form>
              <?php elseif ($l['status'] === 'planned' && !$att_cancelled): ?>
              <!-- Dropdown: Odwołaj / Zaproponuj termin -->
              <?php if ($rp): ?>
              <span class="badge text-bg-info align-self-center" title="Czeka na decyzję prowadzącego">
                <i class="bi bi-calendar2-range me-1" aria-hidden="true"></i>Termin: <?= h(date('d.m.Y', strtotime($rp['proposed_date'])) . ($rp['proposed_from'] ? ' '.substr((string)$rp['proposed_from'],0,5) : '')) ?>
              </span>
              <?php endif; ?>
              <div class="dropdown d-inline-block">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                        data-bs-toggle="dropdown" aria-expanded="false" aria-label="Akcje lekcji">
                  <i class="bi bi-three-dots" aria-hidden="true"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li>
                    <button type="button" class="dropdown-item text-danger"
                            data-cancel-session="<?= (int)$l['id'] ?>"
                            data-lesson-label="<?= h($l['course_name'].' — '.$d->format('d.m.Y')) ?>">
                      <i class="bi bi-x-circle me-2" aria-hidden="true"></i>Odwołaj lekcję
                    </button>
                  </li>
                  <?php if (!$rp): ?>
                  <li>
                    <button type="button" class="dropdown-item"
                            data-reschedule-session="<?= (int)$l['id'] ?>"
                            data-lesson-label="<?= h($l['course_name'].' — '.$d->format('d.m.Y')) ?>"
                            data-lesson-date="<?= h((string)$l['lesson_date']) ?>"
                            data-lesson-from="<?= h((string)($l['time_from'] ?? '')) ?>"
                            data-lesson-to="<?= h((string)($l['time_to'] ?? '')) ?>">
                      <i class="bi bi-calendar2-range me-2" aria-hidden="true"></i>Zaproponuj termin
                    </button>
                  </li>
                  <?php endif; ?>
                </ul>
              </div>
              <?php elseif (in_array($l['status'], ['held','individual_change'], true) && !$l['attended'] && !$att_cancelled): ?>
              <?php if ($att_pending): ?>
              <span class="badge text-bg-info align-self-center" title="Usprawiedliwienie oczekuje na decyzję prowadzącego">
                <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Usprawiedliwienie: czeka
              </span>
              <?php else: ?>
              <form method="post" class="d-inline js-excuse">
                <input type="hidden" name="_token"     value="<?= h($vlab_token) ?>">
                <input type="hidden" name="_op"         value="excuse_absence">
                <input type="hidden" name="session_id"  value="<?= (int)$l['id'] ?>">
                <input type="hidden" name="reason"      class="js-excuse-reason" value="">
                <button type="submit" class="btn btn-sm btn-outline-warning" title="Zgłoś usprawiedliwienie nieobecności">
                  <i class="bi bi-file-earmark-medical me-1" aria-hidden="true"></i>Usprawiedliw
                </button>
              </form>
              <?php endif; ?>
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

  <!-- Pasek narzedzi: widok kalendarza + iCal -->
  <div class="d-flex flex-wrap justify-content-end gap-2 mb-2">
    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#lessonsCalModal">
      <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Widok kalendarza
    </button>
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>Subskrybuj
      </button>
      <ul class="dropdown-menu dropdown-menu-end" style="min-width:22rem">
        <li><h6 class="dropdown-header">Subskrypcja kalendarza lekcji</h6></li>
        <li>
          <a class="dropdown-item" href="<?= h($cal_gcal) ?>" target="_blank" rel="noopener">
            <i class="bi bi-google me-2" aria-hidden="true"></i>Dodaj do Google Calendar
          </a>
        </li>
        <li>
          <a class="dropdown-item" href="<?= h($cal_webcal) ?>">
            <i class="bi bi-apple me-2" aria-hidden="true"></i>Apple Calendar / Outlook (webcal://)
          </a>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li class="px-3 py-1">
          <label class="form-label small mb-1 text-body-secondary">Adres URL (skopiuj recznie)</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control form-control-sm" id="kp-ical-url" value="<?= h($cal_https) ?>" readonly style="font-size:.72rem">
            <button class="btn btn-outline-secondary" type="button" id="kp-ical-copy" title="Kopiuj">
              <i class="bi bi-clipboard" aria-hidden="true"></i>
            </button>
          </div>
        </li>
        <li class="px-3 py-1">
          <form method="post" class="d-inline">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op" value="reset_calendar_token">
            <button type="submit" class="btn btn-link btn-sm p-0 text-danger" style="font-size:.78rem"
                    onclick="return confirm('Zresetowac token? Stary link przestanie dzialac.')">
              <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Zresetuj token (uniewa??nij stary link)
            </button>
          </form>
        </li>
      </ul>
    </div>
  </div>
  <script>
  document.getElementById('kp-ical-copy')?.addEventListener('click',function(){
    var inp = document.getElementById('kp-ical-url');
    if (!inp) return;
    navigator.clipboard?.writeText(inp.value).then(function(){
      var btn = document.getElementById('kp-ical-copy');
      if (btn) { btn.innerHTML='<i class="bi bi-check"></i>'; setTimeout(function(){ btn.innerHTML='<i class="bi bi-clipboard"></i>'; },1500); }
    }).catch(function(){ inp.select(); document.execCommand('copy'); });
  });
  </script>

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

  <script>
  // Usprawiedliwienie nieobecności — zapytaj o powód przed wysłaniem
  (function(){
    document.querySelectorAll('form.js-excuse').forEach(function(f){
      f.addEventListener('submit', function(e){
        var input = f.querySelector('.js-excuse-reason');
        if (input && input.value.trim() !== '') return; // powód ustawiony — wyślij
        e.preventDefault();
        var r = window.prompt('Podaj powód usprawiedliwienia nieobecności (np. choroba, wizyta lekarska):');
        if (r === null || r.trim() === '') return;
        input.value = r.trim();
        f.submit();
      });
    });
  })();
  </script>

  <!-- Modal: propozycja nowego terminu lekcji -->
  <div class="modal fade" id="reschedLessonModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <input type="hidden" name="_token"     value="<?= h($vlab_token) ?>">
        <input type="hidden" name="_op"          value="propose_reschedule">
        <input type="hidden" name="session_id"   id="rs_session_id" value="">
        <div class="modal-header">
          <h2 class="modal-title h5"><i class="bi bi-calendar2-range text-primary me-2" aria-hidden="true"></i>Zaproponuj nowy termin</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">Lekcja: <strong id="rs_lesson_label"></strong></p>
          <p class="text-body-secondary small mb-3">Propozycja zostanie wysłana do prowadzącego. Termin zmieni się dopiero po jego akceptacji.</p>
          <div class="mb-2">
            <label class="form-label fw-semibold" for="rs_date">Proponowana data</label>
            <input type="date" class="form-control" id="rs_date" name="lesson_date" required>
          </div>
          <div class="row g-2">
            <div class="col-6 mb-2">
              <label class="form-label" for="rs_from">Od <span class="text-body-secondary fw-normal">(opc.)</span></label>
              <input type="time" class="form-control" id="rs_from" name="time_from">
            </div>
            <div class="col-6 mb-2">
              <label class="form-label" for="rs_to">Do <span class="text-body-secondary fw-normal">(opc.)</span></label>
              <input type="time" class="form-control" id="rs_to" name="time_to">
            </div>
          </div>
          <label class="form-label fw-semibold" for="rs_reason">Uzasadnienie <span class="text-body-secondary fw-normal">(opc.)</span></label>
          <textarea class="form-control" id="rs_reason" name="reason" rows="2" maxlength="1000"
                    placeholder="np. kolizja z innymi zajęciami, wizyta lekarska…"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij propozycję</button>
        </div>
      </form>
    </div>
  </div>

  <script>
  (function(){
    var modalEl = document.getElementById('reschedLessonModal');
    if (!modalEl) return;
    document.querySelectorAll('[data-reschedule-session]').forEach(function(btn){
      btn.addEventListener('click', function(){
        document.getElementById('rs_session_id').value = btn.getAttribute('data-reschedule-session');
        document.getElementById('rs_lesson_label').textContent = btn.getAttribute('data-lesson-label') || '';
        document.getElementById('rs_date').value = btn.getAttribute('data-lesson-date') || '';
        document.getElementById('rs_from').value = (btn.getAttribute('data-lesson-from') || '').slice(0,5);
        document.getElementById('rs_to').value   = (btn.getAttribute('data-lesson-to') || '').slice(0,5);
        document.getElementById('rs_reason').value = '';
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
  <p class="text-body-secondary small mb-3">Materiały do nauki i zadania domowe — pogrupowane według lekcji. Oceny znajdziesz w zakładce „Oceny".</p>

  <div class="alert alert-info d-flex align-items-start gap-2 mb-3" role="note">
    <i class="bi bi-camera-video-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
    <div>
      <strong>Platforma Moodle została wyłączona.</strong>
      Materiały i zadania domowe prowadzący udostępnia teraz bezpośrednio tutaj, w tym panelu.
      Od 1 września zajęcia odbywają się przez <strong>Zoom</strong>.
    </div>
  </div>

  <?php
    $hwf = $_SESSION['hw_flash'] ?? null; unset($_SESSION['hw_flash']);
    if ($hwf): ?>
  <div class="alert alert-<?= $hwf[0]==='ok'?'success':'danger' ?> alert-dismissible fade show" role="alert">
    <i class="bi bi-<?= $hwf[0]==='ok'?'check-circle':'exclamation-triangle' ?> me-1" aria-hidden="true"></i><?= h($hwf[1]) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
  <?php endif; ?>

  <?php if (!$dyd_groups): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak materiałów i zadań od prowadzącego.</div>
  <?php else:
    $now = date('Y-m-d H:i:s');
    $_cutoff14 = date('Y-m-d', strtotime('-14 days'));
    $_hidden_mat_cnt = 0;
    foreach ($dyd_groups as $_grp) {
        if (!empty($_grp['session_id']) && !empty($_grp['date']) && substr($_grp['date'],0,10) < $_cutoff14)
            $_hidden_mat_cnt += count($_grp['materials']);
    }
  ?>
    <?php foreach ($dyd_groups as $grp):
      $_grp_old = !empty($grp['session_id']) && !empty($grp['date']) && substr($grp['date'],0,10) < $_cutoff14;
      // Pomijaj grupy, które mają tylko stare materiały i brak zadań
      if ($_grp_old && !$grp['homeworks'] && $grp['materials']) continue;
    ?>
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

      <?php if ($grp['materials'] && !$_grp_old): ?>
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

    <?php if ($_hidden_mat_cnt > 0): ?>
    <p class="text-body-secondary small mt-2 mb-0">
      <i class="bi bi-eye-slash me-1" aria-hidden="true"></i><?= $_hidden_mat_cnt === 1 ? '1 materiał' : $_hidden_mat_cnt . ' materiałów' ?> z lekcji sprzed ponad 14 dni jest ukrytych.
    </p>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Zadania z Moodle wyłączone — zastąpione przez Dydaktykę panelu -->
  <?php if (false && $moodle_assignments): $now_m = date('Y-m-d H:i:s'); ?>
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
      // „Dodano": data otwarcia z Moodle, a w razie braku — pierwsze pojawienie się w systemie
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

<?php elseif ($tab === 'oceny' && ($_dz_off = ti_blackout_active('dziennik'))): ?>
  <div class="kp-card p-4 text-center">
    <div class="mb-3" style="font-size:3rem;line-height:1;color:#f59e0b" aria-hidden="true"><i class="bi bi-cone-striped"></i></div>
    <h2 class="h5 fw-bold mb-2">Dziennik ocen jest chwilowo niedostępny</h2>
    <p class="mb-2"><?= h(ti_blackout_message($_dz_off)) ?></p>
    <p class="text-body-secondary small mb-0">Przerwa obowiązuje <?= h(ti_blackout_range_text($_dz_off)) ?>. Pozostałe zakładki działają normalnie.</p>
  </div>

<?php elseif ($tab === 'oceny'):
  // Dane do wykresu — sortuj wg daty, pomiń oceny bez wartości liczbowej
  $chart_datasets = [];
  $chart_colors   = ['#2563eb','#16a34a','#7c3aed','#d97706','#dc2626','#0891b2'];
  $ci = 0;
  foreach ($grades_by_course as $cname => $cgr) {
    $pts = [];
    foreach ($cgr as $g) {
      if ($g['value_num'] === null || $g['value_num'] === '') continue;
      $pts[] = ['x' => substr((string)$g['graded_at'],0,10), 'y' => (float)$g['value_num'], 'label' => $g['description'] ?: $cname];
    }
    usort($pts, fn($a,$b) => strcmp($a['x'],$b['x']));
    if ($pts) {
      $col = $chart_colors[$ci % count($chart_colors)];
      $chart_datasets[] = ['label' => $cname, 'data' => $pts, 'color' => $col];
      $ci++;
    }
  }
  $chart_json = json_encode($chart_datasets, JSON_UNESCAPED_UNICODE);
?>

  <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
    <h1 class="h5 fw-bold mb-0 d-flex align-items-center gap-2">
      <i class="bi bi-table text-primary" aria-hidden="true"></i>Oceny
    </h1>
    <?php if ($grades_student): ?>
    <div class="ms-auto d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" id="kp-grades-view-table" aria-pressed="true">
        <i class="bi bi-table me-1" aria-hidden="true"></i>Tabela
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="kp-grades-view-chart" aria-pressed="false">
        <i class="bi bi-bar-chart-line me-1" aria-hidden="true"></i>Wykres
      </button>
      <a href="?grades_pdf=1" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</a>
    </div>
    <?php endif; ?>
  </div>
  <p class="text-body-secondary small mb-3">Oceny wystawione przez prowadzących, ze średnią ważoną dla każdego kursu.</p>

  <?php if (!$grades_student): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak ocen. Pojawią się tutaj, gdy prowadzący je wystawi.</div>
  <?php else: ?>

  <!-- Widok: wykres -->
  <div id="kp-grades-chart-view" style="display:none">
    <?php if (!empty($chart_datasets)): ?>
    <div class="card mb-3">
      <div class="card-body py-3">
        <canvas id="kp-grades-canvas" style="max-height:320px" aria-label="Wykres ocen" role="img"></canvas>
      </div>
    </div>
    <?php else: ?>
    <div class="alert alert-info small">Brak ocen liczbowych do wykresu.</div>
    <?php endif; ?>
    <!-- Karty kursów ze średnią (skrócony widok) -->
    <?php foreach ($grades_by_course as $cname => $cgr):
      $avg = k30_ti_grades_average($cgr);
      [$abg,$afg] = k30_ti_grade_color($avg);
    ?>
    <div class="card mb-2">
      <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold small"><i class="bi bi-pc-display me-1" aria-hidden="true"></i><?= h($cname) ?></span>
        <span class="text-body-secondary small"><?= count($cgr) ?> ocen</span>
        <?php if ($avg !== null): ?>
        <span class="ms-auto small text-body-secondary">Śr. ważona:</span>
        <span class="badge" style="background:<?= $abg ?>;color:<?= $afg ?>;font-size:.9rem"><?= number_format($avg, 2, ',', '') ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Widok: tabela -->
  <div id="kp-grades-table-view">
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
  </div>

  <script>
  (function(){
    var chartView = document.getElementById('kp-grades-chart-view');
    var tableView = document.getElementById('kp-grades-table-view');
    var btnChart  = document.getElementById('kp-grades-view-chart');
    var btnTable  = document.getElementById('kp-grades-view-table');
    if (!btnChart || !btnTable) return;

    function show(mode) {
      var isChart = mode === 'chart';
      chartView.style.display = isChart ? '' : 'none';
      tableView.style.display = isChart ? 'none' : '';
      btnChart.setAttribute('aria-pressed', isChart ? 'true' : 'false');
      btnChart.classList.toggle('active', isChart);
      btnTable.setAttribute('aria-pressed', isChart ? 'false' : 'true');
      btnTable.classList.toggle('active', !isChart);
      try { localStorage.setItem('kp-grades-view', mode); } catch(e) {}
    }

    btnChart.addEventListener('click', function(){ show('chart'); });
    btnTable.addEventListener('click', function(){ show('table'); });

    // Przywroc ostatni widok
    var saved = null; try { saved = localStorage.getItem('kp-grades-view'); } catch(e){}
    show(saved === 'chart' ? 'chart' : 'table');

    // Wykres Chart.js
    var canvas = document.getElementById('kp-grades-canvas');
    if (!canvas) return;
    var datasets = <?= $chart_json ?>;
    if (!datasets.length) return;

    function buildChart() {
      var chartDatasets = datasets.map(function(ds) {
        return {
          label: ds.label,
          data: ds.data.map(function(p){ return {x: p.x, y: p.y}; }),
          borderColor: ds.color,
          backgroundColor: ds.color + '33',
          pointBackgroundColor: ds.color,
          pointRadius: 5,
          pointHoverRadius: 7,
          tension: 0.35,
          fill: false
        };
      });
      new Chart(canvas, {
        type: 'line',
        data: { datasets: chartDatasets },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          scales: {
            x: { type: 'time', time: { unit: 'day', displayFormats: { day: 'dd.MM' } },
                 adapters: { date: {} }, title: { display: false },
                 ticks: { maxRotation: 45 } },
            y: { min: 1, max: 6, ticks: { stepSize: 1,
                 callback: function(v){ return ['','1','2','3','4','5','6'][Math.round(v)] || v; } },
                 title: { display: true, text: 'Ocena' } }
          },
          plugins: {
            legend: { display: datasets.length > 1 },
            tooltip: {
              callbacks: {
                label: function(ctx) {
                  var raw = datasets[ctx.datasetIndex].data[ctx.dataIndex];
                  return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y + (raw.label ? ' — ' + raw.label : '');
                }
              }
            }
          }
        }
      });
    }

    // Zaladuj Chart.js + adapter date-fns z CDN, potem zbuduj wykres
    function loadScript(src, cb) {
      var s = document.createElement('script'); s.src = src; s.onload = cb; document.head.appendChild(s);
    }
    loadScript('https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', function(){
      loadScript('https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js', buildChart);
    });
  })();
  </script>

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
      <?php if ($is_minor): ?>
      <div class="card border-warning mb-4 shadow-sm">
        <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center gap-2">
          <i class="bi bi-file-earmark-text text-warning fs-5" aria-hidden="true"></i>
          <strong><?= h($vlab_term['title'] ?? 'Regulamin vLAB') ?></strong>
        </div>
        <div class="card-body">
          <div class="border rounded p-3 mb-3 small"
               style="max-height:320px;overflow-y:auto;background:var(--bs-tertiary-bg)"
               tabindex="0" aria-label="Treść regulaminu <?= h($vlab_term['title'] ?? '') ?>">
            <?= $vlab_term['body_html'] ?? '' ?>
          </div>
          <div class="alert alert-info d-flex align-items-start gap-2 mb-0">
            <i class="bi bi-people-fill mt-1" aria-hidden="true"></i>
            <span>Jesteś niepełnoletni/a — regulamin VLab musi zaakceptować Twój opiekun, logując się do <strong>panelu rodzica</strong>. Poinformuj opiekuna — po zalogowaniu znajdzie regulamin w zakładce „Regulamin VLab".</span>
          </div>
        </div>
      </div>
      <?php else: ?>
      <?= _ti_terms_acceptance_block($vlab_term, $vlab_token, 'vlab') ?>
      <?php endif; ?>
    </div>
  </div>
  <?php else: ?>

  <style>
    .vlab-hero{background:linear-gradient(135deg,var(--bs-primary) 0%,#0b5ed7 100%);color:#fff;border-radius:1rem}
    .vlab-hero .bi{opacity:.9}
    .vlab-icon-badge{width:44px;height:44px;border-radius:.85rem;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .vlab-card{border:1px solid var(--bs-border-color);border-radius:.9rem;transition:box-shadow .15s ease,transform .15s ease}
    .vlab-card:hover{box-shadow:0 .5rem 1.25rem rgba(0,0,0,.08);transform:translateY(-1px)}
    .vlab-card .card-body{padding:1.1rem}
    .vlab-status-dot{width:.55rem;height:.55rem;border-radius:50%;display:inline-block}
    .vlab-tpl-card{border:1px solid var(--bs-border-color);border-radius:.9rem;transition:box-shadow .15s ease,border-color .15s ease}
    .vlab-tpl-card:hover{box-shadow:0 .5rem 1.25rem rgba(0,0,0,.08);border-color:var(--bs-primary)}
    [data-bs-toggle="collapse"] .bi-chevron-down{transition:transform .2s ease}
    [data-bs-toggle="collapse"][aria-expanded="true"] .bi-chevron-down{transform:rotate(180deg)}
  </style>

  <!-- ── Nagłówek (hero) ──────────────────────────────────────────────────── -->
  <div class="vlab-hero p-3 p-md-4 mb-3 d-flex align-items-center gap-3 flex-wrap">
    <span class="vlab-icon-badge bg-white bg-opacity-15">
      <i class="bi bi-hdd-stack fs-4" aria-hidden="true"></i>
    </span>
    <div class="flex-grow-1 min-width-0">
      <h1 class="h5 fw-bold mb-1">VLab — Twoje środowiska do ćwiczeń</h1>
      <p class="mb-0 small opacity-90">
        Wirtualne maszyny (kontenery Docker), dostęp przez terminal w przeglądarce lub po SSH — plus opcje rozszerzeń: dedykowane IP i VPS ze zniżką.
      </p>
    </div>
  </div>

  <?php $vlab_blocked_html = vlab_blocked_software_html(); if (trim($vlab_blocked_html) !== ''): ?>
  <div class="vlab-card mb-3 overflow-hidden">
    <button type="button" class="btn text-decoration-none w-100 text-start px-3 py-3 d-flex align-items-center gap-2 bg-transparent border-0 rounded-0"
            data-bs-toggle="collapse" data-bs-target="#vlab-blocked-sw" aria-expanded="false" aria-controls="vlab-blocked-sw">
      <span class="vlab-icon-badge bg-danger-subtle text-danger">
        <i class="bi bi-shield-x" aria-hidden="true"></i>
      </span>
      <span class="flex-grow-1">
        <span class="fw-semibold d-block">Zabronione oprogramowanie i usługi</span>
        <span class="text-body-secondary small">Zasady korzystania z maszyn VLab — rozwiń, aby przeczytać</span>
      </span>
      <i class="bi bi-chevron-down" aria-hidden="true"></i>
    </button>
    <div id="vlab-blocked-sw" class="collapse">
      <hr class="m-0">
      <div class="card-body small">
        <?= $vlab_blocked_html ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div id="vlab-root" data-token="<?= h($vlab_token) ?>">
    <div id="vlab-content" aria-live="polite">
      <div class="text-body-secondary py-4 text-center">
        <div class="spinner-border spinner-border-sm text-primary me-2" role="status" aria-hidden="true"></div>Ładowanie…
      </div>
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
          <div class="mb-2">
            <label class="form-label" for="vc-password">Własne hasło do SSH (opcjonalnie)</label>
            <input type="text" class="form-control font-monospace" id="vc-password" maxlength="72" placeholder="np. moje-haslo123" autocomplete="new-password">
            <div class="form-text">Podaj własne hasło, jeśli chcesz od razu je znać i uniknąć wymuszonej zmiany przy pierwszym logowaniu SSH (po niej połączenie się rozłącza — trzeba połączyć się ponownie). Zostaw puste, aby dostać wygenerowane hasło.</div>
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
      if (c.force_change) h += '<div class="alert alert-warning py-2 small mb-0"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>Przy pierwszym logowaniu SSH system poprosi Cię o ustawienie własnego hasła. Po jego zmianie połączenie SSH samo się rozłączy (tak działa SSH) — połącz się ponownie już NOWYM hasłem, żeby wejść do maszyny.</div>';
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
      let html = '<div class="d-flex align-items-center gap-2 mb-3">'
        + '<span class="vlab-icon-badge bg-primary-subtle text-primary"><i class="bi bi-pc-display" aria-hidden="true"></i></span>'
        + '<h2 class="h6 fw-bold mb-0 flex-grow-1">Moje maszyny</h2>'
        + '<span class="badge text-bg-light border">'+d.count+' / '+d.max+'</span></div>';

      const af = d.dedicated_ip ? Number(d.dedicated_ip.activation_fee).toFixed(2).replace('.',',') : '';
      const mf = d.dedicated_ip ? Number(d.dedicated_ip.monthly_fee).toFixed(2).replace('.',',') : '';

      if (!d.machines.length){
        html += '<div class="vlab-card p-4 text-center text-body-secondary mb-4">'
          + '<i class="bi bi-inboxes fs-1 opacity-25 d-block mb-2" aria-hidden="true"></i>'
          + 'Nie masz jeszcze żadnej maszyny. Utwórz ją z szablonu poniżej.</div>';
      } else {
        html += '<div class="row g-3 mb-4">';
        for (const m of d.machines){
          const [col,lbl] = stMap[m.status] || ['secondary', m.status];
          html += '<div class="col-12 col-lg-6"><div class="vlab-card h-100"><div class="card-body">'
            + '<div class="d-flex align-items-start gap-2 mb-2">'
            + '<span class="vlab-icon-badge bg-'+col+'-subtle text-'+col+'"><i class="bi bi-hdd-stack" aria-hidden="true"></i></span>'
            + '<div class="flex-grow-1 min-width-0">'
            + '<div class="d-flex align-items-center gap-2 flex-wrap">'
            + '<span class="fw-semibold">'+esc(m.label)+'</span>'
            + '<span class="badge text-bg-'+col+'"><span class="vlab-status-dot bg-white me-1" style="opacity:.85"></span>'+lbl+'</span>'
            + (m.force_pw ? '<span class="badge text-bg-warning"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>zmień hasło przy logowaniu</span>' : '')
            + '</div>';
          if (m.status === 'error' && m.error){
            html += '<p class="text-danger small mb-0 mt-1">'+esc(m.error)+'</p>';
          }
          html += '</div></div>';
          // Efektywne dane SSH: konto hosta (preferowane) lub fallback na bezpośredni port kontenera.
          const sshUser = m.host_user || m.ssh_user || '';
          const sshPort = m.host_user ? m.host_port : (m.ssh_port || 0);
          const sshOk   = m.status === 'running' && m.ssh_host && sshUser && sshPort;
          if (sshOk){
            const c = lastCreds[m.id]; // pełne dane logowania, tylko bezpośrednio po utworzeniu
            html += '<div class="bg-body-tertiary border rounded-3 p-2 mb-2 small font-monospace">'
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
          if (d.dedicated_ip && d.dedicated_ip.enabled){
            const dip = m.dedicated_ip;
            if (!dip){
              html += '<div class="bg-body-tertiary rounded-3 p-2 mb-2 small d-flex align-items-center gap-2">'
                + '<i class="bi bi-globe text-body-secondary" aria-hidden="true"></i>'
                + '<span class="text-body-secondary flex-grow-1">Dedykowane IP — '+af+' zł aktywacja + '+mf+' zł/mc</span>'
                + '<button type="button" class="btn btn-primary btn-sm py-0" data-order-dedip="'+m.id+'">Zamów</button></div>';
            } else if (dip.status === 'requested'){
              html += '<div class="bg-warning-subtle rounded-3 p-2 mb-2 small d-flex align-items-center gap-2">'
                + '<i class="bi bi-hourglass-split text-warning-emphasis" aria-hidden="true"></i>'
                + '<span class="flex-grow-1">Dedykowane IP: oczekuje na aktywację</span>'
                + '<button type="button" class="btn btn-link btn-sm p-0 text-danger" data-cancel-dedip="'+dip.order_id+'" data-cancel-dedip-status="requested">zrezygnuj</button></div>';
            } else if (dip.status === 'active'){
              html += '<div class="bg-success-subtle rounded-3 p-2 mb-2 small d-flex align-items-center gap-2">'
                + '<i class="bi bi-globe text-success-emphasis" aria-hidden="true"></i>'
                + '<span class="flex-grow-1">Dedykowane IP: <span class="font-monospace">'+esc(dip.ip_address)+'</span></span>'
                + '<button type="button" class="btn btn-link btn-sm p-0 text-danger" data-cancel-dedip="'+dip.order_id+'" data-cancel-dedip-status="active">zrezygnuj</button></div>';
            }
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
          html += '</div></div></div></div>';
        }
        html += '</div>';
      }

      html += '<div class="d-flex align-items-center gap-2 mt-4 mb-3">'
        + '<span class="vlab-icon-badge bg-primary-subtle text-primary"><i class="bi bi-collection" aria-hidden="true"></i></span>'
        + '<h2 class="h6 fw-bold mb-0">Utwórz nową maszynę</h2></div>';
      const canCreate = d.count < d.max;
      if (!canCreate){
        html += '<div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Osiągnięto limit maszyn ('+d.max+'). Usuń istniejącą, aby utworzyć nową.</div>';
      }
      if (!d.templates.length){
        html += '<div class="vlab-card p-4 text-center text-body-secondary">Brak dostępnych szablonów.</div>';
      } else {
        html += '<div class="row g-3">';
        for (const t of d.templates){
          html += '<div class="col-12 col-md-6 col-lg-4"><div class="vlab-tpl-card h-100"><div class="card-body d-flex flex-column">'
            + '<span class="vlab-icon-badge bg-body-secondary bg-opacity-10 text-body-secondary mb-2"><i class="bi bi-box-seam" aria-hidden="true"></i></span>'
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
      const tpl      = document.getElementById('vc-tpl').value;
      const label    = document.getElementById('vc-label').value.trim() || 'lab';
      const ports    = document.getElementById('vc-ports').value.trim();
      const host_password = document.getElementById('vc-password').value;
      btn.disabled = true; const orig = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Tworzę…';
      const r = await api('create', {template_id: tpl, label, ports, host_password});
      btn.disabled = false; btn.innerHTML = orig;
      if (r.id && r.creds) lastCreds[r.id] = r.creds;
      if (window.bootstrap) bootstrap.Modal.getInstance(document.getElementById('vlabCreateModal'))?.hide();
      document.getElementById('vc-password').value = '';
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

      const dedipBtn = e.target.closest('[data-order-dedip]');
      if (dedipBtn){
        if (!confirm('Zamówić dedykowane IP dla tej maszyny? Opłata aktywacyjna zostanie dodana do Twojego rozliczenia, a następnie naliczany będzie abonament miesięczny. Adres IP przydzieli administrator.')) return;
        dedipBtn.disabled = true;
        const r = await api('order_dedicated_ip', {id: dedipBtn.dataset.orderDedip});
        if (!r.ok) { dedipBtn.disabled = false; alert(r.msg || 'Błąd.'); return; }
        alert(r.msg || 'Zamówienie przyjęte.');
        if (r.data) render(r.data); else reload();
        return;
      }

      const cancelDedipBtn = e.target.closest('[data-cancel-dedip]');
      if (cancelDedipBtn){
        const dedipConfirmMsg = cancelDedipBtn.dataset.cancelDedipStatus === 'active'
          ? 'Zrezygnować z dedykowanego IP? Opłata za bieżący, już opłacony okres NIE jest zwracana (środki poszły do operatora) — naliczona zostanie tylko opłata manipulacyjna.'
          : 'Zrezygnować z zamówienia dedykowanego IP? Usługa nie została jeszcze aktywowana — otrzymasz zwrot całej opłaty, pomniejszony o opłatę manipulacyjną.';
        if (!confirm(dedipConfirmMsg)) return;
        cancelDedipBtn.disabled = true;
        const r = await api('cancel_dedicated_ip', {id: cancelDedipBtn.dataset.cancelDedip});
        if (!r.ok) { cancelDedipBtn.disabled = false; alert(r.msg || 'Błąd.'); return; }
        alert(r.msg || 'Zrezygnowano.');
        if (r.data) render(r.data); else reload();
        return;
      }

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

  <?php
  // ── Dedykowany serwer u zewnętrznego partnera (xxx.edukacja.cloud) ─────────
  $dsrv_pricing  = vlab_dedicated_server_pricing();
  $dsrv_periods  = array_filter($dsrv_pricing['periods'], fn($p) => (float)$p['price'] > 0);
  if ($dsrv_pricing['enabled']):
      $dsrv_order = vlab_dedicated_server_for_student((int)$student['id']);
      $dsrv_msg   = $_SESSION['k30_ds_msg'] ?? null; unset($_SESSION['k30_ds_msg']);
  ?>
  <?php
    $dsrv_status = $dsrv_order['status'] ?? 'none';
    $dsrv_teaser_badge = [
      'requested' => ['Oczekuje na opłatę', 'text-bg-warning', 'hourglass-split'],
      'paid'      => ['Realizacja u partnera', 'text-bg-info', 'truck'],
      'active'    => ['Aktywny', 'text-bg-success', 'check-circle-fill'],
    ][$dsrv_status] ?? null;
  ?>
  <div class="vlab-card mt-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <span class="vlab-icon-badge bg-primary-subtle text-primary flex-shrink-0"><i class="bi bi-hdd-rack" aria-hidden="true"></i></span>
      <div class="flex-grow-1 min-width-0">
        <h2 class="h6 fw-bold mb-1">Twój serwer VPS ze zniżką</h2>
        <div class="small text-body-secondary">
          <?php if ($dsrv_teaser_badge): ?>
          <span class="badge <?= $dsrv_teaser_badge[1] ?> me-1"><i class="bi bi-<?= $dsrv_teaser_badge[2] ?> me-1" aria-hidden="true"></i><?= h($dsrv_teaser_badge[0]) ?></span>
          <?= h($dsrv_status === 'active' ? $dsrv_order['server_hostname'] : ($dsrv_order['hostname_prefix'] . '.' . $dsrv_pricing['domain'])) ?>
          <?php else: ?>
          Własny VPS w cenie obniżonej dla kursantów fundacji.
          <?php endif; ?>
        </div>
      </div>
      <?php if ($dsrv_msg): ?>
      <div class="alert alert-<?= $dsrv_msg[0]==='ok'?'success':'danger' ?> py-2 small mb-0 w-100"><?= h($dsrv_msg[1]) ?></div>
      <?php endif; ?>
      <button type="button" class="btn btn-outline-primary btn-sm flex-shrink-0" data-bs-toggle="modal" data-bs-target="#dsrvModal">
        <i class="bi bi-hdd-rack me-1" aria-hidden="true"></i><?= $dsrv_teaser_badge ? 'Szczegóły' : 'Zamów' ?>
      </button>
    </div>
  </div>

  <!-- Modal: serwer VPS ze zniżką (zamówienie / status / rezygnacja) -->
  <div class="modal fade" id="dsrvModal" tabindex="-1" aria-labelledby="dsrvModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title h5" id="dsrvModalLabel"><i class="bi bi-hdd-rack text-primary me-2" aria-hidden="true"></i>Twój serwer VPS ze zniżką</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-light border small d-flex align-items-start gap-2 mb-3">
            <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
            <span>
              To jest <strong>VPS (wirtualny serwer prywatny)</strong> u zewnętrznego partnera hostingowego — „dedykowany" oznacza tu,
              że zasoby (<?= h($dsrv_pricing['specs']) ?>) są przypisane <strong>wyłącznie Tobie</strong>, a nie że to fizyczny serwer
              dedykowany (bare-metal). To inna usługa niż maszyny (kontenery Docker) w sekcji powyżej.
            </span>
          </div>

          <?php if (!$dsrv_order || $dsrv_order['status'] === 'cancelled'): ?>
          <p class="text-body-secondary small mb-2">
            Własny VPS w cenie obniżonej dla kursantów fundacji. Fundacja wystawia fakturę VAT za wybrany okres
            (+ jednorazowa opłata aktywacyjna); po zaksięgowaniu wpłaty składamy zamówienie u partnera i uruchamiamy Twój serwer.
          </p>
          <?php if (!$dsrv_periods): ?>
          <div class="alert alert-secondary small mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Cennik nie jest jeszcze skonfigurowany — skontaktuj się z administratorem.</div>
          <?php else: ?>
          <?php if ($dsrv_pricing['activation_fee'] > 0): ?>
          <p class="small mb-3"><i class="bi bi-tag me-1" aria-hidden="true"></i>Opłata aktywacyjna (jednorazowo): <strong><?= number_format($dsrv_pricing['activation_fee'], 2, ',', ' ') ?> zł</strong></p>
          <?php endif; ?>
          <div class="row g-2 mb-3">
            <?php foreach ($dsrv_periods as $pkey => $plan): ?>
            <div class="col-6 col-md-4">
              <div class="border rounded-3 p-2 text-center h-100">
                <div class="small text-body-secondary"><?= h($plan['label']) ?></div>
                <?php if ($plan['regular_price'] > $plan['price']): ?>
                <div class="text-decoration-line-through text-body-secondary small"><?= number_format($plan['regular_price'], 2, ',', ' ') ?> zł</div>
                <?php endif; ?>
                <div class="fw-bold fs-5 text-primary"><?= number_format($plan['price'], 2, ',', ' ') ?> zł</div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op" value="order_dedicated_server">
            <div class="col-12 col-md-4">
              <label class="form-label small" for="ds-prefix">Nazwa serwera</label>
              <div class="input-group input-group-sm">
                <input type="text" class="form-control" id="ds-prefix" name="hostname_prefix" required maxlength="32"
                       pattern="[a-z0-9][a-z0-9-]{0,30}[a-z0-9]?" placeholder="np. jkowalski">
                <span class="input-group-text">.<?= h($dsrv_pricing['domain']) ?></span>
              </div>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label small" for="ds-user">Nazwa użytkownika</label>
              <input type="text" class="form-control form-control-sm" id="ds-user" name="server_username" required maxlength="64">
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label small" for="ds-period">Okres rozliczeniowy</label>
              <select class="form-select form-select-sm" id="ds-period" name="billing_period">
                <?php foreach ($dsrv_periods as $pkey => $plan): ?>
                <option value="<?= h($pkey) ?>"><?= h($plan['label']) ?> — <?= number_format($plan['price'], 2, ',', ' ') ?> zł</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-2">
              <button type="submit" class="btn btn-primary btn-sm w-100" onclick="return confirm('Zamówić VPS? Opłata za wybrany okres oraz opłata aktywacyjna zostaną dodane do Twojego rozliczenia.')">
                <i class="bi bi-cart-plus me-1" aria-hidden="true"></i>Zamów
              </button>
            </div>
          </form>
          <?php endif; ?>

          <?php elseif ($dsrv_order['status'] === 'requested'): ?>
          <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
            <i class="bi bi-hourglass-split mt-1" aria-hidden="true"></i>
            <div>
              Zamówienie serwera <strong><?= h($dsrv_order['hostname_prefix']) ?>.<?= h($dsrv_pricing['domain']) ?></strong> czeka na opłatę
              (<?= number_format((float)$dsrv_order['price'] + (float)$dsrv_order['activation_fee'], 2, ',', ' ') ?> zł
              <?php if ((float)$dsrv_order['activation_fee'] > 0): ?>w tym <?= number_format((float)$dsrv_order['activation_fee'], 2, ',', ' ') ?> zł aktywacji, <?php endif; ?>
              okres: <?= $dsrv_order['billing_period'] === 'annual' ? 'roczny' : 'miesięczny' ?>).
              Fundacja wystawi fakturę VAT — sprawdź zakładkę <a href="?tab=rozliczenia">Rozliczenia</a>. Po zaksięgowaniu wpłaty złożymy zamówienie u partnera.
            </div>
          </div>
          <form method="post" onsubmit="return confirm('Zrezygnować z zamówienia? Otrzymasz zwrot całej naliczonej opłaty, pomniejszony o opłatę manipulacyjną.')">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op" value="cancel_dedicated_server">
            <input type="hidden" name="order_id" value="<?= (int)$dsrv_order['id'] ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Zrezygnuj z zamówienia</button>
          </form>

          <?php elseif ($dsrv_order['status'] === 'paid'): ?>
          <div class="alert alert-info d-flex align-items-start gap-2 mb-0">
            <i class="bi bi-truck mt-1" aria-hidden="true"></i>
            <div>
              Wpłata za serwer <strong><?= h($dsrv_order['hostname_prefix']) ?>.<?= h($dsrv_pricing['domain']) ?></strong> została potwierdzona —
              składamy zamówienie u partnera. O uruchomieniu poinformujemy e-mailem. Na tym etapie rezygnacja wymaga kontaktu z administratorem.
            </div>
          </div>

          <?php else: /* active */ ?>
          <div class="alert alert-success d-flex align-items-start gap-2 mb-3">
            <i class="bi bi-check-circle-fill mt-1" aria-hidden="true"></i>
            <div>
              Serwer <strong><?= h($dsrv_order['server_hostname']) ?></strong> jest aktywny (użytkownik: <?= h($dsrv_order['server_username']) ?>).
              Kolejne odnowienie (<?= $dsrv_order['billing_period'] === 'annual' ? 'roczne' : 'miesięczne' ?>): <?= $dsrv_order['next_renewal_at'] ? h(date('d.m.Y', strtotime($dsrv_order['next_renewal_at']))) : '—' ?>.
            </div>
          </div>
          <form method="post" onsubmit="return confirm('Zrezygnować z VPS? Opłata za bieżący, już opłacony okres NIE jest zwracana (środki poszły do partnera) — naliczona zostanie tylko opłata manipulacyjna.')">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op" value="cancel_dedicated_server">
            <input type="hidden" name="order_id" value="<?= (int)$dsrv_order['id'] ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Zrezygnuj z usługi</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php if ($dsrv_msg): ?>
  <script>document.addEventListener('DOMContentLoaded', function(){ if (window.bootstrap) new bootstrap.Modal(document.getElementById('dsrvModal')).show(); });</script>
  <?php endif; ?>
  <?php endif; ?>

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
  <p class="text-body-secondary small mb-3">Program kursu i postęp realizacji. Punkty oznaczone jako „zrealizowane" mają już odbytą lekcję.</p>
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
  ti_msg_mark_read_for_student((int)$student['id']);
  $messages = ti_msg_list_for_student((int)$student['id']);
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <h1 class="h5 fw-bold mb-0">
    <i class="bi bi-envelope text-primary me-2" aria-hidden="true"></i>Wiadomości
  </h1>
</div>

<?php if (($_GET['sent'] ?? '') === '1'): ?>
<div class="alert alert-success alert-dismissible py-2 small mb-3" role="alert">
  <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Wiadomość wysłana.
  <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Zamknij"></button>
</div>
<?php endif; ?>

<?php if (($_GET['blocked'] ?? '') === '1'): ?>
<div class="alert alert-warning py-2 small mb-3" role="alert">
  <i class="bi bi-slash-circle me-1" aria-hidden="true"></i>Nie możesz teraz wysyłać wiadomości — prowadzący tymczasowo zablokował tę funkcję.
</div>
<?php endif; ?>

<?php if (empty($messages)): ?>
<div class="card">
  <div class="card-body text-center text-body-secondary py-5">
    <i class="bi bi-envelope fs-1 opacity-25 d-block mb-3" aria-hidden="true"></i>
    <p class="mb-2">Nie masz jeszcze żadnych wiadomości.</p>
    <p class="small mt-3 mb-0"><a href="?tab=ustawienia">Ustawienia powiadomień</a></p>
  </div>
</div>
<?php else: ?>

<!-- Lista wiadomości -->
<ol class="list-unstyled d-flex flex-column gap-3 mb-4" aria-label="Wiadomości">
  <?php foreach ($messages as $m):
    $mine = ($m['sender'] ?? '') === 'student';
    $ts   = $m['created_at'] ? date('d.m.Y, H:i', strtotime($m['created_at'])) : '';
    $name = $mine ? 'Ty' : ($m['sender_name'] !== '' ? $m['sender_name'] : 'Prowadzący');
    $role_label = $mine ? 'kursant' : 'prowadzący';
  ?>
  <li>
    <article aria-label="Wiadomość od: <?= h($name) ?>, <?= h($ts) ?>">
      <header class="d-flex align-items-baseline gap-2 mb-1">
        <span class="fw-semibold small"><?= h($name) ?></span>
        <span class="text-body-secondary small">(<?= $role_label ?>)</span>
        <time class="text-body-secondary small ms-auto" datetime="<?= h($m['created_at'] ?? '') ?>"><?= h($ts) ?></time>
      </header>
      <div class="border rounded-2 p-3 <?= $mine ? 'border-primary border-opacity-50 bg-primary bg-opacity-10' : '' ?>">
        <p class="mb-0" style="white-space:pre-wrap;line-height:1.6"><?= h($m['body']) ?></p>
      </div>
    </article>
  </li>
  <?php endforeach; ?>
</ol>

<?php endif; ?>

<?php if (($_GET['sec'] ?? '') === '1'): ?>
<div class="alert alert-success alert-dismissible py-2 small mb-3" role="alert">
  <i class="bi bi-check-circle me-1"></i>Wiadomość do sekretariatu wysłana.
  <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Zamknij"></button>
</div>
<?php elseif (($_GET['sec'] ?? '') === 'err'): ?>
<div class="alert alert-warning py-2 small mb-3" role="alert">
  <i class="bi bi-exclamation-triangle me-1"></i>Nie udało się wysłać wiadomości. Spróbuj ponownie lub skontaktuj się bezpośrednio.
</div>
<?php endif; ?>

<?php if ($msg_blocked): ?>
<div class="card border-warning">
  <div class="card-body text-center py-4 text-warning-emphasis">
    <i class="bi bi-slash-circle fs-3 d-block mb-2" aria-hidden="true"></i>
    <p class="mb-0 fw-semibold">Wysyłanie wiadomości zostało tymczasowo zablokowane przez prowadzącego.</p>
    <p class="small text-body-secondary mt-1 mb-0">Skontaktuj się z prowadzącym bezpośrednio lub z Kierownikiem Instytucji (formularz poniżej).</p>
  </div>
</div>
<?php else: ?>
<!-- Formularz nowej wiadomości do prowadzącego -->
<section aria-labelledby="replyHeading" class="card mb-3">
  <div class="card-header">
    <h2 class="h6 fw-bold mb-0" id="replyHeading">
      <i class="bi bi-pencil-square me-2" aria-hidden="true"></i>Napisz do Prowadzącego
    </h2>
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
      <input type="hidden" name="_op" value="msg_new">
      <input type="hidden" name="subject" value="">
      <div class="mb-3">
        <label class="form-label fw-semibold" for="newMsgBody">Treść <span class="text-danger" aria-hidden="true">*</span></label>
        <textarea class="form-control" id="newMsgBody" name="body"
                  rows="4" maxlength="4000" required
                  placeholder="Napisz wiadomość do prowadzącego…"></textarea>
        <div class="form-text">Prowadzący odpowie tutaj. <a href="?tab=ustawienia">Skonfiguruj powiadomienia e-mail / SMS</a>.</div>
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij wiadomość
      </button>
    </form>
  </div>
</section>
<?php endif; ?>

<?php $_kis_admins = ti_admin_users(); $_sek_users = ti_sekretariat_users(); ?>
<!-- Formularz do kierownictwa -->
<section aria-labelledby="secHeading" class="card">
  <div class="card-header">
    <h2 class="h6 fw-bold mb-0" id="secHeading">
      <i class="bi bi-building me-2" aria-hidden="true"></i>Napisz do Kierownictwa
    </h2>
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
      <input type="hidden" name="_op" value="msg_secretariat">
      <div class="mb-3">
        <label class="form-label fw-semibold" for="secMsgTo">Do <span class="text-danger" aria-hidden="true">*</span></label>
        <select class="form-select" id="secMsgTo" name="to_recip" required>
          <option value="KIS"><?= h(TI_KIS_NAME) ?> — Kierownik Instytucji</option>
          <?php if (!empty($_sek_users)): ?>
          <optgroup label="Sekretariat dydaktyki">
            <?php foreach ($_sek_users as $_su): ?>
            <option value="sek_<?= (int)$_su['id'] ?>"><?= h($_su['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
          <?php if (!empty($_kis_admins)): ?>
          <optgroup label="Administracja">
            <option value="all">Wszyscy administratorzy</option>
            <?php foreach ($_kis_admins as $_ka): ?>
            <option value="admin_<?= (int)$_ka['id'] ?>"><?= h($_ka['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold" for="secMsgBody">Treść <span class="text-danger" aria-hidden="true">*</span></label>
        <textarea class="form-control" id="secMsgBody" name="body"
                  rows="3" maxlength="4000" required
                  placeholder="Napisz wiadomość…"></textarea>
        <div class="form-text">Wiadomość zostanie wysłana e-mailem — otrzymasz odpowiedź bezpośrednio na swój adres.</div>
      </div>
      <button type="submit" class="btn btn-outline-primary">
        <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij
      </button>
    </form>
  </div>
</section>

<?php elseif ($tab === 'aktywnosc'):
  // Filtr zdarzeń widocznych dla kursanta (bez wewnętrznych akcji admina)
  $kursant_visible_actions = ['login','login_failed','logout','password_changed','msg_sent','msg_blocked_attempt','alias_changed','email_changed'];
  $placeholders = implode(',', array_fill(0, count($kursant_visible_actions), '?'));
  $kursant_log = db_all(
      "SELECT * FROM k30_ti_account_log WHERE student_id=? AND action IN ({$placeholders})
       ORDER BY created_at DESC, id DESC LIMIT 200",
      array_merge([(int)$student['id']], $kursant_visible_actions)
  );
  $action_labels_kursant = [
      'login'               => ['label' => 'Logowanie',            'icon' => 'bi-box-arrow-in-right', 'color' => 'text-success'],
      'login_failed'        => ['label' => 'Nieudane logowanie',   'icon' => 'bi-exclamation-triangle','color' => 'text-warning'],
      'password_changed'    => ['label' => 'Zmiana hasła',         'icon' => 'bi-key',                'color' => 'text-primary'],
      'msg_sent'            => ['label' => 'Wysłano wiadomość',    'icon' => 'bi-envelope-arrow-up',  'color' => 'text-secondary'],
      'msg_blocked_attempt' => ['label' => 'Próba wysyłki (blok)', 'icon' => 'bi-slash-circle',       'color' => 'text-danger'],
      'alias_changed'       => ['label' => 'Zmiana aliasu',        'icon' => 'bi-person-badge',       'color' => 'text-secondary'],
      'email_changed'       => ['label' => 'Zmiana adresu e-mail', 'icon' => 'bi-envelope-at',         'color' => 'text-primary'],
      'logout'              => ['label' => 'Wylogowanie',          'icon' => 'bi-box-arrow-right',    'color' => 'text-secondary'],
  ];
?>

<h1 class="h5 fw-bold mb-4">
  <i class="bi bi-clock-history text-primary me-2" aria-hidden="true"></i>Aktywność konta
</h1>

<?php if (empty($kursant_log)): ?>
<div class="card">
  <div class="card-body text-center text-body-secondary py-5">
    <i class="bi bi-clock-history fs-1 opacity-25 d-block mb-3" aria-hidden="true"></i>
    <p class="mb-0">Brak zarejestrowanych zdarzeń na tym koncie.</p>
  </div>
</div>
<?php else: ?>
<p class="text-body-secondary small mb-3">Historia ostatnich zdarzeń na Twoim koncie. Jeśli zauważysz nieznane logowania, zmień hasło lub skontaktuj się z prowadzącym.</p>
<ol class="list-unstyled d-flex flex-column gap-2" aria-label="Zdarzenia na koncie">
  <?php foreach ($kursant_log as $le):
    $cfg = $action_labels_kursant[$le['action']] ?? ['label' => $le['action'], 'icon' => 'bi-dot', 'color' => 'text-muted'];
    $ts  = $le['created_at'] ? date('d.m.Y, H:i', strtotime($le['created_at'])) : '';
    $device = !empty($le['user_agent']) ? ti_log_device_label($le['user_agent']) : '';
  ?>
  <li>
    <article class="d-flex gap-3 align-items-start border rounded-2 px-3 py-2">
      <span class="<?= $cfg['color'] ?> mt-1 flex-shrink-0" aria-hidden="true">
        <i class="bi <?= $cfg['icon'] ?>"></i>
      </span>
      <div class="flex-grow-1">
        <div class="fw-semibold small"><?= h($cfg['label']) ?></div>
        <?php if (!empty($le['detail'])): ?>
        <div class="text-body-secondary small"><?= h($le['detail']) ?></div>
        <?php endif; ?>
        <?php if ($device || !empty($le['ip'])): ?>
        <div class="text-body-secondary" style="font-size:.75rem">
          <?= $device ? h($device) : '' ?><?= ($device && !empty($le['ip'])) ? ' · ' : '' ?><?= !empty($le['ip']) ? h($le['ip']) : '' ?>
        </div>
        <?php endif; ?>
      </div>
      <time class="text-body-secondary small flex-shrink-0 text-end" datetime="<?= h($le['created_at'] ?? '') ?>">
        <?= h($ts) ?>
      </time>
    </article>
  </li>
  <?php endforeach; ?>
</ol>
<?php endif; ?>

<?php elseif ($tab === 'ustawienia'): ?>
<?php
  $cur_alias  = (string)($account['login_alias'] ?? '');
  $alias_msg  = (string)($_GET['alias']    ?? '');
  $alias_err  = rawurldecode((string)($_GET['aliaserr'] ?? ''));
  $email_err  = rawurldecode((string)($_GET['emailerr'] ?? ''));
  $ust_flash  = '';
  if (($_GET['sms']    ?? '') === 'on')    $ust_flash = 'Włączono powiadomienia SMS o zajęciach.';
  if (($_GET['sms']    ?? '') === 'off')   $ust_flash = 'Wyłączono powiadomienia SMS o zajęciach.';
  if (($_GET['prefs']  ?? '') === '1')     $ust_flash = 'Ustawienia powiadomień zapisane.';
  if (($_GET['dyd']    ?? '') === '1')     $ust_flash = 'Ustawienia powiadomień o dydaktyce zapisane.';
  if (($_GET['cal']    ?? '') === 'reset') $ust_flash = 'Adres kalendarza zmieniony — zaktualizuj subskrypcję.';
  if (($_GET['email']  ?? '') === 'ok')    $ust_flash = 'Adres e-mail zaktualizowany.';
  if (($_GET['pwok']   ?? '') === '1')     $ust_flash = 'Hasło zostało zmienione.';
  if (($_GET['phones'] ?? '') === '1')     $ust_flash = 'Numery do powiadomień SMS zapisane.';
  if ($alias_msg === 'ok')                 $ust_flash = 'Alias logowania zapisany.';
  if ($alias_msg === 'removed')            $ust_flash = 'Alias logowania usunięty.';
  $pwerr = rawurldecode((string)($_GET['pwerr'] ?? ''));
?>

<?php if ($ust_flash !== ''): ?>
<div class="alert alert-success alert-dismissible d-flex gap-2 align-items-center py-2 mb-3" role="alert">
  <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>
  <span><?= h($ust_flash) ?></span>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
</div>
<?php endif; ?>
<?php if ($pwerr !== ''): ?>
<div class="alert alert-danger alert-dismissible d-flex gap-2 align-items-center py-2 mb-3" role="alert">
  <i class="bi bi-exclamation-circle-fill flex-shrink-0" aria-hidden="true"></i>
  <span><?= h($pwerr) ?></span>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
</div>
<?php endif; ?>

<div class="row g-0 ust-layout">

  <!-- lewa: nawigacja sekcji -->
  <div class="col-12 col-md-3 col-xl-2 pe-md-3 mb-3 mb-md-0">
    <nav class="ust-sidenav d-flex flex-row flex-md-column gap-1" aria-label="Sekcje ustawien">
      <a href="#ust-bezp"   class="ust-navlink btn btn-sm text-start"><i class="bi bi-shield-lock  me-2" aria-hidden="true"></i>Bezpieczenstwo</a>
      <a href="#ust-notify" class="ust-navlink btn btn-sm text-start"><i class="bi bi-bell          me-2" aria-hidden="true"></i>Powiadomienia</a>
      <a href="#ust-push"   class="ust-navlink btn btn-sm text-start"><i class="bi bi-bell-fill     me-2" aria-hidden="true"></i>Push</a>
      <a href="#ust-cal"    class="ust-navlink btn btn-sm text-start"><i class="bi bi-calendar-plus me-2" aria-hidden="true"></i>Kalendarz</a>
    </nav>
  </div>

  <!-- prawa: tresc -->
  <div class="col-12 col-md-9 col-xl-10 d-flex flex-column gap-4">

    <!-- 1. Bezpieczenstwo -->
    <section id="ust-bezp" class="card" aria-labelledby="ust-bezp-h">
      <div class="card-header d-flex align-items-center gap-2 py-2">
        <i class="bi bi-shield-lock text-primary" aria-hidden="true"></i>
        <h2 id="ust-bezp-h" class="h6 fw-bold mb-0">Bezpieczenstwo</h2>
      </div>
      <div class="card-body">
        <div class="row g-4">
          <!-- Alias -->
          <div class="col-12 col-lg-6">
            <h3 class="h6 fw-semibold mb-1"><i class="bi bi-person-badge me-2 text-secondary" aria-hidden="true"></i>Alias logowania</h3>
            <p class="text-body-secondary small mb-2">Wlasna nazwa zamiast przydzielonego loginu. Unikalny, 3-30 znakow: a-z, cyfry, <code>.</code> <code>-</code> <code>_</code>.</p>
            <?php if ($alias_err !== ''): ?>
            <div class="alert alert-danger py-1 small mb-2"><i class="bi bi-exclamation-circle me-1"></i><?= h($alias_err) ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="off" class="d-flex flex-column gap-2">
              <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
              <input type="hidden" name="_op" value="set_alias">
              <div>
                <label class="form-label small mb-1" for="alias-input">Alias<?php if ($cur_alias !== ''): ?> — aktywny: <code class="text-info fw-bold"><?= h($cur_alias) ?></code><?php endif; ?></label>
                <input type="text" class="form-control form-control-sm font-monospace" id="alias-input" name="alias"
                       value="<?= h($cur_alias) ?>" maxlength="30" autocomplete="off"
                       placeholder="np. jan.kowalski" pattern="[a-z0-9][a-z0-9._\-]{1,28}[a-z0-9]"
                       aria-describedby="alias-hint">
                <div class="form-text" id="alias-hint">Login systemowy <code><?= h((string)($account['login'] ?? '')) ?></code> zawsze dziala.</div>
              </div>
              <div class="d-flex gap-2">
                <button class="btn btn-primary btn-sm"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz alias</button>
                <?php if ($cur_alias !== ''): ?>
                <button type="submit" form="alias-remove-form" class="btn btn-outline-secondary btn-sm">Usun alias</button>
                <?php endif; ?>
              </div>
            </form>
            <?php if ($cur_alias !== ''): ?>
            <form method="post" id="alias-remove-form" class="d-none">
              <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
              <input type="hidden" name="_op" value="set_alias">
              <input type="hidden" name="alias" value="">
            </form>
            <?php endif; ?>
          </div>
          <!-- Haslo -->
          <div class="col-12 col-lg-6">
            <h3 class="h6 fw-semibold mb-1"><i class="bi bi-key me-2 text-secondary" aria-hidden="true"></i>Zmien haslo</h3>
            <p class="text-body-secondary small mb-2">Ustaw wlasne haslo do panelu kursanta (min. 8 znakow).</p>
            <form method="post" autocomplete="off" class="d-flex flex-column gap-2">
              <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
              <input type="hidden" name="_op" value="change_password">
              <div>
                <label class="form-label small mb-1" for="cps-cur">Aktualne haslo</label>
                <input type="password" class="form-control form-control-sm" id="cps-cur" name="current" required autocomplete="current-password">
              </div>
              <div>
                <label class="form-label small mb-1" for="cps-new">Nowe haslo</label>
                <input type="password" class="form-control form-control-sm" id="cps-new" name="new" required minlength="8" autocomplete="new-password"
                       placeholder="min. 8 znakow" oninput="kpPwMeter(this,'cps-new-meter')">
                <div class="progress mt-1" style="height:5px" aria-hidden="true">
                  <div class="progress-bar" id="cps-new-meter-bar" style="width:0%"></div>
                </div>
                <div class="form-text" id="cps-new-meter-text" aria-live="polite"></div>
              </div>
              <div>
                <label class="form-label small mb-1" for="cps-cnf">Powtorz nowe haslo</label>
                <input type="password" class="form-control form-control-sm" id="cps-cnf" name="confirm" required minlength="8" autocomplete="new-password">
              </div>
              <div>
                <button class="btn btn-primary btn-sm"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz haslo</button>
              </div>
            </form>
          </div>
          <!-- E-mail -->
          <div class="col-12 col-lg-6">
            <h3 class="h6 fw-semibold mb-1"><i class="bi bi-envelope-at me-2 text-secondary" aria-hidden="true"></i>Zmień e-mail</h3>
            <p class="text-body-secondary small mb-2">Główny adres e-mail — używany do kontaktu i powiadomień.</p>
            <?php if ($email_err !== ''): ?>
            <div class="alert alert-danger py-1 small mb-2"><i class="bi bi-exclamation-circle me-1"></i><?= h($email_err) ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="off" class="d-flex flex-column gap-2">
              <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
              <input type="hidden" name="_op" value="change_email">
              <div>
                <label class="form-label small mb-1" for="em-addr">Adres e-mail</label>
                <input type="email" class="form-control form-control-sm" id="em-addr" name="email" maxlength="190" required
                       value="<?= h((string)($client['email'] ?? '')) ?>" placeholder="np. jan.kowalski@example.com">
              </div>
              <div>
                <button class="btn btn-primary btn-sm"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz e-mail</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </section>

    <!-- 2. Powiadomienia -->
    <section id="ust-notify" class="card" aria-labelledby="ust-notify-h">
      <div class="card-header d-flex align-items-center gap-2 py-2">
        <i class="bi bi-bell text-primary" aria-hidden="true"></i>
        <h2 id="ust-notify-h" class="h6 fw-bold mb-0">Powiadomienia</h2>
        <?php if (!$sms_global_on): ?><span class="badge text-bg-secondary ms-auto">SMS niedostepny</span><?php endif; ?>
      </div>
      <div class="card-body">
        <div class="table-responsive mb-3">
          <table class="table table-sm align-middle mb-0" style="font-size:.875rem">
            <thead>
              <tr>
                <th class="ps-0 fw-semibold border-bottom" style="width:55%">Zdarzenie</th>
                <th class="text-center border-bottom"><i class="bi bi-envelope" aria-hidden="true"></i><span class="visually-hidden">E-mail</span><div class="small text-body-secondary fw-normal">E-mail</div></th>
                <th class="text-center border-bottom"><i class="bi bi-chat-dots" aria-hidden="true"></i><span class="visually-hidden">SMS</span><div class="small text-body-secondary fw-normal">SMS</div></th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="ps-0">
                  Nowa wiadomosc od prowadzacego
                  <?php if ($msg_email_addr !== ''): ?><div class="text-body-secondary" style="font-size:.78rem"><?= h($msg_email_addr) ?></div><?php endif; ?>
                </td>
                <td class="text-center">
                  <form method="post" id="msgPrefsEmailForm" class="d-inline">
                    <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
                    <input type="hidden" name="_op" value="msg_prefs">
                    <input type="hidden" name="sms" value="<?= $msg_pref_sms ? '1' : '0' ?>">
                    <div class="form-check form-switch d-inline-flex m-0 justify-content-center">
                      <input class="form-check-input" type="checkbox" role="switch" id="prefEmail" name="email" value="1"
                             <?= $msg_pref_email ? 'checked' : '' ?> onchange="this.form.submit()"
                             <?= ($msg_email_addr === '') ? 'disabled' : '' ?>>
                      <label class="visually-hidden" for="prefEmail">E-mail: wiadomosci</label>
                    </div>
                  </form>
                </td>
                <td class="text-center">
                  <form method="post" id="msgPrefsSmsForm" class="d-inline">
                    <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
                    <input type="hidden" name="_op" value="msg_prefs">
                    <input type="hidden" name="email" value="<?= $msg_pref_email ? '1' : '0' ?>">
                    <div class="form-check form-switch d-inline-flex m-0 justify-content-center">
                      <input class="form-check-input" type="checkbox" role="switch" id="prefSms" name="sms" value="1"
                             <?= $msg_pref_sms ? 'checked' : '' ?> <?= $sms_global_on ? '' : 'disabled' ?> onchange="this.form.submit()">
                      <label class="visually-hidden" for="prefSms">SMS: wiadomosci</label>
                    </div>
                  </form>
                </td>
              </tr>
              <tr>
                <td class="ps-0">Nowe zajecia dodane przez prowadzacego</td>
                <td class="text-center text-body-secondary"><i class="bi bi-dash" aria-hidden="true"></i></td>
                <td class="text-center">
                  <form method="post" id="smsPrefForm" class="d-inline">
                    <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
                    <input type="hidden" name="_op" value="toggle_sms_lessons">
                    <div class="form-check form-switch d-inline-flex m-0 justify-content-center">
                      <input class="form-check-input" type="checkbox" role="switch" id="smsToggle" name="enabled" value="1"
                             <?= $sms_pref ? 'checked' : '' ?> <?= $sms_global_on ? '' : 'disabled' ?> onchange="this.form.submit()">
                      <label class="visually-hidden" for="smsToggle">SMS: nowe zajecia</label>
                    </div>
                  </form>
                </td>
              </tr>
              <tr>
                <td class="ps-0">Nowe materialy lub zadania (dydaktyka)</td>
                <td class="text-center">
                  <form method="post" id="dydPrefsEmailForm" class="d-inline">
                    <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
                    <input type="hidden" name="_op" value="dyd_prefs">
                    <input type="hidden" name="sms" value="<?= $dyd_pref_sms ? '1' : '0' ?>">
                    <div class="form-check form-switch d-inline-flex m-0 justify-content-center">
                      <input class="form-check-input" type="checkbox" role="switch" id="dydEmail" name="email" value="1"
                             <?= $dyd_pref_email ? 'checked' : '' ?> onchange="this.form.submit()"
                             <?= ($msg_email_addr === '') ? 'disabled' : '' ?>>
                      <label class="visually-hidden" for="dydEmail">E-mail: dydaktyka</label>
                    </div>
                  </form>
                </td>
                <td class="text-center">
                  <form method="post" id="dydPrefsSmsForm" class="d-inline">
                    <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
                    <input type="hidden" name="_op" value="dyd_prefs">
                    <input type="hidden" name="email" value="<?= $dyd_pref_email ? '1' : '0' ?>">
                    <div class="form-check form-switch d-inline-flex m-0 justify-content-center">
                      <input class="form-check-input" type="checkbox" role="switch" id="dydSms" name="sms" value="1"
                             <?= $dyd_pref_sms ? 'checked' : '' ?> <?= $sms_global_on ? '' : 'disabled' ?> onchange="this.form.submit()">
                      <label class="visually-hidden" for="dydSms">SMS: dydaktyka</label>
                    </div>
                  </form>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <?php if ($sms_global_on):
          $ph2val = trim((string)($account['notify_phone2'] ?? ''));
          $ph3val = trim((string)($account['notify_phone3'] ?? ''));
          $ph2ok  = !empty($account['notify_phone2_verified']);
          $ph3ok  = !empty($account['notify_phone3_verified']);

          $phones_qs  = (string)($_GET['phones'] ?? '');
          $phones_msg = null;
          if ($phones_qs === 'err_missing')                     $phones_msg = ['danger',  'Najpierw zapisz numer, zanim wyślesz kod.'];
          elseif ($phones_qs === 'err_sms_off')                 $phones_msg = ['danger',  'Wysyłka SMS jest obecnie wyłączona — poproś administratora o ręczne zatwierdzenie numeru.'];
          elseif (str_starts_with($phones_qs, 'otp_sent_'))     $phones_msg = ['success', 'Kod weryfikacyjny wysłany SMS-em — wpisz go poniżej (ważny 10 minut).'];
          elseif (str_starts_with($phones_qs, 'verified_'))     $phones_msg = ['success', 'Numer potwierdzony — od teraz będzie otrzymywał powiadomienia.'];
          elseif (str_starts_with($phones_qs, 'err_code_'))     $phones_msg = ['danger',  'Nieprawidłowy lub wygasły kod.'];
          elseif ($phones_qs === '1')                           $phones_msg = ['success', 'Numery do powiadomień SMS zapisane.'];
        ?>
        <div class="border-top pt-3 d-flex flex-wrap align-items-center gap-3">
          <div class="flex-grow-1 min-width-0">
            <h3 class="h6 fw-semibold mb-1"><i class="bi bi-telephone-plus me-2 text-secondary" aria-hidden="true"></i>Dodatkowe numery do SMS</h3>
            <div class="small text-body-secondary">
              <?php if ($ph2val === '' && $ph3val === ''): ?>
              Nie dodano dodatkowych numerów.
              <?php else: ?>
              <?php if ($ph2val !== ''): ?>
              <span class="badge <?= $ph2ok ? 'text-bg-success' : 'text-bg-warning' ?>"><i class="bi bi-<?= $ph2ok ? 'check-circle' : 'hourglass-split' ?> me-1" aria-hidden="true"></i><?= $ph2ok ? 'Zweryfikowany' : 'Niepotwierdzony' ?></span>
              <?= h($ph2val) ?>
              <?php endif; ?>
              <?php if ($ph3val !== ''): ?>
              <span class="badge <?= $ph3ok ? 'text-bg-success' : 'text-bg-warning' ?>"><i class="bi bi-<?= $ph3ok ? 'check-circle' : 'hourglass-split' ?> me-1" aria-hidden="true"></i><?= $ph3ok ? 'Zweryfikowany' : 'Niepotwierdzony' ?></span>
              <?= h($ph3val) ?>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm flex-shrink-0" data-bs-toggle="modal" data-bs-target="#phonesModal">
            <i class="bi bi-telephone-plus me-1" aria-hidden="true"></i>Zarządzaj numerami
          </button>
        </div>

        <!-- Modal: dodatkowe numery do SMS (zapis + weryfikacja kodem/administratorem) -->
        <div class="modal fade" id="phonesModal" tabindex="-1" aria-labelledby="phonesModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
              <div class="modal-header">
                <h2 class="modal-title h5" id="phonesModalLabel"><i class="bi bi-telephone-plus text-primary me-2" aria-hidden="true"></i>Dodatkowe numery do SMS</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
              </div>
              <div class="modal-body">
                <?php if ($sms_phone !== ''): ?>
                <p class="text-body-secondary small mb-2">Numer glowny: <span class="font-monospace"><?= h(preg_replace('/.(?=.{2})/u', 'x', $sms_phone)) ?></span></p>
                <?php endif; ?>
                <p class="text-body-secondary small mb-3">
                  <i class="bi bi-shield-check me-1" aria-hidden="true"></i>Nowy numer musi zostać potwierdzony — wyślij sobie kod SMS albo poczekaj na
                  ręczne zatwierdzenie przez administratora. Dopóki numer nie jest potwierdzony, nie otrzyma żadnych powiadomień.
                </p>

                <?php if ($phones_msg): ?>
                <div class="alert alert-<?= $phones_msg[0] ?> py-2 small" role="<?= $phones_msg[0] === 'danger' ? 'alert' : 'status' ?>"><?= h($phones_msg[1]) ?></div>
                <?php endif; ?>

                <form method="post" class="row g-2 align-items-end mb-3">
                  <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
                  <input type="hidden" name="_op" value="notify_phones">
                  <div class="col-sm-5">
                    <label class="form-label small mb-1" for="ph2">Drugi numer</label>
                    <input type="tel" class="form-control form-control-sm" id="ph2" name="phone2" maxlength="30" value="<?= h($ph2val) ?>" placeholder="np. 600 700 800">
                  </div>
                  <div class="col-sm-5">
                    <label class="form-label small mb-1" for="ph3">Trzeci numer</label>
                    <input type="tel" class="form-control form-control-sm" id="ph3" name="phone3" maxlength="30" value="<?= h($ph3val) ?>" placeholder="np. 600 700 900">
                  </div>
                  <div class="col-sm-2">
                    <button class="btn btn-primary btn-sm w-100"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
                  </div>
                </form>

                <?php foreach ([2, 3] as $wn):
                  $pval = trim((string)($account["notify_phone{$wn}"] ?? ''));
                  if ($pval === '') continue;
                  $verified        = !empty($account["notify_phone{$wn}_verified"]);
                  $otp_exp         = (string)($account["notify_phone{$wn}_otp_expires"] ?? '');
                  $has_pending_otp = !empty($account["notify_phone{$wn}_otp"]) && $otp_exp !== '' && $otp_exp > date('Y-m-d H:i:s');
                  $wn_word         = $wn === 2 ? 'drugi' : 'trzeci';
                ?>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small border-top pt-2">
                  <span class="text-body-secondary">Numer <?= $wn_word ?>:</span>
                  <span class="font-monospace"><?= h($pval) ?></span>
                  <?php if ($verified): ?>
                  <span class="badge text-bg-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Zweryfikowany</span>
                  <?php else: ?>
                  <span class="badge text-bg-warning"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Niepotwierdzony</span>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
                    <input type="hidden" name="_op" value="notify_phone_send_otp">
                    <input type="hidden" name="which" value="<?= $wn ?>">
                    <button class="btn btn-outline-secondary btn-sm py-0"><i class="bi bi-send me-1" aria-hidden="true"></i><?= $has_pending_otp ? 'Wyślij kod ponownie' : 'Wyślij kod SMS' ?></button>
                  </form>
                  <?php if ($has_pending_otp): ?>
                  <form method="post" class="d-flex gap-1 align-items-center">
                    <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
                    <input type="hidden" name="_op" value="notify_phone_verify_otp">
                    <input type="hidden" name="which" value="<?= $wn ?>">
                    <label class="visually-hidden" for="code<?= $wn ?>">Kod weryfikacyjny numeru <?= $wn_word ?>ego</label>
                    <input type="text" class="form-control form-control-sm" id="code<?= $wn ?>" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                           placeholder="kod" style="width:6rem" required>
                    <button class="btn btn-primary btn-sm">Potwierdź</button>
                  </form>
                  <?php endif; ?>
                  <?php endif; ?>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <?php if ($phones_msg): ?>
        <script>document.addEventListener('DOMContentLoaded', function(){ if (window.bootstrap) new bootstrap.Modal(document.getElementById('phonesModal')).show(); });</script>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- 2b. Powiadomienia push -->
    <section id="ust-push" class="card" aria-labelledby="ust-push-h">
      <div class="card-header d-flex align-items-center gap-2 py-2">
        <i class="bi bi-bell text-primary" aria-hidden="true"></i>
        <h2 id="ust-push-h" class="h6 fw-bold mb-0">Powiadomienia push</h2>
      </div>
      <div class="card-body">
        <p class="small text-body-secondary mb-3">
          Otrzymuj powiadomienia przeglądarkowe o nowych wiadomościach od prowadzącego i ocenach — nawet gdy panel jest zamknięty.
        </p>
        <button type="button" class="btn btn-primary btn-sm kp-push-enable-btn" onclick="kpEnablePush()" style="display:none">
          <i class="bi bi-bell-fill me-1"></i>Włącz powiadomienia
        </button>
        <span class="kp-push-enabled text-success small" style="display:none">
          <i class="bi bi-check-circle me-1"></i>Powiadomienia push włączone
        </span>
        <span class="kp-push-unsupported text-body-secondary small" style="display:none">
          <i class="bi bi-info-circle me-1"></i>Twoja przeglądarka nie obsługuje powiadomień push.
        </span>
      </div>
    </section>

    <!-- 3. Kalendarz -->
    <section id="ust-cal" class="card" aria-labelledby="ust-cal-h">
      <div class="card-header d-flex align-items-center gap-2 py-2">
        <i class="bi bi-calendar-plus text-primary" aria-hidden="true"></i>
        <h2 id="ust-cal-h" class="h6 fw-bold mb-0">Synchronizacja z kalendarzem</h2>
      </div>
      <div class="card-body">
        <p class="text-body-secondary small mb-3">Dodaj lekcje do Kalendarza Google, Apple lub Outlook. Odswiezasie automatycznie po kazdej zmianie.</p>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <a href="<?= h($cal_gcal) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary"><i class="bi bi-google me-1" aria-hidden="true"></i>Google Calendar</a>
          <a href="<?= h($cal_webcal) ?>" class="btn btn-sm btn-info"><i class="bi bi-apple me-1" aria-hidden="true"></i>Apple / Outlook</a>
          <a href="<?= h($cal_https) ?>" class="btn btn-sm btn-outline-secondary" download="lekcje.ics"><i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz .ics</a>
        </div>
        <label class="form-label small fw-semibold mb-1" for="cal-url">Adres kanalu</label>
        <div class="input-group input-group-sm mb-3">
          <input type="text" class="form-control font-monospace" id="cal-url" value="<?= h($cal_https) ?>"
                 readonly aria-label="Adres kanalu iCal" onclick="this.select()" style="font-size:.8rem">
          <button type="button" class="btn btn-outline-secondary" id="cal-copy"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj</button>
        </div>
        <div class="d-flex align-items-center gap-3">
          <p class="text-body-secondary mb-0 small flex-grow-1"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Adres jest prywatny. Jesli wyciekl, zresetuj go.</p>
          <form method="post" class="m-0 flex-shrink-0" onsubmit="return confirm('Zresetowac adres kalendarza?')">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op" value="reset_calendar_token">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Resetuj adres</button>
          </form>
        </div>
      </div>
    </section>

  </div>
</div>

<style>
.ust-sidenav { flex-wrap:wrap; }
.ust-navlink { color:var(--bs-body-color); border:0; border-radius:.375rem; padding:.35rem .65rem; text-decoration:none; transition:background .12s; }
.ust-navlink:hover { background:var(--bs-tertiary-bg); color:var(--bs-body-color); }
.ust-navlink.active { background:var(--bs-secondary-bg); font-weight:600; }
@media (min-width:768px) {
  .ust-sidenav { position:sticky; top:1rem; }
  .ust-navlink { width:100%; }
}
</style>
<script>
(function(){
  var btn = document.getElementById('cal-copy');
  var inp = document.getElementById('cal-url');
  if (btn && inp) {
    btn.addEventListener('click', function(){
      inp.select();
      var orig = btn.innerHTML;
      var done = function(){
        btn.innerHTML = '<i class="bi bi-check-lg me-1" aria-hidden="true"></i>Skopiowano';
        setTimeout(function(){ btn.innerHTML = orig; }, 1500);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(inp.value).then(done, function(){ try { document.execCommand('copy'); done(); } catch(e){} });
      } else { try { document.execCommand('copy'); done(); } catch(e){} }
    });
  }
  var links = document.querySelectorAll('.ust-navlink');
  var targets = Array.prototype.map.call(links, function(l){ return document.querySelector(l.getAttribute('href')); });
  function setActive(){
    var cur = 0;
    targets.forEach(function(t,i){ if (t && t.getBoundingClientRect().top <= 120) cur = i; });
    links.forEach(function(l,i){ l.classList.toggle('active', i === cur); });
  }
  setActive();
  window.addEventListener('scroll', setActive, { passive:true });
})();
</script>

<?php elseif ($tab === 'licencje'): ?>

  <h1 class="h5 fw-bold mb-1"><i class="bi bi-key text-primary me-1" aria-hidden="true"></i>Moje licencje</h1>
  <p class="text-body-secondary small mb-3">
    Licencje na oprogramowanie (inne niż Microsoft&nbsp;365) przypisane do Ciebie. Klucze i hasła trzymaj w tajemnicy.
  </p>
  <?php
    $rv_client_id        = $student['client_id'];
    $rv_lic_term         = ti_term_get('licencje');
    $rv_lic_term_ok      = ti_term_accepted((int)$student['client_id'], 'licencje');
    $rv_lic_token         = $vlab_token;
    $rv_lic_redirect_tab  = 'licencje';
    include __DIR__ . '/_licencje_view.php';
  ?>

<?php elseif ($tab === 'dysk'):
    $oc_msg         = $_SESSION['k30_oc_msg'] ?? null; unset($_SESSION['k30_oc_msg']);
    $oc_reveal      = $_SESSION['owncloud_reveal'] ?? null; unset($_SESSION['owncloud_reveal']);
    $oc_has_account = !empty($account['owncloud_username']);
    $oc_ready       = owncloud_enabled() && owncloud_admin_configured();
?>

  <h1 class="h5 fw-bold mb-1"><i class="bi bi-hdd-network text-primary me-1" aria-hidden="true"></i>Mój dysk</h1>
  <p class="text-body-secondary small mb-3">
    Własne miejsce na pliki w chmurze ownCloud — materiały, kopie notatek, projekty.
    To nie jest to samo co zakładka „Dydaktyka / eLearning" — tam oddajesz zadania domowe prowadzącemu.
  </p>

  <?php if ($oc_msg): ?>
  <div class="alert alert-<?= $oc_msg[0]==='ok'?'success':'danger' ?> alert-dismissible fade show" role="alert">
    <i class="bi bi-<?= $oc_msg[0]==='ok'?'check-circle':'exclamation-triangle' ?> me-1" aria-hidden="true"></i><?= h($oc_msg[1]) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
  <?php endif; ?>

  <?php if ($oc_reveal): ?>
  <div class="alert alert-warning shadow-sm" role="alert" style="max-width:520px">
    <h2 class="h6 fw-bold mb-2"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>Zapisz dane logowania — pokażemy je tylko raz</h2>
    <dl class="row mb-2 small">
      <dt class="col-4">Adres</dt>
      <dd class="col-8"><a href="<?= h($oc_reveal['url']) ?>" target="_blank" rel="noopener"><?= h($oc_reveal['url']) ?></a></dd>
      <dt class="col-4">Login</dt><dd class="col-8 font-monospace"><?= h($oc_reveal['username']) ?></dd>
      <dt class="col-4">Hasło</dt><dd class="col-8 font-monospace"><?= h($oc_reveal['password']) ?></dd>
      <dt class="col-4">Limit</dt><dd class="col-8"><?= (int)$oc_reveal['quota_mb'] ?> MB</dd>
    </dl>
    <p class="small mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Po opuszczeniu tej strony hasła nie pokażemy ponownie — w razie potrzeby zresetuj je przyciskiem poniżej.</p>
  </div>
  <?php endif; ?>

  <?php if (!$oc_ready): ?>
  <div class="alert alert-secondary" role="note">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Ta funkcja nie jest jeszcze skonfigurowana przez administratora. Spróbuj później.
  </div>
  <?php elseif (!$oc_has_account): ?>
  <div class="card" style="max-width:460px">
    <div class="card-body">
      <h2 class="h6 fw-bold mb-2">Nie masz jeszcze konta</h2>
      <p class="small text-body-secondary">
        Utworzymy konto ownCloud z limitem <?= (int)owncloud_setting('student_quota_mb', '2048') ?> MB.
        Login i hasło zobaczysz od razu po utworzeniu — zapisz je w bezpiecznym miejscu.
      </p>
      <form method="post">
        <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
        <input type="hidden" name="_op"    value="owncloud_create">
        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Utwórz konto</button>
      </form>
    </div>
  </div>
  <?php else: ?>
  <div class="card" style="max-width:460px">
    <div class="card-body">
      <h2 class="h6 fw-bold mb-2"><i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i>Twoje konto</h2>
      <dl class="row mb-3 small">
        <dt class="col-4">Login</dt><dd class="col-8 font-monospace"><?= h($account['owncloud_username']) ?></dd>
        <dt class="col-4">Limit</dt><dd class="col-8"><?= (int)$account['owncloud_quota_mb'] ?> MB</dd>
        <dt class="col-4">Założone</dt>
        <dd class="col-8"><?= $account['owncloud_created_at'] ? h(date('d.m.Y', strtotime($account['owncloud_created_at']))) : '—' ?></dd>
      </dl>
      <div class="d-flex flex-wrap gap-2">
        <a href="<?= h(rtrim(owncloud_setting('url'), '/')) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary">
          <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz ownCloud
        </a>
        <form method="post" onsubmit="return confirm('Zresetować hasło? Stare hasło stanie się nieprawidłowe.');">
          <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
          <input type="hidden" name="_op"    value="owncloud_reset">
          <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-key me-1" aria-hidden="true"></i>Resetuj hasło</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

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

  <div class="alert alert-info d-flex align-items-start gap-2 mb-4" role="note">
    <i class="bi bi-camera-video-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
    <div>
      <strong>Platforma Moodle została wyłączona.</strong>
      Od 1 września zajęcia odbywają się przez <strong>Zoom</strong>.
      Materiały i zadania dostępne są w zakładce <a href="?tab=zadania" class="alert-link">Dydaktyka / eLearning</a>.
    </div>
  </div>

  <?php if (false && $moodle_courses_student): ?>
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
  <?php if ($is_minor && $tp['type'] === 'vlab'): ?>
  <div class="alert alert-info d-flex align-items-start gap-2 mb-4">
    <i class="bi bi-people-fill mt-1" aria-hidden="true"></i>
    <span><strong><?= h($tp['title']) ?></strong> — jesteś niepełnoletni/a, ten regulamin musi zaakceptować Twój opiekun w panelu rodzica (zakładka „Regulamin VLab").</span>
  </div>
  <?php else: ?>
  <?= _ti_terms_acceptance_block($tp, $vlab_token, 'regulaminy') ?>
  <?php endif; ?>
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
            <td><?= h($ta['title']) ?>
              <?php if (($ta['accepted_by_role'] ?? 'kursant') === 'rodzic'): ?>
              <span class="badge text-bg-secondary ms-1" title="Zaakceptowane przez opiekuna"><i class="bi bi-people-fill" aria-hidden="true"></i> opiekun</span>
              <?php endif; ?>
            </td>
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

<?php if ($tab === 'upowaznieni' && !$is_minor): ?>

<?php
$authp_list = db_all(
    "SELECT * FROM k30_ti_authorized_persons WHERE student_account_id=? ORDER BY created_at DESC",
    [(int)$student['id']]
);
?>

<h1 class="h5 fw-bold mb-1"><i class="bi bi-person-check text-primary me-2" aria-hidden="true"></i>Osoby upoważnione</h1>
<p class="text-body-secondary small mb-3">Osoby, które administrator upoważnił do wglądu w Twój panel. Logują się do panelu przez oddzielny adres.</p>

<div class="alert alert-info d-flex gap-2 py-2 small mb-3" role="note">
  <i class="bi bi-info-circle flex-shrink-0 mt-1" aria-hidden="true"></i>
  <span>Listą upoważnień zarządza administrator. Aby dodać lub usunąć osobę, skontaktuj się z placówką.</span>
</div>

<?php if ($authp_list): ?>
<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Imię i nazwisko</th>
          <th>Uwagi</th>
          <th>Ostatnie logowanie</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($authp_list as $ap): ?>
        <tr class="<?= $ap['is_active'] ? '' : 'text-body-secondary' ?>">
          <td class="fw-semibold"><?= h($ap['name']) ?></td>
          <td class="small"><?= h($ap['notes']) ?: '—' ?></td>
          <td class="small text-nowrap"><?= $ap['last_login'] ? h(date('d.m.Y H:i', strtotime($ap['last_login']))) : '—' ?></td>
          <td>
            <?php if ($ap['is_active']): ?>
            <span class="badge text-bg-success">aktywna</span>
            <?php else: ?>
            <span class="badge text-bg-secondary">wstrzymana</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="text-body-secondary small"><i class="bi bi-inbox me-1" aria-hidden="true"></i>Brak upoważnionych osób.</div>
<?php endif; ?>

<?php elseif ($tab === 'komunikaty'): ?>

<?php
  $notices_all = ti_notices_list_for_student((int)$student['id']);
?>
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-megaphone text-primary me-1" aria-hidden="true"></i>Komunikaty placówki</h1>
  <?php if ($notices_unread > 0): ?>
  <form method="post" class="ms-auto">
    <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
    <input type="hidden" name="_op" value="mark_notices_all_read">
    <button class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-check2-all me-1"></i>Oznacz wszystkie jako przeczytane
    </button>
  </form>
  <?php endif; ?>
</div>

<?php if (!$notices_all): ?>
<div class="text-body-secondary text-center py-5">
  <i class="bi bi-megaphone fs-2 d-block mb-2 opacity-40" aria-hidden="true"></i>
  Brak aktywnych komunikatów.
</div>
<?php else: ?>
<div class="d-flex flex-column gap-3">
<?php foreach ($notices_all as $n):
  $is_read   = (int)$n['is_read'];
  $is_pinned = (int)$n['is_pinned'];
?>
<div class="card border-0 shadow-sm <?= !$is_read ? 'border-start border-primary border-3' : '' ?>"
     style="<?= $is_pinned ? 'border-left:4px solid #f59e0b!important' : (!$is_read ? '' : '') ?>">
  <div class="card-body">
    <div class="d-flex align-items-start gap-2 mb-1">
      <?php if ($is_pinned): ?><i class="bi bi-pin-angle-fill text-warning flex-shrink-0 mt-1" title="Przypięty" aria-hidden="true"></i><?php endif; ?>
      <h2 class="h6 fw-bold mb-0 flex-grow-1 <?= !$is_read ? 'text-primary' : '' ?>">
        <?php if (!$is_read): ?><span class="visually-hidden">(Nowe) </span><?php endif; ?>
        <?= h($n['title']) ?>
      </h2>
      <?php if (!$is_read): ?>
      <span class="badge text-bg-primary flex-shrink-0">Nowe</span>
      <?php endif; ?>
    </div>
    <?php if ($n['body']): ?>
    <div class="text-body-secondary" style="white-space:pre-wrap;font-size:.92rem"><?= h($n['body']) ?></div>
    <?php endif; ?>
    <div class="mt-2 d-flex align-items-center gap-3" style="font-size:.78rem">
      <span class="text-body-secondary"><i class="bi bi-clock me-1" aria-hidden="true"></i><?= substr($n['created_at'],0,10) ?></span>
      <?php if ($n['expires_at']): ?>
      <span class="text-body-secondary"><i class="bi bi-calendar-x me-1" aria-hidden="true"></i>ważny do <?= h($n['expires_at']) ?></span>
      <?php endif; ?>
      <?php if (!$is_read): ?>
      <form method="post" class="ms-auto">
        <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
        <input type="hidden" name="_op" value="mark_notice_read">
        <input type="hidden" name="notice_id" value="<?= (int)$n['id'] ?>">
        <button class="btn btn-sm btn-link p-0 text-body-secondary" style="font-size:.78rem">
          <i class="bi bi-check2 me-1"></i>Oznacz jako przeczytany
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

</main>

<!-- ── Modal: szybka wiadomość do prowadzącego (z dashboardu) ──────────── -->
<?php if (!empty($instructors_for_quick ?? [])): ?>
<div class="modal fade" id="modalQuickMsg" tabindex="-1" aria-labelledby="modalQuickMsgLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h2 class="modal-title h5 fw-bold" id="modalQuickMsgLabel"><i class="bi bi-chat-dots me-2"></i>Szybka wiadomość</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <form method="post">
        <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
        <input type="hidden" name="_op" value="msg_new">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Prowadzący</label>
            <select name="thread_subject" class="form-select form-select-sm">
              <?php foreach ($instructors_for_quick as $ins): ?>
              <option value="Wiadomość do: <?= h($ins['name']) ?>"><?= h($ins['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="quickMsgBody">Wiadomość</label>
            <textarea name="body" id="quickMsgBody" class="form-control form-control-sm" rows="4" required placeholder="Napisz wiadomość…" maxlength="4000"></textarea>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Wyślij</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Bottom nav — widoczny tylko na mobile (<= lg) ────────────────────── -->
<nav class="d-lg-none fixed-bottom bg-body border-top" style="padding-bottom:env(safe-area-inset-bottom)" aria-label="Nawigacja główna (mobile)">
  <div class="d-flex justify-content-around py-1">
    <a href="?tab=dane" class="d-flex flex-column align-items-center text-decoration-none px-2 py-1 <?= $tab==='dane' ? 'text-primary' : 'text-body-secondary' ?>" style="min-width:56px">
      <i class="bi bi-house<?= $tab==='dane' ? '-fill' : '' ?>" style="font-size:1.3rem"></i>
      <span style="font-size:.65rem">Dane</span>
    </a>
    <a href="?tab=lekcje" class="d-flex flex-column align-items-center text-decoration-none px-2 py-1 <?= $tab==='lekcje' ? 'text-primary' : 'text-body-secondary' ?>" style="min-width:56px">
      <i class="bi bi-calendar<?= $tab==='lekcje' ? '-check-fill' : '-check' ?>" style="font-size:1.3rem"></i>
      <span style="font-size:.65rem">Lekcje</span>
    </a>
    <a href="?tab=zadania" class="d-flex flex-column align-items-center text-decoration-none px-2 py-1 position-relative <?= $tab==='zadania' ? 'text-primary' : 'text-body-secondary' ?>" style="min-width:56px">
      <i class="bi bi-journal<?= $tab==='zadania' ? '-check' : '' ?>" style="font-size:1.3rem"></i>
      <?php if ($hw_pending_total > 0): ?><span class="position-absolute badge rounded-pill bg-warning text-dark" style="top:0;right:4px;font-size:.55rem;padding:.2em .4em"><?= $hw_pending_total ?></span><?php endif; ?>
      <span style="font-size:.65rem">Zadania</span>
    </a>
    <a href="?tab=wiadomosci" class="d-flex flex-column align-items-center text-decoration-none px-2 py-1 position-relative <?= $tab==='wiadomosci' ? 'text-primary' : 'text-body-secondary' ?>" style="min-width:56px">
      <i class="bi bi-envelope<?= $tab==='wiadomosci' ? '-fill' : '' ?>" style="font-size:1.3rem"></i>
      <?php if ($msg_unread > 0): ?><span class="position-absolute badge rounded-pill bg-primary" style="top:0;right:4px;font-size:.55rem;padding:.2em .4em"><?= $msg_unread ?></span><?php endif; ?>
      <span style="font-size:.65rem">Wiad.</span>
    </a>
    <a href="?tab=ustawienia" class="d-flex flex-column align-items-center text-decoration-none px-2 py-1 <?= $tab==='ustawienia' ? 'text-primary' : 'text-body-secondary' ?>" style="min-width:56px">
      <i class="bi bi-gear<?= $tab==='ustawienia' ? '-fill' : '' ?>" style="font-size:1.3rem"></i>
      <span style="font-size:.65rem">Więcej</span>
    </a>
  </div>
</nav>

<?php include __DIR__ . '/_layout_foot.php'; ?>
