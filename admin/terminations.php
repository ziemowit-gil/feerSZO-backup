<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/termination.php';

require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
require_module_enabled('terminations_enabled', 'Moduł rozwiązań umów');

// ── Obsługa decyzji (inline POST) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $req_id   = intval($_POST['req_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $note     = trim($_POST['decision_note'] ?? '');
    $user     = current_user();

    if (in_array($decision, ['zaakceptowany', 'odrzucony'], true) && $req_id) {
        if (decide_termination($req_id, $user['id'], $decision, $note)) {
            $msg = $decision === 'zaakceptowany'
                ? 'Wniosek zaakceptowany. Status umowy zmieniony na „Rozwiązana". Wnioskujący otrzymał powiadomienie.'
                : 'Wniosek odrzucony. Wnioskujący otrzymał powiadomienie.';
            flash_set('success', $msg);
        } else {
            flash_set('danger', 'Nie można rozpatrzyć tego wniosku (być może już rozpatrzony).');
        }
    }
    header('Location: ' . APP_URL . '/admin/terminations.php' . ($_GET['status'] ? '?status=' . urlencode($_GET['status'] ?? '') : ''));
    exit;
}

$filter   = $_GET['status'] ?? '';
$requests = get_all_termination_requests($filter);

$counts = [];
foreach (['', 'oczekuje', 'zaakceptowany', 'odrzucony'] as $s) {
    $counts[$s] = count(get_all_termination_requests($s));
}

$PAGE_TITLE = 'Wnioski o rozwiązanie umów';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-file-earmark-x text-danger"></i> Wnioski o rozwiązanie umów</h4>
</div>

<?= flash_html() ?>

<!-- Filtry -->
<div class="mb-3 d-flex gap-2 flex-wrap">
  <?php
  $tabs = [
      ''             => ['label' => 'Wszystkie',    'class' => 'secondary'],
      'oczekuje'     => ['label' => 'Oczekujące',   'class' => 'warning'],
      'zaakceptowany'=> ['label' => 'Zaakceptowane','class' => 'success'],
      'odrzucony'    => ['label' => 'Odrzucone',    'class' => 'danger'],
  ];
  foreach ($tabs as $key => $tab):
      $active = $filter === $key ? '' : 'outline-';
      $url = APP_URL . '/admin/terminations.php' . ($key ? '?status=' . $key : '');
  ?>
  <a href="<?= h($url) ?>" class="btn btn-sm btn-<?= $active ?><?= $tab['class'] ?>">
    <?= $tab['label'] ?>
    <span class="badge bg-<?= $tab['class'] ?> ms-1"><?= $counts[$key] ?></span>
  </a>
  <?php endforeach; ?>
</div>

<?php if (!$requests): ?>
<div class="card shadow-sm">
  <div class="card-body text-center text-muted py-5">
    <i class="bi bi-file-earmark-x fs-1 d-block mb-2 opacity-25"></i>
    Brak wniosków<?= $filter ? ' o statusie „' . h(TERMINATION_STATUSES[$filter]['label'] ?? $filter) . '"' : '' ?>.
  </div>
</div>
<?php else: ?>

<div class="d-flex flex-column gap-3">
<?php foreach ($requests as $req):
    try {
        $c_row = db_one("SELECT numer_umowy, status FROM " . table_for_type($req['contract_type']) . " WHERE id=?", [$req['contract_id']]);
        $c_nr     = $c_row['numer_umowy'] ?? "#{$req['contract_id']}";
        $c_status = $c_row['status'] ?? '';
    } catch (\Exception $e) { $c_nr = "#{$req['contract_id']}"; $c_status = ''; }
    $is_pending = $req['status'] === 'oczekuje';
?>
<div class="card shadow-sm <?= $is_pending ? 'border-warning' : '' ?>">
  <div class="card-body">
    <div class="row g-3 align-items-start">

      <!-- Info o wniosku -->
      <div class="col-md-5">
        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
          <a href="<?= h(contract_url($req['contract_type'], $req['contract_id'])) ?>"
             class="fw-semibold text-decoration-none">
            <?= h(CONTRACT_TYPES[$req['contract_type']] ?? $req['contract_type']) ?>
            · <?= h($c_nr) ?>
          </a>
          <?= termination_status_badge($req['status']) ?>
          <?php if ($c_status): ?><?= status_badge($c_status) ?><?php endif; ?>
        </div>
        <div class="small text-muted mb-1">
          <i class="bi bi-person"></i> <?= h($req['requester_name']) ?>
          <?php if ($req['requested_by_name'] && $req['requested_by_name'] !== $req['requester_name']): ?>
          <span class="text-muted">(konto: <?= h($req['requested_by_name']) ?>)</span>
          <?php endif; ?>
        </div>
        <div class="small text-muted mb-1">
          <i class="bi bi-calendar"></i> Złożono: <?= date_pl($req['created_at']) ?>
          <?php if ($req['proposed_date']): ?>
          · Propon. data: <strong><?= date_pl($req['proposed_date']) ?></strong>
          <?php endif; ?>
        </div>
        <?php if (!empty($req['variant']) || !empty($req['initiator'])): ?>
        <div class="small text-muted mb-1">
          <?php if (!empty($req['initiator'])): ?>
          <i class="bi bi-person-fill-gear"></i> <?= h(termination_initiator_label($req['initiator'])) ?>
          <?php endif; ?>
          <?php if (!empty($req['variant'])): ?>
          · Tryb: <strong><?= h(termination_variant_label($req['variant'])) ?></strong>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($req['effective_date'])): ?>
        <div class="small text-muted mb-1">
          <i class="bi bi-flag"></i> Efektywna data zakończenia: <strong><?= date_pl($req['effective_date']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if (!$is_pending && $req['decided_at']): ?>
        <div class="small text-muted">
          Rozpatrzono: <?= date_pl($req['decided_at']) ?>
          <?php if ($req['decided_by_name']): ?>
          przez <?= h($req['decided_by_name']) ?>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Powód -->
      <div class="col-md-4">
        <div class="small fw-semibold text-muted mb-1">Powód</div>
        <div class="small"><?= nl2br(h($req['powod'])) ?></div>
        <?php if ($req['decision_note']): ?>
        <div class="small text-muted mt-2 fst-italic">
          <i class="bi bi-chat-left-text"></i> <?= h($req['decision_note']) ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Akcje -->
      <div class="col-md-3">
        <?php if ($is_pending): ?>
        <form method="post" class="mb-2" id="form-decide-<?= $req['id'] ?>">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
          <div class="mb-2">
            <input type="text" name="decision_note" class="form-control form-control-sm"
                   placeholder="Uwaga (opcjonalnie)">
          </div>
          <div class="d-flex gap-2">
            <button type="submit" name="decision" value="zaakceptowany"
                    class="btn btn-sm btn-success flex-fill"
                    onclick="return confirm('Zaakceptować wniosek?\n\nUmowa otrzyma status „Rozwiązana".')">
              <i class="bi bi-check-lg"></i> Zatwierdź
            </button>
            <button type="submit" name="decision" value="odrzucony"
                    class="btn btn-sm btn-outline-danger flex-fill"
                    onclick="return confirm('Odrzucić wniosek?')">
              <i class="bi bi-x-lg"></i> Odrzuć
            </button>
          </div>
        </form>
        <?php endif; ?>
        <a href="<?= h(contract_url($req['contract_type'], $req['contract_id'])) ?>"
           class="btn btn-sm btn-outline-secondary w-100">
          <i class="bi bi-eye"></i> Otwórz umowę
        </a>
      </div>

    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
