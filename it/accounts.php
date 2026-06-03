<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/includes/it_helpers.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Konta IT';

$services = db_all("SELECT * FROM it_services WHERE is_active=1 ORDER BY sort_order");

// Filtr: poczekaj czy to widok szczegółów konta
$view_id = intval($_GET['id'] ?? 0);
if ($view_id) {
    $account = db_one(
        "SELECT a.*, s.name AS service_name, s.icon AS service_icon, s.color AS service_color, s.slug AS service_slug,
                u.name AS created_by_name, u2.name AS deactivated_by_name
         FROM it_accounts a
         JOIN it_services s ON s.id = a.service_id
         LEFT JOIN users u  ON u.id  = a.created_by
         LEFT JOIN users u2 ON u2.id = a.deactivated_by
         WHERE a.id=?",
        [$view_id]
    );
    if (!$account) { flash_set('danger','Nie znaleziono konta.'); header('Location: '.$_SERVER['PHP_SELF']); exit; }

    $passwords = it_passwords_for_account($view_id, 20);

    // Dane umowy
    $contract_info = null;
    if ($account['contract_type'] && $account['contract_id']) {
        $contract_info = [
            'type'  => $account['contract_type'],
            'id'    => (int)$account['contract_id'],
            'label' => it_contract_label($account['contract_type']),
            'num'   => it_contract_number($account['contract_type'], (int)$account['contract_id']),
            'url'   => it_contract_url($account['contract_type'], (int)$account['contract_id']),
        ];
    }

    include dirname(__DIR__) . '/includes/header.php';
    ?>
<div class="container-fluid py-4" style="max-width:900px">
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/it/index.php">IT</a></li>
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/it/accounts.php">Konta</a></li>
      <li class="breadcrumb-item active"><?= h($account['login'] ?: $account['display_name'] ?: '#'.$view_id) ?></li>
    </ol>
  </nav>

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-3">
      <div style="width:40px;height:40px;border-radius:10px;background:<?= h($account['service_color']) ?>22;display:flex;align-items:center;justify-content:center;color:<?= h($account['service_color']) ?>;font-size:1.2rem">
        <i class="bi <?= h($account['service_icon']) ?>"></i>
      </div>
      <div>
        <div class="fw-bold"><?= h($account['display_name'] ?: $account['login'] ?: 'Konto IT') ?></div>
        <div class="text-muted small"><?= h($account['service_name']) ?></div>
      </div>
      <div class="ms-auto">
        <?php if ($account['is_active']): ?>
        <span class="badge bg-success">Aktywne</span>
        <?php else: ?>
        <span class="badge bg-secondary">Nieaktywne</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <div class="text-muted small mb-1">Login</div>
          <div class="font-monospace"><?= h($account['login'] ?: '—') ?></div>
        </div>
        <div class="col-md-4">
          <div class="text-muted small mb-1">ID zewnętrzny</div>
          <div class="font-monospace small"><?= h($account['external_id'] ?: '—') ?></div>
        </div>
        <div class="col-md-4">
          <div class="text-muted small mb-1">Licencja</div>
          <div><?= $account['license_assigned'] ? '<span class="text-success">Przypisana</span>' : '<span class="text-muted">Brak</span>' ?></div>
        </div>
        <?php if ($contract_info): ?>
        <div class="col-md-6">
          <div class="text-muted small mb-1">Umowa</div>
          <div>
            <a href="<?= $contract_info['url'] ?>" class="text-decoration-none">
              <i class="bi bi-file-earmark-text me-1"></i><?= h($contract_info['num']) ?>
              <span class="badge bg-light text-dark border ms-1"><?= h($contract_info['label']) ?></span>
            </a>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($account['notes']): ?>
        <div class="col-12">
          <div class="text-muted small mb-1">Notatki</div>
          <div><?= h($account['notes']) ?></div>
        </div>
        <?php endif; ?>
      </div>

      <?php if (can_edit()): ?>
      <div class="d-flex gap-2 flex-wrap border-top pt-3">
        <!-- Tylko M365 ma pełną integrację API -->
        <?php if ($account['service_slug'] === 'm365'): ?>
          <?php
          $m365_enabled = m365_setting('m365_enabled') === '1';
          $m365_conf    = (new M365Graph())->is_configured();
          ?>
          <?php if ($m365_enabled && $m365_conf && $account['external_id']): ?>
          <form method="post" action="<?= APP_URL ?>/it/action.php">
            <?= csrf_field() ?>
            <input type="hidden" name="account_id" value="<?= $view_id ?>">
            <input type="hidden" name="action" value="<?= $account['is_active'] ? 'disable' : 'enable' ?>">
            <button class="btn btn-sm <?= $account['is_active'] ? 'btn-warning' : 'btn-success' ?>"
              <?= $account['is_active'] ? "data-confirm='Wyłączyć konto M365?'" : '' ?>>
              <i class="bi bi-<?= $account['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
              <?= $account['is_active'] ? 'Wyłącz konto' : 'Włącz konto' ?>
            </button>
          </form>
          <?php if ($account['contract_type'] && $account['contract_id']): ?>
          <?php
          $tbl = match($account['contract_type']) { 'wolontariat'=>'umowy_wolontariat','zlecenie'=>'umowy_zlecenie','dzielo'=>'umowy_dzielo',default=>null};
          $crow = $tbl ? db_one("SELECT email FROM {$tbl} WHERE id=?",[(int)$account['contract_id']]) : null;
          if ($crow && !empty($crow['email'])):
          ?>
          <form method="post" action="<?= APP_URL ?>/it/action.php">
            <?= csrf_field() ?>
            <input type="hidden" name="account_id" value="<?= $view_id ?>">
            <input type="hidden" name="action" value="reset_password">
            <button class="btn btn-sm btn-outline-primary" data-confirm="Zresetować hasło i wysłać nowe na email?">
              <i class="bi bi-arrow-clockwise"></i> Reset hasła + mail
            </button>
          </form>
          <?php endif; ?>
          <?php endif; ?>
          <?php if (is_admin()): ?>
          <form method="post" action="<?= APP_URL ?>/it/action.php">
            <?= csrf_field() ?>
            <input type="hidden" name="account_id" value="<?= $view_id ?>">
            <input type="hidden" name="action" value="delete_m365">
            <button class="btn btn-sm btn-outline-danger"
              data-confirm="Trwale usunąć konto M365 <?= addslashes(h($account['login'])) ?> z Azure AD? Tej operacji nie można cofnąć.">
              <i class="bi bi-trash3"></i> Usuń z Azure AD
            </button>
          </form>
          <?php endif; ?>
          <?php elseif (!$m365_enabled): ?>
          <p class="text-muted small mb-0">Integracja M365 wyłączona.
            <a href="<?= APP_URL ?>/admin/m365_settings.php">Włącz →</a></p>
          <?php endif; ?>
        <?php else: ?>
        <!-- Inne serwisy — ręczne zarządzanie -->
        <form method="post" action="<?= APP_URL ?>/it/action.php">
          <?= csrf_field() ?>
          <input type="hidden" name="account_id" value="<?= $view_id ?>">
          <input type="hidden" name="action" value="<?= $account['is_active'] ? 'deactivate' : 'activate' ?>">
          <button class="btn btn-sm <?= $account['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
            <?= $account['is_active'] ? "data-confirm='Oznaczyć konto jako nieaktywne?'" : '' ?>>
            <i class="bi bi-<?= $account['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
            <?= $account['is_active'] ? 'Dezaktywuj' : 'Aktywuj' ?>
          </button>
        </form>
        <?php endif; ?>

        <!-- Wydaj hasło ręcznie (dla każdego serwisu) -->
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse"
                data-bs-target="#issue-password-form">
          <i class="bi bi-key"></i> Wydaj hasło
        </button>
      </div>

      <!-- Formularz ręcznego wydania hasła -->
      <div class="collapse mt-3" id="issue-password-form">
        <div class="card card-body bg-light">
          <form method="post" action="<?= APP_URL ?>/it/action.php" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="account_id" value="<?= $view_id ?>">
            <input type="hidden" name="action" value="issue_password">
            <div class="col-md-5">
              <label class="form-label small mb-1">Hasło (puste = generuj automatycznie)</label>
              <input type="text" name="password_plain" class="form-control form-control-sm font-monospace"
                     placeholder="Zostaw puste aby wygenerować">
            </div>
            <div class="col-md-4">
              <label class="form-label small mb-1">Wyślij na email</label>
              <input type="email" name="send_to_email" class="form-control form-control-sm"
                     value="<?= $contract_info ? h($crow['email'] ?? '') : '' ?>"
                     placeholder="Opcjonalnie">
            </div>
            <div class="col-md-3">
              <label class="form-label small mb-1">Notatka</label>
              <input type="text" name="notes" class="form-control form-control-sm" placeholder="Opcjonalnie">
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-sm btn-warning">
                <i class="bi bi-key-fill me-1"></i> Wydaj hasło
              </button>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Historia haseł -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-2 d-flex align-items-center gap-2">
      <i class="bi bi-clock-history" style="color:#fd7e14"></i>
      <span class="fw-semibold">Historia haseł</span>
      <span class="badge bg-secondary ms-auto"><?= count($passwords) ?></span>
    </div>
    <?php if ($passwords): ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0 small">
        <thead class="table-light">
          <tr>
            <th>Data wydania</th>
            <th>Login</th>
            <th>Wysłano na</th>
            <th>Wydał</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($passwords as $pw): ?>
          <tr class="<?= $pw['is_superseded'] ? 'text-muted' : '' ?>">
            <td><?= h(substr($pw['issued_at'],0,16)) ?></td>
            <td class="font-monospace"><?= h($pw['login'] ?: '—') ?></td>
            <td><?= h($pw['sent_to_email'] ?: '—') ?>
              <?php if ($pw['sent_at']): ?><small class="text-muted">(<?= date_pl(substr($pw['sent_at'],0,10)) ?>)</small><?php endif; ?>
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
              <button type="button" class="btn btn-xs btn-outline-secondary"
                      onclick="showPassword(<?= $pw['id'] ?>, this)"
                      style="font-size:.7rem;padding:1px 6px">
                <i class="bi bi-eye"></i> Pokaż
              </button>
              <?php endif; ?>
              <?php if ($pw['notes']): ?>
              <span class="text-muted ms-1" title="<?= h($pw['notes']) ?>"><i class="bi bi-info-circle"></i></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="card-body text-muted text-center small py-3">Brak historii haseł</div>
    <?php endif; ?>
  </div>

</div>
<script>
function showPassword(id, btn) {
  if (btn.dataset.shown) { btn.closest('tr').querySelector('.pw-reveal')?.remove(); btn.innerHTML='<i class="bi bi-eye"></i> Pokaż'; delete btn.dataset.shown; return; }
  fetch('<?= APP_URL ?>/it/action.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: '_csrf=<?= csrf_token() ?>&action=reveal_password&password_id='+id
  }).then(r=>r.json()).then(data=>{
    if (data.error) { alert(data.error); return; }
    const td = btn.closest('td');
    let sp = document.createElement('span');
    sp.className = 'pw-reveal font-monospace ms-2 badge bg-warning text-dark';
    sp.textContent = data.password;
    td.appendChild(sp);
    btn.innerHTML = '<i class="bi bi-eye-slash"></i> Ukryj';
    btn.dataset.shown = '1';
  });
}
</script>
<?php
    include dirname(__DIR__) . '/includes/footer.php';
    exit;
}

