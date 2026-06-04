<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();
require_login();

$u   = current_user();
$uid = (int)$u['id'];
$is_op = hd_is_operator();

// Filtry
$f_status   = $_GET['status'] ?? '';
$f_category = $_GET['category'] ?? '';
$f_q        = trim($_GET['q'] ?? '');
$f_view     = ($is_op && in_array($_GET['view'] ?? '', ['all','unassigned'], true))
            ? $_GET['view'] : ($is_op ? 'all' : 'mine');

$where  = ['1=1'];
$params = [];

if (!$is_op || $f_view === 'mine') {
    $where[]  = 'requester_id = ?';
    $params[] = $uid;
} elseif ($f_view === 'unassigned') {
    $where[]  = 'assigned_to IS NULL';
    $where[]  = "status NOT IN ('zamknięte')";
}

if ($f_status && isset(HD_STATUSES[$f_status])) {
    $where[] = 'status = ?'; $params[] = $f_status;
}
if ($f_category && isset(HD_CATEGORIES[$f_category])) {
    $where[] = 'category = ?'; $params[] = $f_category;
}
if ($f_q !== '') {
    $where[] = '(number LIKE ? OR title LIKE ? OR requester_name LIKE ?)';
    $like = '%' . $f_q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$where_sql = implode(' AND ', $where);

$tickets = db_all(
    "SELECT t.*, u.name AS assigned_name
     FROM helpdesk_tickets t
     LEFT JOIN users u ON u.id = t.assigned_to
     WHERE {$where_sql}
     ORDER BY
       CASE status WHEN 'krytyczny' THEN 0 WHEN 'nowe' THEN 1 WHEN 'otwarte' THEN 2 ELSE 3 END,
       t.updated_at DESC",
    $params
);

// Liczniki dla odznak
$cnt_unassigned = $is_op ? (int)(db_one(
    "SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE assigned_to IS NULL AND status NOT IN ('zamknięte')"
)['c'] ?? 0) : 0;
$cnt_mine_open  = (int)(db_one(
    "SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE requester_id=? AND status NOT IN ('zamknięte','rozwiązane')",
    [$uid]
)['c'] ?? 0);

$PAGE_TITLE = 'Helpdesk IT';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-headset text-primary me-2"></i>Helpdesk IT</h4>
    <div class="text-muted small">Zgłoszenia wsparcia technicznego</div>
  </div>
  <a href="<?= APP_URL ?>/helpdesk/new.php" class="btn btn-primary">
    <i class="bi bi-plus-lg me-1"></i>Nowe zgłoszenie
  </a>
</div>

<?= flash_html() ?>

<!-- Tabs widoku -->
<ul class="nav nav-tabs mb-3">
  <?php if ($is_op): ?>
  <li class="nav-item">
    <a class="nav-link <?= $f_view === 'all' ? 'active' : '' ?>"
       href="?view=all<?= $f_status ? '&status=' . urlencode($f_status) : '' ?>">
      <i class="bi bi-list-ul me-1"></i>Wszystkie
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $f_view === 'unassigned' ? 'active' : '' ?>"
       href="?view=unassigned">
      <i class="bi bi-inbox me-1"></i>Nieprzypisane
      <?php if ($cnt_unassigned): ?>
      <span class="badge bg-danger ms-1"><?= $cnt_unassigned ?></span>
      <?php endif; ?>
    </a>
  </li>
  <?php endif; ?>
  <li class="nav-item">
    <a class="nav-link <?= $f_view === 'mine' ? 'active' : '' ?>"
       href="?view=mine">
      <i class="bi bi-person me-1"></i><?= $is_op ? 'Moje' : 'Moje zgłoszenia' ?>
      <?php if (!$is_op && $cnt_mine_open): ?>
      <span class="badge bg-warning text-dark ms-1"><?= $cnt_mine_open ?></span>
      <?php endif; ?>
    </a>
  </li>
</ul>

<!-- Filtry -->
<form method="get" class="row g-2 mb-3">
  <input type="hidden" name="view" value="<?= h($f_view) ?>">
  <div class="col-sm-4 col-md-3">
    <input name="q" class="form-control form-control-sm" placeholder="Szukaj numeru, tytułu…"
           value="<?= h($f_q) ?>">
  </div>
  <div class="col-sm-3 col-md-2">
    <select name="status" class="form-select form-select-sm">
      <option value="">Wszystkie statusy</option>
      <?php foreach (HD_STATUSES as $k => $s): ?>
      <option value="<?= h($k) ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= h($s['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-3 col-md-2">
    <select name="category" class="form-select form-select-sm">
      <option value="">Wszystkie kategorie</option>
      <?php foreach (HD_CATEGORIES as $k => $v): ?>
      <option value="<?= h($k) ?>" <?= $f_category === $k ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-funnel me-1"></i>Filtruj</button>
    <?php if ($f_q || $f_status || $f_category): ?>
    <a href="?view=<?= h($f_view) ?>" class="btn btn-sm btn-link text-muted">Wyczyść</a>
    <?php endif; ?>
  </div>
</form>

<!-- Lista zgłoszeń -->
<?php if (!$tickets): ?>
<div class="text-center py-5 text-muted">
  <i class="bi bi-inbox" style="font-size:2.5rem"></i>
  <div class="mt-2">Brak zgłoszeń</div>
  <a href="<?= APP_URL ?>/helpdesk/new.php" class="btn btn-primary btn-sm mt-3">
    <i class="bi bi-plus-lg me-1"></i>Utwórz zgłoszenie
  </a>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.87rem">
      <thead class="table-light">
        <tr>
          <th style="width:100px">Numer</th>
          <th>Temat</th>
          <th>Kategoria</th>
          <th>Priorytet</th>
          <th>Status</th>
          <?php if ($is_op): ?><th>Zgłaszający</th><th>Przypisany</th><?php endif; ?>
          <th>Data</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tickets as $t): ?>
        <tr onclick="window.location='<?= APP_URL ?>/helpdesk/view.php?id=<?= $t['id'] ?>'"
            style="cursor:pointer" class="<?= $t['status'] === 'zamknięte' ? 'text-muted' : '' ?>">
          <td class="font-monospace fw-bold" style="font-size:.8rem">
            <?= h($t['number']) ?>
          </td>
          <td>
            <div class="fw-semibold"><?= h($t['title']) ?></div>
            <?php if ($t['description']): ?>
            <div class="text-muted small text-truncate" style="max-width:300px">
              <?= h(mb_substr($t['description'], 0, 80)) ?><?= mb_strlen($t['description']) > 80 ? '…' : '' ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="text-muted small"><?= h(HD_CATEGORIES[$t['category']] ?? $t['category']) ?></td>
          <td><?= hd_priority_badge($t['priority']) ?></td>
          <td><?= hd_status_badge($t['status']) ?></td>
          <?php if ($is_op): ?>
          <td class="text-muted small"><?= h($t['requester_name']) ?></td>
          <td class="small">
            <?= $t['assigned_name']
              ? '<span class="badge bg-light text-dark border">' . h($t['assigned_name']) . '</span>'
              : '<span class="text-danger small"><i class="bi bi-exclamation-circle me-1"></i>Brak</span>' ?>
          </td>
          <?php endif; ?>
          <td class="text-muted small text-nowrap"><?= date_pl(substr($t['updated_at'], 0, 10)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="text-muted small mt-2 text-end"><?= count($tickets) ?> zgłoszeń</div>
<?php endif; ?>

<?php if (is_admin()): ?>
<div class="mt-3 text-end">
  <a href="<?= APP_URL ?>/helpdesk/admin.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-gear me-1"></i>Ustawienia Helpdesk
  </a>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
