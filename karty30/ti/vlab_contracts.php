<?php
/**
 * karty30/ti/vlab_contracts.php — Zarządzanie umowami o dostęp do VLab.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/vlab_contracts.php';

k30_require_access();
karty30_migrate();
vlab_contracts_migrate();

$user      = current_user();
$can_write = can_write('karty30') || is_admin();

// ── Akcje POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');

    // Utwórz nową umowę
    if ($op === 'create') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        if (!$client_id) {
            flash_set('error', 'Wybierz kursanta.');
        } else {
            $id = vlab_contract_create([
                'client_id'       => $client_id,
                'account_id'      => $_POST['account_id'] ?? '',
                'status'          => $_POST['status'] ?? 'projekt',
                'data_zawarcia'   => $_POST['data_zawarcia'] ?? date('Y-m-d'),
                'data_waznosci'   => $_POST['data_waznosci'] ?? '',
                'cel_dostepu'     => trim($_POST['cel_dostepu'] ?? ''),
                'warunki_html'    => $_POST['warunki_html'] ?? '',
                'podpisana_przez' => trim($_POST['podpisana_przez'] ?? ''),
                'uwagi'           => trim($_POST['uwagi'] ?? ''),
            ], (int)$user['id']);
            flash_set('success', 'Umowa VLab utworzona.');
            header('Location: vlab_contracts.php?id=' . $id); exit;
        }
    }

    // Zaktualizuj umowę
    if ($op === 'update') {
        $id = (int)($_POST['contract_id'] ?? 0);
        if ($id) {
            vlab_contract_update($id, [
                'account_id'      => $_POST['account_id'] ?? '',
                'status'          => $_POST['status'] ?? 'projekt',
                'data_zawarcia'   => $_POST['data_zawarcia'] ?? date('Y-m-d'),
                'data_waznosci'   => $_POST['data_waznosci'] ?? '',
                'cel_dostepu'     => trim($_POST['cel_dostepu'] ?? ''),
                'warunki_html'    => $_POST['warunki_html'] ?? '',
                'podpisana_przez' => trim($_POST['podpisana_przez'] ?? ''),
                'uwagi'           => trim($_POST['uwagi'] ?? ''),
            ]);
            flash_set('success', 'Umowa zaktualizowana.');
            header('Location: vlab_contracts.php?id=' . $id); exit;
        }
    }
}

// ── PDF ───────────────────────────────────────────────────────────────────────
if (isset($_GET['pdf'])) {
    $c = vlab_contract_get((int)$_GET['pdf']);
    if (!$c) { http_response_code(404); exit('Nie znaleziono umowy.'); }
    vlab_contract_pdf($c);
}

// ── Widok: pojedyncza umowa ───────────────────────────────────────────────────
$view_id  = (int)($_GET['id'] ?? 0);
$contract = $view_id ? vlab_contract_get($view_id) : null;

// ── Widok: formularz nowej umowy ─────────────────────────────────────────────
$new_mode = isset($_GET['new']);

// ── Lista filtrowana ─────────────────────────────────────────────────────────
$filters = [
    'status'    => $_GET['status'] ?? '',
    'q'         => trim($_GET['q'] ?? ''),
    'client_id' => (int)($_GET['client_id'] ?? 0),
];
$contracts = ($contract || $new_mode) ? [] : vlab_contracts_all($filters);

// Lista klientów dla selecta (kursanci z aktywnymi kontami TI lub istniejącymi umowami VLab)
$clients = db_all(
    "SELECT DISTINCT c.id, c.name, c.email
     FROM k30_clients c
     WHERE c.id IN (
         SELECT DISTINCT client_id FROM k30_ti_student_accounts
         UNION
         SELECT DISTINCT client_id FROM k30_vlab_contracts
     )
     ORDER BY c.name"
);

// Konta studenta dla wybranego klienta (przy edycji)
$student_accounts = [];
if ($contract) {
    $student_accounts = db_all(
        "SELECT id, login, is_active FROM k30_ti_student_accounts WHERE client_id=? ORDER BY login",
        [$contract['client_id']]
    );
} elseif ($new_mode && !empty($_GET['client_id'])) {
    $student_accounts = db_all(
        "SELECT id, login, is_active FROM k30_ti_student_accounts WHERE client_id=? ORDER BY login",
        [(int)$_GET['client_id']]
    );
}

$PAGE_TITLE = 'Umowy VLab';
include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/ti/index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item <?= ($contract || $new_mode) ? '' : 'active' ?>">
    <a href="vlab_contracts.php" <?= ($contract || $new_mode) ? '' : 'aria-current="page"' ?>>Umowy VLab</a>
  </li>
  <?php if ($contract): ?>
  <li class="breadcrumb-item active" aria-current="page"><?= h($contract['numer_umowy']) ?></li>
  <?php elseif ($new_mode): ?>
  <li class="breadcrumb-item active" aria-current="page">Nowa umowa</li>
  <?php endif; ?>
</ol></nav>

<?= flash_html() ?>

<?php /* ═══════════════════════════════════ WIDOK UMOWY ══════════════════════════════════════ */
if ($contract): ?>

