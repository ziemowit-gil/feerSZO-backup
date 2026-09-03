<?php
/**
 * admin/ms_edu_codes.php — kody dostępowe (LCCC) do automatycznego zakładania
 * kont Microsoft 365. CRUD kodów + podgląd kont założonych każdym kodem.
 * Realizacja kodu (bez logowania) w ext/kontoMicrosoftEdu/index.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/msedu.php';

require_role('admin');
msedu_migrate();
$PAGE_TITLE = 'Kody dostępowe M365';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $code = msedu_code_normalize($_POST['code'] ?? '');
        $errors = [];
        if (!msedu_code_valid_format($code)) $errors[] = 'Kod musi mieć format: 1 litera i 3 cyfry, np. A123.';
        if (!$errors) {
            $dupe = msedu_code_get($code);
            if ($dupe && (int)$dupe['id'] !== $id) $errors[] = 'Ten kod już istnieje.';
        }
        if (!$errors) {
            msedu_code_save([
                'code'         => $code,
                'login_prefix' => $_POST['login_prefix'] ?? '',
                'max_uses'     => $_POST['max_uses'] ?? 1,
                'license_skus' => $_POST['license_skus'] ?? [],
                'expires_at'   => trim($_POST['expires_at'] ?? '') !== '' ? trim($_POST['expires_at']) . ' 23:59:59' : '',
                'is_active'    => isset($_POST['is_active']),
                'created_by'   => current_user()['id'] ?? null,
            ], $id ?: null);
            flash_set('success', $id ? 'Kod zaktualizowany.' : 'Kod dodany.');
        } else {
            flash_set('danger', implode(' ', $errors));
        }
        header('Location: ms_edu_codes.php'); exit;
    }

    if ($op === 'delete') {
        msedu_code_delete((int)($_POST['id'] ?? 0));
        flash_set('success', 'Kod usunięty.');
        header('Location: ms_edu_codes.php'); exit;
    }
}

$codes = msedu_codes_list();
$skus  = (new M365Graph())->get_subscribed_skus();
$view_id = (int)($_GET['accounts'] ?? 0);
$view_accounts = $view_id ? msedu_accounts_for_code($view_id) : null;
$view_code     = $view_id ? msedu_code_get_by_id($view_id) : null;

$status_badge = [
    'active'    => ['success',  'aktywny'],
    'inactive'  => ['secondary','wyłączony'],
    'expired'   => ['warning',  'wygasł'],
    'exhausted' => ['danger',   'wyczerpany'],
];

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-xl py-4">
  <div class="d-flex align-items-center gap-2 mb-4 flex-wrap">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-key-fill me-2"></i>Kody dostępowe M365</h1>
    <button class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#codeModal" onclick="msEduFormReset()">
      <i class="bi bi-plus-lg me-1"></i>Nowy kod
    </button>
  </div>

  <?= flash_html() ?>

  <p class="text-muted small">
    Publiczna strona do realizacji kodu: <code><?= h(rtrim(APP_URL, '/')) ?>/ext/kontoMicrosoftEdu/</code>
  </p>

  <?php if ($view_code): ?>
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
      <span>Konta założone kodem <strong><?= h($view_code['code']) ?></strong></span>
      <a href="ms_edu_codes.php" class="btn btn-sm btn-outline-secondary ms-auto">Zamknij</a>
    </div>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>UPN</th><th>Utworzono</th></tr></thead>
        <tbody>
          <?php if (!$view_accounts): ?><tr><td colspan="2" class="text-muted text-center py-3">Brak kont.</td></tr><?php endif; ?>
          <?php foreach ($view_accounts as $a): ?>
          <tr><td class="font-monospace"><?= h($a['upn']) ?></td><td class="text-muted"><?= h(substr($a['created_at'],0,16)) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr><th>Kod</th><th>Prefiks loginu</th><th>Użycia</th><th>Ważność</th><th>Status</th><th class="text-end">Akcje</th></tr>
        </thead>
        <tbody>
          <?php if (!$codes): ?><tr><td colspan="6" class="text-center text-muted py-4">Brak kodów.</td></tr><?php endif; ?>
          <?php foreach ($codes as $c):
            $st = msedu_code_status($c);
            [$cls, $lbl] = $status_badge[$st];
          ?>
          <tr>
            <td class="fw-bold font-monospace"><?= h($c['code']) ?></td>
            <td class="text-muted"><?= h($c['login_prefix']) ?: '<span class="text-muted">edu_</span>' ?></td>
            <td><a href="?accounts=<?= (int)$c['id'] ?>"><?= (int)$c['used_count'] ?> / <?= (int)$c['max_uses'] ?></a></td>
            <td class="text-muted"><?= $c['expires_at'] ? h(substr($c['expires_at'],0,10)) : '—' ?></td>
            <td><span class="badge text-bg-<?= $cls ?>"><?= $lbl ?></span></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-secondary"
                      onclick='msEduFormEdit(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć kod? Historia założonych kont zostanie skasowana.')">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="delete">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal dodaj/edytuj -->
<div class="modal fade" id="codeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="save">
        <input type="hidden" name="id" id="mf_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="mf_title">Nowy kod</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label fw-semibold">Kod <span class="text-danger">*</span></label>
            <input type="text" class="form-control font-monospace" name="code" id="mf_code" required
                   maxlength="4" pattern="[A-Za-z]\d{3}" placeholder="np. A123"
                   oninput="this.value=this.value.toUpperCase()">
            <div class="form-text">1 litera + 3 cyfry.</div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Prefiks loginu</label>
            <input type="text" class="form-control" name="login_prefix" id="mf_prefix" placeholder="edu_">
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label fw-semibold">Maks. użyć</label>
              <input type="number" class="form-control" name="max_uses" id="mf_max" min="1" value="1">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Ważny do <span class="text-muted fw-normal">(opc.)</span></label>
              <input type="date" class="form-control" name="expires_at" id="mf_expires">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Licencje przypisywane automatycznie</label>
            <?php if (!$skus): ?>
            <div class="text-muted small">Brak danych o licencjach — sprawdź konfigurację M365 w Integracjach.</div>
            <?php endif; ?>
            <?php foreach ($skus as $s): ?>
            <div class="form-check">
              <input class="form-check-input msedu-sku" type="checkbox" name="license_skus[]" value="<?= h($s['skuId']) ?>" id="sku_<?= h($s['skuId']) ?>">
              <label class="form-check-label small" for="sku_<?= h($s['skuId']) ?>">
                <?= h($s['skuPartNumber']) ?>
                <span class="text-muted">(<?= (int)($s['prepaidUnits']['enabled'] ?? 0) - (int)($s['consumedUnits'] ?? 0) ?> wolnych)</span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" id="mf_active" checked>
            <label class="form-check-label" for="mf_active">Aktywny</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary">Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function msEduFormReset() {
  document.getElementById('mf_id').value = '0';
  document.getElementById('mf_code').value = '';
  document.getElementById('mf_code').disabled = false;
  document.getElementById('mf_prefix').value = '';
  document.getElementById('mf_max').value = '1';
  document.getElementById('mf_expires').value = '';
  document.getElementById('mf_active').checked = true;
  document.querySelectorAll('.msedu-sku').forEach(function(el){ el.checked = false; });
  document.getElementById('mf_title').textContent = 'Nowy kod';
}
function msEduFormEdit(c) {
  document.getElementById('mf_id').value = c.id;
  document.getElementById('mf_code').value = c.code;
  document.getElementById('mf_prefix').value = c.login_prefix || '';
  document.getElementById('mf_max').value = c.max_uses;
  document.getElementById('mf_expires').value = c.expires_at ? c.expires_at.substring(0,10) : '';
  document.getElementById('mf_active').checked = !!parseInt(c.is_active);
  var skus = [];
  try { skus = JSON.parse(c.license_skus || '[]'); } catch (e) {}
  document.querySelectorAll('.msedu-sku').forEach(function(el){ el.checked = skus.indexOf(el.value) !== -1; });
  document.getElementById('mf_title').textContent = 'Edytuj kod';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('codeModal')).show();
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
