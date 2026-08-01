<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');
if (!defined('TZ_ADMIN_CHROME')) {
    header('Location: ' . APP_URL . '/tozsamosc/audit.php');
    exit;
}
$PAGE_TITLE = 'Dziennik zdarzeń';
$TZ_ACTIVE = 'administracja';
require_once dirname(__DIR__) . '/tozsamosc/_head.php';
?>
<style>.tz-wrap{max-width:1200px}</style>
<?php

$pdo = db();

// ── Filters ──────────────────────────────────────────────────────────────────
$f_user     = trim($_GET['user']     ?? '');
$f_action   = trim($_GET['action']   ?? '');
$f_module   = trim($_GET['module']   ?? '');
$f_date_from = trim($_GET['date_from'] ?? '');
$f_date_to   = trim($_GET['date_to']   ?? '');
$f_q        = trim($_GET['q']        ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 50;

// Distinct values for selects
$actions = $pdo->query("SELECT DISTINCT action FROM admin_audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$modules = $pdo->query("SELECT DISTINCT module FROM admin_audit_log WHERE module IS NOT NULL AND module != '' ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($f_user !== '') {
    $where[]  = "(user_name LIKE ? OR user_email LIKE ?)";
    $params[] = "%{$f_user}%";
    $params[] = "%{$f_user}%";
}
if ($f_action !== '') {
    $where[]  = "action = ?";
    $params[] = $f_action;
}
if ($f_module !== '') {
    $where[]  = "module = ?";
    $params[] = $f_module;
}
if ($f_date_from !== '') {
    $where[]  = "created_at >= ?";
    $params[] = $f_date_from . ' 00:00:00';
}
if ($f_date_to !== '') {
    $where[]  = "created_at <= ?";
    $params[] = $f_date_to . ' 23:59:59';
}
if ($f_q !== '') {
    $where[]  = "(action LIKE ? OR module LIKE ? OR details LIKE ? OR target_label LIKE ?)";
    $params[] = "%{$f_q}%";
    $params[] = "%{$f_q}%";
    $params[] = "%{$f_q}%";
    $params[] = "%{$f_q}%";
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── CSV export ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $pdo->prepare("SELECT * FROM admin_audit_log {$where_sql} ORDER BY id DESC");
    $rows->execute($params);
    $all = $rows->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM for Excel
    fputcsv($out, ['ID', 'Czas', 'Użytkownik', 'Email', 'Akcja', 'Moduł', 'ID obiektu', 'Obiekt', 'Szczegóły', 'IP', 'User-Agent'], ';');
    foreach ($all as $r) {
        fputcsv($out, [
            $r['id'],
            $r['created_at'],
            $r['user_name'],
            $r['user_email'],
            $r['action'],
            $r['module'],
            $r['target_id'],
            $r['target_label'],
            $r['details'],
            $r['ip'],
            $r['user_agent'],
        ], ';');
    }
    fclose($out);
    exit;
}

// ── Count & paginate ──────────────────────────────────────────────────────────
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_audit_log {$where_sql}");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));
$page  = min($page, $pages);
$offset = ($page - 1) * $per_page;

$rows_stmt = $pdo->prepare("SELECT * FROM admin_audit_log {$where_sql} ORDER BY id DESC LIMIT {$per_page} OFFSET {$offset}");
$rows_stmt->execute($params);
$rows = $rows_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Action badge helper ───────────────────────────────────────────────────────
function audit_action_badge(string $action): string {
    $a = strtolower($action);
    if (str_contains($a, 'delete') || str_contains($a, 'remove') || str_contains($a, 'usun')) {
        $cls = 'danger';
    } elseif (str_contains($a, 'create') || str_contains($a, 'add') || str_contains($a, 'dodaj') || str_contains($a, 'utw')) {
        $cls = 'success';
    } elseif (str_contains($a, 'edit') || str_contains($a, 'update') || str_contains($a, 'edyt') || str_contains($a, 'zmien')) {
        $cls = 'warning';
    } elseif (str_contains($a, 'login') || str_contains($a, 'logout') || str_contains($a, 'logon') || str_contains($a, 'log')) {
        $cls = 'info';
    } else {
        $cls = 'secondary';
    }
    return '<span class="badge text-bg-' . $cls . '">' . h($action) . '</span>';
}

// ── Current query string helper for pagination links ─────────────────────────
function audit_qs(array $override = []): string {
    $params = array_merge($_GET, $override);
    unset($params['export']);
    return '?' . http_build_query($params);
}
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-journal-text me-2 text-primary"></i>Audit log</h4>
  <a href="<?= audit_qs(['export' => 'csv', 'page' => null]) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-download me-1"></i>Eksport CSV
  </a>
</div>

<!-- ── Filters ─────────────────────────────────────────────────────────────── -->
<form method="get" class="card mb-3 border-0 shadow-sm">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-sm-6 col-md-3">
        <label class="form-label form-label-sm mb-1">Użytkownik</label>
        <input type="text" name="user" class="form-control form-control-sm"
               value="<?= h($f_user) ?>" placeholder="Imię lub e-mail">
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label form-label-sm mb-1">Akcja</label>
        <select name="action" class="form-select form-select-sm">
          <option value="">— wszystkie —</option>
          <?php foreach ($actions as $a): ?>
          <option value="<?= h($a) ?>" <?= $f_action === $a ? 'selected' : '' ?>><?= h($a) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label form-label-sm mb-1">Moduł</label>
        <select name="module" class="form-select form-select-sm">
          <option value="">— wszystkie —</option>
          <?php foreach ($modules as $m): ?>
          <option value="<?= h($m) ?>" <?= $f_module === $m ? 'selected' : '' ?>><?= h($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label form-label-sm mb-1">Od daty</label>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($f_date_from) ?>">
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label form-label-sm mb-1">Do daty</label>
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($f_date_to) ?>">
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label form-label-sm mb-1">Szukaj (szczegóły)</label>
        <input type="text" name="q" class="form-control form-control-sm"
               value="<?= h($f_q) ?>" placeholder="Słowo kluczowe…">
      </div>
      <div class="col-sm-auto d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filtruj</button>
        <a href="<?= APP_URL ?>/admin/audit_log.php" class="btn btn-outline-secondary btn-sm">Wyczyść</a>
      </div>
    </div>
  </div>
</form>

<!-- ── Results info ─────────────────────────────────────────────────────────── -->
<p class="text-muted small mb-2">
  Znaleziono: <strong><?= number_format($total, 0, ',', ' ') ?></strong> wpisów
  <?php if ($pages > 1): ?> · strona <?= $page ?> z <?= $pages ?><?php endif; ?>
</p>

<!-- ── Table ────────────────────────────────────────────────────────────────── -->
<div class="table-responsive">
<table class="table table-sm table-hover table-bordered align-middle" style="font-size:.84rem">
  <thead class="table-light">
    <tr>
      <th style="width:130px">Czas</th>
      <th>Użytkownik</th>
      <th style="width:160px">Akcja</th>
      <th style="width:120px">Moduł</th>
      <th>Szczegóły</th>
      <th style="width:130px">IP</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($rows)): ?>
    <tr>
      <td colspan="6" class="text-center text-muted py-4">
        <i class="bi bi-inbox d-block mb-1" style="font-size:1.8rem;opacity:.3"></i>
        Brak wpisów spełniających kryteria.
      </td>
    </tr>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td class="text-muted font-monospace" style="font-size:.78rem;white-space:nowrap">
        <?= h($r['created_at']) ?>
      </td>
      <td>
        <div class="fw-semibold"><?= h($r['user_name'] ?: '—') ?></div>
        <?php if ($r['user_email']): ?>
        <div class="text-muted" style="font-size:.76rem"><?= h($r['user_email']) ?></div>
        <?php endif; ?>
      </td>
      <td><?= audit_action_badge($r['action']) ?></td>
      <td>
        <?php if ($r['module']): ?>
        <span class="badge text-bg-light border"><?= h($r['module']) ?></span>
        <?php endif; ?>
        <?php if ($r['target_label']): ?>
        <div class="text-muted" style="font-size:.76rem"><?= h($r['target_label']) ?></div>
        <?php endif; ?>
      </td>
      <td class="text-muted" style="max-width:280px;word-break:break-word">
        <?= h($r['details'] ?? '') ?>
      </td>
      <td class="font-monospace text-muted" style="font-size:.76rem;white-space:nowrap">
        <?= h($r['ip'] ?? '') ?>
      </td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>
</div>

<!-- ── Pagination ────────────────────────────────────────────────────────────── -->
<?php if ($pages > 1): ?>
<nav aria-label="Paginacja audit logu">
  <ul class="pagination pagination-sm justify-content-center flex-wrap">
    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= audit_qs(['page' => $page - 1]) ?>">
        <i class="bi bi-chevron-left"></i>
      </a>
    </li>
    <?php
    $range_start = max(1, $page - 3);
    $range_end   = min($pages, $page + 3);
    if ($range_start > 1): ?>
    <li class="page-item"><a class="page-link" href="<?= audit_qs(['page' => 1]) ?>">1</a></li>
    <?php if ($range_start > 2): ?>
    <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>
    <?php endif; ?>

    <?php for ($p = $range_start; $p <= $range_end; $p++): ?>
    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
      <a class="page-link" href="<?= audit_qs(['page' => $p]) ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>

    <?php if ($range_end < $pages): ?>
    <?php if ($range_end < $pages - 1): ?>
    <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>
    <li class="page-item"><a class="page-link" href="<?= audit_qs(['page' => $pages]) ?>"><?= $pages ?></a></li>
    <?php endif; ?>

    <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= audit_qs(['page' => $page + 1]) ?>">
        <i class="bi bi-chevron-right"></i>
      </a>
    </li>
  </ul>
</nav>
<?php endif; ?>

<?php include dirname(__DIR__) . '/tozsamosc/_foot.php'; ?>
