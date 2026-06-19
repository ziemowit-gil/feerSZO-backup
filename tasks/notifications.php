<?php
/**
 * tasks/notifications.php — Powiadomienia użytkownika w module Zadania
 * Łączy: log wysłanych powiadomień + ustawienia preferencji
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/task_notify.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

$uid  = (int)(current_user()['id'] ?? 0);
$user = current_user();
$csrf = csrf_token();

// ── Zapis preferencji ─────────────────────────────────────────────────────
$saved = false;
$err   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'prefs') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        $err = 'Nieprawidłowy token CSRF.';
    } else {
        try {
            task_notify_save_pref($uid, [
                'notify_assigned'  => isset($_POST['notify_assigned'])  ? 1 : 0,
                'notify_mentioned' => isset($_POST['notify_mentioned']) ? 1 : 0,
                'notify_comment'   => isset($_POST['notify_comment'])   ? 1 : 0,
                'notify_due_1day'  => isset($_POST['notify_due_1day'])  ? 1 : 0,
                'notify_due_today' => isset($_POST['notify_due_today']) ? 1 : 0,
            ]);
            $saved = true;
            flash_set('success', 'Ustawienia powiadomień zapisane.');
            header('Location: notifications.php'); exit;
        } catch (\Throwable $e) {
            $err = 'Błąd zapisu: ' . $e->getMessage();
        }
    }
}

$pref = task_notify_get_pref($uid);

// ── Log powiadomień — ostatnie 60 ─────────────────────────────────────────
$log = db_all(
    "SELECT nl.*, t.title AS task_title, t.id AS task_id_real
     FROM task_notification_log nl
     LEFT JOIN tasks t ON t.id = nl.ref_id
     WHERE nl.user_id = ?
     ORDER BY nl.sent_at DESC
     LIMIT 60",
    [$uid]
);

// ── Zadania przypisane do mnie — nadchodzące terminy ─────────────────────
$upcoming = db_all(
    "SELECT t.id, t.title, t.due_date, t.priority,
            tl.name AS list_name, tw.name AS ws_name
     FROM tasks t
     JOIN task_assignments ta ON ta.task_id = t.id
     JOIN task_lists tl ON tl.id = t.list_id
     JOIN task_workspaces tw ON tw.id = t.workspace_id
     WHERE ta.user_id = ? AND t.completed_at IS NULL AND t.deleted_at IS NULL
       AND t.due_date IS NOT NULL
       AND t.due_date <= date('now', '+14 days')
     ORDER BY t.due_date ASC
     LIMIT 20",
    [$uid]
);

$ev_labels = [
    'assigned'         => 'Przypisano Cię do zadania',
    'mentioned'        => 'Wspomniano Cię w komentarzu',
    'comment_added'    => 'Nowy komentarz',
    'due_1day'         => 'Przypomnienie: termin jutro',
    'due_today'        => 'Przypomnienie: termin dzisiaj',
    'completed'        => 'Zadanie ukończone',
    'leader_notified'  => 'Zgłoszono problem',
];

$ev_icons = [
    'assigned'        => 'bi-person-plus-fill text-primary',
    'mentioned'       => 'bi-at text-info',
    'comment_added'   => 'bi-chat-fill text-secondary',
    'due_1day'        => 'bi-clock-fill text-warning',
    'due_today'       => 'bi-alarm-fill text-danger',
    'completed'       => 'bi-check-circle-fill text-success',
    'leader_notified' => 'bi-megaphone-fill text-warning',
];

$PAGE_TITLE       = 'Powiadomienia';
$TASKS_BREADCRUMB = 'Powiadomienia';
require_once __DIR__ . '/includes/header_tasks.php';
?>

<style>
.notif-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: .65rem;
  overflow: hidden;
}
.notif-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: .75rem 1rem;
  border-bottom: 1px solid #f1f5f9;
  background: #f8fafc;
}
.notif-title {
  font-size: .75rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .07em;
  color: #64748b; display: flex; align-items: center; gap: .4rem;
  margin: 0;
}
.notif-row {
  display: flex; align-items: flex-start; gap: .75rem;
  padding: .65rem 1rem;
  border-bottom: 1px solid #f8fafc;
  font-size: .84rem;
}
.notif-row:last-child { border-bottom: none; }
.notif-icon {
  width: 32px; height: 32px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; font-size: .9rem;
  background: #f1f5f9;
}
.notif-body  { flex: 1; line-height: 1.45; }
.notif-task  { font-weight: 600; color: #0f172a; }
.notif-meta  { font-size: .73rem; color: #94a3b8; margin-top: .1rem; }
.notif-time  { font-size: .72rem; color: #94a3b8; white-space: nowrap; flex-shrink: 0; }

/* Nadchodzące terminy */
.due-row {
  display: flex; align-items: center; gap: .65rem;
  padding: .6rem 1rem;
  border-bottom: 1px solid #f8fafc;
  text-decoration: none; color: inherit;
  cursor: pointer; transition: background .1s;
}
.due-row:last-child { border-bottom: none; }
.due-row:hover { background: #f8fafc; }
.due-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.due-title { flex: 1; font-size: .86rem; font-weight: 600;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.due-date { font-size: .77rem; white-space: nowrap; font-weight: 600; }
.due-date.overdue { color: #dc2626; }
.due-date.today   { color: #f59e0b; }
.due-date.soon    { color: #2563eb; }

/* Przełączniki preferencji */
.pref-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: .7rem 1rem;
  border-bottom: 1px solid #f8fafc;
}
.pref-row:last-child { border-bottom: none; }
.pref-label { display: block; cursor: pointer; font-size: .88rem; color: #0f172a; }
.pref-desc  { font-size: .75rem; color: #94a3b8; }

/* Empty state */
.notif-empty { text-align: center; padding: 2.5rem 1rem; color: #94a3b8; }
.notif-empty i { font-size: 1.6rem; display: block; margin-bottom: .5rem; opacity: .3; }
</style>

<div class="d-flex align-items-start justify-content-between mb-4">
  <div>
    <h1 class="h4 fw-bold mb-0">Powiadomienia</h1>
    <p class="text-muted small mb-0">Historia alertów i ustawienia e-mail</p>
  </div>
</div>

<div class="row g-3">

  <!-- ── Lewa kolumna: log + nadchodzące ──────────────────────────────── -->
  <div class="col-lg-7">

    <!-- Nadchodzące terminy -->
    <?php if ($upcoming): ?>
    <div class="notif-card mb-3">
      <div class="notif-head">
        <h2 class="notif-title">
          <i class="bi bi-calendar-event text-warning" aria-hidden="true"></i>
          Nadchodzące terminy
          <span class="badge rounded-pill ms-1"
                style="background:#fef9c3;color:#92400e;font-size:.65rem">
            <?= count($upcoming) ?>
          </span>
        </h2>
      </div>
      <?php foreach ($upcoming as $t):
        $diff = (int)((strtotime($t['due_date']) - strtotime('today')) / 86400);
        $cls  = $diff < 0 ? 'overdue' : ($diff === 0 ? 'today' : 'soon');
        $lbl  = $diff < 0 ? 'Po terminie (' . abs($diff) . ' dni)'
              : ($diff === 0 ? 'Dzisiaj!'
              : ($diff === 1 ? 'Jutro' : 'Za ' . $diff . ' dni'));
        $pc   = [4=>'#dc2626',3=>'#f59e0b',2=>'#3b82f6',1=>'#94a3b8'][$t['priority']] ?? '#94a3b8';
      ?>
      <div class="due-row"
           tabindex="0" role="button"
           aria-label="Zadanie: <?= h($t['title']) ?>, termin: <?= h($lbl) ?>"
           onclick="window.taskOpenById(<?= $t['id'] ?>)"
           onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.taskOpenById(<?= $t['id'] ?>)}">
        <span class="due-dot" style="background:<?= $pc ?>" aria-hidden="true"></span>
        <span class="due-title"><?= h($t['title']) ?></span>
        <span class="text-muted" style="font-size:.73rem"><?= h($t['ws_name']) ?></span>
        <span class="due-date <?= $cls ?>"><?= h($lbl) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Log powiadomień -->
    <div class="notif-card">
      <div class="notif-head">
        <h2 class="notif-title">
          <i class="bi bi-bell" aria-hidden="true"></i>
          Historia powiadomień e-mail
        </h2>
        <span class="text-muted" style="font-size:.75rem">ostatnie 60</span>
      </div>

      <?php if ($log): ?>
        <?php foreach ($log as $n):
          $icon_cls = $ev_icons[$n['event_type']] ?? 'bi-circle text-muted';
          $label    = $ev_labels[$n['event_type']] ?? $n['event_type'];
          $time     = substr($n['sent_at'], 0, 16);
        ?>
        <div class="notif-row">
          <div class="notif-icon" aria-hidden="true">
            <i class="bi <?= $icon_cls ?>"></i>
          </div>
          <div class="notif-body">
            <div class="notif-task"><?= h($n['task_title'] ?? '—') ?></div>
            <div class="notif-meta"><?= h($label) ?></div>
          </div>
          <time class="notif-time" datetime="<?= h($n['sent_at']) ?>"
                aria-label="Wysłano <?= h($time) ?>">
            <?= h($time) ?>
          </time>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="notif-empty">
          <i class="bi bi-bell-slash" aria-hidden="true"></i>
          <p class="mb-0">Brak historii powiadomień e-mail.</p>
        </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- ── Prawa kolumna: preferencje ───────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="notif-card">
      <div class="notif-head">
        <h2 class="notif-title">
          <i class="bi bi-sliders" aria-hidden="true"></i>
          Ustawienia powiadomień e-mail
        </h2>
      </div>

      <?php if ($err): ?>
      <div class="alert alert-danger small py-2 m-3"><?= h($err) ?></div>
      <?php endif; ?>

      <form method="post" action="notifications.php" novalidate>
        <input type="hidden" name="_csrf"   value="<?= h($csrf) ?>">
        <input type="hidden" name="_action" value="prefs">

        <?php
        $prefs_list = [
          ['notify_assigned',  'Przypisanie do zadania',     'Gdy ktoś przypisze Cię do zadania'],
          ['notify_mentioned', 'Wzmianka (@)',               'Gdy ktoś wspomni Cię w komentarzu'],
          ['notify_comment',   'Nowy komentarz',             'Gdy pojawi się komentarz do Twojego zadania'],
          ['notify_due_1day',  'Termin — 1 dzień wcześniej', 'Przypomnienie dzień przed terminem'],
          ['notify_due_today', 'Termin — w dniu terminu',    'Przypomnienie w dniu terminu'],
        ];
        foreach ($prefs_list as [$key, $lbl, $desc]):
          $checked = (bool)($pref[$key] ?? 1);
        ?>
        <div class="pref-row">
          <div>
            <label class="pref-label" for="pref-<?= $key ?>"><?= h($lbl) ?></label>
            <div class="pref-desc" id="pref-desc-<?= $key ?>"><?= h($desc) ?></div>
          </div>
          <div class="form-check form-switch ms-3 mb-0" style="flex-shrink:0">
            <input class="form-check-input"
                   type="checkbox"
                   role="switch"
                   id="pref-<?= $key ?>"
                   name="<?= $key ?>"
                   <?= $checked ? 'checked' : '' ?>
                   aria-describedby="pref-desc-<?= $key ?>">
          </div>
        </div>
        <?php endforeach; ?>

        <div class="p-3 border-top">
          <button type="submit" class="btn btn-primary btn-sm w-100">
            <i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz ustawienia
          </button>
        </div>
      </form>

      <!-- Info o adresie e-mail -->
      <div class="px-3 pb-3">
        <div class="d-flex align-items-center gap-2 p-2 rounded"
             style="background:#f8fafc;font-size:.78rem;color:#64748b">
          <i class="bi bi-envelope" aria-hidden="true"></i>
          Powiadomienia są wysyłane na:
          <strong><?= h($user['email'] ?? '—') ?></strong>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Offcanvas do podglądu zadań -->
<div class="offcanvas offcanvas-end shadow-lg" tabindex="-1" id="taskOffcanvas"
     role="dialog" aria-labelledby="taskOffcanvasLabel" aria-modal="true">
  <div class="offcanvas-header" style="border-bottom:1px solid #e2e8f0;padding:.85rem 1.1rem">
    <h2 class="h6 offcanvas-title fw-bold mb-0" id="taskOffcanvasLabel">
      <i class="bi bi-card-text me-1 text-primary" aria-hidden="true"></i>Szczegóły zadania
    </h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"
            aria-label="Zamknij szczegóły zadania"></button>
  </div>
  <div class="offcanvas-body p-0 overflow-auto" id="taskOffcanvasBody"
       aria-live="polite" aria-atomic="true">
    <div class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm" role="status">
        <span class="visually-hidden">Ładowanie…</span>
      </div>
    </div>
  </div>
</div>

<script>
const BASE = <?= json_encode(rtrim(APP_URL,'/')) ?>;

window.taskOpenById = function(id) {
    const body = document.getElementById('taskOffcanvasBody');
    body.innerHTML = '<div class="text-center py-5 text-muted">'
        + '<div class="spinner-border spinner-border-sm" role="status">'
        + '<span class="visually-hidden">Ładowanie…</span></div></div>';
    bootstrap.Offcanvas.getOrCreateInstance(
        document.getElementById('taskOffcanvas')
    ).show();
    fetch(BASE + '/tasks/detail.php?id=' + id)
        .then(r => r.text())
        .then(html => {
            body.innerHTML = '';
            body.appendChild(document.createRange().createContextualFragment(html));
        })
        .catch(() => {
            body.innerHTML = '<div class="alert alert-danger m-3">Błąd ładowania.</div>';
        });
};
</script>

<?php require_once __DIR__ . '/includes/footer_tasks.php'; ?>
