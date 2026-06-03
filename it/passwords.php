<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/it_helpers.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Hasła serwisów IT';

$filter_service  = $_GET['service']  ?? '';
$filter_q        = trim($_GET['q']   ?? '');
$filter_status   = $_GET['status']   ?? 'active';
$page            = max(1, intval($_GET['page'] ?? 1));
$per             = 30;

$services = db_all("SELECT * FROM it_services WHERE is_active=1 ORDER BY sort_order");

$where  = '1=1';
$params = [];
if ($filter_service) {
    $svc = db_one("SELECT id FROM it_services WHERE slug=?", [$filter_service]);
    if ($svc) { $where .= ' AND p.service_id=?'; $params[] = $svc['id']; }
}
if ($filter_status === 'active')   { $where .= ' AND p.is_superseded=0'; }
if ($filter_status === 'inactive') { $where .= ' AND p.is_superseded=1'; }
if ($filter_q) {
    $where .= ' AND (p.login LIKE ? OR p.sent_to_email LIKE ?)';
    $params = array_merge($params, ["%$filter_q%", "%$filter_q%"]);
}

$total = (int)(db_one(
    "SELECT COUNT(*) AS c FROM it_service_passwords p WHERE {$where}", $params
)['c'] ?? 0);

$pag = paginate($total, $per, $page, APP_URL . '/it/passwords.php?' . http_build_query(array_filter([
    'service' => $filter_service, 'status' => $filter_status !== 'active' ? $filter_status : null, 'q' => $filter_q
])));

$passwords = db_all(
    "SELECT p.*,
            s.name AS service_name, s.icon AS service_icon, s.color AS service_color, s.slug AS service_slug,
            u.name AS issued_by_name
     FROM it_service_passwords p
     JOIN it_services s ON s.id = p.service_id
     LEFT JOIN users u ON u.id = p.issued_by
     WHERE {$where}
     ORDER BY p.issued_at DESC LIMIT {$per} OFFSET {$pag['offset']}",
    $params
);

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid py-4">

  <div class="d-flex align-items-center gap-3 mb-3">
    <div style="width:40px;height:40px;border-radius:10px;background:#fff3e0;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fd7e14;flex-shrink:0">
      <i class="bi bi-key-fill"></i>
    </div>
    <div>
      <h1 class="h5 mb-0 fw-bold">Hasła serwisów IT</h1>
      <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/it/index.php">IT</a></li>
        <li class="breadcrumb-item active">Hasła</li>
      </ol></nav>
    </div>
    <?php if (is_admin()): ?>
    <div class="ms-auto">
      <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#modal-issue-manual">
        <i class="bi bi-key me-1"></i> Wydaj hasło ręcznie
      </button>
    </div>
    <?php endif; ?>
  </div>

  <!-- Alert: dostęp do haseł logowany -->
  <div class="alert alert-warning alert-dismissible py-2 px-3 small mb-3">
    <i class="bi bi-shield-exclamation me-1"></i>
    Każde ujawnienie hasła jest <strong>logowane</strong>. Hasła są szyfrowane (AES-256). Tylko administratorzy mogą je wyświetlać.
    <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
  </div>

  <!-- Filtry -->
  <form method="get" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
      <input type="search" name="q" value="<?= h($filter_q) ?>" class="form-control form-control-sm" placeholder="Login lub email…">
    </div>
    <div class="col-auto">
      <select name="service" class="form-select form-select-sm">
        <option value="">Wszystkie serwisy</option>
        <?php foreach ($services as $s): ?>
        <option value="<?= h($s['slug']) ?>" <?= $filter_service === $s['slug'] ? 'selected' : '' ?>>
          <?= h($s['name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="status" class="form-select form-select-sm">
        <option value="active"   <?= $filter_status==='active'   ? 'selected':'' ?>>Aktywne</option>
        <option value="inactive" <?= $filter_status==='inactive' ? 'selected':'' ?>>Wygasłe</option>
        <option value=""         <?= $filter_status===''         ? 'selected':'' ?>>Wszystkie</option>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-outline-secondary">Filtruj</button>
    </div>
    <div class="col-auto ms-auto text-muted small"><?= $total ?> haseł</div>
  </form>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover mb-0 small">
        <thead class="table-light">
          <tr>
            <th>Data wydania</th>
            <th>Serwis</th>
            <th>Login</th>
            <th>Wysłano na</th>
            <th>Umowa</th>
            <th>Wydał</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($passwords as $pw): ?>
          <tr class="<?= $pw['is_superseded'] ? 'text-muted' : '' ?>">
            <td style="white-space:nowrap"><?= h(substr($pw['issued_at'],0,16)) ?></td>
            <td>
              <i class="bi <?= h($pw['service_icon']) ?>" style="color:<?= h($pw['service_color']) ?>"></i>
              <span class="ms-1"><?= h($pw['service_name']) ?></span>
            </td>
            <td class="font-monospace"><?= h($pw['login'] ?: '—') ?></td>
            <td>
              <?= h($pw['sent_to_email'] ?: '—') ?>
              <?php if ($pw['sent_at']): ?>
              <span class="text-success" title="Wysłano: <?= h($pw['sent_at']) ?>"><i class="bi bi-envelope-check"></i></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($pw['contract_type'] && $pw['contract_id']): ?>
              <a href="<?= it_contract_url($pw['contract_type'], (int)$pw['contract_id']) ?>"
                 class="text-decoration-none" style="font-size:.78rem">
                <?= h(it_contract_label($pw['contract_type'])) ?>
                <i class="bi bi-box-arrow-up-right ms-1" style="font-size:.6rem"></i>
              </a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= h($pw['issued_by_name'] ?: '—') ?></td>
            <td>
              <?php if ($pw['is_superseded']): ?>
              <span class="badge bg-secondary">wygasłe</span>
              <?php elseif ($pw['password_enc']): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle">aktywne</span>
              <?php else: ?>
              <span class="badge bg-light text-dark border">brak</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($pw['password_enc'] && !$pw['is_superseded'] && is_admin()): ?>
              <button type="button" class="btn btn-outline-secondary"
                      onclick="showPassword(<?= $pw['id'] ?>, this)"
                      style="font-size:.7rem;padding:2px 8px;line-height:1.2">
                <i class="bi bi-eye"></i> Pokaż
              </button>
              <?php endif; ?>
              <?php if ($pw['account_id']): ?>
              <a href="<?= APP_URL ?>/it/accounts.php?id=<?= $pw['account_id'] ?>"
                 class="btn btn-outline-secondary ms-1" style="font-size:.7rem;padding:2px 8px;line-height:1.2">
                <i class="bi bi-person"></i>
              </a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$passwords): ?>
          <tr><td colspan="8" class="text-muted py-4 text-center">Brak haseł spełniających kryteria</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pag['pages'] > 1): ?>
    <div class="card-footer bg-white"><?= $pag['html'] ?></div>
    <?php endif; ?>
  </div>
