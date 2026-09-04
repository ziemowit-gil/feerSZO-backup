<?php
/**
 * tasks/notifications.php — Historia powiadomień + preferencje
 * Obsługuje ?_ajax=1 → JSON z fragmentem HTML do lazy-load / filtrowania
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

/* ── Zapis preferencji ───────────────────────────────────────────────── */
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'prefs') {
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
            flash_set('success', 'Ustawienia powiadomień zapisane.');
            header('Location: notifications.php'); exit;
        } catch (\Throwable $e) {
            $err = 'Błąd zapisu: ' . $e->getMessage();
        }
    }
}

$pref = task_notify_get_pref($uid);

/* ── Filtr + paginacja ──────────────────────────────────────────────── */
const NOTIF_PAGE = 20;

$filter_type = trim($_GET['type'] ?? '');
$filter_ch   = trim($_GET['ch'] ?? '');   // 'email' | 'sms' | ''
$offset      = max(0, (int)($_GET['offset'] ?? 0));
$is_ajax     = !empty($_GET['_ajax']);

$valid_types = ['assigned','comment','mention','due_1day','due_today','confirmed','rejected','leader_notified'];
if (!in_array($filter_type, $valid_types, true)) $filter_type = '';
if (!in_array($filter_ch, ['email','sms'], true)) $filter_ch = '';

function _notif_query(int $uid, string $type, string $ch, int $offset): array {
    $where = ['nl.user_id = ?'];
    $params = [$uid];

    if ($type !== '') {
        $where[] = 'nl.event_type = ?';
        $params[] = $type;
    }
    if ($ch !== '') {
        $where[] = 'nl.channel = ?';
        $params[] = $ch;
    }

    $sql_where = implode(' AND ', $where);

    $total = (int)(db_one(
        "SELECT COUNT(*) AS n FROM task_notification_log nl WHERE $sql_where",
        $params
    )['n'] ?? 0);

    $params_paged = $params;
    $params_paged[] = NOTIF_PAGE + 1;   // +1 do wykrycia has_more
    $params_paged[] = $offset;

    $rows = db_all(
        "SELECT nl.*, t.title AS task_title, t.id AS task_id_real
         FROM task_notification_log nl
         LEFT JOIN tasks t ON t.id = nl.ref_id
         WHERE $sql_where
         ORDER BY nl.sent_at DESC
         LIMIT ? OFFSET ?",
        $params_paged
    );

    $has_more = count($rows) > NOTIF_PAGE;
    if ($has_more) array_pop($rows);

    return ['rows' => $rows, 'total' => $total, 'has_more' => $has_more];
}

/* ── Nadchodzące terminy (tylko pełna strona) ───────────────────────── */
$upcoming = [];
if (!$is_ajax) {
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
}

/* ── Metadane zdarzeń ────────────────────────────────────────────────── */
$ev_labels = [
    'assigned'         => 'Przypisano',
    'mention'          => 'Wzmianka',
    'comment'          => 'Komentarz',
    'due_1day'         => 'Termin (jutro)',
    'due_today'        => 'Termin (dzisiaj)',
    'confirmed'        => 'Potwierdzenie',
    'rejected'         => 'Odrzucenie',
    'leader_notified'  => 'Zgłoszono problem',
];

$ev_icons = [
    'assigned'        => 'bi-person-plus-fill',
    'mention'         => 'bi-at',
    'comment'         => 'bi-chat-fill',
    'due_1day'        => 'bi-clock-fill',
    'due_today'       => 'bi-alarm-fill',
    'confirmed'       => 'bi-check-circle-fill',
    'rejected'        => 'bi-x-circle-fill',
    'leader_notified' => 'bi-megaphone-fill',
];

$ev_colors = [
    'assigned'        => '#2563eb',
    'mention'         => '#0891b2',
    'comment'         => '#64748b',
    'due_1day'        => '#f59e0b',
    'due_today'       => '#dc2626',
    'confirmed'       => '#059669',
    'rejected'        => '#dc2626',
    'leader_notified' => '#f59e0b',
];

