<?php
/**
 * admin/api_audit.php — Rejestr operacji zapisu wykonanych przez API (RODO).
 * Pokazuje kto (klucz), co, kiedy i jakie pola utworzył/zmienił/usunął.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/api_auth.php';

require_role('admin');
api_auth_migrate();

$PAGE_TITLE = 'Audyt API';

// ── Filtry ───────────────────────────────────────────────────────────────────
$f_key    = (int)($_GET['key_id'] ?? 0);
$f_action = in_array($_GET['action'] ?? '', ['create','update','delete'], true) ? $_GET['action'] : '';
$f_res    = trim($_GET['resource'] ?? '');
$f_from   = trim($_GET['from'] ?? '');
$f_to     = trim($_GET['to'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per      = 50;
$off      = ($page - 1) * $per;

$where = []; $params = [];
if ($f_key)         { $where[] = 'key_id = ?';   $params[] = $f_key; }
if ($f_action)      { $where[] = 'action = ?';   $params[] = $f_action; }
if ($f_res !== '')  { $where[] = 'resource = ?'; $params[] = $f_res; }
if ($f_from !== '') { $where[] = 'created_at >= ?'; $params[] = substr($f_from,0,10) . ' 00:00:00'; }
if ($f_to !== '')   { $where[] = 'created_at <= ?'; $params[] = substr($f_to,0,10) . ' 23:59:59'; }
$sql_where = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int)(db_one("SELECT COUNT(*) c FROM api_audit_log $sql_where", $params)['c'] ?? 0);
$rows  = db_all("SELECT * FROM api_audit_log $sql_where ORDER BY id DESC LIMIT ? OFFSET ?",
                array_merge($params, [$per, $off]));
$keys  = db_all("SELECT id, name FROM api_keys ORDER BY name");
$pages = max(1, (int)ceil($total / $per));

$act_badge = ['create' => 'success', 'update' => 'warning', 'delete' => 'danger'];

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-journal-text me-2"></i><?= h($PAGE_TITLE) ?></h4>
  <span class="badge bg-secondary"><?= $total ?> wpisów</span>
  <a href="<?= APP_URL ?>/admin/api_manage.php?tab=api" class="btn btn-outline-secondary btn-sm ms-auto">
    <i class="bi bi-key me-1"></i>Klucze API
  </a>
</div>

<p class="text-muted small">Rejestr operacji <strong>zapisu</strong> (utwórz / zmień / usuń) wykonanych przez API.
Zapisywane są <strong>nazwy pól</strong>, nie ich wartości — zgodnie z zasadą minimalizacji danych (RODO).</p>

<form method="get" class="card card-body mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-sm-3">
      <label class="form-label small mb-1">Klucz API</label>
      <select name="key_id" class="form-select form-select-sm">
        <option value="0">— wszystkie —</option>
        <?php foreach ($keys as $k): ?>
        <option value="<?= (int)$k['id'] ?>" <?= $f_key===(int)$k['id']?'selected':'' ?>><?= h($k['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-2">
      <label class="form-label small mb-1">Operacja</label>
      <select name="action" class="form-select form-select-sm">
        <option value="">— wszystkie —</option>
        <?php foreach (['create'=>'utwórz','update'=>'zmień','delete'=>'usuń'] as $a=>$lab): ?>
        <option value="<?= $a ?>" <?= $f_action===$a?'selected':'' ?>><?= $lab ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-2">
      <label class="form-label small mb-1">Zasób</label>
      <input type="text" name="resource" value="<?= h($f_res) ?>" class="form-control form-control-sm" placeholder="np. clients">
    </div>
    <div class="col-sm-2">
      <label class="form-label small mb-1">Od</label>
      <input type="date" name="from" value="<?= h($f_from) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-sm-2">
      <label class="form-label small mb-1">Do</label>
      <input type="date" name="to" value="<?= h($f_to) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-sm-1">
      <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i></button>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>Czas</th><th>Klucz</th><th>Operacja</th><th>Zasób</th><th>ID</th><th>Pola</th><th>Status</th><th>IP</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Brak wpisów.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="small text-nowrap"><?= h($r['created_at']) ?></td>
          <td class="small"><?= h($r['key_name'] ?: ('#'.$r['key_id'])) ?></td>
          <td><span class="badge text-bg-<?= $act_badge[$r['action']] ?? 'secondary' ?>"><?= h($r['action']) ?></span></td>
          <td class="small"><code><?= h($r['resource']) ?></code></td>
          <td class="small"><?= $r['record_id'] ? (int)$r['record_id'] : '—' ?></td>
          <td class="small text-muted" style="max-width:260px"><?= h($r['fields'] ?: '—') ?></td>
          <td class="small"><?= (int)$r['status'] ?></td>
          <td class="small text-muted"><?= h($r['ip']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm">
  <?php
  $qs = $_GET;
  for ($p = 1; $p <= $pages; $p++):
    $qs['page'] = $p;
  ?>
  <li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?<?= h(http_build_query($qs)) ?>"><?= $p ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
