<?php
/**
 * admin/panel_terms.php — Zarządzanie regulaminem panelu SZO.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/panel_terms.php';

require_login();
if (!is_admin()) { http_response_code(403); exit('Brak dostępu.'); }

panel_terms_migrate();
$user = current_user();

$flash_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');

    if ($op === 'save') {
        $title     = trim((string)($_POST['title'] ?? ''));
        $body_html = (string)($_POST['body_html'] ?? '');
        $is_active = !empty($_POST['is_active']) ? 1 : 0;
        if ($title === '') {
            $flash_err = 'Tytuł regulaminu nie może być pusty.';
        } else {
            panel_term_save($title, $body_html, $is_active, (int)$user['id']);
            flash_set('success', 'Regulamin zapisany.');
            header('Location: panel_terms.php'); exit;
        }
    }
}

$term    = panel_term_get();
$accepts = db_all(
    "SELECT a.*, u.name AS user_name, u.email AS user_email, 'staff' AS who_type
     FROM panel_terms_accepts a
     JOIN users u ON u.id=a.user_id
     UNION ALL
     SELECT a.id, a.term_id, NULL, a.version, a.ip, a.ua, a.accepted_at,
            c.name AS user_name, c.email AS user_email, 'kursant' AS who_type
     FROM panel_terms_kursant_accepts a
     JOIN k30_clients c ON c.id=a.client_id
     ORDER BY accepted_at DESC
     LIMIT 200"
);

$PAGE_TITLE = 'Regulamin panelu';
include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/index.php">Admin</a></li>
  <li class="breadcrumb-item active">Regulamin panelu</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-text text-primary me-2"></i>Regulamin panelu</h4>
  <?php if ($term && $term['is_active']): ?>
  <span class="badge text-bg-success">Aktywny · v<?= (int)$term['version'] ?></span>
  <?php else: ?>
  <span class="badge text-bg-secondary">Nieaktywny</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if ($flash_err !== ''): ?>
<div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h($flash_err) ?></div>
<?php endif; ?>

<div class="row g-4">

  <!-- Edytor treści -->
  <div class="col-xl-7">
    <div class="card shadow-sm">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-pencil-square text-primary"></i>
        <span class="fw-semibold">Treść regulaminu</span>
      </div>
      <div class="card-body">
        <form method="post" id="pt-form">
          <?= csrf_field() ?>
          <input type="hidden" name="_op" value="save">

          <div class="mb-3">
            <label class="form-label fw-semibold" for="pt-title">Tytuł</label>
            <input type="text" class="form-control" id="pt-title" name="title"
                   value="<?= h($term['title'] ?? 'Regulamin panelu') ?>" required maxlength="200">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="pt-body">Treść regulaminu</label>
            <div id="pt-editor" style="min-height:360px"></div>
            <textarea name="body_html" id="pt-body" class="d-none"><?= h($term['body_html'] ?? '') ?></textarea>
            <div class="form-text">Zmiana treści automatycznie podniesie numer wersji — wszyscy użytkownicy będą musieli ponownie zaakceptować.</div>
          </div>

          <div class="mb-4">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="pt-active" name="is_active" value="1"
                     <?= !empty($term['is_active']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="pt-active">
                Regulamin aktywny (wymagany przy wejściu do panelu)
              </label>
            </div>
          </div>

          <div class="d-flex align-items-center gap-3">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-floppy me-1"></i>Zapisz
            </button>
            <?php if ($term && !empty($term['updated_at'])): ?>
            <span class="text-body-secondary small">
              <?php
              $editor_name = '';
              if (!empty($term['updated_by'])) {
                  $ed = db_one("SELECT COALESCE(NULLIF(TRIM(first_name||' '||last_name),''), name) AS n FROM users WHERE id=?", [$term['updated_by']]);
                  $editor_name = $ed['n'] ?? '';
              }
              ?>
              <?= $editor_name ? 'Zmienił: ' . h($editor_name) . ' · ' : '' ?><?= h(date('d.m.Y H:i', strtotime($term['updated_at']))) ?>
            </span>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Lista akceptacji -->
  <div class="col-xl-5">
    <div class="card shadow-sm">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-check2-all text-success"></i>
        <span class="fw-semibold">Akceptacje</span>
        <span class="badge text-bg-secondary ms-auto"><?= count($accepts) ?></span>
      </div>
      <?php if ($accepts): ?>
      <div class="table-responsive" style="max-height:520px;overflow-y:auto">
        <table class="table table-sm table-hover mb-0 small">
          <thead class="table-light sticky-top">
            <tr>
              <th>Użytkownik</th>
              <th>Data</th>
              <th>IP</th>
              <th>v.</th>
            </tr>

          </thead>
          <tbody>
            <?php foreach ($accepts as $a): ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-1">
                  <?= ($a['who_type'] ?? '') === 'kursant'
                      ? '<span class="badge text-bg-info">kursant</span>'
                      : '<span class="badge text-bg-secondary">staff</span>' ?>
                  <?= h($a['user_name']) ?>
                </div>
                <?php if (!empty($a['user_email'])): ?>
                <div class="text-body-secondary small"><?= h($a['user_email']) ?></div>
                <?php endif; ?>
              </td>
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
        <i class="bi bi-inbox me-1"></i>Brak akceptacji.
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.min.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
(function(){
  const textarea = document.getElementById('pt-body');
  const editorEl = document.getElementById('pt-editor');
  if (!textarea || !editorEl) return;
  const quill = new Quill(editorEl, {
    theme: 'snow',
    modules: { toolbar: [[{header:[1,2,3,false]}],['bold','italic','underline'],[{list:'ordered'},{list:'bullet'}],['link'],['clean']] }
  });
  const existing = textarea.value.trim();
  if (existing) quill.root.innerHTML = existing;
  document.getElementById('pt-form')?.addEventListener('submit', function() {
    textarea.value = quill.root.innerHTML;
  });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