// ── Lista kont ────────────────────────────────────────────────────────────────
$filter_service = $_GET['service'] ?? '';
$filter_status  = $_GET['status']  ?? 'active';
$filter_q       = trim($_GET['q']  ?? '');
$page           = max(1, intval($_GET['page'] ?? 1));
$per            = 25;

$where  = '1=1';
$params = [];

if ($filter_service) {
    $svc = db_one("SELECT id FROM it_services WHERE slug=?", [$filter_service]);
    if ($svc) { $where .= ' AND a.service_id=?'; $params[] = $svc['id']; }
}
if ($filter_status === 'active')   { $where .= ' AND a.is_active=1'; }
if ($filter_status === 'inactive') { $where .= ' AND a.is_active=0'; }
if ($filter_q) {
    $where .= ' AND (a.login LIKE ? OR a.display_name LIKE ? OR a.external_id LIKE ?)';
    $params = array_merge($params, ["%$filter_q%","%$filter_q%","%$filter_q%"]);
}

$total = (int)(db_one(
    "SELECT COUNT(*) AS c FROM it_accounts a WHERE {$where}", $params
)['c'] ?? 0);

$pag = paginate($total, $per, $page, APP_URL . '/it/accounts.php?' . http_build_query(array_filter([
    'service' => $filter_service, 'status' => $filter_status !== 'active' ? $filter_status : null, 'q' => $filter_q
])));

