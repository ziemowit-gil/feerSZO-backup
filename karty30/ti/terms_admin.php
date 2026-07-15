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

    // Pominięcie wymogu akceptacji / zdalna akceptacja w imieniu kursanta.
    if ($op === 'admin_action' && isset(TI_TERM_TYPES[$type])) {
        $mode      = ($_POST['mode'] ?? '') === 'skip' ? 'skip' : 'remote';
        $client_id = (int)($_POST['client_id'] ?? 0);
        $reason    = trim((string)($_POST['reason'] ?? ''));
        $term      = ti_term_get($type);
        if (!$client_id) {
            $flash_err = 'Wybierz kursanta.';
        } elseif ($reason === '') {
            $flash_err = 'Podaj uzasadnienie / podstawę czynności.';
        } elseif (!$term) {
            $flash_err = 'Nie znaleziono regulaminu.';
        } else {
            $aid = ti_term_admin_action($client_id, (int)$term['id'], (int)$user['id'], $mode, $reason);
            if ($aid) {
                flash_set('success', $mode === 'skip'
                    ? 'Wymóg akceptacji regulaminu pominięty.'
                    : 'Regulamin zaakceptowany zdalnie w imieniu kursanta.');
                header('Location: terms_admin.php?type=' . urlencode($type) . '&last_accept=' . $aid); exit;
            }
            $flash_err = 'Nie udało się zapisać czynności.';
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
            c.name AS client_name,
            COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS admin_name
     FROM k30_ti_terms_accepts a
     JOIN k30_ti_terms t ON t.id=a.term_id
     JOIN k30_clients c ON c.id=a.client_id
     LEFT JOIN users u ON u.id=a.admin_id
     WHERE t.type=?
     ORDER BY a.accepted_at DESC
     LIMIT 100",
    [$active_type]
);

// Kursanci TI (do wyboru w akcji administratora) + ostatnio zapisane oświadczenie (do linku pobrania)
$ti_clients = db_all(
    "SELECT DISTINCT a.client_id, cl.name AS client_name, a.is_minor
     FROM k30_ti_student_accounts a
     JOIN k30_clients cl ON cl.id=a.client_id
     ORDER BY cl.name"
);
$last_accept_id = (int)($_GET['last_accept'] ?? 0);

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
              <th>Kto</th>
              <th>Data</th>
              <th>v.</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($accepts as $a):
              $is_admin_row = str_starts_with((string)$a['accepted_by_role'], 'admin_');
              $role_badge   = $is_admin_row ? 'text-bg-warning' : ($a['accepted_by_role'] === 'rodzic' ? 'text-bg-info' : 'text-bg-secondary');
            ?>
            <tr>
              <td><?= h($a['client_name']) ?></td>
              <td>
                <span class="badge <?= $role_badge ?>" <?= $is_admin_row && $a['admin_note'] !== '' ? 'title="'.h($a['admin_note']).'"' : '' ?>>
                  <?= h(ti_terms_role_label($a['accepted_by_role'])) ?>
                </span>
                <?php if ($is_admin_row): ?><div class="text-body-secondary" style="font-size:.75rem"><?= h($a['admin_name'] ?? '') ?></div><?php endif; ?>
              </td>
              <td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime($a['accepted_at']))) ?></td>
              <td>v<?= (int)$a['version'] ?></td>
              <td class="text-end">
                <?php if ($is_admin_row): ?>
                <a href="terms_pdf.php?id=<?= (int)$a['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0" title="Pobierz oświadczenie (PDF)"><i class="bi bi-file-earmark-pdf"></i></a>
                <?php endif; ?>
              </td>
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

  <?php if ($can_write): ?>
  <!-- Pominięcie / zdalna akceptacja przez administratora -->
  <div class="col-12">
    <div class="card border-warning shadow-sm">
      <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center gap-2">
        <i class="bi bi-person-gear text-warning"></i>
        <span class="fw-semibold">Administrator: pomiń wymóg lub zaakceptuj zdalnie w imieniu kursanta</span>
      </div>
      <div class="card-body">
        <?php if ($last_accept_id): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 py-2">
          <i class="bi bi-check2-circle" aria-hidden="true"></i>
          <span>Czynność zapisana. <a href="terms_pdf.php?id=<?= $last_accept_id ?>" target="_blank" class="alert-link">Pobierz oświadczenie (PDF)</a>.</span>
        </div>
        <?php endif; ?>
        <p class="text-body-secondary small">Użyj, gdy kursant (lub jego opiekun) nie może samodzielnie zaakceptować regulaminu w panelu — np. potwierdził zgodę telefonicznie lub mailowo. Każda czynność jest logowana i generuje oświadczenie do wydruku.</p>
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="_op" value="admin_action">
          <input type="hidden" name="type" value="<?= h($active_type) ?>">
          <div class="col-md-4">
            <label class="form-label small" for="admin-action-client">Kursant</label>
            <select class="form-select form-select-sm" id="admin-action-client" name="client_id" required>
              <option value="">— wybierz —</option>
              <?php foreach ($ti_clients as $tc): ?>
              <option value="<?= (int)$tc['client_id'] ?>"><?= h($tc['client_name']) ?><?= $tc['is_minor'] ? ' (niepełnoletni)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label small" for="admin-action-reason">Uzasadnienie / podstawa czynności</label>
            <input type="text" class="form-control form-control-sm" id="admin-action-reason" name="reason" required maxlength="500"
                   placeholder="np. zgoda potwierdzona telefonicznie 12.07.2026 przez opiekuna">
          </div>
          <div class="col-md-3 d-flex gap-2">
            <button type="submit" name="mode" value="skip" class="btn btn-sm btn-outline-warning flex-fill" title="Kursant nie musi akceptować — dostęp odblokowany bez zaznaczenia zgody">
              <i class="bi bi-skip-forward me-1"></i>Pomiń wymóg
            </button>
            <button type="submit" name="mode" value="remote" class="btn btn-sm btn-warning flex-fill" title="Zapisz akceptację w imieniu kursanta">
              <i class="bi bi-check2-circle me-1"></i>Zaakceptuj zdalnie
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

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
