<?php
/**
 * crm/cases/index.php — Lista spraw CRM.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$PAGE_TITLE   = 'Sprawy CRM';
$can_write    = can_write('crm') || is_admin();

$search    = trim($_GET['q']       ?? '');
$status_f  = $_GET['status']       ?? '';
$priority_f= $_GET['priority']     ?? '';
$contact_f = (int)($_GET['contact_id'] ?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));
$per       = 25;

$where  = '1=1';
$params = [];
if ($search) {
    $where .= " AND (c.title LIKE ? OR ct.imie_nazwisko LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($status_f) {
    $where .= " AND c.status=?";
    $params[] = $status_f;
}
if ($priority_f) {
    $where .= " AND c.priority=?";
    $params[] = $priority_f;
}
if ($contact_f) {
    $where .= " AND c.contact_id=?";
    $params[] = $contact_f;
}

$total = (int)(db_one(
    "SELECT COUNT(*) AS n FROM crm_cases c
     LEFT JOIN crm_contacts ct ON ct.id=c.contact_id WHERE $where",
    $params
)['n'] ?? 0);

$offset = ($page - 1) * $per;
$rows   = db_all(
    "SELECT c.*, ct.imie_nazwisko AS contact_name, ct.type AS contact_type,
            (SELECT COUNT(*) FROM crm_case_notes n WHERE n.case_id=c.id) AS notes_count,
            (SELECT COUNT(*) FROM crm_case_files f WHERE f.case_id=c.id) AS files_count
     FROM crm_cases c
     LEFT JOIN crm_contacts ct ON ct.id=c.contact_id
     WHERE $where ORDER BY c.updated_at DESC LIMIT $per OFFSET $offset",
    $params
);

// Statystyki
$stats = [];
foreach (['open','in_progress','closed','cancelled'] as $s) {
    $stats[$s] = (int)(db_one("SELECT COUNT(*) AS n FROM crm_cases WHERE status=?", [$s])['n'] ?? 0);
}

$status_cfg = [
    'open'        => ['label'=>'Otwarta',     'color'=>'#2563EB','bg'=>'#EEF4FF','icon'=>'bi-circle'],
    'in_progress' => ['label'=>'W toku',      'color'=>'#D97706','bg'=>'#FEF3E2','icon'=>'bi-arrow-clockwise'],
    'closed'      => ['label'=>'Zamknięta',   'color'=>'#2E844A','bg'=>'#EFF7ED','icon'=>'bi-check-circle'],
    'cancelled'   => ['label'=>'Anulowana',   'color'=>'#9CA3AF','bg'=>'#F3F4F6','icon'=>'bi-x-circle'],
];
$priority_cfg = [
    'low'    => ['label'=>'Niski',   'color'=>'#6B7280'],
    'medium' => ['label'=>'Średni',  'color'=>'#D97706'],
    'high'   => ['label'=>'Wysoki',  'color'=>'#DC2626'],
];

include dirname(__DIR__) . '/includes/header_crm.php';
?>

<style>
.case-row { display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border-bottom:1px solid #F3F4F6;transition:background .1s;text-decoration:none;color:#111827 }
.case-row:hover { background:#F9FAFB }
.case-row:last-child { border-bottom:none }
.case-status-pill { display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:2rem;font-size:.72rem;font-weight:600;white-space:nowrap }
.case-priority-dot { width:8px;height:8px;border-radius:50%;flex-shrink:0 }
.case-meta { font-size:.76rem;color:#9CA3AF }
</style>

<!-- Nagłówek -->
<div class="crm-page-header">
  <div>
    <div class="crm-page-title"><i class="bi bi-briefcase-fill" style="color:#0176D3"></i> Sprawy</div>
    <div class="crm-page-subtitle">Zarządzanie sprawami powiązanymi z kontaktami</div>
  </div>
  <?php if ($can_write): ?>
  <div class="crm-page-actions">
    <a href="add.php" class="btn btn-crm-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Nowa sprawa
    </a>
  </div>
  <?php endif; ?>
</div>

<!-- Pasy statusów -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php
  $all_total = array_sum($stats);
  $qs = http_build_query(array_filter(['q'=>$search,'priority'=>$priority_f,'contact_id'=>$contact_f?:null]));
  ?>
  <a href="?<?= $qs ?>" class="wol-stat-pill <?= !$status_f?'selected':'' ?>"
     style="background:#F3F4F6;color:#374151;border-color:<?= !$status_f?'#374151':'transparent' ?>">
    Wszystkie <strong><?= $all_total ?></strong>
  </a>
  <?php foreach ($status_cfg as $sk=>$sv): if (!($stats[$sk]??0)) continue; ?>
  <a href="?<?= $qs ?>&status=<?= $sk ?>" class="wol-stat-pill <?= $status_f===$sk?'selected':'' ?>"
     style="background:<?= $sv['bg'] ?>;color:<?= $sv['color'] ?>;border-color:<?= $status_f===$sk?$sv['color']:'transparent' ?>">
    <i class="bi <?= $sv['icon'] ?>"></i> <?= $sv['label'] ?> <strong><?= $stats[$sk] ?></strong>
  </a>
  <?php endforeach; ?>
</div>

<!-- Filtry -->
<form method="get" class="wol-filter-bar mb-3">
  <?php if ($status_f): ?><input type="hidden" name="status" value="<?= h($status_f) ?>"><?php endif; ?>
  <div class="row g-2 align-items-center">
    <div class="col-md-5">
      <div class="input-group input-group-sm">
        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
        <input name="q" class="form-control" placeholder="Szukaj po tytule lub kontakcie…" value="<?= h($search) ?>">
      </div>
    </div>
    <div class="col-md-3">
      <select name="priority" class="form-select form-select-sm">
        <option value="">— wszystkie priorytety —</option>
        <?php foreach ($priority_cfg as $pk=>$pv): ?>
        <option value="<?= $pk ?>" <?= $priority_f===$pk?'selected':'' ?>><?= $pv['label'] ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel"></i> Filtruj</button>
      <?php if ($search||$status_f||$priority_f||$contact_f): ?>
      <a href="?" class="btn btn-outline-secondary btn-sm ms-1"><i class="bi bi-x"></i></a>
      <?php endif; ?>
    </div>
  </div>
</form>

<!-- Lista spraw -->
<div class="card shadow-sm">
  <?php if (!$rows): ?>
  <div class="text-center py-5">
    <i class="bi bi-briefcase display-4 text-secondary opacity-25 d-block mb-3"></i>
    <h5 class="text-muted">Brak spraw</h5>
    <p class="text-muted small mb-3"><?= $search||$status_f||$priority_f ? 'Zmień filtry.' : 'Nie dodano jeszcze żadnych spraw.' ?></p>
    <?php if ($can_write && !$search && !$status_f): ?>
    <a href="add.php" class="btn btn-crm-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Dodaj sprawę</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <?php foreach ($rows as $r):
    $sc = $status_cfg[$r['status']] ?? $status_cfg['open'];
    $pc = $priority_cfg[$r['priority']] ?? $priority_cfg['medium'];
  ?>
  <a href="view.php?id=<?= (int)$r['id'] ?>" class="case-row"
     aria-label="<?= h($r['title']) ?><?= $r['contact_name'] ? ', '.h($r['contact_name']) : '' ?> — <?= h($sc['label']) ?>, priorytet: <?= h($pc['label']) ?>">
    <div class="case-priority-dot" style="background:<?= $pc['color'] ?>" aria-hidden="true">
      <span class="visually-hidden">Priorytet: <?= h($pc['label']) ?></span>
    </div>
    <div style="flex:1;min-width:0">
      <div class="fw-semibold" style="font-size:.88rem;color:#111827" aria-hidden="true"><?= h($r['title']) ?></div>
      <div class="case-meta" aria-hidden="true">
        <i class="bi bi-person me-1" aria-hidden="true"></i><?= h($r['contact_name'] ?? '—') ?>
        <?php if ($r['notes_count']): ?>
        <span class="ms-2"><i class="bi bi-chat me-1" aria-hidden="true"></i><?= $r['notes_count'] ?></span>
        <?php endif; ?>
        <?php if ($r['files_count']): ?>
        <span class="ms-2"><i class="bi bi-paperclip me-1" aria-hidden="true"></i><?= $r['files_count'] ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-shrink-0" aria-hidden="true">
      <span class="case-status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
        <i class="bi <?= $sc['icon'] ?>" aria-hidden="true"></i><?= $sc['label'] ?>
      </span>
      <span class="case-meta d-none d-sm-inline" style="white-space:nowrap">
        <?= date_pl($r['updated_at']) ?>
      </span>
      <i class="bi bi-chevron-right text-muted opacity-40" style="font-size:.7rem" aria-hidden="true"></i>
    </div>
  </a>
  <?php endforeach; ?>

  <!-- Paginacja -->
  <?php if ($total > $per): ?>
  <div class="card-footer d-flex justify-content-between align-items-center py-2" style="background:#FAFAFA">
    <small class="text-muted">Łącznie: <strong><?= $total ?></strong></small>
    <div class="d-flex gap-1">
      <?php
      $pages = ceil($total / $per);
      $base  = '?' . http_build_query(array_filter(['q'=>$search,'status'=>$status_f,'priority'=>$priority_f,'contact_id'=>$contact_f?:null]));
      for ($p = 1; $p <= $pages; $p++):
      ?>
      <a href="<?= $base ?>&page=<?= $p ?>" class="btn btn-sm <?= $p===$page?'btn-primary':'btn-outline-secondary' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<style>
.wol-stat-pill { display:inline-flex;align-items:center;gap:.4rem;padding:.35rem .85rem;border-radius:2rem;font-size:.78rem;font-weight:600;text-decoration:none;cursor:pointer;border:2px solid transparent;transition:all .12s }
.wol-filter-bar { background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:.75rem 1rem }
</style>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
