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
                    $ok = sms_send($tu['phone_number'], 'FEER Zadania [test]: powiadomienia SMS działają poprawnie.');
                    $admin_test_result = ['ok' => $ok, 'msg' => $ok
                        ? 'SMS testowy wysłany na ' . $tu['phone_number'] . ' (' . $tu['name'] . ')'
                        : 'Błąd wysyłki SMS — sprawdź konfigurację bramki.'];
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
                    $ok = (bool) approval_send_email($tu['email'], 'Test powiadomień — Zadania', $html, 'task_test', (int)$tu['id']);
                    $admin_test_result = ['ok' => $ok, 'msg' => $ok
                        ? 'E-mail testowy wysłany na ' . $tu['email'] . ' (' . $tu['name'] . ')'
                        : 'Nie udało się wysłać na ' . $tu['email'] . ' — sprawdź logi serwera.'];
                }
            }
        }
    } elseif (($_POST['_action'] ?? '') === 'test_notif') {
        // ── Test wysyłki ──────────────────────────────────────────────────
        $ch = $_POST['test_channel'] ?? 'email';
        if ($ch === 'sms' && $has_sms) {
            require_once dirname(__DIR__) . '/includes/sms.php';
            $ok  = sms_send($user['phone_number'], 'FEER Zadania: test powiadomień SMS działa poprawnie.');
            $test_result = ['ok' => $ok, 'channel' => 'sms',
                'msg' => $ok ? 'SMS wysłany na ' . $user['phone_number'] : 'Błąd wysyłki SMS — sprawdź konfigurację bramki.'];
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
                $ok = (bool) approval_send_email($notify_to, 'Test powiadomień — Zadania', $html, 'task_test', $uid);
                $test_result = ['ok' => $ok, 'channel' => 'email',
                    'msg' => $ok ? 'E-mail testowy wysłany na ' . $notify_to : 'Nie udało się wysłać — sprawdź konfigurację M365/SMTP lub logi serwera.'];
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
            // Własny adres: aktywny dopiero po kliknięciu linku wysłanego na ten adres
            if (array_key_exists('notify_email', $_POST)) {
                $chg = task_notify_request_email_change($uid, (string)$_POST['notify_email'], (string)($user['email'] ?? ''));
                if ($chg['status'] === 'error') $error = $chg['msg'];
                else $email_msg = $chg['msg'];
            }
            $saved = $error === '';
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
                'msg'    => $is_phone_save ? 'Numer telefonu zapisany.' : trim('Ustawienia zapisane pomyślnie. ' . $email_msg),
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
        <div style="font-size:1.2rem;font-weight:700">Powiadomienia e-mail</div>
        <div style="font-size:.82rem;opacity:.85;margin-top:.15rem">Zadania i terminy — kontroluj co i kiedy trafia na Twoją skrzynkę</div>
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

  <form method="post" id="notifForm">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <!-- Szybkie presety ─────────────────────────────────────────── -->
    <div class="d-flex align-items-center gap-2 mb-1" style="font-size:.78rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.06em">
      <i class="bi bi-lightning-fill" style="color:#f59e0b"></i> Szybkie ustawienia
    </div>
    <div class="ns-presets">
      <button type="button" class="ns-preset" onclick="setPreset('all')">
        <i class="bi bi-bell me-1"></i>Wszystkie
      </button>
      <button type="button" class="ns-preset" onclick="setPreset('important')">
        <i class="bi bi-star me-1"></i>Tylko ważne
      </button>
      <button type="button" class="ns-preset" onclick="setPreset('deadlines')">
        <i class="bi bi-clock me-1"></i>Tylko terminy
      </button>
      <button type="button" class="ns-preset" onclick="setPreset('none')">
        <i class="bi bi-bell-slash me-1"></i>Wyłącz wszystkie
      </button>
    </div>

    <!-- ══ Sekcja: Adres powiadomień ══════════════════════════════════ -->
    <div class="ns-card mb-3">
      <div class="ns-card-header">
        <i class="bi bi-envelope-at-fill" style="color:#2563eb"></i>
        Adres do powiadomień
      </div>
      <div class="ns-row" style="flex-wrap:wrap">
        <div class="ns-label" style="min-width:14rem">
          <label for="notify_email" class="ns-label-title">Inny adres e-mail (opcjonalnie)</label>
          <div class="ns-label-desc" id="notify_email_desc">
            Zostaw puste, aby powiadomienia trafiały na adres z konta:
            <strong><?= h($user['email'] ?: '— brak —') ?></strong>.
          </div>
        </div>
        <input type="email" class="form-control form-control-sm" style="max-width:20rem"
               id="notify_email" name="notify_email" maxlength="254" autocomplete="email"
               value="<?= h(($pref['notify_email_pending'] ?? '') !== '' ? $pref['notify_email_pending'] : ($pref['notify_email'] ?? '')) ?>"
               placeholder="<?= h($user['email'] ?: 'np. imie@example.org') ?>"
               aria-describedby="notify_email_desc notify_email_state">
        <div id="notify_email_state" class="w-100 small" style="flex-basis:100%">
          <?php if (($pref['notify_email_pending'] ?? '') !== ''): ?>
          <span class="text-warning-emphasis">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            Oczekuje na potwierdzenie: <strong><?= h($pref['notify_email_pending']) ?></strong> — kliknij link w wiadomości
            wysłanej na ten adres. Do tego czasu powiadomienia idą na <strong><?= h($notify_to ?: '—') ?></strong>.
            Zapisz ponownie, aby wysłać link jeszcze raz.
          </span>
          <?php elseif (($pref['notify_email'] ?? '') !== '' && !empty($pref['notify_email_verified_at'])): ?>
          <span class="text-success">
            <i class="bi bi-patch-check-fill" aria-hidden="true"></i>
            Adres <strong><?= h($pref['notify_email']) ?></strong> potwierdzony.
          </span>
          <?php else: ?>
          <span class="text-muted">Nowy adres trzeba będzie potwierdzić linkiem wysłanym na tę skrzynkę.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══ Sekcja: Tryb wysyłki ═════════════════════════════════════ -->
    <fieldset class="ns-card mb-3">
      <legend class="ns-card-header w-100 mb-0" style="float:none">
        <i class="bi bi-inboxes-fill" style="color:#2563eb"></i>
        Jak wysyłać powiadomienia o aktywności
      </legend>
      <?php $digest_on = !empty($pref['notify_digest']); ?>
      <div class="ns-row">
        <div class="form-check mb-0">
          <input class="form-check-input" type="radio" name="notify_digest" id="notify_digest_0" value="0" style="width:1.1em;height:1.1em" <?= $digest_on ? '' : 'checked' ?>>
          <label class="form-check-label" for="notify_digest_0">
            <span class="ns-label-title">Od razu</span>
            <span class="ns-label-desc d-block">Osobny e-mail przy każdym zdarzeniu.</span>
          </label>
        </div>
      </div>
      <div class="ns-row">
        <div class="form-check mb-0">
          <input class="form-check-input" type="radio" name="notify_digest" id="notify_digest_1" value="1" style="width:1.1em;height:1.1em" <?= $digest_on ? 'checked' : '' ?>>
          <label class="form-check-label" for="notify_digest_1">
            <span class="ns-label-title">Podsumowanie dzienne</span>
            <span class="ns-label-desc d-block">Jeden e-mail po południu (16–18) ze wszystkimi zdarzeniami z dnia. Przypomnienia o terminach nadal przychodzą rano.</span>
          </label>
        </div>
      </div>
    </fieldset>

    <!-- ══ Sekcja: Aktywność ═══════════════════════════════════════ -->
    <div class="ns-card mb-3">
      <div class="ns-card-header">
        <i class="bi bi-activity" style="color:#2563eb"></i>
        Aktywność
        <span class="ns-meta ms-auto" style="font-weight:400">
          <span class="ns-channel"><i class="bi bi-envelope-fill"></i> E-mail</span>
          <span class="ns-timing">natychmiast</span>
        </span>
      </div>

      <?php
      $activity = [
          [
              'key'   => 'notify_assigned',
              'icon'  => 'bi-person-plus-fill',
              'color' => '#dbeafe',
              'ic'    => '#2563eb',
              'title' => 'Przypisanie do zadania',
              'desc'  => 'Gdy ktoś przypisze Cię do nowego zadania.',
              'timing'=> 'od razu',
          ],
          [
              'key'   => 'notify_mentioned',
              'icon'  => 'bi-at',
              'color' => '#e0f2fe',
              'ic'    => '#0284c7',
              'title' => 'Wzmianka @',
              'desc'  => 'Gdy ktoś użyje Twojego @NazwyUżytkownika w komentarzu.',
              'timing'=> 'od razu',
          ],
          [
              'key'   => 'notify_comment',
              'icon'  => 'bi-chat-left-text-fill',
              'color' => '#f1f5f9',
              'ic'    => '#64748b',
              'title' => 'Nowy komentarz',
              'desc'  => 'Każdy komentarz do zadań, do których jesteś przypisany/a.',
              'timing'=> 'od razu',
          ],
          [
              'key'   => 'notify_file',
              'icon'  => 'bi-paperclip',
              'color' => '#ecfeff',
              'ic'    => '#0891b2',
              'title' => 'Nowy plik w zadaniu',
              'desc'  => 'Gdy ktoś doda plik do zadania, do którego jesteś przypisany/a (max 1 e-mail na zadanie dziennie).',
              'timing'=> 'od razu',
          ],
          [
              'key'   => 'notify_moved',
              'icon'  => 'bi-kanban-fill',
              'color' => '#f0fdf4',
              'ic'    => '#16a34a',
              'title' => 'Zmiana statusu (kolumny)',
              'desc'  => 'Gdy ktoś przeniesie Twoje zadanie do innej kolumny, np. „Do weryfikacji” (max 1 e-mail na zadanie dziennie).',
              'timing'=> 'od razu',
          ],
          [
              'key'   => 'notify_watched',
              'icon'  => 'bi-eye-fill',
              'color' => '#f5f3ff',
              'ic'    => '#6d28d9',
              'title' => 'Obserwowane zadania',
              'desc'  => 'Komentarze, nowe pliki i zmiana statusu w zadaniach, które obserwujesz (bez przypisania).',
              'timing'=> 'od razu',
          ],
          [
              'key'   => 'notify_confirmed',
              'icon'  => 'bi-patch-check-fill',
              'color' => '#ede9fe',
              'ic'    => '#7c3aed',
              'title' => 'Potwierdzenie wykonania',
              'desc'  => 'Gdy lider potwierdzi wykonanie zadania, które realizujesz.',
              'timing'=> 'od razu',
          ],
          [
              'key'   => 'notify_rejected',
              'icon'  => 'bi-x-octagon-fill',
              'color' => '#fee2e2',
              'ic'    => '#dc2626',
              'title' => 'Odrzucenie wykonania',
              'desc'  => 'Gdy lider odrzuci wykonanie zadania i poda powód.',
              'timing'=> 'od razu',
          ],
      ];
      foreach ($activity as $opt):
          $on = (bool)($pref[$opt['key']] ?? 0);
      ?>
      <div class="ns-row">
        <div class="ns-icon" style="background:<?= $opt['color'] ?>;color:<?= $opt['ic'] ?>">
          <i class="bi <?= $opt['icon'] ?>"></i>
        </div>
        <div class="ns-label">
          <div class="ns-label-title"><?= $opt['title'] ?></div>
          <div class="ns-label-desc"><?= $opt['desc'] ?></div>
        </div>
        <div class="ns-switch">
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox"
                   id="<?= $opt['key'] ?>" name="<?= $opt['key'] ?>"
                   role="switch"
                   <?= $on ? 'checked' : '' ?>>
            <label class="visually-hidden" for="<?= $opt['key'] ?>"><?= $opt['title'] ?></label>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- ══ Sekcja: Przypomnienia ════════════════════════════════════ -->
    <div class="ns-card mb-4">
      <div class="ns-card-header">
        <i class="bi bi-alarm-fill" style="color:#d97706"></i>
        Przypomnienia o terminach
        <span class="ns-meta ms-auto" style="font-weight:400">
          <span class="ns-channel"><i class="bi bi-envelope-fill"></i> E-mail</span>
          <span class="ns-timing">raz dziennie (CRON)</span>
        </span>
      </div>

      <?php
      $reminders = [
          [
              'key'   => 'notify_due_1day',
              'icon'  => 'bi-calendar-event-fill',
              'color' => '#fef9c3',
              'ic'    => '#ca8a04',
              'title' => 'Termin jutro',
              'desc'  => 'Przypomnienie wysyłane dzień przed terminem zadania.',
              'timing'=> 'wieczór dnia poprzedniego',
          ],
          [
              'key'   => 'notify_due_today',
              'icon'  => 'bi-alarm-fill',
              'color' => '#fee2e2',
              'ic'    => '#dc2626',
              'title' => 'Termin dzisiaj',
              'desc'  => 'Poranek w dniu, w którym zadanie powinno być ukończone.',
              'timing'=> 'rano w dniu terminu',
          ],
          [
              'key'   => 'notify_due_soon',
              'icon'  => 'bi-hourglass-bottom',
              'color' => '#ffedd5',
              'ic'    => '#ea580c',
              'title' => 'Termin za godzinę',
              'desc'  => 'Gdy zadanie ma ustawioną godzinę terminu — ok. godzinę przed nią.',
              'timing'=> 'ok. 1 h przed',
          ],
      ];
      foreach ($reminders as $opt):
          $on = (bool)($pref[$opt['key']] ?? 0);
      ?>
      <div class="ns-row">
        <div class="ns-icon" style="background:<?= $opt['color'] ?>;color:<?= $opt['ic'] ?>">
          <i class="bi <?= $opt['icon'] ?>"></i>
        </div>
        <div class="ns-label">
          <div class="ns-label-title"><?= $opt['title'] ?></div>
          <div class="ns-label-desc"><?= $opt['desc'] ?></div>
        </div>
        <div class="ns-meta me-2">
          <span class="ns-timing"><?= $opt['timing'] ?></span>
        </div>
        <div class="ns-switch">
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox"
                   id="<?= $opt['key'] ?>" name="<?= $opt['key'] ?>"
                   role="switch"
                   <?= $on ? 'checked' : '' ?>>
            <label class="visually-hidden" for="<?= $opt['key'] ?>"><?= $opt['title'] ?></label>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- ══ Sekcja: SMS ══════════════════════════════════════════════ -->
    <div class="ns-card mb-4">
      <div class="ns-card-header">
        <i class="bi bi-phone-fill" style="color:#16a34a"></i>
        Powiadomienia SMS
        <?php if (!sms_is_enabled()): ?>
          <span class="sec-badge" style="background:#fee2e2;color:#dc2626">SMS wyłączony</span>
        <?php elseif (!$has_sms): ?>
          <span class="sec-badge" style="background:#fef9c3;color:#ca8a04">Brak nr telefonu</span>
        <?php else: ?>
          <span class="sec-badge" style="background:#dcfce7;color:#16a34a">Aktywne</span>
        <?php endif; ?>
      </div>
      <div class="ns-row">
        <div class="ns-icon" style="background:#dcfce7;color:#16a34a">
          <i class="bi bi-chat-dots-fill"></i>
        </div>
        <div class="ns-label">
          <div class="ns-label-title">Powiadomienia SMS</div>
          <div class="ns-label-desc">
            Otrzymuj SMS przy przypisaniu, komentarzu i terminach.
            <?php if (!sms_is_enabled()): ?>
              SMS wymaga konfiguracji w panelu admina.
            <?php endif; ?>
          </div>
          <?php if (!$has_sms && sms_is_enabled()): ?>
          <!-- Inline: dodaj numer bez przechodzenia do profilu -->
          <form id="ns-phone-form" method="post" action="notification_settings.php<?= $is_fragment ? '?_fragment=1' : '' ?>"
                class="mt-2" style="max-width:280px" novalidate>
            <input type="hidden" name="_csrf"    value="<?= h($csrf) ?>">
            <input type="hidden" name="_action"  value="save_phone">
            <div class="d-flex gap-2">
              <input type="tel" name="phone_number" id="ns-phone-input"
                     class="form-control form-control-sm"
                     placeholder="np. +48600123456"
                     value="<?= h($user['phone_number'] ?? '') ?>"
                     aria-label="Numer telefonu" style="font-size:.83rem">
              <button type="submit" class="btn btn-sm btn-warning flex-shrink-0" style="font-size:.83rem;white-space:nowrap">
                <i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz
              </button>
            </div>
            <div id="ns-phone-msg" class="mt-1" style="font-size:.73rem"></div>
          </form>
          <?php elseif ($has_sms): ?>
          <div class="mt-1 d-flex align-items-center gap-2">
            <span style="font-size:.75rem;color:#16a34a">
              <i class="bi bi-phone-fill me-1"></i><?= h($user['phone_number']) ?>
            </span>
            <button type="button" class="btn btn-sm btn-link p-0" style="font-size:.73rem;color:#94a3b8"
                    id="ns-phone-change-btn">zmień</button>
          </div>
          <form id="ns-phone-change-form" method="post" action="notification_settings.php<?= $is_fragment ? '?_fragment=1' : '' ?>"
                class="mt-2" style="max-width:280px;display:none" novalidate>
            <input type="hidden" name="_csrf"    value="<?= h($csrf) ?>">
            <input type="hidden" name="_action"  value="save_phone">
            <div class="d-flex gap-2">
              <input type="tel" name="phone_number" id="ns-phone-change-input"
                     class="form-control form-control-sm"
                     placeholder="np. +48600123456"
                     value="<?= h($user['phone_number'] ?? '') ?>"
                     aria-label="Numer telefonu" style="font-size:.83rem">
              <button type="submit" class="btn btn-sm btn-warning flex-shrink-0" style="font-size:.83rem;white-space:nowrap">
                <i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz
              </button>
            </div>
          </form>
          <?php endif; ?>
        </div>
        <div class="ns-switch">
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox"
                   id="notify_sms" name="notify_sms" role="switch"
                   <?= ($pref['notify_sms'] ?? 0) ? 'checked' : '' ?>
                   <?= !$has_sms ? 'disabled' : '' ?>>
            <label class="visually-hidden" for="notify_sms">SMS</label>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer z przyciskami ──────────────────────────────────────── -->
    <div class="ns-card">
      <div class="ns-footer">
        <?php if (!$is_fragment): ?>
        <a href="<?= APP_URL ?>/tasks/index.php" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-left me-1"></i>Wróć do tablicy
        </a>
        <?php else: ?>
        <span></span>
        <?php endif; ?>
        <div class="d-flex align-items-center gap-2">
          <?php if (!$is_fragment): ?>
          <a href="<?= APP_URL ?>/tasks/notifications.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-clock-history me-1"></i>Historia
          </a>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary btn-sm px-4">
            <i class="bi bi-floppy me-1"></i>Zapisz ustawienia
          </button>
        </div>
      </div>
    </div>

  </form>

  <!-- ══ Test dostarczania ════════════════════════════════════════ -->
  <div class="ns-card mb-3 mt-4">
    <div class="ns-card-header">
      <i class="bi bi-send-fill" style="color:#7c3aed"></i>
      Testuj dostarczanie
      <span class="ns-meta ms-auto" style="font-weight:400;font-size:.75rem;color:#94a3b8">Sprawdź czy powiadomienia faktycznie docierają</span>
    </div>

    <?php if ($test_result !== null): ?>
    <div class="px-3 pt-3">
      <div class="alert alert-<?= $test_result['ok'] ? 'success' : 'warning' ?> d-flex align-items-center gap-2 py-2 mb-0" role="status">
        <i class="bi <?= $test_result['ok'] ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
        <?= h($test_result['msg']) ?>
      </div>
    </div>
    <?php endif; ?>

    <form method="post" class="ns-row" style="border-bottom:none;flex-wrap:wrap;gap:.75rem">
      <input type="hidden" name="_csrf"    value="<?= h($csrf) ?>">
      <input type="hidden" name="_action" value="test_notif">
      <div class="ns-icon" style="background:#ede9fe;color:#7c3aed">
        <i class="bi bi-envelope-paper-fill"></i>
      </div>
      <div class="ns-label">
        <div class="ns-label-title">Wyślij powiadomienie testowe</div>
        <div class="ns-label-desc">Weryfikuje czy e-mail, SMS lub in-app faktycznie dochodzi do Ciebie.</div>
      </div>
      <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
        <button type="submit" name="test_channel" value="email"
                class="btn btn-sm" style="background:#7c3aed;color:#fff;border:none;font-size:.8rem"
                <?= !$has_mail ? 'disabled title="Brak adresu e-mail"' : '' ?>>
          <i class="bi bi-envelope-fill me-1" aria-hidden="true"></i>Test e-mail
        </button>
        <?php if (sms_is_enabled()): ?>
        <button type="submit" name="test_channel" value="sms"
                class="btn btn-sm btn-outline-success" style="font-size:.8rem"
                <?= !$has_sms ? 'disabled title="Brak numeru telefonu"' : '' ?>>
          <i class="bi bi-chat-dots-fill me-1" aria-hidden="true"></i>Test SMS
        </button>
        <?php endif; ?>
        <button type="submit" name="test_channel" value="inapp"
                class="btn btn-sm btn-outline-secondary" style="font-size:.8rem">
          <i class="bi bi-bell-fill me-1" aria-hidden="true"></i>Test in-app
        </button>
      </div>
    </form>
  </div>

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
  <div class="ns-info mt-3">
    <div class="ns-info-row">
      <i class="bi bi-lightning-fill flex-shrink-0 mt-1" style="color:#2563eb"></i>
      <div><strong>Aktywność (przypisanie, @wzmianka, komentarze)</strong> — e-mail wysyłany natychmiast po zdarzeniu, max 1 raz dziennie dla tego samego zdarzenia.</div>
    </div>
    <div class="ns-info-row">
      <i class="bi bi-alarm-fill flex-shrink-0 mt-1" style="color:#d97706"></i>
      <div><strong>Przypomnienia o terminach</strong> — wysyłane raz dziennie przez skrypt CRON; nie duplikują się w tym samym dniu.</div>
    </div>
    <div class="ns-info-row">
      <i class="bi bi-envelope flex-shrink-0 mt-1"></i>
      <div>Powiadomienia trafiają na adres <strong><?= h($notify_to ?: '— brak adresu —') ?></strong>. Możesz podać inny w sekcji „Adres do powiadomień”.</div>
    </div>
  </div>

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
