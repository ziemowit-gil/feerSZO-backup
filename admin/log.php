<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin');
$PAGE_TITLE = 'Dziennik zdarzeń';

// ── Filtry ────────────────────────────────────────────────────────────────────
$f_action    = $_GET['action']    ?? '';
$f_type      = $_GET['ctype']     ?? '';
$f_user      = trim($_GET['user'] ?? '');
$f_date_from = $_GET['date_from'] ?? '';
$f_date_to   = $_GET['date_to']   ?? '';
$f_search    = trim($_GET['q']    ?? '');
$page        = max(1, (int)($_GET['p'] ?? 1));
$per_page    = 50;

// ── Buduj WHERE ───────────────────────────────────────────────────────────────
$where = []; $params = [];
if ($f_action) {
    $where[] = 'l.action = ?';
    $params[] = $f_action;
}
if ($f_type) {
    $where[] = 'l.contract_type = ?';
    $params[] = $f_type;
}
if ($f_user) {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR l.user_snapshot LIKE ?)';
    $params[] = "%$f_user%"; $params[] = "%$f_user%"; $params[] = "%$f_user%";
}
if ($f_date_from) {
    $where[] = 'DATE(l.created_at) >= ?';
    $params[] = $f_date_from;
}
if ($f_date_to) {
    $where[] = 'DATE(l.created_at) <= ?';
    $params[] = $f_date_to;
}
if ($f_search) {
    $where[] = '(l.note LIKE ? OR l.user_snapshot LIKE ?)';
    $params[] = "%$f_search%"; $params[] = "%$f_search%";
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Licznik + dane ────────────────────────────────────────────────────────────
$total = (int)(db_one(
    "SELECT COUNT(*) AS n
     FROM contract_audit_log l
     LEFT JOIN users u ON l.user_id = u.id
     $sql_where",
    $params
)['n'] ?? 0);

$pages  = max(1, (int)ceil($total / $per_page));
$page   = min($page, $pages);
$offset = ($page - 1) * $per_page;

$logs = db_all(
    "SELECT l.*, u.name AS user_name, u.email AS user_email
     FROM contract_audit_log l
     LEFT JOIN users u ON l.user_id = u.id
     $sql_where
     ORDER BY l.id DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $offset])
);

// ── Dropdown: unikalne wartości ───────────────────────────────────────────────
$all_actions = db_all(
    "SELECT DISTINCT action FROM contract_audit_log ORDER BY action"
);
$all_types = db_all(
    "SELECT DISTINCT contract_type FROM contract_audit_log
     WHERE contract_type IS NOT NULL AND contract_type != ''
     ORDER BY contract_type"
);

include dirname(__DIR__) . '/includes/header.php';

// ── Helper: wyświetl "obiekt" wpisu ───────────────────────────────────────────
function log_entity_html(array $e): string {
    $type = $e['contract_type'] ?? '';
    $id   = (int)($e['contract_id'] ?? 0);

    $contract_slugs = ['zlecenie','wolontariat','dzielo','praca','uslugi','inne'];
    if (in_array($type, $contract_slugs, true) && $id > 0) {
        $label = CONTRACT_TYPES[$type] ?? $type;
        return '<a href="' . APP_URL . '/contracts/' . $type . '/view.php?id=' . $id
             . '" class="text-decoration-none">'
             . '<span class="badge bg-light text-dark border">' . h($label) . '</span>'
             . ' <span class="font-monospace small">#' . $id . '</span></a>';
    }
    if ($type === 'user' && $id > 0) {
        $u = db_one("SELECT name FROM users WHERE id=?", [$id]);
        $name = $u['name'] ?? '#' . $id;
        return '<a href="' . APP_URL . '/admin/users.php" class="text-decoration-none">'
             . '<span class="badge bg-primary-subtle text-primary-emphasis border"><i class="bi bi-person"></i> '
             . h($name) . '</span></a>';
    }
    if ($type === 'auth') {
        return '<span class="badge bg-secondary-subtle text-secondary-emphasis"><i class="bi bi-lock"></i> Logowanie</span>';
    }
    if ($type === 'system') {
        return '<span class="badge bg-secondary-subtle text-secondary-emphasis"><i class="bi bi-gear"></i> System</span>';
    }
    if ($type === 'm365_standalone') {
        return '<a href="' . APP_URL . '/admin/m365.php" class="text-decoration-none">'
             . '<span class="badge bg-info-subtle text-info-emphasis border"><i class="bi bi-microsoft"></i> M365'
             . ($id > 0 ? ' #'.$id : '') . '</span></a>';
    }
    if ($type) {
        return '<span class="badge bg-light text-dark border small">'
             . h($type) . ($id > 0 ? ' #'.$id : '') . '</span>';
    }
    return '<span class="text-muted">—</span>';
}

