<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/letters.php';
require_once dirname(__DIR__) . '/includes/applications.php';

require_login();
if (!can_read('admin') && !can_read('pisma')) {
    http_response_code(403); die('Brak dostępu.');
}

$PAGE_TITLE = 'Pisma i wnioski użytkowników';

$status_meta = [
    'nowy'        => ['label' => 'Nowy',        'class' => 'primary'],
    'w_trakcie'   => ['label' => 'W trakcie',   'class' => 'warning'],
    'rozpatrzony' => ['label' => 'Rozpatrzony', 'class' => 'success'],
    'odrzucony'   => ['label' => 'Odrzucony',   'class' => 'danger'],
];

// Akcje (odpowiedź, status) obsługuje strona szczegółów admin/application.php

// ── Dane typów (do filtra i wyświetlania) ─────────────────────────────────────
$all_types    = app_types_all();
$types_by_id  = array_column($all_types, null, 'id');

// ── Filtry ────────────────────────────────────────────────────────────────────
$filter_status  = $_GET['status'] ?? '';
$filter_type_id = (int)($_GET['type_id'] ?? 0);

$where = []; $params = [];
if ($filter_status && isset($status_meta[$filter_status])) {
    $where[] = 'a.status = ?'; $params[] = $filter_status;
}
if ($filter_type_id) {
    $where[] = 'a.type_id = ?'; $params[] = $filter_type_id;
}

$sql = "SELECT a.*, u.name AS user_name, u.email AS user_email, ab.name AS answered_by_name,
               t.label AS type_label, t.icon AS type_icon, t.allow_attachment AS type_allow_att, t.name AS type_name
        FROM user_applications a
        JOIN users u ON u.id = a.user_id
        LEFT JOIN users ab ON ab.id = a.answered_by
        LEFT JOIN application_types t ON t.id = a.type_id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY CASE a.status WHEN 'nowy' THEN 0 WHEN 'w_trakcie' THEN 1 ELSE 2 END, a.created_at DESC";

$applications = db_all($sql, $params);

// Liczniki zakładek (z uwzględnieniem filtra typu)
$counts = array_fill_keys(array_keys($status_meta), 0);
foreach (db_all("SELECT status, COUNT(*) AS n FROM user_applications" . ($filter_type_id ? " WHERE type_id = ?" : '') . " GROUP BY status", $filter_type_id ? [$filter_type_id] : []) as $r) {
    if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['n'];
}
$qs = fn(string $st) => '?' . http_build_query(array_filter(['status' => $st, 'type_id' => $filter_type_id ?: null]));

$contract_labels = ['zlecenie' => 'Zlecenie', 'wolontariat' => 'Wolontariat', 'dzielo' => 'Dzieło', 'praca' => 'Praca'];

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;flex-shrink:0">
    <i class="bi bi-inbox text-primary fs-4"></i>
  </div>
  <div class="flex-grow-1">
    <h4 class="mb-0">Pisma i wnioski użytkowników</h4>
    <div class="text-muted small">Wnioski i pisma złożone przez użytkowników systemu</div>
  </div>
  <?php if (can_write('admin')): ?>
  <a href="<?= APP_URL ?>/admin/application_types.php" class="btn btn-outline-primary">
    <i class="bi bi-ui-checks-grid me-1"></i> Typy wniosków
  </a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<!-- ── Zakładki statusów ──────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3" role="list" aria-label="Status pism">
  <?php foreach (['' => ['label' => 'Wszystkie', 'class' => 'secondary']] + $status_meta as $s => $meta): ?>
  <li class="nav-item">
    <a class="nav-link<?= $filter_status === $s ? ' active' : '' ?>" href="<?= h($qs($s)) ?>"<?= $filter_status === $s ? ' aria-current="page"' : '' ?>>
      <?= h($meta['label']) ?>
      <span class="badge rounded-pill bg-<?= $s === '' ? 'secondary' : $meta['class'] ?> ms-1"><?= $s === '' ? array_sum($counts) : $counts[$s] ?></span>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<!-- ── Filtr typu ─────────────────────────────────────────────────────────── -->
<form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-3">
  <?php if ($filter_status): ?><input type="hidden" name="status" value="<?= h($filter_status) ?>"><?php endif; ?>
  <div>
    <label for="f_type" class="form-label small mb-1 fw-semibold">Typ pisma</label>
    <select id="f_type" name="type_id" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">Wszystkie typy</option>
      <?php foreach ($all_types as $t): ?>
      <option value="<?= $t['id'] ?>"<?= $filter_type_id === (int)$t['id'] ? ' selected' : '' ?>><?= h($t['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($filter_type_id): ?><a href="<?= h(APP_URL . '/admin/applications.php' . ($filter_status ? '?status=' . $filter_status : '')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg" aria-hidden="true"></i> Wyczyść typ</a><?php endif; ?>
</form>

<!-- ── Lista ──────────────────────────────────────────────────────────────── -->
<?php if (!$applications): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-inbox fs-1 d-block mb-2 opacity-25" aria-hidden="true"></i>
    <p class="mb-0">Brak pism<?= $filter_status || $filter_type_id ? ' dla wybranych filtrów' : '' ?>.</p>
  </div>
</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light"><tr>
        <th scope="col">Pismo</th><th scope="col">Składający</th><th scope="col">Typ</th><th scope="col">Złożone</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Otwórz</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($applications as $app):
          $st  = $status_meta[$app['status']] ?? ['label' => $app['status'], 'class' => 'secondary'];
          $url = APP_URL . '/admin/application.php?id=' . (int)$app['id'];
      ?>
      <tr<?= $app['status'] === 'nowy' ? ' class="fw-semibold"' : '' ?>>
        <td>
          <i class="bi <?= h($app['type_icon'] ?? 'bi-file-text') ?> text-primary me-1" aria-hidden="true"></i>
          <a href="<?= h($url) ?>" class="text-decoration-none"><?= h($app['tytul']) ?></a>
          <?php if (!empty($app['doc_number'])): ?><div class="small text-muted fw-normal font-monospace"><?= h($app['doc_number']) ?></div><?php endif; ?>
        </td>
        <td><?= h($app['user_name']) ?><div class="small text-muted fw-normal"><?= h($app['user_email']) ?></div></td>
        <td class="small fw-normal"><?= h($app['type_label'] ?? '—') ?>
          <?php if ($app['contract_type'] && $app['contract_id']): ?><div class="text-muted"><?= h($contract_labels[$app['contract_type']] ?? $app['contract_type']) ?> #<?= (int)$app['contract_id'] ?></div><?php endif; ?></td>
        <td class="small fw-normal text-nowrap"><?= h(date('d.m.Y H:i', strtotime($app['created_at']))) ?>
          <?php if ($app['odpowiedz_at']): ?><div class="text-muted">odp. <?= h(date('d.m.Y', strtotime($app['odpowiedz_at']))) ?><?= $app['answered_by_name'] ? ' · ' . h($app['answered_by_name']) : '' ?></div><?php endif; ?></td>
        <td><span class="badge bg-<?= $st['class'] ?>"><?= $st['label'] ?></span><?php if ($app['plik']): ?> <i class="bi bi-paperclip text-muted" title="z załącznikiem" aria-label="z załącznikiem"></i><?php endif; ?></td>
        <td class="text-end"><a href="<?= h($url) ?>" class="btn btn-sm btn-outline-primary" aria-label="Otwórz pismo <?= h($app['tytul']) ?>">Otwórz <i class="bi bi-chevron-right" aria-hidden="true"></i></a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