/* ── AJAX ─────────────────────────────────────────────────────────────── */
if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $res = _notif_query($uid, $filter_type, $filter_ch, $offset);
    ob_start();
    _notif_render_rows($res['rows'], $ev_labels, $ev_icons, $ev_colors, $res['has_more'], $filter_type, $filter_ch, $offset + NOTIF_PAGE);
    $html = ob_get_clean();
    echo json_encode(['ok' => true, 'html' => $html, 'total' => $res['total'], 'has_more' => $res['has_more']], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── Załaduj dane dla pełnej strony ─────────────────────────────────── */
$res = _notif_query($uid, $filter_type, $filter_ch, 0);

/* ── Helper: renderuj wiersze logu ──────────────────────────────────── */
function _notif_render_rows(array $rows, array $ev_labels, array $ev_icons, array $ev_colors, bool $has_more, string $filter_type, string $filter_ch, int $next_offset): void {
    if (!$rows): ?>
    <div class="notif-empty" id="notif-empty-state">
      <i class="bi bi-bell-slash" aria-hidden="true"></i>
      <p class="mb-0">Brak wpisów dla wybranych filtrów.</p>
    </div>
    <?php return; endif;

    foreach ($rows as $n):
        $ev   = $n['event_type'] ?? '';
        $icon = $ev_icons[$ev]  ?? 'bi-circle';
        $col  = $ev_colors[$ev] ?? '#94a3b8';
        $lbl  = $ev_labels[$ev] ?? $ev;
        $time = substr($n['sent_at'] ?? '', 0, 16);
        $ch       = $n['channel']  ?? 'email';
        $delivery = $n['delivery'] ?? 'direct';
        $is_fail  = ($delivery === 'failed' || $delivery === 'rate_limited');
    ?>
    <div class="notif-row<?= $is_fail ? ' notif-row-failed' : '' ?>" data-ev="<?= h($ev) ?>" data-ch="<?= h($ch) ?>">
      <div class="notif-icon" style="color:<?= $is_fail ? '#dc2626' : h($col) ?>" aria-hidden="true">
        <i class="bi <?= $is_fail ? 'bi-exclamation-circle-fill' : h($icon) ?>"></i>
      </div>
      <div class="notif-body">
        <div class="notif-task"><?= h($n['task_title'] ?? '—') ?></div>
        <div class="notif-meta"><?= h($lbl) ?></div>
      </div>
      <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0">
        <time class="notif-time" datetime="<?= h($n['sent_at']) ?>"><?= h($time) ?></time>
        <?php if ($is_fail): ?>
        <span class="notif-ch-badge notif-ch-failed" title="Błąd wysyłki: <?= h($delivery) ?>" aria-label="Błąd">
          <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>Błąd
        </span>
        <?php elseif ($ch === 'sms'): ?>
        <span class="notif-ch-badge notif-ch-sms" title="Wysłano przez SMS" aria-label="SMS">
          <i class="bi bi-chat-dots-fill" aria-hidden="true"></i>SMS
        </span>
        <?php elseif ($delivery === 'queue'): ?>
        <span class="notif-ch-badge notif-ch-queue" title="Wysłano przez kolejkę e-mail" aria-label="Kolejka">
          <i class="bi bi-hourglass-split" aria-hidden="true"></i>Kolejka
        </span>
        <?php elseif ($delivery === 'php_mail'): ?>
        <span class="notif-ch-badge notif-ch-queue" title="Wysłano przez PHP mail()" aria-label="Fallback">
          <i class="bi bi-envelope-arrow-up-fill" aria-hidden="true"></i>Fallback
        </span>
        <?php else: ?>
        <span class="notif-ch-badge notif-ch-email" title="Wysłano przez e-mail (<?= h($delivery) ?>)" aria-label="E-mail">
          <i class="bi bi-envelope-fill" aria-hidden="true"></i>E-mail
        </span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach;

    if ($has_more): ?>
    <div class="notif-more-wrap" id="notif-more-wrap">
      <button type="button" class="btn btn-sm notif-more-btn"
              id="notif-more-btn"
              data-type="<?= h($filter_type) ?>"
              data-ch="<?= h($filter_ch) ?>"
              data-offset="<?= (int)$next_offset ?>">
        <i class="bi bi-chevron-down me-1" aria-hidden="true"></i>Załaduj więcej
      </button>
    </div>
    <?php endif;
}

$PAGE_TITLE       = 'Powiadomienia';
$TASKS_BREADCRUMB = 'Powiadomienia';
require_once __DIR__ . '/includes/header_tasks.php';
?>

<style type="text/tailwindcss">
.notif-card { @apply tw-bg-white tw-border tw-border-slate-200 tw-rounded-xl tw-overflow-hidden; }
.notif-head {
  @apply tw-flex tw-items-center tw-justify-between tw-py-3 tw-px-4 tw-border-b tw-border-slate-100 tw-bg-slate-50 tw-flex-wrap tw-gap-2;
}
.notif-title {
  @apply tw-text-xs tw-font-bold tw-uppercase tw-tracking-wider tw-text-slate-500 tw-flex tw-items-center tw-gap-[.4rem] tw-m-0;
}

/* Pasek filtrów */
.notif-filter-bar {
  @apply tw-flex tw-items-center tw-gap-2 tw-py-[.6rem] tw-px-4 tw-border-b tw-border-slate-100 tw-bg-[#fafbfc] tw-flex-wrap;
}
.notif-filter-label {
  @apply tw-text-[.73rem] tw-text-slate-400 tw-font-semibold tw-uppercase tw-tracking-wide tw-flex-shrink-0;
}
.notif-chip {
  @apply tw-inline-flex tw-items-center tw-gap-[.3rem] tw-py-[.22rem] tw-px-[.65rem] tw-rounded-full tw-border tw-border-slate-200 tw-bg-white tw-text-slate-700 tw-text-[.78rem] tw-font-medium tw-cursor-pointer tw-no-underline tw-transition-all tw-whitespace-nowrap;
}
.notif-chip:hover { border-color: var(--tsk-green); color: var(--tsk-green); background: #ecfdf5; }
.notif-chip.active { background: var(--tsk-green); color: #fff; border-color: var(--tsk-green); }
.notif-chip-sep { @apply tw-w-px tw-h-5 tw-bg-slate-200 tw-flex-shrink-0; }

/* Wiersze logu */
.notif-row {
  @apply tw-flex tw-items-start tw-gap-3 tw-py-[.65rem] tw-px-4 tw-border-b tw-border-slate-50 tw-text-[.84rem];
}
.notif-row:last-child { @apply tw-border-b-0; }
.notif-icon {
  @apply tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-flex-shrink-0 tw-text-[.95rem] tw-bg-slate-100;
}
.notif-body  { @apply tw-flex-1 tw-leading-[1.45] tw-min-w-0; }
.notif-task  { @apply tw-font-semibold tw-text-slate-900 tw-whitespace-nowrap tw-overflow-hidden tw-text-ellipsis; }
.notif-meta  { @apply tw-text-[.73rem] tw-text-slate-400 tw-mt-[.1rem]; }
.notif-time  { @apply tw-text-[.72rem] tw-text-slate-400; }

/* Badge kanału */
.notif-ch-badge {
  @apply tw-inline-flex tw-items-center tw-gap-[.2rem] tw-text-[.62rem] tw-font-bold tw-py-[.1rem] tw-px-[.4rem] tw-rounded-full tw-leading-[1.4] tw-whitespace-nowrap;
}
.notif-ch-email  { @apply tw-bg-blue-50 tw-text-blue-700; }
.notif-ch-sms    { @apply tw-bg-green-50 tw-text-green-700; }
.notif-ch-queue  { @apply tw-bg-amber-50 tw-text-amber-700; }
.notif-ch-failed { @apply tw-bg-red-50 tw-text-red-600; }
.notif-row-failed { @apply tw-opacity-[.82]; }
.notif-row-failed .notif-task { @apply tw-line-through tw-text-slate-400; }

/* Załaduj więcej */
.notif-more-wrap { @apply tw-flex tw-justify-center tw-py-3 tw-border-t tw-border-slate-100; }
.notif-more-btn {
  @apply tw-bg-slate-100 tw-text-slate-700 tw-border tw-border-slate-200 tw-text-[.82rem] tw-rounded-lg tw-py-[.4rem] tw-px-[1.1rem];
}
.notif-more-btn:hover { background: var(--tsk-green-bg); color: var(--tsk-green); border-color: var(--tsk-green); }

/* Nadchodzące terminy */
.due-row {
  @apply tw-flex tw-items-center tw-gap-[.65rem] tw-py-[.6rem] tw-px-4 tw-border-b tw-border-slate-50 tw-no-underline tw-text-inherit tw-cursor-pointer tw-transition-colors;
}
.due-row:last-child { @apply tw-border-b-0; }
.due-row:hover { @apply tw-bg-slate-50; }
.due-dot   { @apply tw-w-[10px] tw-h-[10px] tw-rounded-full tw-flex-shrink-0; }
.due-title { @apply tw-flex-1 tw-text-[.86rem] tw-font-semibold tw-whitespace-nowrap tw-overflow-hidden tw-text-ellipsis; }
.due-date  { @apply tw-text-[.77rem] tw-whitespace-nowrap tw-font-semibold; }
.due-date.overdue { @apply tw-text-red-600; }
.due-date.today   { @apply tw-text-amber-500; }
.due-date.soon    { @apply tw-text-blue-600; }

/* Preferencje */
.pref-row { @apply tw-flex tw-items-center tw-justify-between tw-py-[.7rem] tw-px-4 tw-border-b tw-border-slate-50; }
.pref-row:last-child { @apply tw-border-b-0; }
.pref-label { @apply tw-block tw-cursor-pointer tw-text-[.88rem] tw-text-slate-900; }
.pref-desc  { @apply tw-text-xs tw-text-slate-400; }

/* Empty state */
.notif-empty { @apply tw-text-center tw-py-10 tw-px-4 tw-text-slate-400; }
.notif-empty i { @apply tw-text-2xl tw-block tw-mb-2 tw-opacity-30; }
</style>

<div class="d-flex align-items-start justify-content-between mb-4">
  <div>
    <h1 class="h4 fw-bold mb-0">Powiadomienia</h1>
    <p class="text-muted small mb-0">Historia alertów i ustawienia e-mail / SMS</p>
  </div>
</div>

<div class="row g-3">

  <!-- ── Lewa: log + nadchodzące ────────────────────────────────────── -->
  <div class="col-lg-7">

    <?php if ($upcoming): ?>
    <div class="notif-card mb-3">
      <div class="notif-head">
        <h2 class="notif-title">
          <i class="bi bi-calendar-event text-warning" aria-hidden="true"></i>
          Nadchodzące terminy
          <span class="badge rounded-pill ms-1" style="background:#fef9c3;color:#92400e;font-size:.65rem">
            <?= count($upcoming) ?>
          </span>
        </h2>
      </div>
      <?php foreach ($upcoming as $t):
        $diff = (int)((strtotime($t['due_date']) - strtotime('today')) / 86400);
        $cls  = $diff < 0 ? 'overdue' : ($diff === 0 ? 'today' : 'soon');
        $lbl  = $diff < 0 ? 'Po terminie (' . abs($diff) . ' dni)'
              : ($diff === 0 ? 'Dzisiaj!' : ($diff === 1 ? 'Jutro' : 'Za ' . $diff . ' dni'));
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

    <!-- Historia -->
    <div class="notif-card">
      <div class="notif-head">
        <h2 class="notif-title">
          <i class="bi bi-bell" aria-hidden="true"></i>Historia powiadomień
        </h2>
        <span class="text-muted" id="notif-total-label" style="font-size:.75rem">
          <?= $res['total'] ?> wpisów
        </span>
      </div>

      <!-- Pasek filtrów -->
      <div class="notif-filter-bar" id="notif-filter-bar" role="toolbar" aria-label="Filtr historii powiadomień">
        <span class="notif-filter-label">Zdarzenie:</span>
        <a href="#" class="notif-chip <?= $filter_type === '' ? 'active' : '' ?>"
           data-filter-type="" aria-pressed="<?= $filter_type === '' ? 'true' : 'false' ?>">Wszystkie</a>
        <?php
        $chip_types = [
            'assigned'        => 'Przypisanie',
            'comment'         => 'Komentarz',
            'mention'         => 'Wzmianka',
            'due_1day'        => 'Termin',
            'confirmed'       => 'Potwierdzenie',
            'rejected'        => 'Odrzucenie',
            'leader_notified' => 'Problem',
        ];
        foreach ($chip_types as $val => $lbl): ?>
        <a href="#" class="notif-chip <?= $filter_type === $val ? 'active' : '' ?>"
           data-filter-type="<?= h($val) ?>"
           aria-pressed="<?= $filter_type === $val ? 'true' : 'false' ?>"><?= h($lbl) ?></a>
        <?php endforeach; ?>

        <span class="notif-chip-sep" aria-hidden="true"></span>
        <span class="notif-filter-label">Kanał:</span>
        <a href="#" class="notif-chip <?= $filter_ch === '' ? 'active' : '' ?>"
           data-filter-ch="" aria-pressed="<?= $filter_ch === '' ? 'true' : 'false' ?>">Wszystkie</a>
        <a href="#" class="notif-chip <?= $filter_ch === 'email' ? 'active' : '' ?>"
           data-filter-ch="email" aria-pressed="<?= $filter_ch === 'email' ? 'true' : 'false' ?>">
          <i class="bi bi-envelope-fill" aria-hidden="true"></i>E-mail</a>
        <a href="#" class="notif-chip <?= $filter_ch === 'sms' ? 'active' : '' ?>"
           data-filter-ch="sms" aria-pressed="<?= $filter_ch === 'sms' ? 'true' : 'false' ?>">
          <i class="bi bi-chat-dots-fill" aria-hidden="true"></i>SMS</a>
      </div>

      <div id="notif-log-body" aria-live="polite" aria-busy="false">
        <?php _notif_render_rows($res['rows'], $ev_labels, $ev_icons, $ev_colors, $res['has_more'], $filter_type, $filter_ch, NOTIF_PAGE); ?>
      </div>
    </div>

  </div>

  <!-- ── Prawa: preferencje ─────────────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="notif-card">
      <div class="notif-head">
        <h2 class="notif-title">
          <i class="bi bi-sliders" aria-hidden="true"></i>Ustawienia powiadomień
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
            <input class="form-check-input" type="checkbox" role="switch"
                   id="pref-<?= $key ?>" name="<?= $key ?>"
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

      <div class="px-3 pb-3">
        <div class="d-flex align-items-center gap-2 p-2 rounded"
             style="background:#f8fafc;font-size:.78rem;color:#64748b">
          <i class="bi bi-envelope" aria-hidden="true"></i>
          Powiadomienia e-mail:
          <strong><?= h($user['email'] ?? '—') ?></strong>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Offcanvas podglądu zadania -->
<div class="offcanvas offcanvas-end shadow-lg" tabindex="-1" id="taskOffcanvas"
     role="dialog" aria-labelledby="taskOffcanvasLabel" aria-modal="true">
  <div class="offcanvas-header" style="border-bottom:1px solid #e2e8f0;padding:.85rem 1.1rem">
    <h2 class="h6 offcanvas-title fw-bold mb-0" id="taskOffcanvasLabel">
      <i class="bi bi-card-text me-1 text-primary" aria-hidden="true"></i>Szczegóły zadania
    </h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"
            aria-label="Zamknij szczegóły zadania"></button>
  </div>
  <div class="offcanvas-body p-0 overflow-auto" id="taskOffcanvasBody" aria-live="polite" aria-atomic="true">
    <div class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm" role="status">
        <span class="visually-hidden">Ładowanie…</span>
      </div>
    </div>
  </div>
</div>

<script>
const BASE = <?= json_encode(rtrim(APP_URL, '/')) ?>;

/* ── Podgląd zadania ─────────────────────────────────────────────────── */
window.taskOpenById = function (id) {
    const body = document.getElementById('taskOffcanvasBody');
    body.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Ładowanie…</span></div></div>';
    bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('taskOffcanvas')).show();
    fetch(BASE + '/tasks/detail.php?id=' + id)
        .then(r => r.text())
        .then(html => { body.innerHTML = ''; body.appendChild(document.createRange().createContextualFragment(html)); })
        .catch(() => { body.innerHTML = '<div class="alert alert-danger m-3">Błąd ładowania.</div>'; });
};

/* ── Filtr + paginacja ───────────────────────────────────────────────── */
(function () {
    const logBody   = document.getElementById('notif-log-body');
    const totalLbl  = document.getElementById('notif-total-label');
    const filterBar = document.getElementById('notif-filter-bar');
    if (!logBody || !filterBar) return;

    let curType   = <?= json_encode($filter_type) ?>;
    let curCh     = <?= json_encode($filter_ch) ?>;
    let loading   = false;

    function buildUrl(type, ch, offset) {
        const p = new URLSearchParams({ _ajax: '1', type, ch, offset });
        return BASE + '/tasks/notifications.php?' + p.toString();
    }

    function setLoading(state) {
        loading = state;
        logBody.setAttribute('aria-busy', state ? 'true' : 'false');
    }

    function fetchPage(type, ch, offset, append) {
        if (loading) return;
        setLoading(true);
        if (!append) {
            logBody.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Ładowanie…</span></div></div>';
        } else {
            const wrap = document.getElementById('notif-more-wrap');
            if (wrap) {
                const btn = wrap.querySelector('#notif-more-btn');
                if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Ładowanie…'; }
            }
        }
        fetch(buildUrl(type, ch, offset))
            .then(r => r.json())
            .then(d => {
                if (!d.ok) throw new Error(d.error || 'Błąd');
                if (append) {
                    const wrap = document.getElementById('notif-more-wrap');
                    if (wrap) wrap.remove();
                    logBody.insertAdjacentHTML('beforeend', d.html);
                } else {
                    logBody.innerHTML = d.html;
                }
                if (totalLbl) totalLbl.textContent = d.total + ' wpisów';
            })
            .catch(() => {
                if (!append) logBody.innerHTML = '<div class="alert alert-danger m-3">Błąd ładowania.</div>';
            })
            .finally(() => setLoading(false));
    }

    /* Klik w chip filtra */
    filterBar.addEventListener('click', function (e) {
        const chip = e.target.closest('.notif-chip');
        if (!chip) return;
        e.preventDefault();

        if ('filterType' in chip.dataset) {
            curType = chip.dataset.filterType;
            filterBar.querySelectorAll('[data-filter-type]').forEach(c => {
                c.classList.toggle('active', c.dataset.filterType === curType);
                c.setAttribute('aria-pressed', c.dataset.filterType === curType ? 'true' : 'false');
            });
        } else if ('filterCh' in chip.dataset) {
            curCh = chip.dataset.filterCh;
            filterBar.querySelectorAll('[data-filter-ch]').forEach(c => {
                c.classList.toggle('active', c.dataset.filterCh === curCh);
                c.setAttribute('aria-pressed', c.dataset.filterCh === curCh ? 'true' : 'false');
            });
        } else return;

        fetchPage(curType, curCh, 0, false);
    });

    /* Klik „Załaduj więcej" (delegacja — przycisk może być re-renderowany) */
    logBody.addEventListener('click', function (e) {
        const btn = e.target.closest('#notif-more-btn');
        if (!btn) return;
        const offset = parseInt(btn.dataset.offset, 10) || 0;
        fetchPage(curType, curCh, offset, true);
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer_tasks.php'; ?>
