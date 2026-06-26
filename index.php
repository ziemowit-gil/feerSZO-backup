<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
if (defined('CRM_STANDALONE') && CRM_STANDALONE) { header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }

$PAGE_TITLE = 'Dashboard';
$_user      = current_user();
$today      = date('Y-m-d');
$month_start = date('Y-m-01');

// ── KPI — główne liczniki ─────────────────────────────────────────────────────
$kpi = ['total' => 0, 'active' => 0, 'new_month' => 0, 'expiring_7' => 0,
        'expiring_30' => 0, 'pending_approval' => 0, 'tasks_open' => 0, 'tasks_mine' => 0];

$end_col_map = [
    'zlecenie' => 'data_zakonczenia', 'uslugi' => 'data_zakonczenia',
    'wolontariat' => 'data_zakonczenia', 'dzielo' => 'termin_oddania',
    'praca' => 'data_zakonczenia', 'inne' => 'data_zakonczenia',
];

foreach (CONTRACT_TYPES as $slug => $label) {
    $table = table_for_type($slug);
    $col   = $end_col_map[$slug] ?? 'data_zakonczenia';
    try {
        $total  = (int)(db_one("SELECT COUNT(*) AS c FROM {$table}")['c'] ?? 0);
        $active = (int)(db_one("SELECT COUNT(*) AS c FROM {$table} WHERE status IN ('podpisana','w realizacji','obowiązująca')")['c'] ?? 0);
        $new_m  = (int)(db_one("SELECT COUNT(*) AS c FROM {$table} WHERE DATE(created_at) >= ?", [$month_start])['c'] ?? 0);
        $exp7   = (int)(db_one("SELECT COUNT(*) AS c FROM {$table} WHERE bezterminowa=0 AND {$col} BETWEEN ? AND DATE(?,'+7 days') AND status NOT IN ('zakończona','anulowana','rozwiązana')", [$today, $today])['c'] ?? 0);
        $exp30  = (int)(db_one("SELECT COUNT(*) AS c FROM {$table} WHERE bezterminowa=0 AND {$col} BETWEEN ? AND DATE(?,'+30 days') AND status NOT IN ('zakończona','anulowana','rozwiązana')", [$today, $today])['c'] ?? 0);
        $kpi['total']       += $total;
        $kpi['active']      += $active;
        $kpi['new_month']   += $new_m;
        $kpi['expiring_7']  += $exp7;
        $kpi['expiring_30'] += $exp30;
    } catch (\Throwable $e) {}
}

try { $kpi['pending_approval'] = (int)(db_one("SELECT COUNT(*) AS c FROM approval_requests WHERE status='pending'")['c'] ?? 0); } catch (\Throwable $e) {}
try {
    $kpi['tasks_open'] = (int)(db_one("SELECT COUNT(*) AS c FROM tasks WHERE deleted_at IS NULL AND status NOT IN ('done','archived')")['c'] ?? 0);
    $kpi['tasks_mine'] = (int)(db_one("SELECT COUNT(*) AS c FROM task_assignments ta JOIN tasks t ON t.id=ta.task_id WHERE ta.user_id=? AND t.deleted_at IS NULL AND t.status NOT IN ('done','archived')", [(int)$_user['id']])['c'] ?? 0);
} catch (\Throwable $e) {}

// ── Wygasające ────────────────────────────────────────────────────────────────
$expiring = [];
foreach (CONTRACT_TYPES as $slug => $label) {
    $table = table_for_type($slug);
    $col   = $end_col_map[$slug] ?? 'data_zakonczenia';
    try {
        $rows = db_all(
            "SELECT id, numer_umowy, imie_nazwisko, {$col} AS end_date, '{$slug}' AS type, '{$label}' AS type_label
             FROM {$table}
             WHERE bezterminowa=0 AND {$col} BETWEEN ? AND DATE(?,'+30 days')
               AND status NOT IN ('zakończona','anulowana','rozwiązana')
             ORDER BY {$col} LIMIT 8",
            [$today, $today]
        );
        $expiring = array_merge($expiring, $rows);
    } catch (\Throwable $e) {}
}
usort($expiring, fn($a, $b) => strcmp($a['end_date'], $b['end_date']));