// ── Helper: czytelna nazwa kategorii ─────────────────────────────────────────
function log_type_label(string $type): string {
    if (defined('CONTRACT_TYPES') && isset(CONTRACT_TYPES[$type])) return CONTRACT_TYPES[$type];
    return match($type) {
        'auth'            => 'Logowanie',
        'user'            => 'Użytkownicy',
        'system'          => 'System / ustawienia',
        'm365_standalone' => 'Microsoft 365 (standalone)',
        default           => $type,
    };
}

// ── Helper: URL strony z obecnymi filtrami ────────────────────────────────────
function log_page_url(int $p): string {
    global $f_action, $f_type, $f_user, $f_date_from, $f_date_to, $f_search;
    $qs = http_build_query(array_filter([
        'action'    => $f_action,
        'ctype'     => $f_type,
        'user'      => $f_user,
        'date_from' => $f_date_from,
        'date_to'   => $f_date_to,
        'q'         => $f_search,
        'p'         => $p > 1 ? $p : '',
    ], fn($v) => $v !== '' && $v !== null));
    return 'log.php' . ($qs ? '?' . $qs : '');
}
?>

<style>
.log-table td { vertical-align: middle; font-size: .875rem; }
.log-note     { max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.log-note:hover { white-space: normal; overflow: visible; }
.filter-bar .form-label { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d; }
</style>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-journal-text text-primary"></i> Dziennik zdarzeń</h4>
  <span class="badge bg-secondary"><?= number_format($total) ?> wpisów</span>
  <?php if ($f_action || $f_type || $f_user || $f_date_from || $f_date_to || $f_search): ?>
  <span class="badge bg-warning text-dark"><i class="bi bi-funnel-fill"></i> Aktywne filtry</span>
  <a href="log.php" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-x-circle"></i> Wyczyść filtry
  </a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<!-- ── Pasek filtrów ──────────────────────────────────────────────────────────── -->
<form method="get" class="card shadow-sm mb-3 filter-bar">
  <div class="card-body py-2 px-3">
    <div class="row g-2 align-items-end">

      <div class="col-6 col-md-2">
        <label class="form-label mb-1">Akcja</label>
        <select name="action" class="form-select form-select-sm">
          <option value="">— wszystkie —</option>
          <?php foreach ($all_actions as $a): ?>
          <option value="<?= h($a['action']) ?>" <?= $f_action === $a['action'] ? 'selected' : '' ?>>
            <?= h(action_label($a['action'])) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label mb-1">Kategoria</label>
        <select name="ctype" class="form-select form-select-sm">
          <option value="">— wszystkie —</option>
          <?php foreach ($all_types as $t): ?>
          <option value="<?= h($t['contract_type']) ?>"
                  <?= $f_type === $t['contract_type'] ? 'selected' : '' ?>>
            <?= h(log_type_label($t['contract_type'])) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label mb-1">Operator</label>
        <input type="text" name="user" class="form-control form-control-sm"
               value="<?= h($f_user) ?>" placeholder=" Imię i nazwiskoś lub e-mail">
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label mb-1">Od daty</label>
        <input type="date" name="date_from" class="form-control form-control-sm"
               value="<?= h($f_date_from) ?>">
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label mb-1">Do daty</label>
        <input type="date" name="date_to" class="form-control form-control-sm"
               value="<?= h($f_date_to) ?>">
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label mb-1">Szukaj w notatce</label>
        <div class="input-group input-group-sm">
          <input type="text" name="q" class="form-control"
                 value="<?= h($f_search) ?>" placeholder="Szukaj…">
          <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
        </div>
      </div>

    </div>
  </div>
</form>

<!-- ── Tabela ─────────────────────────────────────────────────────────────────── -->
<div class="card shadow-sm">
<div class="table-responsive">
<table class="table table-sm table-hover log-table mb-0">
  <thead class="table-light">
    <tr>
      <th class="ps-3" style="min-width:130px">Czas</th>
      <th style="min-width:170px">Akcja</th>
      <th style="min-width:160px">Dotyczy</th>
      <th style="min-width:140px">Operator</th>
      <th>Notatka</th>
      <th style="width:105px">IP</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$logs): ?>
    <tr>
      <td colspan="6" class="text-center text-muted py-5">
        <i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>
        Brak wpisów spełniających kryteria.
      </td>
    </tr>
  <?php endif; ?>
  <?php foreach ($logs as $log): ?>
    <tr>
      <td class="ps-3 text-nowrap text-muted">
        <?= date('d.m.Y', strtotime($log['created_at'])) ?>
        <div class="font-monospace small"><?= date('H:i:s', strtotime($log['created_at'])) ?></div>
      </td>
      <td><?= action_badge($log['action']) ?></td>
      <td><?= log_entity_html($log) ?></td>
      <td>
        <?php if ($log['user_name']): ?>
          <div class="fw-semibold"><?= h($log['user_name']) ?></div>
          <div class="text-muted small"><?= h($log['user_email'] ?? '') ?></div>
        <?php else: ?>
          <span class="text-muted small"><?= h($log['user_snapshot'] ?? 'System') ?></span>
        <?php endif; ?>
      </td>
      <td class="log-note text-muted" title="<?= h($log['note'] ?? '') ?>">
        <?= h($log['note'] ?? '') ?>
      </td>
      <td class="font-monospace small text-muted"><?= h($log['ip_address'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<!-- Paginacja -->
<?php if ($pages > 1): ?>
<div class="card-footer bg-white d-flex justify-content-between align-items-center py-2 px-3">
  <span class="text-muted small">
    Strona <?= $page ?> z <?= $pages ?>
    &nbsp;·&nbsp; <?= number_format($total) ?> wpisów
  </span>
  <nav aria-label="Paginacja logu">
    <ul class="pagination pagination-sm mb-0 gap-1">
      <?php if ($page > 1): ?>
      <li class="page-item">
        <a class="page-link" href="<?= log_page_url(1) ?>">«</a>
      </li>
      <li class="page-item">
        <a class="page-link" href="<?= log_page_url($page - 1) ?>">‹</a>
      </li>
      <?php endif; ?>

      <?php
        $p_start = max(1, $page - 2);
        $p_end   = min($pages, $page + 2);
      ?>
      <?php for ($i = $p_start; $i <= $p_end; $i++): ?>
      <li class="page-item <?= $i === $page ? 'active' : '' ?>">
        <a class="page-link" href="<?= log_page_url($i) ?>"><?= $i ?></a>
      </li>
      <?php endfor; ?>

      <?php if ($page < $pages): ?>
      <li class="page-item">
        <a class="page-link" href="<?= log_page_url($page + 1) ?>">›</a>
      </li>
      <li class="page-item">
        <a class="page-link" href="<?= log_page_url($pages) ?>">»</a>
      </li>
      <?php endif; ?>
    </ul>
  </nav>
</div>
<?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
