<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once dirname(dirname(__DIR__)) . '/includes/amendments.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval_workflow.php';
_awf_init();

require_role('admin');
require_module_enabled('approvals_enabled', 'Moduł obiegu dokumentów');
$PAGE_TITLE = 'Akceptacje';

$tab    = $_GET['tab']    ?? 'approvals';
$status = $_GET['status'] ?? 'oczekuje';

// Counts for badges
$cnt = [];
foreach (['contract_approvals' => 'approvals', 'contract_amendments' => 'amendments', 'contract_edit_requests' => 'changes'] as $tbl => $key) {
    try { $cnt[$key] = (int) db_one("SELECT COUNT(*) AS c FROM {$tbl} WHERE status='oczekuje'")['c']; }
    catch (\Exception $e) { $cnt[$key] = 0; }
}
try { $cnt['workflow'] = (int) db_one("SELECT COUNT(*) AS c FROM approval_requests WHERE status='oczekuje'")['c']; }
catch (\Exception $e) { $cnt['workflow'] = 0; }

function status_tabs(string $current, string $tab): string {
    $out = '<div class="btn-group btn-group-sm mb-3">';
    foreach (['oczekuje' => 'Oczekujące', 'zaakceptowana' => 'Zaakceptowane', 'odrzucona' => 'Odrzucone', '' => 'Wszystkie'] as $s => $l) {
        $active = $current === $s ? ' active' : '';
        $out .= "<a href='?tab={$tab}&status={$s}' class='btn btn-outline-secondary{$active}'>{$l}</a>";
    }
    return $out . '</div>';
}

