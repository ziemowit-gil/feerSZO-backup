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

// ── Akcje ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_write('pisma') && !can_write('admin')) { http_response_code(403); die(); }

    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'odpowiedz' && $id) {
        $odpowiedz = trim($_POST['odpowiedz'] ?? '');
        $status    = $_POST['status'] ?? 'rozpatrzony';
        if (!isset($status_meta[$status]) || $status === 'nowy') $status = 'rozpatrzony';
        db_update('user_applications', [
            'status'       => $status,
            'odpowiedz'    => $odpowiedz,
            'odpowiedz_at' => date('Y-m-d H:i:s'),
            'answered_by'  => current_user()['id'],
        ], $id);
        flash_set('success', 'Odpowiedź zapisana.');
        header('Location: ' . APP_URL . '/admin/applications.php'); exit;
    }

    if ($action === 'set_status' && $id) {
        $status = $_POST['status'] ?? '';
        if (isset($status_meta[$status])) {
            db_update('user_applications', ['status' => $status], $id);
        }
        header('Location: ' . APP_URL . '/admin/applications.php'); exit;
    }
}

// ── Dane typów (do filtra i wyświetlania) ─────────────────────────────────────
$all_types    = app_types_all();
$types_by_id  = array_column($all_types, null, 'id');
// Pola per typ (lazy cache)
$fields_cache = [];
$get_fields   = function (int $tid) use (&$fields_cache): array {
    if (!isset($fields_cache[$tid])) $fields_cache[$tid] = app_type_fields($tid);
    return $fields_cache[$tid];
};

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

$sql = "SELECT a.*, u.name AS user_name, u.email AS user_email,
               t.label AS type_label, t.icon AS type_icon, t.allow_attachment AS type_allow_att
        FROM user_applications a
        JOIN users u ON u.id = a.user_id
        LEFT JOIN application_types t ON t.id = a.type_id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY CASE a.status WHEN 'nowy' THEN 0 WHEN 'w_trakcie' THEN 1 ELSE 2 END, a.created_at DESC";

$applications = db_all($sql, $params);

// Statystyki per status
$counts = [];
foreach (array_keys($status_meta) as $s) {
    $counts[$s] = (int)db()->query("SELECT COUNT(*) FROM user_applications WHERE status='$s'")->fetchColumn();
}

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

<!-- ── Statystyki ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <?php foreach ($status_meta as $s => $meta): ?>
  <div class="col-6 col-sm-3">
    <a href="?status=<?= $s ?>" class="card shadow-sm text-decoration-none h-100
       <?= $filter_status === $s ? 'border-' . $meta['class'] . ' border-2' : '' ?>">
      <div class="card-body text-center py-3">
        <div class="fs-3 fw-bold text-<?= $meta['class'] ?>"><?= $counts[$s] ?></div>
        <div class="text-muted small"><?= $meta['label'] ?></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Filtry ─────────────────────────────────────────────────────────────── -->
