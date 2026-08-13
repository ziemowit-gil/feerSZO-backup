<?php
/**
 * karty30/ti/subject_types.php — Zarządzanie rodzajami zajęć TI (admin).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

if (!is_admin()) { http_response_code(403); die('Tylko administrator.'); }

$PAGE_TITLE = 'Rodzaje zajęć TI';

// ── Zapis / edycja ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save') {
        $abbr  = strtoupper(trim($_POST['abbreviation'] ?? ''));
        $name  = trim($_POST['name'] ?? '');
        $order = (int)($_POST['sort_order'] ?? 0);
        $act   = isset($_POST['is_active']) ? 1 : 0;
        $id    = (int)($_POST['id'] ?? 0);

        if (!preg_match('/^[A-Z0-9\-]{1,10}$/', $abbr)) {
            flash_set('danger', 'Skrót może zawierać tylko litery A–Z, cyfry i myślnik, maks. 10 znaków.');
            header('Location: subject_types.php'); exit;
        }
        if ($name === '') {
            flash_set('danger', 'Nazwa jest wymagana.');
            header('Location: subject_types.php'); exit;
        }

        if ($id) {
            db()->prepare("UPDATE k30_ti_subject_types SET abbreviation=?, name=?, is_active=?, sort_order=? WHERE id=?")
                 ->execute([$abbr, $name, $act, $order, $id]);
            flash_set('success', 'Rodzaj zajęć "' . $abbr . '" zaktualizowany.');
        } else {
            db()->prepare("INSERT INTO k30_ti_subject_types (abbreviation, name, is_active, sort_order) VALUES (?,?,?,?)")
                 ->execute([$abbr, $name, $act, $order]);
            flash_set('success', 'Dodano rodzaj zajęć "' . $abbr . '".');
        }
        header('Location: subject_types.php'); exit;
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $used = db_one("SELECT COUNT(*) AS cnt FROM k30_ti_courses WHERE subject_type_id=?", [$id])['cnt'] ?? 0;
        if ($used > 0) {
            flash_set('danger', "Nie można usunąć — rodzaj jest przypisany do {$used} kursu(-ów). Możesz go dezaktywować.");
        } else {
            db()->prepare("DELETE FROM k30_ti_subject_types WHERE id=?")->execute([$id]);
            flash_set('success', 'Rodzaj zajęć usunięty.');
        }
        header('Location: subject_types.php'); exit;
    }
}

$types   = k30_ti_subject_types(false);
$edit_id = (int)($_GET['edit'] ?? 0);
$edit    = $edit_id ? (db_one("SELECT * FROM k30_ti_subject_types WHERE id=?", [$edit_id]) ?: null) : null;

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Rodzaje zajęć</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-tags text-primary me-2"></i>Rodzaje zajęć TI</h4>
  <?php if (!$edit): ?>
  <a href="?edit=0" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-plus-lg me-1"></i>Nowy rodzaj</a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if (isset($_GET['edit'])): ?>
<div class="card border-0 shadow-sm mb-4" style="max-width:500px">
  <div class="card-header fw-semibold"><?= $edit ? 'Edytuj: '.h($edit['abbreviation']).' — '.h($edit['name']) : 'Nowy rodzaj zajęć' ?></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="save">
      <input type="hidden" name="id"    value="<?= (int)($edit['id'] ?? 0) ?>">

      <div class="row g-3 mb-3">
        <div class="col-4">
          <label class="form-label fw-semibold">Skrót <span class="text-danger">*</span>
            <span class="text-muted fw-normal small">(A–Z, max 10)</span>
          </label>
          <input type="text" class="form-control font-monospace text-uppercase" name="abbreviation"
                 maxlength="10" required pattern="[A-Za-z0-9\-]{1,10}"
                 value="<?= h($edit['abbreviation'] ?? '') ?>"
                 placeholder="np. ANG"
                 oninput="this.value=this.value.toUpperCase()"
                 aria-describedby="abbr_help">
          <div id="abbr_help" class="form-text">Używany w nazwie grupy</div>
        </div>
        <div class="col-8">
          <label class="form-label fw-semibold">Pełna nazwa <span class="text-danger">*</span></label>
          <input type="text" class="form-control" name="name" required
                 value="<?= h($edit['name'] ?? '') ?>" placeholder="np. Angielski">
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-auto">
          <label class="form-label">Kolejność</label>
          <input type="number" class="form-control" name="sort_order" min="0" style="width:90px"
                 value="<?= (int)($edit['sort_order'] ?? 0) ?>">
        </div>
        <div class="col-auto d-flex align-items-end pb-1">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" id="st_active"
                   <?= (!isset($edit) || $edit['is_active']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="st_active">Aktywny</label>
          </div>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Zapisz</button>
        <a href="subject_types.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($types): ?>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th scope="col" style="width:90px">Skrót</th>
          <th scope="col">Nazwa</th>
          <th scope="col" class="text-center" style="width:80px">Kolejność</th>
          <th scope="col" class="text-center" style="width:90px">Status</th>
          <th scope="col" class="text-end" style="width:130px">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($types as $t):
          $used = (int)(db_one("SELECT COUNT(*) AS cnt FROM k30_ti_courses WHERE subject_type_id=?", [$t['id']])['cnt'] ?? 0);
        ?>
        <tr class="<?= $t['is_active'] ? '' : 'opacity-60' ?>">
          <td>
            <span class="badge text-bg-primary font-monospace fs-6"><?= h($t['abbreviation']) ?></span>
          </td>
          <td class="fw-semibold"><?= h($t['name']) ?></td>
          <td class="text-center text-muted small"><?= (int)$t['sort_order'] ?></td>
          <td class="text-center">
            <?php if ($t['is_active']): ?>
              <span class="badge bg-success">Aktywny</span>
            <?php else: ?>
              <span class="badge bg-secondary">Nieaktywny</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <a href="?edit=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Edytuj">
              <i class="bi bi-pencil"></i>
            </a>
            <?php if ($used === 0): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć „<?= h(addslashes($t['abbreviation'])) ?>"?')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="delete">
              <input type="hidden" name="id"    value="<?= (int)$t['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2 ms-1" title="Usuń">
                <i class="bi bi-trash"></i>
              </button>
            </form>
            <?php else: ?>
            <span class="text-muted small ms-2" title="Używany w <?= $used ?> kursie(-ach)">
              <i class="bi bi-link-45deg"></i><?= $used ?>
            </span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="alert alert-info">
  Brak zdefiniowanych rodzajów zajęć.
  <a href="?edit=0">Dodaj pierwszy</a> (np. ANG — Angielski, TI — Tyfloinformatyka).
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
