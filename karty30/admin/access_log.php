<?php
/**
 * karty30/admin/access_log.php — Rejestr dostępu do danych wrażliwych (RODO).
 * Kto, kiedy i do których danych (beneficjent / konsultacja / wizyta) miał dostęp.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
if (!is_admin()) {
    flash_set('danger', 'Tylko administrator ma dostęp do rejestru.');
    header('Location: ' . APP_URL . '/karty30/index.php');
    exit;
}
k30_access_log_migrate();

$ENT = ['client' => 'Beneficjent', 'consultation' => 'Konsultacja', 'schedule' => 'Wizyta'];
$ACT = ['view' => 'Wgląd', 'edit' => 'Edycja', 'print' => 'Wydruk', 'export' => 'Eksport'];

// ── Filtry ───────────────────────────────────────────────────────────────────
$f_user = (int)($_GET['user_id'] ?? 0);
$f_ent  = isset($ENT[$_GET['entity_type'] ?? '']) ? $_GET['entity_type'] : '';
$f_act  = isset($ACT[$_GET['action'] ?? '']) ? $_GET['action'] : '';
$f_eid  = (int)($_GET['entity_id'] ?? 0);
$f_from = trim($_GET['from'] ?? '');
$f_to   = trim($_GET['to'] ?? '');

$where = []; $params = [];
if ($f_user)        { $where[] = 'user_id = ?';     $params[] = $f_user; }
if ($f_ent !== '')  { $where[] = 'entity_type = ?'; $params[] = $f_ent; }
if ($f_act !== '')  { $where[] = 'action = ?';      $params[] = $f_act; }
if ($f_eid)         { $where[] = 'entity_id = ?';   $params[] = $f_eid; }
if ($f_from !== '') { $where[] = 'created_at >= ?'; $params[] = substr($f_from,0,10) . ' 00:00:00'; }
if ($f_to !== '')   { $where[] = 'created_at <= ?'; $params[] = substr($f_to,0,10) . ' 23:59:59'; }
$sql_where = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── Eksport CSV ──────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $all = db_all("SELECT * FROM k30_access_log $sql_where ORDER BY id DESC LIMIT 10000", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rejestr_dostepu_k30_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // BOM dla Excela
    fputcsv($out, ['Czas', 'Użytkownik', 'ID użytk.', 'Typ', 'ID rekordu', 'Etykieta', 'Akcja', 'IP']);
    foreach ($all as $r) {
        fputcsv($out, [
            $r['created_at'], $r['user_name'], $r['user_id'],
            $ENT[$r['entity_type']] ?? $r['entity_type'], $r['entity_id'],
            $r['entity_label'], $ACT[$r['action']] ?? $r['action'], $r['ip'],
        ]);
    }
    fclose($out);
    exit;
}

$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 60;
$off   = ($page - 1) * $per;
$total = (int)(db_one("SELECT COUNT(*) c FROM k30_access_log $sql_where", $params)['c'] ?? 0);
$rows  = db_all("SELECT * FROM k30_access_log $sql_where ORDER BY id DESC LIMIT ? OFFSET ?",
                array_merge($params, [$per, $off]));
$pages = max(1, (int)ceil($total / $per));
$users = db_all("SELECT DISTINCT user_id, user_name FROM k30_access_log WHERE user_id IS NOT NULL ORDER BY user_name");

$act_badge = ['view' => 'secondary', 'edit' => 'warning', 'print' => 'info', 'export' => 'dark'];

$PAGE_TITLE = 'Rejestr dostępu (RODO) — Dydaktyka 3';
include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka</a></li>
  <li class="breadcrumb-item active">Rejestr dostępu (RODO)</li>
</ol></nav>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-shield-lock text-primary me-2"></i>Rejestr dostępu do danych wrażliwych</h4>
  <span class="badge bg-secondary"><?= $total ?> wpisów</span>
  <a href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>" class="btn btn-outline-secondary btn-sm ms-auto">
    <i class="bi bi-filetype-csv me-1"></i>Eksport CSV
  </a>
</div>

<p class="text-body-secondary small">Zapis wglądu, edycji i wydruku kart beneficjentów, konsultacji i wizyt — na potrzeby
rozliczalności RODO i kontroli (np. PFRON). Rejestr zawiera kto, co i kiedy — bez kopiowania samych danych.</p>

<form method="get" class="card card-body mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-sm-3">
      <label class="form-label small mb-1">Użytkownik</label>
      <select name="user_id" class="form-select form-select-sm">
        <option value="0">— wszyscy —</option>
        <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['user_id'] ?>" <?= $f_user===(int)$u['user_id']?'selected':'' ?>><?= h($u['user_name'] ?: ('#'.$u['user_id'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-2">
      <label class="form-label small mb-1">Typ danych</label>
      <select name="entity_type" class="form-select form-select-sm">
        <option value="">— wszystkie —</option>
        <?php foreach ($ENT as $k=>$lab): ?><option value="<?= $k ?>" <?= $f_ent===$k?'selected':'' ?>><?= h($lab) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-2">
      <label class="form-label small mb-1">Akcja</label>
      <select name="action" class="form-select form-select-sm">
        <option value="">— wszystkie —</option>
        <?php foreach ($ACT as $k=>$lab): ?><option value="<?= $k ?>" <?= $f_act===$k?'selected':'' ?>><?= h($lab) ?></option><?php endforeach; ?>
      </select>
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

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>Czas</th><th>Użytkownik</th><th>Akcja</th><th>Typ</th><th>Rekord</th><th>IP</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Brak wpisów.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="small text-nowrap"><?= h($r['created_at']) ?></td>
          <td class="small"><?= h($r['user_name'] ?: ('#'.$r['user_id'])) ?></td>
          <td><span class="badge text-bg-<?= $act_badge[$r['action']] ?? 'secondary' ?>"><?= h($ACT[$r['action']] ?? $r['action']) ?></span></td>
          <td class="small"><?= h($ENT[$r['entity_type']] ?? $r['entity_type']) ?></td>
          <td class="small"><?= h($r['entity_label'] ?: '') ?> <span class="text-muted">#<?= (int)$r['entity_id'] ?></span></td>
          <td class="small text-muted"><?= h($r['ip']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm">
  <?php $qs = $_GET; for ($p = 1; $p <= min($pages, 50); $p++): $qs['page'] = $p; ?>
  <li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?<?= h(http_build_query($qs)) ?>"><?= $p ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
