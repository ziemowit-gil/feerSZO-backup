<?php
/**
 * crm/cases/index.php — Lista spraw CRM.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_perms.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('cases', 'read');
crm_migrate();

$PAGE_TITLE   = 'Sprawy CRM';
$can_write    = can_write('crm') || is_admin();

$uid       = (int)(current_user()['id'] ?? 0);
$search    = trim($_GET['q']       ?? '');
$status_f  = $_GET['status']       ?? '';
$priority_f= $_GET['priority']     ?? '';
$contact_f = (int)($_GET['contact_id'] ?? 0);
$shared_f  = !empty($_GET['shared']);
// Filtry prowadzenia: „moje", „bez opiekuna", „po terminie", typ sprawy.
// Bez nich opiekun (owner_id) był kolumną, na którą nikt nie mógł spojrzeć.
$mine_f    = !empty($_GET['mine']);
$noowner_f = !empty($_GET['noowner']);
$overdue_f = !empty($_GET['overdue']);
$type_f    = (int)($_GET['type_id'] ?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));
$per       = 25;

$where  = '1=1';
$params = [];
if ($shared_f) {
    $where .= " AND EXISTS (SELECT 1 FROM crm_case_shares s WHERE s.case_id=c.id AND s.user_id=?)";
    $params[] = $uid;
}
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
if ($mine_f) {
    $where .= " AND c.owner_id=?";
    $params[] = $uid;
}
if ($noowner_f) {
    $where .= " AND (c.owner_id IS NULL OR c.owner_id=0)";
}
if ($overdue_f) {
    // Po terminie = tylko sprawy w toku; zamkniętej nikt już nie „spóźni"
    $where .= " AND c.due_date IS NOT NULL AND c.due_date <> '' AND date(c.due_date) < date('now')"
            . " AND c.status IN ('open','in_progress')";
}
if ($type_f) {
    $where .= " AND c.type_id=?";
    $params[] = $type_f;
}

$total = (int)(db_one(
    "SELECT COUNT(*) AS n FROM crm_cases c
     LEFT JOIN crm_contacts ct ON ct.id=c.contact_id WHERE $where",
    $params
)['n'] ?? 0);

$offset = ($page - 1) * $per;
$rows   = db_all(
    "SELECT c.*, ct.imie_nazwisko AS contact_name, ct.type AS contact_type, u.name AS owner_name,
            (SELECT COUNT(*) FROM crm_case_notes n WHERE n.case_id=c.id) AS notes_count,
            (SELECT COUNT(*) FROM crm_case_files f WHERE f.case_id=c.id) AS files_count,
            (SELECT COUNT(*) FROM crm_case_shares s WHERE s.case_id=c.id) AS shares_count
     FROM crm_cases c
     LEFT JOIN crm_contacts ct ON ct.id=c.contact_id
     LEFT JOIN users u         ON u.id=c.owner_id
     WHERE $where ORDER BY c.updated_at DESC LIMIT $per OFFSET $offset",
    $params
);

// Liczniki filtrów prowadzenia — pokazujemy je na pigułkach, żeby było widać,
// czy w ogóle jest co filtrować.
$cnt_mine = $cnt_noowner = $cnt_overdue = 0;
try {
    $cnt_mine = (int)(db_one("SELECT COUNT(*) AS n FROM crm_cases
                               WHERE owner_id=? AND status IN ('open','in_progress')", [$uid])['n'] ?? 0);
    $cnt_noowner = (int)(db_one("SELECT COUNT(*) AS n FROM crm_cases
                                  WHERE (owner_id IS NULL OR owner_id=0) AND status IN ('open','in_progress')")['n'] ?? 0);
    $cnt_overdue = (int)(db_one("SELECT COUNT(*) AS n FROM crm_cases
                                  WHERE due_date IS NOT NULL AND due_date <> ''
                                    AND date(due_date) < date('now')
                                    AND status IN ('open','in_progress')")['n'] ?? 0);
} catch (\Throwable $e) {}

require_once dirname(dirname(__DIR__)) . '/includes/crm_case_extras.php';
$case_types_list = crm_case_types();

// Statystyki
$stats = [];
foreach (['open','in_progress','closed','cancelled'] as $s) {
    $stats[$s] = (int)(db_one("SELECT COUNT(*) AS n FROM crm_cases WHERE status=?", [$s])['n'] ?? 0);
}
$shared_count = (int)(db_one(
    "SELECT COUNT(DISTINCT case_id) AS n FROM crm_case_shares WHERE user_id=?", [$uid]
)['n'] ?? 0);

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

<!-- Filtry prowadzenia: kto prowadzi i co się pali -->
<?php
  $base_qs = static function (array $over = []) use ($search, $status_f, $priority_f, $contact_f, $shared_f, $mine_f, $noowner_f, $overdue_f, $type_f): string {
      return http_build_query(array_filter(array_merge([
          'q' => $search ?: null, 'status' => $status_f ?: null, 'priority' => $priority_f ?: null,
          'contact_id' => $contact_f ?: null, 'shared' => $shared_f ? 1 : null,
          'mine' => $mine_f ? 1 : null, 'noowner' => $noowner_f ? 1 : null,
          'overdue' => $overdue_f ? 1 : null, 'type_id' => $type_f ?: null,
      ], $over), static fn($v) => $v !== null && $v !== ''));
  };
?>
<div class="d-flex flex-wrap gap-2 mb-2 align-items-center">
  <a href="?<?= $base_qs(['mine' => $mine_f ? null : 1]) ?>" class="wol-stat-pill <?= $mine_f ? 'selected' : '' ?>"
     style="background:<?= $mine_f ? '#EEF4FF' : '#F9FAFB' ?>;color:#1D4ED8;border-color:<?= $mine_f ? '#1D4ED8' : 'transparent' ?>"
     title="Sprawy, które prowadzisz (otwarte i w toku)">
    <i class="bi bi-person-check me-1"></i>Moje sprawy <strong><?= $cnt_mine ?></strong>
  </a>
  <a href="?<?= $base_qs(['noowner' => $noowner_f ? null : 1]) ?>" class="wol-stat-pill <?= $noowner_f ? 'selected' : '' ?>"
     style="background:<?= $noowner_f ? '#FFF7ED' : '#F9FAFB' ?>;color:#B45309;border-color:<?= $noowner_f ? '#B45309' : 'transparent' ?>"
     title="Sprawy bez przypisanego prowadzącego — nikt ich nie pilnuje">
    <i class="bi bi-person-dash me-1"></i>Bez opiekuna <strong><?= $cnt_noowner ?></strong>
  </a>
  <a href="?<?= $base_qs(['overdue' => $overdue_f ? null : 1]) ?>" class="wol-stat-pill <?= $overdue_f ? 'selected' : '' ?>"
     style="background:<?= $overdue_f ? '#FEF2F2' : '#F9FAFB' ?>;color:#B91C1C;border-color:<?= $overdue_f ? '#B91C1C' : 'transparent' ?>"
     title="Termin minął, a sprawa dalej otwarta">
    <i class="bi bi-alarm me-1"></i>Po terminie <strong><?= $cnt_overdue ?></strong>
  </a>

  <?php if ($case_types_list): ?>
  <form method="get" class="d-inline-flex align-items-center gap-1 ms-auto">
    <?php foreach (['q'=>$search,'status'=>$status_f,'priority'=>$priority_f,'contact_id'=>$contact_f,
                    'shared'=>$shared_f?1:'','mine'=>$mine_f?1:'','noowner'=>$noowner_f?1:'','overdue'=>$overdue_f?1:''] as $k => $v):
          if ($v === '' || $v === 0) continue; ?>
    <input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>">
    <?php endforeach; ?>
    <label class="text-muted small mb-0" for="type_id">Typ:</label>
    <select name="type_id" id="type_id" class="form-select form-select-sm" style="max-width:190px"
            onchange="this.form.submit()">
      <option value="">wszystkie</option>
      <?php foreach ($case_types_list as $ct): ?>
      <option value="<?= (int)$ct['id'] ?>" <?= $type_f === (int)$ct['id'] ? 'selected' : '' ?>><?= h($ct['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
</div>

<!-- Pasy statusów -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php
  $all_total = array_sum($stats);
  $qs = http_build_query(array_filter(['q'=>$search,'priority'=>$priority_f,'contact_id'=>$contact_f?:null,'shared'=>$shared_f?1:null]));
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
  <?php if ($shared_count || $shared_f):
    $qs_share = http_build_query(array_filter(['q'=>$search,'status'=>$status_f,'priority'=>$priority_f,'contact_id'=>$contact_f?:null]));
  ?>
  <a href="?<?= $qs_share ?><?= $shared_f?'':'&shared=1' ?>" class="wol-stat-pill <?= $shared_f?'selected':'' ?>"
     style="background:#EEF2FF;color:#4338CA;border-color:<?= $shared_f?'#4338CA':'transparent' ?>">
    <i class="bi bi-people-fill"></i> Udostępnione mi <strong><?= $shared_count ?></strong>
  </a>
  <?php endif; ?>
</div>

<!-- Filtry -->
<form method="get" class="wol-filter-bar mb-3">
  <?php if ($status_f): ?><input type="hidden" name="status" value="<?= h($status_f) ?>"><?php endif; ?>
  <?php if ($shared_f): ?><input type="hidden" name="shared" value="1"><?php endif; ?>
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
      <?php if ($search||$status_f||$priority_f||$contact_f||$shared_f): ?>
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
      <?php if (!empty($r['case_number'])): ?>
      <div style="font-family:monospace;font-size:.7rem;font-weight:700;color:#1D4ED8;letter-spacing:.05em;margin-bottom:.1rem" aria-hidden="true"><?= h($r['case_number']) ?></div>
      <?php endif; ?>
      <div class="fw-semibold" style="font-size:.88rem;color:#111827" aria-hidden="true">
        <?= h($r['title']) ?>
      </div>
      <div class="case-meta" aria-hidden="true">
        <i class="bi bi-person me-1" aria-hidden="true"></i><?= h($r['contact_name'] ?? '—') ?>
        <?php if ($r['notes_count']): ?>
        <span class="ms-2"><i class="bi bi-chat me-1" aria-hidden="true"></i><?= $r['notes_count'] ?></span>
        <?php endif; ?>
        <?php if ($r['files_count']): ?>
        <span class="ms-2"><i class="bi bi-paperclip me-1" aria-hidden="true"></i><?= $r['files_count'] ?></span>
        <?php endif; ?>
        <?php if ($r['shares_count']): ?>
        <span class="ms-2" style="color:#4338CA"><i class="bi bi-people-fill me-1" aria-hidden="true"></i><?= $r['shares_count'] ?></span>
        <?php endif; ?>
        <?php if (!empty($r['owner_name'])): ?>
        <span class="ms-2" title="Prowadzi sprawę"><i class="bi bi-person-check me-1" aria-hidden="true"></i><?= h($r['owner_name']) ?></span>
        <?php endif; ?>
        <?php if (!empty($r['due_date']) && in_array($r['status'], ['open','in_progress'], true)):
              $dl = (int)floor((strtotime((string)$r['due_date']) - time()) / 86400); ?>
        <span class="ms-2" style="<?= $dl < 0 ? 'color:#B91C1C;font-weight:600' : ($dl <= 2 ? 'color:#B45309' : '') ?>"
              title="Termin sprawy">
          <i class="bi bi-alarm me-1" aria-hidden="true"></i>
          <?= $dl < 0 ? 'po terminie o ' . abs($dl) . ' dni' : ($dl === 0 ? 'termin dziś' : 'za ' . $dl . ' dni') ?>
        </span>
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
      $base  = '?' . $base_qs();
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