</div>

<?php if (is_admin()): ?>
<!-- Modal: ręczne wydanie hasła -->
<div class="modal fade" id="modal-issue-manual" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" action="<?= APP_URL ?>/it/action.php" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="issue_password_manual">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-key-fill me-2" style="color:#fd7e14"></i>Wydaj hasło ręcznie</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Serwis <span class="text-danger">*</span></label>
          <select name="service_id" class="form-select" required>
            <option value="">— wybierz —</option>
            <?php foreach ($services as $s): ?>
            <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Login <span class="text-danger">*</span></label>
          <input type="text" name="login" class="form-control font-monospace" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Hasło (puste = generuj automatycznie)</label>
          <input type="text" name="password_plain" class="form-control font-monospace"
                 placeholder="Zostaw puste aby wygenerować">
        </div>
        <div class="mb-3">
          <label class="form-label">Wyślij na email</label>
          <input type="email" name="send_to_email" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Notatka</label>
          <input type="text" name="notes" class="form-control">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-warning">
          <i class="bi bi-key-fill me-1"></i> Wydaj hasło
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function showPassword(id, btn) {
  if (btn.dataset.shown) {
    const sp = btn.closest('tr').querySelector('.pw-reveal');
    if (sp) sp.remove();
    btn.innerHTML = '<i class="bi bi-eye"></i> Pokaż';
    delete btn.dataset.shown;
    return;
  }
  fetch('<?= APP_URL ?>/it/action.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: '_csrf=<?= csrf_token() ?>&action=reveal_password&password_id=' + id
  }).then(r => r.json()).then(data => {
    if (data.error) { alert(data.error); return; }
    const td = btn.closest('td');
    let sp = document.createElement('span');
    sp.className = 'pw-reveal font-monospace ms-2 badge bg-warning text-dark';
    sp.textContent = data.password;
    td.insertBefore(sp, btn);
    btn.innerHTML = '<i class="bi bi-eye-slash"></i> Ukryj';
    btn.dataset.shown = '1';
  });
}
</script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