function fetch_rows(string $tbl, string $status, string $userCol = 'requested_by'): array {
    $w = $status ? "WHERE a.status=?" : "WHERE 1=1";
    $p = $status ? [$status] : [];
    return db_all(
        "SELECT a.*, u.name AS requested_by_name
         FROM {$tbl} a LEFT JOIN users u ON u.id = a.{$userCol}
         {$w} ORDER BY a.requested_at DESC", $p);
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<h4 class="mb-3"><i class="bi bi-check2-square text-primary"></i> Akceptacje</h4>
<?= flash_html() ?>

<!-- Tab navigation -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $tab==='approvals'?'active':'' ?>" href="?tab=approvals&status=<?= h($status) ?>">
      <i class="bi bi-check2-square"></i> Akceptacje umów
      <?php if ($cnt['approvals']): ?><span class="badge bg-warning text-dark ms-1"><?= $cnt['approvals'] ?></span><?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='amendments'?'active':'' ?>" href="?tab=amendments&status=<?= h($status) ?>">
      <i class="bi bi-file-earmark-diff"></i> Aneksy
      <?php if ($cnt['amendments']): ?><span class="badge bg-warning text-dark ms-1"><?= $cnt['amendments'] ?></span><?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='changes'?'active':'' ?>" href="?tab=changes&status=<?= h($status) ?>">
      <i class="bi bi-pencil-square"></i> Wnioski o edycję
      <?php if ($cnt['changes']): ?><span class="badge bg-warning text-dark ms-1"><?= $cnt['changes'] ?></span><?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='workflow'?'active':'' ?>" href="?tab=workflow&status=<?= h($status) ?>">
      <i class="bi bi-diagram-3"></i> Wielostopniowe
      <?php if ($cnt['workflow']): ?><span class="badge bg-warning text-dark ms-1"><?= $cnt['workflow'] ?></span><?php endif; ?>
    </a>
  </li>
</ul>

<?php
// ── TAB: Akceptacje umów ──────────────────────────────────────────────────────
if ($tab === 'approvals'):
    $statusMap = ['oczekuje'=>'oczekuje','zaakceptowana'=>'zaakceptowana','odrzucona'=>'odrzucona'];
    $st  = $statusMap[$status] ?? '';
    $rows = fetch_rows('contract_approvals', $st);
    echo status_tabs($st, 'approvals');
?>
<?php if (!$rows): ?>
<div class="alert alert-info">Brak wpisów.</div>
<?php else: ?>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover mb-0">
  <thead class="table-light"><tr><th>Typ</th><th>Numer</th><th>Wnioskujący</th><th>Data</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $is_canva = $r['contract_type'] === 'canva_request';
      $tbl    = table_for_type($r['contract_type']);
      $c      = db_one("SELECT numer_umowy, imie_nazwisko FROM {$tbl} WHERE id=?", [$r['contract_id']]);
      $numer  = $c['numer_umowy'] ?? '(usunięta)';
      $vurl   = contract_url($r['contract_type'], (int)$r['contract_id']);
      $type_label = $is_canva
          ? '<span style="color:#7c3aed">🎨 Prośba o Canva</span>'
          : h(CONTRACT_TYPES[$r['contract_type']] ?? $r['contract_type']);
  ?>
  <tr>
    <td><small class="text-muted"><?= $type_label ?></small></td>
    <td><a href="<?= $vurl ?>"><strong><?= h($is_canva ? ($c['imie_nazwisko'] ?? $numer) : $numer) ?></strong></a>
      <?php if ($is_canva): ?><div class="text-muted" style="font-size:.75rem"><?= h($numer) ?></div><?php endif; ?>
    </td>
    <td><?= h($r['requested_by_name'] ?? '—') ?></td>
    <td><?= date_pl($r['requested_at']) ?></td>
    <td><?= approval_badge($r['status']) ?></td>
    <td class="text-end">
      <a href="<?= $vurl ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
      <?php if ($r['status'] === 'oczekuje'): ?>
      <button type="button" class="btn btn-sm btn-outline-secondary"
        data-bs-toggle="modal" data-bs-target="#decisionModal"
        data-id="<?= $r['id'] ?>"
        data-numer="<?= h($numer) ?>"
        data-action="<?= APP_URL ?>/contracts/approvals/approve.php"
        data-field="approval_id"
        data-accept="zaakceptowana"
        data-reject="odrzucona">
        <i class="bi bi-chat-square-dots"></i> Decyduj
      </button>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>

<?php
// ── TAB: Aneksy ───────────────────────────────────────────────────────────────
elseif ($tab === 'amendments'):
    $statusMap = ['oczekuje'=>'oczekuje','zaakceptowana'=>'zaakceptowany','odrzucona'=>'odrzucony'];
    $st   = $statusMap[$status] ?? '';
    $rows = fetch_rows('contract_amendments', $st);
    echo status_tabs($status, 'amendments');
?>
<?php if (!$rows): ?>
<div class="alert alert-info">Brak aneksów.</div>
<?php else: ?>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover mb-0">
  <thead class="table-light"><tr><th>Typ</th><th>Numer</th><th>Aneks</th><th>Opis</th><th>Wnioskujący</th><th>Data</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $tbl   = table_for_type($r['contract_type']);
      $c     = db_one("SELECT numer_umowy FROM {$tbl} WHERE id=?", [$r['contract_id']]);
      $numer = $c['numer_umowy'] ?? '(usunięta)';
      $vurl  = APP_URL . '/contracts/' . $r['contract_type'] . '/view.php?id=' . $r['contract_id'];
  ?>
  <tr>
    <td><small class="text-muted"><?= h(CONTRACT_TYPES[$r['contract_type']] ?? $r['contract_type']) ?></small></td>
    <td><a href="<?= $vurl ?>"><strong><?= h($numer) ?></strong></a></td>
    <td><span class="badge bg-secondary">#<?= $r['numer_aneksu'] ?></span></td>
    <td class="text-truncate" style="max-width:200px"><?= h($r['opis_zmian']) ?></td>
    <td><?= h($r['requested_by_name'] ?? '—') ?></td>
    <td><?= date_pl($r['requested_at']) ?></td>
    <td><?= amendment_badge($r['status']) ?></td>
    <td class="text-end">
      <a href="<?= $vurl ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
      <?php if ($r['status'] === 'oczekuje'): ?>
      <button type="button" class="btn btn-sm btn-outline-secondary"
        data-bs-toggle="modal" data-bs-target="#decisionModal"
        data-id="<?= $r['id'] ?>"
        data-numer="<?= h($numer) ?>"
        data-action="<?= APP_URL ?>/contracts/approvals/amendments_approve.php"
        data-field="amendment_id"
        data-accept="zaakceptowany"
        data-reject="odrzucony">
        <i class="bi bi-chat-square-dots"></i> Decyduj
      </button>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>

<?php
// ── TAB: Wnioski o edycję ─────────────────────────────────────────────────────
elseif ($tab === 'changes'):
    $statusMap = ['oczekuje'=>'oczekuje','zaakceptowana'=>'zaakceptowany','odrzucona'=>'odrzucony'];
    $st   = $statusMap[$status] ?? '';
    $rows = fetch_rows('contract_edit_requests', $st);
    echo status_tabs($status, 'changes');
?>
<?php if (!$rows): ?>
<div class="alert alert-info">Brak wniosków o edycję.</div>
<?php else: ?>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover mb-0">
  <thead class="table-light"><tr><th>Typ</th><th>Numer</th><th>Opis żądanej zmiany</th><th>Wnioskujący</th><th>Data</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $tbl   = table_for_type($r['contract_type']);
      $c     = db_one("SELECT numer_umowy FROM {$tbl} WHERE id=?", [$r['contract_id']]);
      $numer = $c['numer_umowy'] ?? '(usunięta)';
      $vurl  = APP_URL . '/contracts/' . $r['contract_type'] . '/view.php?id=' . $r['contract_id'];
  ?>
  <tr>
    <td><small class="text-muted"><?= h(CONTRACT_TYPES[$r['contract_type']] ?? $r['contract_type']) ?></small></td>
    <td><a href="<?= $vurl ?>"><strong><?= h($numer) ?></strong></a></td>
    <td class="text-truncate" style="max-width:220px"><?= h($r['opis_zmian']) ?></td>
    <td><?= h($r['requested_by_name'] ?? '—') ?></td>
    <td><?= date_pl($r['requested_at']) ?></td>
    <td><?= edit_request_badge($r['status']) ?></td>
    <td class="text-end">
      <a href="<?= $vurl ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
      <?php if ($r['status'] === 'oczekuje'): ?>
      <button type="button" class="btn btn-sm btn-outline-secondary"
        data-bs-toggle="modal" data-bs-target="#decisionModal"
        data-id="<?= $r['id'] ?>"
        data-numer="<?= h($numer) ?>"
        data-action="<?= APP_URL ?>/contracts/approvals/changes_approve.php"
        data-field="request_id"
        data-accept="zaakceptowany"
        data-reject="odrzucony">
        <i class="bi bi-chat-square-dots"></i> Decyduj
      </button>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
<?php
// ── TAB: Wielostopniowe ───────────────────────────────────────────────────────
elseif ($tab === 'workflow'):
    $statusMapWf = ['oczekuje'=>'oczekuje','zaakceptowana'=>'zaakceptowana','odrzucona'=>'odrzucona'];
    $st_wf = $statusMapWf[$status] ?? '';
    $w_where = $st_wf ? "WHERE r.status=?" : "WHERE 1=1";
    $w_params = $st_wf ? [$st_wf] : [];
    $wf_rows = db_all(
        "SELECT r.*, u.name AS requested_by_name, w.name AS workflow_name
         FROM approval_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN approval_workflows w ON w.id = r.workflow_id
         {$w_where} ORDER BY r.requested_at DESC",
        $w_params
    );
    echo status_tabs($st_wf, 'workflow');
?>
<?php if (!$wf_rows): ?>
<div class="alert alert-info">Brak wniosków wielostopniowych.</div>
<?php else: ?>
<?php foreach ($wf_rows as $r):
    $tbl   = table_for_type($r['contract_type']);
    $c     = db_one("SELECT numer_umowy FROM {$tbl} WHERE id=?", [$r['contract_id']]);
    $numer = $c['numer_umowy'] ?? '(usunięta)';
    $vurl  = APP_URL . '/contracts/' . $r['contract_type'] . '/view.php?id=' . $r['contract_id'];
    $steps_status = awf_get_request_steps_status($r['id']);
    $total_steps  = count($steps_status);
    $done_steps   = 0;
    foreach ($steps_status as $s) {
        $approved = array_filter($s['decisions'], fn($d) => $d['status'] === 'zaakceptowana');
        if ($approved) $done_steps++;
    }
?>
<div class="card mb-3 shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center">
    <div>
      <small class="text-muted"><?= h(CONTRACT_TYPES[$r['contract_type']] ?? $r['contract_type']) ?></small>
      <a href="<?= $vurl ?>" class="ms-2 fw-bold"><?= h($numer) ?></a>
      <span class="badge bg-<?= $r['status'] === 'oczekuje' ? 'warning text-dark' : ($r['status'] === 'zaakceptowana' ? 'success' : 'secondary') ?> ms-1">
        <?= h(ucfirst($r['status'])) ?>
      </span>
    </div>
    <div class="text-end" style="font-size:.82em">
      <span class="text-muted">Workflow: <?= h($r['workflow_name'] ?? '—') ?></span><br>
      <span class="text-muted">Zgłoszono: <?= date_pl($r['requested_at']) ?> przez <?= h($r['requested_by_name'] ?? '—') ?></span>
    </div>
  </div>
  <div class="card-body py-2 px-3">
    <!-- Mini pasek postępu kroków -->
    <div class="d-flex align-items-center gap-1 flex-wrap mb-2">
      <small class="text-muted me-1">Postęp:</small>
      <?php foreach ($steps_status as $i => $step):
        $sorder  = $step['step_order'];
        $cur     = (int) $r['current_step_order'];
        $req_st  = $r['status'];
        $any_app = (bool) array_filter($step['decisions'], fn($d) => $d['status'] === 'zaakceptowana');
        $any_rej = (bool) array_filter($step['decisions'], fn($d) => $d['status'] === 'odrzucona');

        if ($any_rej) { $badge = 'bg-danger'; $icon = 'bi-x-circle-fill'; }
        elseif ($sorder < $cur || ($req_st === 'zaakceptowana')) { $badge = 'bg-success'; $icon = 'bi-check-circle-fill'; }
        elseif ($sorder === $cur && $req_st === 'oczekuje') { $badge = 'bg-warning text-dark'; $icon = 'bi-hourglass-split'; }
        else { $badge = 'bg-light border text-muted'; $icon = 'bi-circle'; }
      ?>
      <?php if ($i > 0): ?>
      <div class="border-top" style="width:20px;border-color:#aaa!important;margin-top:-2px"></div>
      <?php endif; ?>
      <span class="badge <?= $badge ?>" title="<?= h($step['step_name']) ?>">
        <i class="bi <?= $icon ?>"></i> <?= h($step['step_name']) ?>
      </span>
      <?php endforeach; ?>
    </div>
    <!-- Decyzje bieżącego kroku -->
    <?php
    $cur_step_data = null;
    foreach ($steps_status as $s) {
        if ($s['step_order'] == $r['current_step_order']) { $cur_step_data = $s; break; }
    }
    if ($cur_step_data && $r['status'] === 'oczekuje'):
    ?>
    <div style="font-size:.83em">
      <strong>Bieżący etap — <?= h($cur_step_data['step_name']) ?>:</strong>
      <?php foreach ($cur_step_data['decisions'] as $d): ?>
      <?php
        if ($d['status'] === 'zaakceptowana') $di = '<i class="bi bi-check-circle-fill text-success"></i>';
        elseif ($d['status'] === 'odrzucona')  $di = '<i class="bi bi-x-circle-fill text-danger"></i>';
        else $di = '<i class="bi bi-hourglass-split text-warning"></i>';
      ?>
      <span class="ms-2"><?= $di ?> <?= h($d['approver_name']) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-footer py-1 text-end">
    <a href="<?= $vurl ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Otwórz umowę</a>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>

<!-- Modal decyzji (wspólny dla wszystkich tabów) -->
<div class="modal fade" id="decisionModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-chat-square-dots"></i> Podejmij decyzję</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" id="decisionForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" id="modalIdField" name="" value="">
        <input type="hidden" name="decision" id="modalDecision" value="">
        <div class="modal-body">
          <p class="mb-3">Umowa: <strong id="modalNumer"></strong></p>
          <label class="form-label">Komentarz</label>
          <textarea name="decision_note" id="modalNote" class="form-control" rows="3"
            placeholder="Wymagany przy odrzuceniu..."></textarea>
          <div class="invalid-feedback">Komentarz jest wymagany przy odrzuceniu.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="button" class="btn btn-danger" id="btnReject">
            <i class="bi bi-x-lg"></i> Odrzuć
          </button>
          <button type="button" class="btn btn-success" id="btnAccept">
            <i class="bi bi-check-lg"></i> Akceptuję
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('decisionModal');
  var acceptVal = '', rejectVal = '';
  modal.addEventListener('show.bs.modal', function (e) {
    var t = e.relatedTarget;
    document.getElementById('decisionForm').action = t.dataset.action;
    var f = document.getElementById('modalIdField');
    f.name  = t.dataset.field;
    f.value = t.dataset.id;
    document.getElementById('modalNumer').textContent = t.dataset.numer;
    document.getElementById('modalNote').value = '';
    document.getElementById('modalNote').classList.remove('is-invalid');
    acceptVal = t.dataset.accept;
    rejectVal = t.dataset.reject;
  });
  function decide(decision) {
    var note = document.getElementById('modalNote');
    if (decision === rejectVal && !note.value.trim()) {
      note.classList.add('is-invalid');
      note.focus();
      return;
    }
    note.classList.remove('is-invalid');
    document.getElementById('modalDecision').value = decision;
    document.getElementById('decisionForm').submit();
  }
  document.getElementById('btnAccept').addEventListener('click', function () { decide(acceptVal); });
  document.getElementById('btnReject').addEventListener('click', function () { decide(rejectVal); });
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