<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-lock text-primary me-2"></i><?= h($contract['numer_umowy']) ?></h4>
  <?php
  $st = VLAB_CONTRACT_STATUSES[$contract['status']] ?? ['label' => $contract['status'], 'class' => 'secondary'];
  $cc = STATUS_CUSTOM_COLORS[$st['class']] ?? null;
  $style = $cc ? "background:$cc;color:#fff" : '';
  ?>
  <span class="badge text-bg-<?= $st['class'] ?>" <?= $style ? 'style="' . $style . '"' : '' ?>>
    <?= h($st['label']) ?>
  </span>
  <div class="ms-auto d-flex gap-2">
    <a href="vlab_contracts.php?pdf=<?= $contract['id'] ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-filetype-pdf me-1"></i>PDF
    </a>
    <?php if ($can_write): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editModal">
      <i class="bi bi-pencil me-1"></i>Edytuj
    </button>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-7">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold"><i class="bi bi-person text-primary me-1"></i>Dane umowy</div>
      <div class="card-body">
        <dl class="row mb-0 small">
          <dt class="col-sm-4">Kursant</dt>
          <dd class="col-sm-8"><?= h($contract['client_name']) ?><?= $contract['client_email'] ? ' <span class="text-body-secondary">(' . h($contract['client_email']) . ')</span>' : '' ?></dd>
          <dt class="col-sm-4">Konto VLab</dt>
          <dd class="col-sm-8"><?= $contract['account_login'] ? h($contract['account_login']) : '<span class="text-body-secondary">—</span>' ?></dd>
          <dt class="col-sm-4">Data zawarcia</dt>
          <dd class="col-sm-8"><?= $contract['data_zawarcia'] ? h(date('d.m.Y', strtotime($contract['data_zawarcia']))) : '—' ?></dd>
          <dt class="col-sm-4">Ważna do</dt>
          <dd class="col-sm-8"><?= $contract['data_waznosci'] ? h(date('d.m.Y', strtotime($contract['data_waznosci']))) : '<span class="text-body-secondary">bezterminowa</span>' ?></dd>
          <dt class="col-sm-4">Cel dostępu</dt>
          <dd class="col-sm-8"><?= $contract['cel_dostepu'] ? h($contract['cel_dostepu']) : '<span class="text-body-secondary">—</span>' ?></dd>
          <dt class="col-sm-4">Podpisana przez</dt>
          <dd class="col-sm-8"><?= $contract['podpisana_przez'] ? h($contract['podpisana_przez']) : '<span class="text-body-secondary">—</span>' ?></dd>
          <?php if ($contract['uwagi']): ?>
          <dt class="col-sm-4">Uwagi</dt>
          <dd class="col-sm-8"><?= h($contract['uwagi']) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>
  </div>
  <div class="col-md-5">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold"><i class="bi bi-card-text text-primary me-1"></i>Warunki dostępu</div>
      <div class="card-body small">
        <?php if ($contract['warunki_html']): ?>
        <?= $contract['warunki_html'] ?>
        <?php else: ?>
        <span class="text-body-secondary">Brak treści warunków.</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($can_write): ?>