$accounts = db_all(
    "SELECT a.*, s.name AS service_name, s.icon AS service_icon, s.color AS service_color, s.slug AS service_slug
     FROM it_accounts a
     JOIN it_services s ON s.id = a.service_id
     WHERE {$where}
     ORDER BY a.updated_at DESC LIMIT {$per} OFFSET {$pag['offset']}",
    $params
);

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid py-4">

  <div class="d-flex align-items-center gap-3 mb-3">
    <div style="width:40px;height:40px;border-radius:10px;background:#fff3e0;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fd7e14;flex-shrink:0">
      <i class="bi bi-person-badge"></i>
    </div>
    <div>
      <h1 class="h5 mb-0 fw-bold">Konta IT</h1>
      <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/it/index.php">IT</a></li>
        <li class="breadcrumb-item active">Konta</li>
      </ol></nav>
    </div>
  </div>

  <!-- Filtry -->
  <form method="get" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
      <input type="search" name="q" value="<?= h($filter_q) ?>" class="form-control form-control-sm" placeholder="Szukaj loginu…">
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
        <option value="inactive" <?= $filter_status==='inactive' ? 'selected':'' ?>>Nieaktywne</option>
        <option value=""         <?= $filter_status===''         ? 'selected':'' ?>>Wszystkie</option>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-outline-secondary">Filtruj</button>
    </div>
    <div class="col-auto ms-auto">
      <span class="text-muted small"><?= $total ?> kont</span>
    </div>
  </form>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover mb-0 small">
        <thead class="table-light">
          <tr>
            <th>Serwis</th>
            <th>Login / Użytkownik</th>
            <th>Umowa</th>
            <th>Status</th>
            <th>Licencja</th>
            <th>Zaktualizowano</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($accounts as $a): ?>
          <tr>
            <td>
              <i class="bi <?= h($a['service_icon']) ?>" style="color:<?= h($a['service_color']) ?>"></i>
              <span class="ms-1"><?= h($a['service_name']) ?></span>
            </td>
            <td>
              <div class="font-monospace"><?= h($a['login'] ?: '—') ?></div>
              <?php if ($a['display_name'] && $a['display_name'] !== $a['login']): ?>
              <div class="text-muted" style="font-size:.75rem"><?= h($a['display_name']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($a['contract_type'] && $a['contract_id']): ?>
              <a href="<?= it_contract_url($a['contract_type'], (int)$a['contract_id']) ?>"
                 class="text-decoration-none small">
                <?= h(it_contract_label($a['contract_type'])) ?>
                <i class="bi bi-box-arrow-up-right ms-1" style="font-size:.65rem"></i>
              </a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <?php if ($a['is_active']): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle">aktywne</span>
              <?php else: ?>
              <span class="badge bg-secondary-subtle text-secondary border">nieaktywne</span>
              <?php endif; ?>
            </td>
            <td><?= $a['license_assigned'] ? '<i class="bi bi-check-circle text-success"></i>' : '<span class="text-muted">—</span>' ?></td>
            <td class="text-muted"><?= date_pl(substr($a['updated_at'],0,10)) ?></td>
            <td>
              <a href="?id=<?= $a['id'] ?>" class="btn btn-xs btn-outline-secondary" style="font-size:.72rem;padding:2px 8px">
                Szczegóły
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$accounts): ?>
          <tr><td colspan="7" class="text-muted py-4 text-center">Brak kont spełniających kryteria</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pag['pages'] > 1): ?>
    <div class="card-footer bg-white">
      <?= $pag['html'] ?>
    </div>
    <?php endif; ?>
  </div>

</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
