<?php
/**
 * karty30/ti/terms_admin.php — Zarządzanie regulaminami TI (admin).
 * Dostęp: zalogowany użytkownik SZO z uprawnieniami do modułu TI/admin.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_terms.php';

auth_require();
$user = auth_user();

ti_terms_migrate();

$csrf = csrf_token();
$flash_ok  = '';
$flash_err = '';

// Zapis regulaminu
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_token'] ?? '')) {
        http_response_code(403); exit('Nieprawidłowy token CSRF.');
    }
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
            header('Location: terms_admin.php?type=' . urlencode($type) . '&saved=1'); exit;
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

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="container-fluid mt-3">
  <div class="row">
    <div class="col-12">

      <div class="d-flex align-items-center gap-3 mb-3">
        <h1 class="h5 fw-bold mb-0"><i class="bi bi-file-earmark-text text-primary me-2" aria-hidden="true"></i>Regulaminy TI</h1>
        <a href="index.php" class="btn btn-sm btn-outline-secondary ms-auto">
          <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Panel TI
        </a>
      </div>

      <?php if (!empty($_GET['saved'])): ?>
      <div class="alert alert-success alert-dismissible py-2" role="alert">
        <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Regulamin zapisany.
        <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Zamknij"></button>
      </div>
      <?php endif; ?>
      <?php if ($flash_err !== ''): ?>
      <div class="alert alert-danger py-2" role="alert"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= h($flash_err) ?></div>
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
              <i class="bi bi-pencil-square text-primary" aria-hidden="true"></i>
              <span class="fw-semibold">Treść regulaminu</span>
              <span class="badge <?= $current['is_active'] ? 'text-bg-success' : 'text-bg-secondary' ?> ms-auto">
                <?= $current['is_active'] ? 'Aktywny' : 'Nieaktywny' ?> · v<?= (int)$current['version'] ?>
              </span>
            </div>
            <div class="card-body">
              <form method="post" id="term-form">
                <input type="hidden" name="_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="_op" value="save_term">
                <input type="hidden" name="type" value="<?= h($active_type) ?>">

                <div class="mb-3">
                  <label class="form-label fw-semibold" for="term-title">Tytuł wyświetlany kursantom</label>
                  <input type="text" class="form-control" id="term-title" name="title"
                         value="<?= h($current['title']) ?>" required maxlength="200">
                </div>

                <div class="mb-3">
                  <label class="form-label fw-semibold" for="term-body">Treść regulaminu</label>
                  <div id="term-editor-wrap">
                    <div id="term-editor" style="min-height:360px;border:1px solid var(--bs-border-color);border-radius:.375rem;background:var(--bs-body-bg);padding:.5rem"></div>
                  </div>
                  <textarea name="body_html" id="term-body" class="d-none"><?= h($current['body_html']) ?></textarea>
                  <div class="form-text">Edytor WYSIWYG. Zmiana treści automatycznie podniesie numer wersji — kursanci będą musieli ponownie zaakceptować regulamin.</div>
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

                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-primary">
                    <i class="bi bi-floppy me-1" aria-hidden="true"></i>Zapisz regulamin
                  </button>
                  <?php if (!empty($current['updated_at'])): ?>
                  <span class="text-body-secondary small align-self-center">
                    Ostatnia zmiana: <?= h(date('d.m.Y H:i', strtotime($current['updated_at']))) ?>
                    <?= !empty($current['editor_name']) ? ' · ' . h($current['editor_name']) : '' ?>
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
              <i class="bi bi-check2-all text-success" aria-hidden="true"></i>
              <span class="fw-semibold">Ostatnie akceptacje</span>
              <span class="badge text-bg-secondary ms-auto"><?= count($accepts) ?></span>
            </div>
            <?php if ($accepts): ?>
            <div class="table-responsive" style="max-height:480px;overflow-y:auto">
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
              <i class="bi bi-inbox me-1" aria-hidden="true"></i>Brak akceptacji dla tego regulaminu.
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div><!-- .row -->
      <?php endif; ?>

    </div>
  </div>
</div>

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

  // Załaduj istniejącą treść
  const existing = textarea.value.trim();
  if (existing) quill.root.innerHTML = existing;

  // Przed submitem — skopiuj HTML z Quill do textarea
  document.getElementById('term-form').addEventListener('submit', function() {
    textarea.value = quill.root.innerHTML;
  });
})();
</script>
<?php require_once dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
