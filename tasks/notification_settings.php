<?php
/**
 * tasks/notification_settings.php — Preferencje powiadomień email (moduł Zadań)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/task_notify.php';

require_login();
$uid  = (int)(current_user()['id'] ?? 0);
$user = current_user();

$saved            = false;
$error            = '';
$email_msg        = '';     // komunikat o weryfikacji własnego adresu powiadomień
$test_result      = null;   // null | ['ok'=>bool, 'msg'=>string, 'channel'=>string]
$admin_test_result = null;  // null | ['ok'=>bool, 'msg'=>string]

// Adres docelowy: własny adres powiadomień (preferencje) albo adres z konta
$notify_to = task_notify_address($uid, (string)($user['email'] ?? ''));
$has_mail  = $notify_to !== '';
require_once dirname(__DIR__) . '/includes/sms.php';
// phone_number nie jest w sesji — ładuj zawsze z DB
$_db_phone = db_one("SELECT phone_number FROM users WHERE id=?", [$uid]);
$user['phone_number'] = $_db_phone['phone_number'] ?? null;
$has_sms  = sms_is_enabled() && !empty($user['phone_number']);

// Tryb fragmentu: zwraca tylko treść (bez header/footer), POST → JSON
$is_fragment = !empty($_GET['_fragment']);

// Dwa widoki: użytkownika (domyślny, też w oknie) i administracyjny (?view=admin — tylko pełna strona,
// lider obszaru albo administrator): odpowiedzi e-mailem, test dla innej osoby, status członków.
$_ns_leader = is_admin() || (int)(db_one("SELECT COUNT(*) AS n FROM task_workspace_members WHERE user_id = ? AND role IN ('admin','editor')", [$uid])['n'] ?? 0) > 0;
$ns_view = (!$is_fragment && ($_GET['view'] ?? '') === 'admin' && $_ns_leader) ? 'admin' : 'user';

// ── Zapis ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        $error = 'Nieprawidłowy token CSRF. Odśwież stronę i spróbuj ponownie.';
    } elseif (($_POST['_action'] ?? '') === 'save_phone') {
        // ── Zapis numeru telefonu (inline, bez przejścia do profilu) ─────
        $phone_raw = trim($_POST['phone_number'] ?? '');
        $phone_new = preg_replace('/[^\d+]/', '', $phone_raw);
        if ($phone_new !== '' && !preg_match('/^\+?[0-9]{7,15}$/', $phone_new)) {
            $error = 'Nieprawidłowy numer telefonu (min. 7 cyfr, tylko cyfry lub + na początku).';
        } else {
            db()->prepare("UPDATE users SET phone_number=? WHERE id=?")->execute([$phone_new ?: null, $uid]);
            $user['phone_number'] = $phone_new ?: null;
            $has_sms = sms_is_enabled() && !empty($user['phone_number']);
            $saved = true;
        }
        if (!$is_fragment && $saved) {
            flash_set('success', 'Numer telefonu zapisany.');
            header('Location: notification_settings.php'); exit;
        }
    } elseif (($_POST['_action'] ?? '') === 'save_mail_reply') {
        // ── Admin systemu: odpowiedzi e-mailem → komentarze (globalnie) ──────
        if (!is_admin()) {
            $error = 'Brak uprawnień.';
        } else {
            require_once dirname(__DIR__) . '/includes/m365.php';
            $on = !empty($_POST['tasks_mail_reply_enabled']);
            if ($on && m365_setting('tasks_mail_reply_enabled') !== '1') {
                // Świeży start: bez przetwarzania poczty sprzed włączenia, nowa delta
                m365_save_setting('tasks_mail_reply_since', date('Y-m-d H:i:s'));
                m365_save_setting('tasks_mail_reply_delta', '');
            }
            m365_save_setting('tasks_mail_reply_enabled', $on ? '1' : '0');
            $saved = true;
            $email_msg = $on ? 'Odpowiedzi e-mailem włączone.' : 'Odpowiedzi e-mailem wyłączone.';
        }
    } elseif (($_POST['_action'] ?? '') === 'admin_test_notif') {
        // ── Admin: test per user ──────────────────────────────────────────
        $_is_leader_check = (int)(db_one("SELECT COUNT(*) AS n FROM task_workspace_members WHERE user_id = ? AND role IN ('admin','editor')", [$uid])['n'] ?? 0) > 0;
        if (!is_admin() && !$_is_leader_check) {
            $admin_test_result = ['ok' => false, 'msg' => 'Brak uprawnień.'];
        } else {
            $tuid = (int)($_POST['target_uid'] ?? 0);
            $tch  = in_array($_POST['test_channel'] ?? '', ['email','sms','inapp'], true)
                    ? $_POST['test_channel'] : 'email';
            $tu   = $tuid ? (db_one("SELECT id,name,email,phone_number FROM users WHERE id = ?", [$tuid]) ?: []) : [];
            if (!$tu) {
                $admin_test_result = ['ok' => false, 'msg' => 'Nie znaleziono użytkownika.'];
            } elseif ($tch === 'sms') {
                if (!sms_is_enabled() || empty($tu['phone_number'])) {
                    $admin_test_result = ['ok' => false, 'msg' => 'SMS niedostępny lub brak numeru telefonu dla tego użytkownika.'];
                } else {
                    require_once dirname(__DIR__) . '/includes/sms.php';
                    // sms_send() zwraca dostawcę, który wysłał ('' = pominięto), a przy porażce rzuca wyjątek —
                    // dawniej zwracało void, więc test zawsze zgłaszał „Błąd wysyłki” (null) nawet po udanej wysyłce
                    $sms_err = '';
                    try { $ok = sms_send($tu['phone_number'], 'FEER Zadania [test]: powiadomienia SMS działają poprawnie.', 'tasks_notify') !== ''; }
                    catch (\Throwable $e) { $ok = false; $sms_err = sms_scrub($e->getMessage()); }
                    $admin_test_result = ['ok' => $ok, 'msg' => $ok
                        ? 'SMS testowy wysłany na ' . $tu['phone_number'] . ' (' . $tu['name'] . ')'
                        : 'Błąd wysyłki SMS — ' . ($sms_err !== '' ? $sms_err : 'sprawdź konfigurację bramki.')];
                }
            } elseif ($tch === 'inapp') {
                try {
                    notif_create((int)$tu['id'], 'task', 'Zadania — test', 'To jest testowe powiadomienie systemowe.', APP_URL . '/tasks/notifications.php');
                    $admin_test_result = ['ok' => true, 'msg' => 'Powiadomienie in-app wysłane dla ' . $tu['name'] . '.'];
                } catch (\Throwable $e) {
                    $admin_test_result = ['ok' => false, 'msg' => 'Błąd: ' . $e->getMessage()];
                }
            } else {
                $tu['email'] = task_notify_address((int)$tu['id'], (string)($tu['email'] ?? ''));
                if (empty($tu['email'])) {
                    $admin_test_result = ['ok' => false, 'msg' => 'Użytkownik ' . $tu['name'] . ' nie ma adresu e-mail.'];
                } else {
                    $html = _feer_email_tpl(
                        '<p>To jest wiadomość testowa wysłana przez administratora z modułu <strong>Zadania</strong>.</p>'
                        . '<p style="color:#64748b;font-size:13px">Jeśli ją widzisz — powiadomienia e-mail działają poprawnie.</p>',
                        'Test powiadomień — Zadania',
                        APP_URL . '/tasks/notifications.php',
                        'Przejdź do powiadomień →'
                    );
                    $via = ''; $err = '';
                    $ok = task_mail_send($tu['email'], 'Test powiadomień — Zadania', $html, 'task_test', (int)$tu['id'], 20, $via, $err, (int)$tu['id'], 'test');
                    $admin_test_result = ['ok' => $ok, 'msg' => $ok
                        ? 'E-mail testowy wysłany na ' . $tu['email'] . ' (' . $tu['name'] . ')'
                        : 'Nie udało się wysłać na ' . $tu['email'] . ' przez Microsoft Graph: ' . $err];
                }
            }
        }
    } elseif (($_POST['_action'] ?? '') === 'test_notif') {
        // ── Test wysyłki ──────────────────────────────────────────────────
        $ch = $_POST['test_channel'] ?? 'email';
        if ($ch === 'sms' && $has_sms) {
            require_once dirname(__DIR__) . '/includes/sms.php';
            $sms_err = '';
            try { $ok = sms_send($user['phone_number'], 'FEER Zadania: test powiadomień SMS działa poprawnie.', 'tasks_notify') !== ''; }
            catch (\Throwable $e) { $ok = false; $sms_err = sms_scrub($e->getMessage()); }
            $test_result = ['ok' => $ok, 'channel' => 'sms',
                'msg' => $ok ? 'SMS wysłany na ' . $user['phone_number'] : 'Błąd wysyłki SMS — ' . ($sms_err !== '' ? $sms_err : 'sprawdź konfigurację bramki.')];
        } elseif ($ch === 'inapp') {
            try {
                notif_create($uid, 'task', 'Zadania — test in-app', 'To jest testowe powiadomienie in-app.', APP_URL . '/tasks/notifications.php');
                $test_result = ['ok' => true, 'channel' => 'inapp', 'msg' => 'Powiadomienie in-app wysłane — sprawdź dzwonek w nagłówku.'];
            } catch (\Throwable $e) {
                $test_result = ['ok' => false, 'channel' => 'inapp', 'msg' => 'Błąd: ' . $e->getMessage()];
            }
        } else {
            if (!$has_mail) {
                $test_result = ['ok' => false, 'channel' => 'email', 'msg' => 'Brak adresu e-mail w profilu.'];
            } else {
                require_once dirname(__DIR__) . '/includes/functions.php';
                $html = _feer_email_tpl(
                    '<p>To jest wiadomość testowa z modułu <strong>Zadania</strong>.</p>'
                    . '<p style="color:#64748b;font-size:13px">Jeśli ją widzisz — powiadomienia e-mail działają poprawnie.</p>',
                    'Test powiadomień — Zadania',
                    APP_URL . '/tasks/notifications.php',
                    'Przejdź do powiadomień →'
                );
                $via = ''; $err = '';
                $ok = task_mail_send($notify_to, 'Test powiadomień — Zadania', $html, 'task_test', $uid, 20, $via, $err, (int)$uid, 'test');
                $test_result = ['ok' => $ok, 'channel' => 'email',
                    'msg' => $ok ? 'E-mail testowy wysłany na ' . $notify_to : 'Nie udało się wysłać przez Microsoft Graph: ' . $err];
            }
        }
    } else {
        try {
            task_notify_save_pref($uid, [
                'notify_file'      => isset($_POST['notify_file'])      ? 1 : 0,
                'notify_moved'     => isset($_POST['notify_moved'])     ? 1 : 0,
                'notify_watched'   => isset($_POST['notify_watched'])   ? 1 : 0,
                'notify_digest'    => (int)(($_POST['notify_digest'] ?? '0') === '1'),
                'notify_due_soon'  => isset($_POST['notify_due_soon'])  ? 1 : 0,
                'notify_assigned'  => isset($_POST['notify_assigned'])  ? 1 : 0,
                'notify_mentioned' => isset($_POST['notify_mentioned']) ? 1 : 0,
                'notify_comment'   => isset($_POST['notify_comment'])   ? 1 : 0,
                'notify_due_1day'  => isset($_POST['notify_due_1day'])  ? 1 : 0,
                'notify_due_today' => isset($_POST['notify_due_today']) ? 1 : 0,
                'notify_confirmed' => isset($_POST['notify_confirmed']) ? 1 : 0,
                'notify_rejected'  => isset($_POST['notify_rejected'])  ? 1 : 0,
                'notify_sms'       => isset($_POST['notify_sms'])       ? 1 : 0,
            ]);
            // Własny adres: aktywny dopiero po kliknięciu linku wysłanego na ten adres. Tylko gdy pole się zmieniło —
            // dawniej każdy zapis przełączników ponownie wysyłał link na oczekujący adres, a nieudana wysyłka
            // zgłaszała błąd całego zapisu, choć przełączniki były już zapisane.
            $email_warn = false;
            if (array_key_exists('notify_email', $_POST)) {
                $_cp = task_notify_get_pref($uid);   // $pref ładowany jest niżej, po obsłudze zapisu
                $cur_email = (string)(($_cp['notify_email_pending'] ?? '') !== '' ? $_cp['notify_email_pending'] : ($_cp['notify_email'] ?? ''));
                $new_email = trim((string)$_POST['notify_email']);
                if (strcasecmp($new_email, $cur_email) !== 0) {
                    $chg = task_notify_request_email_change($uid, $new_email, (string)($user['email'] ?? ''));
                    $email_msg = $chg['msg'];
                    $email_warn = in_array($chg['status'], ['error', 'throttled'], true);
                }
            }
            $saved = true;
        } catch (\Throwable $e) {
            $error = 'Błąd zapisu: ' . $e->getMessage();
        }
    }

    // ── Tryb fragmentu: wszystkie POST → JSON ─────────────────────────────
    if ($is_fragment) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if ($error) {
            echo json_encode(['ok' => false, 'msg' => $error], JSON_UNESCAPED_UNICODE);
        } elseif ($test_result !== null) {
            echo json_encode(['ok' => $test_result['ok'], 'msg' => $test_result['msg'],
                              'is_test' => true, 'channel' => $test_result['channel'] ?? ''], JSON_UNESCAPED_UNICODE);
        } elseif ($admin_test_result !== null) {
            echo json_encode(['ok' => $admin_test_result['ok'], 'msg' => $admin_test_result['msg'],
                              'is_admin_test' => true], JSON_UNESCAPED_UNICODE);
        } elseif ($saved) {
            $is_phone_save = ($_POST['_action'] ?? '') === 'save_phone';
            echo json_encode(['ok' => true,
                'msg'    => $is_phone_save ? 'Numer telefonu zapisany.' : trim('Zapisano ' . date('H:i') . '. ' . $email_msg),
                'warn'   => !empty($email_warn),
                'reload' => $is_phone_save,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Błąd.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

$pref = task_notify_get_pref($uid);
$csrf = csrf_token();
$notify_to = task_notify_address($uid, (string)($user['email'] ?? ''));
$has_mail  = $notify_to !== '';

if (!$is_fragment) {
    $page_title = 'Powiadomienia — Zadania';
    require_once dirname(__DIR__) . '/includes/header.php';
}
?>

<style>
/* ── layout ──────────────────────────────────────────────────── */
.ns-wrap     { max-width: 760px; }
.ns-header   { background: linear-gradient(135deg,#1e40af,#2563eb); border-radius:12px; padding:1.5rem; color:#fff; margin-bottom:1.75rem; }
.ns-email-chip { display:inline-flex; align-items:center; gap:.45rem; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25); border-radius:20px; padding:.25rem .75rem; font-size:.82rem; margin-top:.5rem; }

/* ── section card ────────────────────────────────────────────── */
.ns-card        { border:1px solid #e2e8f0; border-radius:10px; background:#fff; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.ns-card-header { padding:.75rem 1.25rem; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; gap:.6rem; font-weight:700; font-size:.84rem; color:#1e293b; }
.ns-card-header .sec-badge { font-size:.7rem; font-weight:600; padding:.15rem .55rem; border-radius:10px; margin-left:auto; }

/* ── row ─────────────────────────────────────────────────────── */
.ns-row         { display:flex; align-items:center; gap:1rem; padding:.9rem 1.25rem; border-bottom:1px solid #f1f5f9; transition:background .1s; }
.ns-row:last-child { border-bottom:none; }
.ns-row:hover   { background:#fafbff; }
.ns-icon        { width:38px; height:38px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
.ns-label       { flex:1; min-width:0; }
.ns-label-title { font-weight:600; font-size:.9rem; color:#0f172a; }
.ns-label-desc  { font-size:.77rem; color:#64748b; margin-top:.1rem; }
.ns-meta        { display:flex; align-items:center; gap:.4rem; flex-shrink:0; }
.ns-timing      { font-size:.71rem; color:#94a3b8; background:#f1f5f9; padding:.15rem .5rem; border-radius:10px; white-space:nowrap; }
.ns-channel     { font-size:.71rem; color:#2563eb; background:#eff6ff; padding:.15rem .5rem; border-radius:10px; display:flex; align-items:center; gap:.25rem; }
.ns-switch      { flex-shrink:0; }

/* ── toggle override ─────────────────────────────────────────── */
.form-check-input[type=checkbox] { width:2.5em; height:1.35em; cursor:pointer; }
.form-check-input:checked { background-color:#2563eb; border-color:#2563eb; }
.form-check-input:focus   { box-shadow:0 0 0 3px rgba(37,99,235,.15); }

/* ── presets ─────────────────────────────────────────────────── */
.ns-presets { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.25rem; }
.ns-preset  { font-size:.79rem; padding:.3rem .8rem; border:1px solid #e2e8f0; border-radius:20px; background:#fff; cursor:pointer; color:#475569; transition:all .12s; }
.ns-preset:hover { border-color:#2563eb; color:#2563eb; background:#eff6ff; }

/* ── info box ────────────────────────────────────────────────── */
.ns-info { background:#f0f7ff; border:1px solid #bfdbfe; border-radius:8px; padding:.9rem 1.1rem; font-size:.8rem; color:#1e40af; }
.ns-info-row { display:flex; align-items:flex-start; gap:.5rem; margin-bottom:.4rem; }
.ns-info-row:last-child { margin-bottom:0; }

/* ── footer bar ──────────────────────────────────────────────── */
.ns-footer { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:1rem 1.25rem; background:#f8fafc; border-top:1px solid #e2e8f0; }

/* ══ Prostsza szata (Metro): płaskie sekcje, bez kolorowych kafelków ikon i plakietek kanałów,
   zielone przełączniki modułu, przycisk „Zapisz” przyklejony do dołu okna ══ */
.ns-wrap{max-width:720px}
.ns-header{background:#1d1d1d;border-radius:0}
.ns-card{border:0;border-radius:0;box-shadow:none;background:#fff;border-top:2px solid #111827;overflow:visible}
.ns-card-header{background:#fff;border-bottom:1px solid #e1e1e1;padding:.75rem 0;font-size:.95rem;font-weight:600;color:#111827}
.ns-card-header>i,.ns-card-header .ns-meta,.ns-card-header .sec-badge{display:none}
.ns-row{padding:.65rem 0;gap:.75rem;border-bottom-color:#f0f0f0}
.ns-row:hover{background:transparent}
.ns-row .ns-icon{display:none}
.ns-label-title{font-weight:500;font-size:.9rem}
.ns-label-desc{font-size:.75rem;color:#5f5f5f}
.form-check-input:checked{background-color:#007a00;border-color:#007a00}
.form-check-input:focus{box-shadow:0 0 0 3px rgba(0,122,0,.2)}
.ns-presets{gap:4px}
.ns-preset{border-radius:0;border:2px solid #111827;background:#fff;color:#111827;font-weight:600}
.ns-preset:hover,.ns-preset:focus-visible{background:#111827;color:#fff;border-color:#111827}
.ns-footer{position:sticky;bottom:0;z-index:2;background:#fff;padding:.8rem 0;border-top:1px solid #e1e1e1}
.ns-footer .btn-primary{background:#007a00;border:0;border-radius:0;font-weight:600}
.ns-footer .btn-primary:hover,.ns-footer .btn-primary:focus-visible{background:#1d1d1d}
.ns-more>summary{cursor:pointer;list-style:none;border-bottom:0}
.ns-more>summary::-webkit-details-marker{display:none}
.ns-more>summary::after{content:"\25BE";margin-left:auto;color:#5f5f5f}
.ns-more[open]>summary::after{content:"\25B4"}
.ns-info{background:#f2f2f2;border:0;border-radius:0;color:#3f3f46}
.ns-admin-link{display:flex;align-items:center;gap:.75rem;padding:.8rem 1rem;margin:0 0 1rem;background:#f2f2f2;color:#111827;text-decoration:none}
.ns-admin-link:hover,.ns-admin-link:focus-visible{background:#1d1d1d;color:#fff}
.ns-admin-link small{color:inherit;opacity:.75}
</style>

<div class="ns-wrap <?= $is_fragment ? 'p-3' : 'py-4' ?> mx-auto">

  <!-- Header karty (ukryty w modalu — modal ma własny nagłówek) ── -->
  <?php if (!$is_fragment): ?>
  <div class="ns-header">
    <div class="d-flex align-items-center gap-3">
      <div style="width:50px;height:50px;border-radius:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0">
        <i class="bi bi-bell-fill"></i>
      </div>
      <div style="flex:1">
        <div style="font-size:1.2rem;font-weight:700"><?= $ns_view === 'admin' ? 'Powiadomienia — administracja' : 'Powiadomienia e-mail' ?></div>
        <div style="font-size:.82rem;opacity:.85;margin-top:.15rem"><?= $ns_view === 'admin'
            ? 'Status powiadomień członków obszarów, testy wysyłki i odpowiedzi e-mailem'
            : 'Zadania i terminy — kontroluj co i kiedy trafia na Twoją skrzynkę' ?></div>
        <?php if ($has_mail): ?>
        <div class="ns-email-chip">
          <i class="bi bi-envelope-fill"></i>
          <?= h($notify_to) ?>
          <span style="opacity:.7">· <?= ($pref['notify_email'] ?? '') !== '' && !empty($pref['notify_email_verified_at']) ? 'własny adres powiadomień' : 'adres z konta' ?></span>
        </div>
        <?php else: ?>
        <div class="ns-email-chip" style="background:rgba(239,68,68,.2);border-color:rgba(239,68,68,.4)">
          <i class="bi bi-exclamation-triangle-fill"></i>
          Brak adresu e-mail — powiadomienia nie będą wysyłane
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; /* !$is_fragment — koniec ns-header */ ?>

  <?php if ($saved && !$is_fragment): ?>
  <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
    <i class="bi bi-check-circle-fill"></i>
    <span>Ustawienia zapisane pomyślnie.<?= $email_msg !== '' ? ' ' . h($email_msg) : '' ?></span>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php elseif ($error && !$is_fragment): ?>
  <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" role="alert">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?= h($error) ?>
  </div>
  <?php endif; ?>

  <?php if ($ns_view === 'admin'): ?>
  <p class="mb-3"><a href="<?= APP_URL ?>/tasks/notification_settings.php"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Moje ustawienia powiadomień</a></p>
  <?php else: /* ── widok użytkownika ── */ ?>
  <?php require __DIR__ . '/includes/notif_settings_user.php'; ?>
  <?php endif; /* koniec widoku użytkownika */ ?>

  <?php if ($ns_view === 'admin'): ?>

  <?php
  $is_leader_or_admin = is_admin() || (int)(db_one("SELECT COUNT(*) AS n FROM task_workspace_members WHERE user_id = ? AND role IN ('admin','editor')", [$uid])['n'] ?? 0) > 0;
  if ($is_leader_or_admin):
    $member_users = db_all("
        SELECT DISTINCT u.id, u.name, u.email, u.phone_number
        FROM users u
        JOIN task_workspace_members twm ON twm.user_id = u.id
        ORDER BY u.name");
  ?>
  <?php if (is_admin()):
    require_once dirname(__DIR__) . '/includes/m365.php';
    $mr_on     = m365_setting('tasks_mail_reply_enabled') === '1';
    $mr_sender = m365_setting('m365_sender_user_id');
    $mr_m365   = m365_setting('m365_enabled') === '1' && $mr_sender !== '';
  ?>
  <!-- ══ Admin: odpowiedzi e-mailem → komentarze ═════════════════════ -->
  <div class="ns-card mb-3 mt-4">
    <div class="ns-card-header">
      <i class="bi bi-reply-fill" style="color:#2563eb"></i>
      Odpowiedź e-mailem jako komentarz
      <span class="sec-badge ms-auto" style="background:#fee2e2;color:#b91c1c">Admin systemu</span>
    </div>
    <form method="post" class="p-3">
      <input type="hidden" name="_csrf"   value="<?= h($csrf) ?>">
      <input type="hidden" name="_action" value="save_mail_reply">
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" role="switch" id="tasks_mail_reply_enabled"
               name="tasks_mail_reply_enabled" value="1" <?= $mr_on ? 'checked' : '' ?> <?= $mr_m365 ? '' : 'disabled' ?>>
        <label class="form-check-label" for="tasks_mail_reply_enabled">
          Odpowiedź na powiadomienie o zadaniu dodaje komentarz
        </label>
      </div>
      <p class="small text-muted mb-2">
        Temat powiadomień dostaje znacznik <code>[ZAD-123-…]</code>. Odpowiedzi trafiają do skrzynki nadawcy
        <strong><?= h($mr_sender ?: '— nieustawiony —') ?></strong>, a cron co 5 min zamienia je w komentarze —
        tylko gdy nadawca to adresat powiadomienia (e-mail z konta lub potwierdzony adres powiadomień)
        i nadal może komentować. Wymaga uprawnienia Graph <strong>Mail.Read (Application)</strong> dla tej skrzynki.
      </p>
      <?php if (!$mr_m365): ?>
      <p class="small text-warning-emphasis mb-2">Niedostępne: wysyłka przez Microsoft 365 nie jest skonfigurowana.</p>
      <?php endif; ?>
      <button type="submit" class="btn btn-sm btn-primary" <?= $mr_m365 ? '' : 'disabled' ?>>Zapisz</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- ══ Admin: Test per user ════════════════════════════════════ -->
  <div class="ns-card mb-3 mt-4">
    <div class="ns-card-header">
      <i class="bi bi-person-lines-fill" style="color:#0891b2"></i>
      Testuj powiadomienia — wybrany użytkownik
      <span class="sec-badge ms-auto" style="background:#e0f2fe;color:#0369a1">Admin / Lider</span>
    </div>

    <?php if ($admin_test_result !== null): ?>
    <div class="px-3 pt-3">
      <div class="alert alert-<?= $admin_test_result['ok'] ? 'success' : 'warning' ?> d-flex align-items-center gap-2 py-2 mb-0" role="status">
        <i class="bi <?= $admin_test_result['ok'] ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
        <?= h($admin_test_result['msg']) ?>
      </div>
    </div>
    <?php endif; ?>

    <form method="post" class="p-3">
      <input type="hidden" name="_csrf"   value="<?= h($csrf) ?>">
      <input type="hidden" name="_action" value="admin_test_notif">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-sm-5">
          <label class="form-label" style="font-size:.8rem;font-weight:600;color:#374151" for="admin_target_uid">
            <i class="bi bi-person me-1"></i>Użytkownik
          </label>
          <select id="admin_target_uid" name="target_uid" class="form-select form-select-sm" required>
            <option value="">— wybierz —</option>
            <?php foreach ($member_users as $mu): ?>
            <option value="<?= (int)$mu['id'] ?>"
                    data-email="<?= h($mu['email']) ?>"
                    data-phone="<?= h($mu['phone_number'] ?? '') ?>">
              <?= h($mu['name']) ?><?= $mu['email'] ? ' ·  ' . h($mu['email']) : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-sm-4">
          <label class="form-label" style="font-size:.8rem;font-weight:600;color:#374151" for="admin_test_channel">
            <i class="bi bi-send me-1"></i>Kanał
          </label>
          <select id="admin_test_channel" name="test_channel" class="form-select form-select-sm">
            <option value="email">E-mail</option>
            <?php if (sms_is_enabled()): ?><option value="sms">SMS</option><?php endif; ?>
            <option value="inapp">In-app (dzwonek)</option>
          </select>
        </div>
        <div class="col-12 col-sm-3">
          <button type="submit" class="btn btn-sm w-100"
                  style="background:#0891b2;color:#fff;border:none;font-size:.8rem">
            <i class="bi bi-send-fill me-1"></i>Wyślij test
          </button>
        </div>
      </div>
      <div id="admin-test-hint" class="mt-2" style="font-size:.73rem;color:#94a3b8"></div>
    </form>
  </div>
  <?php endif; ?>

  <?php
  /* ══ Status powiadomień — wszyscy członkowie obszarów ═══════════════════ */
  if ($is_leader_or_admin):
      $members_status = [];
      try {
          $members_status = db_all("
              SELECT DISTINCT u.id, u.name, u.email, u.phone_number,
                     tnp.user_id AS configured_uid,
                     COALESCE(tnp.notify_sms, 0) AS notify_sms,
                     tnp.updated_at
              FROM users u
              JOIN task_workspace_members twm ON twm.user_id = u.id
              LEFT JOIN task_notification_prefs tnp ON tnp.user_id = u.id
              WHERE u.is_active = 1
              ORDER BY (tnp.user_id IS NULL) DESC, u.name
          ");
      } catch (\Throwable $e) {}
      $unconfigured_count = count(array_filter($members_status, fn($m) => !$m['configured_uid']));
  endif;
  ?>

  <?php if ($is_leader_or_admin && $members_status): ?>
  <!-- ══ Admin: Status powiadomień ══════════════════════════════════════ -->
  <div class="ns-card mb-3 mt-4" id="ns-member-status">
    <div class="ns-card-header">
      <i class="bi bi-people-fill" style="color:#0891b2"></i>
      Status powiadomień — członkowie obszarów
      <?php if ($unconfigured_count > 0): ?>
      <span class="sec-badge ms-auto" style="background:#fef9c3;color:#92400e">
        <?= $unconfigured_count ?> bez konfiguracji
      </span>
      <?php else: ?>
      <span class="sec-badge ms-auto" style="background:#dcfce7;color:#16a34a">
        Wszyscy skonfigurowani
      </span>
      <?php endif; ?>
    </div>

    <!-- Toolbar -->
    <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom" style="background:#f8fafc;flex-wrap:wrap">
      <div class="form-check form-switch mb-0" style="font-size:.82rem">
        <input class="form-check-input" type="checkbox" id="ns-only-uncfg" role="switch">
        <label class="form-check-label" for="ns-only-uncfg">Tylko niekonfigurowane</label>
      </div>
      <div class="ms-auto">
        <?php if ($unconfigured_count > 0): ?>
        <button type="button" id="ns-bulk-reminder-btn"
                class="btn btn-sm" style="background:#0891b2;color:#fff;border:none;font-size:.8rem"
                data-csrf="<?= h($csrf) ?>">
          <i class="bi bi-envelope-fill me-1"></i>Wyślij do wszystkich bez konfiguracji
          (<?= $unconfigured_count ?>)
        </button>
        <?php else: ?>
        <span style="font-size:.79rem;color:#94a3b8">Wszyscy mają własną konfigurację</span>
        <?php endif; ?>
      </div>
    </div>
    <div id="ns-bulk-result" style="display:none" class="px-3 pt-2"></div>

    <!-- Tabela -->
    <div style="overflow-x:auto">
    <table class="table table-sm mb-0" style="font-size:.82rem">
      <thead style="background:#f8fafc">
        <tr>
          <th class="px-3 py-2">Imię i nazwisko</th>
          <th class="py-2">E-mail</th>
          <th class="py-2 text-center">Status</th>
          <th class="py-2 text-center" title="Ma adres e-mail">
            <i class="bi bi-envelope"></i>
          </th>
          <th class="py-2 text-center" title="SMS włączony">
            <i class="bi bi-phone"></i>
          </th>
          <th class="py-2" style="white-space:nowrap">Ostatnia zmiana</th>
          <th class="py-2"></th>
        </tr>
      </thead>
      <tbody id="ns-member-tbody">
        <?php foreach ($members_status as $m):
            $is_cfg   = (bool)$m['configured_uid'];
            $has_mail = !empty($m['email']);
            $has_tel  = !empty($m['phone_number']);
        ?>
        <tr data-cfg="<?= $is_cfg ? '1' : '0' ?>" class="ns-member-row">
          <td class="px-3 py-2 fw-semibold"><?= h($m['name']) ?></td>
          <td class="py-2" style="color:#475569"><?= h($m['email'] ?: '—') ?></td>
          <td class="py-2 text-center">
            <?php if ($is_cfg): ?>
            <span class="badge" style="background:#dcfce7;color:#15803d;font-size:.7rem">Skonfigurowany</span>
            <?php else: ?>
            <span class="badge" style="background:#fef9c3;color:#92400e;font-size:.7rem">Domyślne</span>
            <?php endif; ?>
          </td>
          <td class="py-2 text-center">
            <?php if ($has_mail): ?>
            <i class="bi bi-check-circle-fill" style="color:#16a34a" title="Ma adres e-mail"></i>
            <?php else: ?>
            <i class="bi bi-x-circle-fill" style="color:#dc2626" title="Brak adresu e-mail"></i>
            <?php endif; ?>
          </td>
          <td class="py-2 text-center">
            <?php if ($m['notify_sms'] && $has_tel): ?>
            <i class="bi bi-check-circle-fill" style="color:#16a34a" title="SMS włączony"></i>
            <?php elseif (!$has_tel): ?>
            <i class="bi bi-dash-circle" style="color:#94a3b8" title="Brak numeru telefonu"></i>
            <?php else: ?>
            <i class="bi bi-x-circle" style="color:#cbd5e1" title="SMS wyłączony"></i>
            <?php endif; ?>
          </td>
          <td class="py-2" style="color:#94a3b8;white-space:nowrap">
            <?= $m['updated_at'] ? h(substr($m['updated_at'], 0, 16)) : '—' ?>
          </td>
          <td class="py-2">
            <?php if (!$is_cfg && $has_mail): ?>
            <button type="button" class="btn btn-sm ns-send-reminder-btn"
                    style="font-size:.73rem;background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd"
                    data-uid="<?= (int)$m['id'] ?>"
                    data-csrf="<?= h($csrf) ?>">
              <i class="bi bi-envelope-fill me-1"></i>Wyślij przypomnienie
            </button>
            <?php elseif (!$has_mail): ?>
            <span style="font-size:.73rem;color:#94a3b8">Brak e-mail</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Informacja ───────────────────────────────────────────────── -->
  <?php endif; /* koniec widoku administracyjnego */ ?>



</div>

<script>
var PRESETS = {
  all:       ['notify_assigned','notify_mentioned','notify_comment','notify_file','notify_moved','notify_watched','notify_confirmed','notify_rejected','notify_due_1day','notify_due_today','notify_due_soon'],
  important: ['notify_assigned','notify_mentioned','notify_file','notify_watched','notify_confirmed','notify_rejected','notify_due_1day','notify_due_today','notify_due_soon'],
  deadlines: ['notify_due_1day','notify_due_today','notify_due_soon'],
  none:      []
};
function setPreset(name) {
  var on = PRESETS[name] || [];
  ['notify_assigned','notify_mentioned','notify_comment','notify_file','notify_moved','notify_watched','notify_confirmed','notify_rejected','notify_due_1day','notify_due_today','notify_due_soon'].forEach(function(k) {
    var el = document.getElementById(k);
    if (el) el.checked = on.indexOf(k) >= 0;
  });
}
// Admin user picker hint
(function(){
  var sel = document.getElementById('admin_target_uid');
  var hint = document.getElementById('admin-test-hint');
  if (!sel || !hint) return;
  sel.addEventListener('change', function(){
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) { hint.textContent = ''; return; }
    var parts = [];
    if (opt.dataset.email) parts.push('E-mail: ' + opt.dataset.email);
    if (opt.dataset.phone) parts.push('Tel: ' + opt.dataset.phone);
    hint.textContent = parts.length ? parts.join('  ·  ') : 'Brak danych kontaktowych';
  });
})();

// ── Status powiadomień — przypomnienia ───────────────────────────────────
(function () {
  var API = '<?= APP_URL ?>/tasks/api/send_notif_reminder.php';

  /* Filtr: tylko niekonfigurowane */
  var checkbox = document.getElementById('ns-only-uncfg');
  if (checkbox) {
    checkbox.addEventListener('change', function () {
      document.querySelectorAll('.ns-member-row').forEach(function (row) {
        var cfg = row.dataset.cfg === '1';
        row.style.display = (checkbox.checked && cfg) ? 'none' : '';
      });
    });
  }

  /* Przypomnienie dla pojedynczego użytkownika */
  document.querySelectorAll('.ns-send-reminder-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var uid  = parseInt(btn.dataset.uid, 10);
      var csrf = btn.dataset.csrf;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:.85em;height:.85em"></span>Wysyłanie…';
      fetch(API, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_csrf: csrf, action: 'send', uid: uid})
      })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) {
          btn.innerHTML = '<i class="bi bi-check-circle-fill me-1" style="color:#16a34a"></i>Wysłano';
          btn.style.cssText = 'font-size:.73rem;background:#f0fdf4;color:#15803d;border:1px solid #86efac';
        } else {
          btn.disabled = false;
          btn.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1" style="color:#dc2626"></i>'
                        + (d.msg || 'Błąd');
          btn.style.cssText = 'font-size:.73rem;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5';
        }
        btn.title = d.msg || '';
      })
      .catch(function () {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-envelope-fill me-1"></i>Wyślij przypomnienie';
        btn.style.cssText = 'font-size:.73rem;background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd';
      });
    });
  });

  /* Bulk reminder */
  var bulkBtn    = document.getElementById('ns-bulk-reminder-btn');
  var bulkResult = document.getElementById('ns-bulk-result');
  if (!bulkBtn) return;
  bulkBtn.addEventListener('click', function () {
    if (!confirm('Wyślać e-mail z przypomnieniem do wszystkich użytkowników bez własnej konfiguracji?')) return;
    bulkBtn.disabled = true;
    bulkBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:.85em;height:.85em"></span>Wysyłanie…';
    fetch(API, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({_csrf: bulkBtn.dataset.csrf, action: 'bulk'})
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      bulkBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Gotowe';
      if (bulkResult) {
        bulkResult.style.display = '';
        bulkResult.innerHTML = '<div class="alert alert-' + (d.ok ? 'success' : 'warning')
          + ' d-flex align-items-center gap-2 py-2 mb-2" style="font-size:.8rem">'
          + '<i class="bi bi-' + (d.ok ? 'check-circle-fill' : 'exclamation-triangle-fill') + '"></i>'
          + '<span>' + (d.msg || '') + '</span></div>';
      }
    })
    .catch(function () {
      bulkBtn.disabled = false;
      bulkBtn.innerHTML = '<i class="bi bi-envelope-fill me-1"></i>Wyślij do wszystkich bez konfiguracji';
    });
  });
})();

/* ── Inline zmiana numeru telefonu ─────────────────────────────────────── */
(function () {
  var changeBtn  = document.getElementById('ns-phone-change-btn');
  var changeForm = document.getElementById('ns-phone-change-form');
  if (changeBtn && changeForm) {
    changeBtn.addEventListener('click', function () {
      changeForm.style.display = changeForm.style.display === 'none' ? '' : 'none';
      if (changeForm.style.display !== 'none') {
        var inp = changeForm.querySelector('input[type=tel]');
        if (inp) inp.focus();
      }
    });
  }
})();
</script>

<?php if (!$is_fragment): ?>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
<?php else: ?>
<?php exit; ?>
<?php endif; ?>
