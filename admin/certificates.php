<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/certificates.php';

require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
require_module_enabled('certificates_enabled', 'Moduł zaświadczeń');

$filter = $_GET['status'] ?? '';
$requests = get_all_certificate_requests($filter);

$counts = [];
foreach (['', 'oczekuje', 'wydane', 'odrzucone'] as $s) {
    $counts[$s] = count(get_all_certificate_requests($s));
}

$PAGE_TITLE = 'Wnioski o zaświadczenia';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-award text-primary"></i> Wnioski o zaświadczenia</h4>
</div>

<!-- Filtry -->
<div class="mb-3 d-flex gap-2 flex-wrap">
  <?php
  $tabs = [
      '' => ['label' => 'Wszystkie', 'class' => 'secondary'],
      'oczekuje'  => ['label' => 'Oczekujące', 'class' => 'warning'],
      'wydane'    => ['label' => 'Wydane',     'class' => 'success'],
      'odrzucone' => ['label' => 'Odrzucone',  'class' => 'danger'],
  ];
  foreach ($tabs as $key => $tab):
      $active = $filter === $key ? '' : 'outline-';
      $url = APP_URL . '/admin/certificates.php' . ($key ? '?status=' . $key : '');
  ?>
  <a href="<?= h($url) ?>"
     class="btn btn-sm btn-<?= $active ?><?= $tab['class'] ?>">
    <?= $tab['label'] ?>
    <span class="badge bg-<?= $tab['class'] ?> ms-1"><?= $counts[$key] ?></span>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($requests): ?>
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Umowa</th>
          <th>Wnioskodawca</th>
          <th>E-mail</th>
          <th>Cel</th>
          <th>Złożono</th>
          <th>Status</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($requests as $req): ?>
      <tr class="<?= $req['status'] === 'oczekuje' ? 'table-warning' : '' ?>">
        <td class="text-muted small"><?= $req['id'] ?></td>
        <td>
          <a href="<?= h(contract_url($req['contract_type'], $req['contract_id'])) ?>" class="text-decoration-none fw-semibold">
            <?= h(CONTRACT_TYPES[$req['contract_type']] ?? $req['contract_type']) ?>
          </a>
          <br><small class="text-muted"><?= h($req['contract_type']) ?>/<?= $req['contract_id'] ?></small>
        </td>
        <td><?= h($req['requester_name']) ?></td>
        <td class="small"><?= h($req['requester_email']) ?></td>
        <td class="small text-truncate" style="max-width:180px" title="<?= h($req['cel']) ?>">
          <?= h($req['cel']) ?>
        </td>
        <td class="small text-nowrap"><?= date_pl($req['created_at']) ?></td>
        <td><?= certificate_status_badge($req['status']) ?></td>
        <td class="text-end text-nowrap">
          <?php if ($req['status'] === 'oczekuje'): ?>
          <a href="<?= APP_URL ?>/certificates/issue.php?id=<?= $req['id'] ?>"
             class="btn btn-sm btn-success">
            <i class="bi bi-award"></i> Wydaj
          </a>
          <?php elseif ($req['status'] === 'wydane'): ?>
          <a href="<?= APP_URL ?>/certificates/issue.php?id=<?= $req['id'] ?>"
             class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-eye"></i> Podgląd
          </a>
          <a href="<?= APP_URL ?>/certificates/print.php?id=<?= $req['id'] ?>"
             target="_blank" class="btn btn-sm btn-outline-success" title="Podgląd PDF">
            <i class="bi bi-printer"></i>
          </a>
          <a href="<?= APP_URL ?>/certificates/download_docx.php?id=<?= $req['id'] ?>"
             class="btn btn-sm btn-outline-secondary" title="Pobierz DOCX">
            <i class="bi bi-file-earmark-word"></i>
          </a>
          <?php else: ?>
          <a href="<?= APP_URL ?>/certificates/issue.php?id=<?= $req['id'] ?>"
             class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-eye"></i> Szczegóły
          </a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="card-body text-center text-muted py-5">
    <i class="bi bi-award fs-1 d-block mb-2"></i>
    <?php if ($filter): ?>
    Brak wniosków o statusie „<?= h(CERTIFICATE_STATUSES[$filter]['label'] ?? $filter) ?>".
    <?php else: ?>
    Brak wniosków o zaświadczenia.
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
