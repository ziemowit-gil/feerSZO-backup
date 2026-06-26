<?php
/**
 * karty30/ti/terms_admin.php — Zarządzanie regulaminami TI (admin).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_terms.php';

k30_require_access();
karty30_migrate();
ti_terms_migrate();

$user      = current_user();
$can_write = can_write('karty30') || is_admin();

$flash_err = '';

// Zapis regulaminu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op   = (string)($_POST['_op'] ?? '');
    $type = (string)($_POST['type'] ?? '');

    if ($op === 'save_term' && isset(TI_TERM_TYPES[$type])) {
        $title     = trim((string)($_POST['title'] ?? ''));
        $body_html = (string)($_POST['body_html'] ?? '');
        $is_active = !empty($_POST['is_active']) ? 1 : 0;
        if ($title === '') {
            $flash_err = 'Tytuł regulaminu nie może być pusty.';
        } else {
            ti_term_save($type, $title, $body_html, $is_active, (int)$user['id']);
            flash_set('success', 'Regulamin zapisany.');
            header('Location: terms_admin.php?type=' . urlencode($type)); exit;
        }
    }
}

$active_type = $_GET['type'] ?? 'szkolenia';
if (!isset(TI_TERM_TYPES[$active_type])) $active_type = 'szkolenia';
$terms = ti_terms_all();
$current = null;
foreach ($terms as $t) { if ($t['type'] === $active_type) { $current = $t; break; } }

// Lista ostatnich akceptacji
$accepts = db_all(
    "SELECT a.*, t.title AS term_title, t.type AS term_type,
            c.name AS client_name
     FROM k30_ti_terms_accepts a
     JOIN k30_ti_terms t ON t.id=a.term_id
     JOIN k30_clients c ON c.id=a.client_id
     WHERE t.type=?
     ORDER BY a.accepted_at DESC
     LIMIT 100",
    [$active_type]
);

$PAGE_TITLE = 'Regulaminy TI';
include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/ti/index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Regulaminy</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-text text-primary me-2"></i>Regulaminy TI</h4>
</div>

<?= flash_html() ?>

<?php if ($flash_err !== ''): ?>
<div class="alert alert-danger py-2" role="alert"><i class="bi bi-exclamation-triangle me-1"></i><?= h($flash_err) ?></div>
<?php endif; ?>

<!-- Zakładki typów regulaminów -->
<ul class="nav nav-tabs mb-3" role="tablist">
  <?php foreach (TI_TERM_TYPES as $ttype => $tlabel): ?>
  <li class="nav-item">
    <a class="nav-link <?= $active_type===$ttype?'active':'' ?>"
       href="?type=<?= urlencode($ttype) ?>"><?= h($tlabel) ?></a>
  </li>
  <?php endforeach; ?>
</ul>

<?php if ($current): ?>
<div class="row g-4">

  <!-- Edytor treści -->
  <div class="col-xl-7">
    <div class="card shadow-sm">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-pencil-square text-primary"></i>
        <span class="fw-semibold">Treść regulaminu</span>
        <span class="badge <?= $current['is_active'] ? 'text-bg-success' : 'text-bg-secondary' ?> ms-auto">
          <?= $current['is_active'] ? 'Aktywny' : 'Nieaktywny' ?> · v<?= (int)$current['version'] ?>
        </span>
      </div>
      <div class="card-body">
        <?php if ($can_write): ?>
        <form method="post" id="term-form">
          <?= csrf_field() ?>
          <input type="hidden" name="_op" value="save_term">
          <input type="hidden" name="type" value="<?= h($active_type) ?>">

          <div class="mb-3">
            <label class="form-label fw-semibold" for="term-title">Tytuł wyświetlany kursantom</label>
            <input type="text" class="form-control" id="term-title" name="title"
                   value="<?= h($current['title']) ?>" required maxlength="200">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="term-body">Treść regulaminu</label>
            <div id="term-editor" style="min-height:340px"></div>
            <textarea name="body_html" id="term-body" class="d-none"><?= h($current['body_html']) ?></textarea>
            <div class="form-text">Zmiana treści automatycznie podniesie numer wersji — kursanci będą musieli ponownie zaakceptować.</div>
          </div>

          <div class="mb-3">
            <div class="form-check form-switch">
              <input type="checkbox" class="form-check-input" id="term-active" name="is_active" value="1"
                     <?= $current['is_active'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="term-active">
                Regulamin aktywny (wymagany do zaakceptowania przez kursantów)
              </label>
            </div>
          </div>

          <div class="d-flex align-items-center gap-3">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-floppy me-1"></i>Zapisz
            </button>
            <?php if (!empty($current['updated_at'])): ?>
            <span class="text-body-secondary small">
              Zmienił: <?= !empty($current['editor_name']) ? h($current['editor_name']) : '—' ?>
              · <?= h(date('d.m.Y H:i', strtotime($current['updated_at']))) ?>
            </span>
            <?php endif; ?>
          </div>
        </form>
        <?php else: ?>
        <div class="alert alert-secondary py-2 small">Brak uprawnień do edycji.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Lista akceptacji -->
  <div class="col-xl-5">
    <div class="card shadow-sm">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-check2-all text-success"></i>
        <span class="fw-semibold">Ostatnie akceptacje</span>
        <span class="badge text-bg-secondary ms-auto"><?= count($accepts) ?></span>
      </div>
      <?php if ($accepts): ?>
      <div class="table-responsive" style="max-height:500px;overflow-y:auto">
        <table class="table table-sm table-hover mb-0 small">
          <thead class="table-light sticky-top">
            <tr>
              <th>Kursant</th>
              <th>Data</th>
              <th>IP</th>
              <th>v.</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($accepts as $a): ?>
            <tr>
              <td><?= h($a['client_name']) ?></td>
              <td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime($a['accepted_at']))) ?></td>
              <td class="font-monospace text-body-secondary"><?= h($a['ip']) ?></td>
              <td>v<?= (int)$a['version'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="card-body text-body-secondary small">
        <i class="bi bi-inbox me-1"></i>Brak akceptacji dla tego regulaminu.
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>
<?php endif; ?>

<!-- Quill WYSIWYG -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.min.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
(function(){
  const textarea = document.getElementById('term-body');
  const editorEl = document.getElementById('term-editor');
  if (!textarea || !editorEl) return;

  const quill = new Quill(editorEl, {
    theme: 'snow',
    modules: {
      toolbar: [
        [{ header: [1,2,3,false] }],
        ['bold','italic','underline'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['link'],
        ['clean']
      ]
    }
  });

  const existing = textarea.value.trim();
  if (existing) quill.root.innerHTML = existing;

  document.getElementById('term-form')?.addEventListener('submit', function() {
    textarea.value = quill.root.innerHTML;
  });
})();
</script>
<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