// ── Oczekujące akceptacje ─────────────────────────────────────────────────────
$pending_approvals = [];
try {
    $pending_approvals = db_all(
        "SELECT ar.id, ar.contract_type, ar.contract_id, ar.numer_umowy, ar.requester_name, ar.created_at
         FROM approval_requests ar WHERE ar.status='pending' ORDER BY ar.created_at DESC LIMIT 5"
    );
} catch (\Throwable $e) {}

// ── Wiadomości ────────────────────────────────────────────────────────────────
$dash_threads = [];
if (can_edit()) {
    try {
        require_once __DIR__ . '/includes/messages.php';
        $dash_threads = db_all("
            SELECT m.context_type, m.context_id, m.contract_type,
                   MAX(m.created_at) AS last_at,
                   SUM(CASE WHEN m.sender_type='user' AND m.is_read=0 THEN 1 ELSE 0 END) AS unread,
                   (SELECT sender_name FROM messages m2 WHERE m2.context_type=m.context_type AND m2.context_id=m.context_id ORDER BY m2.created_at DESC LIMIT 1) AS last_sender,
                   (SELECT body FROM messages m2 WHERE m2.context_type=m.context_type AND m2.context_id=m.context_id ORDER BY m2.created_at DESC LIMIT 1) AS last_body
            FROM messages m GROUP BY m.context_type, m.context_id ORDER BY last_at DESC LIMIT 6
        ");
        foreach ($dash_threads as &$_dt) {
            if ($_dt['context_type'] === 'contract') {
                $table = 'umowy_' . $_dt['contract_type'];
                try {
                    $r = db_one("SELECT numer_umowy, imie_nazwisko FROM {$table} WHERE id=?", [(int)$_dt['context_id']]);
                    $_dt['_label'] = $r['numer_umowy'] ?? '#' . $_dt['context_id'];
                    $_dt['_sub']   = $r['imie_nazwisko'] ?? '';
                    $_dt['_url']   = APP_URL . '/contracts/' . $_dt['contract_type'] . '/view.php?id=' . $_dt['context_id'];
                } catch (\Throwable $e) { $_dt['_label'] = '#' . $_dt['context_id']; $_dt['_sub'] = ''; $_dt['_url'] = '#'; }
            } else {
                try {
                    $r = db_one("SELECT imie_nazwisko FROM onboarding_volunteers WHERE id=?", [(int)$_dt['context_id']]);
                    $_dt['_label'] = $r['imie_nazwisko'] ?? '#' . $_dt['context_id'];
                    $_dt['_sub']   = 'Zgłoszenie';
                    $_dt['_url']   = APP_URL . '/admin/onboarding_view.php?id=' . $_dt['context_id'];
                } catch (\Throwable $e) { $_dt['_label'] = ''; $_dt['_sub'] = ''; $_dt['_url'] = '#'; }
            }
        }
        unset($_dt);
    } catch (\Throwable $e) {}
}

// ── Moje zadania ──────────────────────────────────────────────────────────────
$my_tasks = [];
try {
    $my_tasks = db_all(
        "SELECT t.id, t.title, t.priority, t.due_date, tl.name AS list_name, tw.name AS ws_name
         FROM tasks t
         JOIN task_assignments ta ON ta.task_id = t.id AND ta.user_id = ?
         LEFT JOIN task_lists tl ON tl.id = t.list_id
         LEFT JOIN task_workspaces tw ON tw.id = t.workspace_id
         WHERE t.deleted_at IS NULL AND t.status NOT IN ('done','archived')
         ORDER BY t.due_date ASC NULLS LAST, t.priority DESC
         LIMIT 6",
        [(int)$_user['id']]
    );
} catch (\Throwable $e) {}

$hour = (int)date('H');
$greeting = $hour < 12 ? 'Dzień dobry' : ($hour < 18 ? 'Dzień dobry' : 'Dobry wieczór');
$first_name = explode(' ', trim($_user['name'] ?? $_user['email'] ?? ''))[0];

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── Dashboard layout ─────────────────────────────────────────────────────── */
.dash-kpi {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: .75rem;
  margin-bottom: 1.5rem;
}
.kpi-card {
  background: #fff;
  border: 1px solid #E2E8F0;
  border-radius: 14px;
  padding: 1.1rem 1.2rem;
  position: relative;
  overflow: hidden;
  transition: box-shadow .15s;
  text-decoration: none;
  display: block;
  color: inherit;
}
.kpi-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); color: inherit; }
.kpi-card::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: var(--kpi-accent, #94A3B8);
  border-radius: 14px 14px 0 0;
}
.kpi-icon {
  width: 36px; height: 36px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; flex-shrink: 0; margin-bottom: .6rem;
}
.kpi-val  { font-size: 1.75rem; font-weight: 800; line-height: 1; color: #0F172A; }
.kpi-lbl  { font-size: .75rem; font-weight: 600; color: #64748B; margin-top: .2rem; text-transform: uppercase; letter-spacing: .05em; }
.kpi-sub  { font-size: .72rem; color: #94A3B8; margin-top: .15rem; }

/* ── Sekcje ───────────────────────────────────────────────────────────────── */
.dash-section {
  background: #fff;
  border: 1px solid #E2E8F0;
  border-radius: 14px;
  overflow: hidden;
  margin-bottom: 1rem;
}
.dash-section-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: .8rem 1.1rem;
  border-bottom: 1px solid #F1F5F9;
  background: #FAFBFC;
}
.dash-section-title {
  font-size: .88rem; font-weight: 700; color: #1E293B;
  display: flex; align-items: center; gap: .4rem;
}
.dash-section-head a { font-size: .78rem; }

/* ── Zadania ──────────────────────────────────────────────────────────────── */
.task-item { display: flex; align-items: flex-start; gap: .65rem; padding: .6rem .9rem; border-bottom: 1px solid #F8FAFC; }
.task-item:last-child { border-bottom: none; }
.task-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; margin-top: 5px; }
.task-title { font-size: .83rem; font-weight: 500; color: #0F172A; line-height: 1.3; }
.task-meta  { font-size: .71rem; color: #94A3B8; margin-top: .1rem; }

/* ── Wiadomości ───────────────────────────────────────────────────────────── */
.msg-item { display: flex; align-items: center; gap: .7rem; padding: .6rem .9rem; border-bottom: 1px solid #F8FAFC; text-decoration: none; color: inherit; transition: background .1s; }
.msg-item:last-child { border-bottom: none; }
.msg-item:hover { background: #F8FAFC; }
.msg-avatar { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .65rem; font-weight: 700; flex-shrink: 0; }
.msg-label { font-size: .82rem; font-weight: 600; color: #0F172A; }
.msg-preview { font-size: .74rem; color: #94A3B8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 240px; }
.msg-time { font-size: .68rem; color: #CBD5E1; white-space: nowrap; }

/* ── Wygasające ───────────────────────────────────────────────────────────── */
.exp-item { display: flex; align-items: center; gap: .65rem; padding: .55rem .9rem; border-bottom: 1px solid #F8FAFC; text-decoration: none; color: inherit; transition: background .1s; }
.exp-item:last-child { border-bottom: none; }
.exp-item:hover { background: #FFF7ED; }
.exp-days { font-size: .7rem; font-weight: 700; padding: .2rem .5rem; border-radius: 20px; flex-shrink: 0; }

/* ── Akceptacje ───────────────────────────────────────────────────────────── */
.appr-item { display: flex; align-items: center; gap: .65rem; padding: .55rem .9rem; border-bottom: 1px solid #F8FAFC; }
.appr-item:last-child { border-bottom: none; }

/* ── Responsive ───────────────────────────────────────────────────────────── */
@media (max-width: 575px) {
  .dash-kpi { grid-template-columns: repeat(2, 1fr); }
  .kpi-val { font-size: 1.4rem; }
}
</style>

<!-- ── Nagłówek dashboardu ──────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <div>
    <h5 class="mb-0 fw-bold"><?= h($greeting) ?>, <?= h($first_name) ?> 👋</h5>
    <div class="text-muted small"><?= date('l, j F Y', strtotime($today)) ?></div>
  </div>
  <?php if (can_edit()): ?>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= APP_URL ?>/contracts/wolontariat/add.php" class="btn btn-sm btn-primary">
      <i class="bi bi-plus-lg me-1"></i>Nowa umowa
    </a>
    <a href="<?= APP_URL ?>/admin/contract_expiry.php" class="btn btn-sm btn-outline-warning">
      <i class="bi bi-calendar-x me-1"></i>Monitoring
    </a>
  </div>
  <?php endif; ?>
</div>

<!-- ── KPI ──────────────────────────────────────────────────────────────────── -->
<div class="dash-kpi">

  <a href="<?= APP_URL ?>/contracts/wolontariat/list.php" class="kpi-card" style="--kpi-accent:#1E6DFF">
    <div class="kpi-icon" style="background:#EEF4FF;color:#1E6DFF"><i class="bi bi-file-earmark-text-fill"></i></div>
    <div class="kpi-val"><?= $kpi['total'] ?></div>
    <div class="kpi-lbl">Wszystkich umów</div>
    <div class="kpi-sub">+<?= $kpi['new_month'] ?> w tym miesiącu</div>
  </a>

  <div class="kpi-card" style="--kpi-accent:#16A34A">
    <div class="kpi-icon" style="background:#F0FDF4;color:#16A34A"><i class="bi bi-check-circle-fill"></i></div>
    <div class="kpi-val"><?= $kpi['active'] ?></div>
    <div class="kpi-lbl">Aktywnych</div>
    <div class="kpi-sub"><?= $kpi['total'] > 0 ? round($kpi['active'] / $kpi['total'] * 100) : 0 ?>% wszystkich</div>
  </div>

  <?php if ($kpi['expiring_7'] > 0): ?>
  <a href="<?= APP_URL ?>/admin/contract_expiry.php" class="kpi-card" style="--kpi-accent:#DC2626">
    <div class="kpi-icon" style="background:#FEF2F2;color:#DC2626"><i class="bi bi-calendar-x-fill"></i></div>
    <div class="kpi-val text-danger"><?= $kpi['expiring_7'] ?></div>
    <div class="kpi-lbl">Wygasa w 7 dni</div>
    <div class="kpi-sub"><?= $kpi['expiring_30'] ?> w ciągu 30 dni</div>
  </a>
  <?php elseif ($kpi['expiring_30'] > 0): ?>
  <a href="<?= APP_URL ?>/admin/contract_expiry.php" class="kpi-card" style="--kpi-accent:#F59E0B">
    <div class="kpi-icon" style="background:#FFFBEB;color:#F59E0B"><i class="bi bi-calendar-event-fill"></i></div>
    <div class="kpi-val text-warning"><?= $kpi['expiring_30'] ?></div>
    <div class="kpi-lbl">Wygasa w 30 dni</div>
    <div class="kpi-sub">Sprawdź monitoring</div>
  </a>
  <?php else: ?>
  <div class="kpi-card" style="--kpi-accent:#16A34A">
    <div class="kpi-icon" style="background:#F0FDF4;color:#16A34A"><i class="bi bi-calendar-check-fill"></i></div>
    <div class="kpi-val">0</div>
    <div class="kpi-lbl">Wygasających</div>
    <div class="kpi-sub">Brak w ciągu 30 dni</div>
  </div>
  <?php endif; ?>

  <?php if ($kpi['pending_approval'] > 0): ?>
  <a href="<?= APP_URL ?>/admin/approvals.php" class="kpi-card" style="--kpi-accent:#7C3AED">
    <div class="kpi-icon" style="background:#F5F3FF;color:#7C3AED"><i class="bi bi-hourglass-split"></i></div>
    <div class="kpi-val text-purple" style="color:#7C3AED"><?= $kpi['pending_approval'] ?></div>
    <div class="kpi-lbl">Do akceptacji</div>
    <div class="kpi-sub">Oczekuje na decyzję</div>
  </a>
  <?php endif; ?>

  <?php if (module_enabled('tasks_enabled') && ($kpi['tasks_mine'] > 0 || $kpi['tasks_open'] > 0)): ?>
  <a href="<?= APP_URL ?>/tasks/index.php" class="kpi-card" style="--kpi-accent:#0EA5E9">
    <div class="kpi-icon" style="background:#F0F9FF;color:#0EA5E9"><i class="bi bi-kanban-fill"></i></div>
    <div class="kpi-val"><?= $kpi['tasks_mine'] ?></div>
    <div class="kpi-lbl">Moich zadań</div>
    <div class="kpi-sub"><?= $kpi['tasks_open'] ?> otwartych łącznie</div>
  </a>
  <?php endif; ?>


</div>

<!-- ── Główna siatka 2×2 ─────────────────────────────────────────────────────── -->
<div class="row g-3">

  <!-- Wygasają wkrótce -->
  <div class="col-xl-6">
    <div class="dash-section h-100">
      <div class="dash-section-head">
        <div class="dash-section-title"><i class="bi bi-calendar-x" style="color:#F59E0B"></i> Wygasają wkrótce</div>
        <a href="<?= APP_URL ?>/admin/contract_expiry.php" class="btn btn-sm btn-outline-secondary btn-xs">Wszystkie</a>
      </div>
      <?php if ($expiring): ?>
      <?php foreach ($expiring as $r):
        $days = (int)round((strtotime($r['end_date']) - strtotime($today)) / 86400);
        $days_color = $days <= 7 ? '#EF4444' : ($days <= 14 ? '#F59E0B' : '#0EA5E9');
        $days_bg    = $days <= 7 ? '#FEF2F2' : ($days <= 14 ? '#FFFBEB' : '#F0F9FF');
      ?>
      <a href="<?= contract_url($r['type'], $r['id']) ?>" class="exp-item">
        <span class="exp-days" style="background:<?= $days_bg ?>;color:<?= $days_color ?>"><?= $days ?>d</span>
        <div style="flex:1;overflow:hidden">
          <div style="font-size:.81rem;font-weight:600;color:#0F172A"><?= h($r['numer_umowy'] ?: '—') ?></div>
          <div style="font-size:.73rem;color:#94A3B8"><?= h($r['imie_nazwisko'] ?? '') ?></div>
        </div>
        <div style="font-size:.72rem;color:#64748B;white-space:nowrap"><?= date_pl($r['end_date']) ?></div>
      </a>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="text-muted text-center py-4 small">
        <i class="bi bi-check-circle text-success me-1"></i>Brak wygasających w ciągu 30 dni
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Do akceptacji -->
  <div class="col-xl-6">
    <div class="dash-section h-100">
      <div class="dash-section-head">
        <div class="dash-section-title"><i class="bi bi-hourglass-split" style="color:#7C3AED"></i> Do akceptacji</div>
        <?php if ($pending_approvals): ?>
        <a href="<?= APP_URL ?>/admin/approvals.php" class="btn btn-sm btn-outline-secondary btn-xs">Rozpatrz</a>
        <?php endif; ?>
      </div>
      <?php if ($pending_approvals): ?>
      <?php foreach ($pending_approvals as $a): ?>
      <div class="appr-item">
        <div style="width:6px;height:6px;border-radius:50%;background:#7C3AED;flex-shrink:0;margin-top:3px"></div>
        <div style="flex:1;overflow:hidden">
          <div style="font-size:.81rem;font-weight:600;color:#0F172A"><?= h($a['numer_umowy'] ?? '—') ?></div>
          <div style="font-size:.72rem;color:#94A3B8"><?= h($a['requester_name'] ?? '') ?> · <?= date_pl($a['created_at']) ?></div>
        </div>
        <a href="<?= APP_URL ?>/admin/approvals.php" class="btn btn-sm" style="font-size:.72rem;padding:.2rem .55rem;background:#F5F3FF;color:#7C3AED;border:1px solid #DDD6FE">Rozpatrz</a>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="text-muted text-center py-4 small">
        <i class="bi bi-check-circle text-success me-1"></i>Brak wniosków do akceptacji
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Moje zadania -->
  <?php if (module_enabled('tasks_enabled')): ?>
  <div class="col-xl-6">
    <div class="dash-section h-100">
      <div class="dash-section-head">
        <div class="dash-section-title"><i class="bi bi-kanban" style="color:#0EA5E9"></i> Moje zadania</div>
        <a href="<?= APP_URL ?>/tasks/index.php" class="btn btn-sm btn-outline-secondary btn-xs">
          <i class="bi bi-grid-3x3-gap me-1"></i>Tablica
        </a>
      </div>
      <?php if ($my_tasks): ?>
      <?php
      $prio_colors = ['high'=>'#EF4444','medium'=>'#F59E0B','low'=>'#94A3B8','critical'=>'#7C3AED'];
      foreach ($my_tasks as $t):
        $pc = $prio_colors[$t['priority'] ?? 'low'] ?? '#94A3B8';
        $overdue = $t['due_date'] && $t['due_date'] < $today;
      ?>
      <div class="task-item">
        <div class="task-dot" style="background:<?= $pc ?>"></div>
        <div style="flex:1;overflow:hidden">
          <div class="task-title"><?= h($t['title']) ?></div>
          <div class="task-meta">
            <?= h($t['ws_name'] ?? '') ?><?= ($t['ws_name'] && $t['list_name']) ? ' › ' : '' ?><?= h($t['list_name'] ?? '') ?>
            <?php if ($t['due_date']): ?>
            <span class="ms-2 <?= $overdue ? 'text-danger fw-semibold' : '' ?>">
              <i class="bi bi-calendar2 me-1"></i><?= date_pl($t['due_date']) ?>
              <?= $overdue ? '⚠' : '' ?>
            </span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="text-muted text-center py-4 small"><i class="bi bi-check2-circle text-success me-1"></i>Brak przypisanych zadań</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Wiadomości -->
  <?php if (can_edit()): ?>
  <div class="col-xl-6">
    <div class="dash-section h-100">
      <div class="dash-section-head">
        <div class="dash-section-title">
          <i class="bi bi-chat-dots" style="color:#1E6DFF"></i> Wiadomości
          <?php $total_unread = array_sum(array_column($dash_threads, 'unread')); if ($total_unread): ?>
          <span class="badge bg-danger ms-1" style="font-size:.65rem"><?= $total_unread ?></span>
          <?php endif; ?>
        </div>
        <a href="<?= APP_URL ?>/admin/messages.php" class="btn btn-sm btn-outline-secondary btn-xs">Wszystkie</a>
      </div>
      <?php if ($dash_threads): ?>
      <?php foreach ($dash_threads as $_dt):
        $unread  = (int)$_dt['unread'];
        $initials = strtoupper(mb_substr($_dt['_label'] ?? '?', 0, 2));
        $preview  = mb_substr(strip_tags($_dt['last_body'] ?? ''), 0, 60);
        $sender   = $_dt['last_sender'] ? explode(' ', $_dt['last_sender'])[0] . ': ' : '';
      ?>
      <button type="button" class="msg-item w-100"
        style="background:<?= $unread ? '#EFF6FF' : 'transparent' ?>;border:none;text-align:left"
        onclick="window.MsgWidget && window.MsgWidget.openThread('<?= h($_dt['context_type']) ?>',<?= (int)$_dt['context_id'] ?>,'<?= h($_dt['contract_type'] ?? '') ?>','<?= h($_dt['_label'] ?? '') ?>','<?= h($_dt['_url'] ?? '#') ?>')">
        <div class="msg-avatar" style="background:<?= $unread ? '#DBEAFE;color:#1E6DFF' : '#F1F5F9;color:#64748B' ?>">
          <?= h($initials) ?>
        </div>
        <div style="flex:1;overflow:hidden">
          <div class="msg-label <?= $unread ? 'text-primary' : '' ?>"><?= h($_dt['_label'] ?? '—') ?></div>
          <div class="msg-preview"><?= h($sender . $preview) ?></div>
        </div>
        <div class="text-end">
          <div class="msg-time"><?= date_pl($_dt['last_at']) ?></div>
          <?php if ($unread): ?>
          <span class="badge bg-danger mt-1" style="font-size:.62rem"><?= $unread ?></span>
          <?php endif; ?>
        </div>
      </button>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="text-muted text-center py-4 small"><i class="bi bi-chat me-1"></i>Brak wiadomości</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
