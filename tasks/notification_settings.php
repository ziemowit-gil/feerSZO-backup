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

$saved = false;
$error = '';

// ── Zapis ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        $error = 'Nieprawidłowy token CSRF. Odśwież stronę i spróbuj ponownie.';
    } else {
        try {
            task_notify_save_pref($uid, [
                'notify_assigned'  => isset($_POST['notify_assigned'])  ? 1 : 0,
                'notify_mentioned' => isset($_POST['notify_mentioned']) ? 1 : 0,
                'notify_comment'   => isset($_POST['notify_comment'])   ? 1 : 0,
                'notify_due_1day'  => isset($_POST['notify_due_1day'])  ? 1 : 0,
                'notify_due_today' => isset($_POST['notify_due_today']) ? 1 : 0,
                'notify_confirmed' => isset($_POST['notify_confirmed']) ? 1 : 0,
                'notify_sms'       => isset($_POST['notify_sms'])       ? 1 : 0,
            ]);
            $saved = true;
        } catch (\Throwable $e) {
            $error = 'Błąd zapisu: ' . $e->getMessage();
        }
    }
}

$pref     = task_notify_get_pref($uid);
$csrf     = csrf_token();
$has_mail = !empty($user['email']);
require_once dirname(__DIR__) . '/includes/sms.php';
$has_sms  = sms_is_enabled() && !empty($user['phone_number']);

$page_title = 'Powiadomienia — Zadania';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<style>
/* ── layout ──────────────────────────────────────────────────── */
.ns-wrap     { max-width: 720px; }
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

<div class="ns-wrap py-4 mx-auto">

  <!-- Header karty ─────────────────────────────────────────────── -->
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
          <?= h($user['email']) ?>
          <span style="opacity:.7">· aktywny kanał</span>
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

  <?php if ($saved): ?>
  <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
    <i class="bi bi-check-circle-fill"></i>
    <span>Ustawienia zapisane pomyślnie.</span>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php elseif ($error): ?>
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
              'key'   => 'notify_confirmed',
              'icon'  => 'bi-patch-check-fill',
              'color' => '#ede9fe',
              'ic'    => '#7c3aed',
              'title' => 'Potwierdzenie wykonania',
              'desc'  => 'Gdy zlecający potwierdzi wykonanie zadania, które realizujesz.',
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
            <?php if (!$has_sms && sms_is_enabled()): ?>
              <a href="<?= APP_URL ?>/panel/settings.php" class="text-warning">Dodaj numer telefonu w profilu →</a>
            <?php elseif (!sms_is_enabled()): ?>
              SMS wymaga konfiguracji w panelu admina.
            <?php endif; ?>
          </div>
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
        <a href="<?= APP_URL ?>/tasks/index.php" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-left me-1"></i>Wróć do tablicy
        </a>
        <div class="d-flex align-items-center gap-2">
          <a href="<?= APP_URL ?>/tasks/notifications.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-clock-history me-1"></i>Historia
          </a>
          <button type="submit" class="btn btn-primary btn-sm px-4">
            <i class="bi bi-floppy me-1"></i>Zapisz ustawienia
          </button>
        </div>
      </div>
    </div>

  </form>

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
      <div>Powiadomienia trafiają na adres <strong><?= h($user['email'] ?: '— brak adresu —') ?></strong>. Zmień go w profilu konta.</div>
    </div>
  </div>

</div>

<script>
var PRESETS = {
  all:       ['notify_assigned','notify_mentioned','notify_comment','notify_confirmed','notify_due_1day','notify_due_today'],
  important: ['notify_assigned','notify_mentioned','notify_confirmed','notify_due_1day','notify_due_today'],
  deadlines: ['notify_due_1day','notify_due_today'],
  none:      []
};
function setPreset(name) {
  var on = PRESETS[name] || [];
  ['notify_assigned','notify_mentioned','notify_comment','notify_confirmed','notify_due_1day','notify_due_today'].forEach(function(k) {
    var el = document.getElementById(k);
    if (el) el.checked = on.indexOf(k) >= 0;
  });
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