<!-- Modal edycji -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-modal="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="editModalLabel"><i class="bi bi-pencil me-1"></i>Edytuj umowę</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <form method="post" id="edit-form">
        <div class="modal-body">
          <?= csrf_field() ?>
          <input type="hidden" name="_op" value="update">
          <input type="hidden" name="contract_id" value="<?= $contract['id'] ?>">

          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Konto VLab</label>
              <select name="account_id" class="form-select form-select-sm">
                <option value="">— brak —</option>
                <?php $sa = db_all("SELECT id, login, is_active FROM k30_ti_student_accounts WHERE client_id=? ORDER BY login", [$contract['client_id']]);
                foreach ($sa as $a): ?>
                <option value="<?= $a['id'] ?>" <?= (int)$contract['account_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                  <?= h($a['login']) ?><?= !$a['is_active'] ? ' (nieaktywne)' : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Status</label>
              <select name="status" class="form-select form-select-sm">
                <?php foreach (VLAB_CONTRACT_STATUSES as $slug => $info): ?>
                <option value="<?= $slug ?>" <?= $contract['status'] === $slug ? 'selected' : '' ?>><?= h($info['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Data zawarcia</label>
              <input type="date" name="data_zawarcia" class="form-control form-control-sm"
                     value="<?= h($contract['data_zawarcia']) ?>" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Ważna do <span class="text-body-secondary fw-normal">(puste = bezterminowa)</span></label>
              <input type="date" name="data_waznosci" class="form-control form-control-sm"
                     value="<?= h($contract['data_waznosci'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Cel dostępu</label>
              <input type="text" name="cel_dostepu" class="form-control form-control-sm"
                     value="<?= h($contract['cel_dostepu']) ?>" maxlength="300">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Warunki dostępu (HTML)</label>
              <div id="vc-editor" style="min-height:200px"></div>
              <textarea name="warunki_html" id="vc-body" class="d-none"><?= h($contract['warunki_html']) ?></textarea>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Podpisana przez</label>
              <input type="text" name="podpisana_przez" class="form-control form-control-sm"
                     value="<?= h($contract['podpisana_przez']) ?>" maxlength="200">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold small">Uwagi</label>
              <input type="text" name="uwagi" class="form-control form-control-sm"
                     value="<?= h($contract['uwagi']) ?>" maxlength="500">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-floppy me-1"></i>Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>


<?php /* ═══════════════════════════════════ NOWA UMOWA ═════════════════════════════════════ */
elseif ($new_mode): ?>

<h4 class="fw-bold mb-3"><i class="bi bi-file-earmark-plus text-success me-2"></i>Nowa umowa o dostęp do VLab</h4>

<div class="card shadow-sm" style="max-width:760px">
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="_op" value="create">

      <div class="row g-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="new-client">Kursant <span class="text-danger">*</span></label>
          <select name="client_id" id="new-client" class="form-select" required
                  onchange="this.form.action='vlab_contracts.php?new&client_id='+this.value;this.form.submit()">
            <option value="">— wybierz kursanta —</option>
            <?php foreach ($clients as $cl): ?>
            <option value="<?= $cl['id'] ?>" <?= (int)($_GET['client_id'] ?? 0) === (int)$cl['id'] ? 'selected' : '' ?>>
              <?= h($cl['name']) ?><?= $cl['email'] ? ' — ' . h($cl['email']) : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Jeśli kursanta nie ma na liście, najpierw utwórz mu konto TI.</div>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="new-account">Konto VLab</label>
          <select name="account_id" id="new-account" class="form-select">
            <option value="">— brak / przypisz później —</option>
            <?php foreach ($student_accounts as $a): ?>
            <option value="<?= $a['id'] ?>"><?= h($a['login']) ?><?= !$a['is_active'] ? ' (nieaktywne)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="new-status">Status</label>
          <select name="status" id="new-status" class="form-select">
            <?php foreach (VLAB_CONTRACT_STATUSES as $slug => $info): ?>
            <option value="<?= $slug ?>" <?= $slug === 'projekt' ? 'selected' : '' ?>><?= h($info['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold" for="new-data">Data zawarcia</label>
          <input type="date" name="data_zawarcia" id="new-data" class="form-control"
                 value="<?= date('Y-m-d') ?>" required>
          <div class="form-text">Wyznacza numer: VL/ddmmrr/nnn</div>
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold" for="new-waz">Ważna do</label>
          <input type="date" name="data_waznosci" id="new-waz" class="form-control">
          <div class="form-text">Puste = bezterminowa</div>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold" for="new-cel">Cel dostępu</label>
          <input type="text" name="cel_dostepu" id="new-cel" class="form-control"
                 placeholder="np. nauka programowania, kurs cyberbezpieczeństwa" maxlength="300">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Warunki dostępu</label>
          <div id="vc-editor-new" style="min-height:180px"></div>
          <textarea name="warunki_html" id="vc-body-new" class="d-none"></textarea>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="new-podp">Podpisana przez</label>
          <input type="text" name="podpisana_przez" id="new-podp" class="form-control"
                 value="<?= h($user['name'] ?? '') ?>" maxlength="200">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="new-uwagi">Uwagi</label>
          <input type="text" name="uwagi" id="new-uwagi" class="form-control" maxlength="500">
        </div>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-success">
          <i class="bi bi-file-earmark-plus me-1"></i>Utwórz umowę
        </button>
        <a href="vlab_contracts.php" class="btn btn-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>


<?php /* ════════════════════════════════════ LISTA ═══════════════════════════════════════════ */
else: ?>

<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-lock text-primary me-2"></i>Umowy VLab</h4>
  <span class="badge text-bg-secondary"><?= count($contracts) ?></span>
  <?php if ($can_write): ?>
  <a href="vlab_contracts.php?new" class="btn btn-success btn-sm ms-auto">
    <i class="bi bi-plus-lg me-1"></i>Nowa umowa
  </a>
  <?php endif; ?>
</div>

<!-- Filtry -->
<form class="row g-2 mb-3" method="get">
  <div class="col-sm-4">
    <input type="search" name="q" class="form-control form-control-sm"
           placeholder="Szukaj (numer, kursant)…" value="<?= h($filters['q']) ?>">
  </div>
  <div class="col-sm-3">
    <select name="status" class="form-select form-select-sm">
      <option value="">Wszystkie statusy</option>
      <?php foreach (VLAB_CONTRACT_STATUSES as $slug => $info): ?>
      <option value="<?= $slug ?>" <?= $filters['status'] === $slug ? 'selected' : '' ?>><?= h($info['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <button type="submit" class="btn btn-secondary btn-sm">Filtruj</button>
    <a href="vlab_contracts.php" class="btn btn-link btn-sm">Wyczyść</a>
  </div>
</form>

<?php if ($contracts): ?>
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Numer umowy</th>
          <th>Kursant</th>
          <th>Konto VLab</th>
          <th>Data zawarcia</th>
          <th>Ważna do</th>
          <th>Status</th>
          <th><span class="visually-hidden">Akcje</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($contracts as $c):
          $st  = VLAB_CONTRACT_STATUSES[$c['status']] ?? ['label' => $c['status'], 'class' => 'secondary'];
          $cc2 = STATUS_CUSTOM_COLORS[$st['class']] ?? null;
          $sty = $cc2 ? 'style="background:' . $cc2 . ';color:#fff"' : '';
        ?>
        <tr>
          <td class="font-monospace small fw-semibold">
            <a href="vlab_contracts.php?id=<?= $c['id'] ?>" class="text-decoration-none"><?= h($c['numer_umowy']) ?></a>
          </td>
          <td>
            <div><?= h($c['client_name']) ?></div>
            <?php if ($c['client_email']): ?>
            <div class="text-body-secondary small"><?= h($c['client_email']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small"><?= $c['account_login'] ? h($c['account_login']) : '<span class="text-body-secondary">—</span>' ?></td>
          <td class="small text-nowrap"><?= $c['data_zawarcia'] ? h(date('d.m.Y', strtotime($c['data_zawarcia']))) : '—' ?></td>
          <td class="small text-nowrap">
            <?= $c['data_waznosci'] ? h(date('d.m.Y', strtotime($c['data_waznosci']))) : '<span class="text-body-secondary">bezterm.</span>' ?>
          </td>
          <td><span class="badge text-bg-<?= $st['class'] ?>" <?= $sty ?>><?= h($st['label']) ?></span></td>
          <td>
            <a href="vlab_contracts.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary py-0">
              <i class="bi bi-eye"></i>
            </a>
            <a href="vlab_contracts.php?pdf=<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary py-0" title="PDF">
              <i class="bi bi-filetype-pdf"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="text-body-secondary">
  <i class="bi bi-inbox me-1"></i>Brak umów<?= $filters['q'] || $filters['status'] ? ' spełniających kryteria.' : '.' ?>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- Quill WYSIWYG -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.min.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
(function(){
  function initQuill(editorId, textareaId, formId) {
    const ta = document.getElementById(textareaId);
    const el = document.getElementById(editorId);
    if (!ta || !el) return;
    const q = new Quill(el, {
      theme: 'snow',
      modules: { toolbar: [[{header:[1,2,false]}],['bold','italic','underline'],[{list:'ordered'},{list:'bullet'}],['link'],['clean']] }
    });
    const ex = ta.value.trim();
    if (ex) q.root.innerHTML = ex;
    const form = formId ? document.getElementById(formId) : el.closest('form');
    if (form) form.addEventListener('submit', () => { ta.value = q.root.innerHTML; });
  }
  initQuill('vc-editor',     'vc-body',     'edit-form');
  initQuill('vc-editor-new', 'vc-body-new', null);
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
