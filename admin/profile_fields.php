<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/directory.php';

require_role('admin');
directory_migrate();

$PAGE_TITLE = 'Pola profilu użytkownika';
$flash = '';
$error = '';

// --- Helpers ---
function pf_to_snake(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/[^a-z0-9\s_]/u', '', $s);
    $s = preg_replace('/[\s]+/', '_', trim($s));
    return substr($s, 0, 60);
}

// --- POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ADD
    if ($action === 'add') {
        $label      = trim($_POST['label'] ?? '');
        $field_key  = trim($_POST['field_key'] ?? '') ?: pf_to_snake($label);
        $field_type = in_array($_POST['field_type'] ?? '', ['text','textarea','url','select']) ? $_POST['field_type'] : 'text';
        $options    = trim($_POST['options'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);

        if (!$label) {
            $error = 'Etykieta jest wymagana.';
        } elseif (!$field_key) {
            $error = 'Klucz pola jest wymagany.';
        } else {
            // Options to JSON
            $options_json = '';
            if ($field_type === 'select' && $options) {
                $opts = array_values(array_filter(array_map('trim', explode("\n", $options))));
                $options_json = json_encode($opts, JSON_UNESCAPED_UNICODE);
            }
            try {
                db()->prepare("INSERT INTO profile_field_defs (label, field_key, field_type, options, sort_order) VALUES (?,?,?,?,?)")
                     ->execute([$label, $field_key, $field_type, $options_json, $sort_order]);
                $flash = 'Pole zostało dodane.';
            } catch (Exception $e) {
                $error = 'Błąd: ' . $e->getMessage();
            }
        }
    }

    // TOGGLE
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare("UPDATE profile_field_defs SET is_active = 1 - is_active WHERE id = ?")
                 ->execute([$id]);
            $flash = 'Stan pola zmieniony.';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    // DELETE
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $usage = db_one("SELECT COUNT(*) AS cnt FROM profile_field_values WHERE field_id = ?", [$id]);
            if ((int)($usage['cnt'] ?? 0) > 0) {
                $error = 'Nie można usunąć pola, które posiada wartości. Najpierw wyłącz pole.';
            } else {
                db()->prepare("DELETE FROM profile_field_defs WHERE id = ?")->execute([$id]);
                $flash = 'Pole zostało usunięte.';
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    // MOVE UP
    if ($action === 'move_up') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $cur = db_one("SELECT id, sort_order FROM profile_field_defs WHERE id = ?", [$id]);
            $prev = db_one("SELECT id, sort_order FROM profile_field_defs WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1", [$cur['sort_order']]);
            if ($cur && $prev) {
                db()->prepare("UPDATE profile_field_defs SET sort_order=? WHERE id=?")->execute([$prev['sort_order'], $cur['id']]);
                db()->prepare("UPDATE profile_field_defs SET sort_order=? WHERE id=?")->execute([$cur['sort_order'],  $prev['id']]);
            }
            $flash = 'Kolejność zmieniona.';
        } catch (Exception $e) { $error = $e->getMessage(); }
    }

    // MOVE DOWN
    if ($action === 'move_down') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $cur  = db_one("SELECT id, sort_order FROM profile_field_defs WHERE id = ?", [$id]);
            $next = db_one("SELECT id, sort_order FROM profile_field_defs WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1", [$cur['sort_order']]);
            if ($cur && $next) {
                db()->prepare("UPDATE profile_field_defs SET sort_order=? WHERE id=?")->execute([$next['sort_order'], $cur['id']]);
                db()->prepare("UPDATE profile_field_defs SET sort_order=? WHERE id=?")->execute([$cur['sort_order'],  $next['id']]);
            }
            $flash = 'Kolejność zmieniona.';
        } catch (Exception $e) { $error = $e->getMessage(); }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
        header('Location: ' . APP_URL . '/admin/profile_fields.php' . ($flash ? '?msg=' . urlencode($flash) : ''));
        exit;
    }
}

if (empty($flash) && !empty($_GET['msg'])) {
    $flash = $_GET['msg'];
}

