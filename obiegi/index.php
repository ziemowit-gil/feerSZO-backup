<?php
/**
 * obiegi/index.php — Pulpit obiegów: „Do mnie" (do decyzji) + „Moje wnioski".
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';
require_once dirname(__DIR__) . '/includes/obiegi.php';

require_login();
require_module_enabled('obiegi_enabled', 'Moduł Obiegi');

$u   = current_user();
$uid = (int)$u['id'];

$inbox = obiegi_inbox($u);
$mine  = obiegi_my_requests($uid);
$defs  = obiegi_definitions(true);

$PAGE_TITLE = 'Obiegi';
$tab = $_GET['tab'] ?? 'inbox';

function _obiegi_row(array $r, bool $show_submitter): string {
    $step = '';
    if ($r['status'] === 'w_toku') {
        $s = obiegi_step_at((int)$r['definition_id'], (int)$r['current_step_order']);
        $step = $s ? h($s['name']) : '—';
    }
    $url = APP_URL . '/obiegi/view.php?id=' . (int)$r['id'];
    $out  = '<tr style="cursor:pointer" onclick="location.href=\'' . $url . '\'">';
    $out .= '<td><i class="bi ' . h($r['def_icon'] ?: 'bi-diagram-2') . ' me-2 text-muted"></i>' . h($r['title']) . '<div class="small text-muted">' . h($r['def_name']) . '</div></td>';
    if ($show_submitter) $out .= '<td class="small">' . h($r['submitter_name'] ?? '—') . '</td>';
    $out .= '<td class="small">' . ($step !== '' ? $step : '<span class="text-muted">—</span>') . '</td>';
    $out .= '<td>' . obieg_status_badge($r['status']) . '</td>';
    $out .= '<td class="small text-muted">' . h(substr((string)$r['submitted_at'], 0, 16)) . '</td>';
    $out .= '</tr>';
    return $out;
}

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container my-4" style="max-width:1000px">
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-diagram-2 me-2"></i>Obiegi</h1>
    <div class="d-flex gap-2">
      <?php if ($defs): ?>
      <a href="<?= APP_URL ?>/obiegi/new.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowy wniosek</a>
      <?php endif; ?>
      <?php if (is_admin()): ?>
      <a href="<?= APP_URL ?>/admin/obiegi.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear me-1"></i>Definicje</a>
      <?php endif; ?>
    </div>
  </div>
  <?= flash_html() ?>

  <?php if (!$defs): ?>
    <div class="alert alert-info">
      Nie zdefiniowano jeszcze żadnego typu obiegu.
      <?php if (is_admin()): ?><a href="<?= APP_URL ?>/admin/obiegi.php">Utwórz pierwszy obieg w panelu definicji.</a><?php else: ?>Skontaktuj się z administratorem.<?php endif; ?>
    </div>
  <?php endif; ?>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item">
      <a class="nav-link<?= $tab === 'inbox' ? ' active' : '' ?>" href="?tab=inbox">
        <i class="bi bi-inbox me-1"></i>Do mnie
        <?php if ($inbox): ?><span class="badge bg-danger ms-1"><?= count($inbox) ?></span><?php endif; ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link<?= $tab === 'mine' ? ' active' : '' ?>" href="?tab=mine">
        <i class="bi bi-person me-1"></i>Moje wnioski
        <?php if ($mine): ?><span class="badge bg-secondary ms-1"><?= count($mine) ?></span><?php endif; ?>
      </a>
    </li>
  </ul>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <?php if ($tab === 'inbox'): ?>
          <thead><tr><th>Wniosek</th><th>Wnioskodawca</th><th>Bieżący krok</th><th>Status</th><th>Złożono</th></tr></thead>
          <tbody>
            <?php if (!$inbox): ?>
              <tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-check2-circle me-1"></i>Brak wniosków oczekujących na Twoją decyzję.</td></tr>
            <?php else: foreach ($inbox as $r) echo _obiegi_row($r, true); endif; ?>
          </tbody>
        <?php else: ?>
          <thead><tr><th>Wniosek</th><th>Bieżący krok</th><th>Status</th><th>Złożono</th></tr></thead>
          <tbody>
            <?php if (!$mine): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">Nie złożyłeś jeszcze żadnego wniosku.</td></tr>
            <?php else: foreach ($mine as $r) echo _obiegi_row($r, false); endif; ?>
          </tbody>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
