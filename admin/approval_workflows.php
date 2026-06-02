<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/approval_workflow.php';

require_role('admin');
_awf_init();

$PAGE_TITLE = 'Szablony workflow akceptacji';

// ── Obsługa POST ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // --- Zapisz workflow ---
    if ($action === 'save_workflow') {
        $wid  = intval($_POST['workflow_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $active  = isset($_POST['is_active'])  ? 1 : 0;
        $default = isset($_POST['is_default']) ? 1 : 0;

        if ($name === '') {
            flash_set('danger', 'Nazwa workflow jest wymagana.');
            header('Location: ' . APP_URL . '/admin/approval_workflows.php'); exit;
        }

        if ($default) {
            // Usuń domyślność innych
            db()->prepare("UPDATE approval_workflows SET is_default=0")->execute();
        }

        if ($wid) {
            db()->prepare(
                "UPDATE approval_workflows SET name=?, description=?, is_active=?, is_default=? WHERE id=?"
            )->execute([$name, $desc, $active, $default, $wid]);
            flash_set('success', 'Workflow zaktualizowany.');
        } else {
            $wid = db_insert('approval_workflows', [
                'name'        => $name,
                'description' => $desc,
                'is_active'   => $active,
                'is_default'  => $default,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            flash_set('success', 'Workflow dodany.');
        }
        header('Location: ' . APP_URL . '/admin/approval_workflows.php?edit=' . $wid . '#workflow-' . $wid); exit;
    }

    // --- Usuń workflow ---
    if ($action === 'delete_workflow') {
        $wid = intval($_POST['workflow_id'] ?? 0);
        if ($wid) {
            db()->prepare("DELETE FROM approval_workflows WHERE id=?")->execute([$wid]);
            flash_set('success', 'Workflow usunięty.');
        }
        header('Location: ' . APP_URL . '/admin/approval_workflows.php'); exit;
    }

    // --- Ustaw jako domyślny ---
    if ($action === 'set_default') {
        $wid = intval($_POST['workflow_id'] ?? 0);
        if ($wid) {
            db()->prepare("UPDATE approval_workflows SET is_default=0")->execute();
            db()->prepare("UPDATE approval_workflows SET is_default=1 WHERE id=?")->execute([$wid]);
            flash_set('success', 'Domyślny workflow ustawiony.');
        }
        header('Location: ' . APP_URL . '/admin/approval_workflows.php#workflow-' . $wid); exit;
    }

    // --- Zapisz krok ---
    if ($action === 'save_step') {
        $step_id    = intval($_POST['step_id'] ?? 0);
        $wid        = intval($_POST['workflow_id'] ?? 0);
        $step_order = intval($_POST['step_order'] ?? 1);
        $sname      = trim($_POST['step_name'] ?? '');
        $req_all    = isset($_POST['require_all']) ? 1 : 0;
        $approver_ids = array_map('intval', (array)($_POST['approver_ids'] ?? []));

        if (!$wid || !$sname) {
            flash_set('danger', 'Brak wymaganych danych kroku.');
            header('Location: ' . APP_URL . '/admin/approval_workflows.php?edit=' . $wid . '#steps'); exit;
        }

        if ($step_id) {
            db()->prepare(
                "UPDATE approval_workflow_steps SET step_order=?, name=?, require_all=? WHERE id=?"
            )->execute([$step_order, $sname, $req_all, $step_id]);
            db()->prepare("DELETE FROM approval_workflow_approvers WHERE step_id=?")->execute([$step_id]);
        } else {
            $step_id = db_insert('approval_workflow_steps', [
                'workflow_id' => $wid,
                'step_order'  => $step_order,
                'name'        => $sname,
                'require_all' => $req_all,
            ]);
        }

        foreach ($approver_ids as $uid) {
            if ($uid) {
                db_insert('approval_workflow_approvers', ['step_id' => $step_id, 'user_id' => $uid]);
            }
        }

        flash_set('success', 'Krok zapisany.');
        header('Location: ' . APP_URL . '/admin/approval_workflows.php?edit=' . $wid . '#steps'); exit;
    }

    // --- Usuń krok ---
    if ($action === 'delete_step') {
        $step_id = intval($_POST['step_id'] ?? 0);
        $wid     = intval($_POST['workflow_id'] ?? 0);
        if ($step_id) {
            db()->prepare("DELETE FROM approval_workflow_steps WHERE id=?")->execute([$step_id]);
            flash_set('success', 'Krok usunięty.');
        }
        header('Location: ' . APP_URL . '/admin/approval_workflows.php?edit=' . $wid . '#steps'); exit;
    }

    // --- Przypisania typów umów ---
    if ($action === 'save_contract_types') {
        db()->prepare("DELETE FROM approval_workflow_contracts")->execute();
        $assignments = $_POST['contract_workflow'] ?? [];
        foreach ($assignments as $ctype => $wf_id) {
            $wf_id = intval($wf_id);
            if ($wf_id) {
                db_insert('approval_workflow_contracts', [
                    'workflow_id'   => $wf_id,
                    'contract_type' => $ctype,
                ]);
            }
        }
        flash_set('success', 'Przypisania typów umów zapisane.');
        header('Location: ' . APP_URL . '/admin/approval_workflows.php#contract-types'); exit;
    }
}

// ── Pobierz dane ──────────────────────────────────────────────────────────────

$workflows    = db_all("SELECT * FROM approval_workflows ORDER BY is_default DESC, name ASC");
$all_users    = db_all("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name");
$ct_map       = db_all("SELECT * FROM approval_workflow_contracts");
$ct_workflow  = [];
foreach ($ct_map as $r) {
    $ct_workflow[$r['contract_type']] = $r['workflow_id'];
}

$editing_wid  = intval($_GET['edit'] ?? 0);
$editing_wf   = $editing_wid ? db_one("SELECT * FROM approval_workflows WHERE id=?", [$editing_wid]) : null;
$editing_steps = $editing_wf ? awf_get_steps($editing_wid) : [];

// Maks krok dla nowego numeru
$max_step_order = 1;
if ($editing_steps) {
    $max_step_order = max(array_column($editing_steps, 'step_order')) + 1;
}

include dirname(__DIR__) . '/includes/header.php';
?>

<h4 class="mb-3"><i class="bi bi-diagram-3 text-primary"></i> Szablony workflow akceptacji</h4>
<?= flash_html() ?>

<div class="row g-4">

<!-- ═══════════════════════════════════════════════════════════════════════════
     LEWA KOLUMNA: Lista workflow
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="col-lg-7">

  <!-- Przycisk dodaj nowy -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Skonfigurowane workflow</h5>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalWorkflow"
            data-wid="0" data-name="" data-desc="" data-active="1" data-default="0">
      <i class="bi bi-plus-lg"></i> Nowy workflow
    </button>
  </div>

  <?php if (!$workflows): ?>
  <div class="alert alert-info">Brak skonfigurowanych workflow. Kliknij „Nowy workflow", aby dodać pierwszy.</div>
  <?php endif; ?>

  <?php foreach ($workflows as $wf):
    $wf_steps = awf_get_steps($wf['id']);
  ?>
  <div class="card mb-3 shadow-sm" id="workflow-<?= $wf['id'] ?>">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <strong><?= h($wf['name']) ?></strong>
        <?php if ($wf['is_default']): ?><span class="badge bg-primary ms-1">Domyślny</span><?php endif; ?>
        <?php if (!$wf['is_active']): ?><span class="badge bg-secondary ms-1">Nieaktywny</span><?php endif; ?>
      </div>
      <div class="d-flex gap-1">
        <a href="?edit=<?= $wf['id'] ?>#steps" class="btn btn-sm btn-outline-primary" title="Edytuj kroki">
          <i class="bi bi-list-ol"></i> Kroki
        </a>
        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalWorkflow"
                data-wid="<?= $wf['id'] ?>"
                data-name="<?= h($wf['name']) ?>"
                data-desc="<?= h($wf['description']) ?>"
                data-active="<?= $wf['is_active'] ?>"
                data-default="<?= $wf['is_default'] ?>">
          <i class="bi bi-pencil"></i>
        </button>
        <?php if (!$wf['is_default']): ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="set_default">
          <input type="hidden" name="workflow_id" value="<?= $wf['id'] ?>">
          <button class="btn btn-sm btn-outline-info" title="Ustaw jako domyślny">
            <i class="bi bi-star"></i>
          </button>
        </form>
        <?php endif; ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Usuń workflow «<?= h(addslashes($wf['name'])) ?>»?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="delete_workflow">
          <input type="hidden" name="workflow_id" value="<?= $wf['id'] ?>">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i></button>
        </form>
      </div>
    </div>
    <?php if ($wf['description']): ?>
    <div class="card-body py-2 px-3 text-muted" style="font-size:.85em"><?= h($wf['description']) ?></div>
    <?php endif; ?>
    <div class="card-body pt-0 pb-2 px-3">
      <?php if (!$wf_steps): ?>
      <p class="text-muted mb-0" style="font-size:.85em"><em>Brak zdefiniowanych kroków — <a href="?edit=<?= $wf['id'] ?>#steps">dodaj</a></em></p>
      <?php endif; ?>
      <?php foreach ($wf_steps as $i => $step): ?>
      <div class="d-flex align-items-start gap-2 py-1 <?= $i ? 'border-top' : '' ?>">
        <span class="badge bg-light text-dark border mt-1"><?= $step['step_order'] ?></span>
        <div style="font-size:.88em">
          <strong><?= h($step['name']) ?></strong>
          <?php if ($step['require_all']): ?>
          <span class="badge bg-warning text-dark ms-1" title="Wszyscy muszą zaakceptować">Wszyscy</span>
          <?php else: ?>
          <span class="badge bg-primary ms-1" title="Wystarczy jeden akceptor">Dowolny</span>
          <?php endif; ?>
          <br>
          <?php foreach ($step['approvers'] as $ap): ?>
          <span class="badge bg-light text-dark border me-1"><?= h($ap['name']) ?></span>
          <?php endforeach; ?>
          <?php if (!$step['approvers']): ?>
          <span class="text-danger" style="font-size:.85em"><em>Brak akceptorów!</em></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

</div><!-- /col-lg-7 -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     PRAWA KOLUMNA: Przypisania + Edycja kroków
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="col-lg-5">

  <!-- Przypisania typów umów -->
  <div class="card shadow-sm mb-4" id="contract-types">
    <div class="card-header"><strong><i class="bi bi-link-45deg"></i> Typy umów → Workflow</strong></div>
    <div class="card-body">
      <p class="text-muted" style="font-size:.85em">Wybierz workflow dla każdego typu umowy. Jeśli nie wybrano, stosowany jest domyślny workflow (lub brak — jeśli żaden nie jest domyślny).</p>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_contract_types">
        <table class="table table-sm mb-3">
          <thead class="table-light"><tr><th>Typ umowy</th><th>Workflow</th></tr></thead>
          <tbody>
          <?php foreach (CONTRACT_TYPES as $ctype => $clabel): ?>
          <tr>
            <td><?= h($clabel) ?></td>
            <td>
              <select name="contract_workflow[<?= h($ctype) ?>]" class="form-select form-select-sm">
                <option value="">— fallback do admina —</option>
                <?php foreach ($workflows as $wf): ?>
                <option value="<?= $wf['id'] ?>"
                  <?= (($ct_workflow[$ctype] ?? 0) == $wf['id']) ? 'selected' : '' ?>>
                  <?= h($wf['name']) ?><?= $wf['is_active'] ? '' : ' [nieaktywny]' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Zapisz przypisania</button>
      </form>
    </div>
  </div>

  <!-- Edycja kroków wybranego workflow -->
  <?php if ($editing_wf): ?>
  <div class="card shadow-sm" id="steps">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong><i class="bi bi-list-ol"></i> Kroki: <?= h($editing_wf['name']) ?></strong>
      <a href="<?= APP_URL ?>/admin/approval_workflows.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-x-lg"></i>
      </a>
    </div>
    <div class="card-body p-0">

      <!-- Istniejące kroki -->
      <?php if (!$editing_steps): ?>
      <p class="text-muted p-3 mb-0"><em>Brak kroków. Dodaj pierwszy poniżej.</em></p>
      <?php endif; ?>
      <?php foreach ($editing_steps as $step): ?>
      <div class="border-bottom p-3">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div>
            <span class="badge bg-secondary me-1">#<?= $step['step_order'] ?></span>
            <strong><?= h($step['name']) ?></strong>
            <?php if ($step['require_all']): ?>
            <span class="badge bg-warning text-dark ms-1">Wszyscy</span>
            <?php else: ?>
            <span class="badge bg-primary ms-1">Dowolny</span>
            <?php endif; ?>
          </div>
          <div class="d-flex gap-1">
            <button class="btn btn-xs btn-outline-secondary btn-sm" style="padding:1px 6px;font-size:.75em"
                    data-bs-toggle="collapse" data-bs-target="#editStep<?= $step['id'] ?>">
              <i class="bi bi-pencil"></i>
            </button>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć krok?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete_step">
              <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
              <input type="hidden" name="workflow_id" value="<?= $editing_wid ?>">
              <button class="btn btn-xs btn-outline-danger btn-sm" style="padding:1px 6px;font-size:.75em">
                <i class="bi bi-trash3"></i>
              </button>
            </form>
          </div>
        </div>

        <!-- Akceptorzy -->
        <div style="font-size:.83em">
        <?php foreach ($step['approvers'] as $ap): ?>
        <span class="badge bg-light text-dark border me-1"><?= h($ap['name']) ?></span>
        <?php endforeach; ?>
        <?php if (!$step['approvers']): ?><span class="text-danger">Brak akceptorów!</span><?php endif; ?>
        </div>

        <!-- Formularz edycji (collapse) -->
        <div class="collapse mt-2" id="editStep<?= $step['id'] ?>">
          <form method="post" class="border rounded p-2 bg-light">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save_step">
            <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
            <input type="hidden" name="workflow_id" value="<?= $editing_wid ?>">
            <div class="row g-2 mb-2">
              <div class="col-3">
                <label class="form-label mb-0" style="font-size:.8em">Kolejność</label>
                <input type="number" name="step_order" class="form-control form-control-sm" value="<?= $step['step_order'] ?>" min="1">
              </div>
              <div class="col-9">
                <label class="form-label mb-0" style="font-size:.8em">Nazwa kroku</label>
                <input type="text" name="step_name" class="form-control form-control-sm" value="<?= h($step['name']) ?>" required>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label mb-0" style="font-size:.8em">Akceptorzy</label>
              <select name="approver_ids[]" class="form-select form-select-sm" multiple size="4">
                <?php
                $cur_approver_ids = array_column($step['approvers'], 'user_id');
                foreach ($all_users as $u): ?>
                <option value="<?= $u['id'] ?>"
                  <?= in_array($u['id'], $cur_approver_ids) ? 'selected' : '' ?>>
                  <?= h($u['name']) ?> (<?= h($u['email']) ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" style="font-size:.75em">Ctrl/Cmd + klik aby wybrać kilka.</div>
            </div>
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" name="require_all" id="reqAll<?= $step['id'] ?>"
                     <?= $step['require_all'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="reqAll<?= $step['id'] ?>" style="font-size:.85em">
                Wszyscy muszą zaakceptować (domyślnie: wystarczy jeden)
              </label>
            </div>
            <button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Zapisz</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Dodaj nowy krok -->
      <div class="p-3">
        <h6 class="text-muted mb-2" style="font-size:.85em">DODAJ NOWY KROK</h6>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save_step">
          <input type="hidden" name="step_id" value="0">
          <input type="hidden" name="workflow_id" value="<?= $editing_wid ?>">
          <div class="row g-2 mb-2">
            <div class="col-3">
              <label class="form-label mb-0" style="font-size:.8em">Kolejność</label>
              <input type="number" name="step_order" class="form-control form-control-sm" value="<?= $max_step_order ?>" min="1">
            </div>
            <div class="col-9">
              <label class="form-label mb-0" style="font-size:.8em">Nazwa kroku</label>
              <input type="text" name="step_name" class="form-control form-control-sm" placeholder="np. Kierownik działu" required>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label mb-0" style="font-size:.8em">Akceptorzy</label>
            <select name="approver_ids[]" class="form-select form-select-sm" multiple size="5">
              <?php foreach ($all_users as $u): ?>
              <option value="<?= $u['id'] ?>"><?= h($u['name']) ?> (<?= h($u['email']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <div class="form-text" style="font-size:.75em">Ctrl/Cmd + klik aby wybrać kilka.</div>
          </div>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="require_all" id="reqAllNew">
            <label class="form-check-label" for="reqAllNew" style="font-size:.85em">
              Wszyscy muszą zaakceptować
            </label>
          </div>
          <button class="btn btn-success btn-sm"><i class="bi bi-plus-lg"></i> Dodaj krok</button>
        </form>
      </div>

    </div>
  </div>
  <?php endif; ?>

</div><!-- /col-lg-5 -->
</div><!-- /row -->

<!-- ── Modal: Utwórz/Edytuj workflow ─────────────────────────────────────── -->
<div class="modal fade" id="modalWorkflow" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalWorkflowTitle"><i class="bi bi-diagram-3"></i> Workflow</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" id="formWorkflow">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_workflow">
        <input type="hidden" name="workflow_id" id="modalWfId" value="0">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Nazwa <span class="text-danger">*</span></label>
            <input type="text" name="name" id="modalWfName" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Opis</label>
            <textarea name="description" id="modalWfDesc" class="form-control" rows="2"
                      placeholder="Opcjonalny opis..."></textarea>
          </div>
          <div class="d-flex gap-4">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="is_active" id="modalWfActive">
              <label class="form-check-label" for="modalWfActive">Aktywny</label>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="is_default" id="modalWfDefault">
              <label class="form-check-label" for="modalWfDefault">Domyślny</label>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('modalWorkflow');
  modal.addEventListener('show.bs.modal', function (e) {
    var t = e.relatedTarget;
    var wid = t.dataset.wid || '0';
    document.getElementById('modalWfId').value  = wid;
    document.getElementById('modalWfName').value = t.dataset.name || '';
    document.getElementById('modalWfDesc').value = t.dataset.desc || '';
    document.getElementById('modalWfActive').checked  = t.dataset.active  === '1';
    document.getElementById('modalWfDefault').checked = t.dataset.default === '1';
    document.getElementById('modalWorkflowTitle').innerHTML =
      '<i class="bi bi-diagram-3"></i> ' + (wid === '0' ? 'Nowy workflow' : 'Edytuj workflow');
  });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