// Load fields
try {
    $fields = db_all("SELECT * FROM profile_field_defs ORDER BY sort_order ASC, id ASC");
} catch (Exception $e) {
    $fields = [];
    $error = $e->getMessage();
}

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="container-fluid py-4" style="max-width:960px">
  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <a href="<?= APP_URL ?>/directory/" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Katalog
    </a>
    <h1 class="h4 mb-0"><i class="bi bi-card-list me-2 text-primary"></i><?= h($PAGE_TITLE) ?></h1>
  </div>

  <?php if ($flash): ?>
  <div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i><?= h($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?>
  </div>
  <?php endif; ?>

  <!-- Fields list -->
  <div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white fw-bold border-bottom">
      <i class="bi bi-list-ul me-2"></i>Zdefiniowane pola
    </div>
    <?php if (empty($fields)): ?>
    <div class="card-body text-muted">Brak zdefiniowanych pól. Dodaj pierwsze poniżej.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Etykieta</th>
            <th>Klucz</th>
            <th>Typ</th>
            <th class="text-center">Aktywne</th>
            <th class="text-center">Kolejność</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fields as $f): ?>
          <tr class="<?= $f['is_active'] ? '' : 'table-secondary text-muted' ?>">
            <td class="fw-semibold"><?= h($f['label']) ?></td>
            <td><code><?= h($f['field_key']) ?></code></td>
            <td>
              <?php
              $type_labels = ['text'=>'Tekst','textarea'=>'Tekst długi','url'=>'URL','select'=>'Lista'];
              echo h($type_labels[$f['field_type']] ?? $f['field_type']);
              ?>
            </td>
            <td class="text-center">
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                <button type="submit" class="btn btn-sm <?= $f['is_active'] ? 'btn-success' : 'btn-outline-secondary' ?>" title="<?= $f['is_active'] ? 'Wyłącz' : 'Włącz' ?>">
                  <i class="bi <?= $f['is_active'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                </button>
              </form>
            </td>
            <td class="text-center">
              <div class="d-flex gap-1 justify-content-center">
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="move_up">
                  <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-secondary" title="W górę"><i class="bi bi-chevron-up"></i></button>
                </form>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="move_down">
                  <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-secondary" title="W dół"><i class="bi bi-chevron-down"></i></button>
                </form>
              </div>
            </td>
            <td class="text-end">
              <?php
              $usage = 0;
              try {
                $u = db_one("SELECT COUNT(*) AS cnt FROM profile_field_values WHERE field_id = ?", [(int)$f['id']]);
                $usage = (int)($u['cnt'] ?? 0);
              } catch (Exception $e) {}
              ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Na pewno usunąć to pole?')">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"
                        <?= $usage > 0 ? 'disabled title="Ma ' . $usage . ' wartości — najpierw wyłącz"' : '' ?>>
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Add form -->
  <div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white fw-bold border-bottom">
      <i class="bi bi-plus-circle me-2 text-primary"></i>Dodaj nowe pole
    </div>
    <div class="card-body">
      <form method="post" id="addFieldForm">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Etykieta <span class="text-danger">*</span></label>
            <input type="text" name="label" id="addLabel" class="form-control" required
                   placeholder="np. Numer pokoju">
          </div>
          <div class="col-md-3">
            <label class="form-label">Klucz pola</label>
            <input type="text" name="field_key" id="addKey" class="form-control"
                   placeholder="auto z etykiety" pattern="[a-z0-9_]+" title="Małe litery, cyfry, podkreślniki">
            <div class="form-text">Unikalne, snake_case</div>
          </div>
          <div class="col-md-2">
            <label class="form-label">Typ</label>
            <select name="field_type" id="addType" class="form-select">
              <option value="text">Tekst</option>
              <option value="textarea">Tekst długi</option>
              <option value="url">URL</option>
              <option value="select">Lista</option>
            </select>
          </div>
          <div class="col-md-1">
            <label class="form-label">Kolejność</label>
            <input type="number" name="sort_order" class="form-control" value="0" min="0">
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">
              <i class="bi bi-plus-lg me-1"></i>Dodaj
            </button>
          </div>
          <!-- Options (for select) -->
          <div class="col-12" id="optionsRow" style="display:none">
            <label class="form-label">Opcje listy <small class="text-muted">(jedna opcja na linię)</small></label>
            <textarea name="options" class="form-control font-monospace" rows="4"
                      placeholder="Opcja 1&#10;Opcja 2&#10;Opcja 3"></textarea>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Auto-generate field_key from label
document.getElementById('addLabel').addEventListener('input', function() {
  const keyEl = document.getElementById('addKey');
  if (keyEl.dataset.manual) return;
  let s = this.value.toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g,'')
    .replace(/[^a-z0-9\s_]/g,'')
    .replace(/\s+/g,'_').replace(/^_+|_+$/g,'')
    .substring(0, 60);
  keyEl.value = s;
});
document.getElementById('addKey').addEventListener('input', function() {
  this.dataset.manual = '1';
});

// Show options textarea only for select
document.getElementById('addType').addEventListener('change', function() {
  document.getElementById('optionsRow').style.display = this.value === 'select' ? '' : 'none';
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