<form method="get" class="card shadow-sm mb-4">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small mb-1 fw-semibold">Typ pisma</label>
        <select name="type_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">Wszystkie typy</option>
          <?php foreach ($all_types as $t): ?>
          <option value="<?= $t['id'] ?>"<?= $filter_type_id === $t['id'] ? ' selected' : '' ?>><?= h($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4">
        <label class="form-label small mb-1 fw-semibold">Status</label>
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">Wszystkie statusy</option>
          <?php foreach ($status_meta as $s => $meta): ?>
          <option value="<?= $s ?>"<?= $filter_status === $s ? ' selected' : '' ?>><?= $meta['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4 d-flex align-items-end">
        <?php if ($filter_status || $filter_type_id): ?>
        <a href="<?= APP_URL ?>/admin/applications.php" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-x-lg"></i> Wyczyść
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>

<!-- ── Lista ──────────────────────────────────────────────────────────────── -->
<?php if (!$applications): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-inbox fs-1 d-block mb-2 opacity-25"></i>
    <p class="mb-0">Brak pism<?= $filter_status || $filter_type_id ? ' dla wybranych filtrów' : '' ?>.</p>
  </div>
</div>
<?php else: ?>

<div class="d-flex flex-column gap-3">
<?php foreach ($applications as $app):
    $st      = $status_meta[$app['status']] ?? ['label' => $app['status'], 'class' => 'secondary'];
    $is_new  = $app['status'] === 'nowy';
    $fields  = $app['type_id'] ? $get_fields((int)$app['type_id']) : [];
    $fdata   = json_decode($app['fields_json'] ?? '{}', true) ?: [];
    // Buduj etykiety pól
    $field_labels = array_column($fields, 'label', 'name');
?>
<div class="card shadow-sm <?= $is_new ? 'border-primary border-2' : '' ?>">
  <div class="card-body">
    <div class="d-flex align-items-start gap-3">
      <i class="bi <?= h($app['type_icon'] ?? 'bi-file-text') ?> fs-4 text-primary mt-1 flex-shrink-0"></i>
      <div class="flex-grow-1">

        <!-- Nagłówek -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
          <span class="fw-bold fs-6"><?= h($app['tytul']) ?></span>
          <span class="badge bg-<?= $st['class'] ?>"><?= $st['label'] ?></span>
          <?php if ($is_new): ?><span class="badge bg-danger">Nowe</span><?php endif; ?>
          <span class="text-muted small ms-auto text-nowrap"><?= h(substr($app['created_at'], 0, 10)) ?></span>
        </div>

        <!-- Meta -->
        <div class="small text-muted mb-3 d-flex flex-wrap gap-2 align-items-center">
          <span><i class="bi bi-person me-1"></i><?= h($app['user_name']) ?> &lt;<?= h($app['user_email']) ?>&gt;</span>
          <span class="opacity-50">·</span>
          <span><?= h($app['type_label'] ?? '—') ?></span>
          <?php if ($app['contract_type'] && $app['contract_id']): ?>
          <span class="opacity-50">·</span>
          <a href="<?= APP_URL ?>/contracts/<?= h($app['contract_type']) ?>/view.php?id=<?= $app['contract_id'] ?>"
             class="text-decoration-none">
            <?= h($contract_labels[$app['contract_type']] ?? $app['contract_type']) ?> #<?= $app['contract_id'] ?>
          </a>
          <?php endif; ?>
        </div>

        <!-- Pola dynamiczne -->
        <?php if ($fdata): ?>
        <div class="row g-2 mb-3">
          <?php foreach ($fields as $f):
              $val = $fdata[$f['name']] ?? null;
              if ($val === null || $val === '') continue;
          ?>
          <div class="col-sm-6 col-lg-4">
            <div class="bg-light rounded p-2">
              <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em"><?= h($f['label']) ?></div>
              <div class="fw-semibold small"><?= h($val) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php // fallback dla starych rekordów bez type_id
          foreach ($fdata as $k => $v): if ($v && !isset($field_labels[$k])): ?>
          <div class="col-sm-6 col-lg-4">
            <div class="bg-light rounded p-2">
              <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em"><?= h(str_replace('_', ' ', $k)) ?></div>
              <div class="fw-semibold small"><?= h($v) ?></div>
            </div>
          </div>
          <?php endif; endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Załącznik -->
        <?php if ($app['plik']): ?>
        <div class="mb-3">
          <a href="<?= h(letter_file_url($app['plik'])) ?>" class="btn btn-sm btn-outline-secondary" download target="_blank">
            <i class="bi bi-paperclip me-1"></i>Pobierz załącznik
          </a>
        </div>
        <?php endif; ?>

        <!-- Odpowiedź admina -->
        <?php if ($app['odpowiedz']): ?>
        <div class="alert alert-success py-2 px-3 mb-3 small">
          <i class="bi bi-reply me-1"></i>
          <strong>Odpowiedź:</strong> <?= h($app['odpowiedz']) ?>
          <span class="text-muted ms-2"><?= h(substr($app['odpowiedz_at'] ?? '', 0, 10)) ?></span>
        </div>
        <?php endif; ?>

        <!-- Akcje -->
        <?php if (can_write('pisma') || can_write('admin')): ?>
        <div class="d-flex gap-2 flex-wrap align-items-center">
          <button class="btn btn-sm btn-primary" type="button"
                  data-bs-toggle="collapse" data-bs-target="#reply-<?= $app['id'] ?>">
            <i class="bi bi-reply me-1"></i><?= $app['odpowiedz'] ? 'Zmień odpowiedź' : 'Odpowiedz' ?>
          </button>
          <!-- Szybka zmiana statusu -->
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="id" value="<?= $app['id'] ?>">
            <select name="status" class="form-select form-select-sm d-inline-block w-auto"
                    onchange="this.form.submit()">
              <?php foreach ($status_meta as $sv => $sm): ?>
              <option value="<?= $sv ?>"<?= $app['status'] === $sv ? ' selected' : '' ?>><?= $sm['label'] ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>

        <div class="collapse mt-3" id="reply-<?= $app['id'] ?>">
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="odpowiedz">
            <input type="hidden" name="id" value="<?= $app['id'] ?>">
            <div class="mb-2">
              <textarea name="odpowiedz" class="form-control form-control-sm" rows="3"
                        placeholder="Treść odpowiedzi dla użytkownika..."><?= h($app['odpowiedz'] ?? '') ?></textarea>
            </div>
            <div class="d-flex gap-2 align-items-center">
              <select name="status" class="form-select form-select-sm w-auto">
                <?php foreach (['rozpatrzony', 'w_trakcie', 'odrzucony'] as $sv): ?>
                <option value="<?= $sv ?>"<?= $app['status'] === $sv ? ' selected' : '' ?>>
                  <?= $status_meta[$sv]['label'] ?>
                </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-sm btn-success">
                <i class="bi bi-check2 me-1"></i>Zapisz odpowiedź
              </button>
            </div>
          </form>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
