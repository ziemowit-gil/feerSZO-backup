<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/applications.php';

require_login();
if (!can_read('admin')) { http_response_code(403); die('Brak dostępu.'); }

$PAGE_TITLE = 'Typy wniosków i pism';

const FIELD_TYPES = [
    'text'     => ['label' => 'Tekst (jedna linia)',   'icon' => 'bi-input-cursor-text'],
    'textarea' => ['label' => 'Tekst (wiele linii)',   'icon' => 'bi-textarea'],
    'select'   => ['label' => 'Lista wyboru',          'icon' => 'bi-menu-button-wide'],
    'date'     => ['label' => 'Data',                  'icon' => 'bi-calendar3'],
    'number'   => ['label' => 'Liczba',                'icon' => 'bi-123'],
    'checkbox' => ['label' => 'Pole wyboru (tak/nie)', 'icon' => 'bi-check2-square'],
];

// ── Obsługa akcji POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_write('admin')) { http_response_code(403); die(); }

    $action = $_POST['action'] ?? '';

    // ── Typy wniosków ─────────────────────────────────────────────────────────

    if ($action === 'save_type') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim(preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['name'] ?? '')));
        $label       = trim($_POST['label'] ?? '');
        $icon        = trim($_POST['icon'] ?? 'bi-file-text');
        $description = trim($_POST['description'] ?? '');
        $requires    = isset($_POST['requires_contract']) ? 1 : 0;
        $attachment  = isset($_POST['allow_attachment'])  ? 1 : 0;
        $active      = isset($_POST['is_active'])         ? 1 : 0;
        $sort        = (int)($_POST['sort_order'] ?? 0);

        if ($name && $label) {
            if ($id) {
                db_update('application_types', compact('label', 'icon', 'description', 'requires_contract', 'allow_attachment', 'is_active', 'sort_order') + [
                    'requires_contract' => $requires,
                    'allow_attachment'  => $attachment,
                    'is_active'         => $active,
                    'sort_order'        => $sort,
                ], $id);
            } else {
                db_insert('application_types', [
                    'name'              => $name,
                    'label'             => $label,
                    'icon'              => $icon,
                    'description'       => $description,
                    'requires_contract' => $requires,
                    'allow_attachment'  => $attachment,
                    'is_active'         => $active,
                    'sort_order'        => $sort,
                ]);
            }
            flash_set('success', 'Typ wniosku zapisany.');
        } else {
            flash_set('error', 'Nazwa i etykieta są wymagane.');
        }
        header('Location: ' . APP_URL . '/admin/application_types.php'); exit;
    }

    if ($action === 'delete_type') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            db()->prepare("DELETE FROM application_types WHERE id=?")->execute([$id]);
            flash_set('success', 'Typ usunięty.');
        }
        header('Location: ' . APP_URL . '/admin/application_types.php'); exit;
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $cur = (int)(db_one("SELECT is_active FROM application_types WHERE id=?", [$id])['is_active'] ?? 0);
            db_update('application_types', ['is_active' => $cur ? 0 : 1], $id);
        }
        header('Location: ' . APP_URL . '/admin/application_types.php#type-' . $id); exit;
    }

    // ── Pola formularza ───────────────────────────────────────────────────────

    if ($action === 'save_field') {
        $fid      = (int)($_POST['field_id'] ?? 0);
        $type_id  = (int)($_POST['type_id'] ?? 0);
        $name     = trim(preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['fname'] ?? '')));
        $label    = trim($_POST['flabel'] ?? '');
        $ftype    = $_POST['ftype'] ?? 'text';
        $ph       = trim($_POST['placeholder'] ?? '');
        $req      = isset($_POST['frequired']) ? 1 : 0;
        $sort     = (int)($_POST['fsort'] ?? 0);

        // Opcje dla select — textarea z każdą opcją w nowej linii
        $opts_raw = trim($_POST['options_text'] ?? '');
        $opts     = array_values(array_filter(array_map('trim', explode("\n", $opts_raw))));
        $opts_json = json_encode($opts, JSON_UNESCAPED_UNICODE);

        if (!isset(FIELD_TYPES[$ftype])) $ftype = 'text';

        if ($type_id && $name && $label) {
            if ($fid) {
                db_update('application_type_fields', [
                    'label'        => $label,
                    'field_type'   => $ftype,
                    'options_json' => $opts_json,
                    'placeholder'  => $ph,
                    'required'     => $req,
                    'sort_order'   => $sort,
                ], $fid);
            } else {
                db_insert('application_type_fields', [
                    'type_id'      => $type_id,
                    'name'         => $name,
                    'label'        => $label,
                    'field_type'   => $ftype,
                    'options_json' => $opts_json,
                    'placeholder'  => $ph,
                    'required'     => $req,
                    'sort_order'   => $sort,
                ]);
            }
            flash_set('success', 'Pole zapisane.');
        } else {
            flash_set('error', 'Uzupełnij wymagane dane pola.');
        }
        header('Location: ' . APP_URL . '/admin/application_types.php#type-' . $type_id); exit;
    }

    if ($action === 'delete_field') {
        $fid     = (int)($_POST['field_id'] ?? 0);
        $type_id = (int)($_POST['type_id'] ?? 0);
        if ($fid) {
            db()->prepare("DELETE FROM application_type_fields WHERE id=?")->execute([$fid]);
            flash_set('success', 'Pole usunięte.');
        }
        header('Location: ' . APP_URL . '/admin/application_types.php#type-' . $type_id); exit;
    }

    if ($action === 'move_field') {
        $fid      = (int)($_POST['field_id'] ?? 0);
        $type_id  = (int)($_POST['type_id'] ?? 0);
        $dir      = $_POST['dir'] ?? '';
        if ($fid && in_array($dir, ['up', 'down'], true)) {
            $fields = db_all("SELECT id, sort_order FROM application_type_fields WHERE type_id=? ORDER BY sort_order, id", [$type_id]);
            $idx    = array_search($fid, array_column($fields, 'id'));
            $swap   = $dir === 'up' ? $idx - 1 : $idx + 1;
            if (isset($fields[$swap])) {
                $s1 = $fields[$idx]['sort_order'];
                $s2 = $fields[$swap]['sort_order'];
                if ($s1 === $s2) { $s1 = $idx * 10; $s2 = $swap * 10; }
                db_update('application_type_fields', ['sort_order' => $s2], $fields[$idx]['id']);
                db_update('application_type_fields', ['sort_order' => $s1], $fields[$swap]['id']);
            }
        }
        header('Location: ' . APP_URL . '/admin/application_types.php#type-' . $type_id); exit;
    }
}

// ── Dane ──────────────────────────────────────────────────────────────────────
$types = app_types_with_fields();
$edit_type_id = (int)($_GET['edit'] ?? 0);
$edit_type    = $edit_type_id ? app_type_get($edit_type_id) : null;

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;flex-shrink:0">
    <i class="bi bi-ui-checks-grid text-primary fs-4"></i>
  </div>
  <div class="flex-grow-1">
    <h4 class="mb-0">Typy wniosków i pism</h4>
    <div class="text-muted small">Definiuj jakie pisma mogą składać użytkownicy i jakie pola mają wypełniać</div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#form-new-type">
    <i class="bi bi-plus-lg me-1"></i> Nowy typ
  </button>
  <a href="<?= APP_URL ?>/admin/applications.php" class="btn btn-outline-secondary">
    <i class="bi bi-inbox me-1"></i> Złożone wnioski
  </a>
</div>

<?= flash_html() ?>

<!-- ── Formularz nowego / edycja typu ────────────────────────────────────────── -->
<div class="collapse<?= ($edit_type || !$types) ? ' show' : '' ?> mb-4" id="form-new-type">
  <div class="card shadow-sm border-primary">
    <div class="card-header fw-semibold text-primary">
      <i class="bi bi-<?= $edit_type ? 'pencil' : 'plus-circle' ?> me-2"></i>
      <?= $edit_type ? 'Edycja typu: ' . h($edit_type['label']) : 'Nowy typ wniosku / pisma' ?>
    </div>
    <div class="card-body">
      <form method="post" novalidate>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_type">
        <input type="hidden" name="id" value="<?= $edit_type ? $edit_type['id'] : 0 ?>">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Nazwa wewnętrzna <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control font-monospace"
                   value="<?= h($edit_type['name'] ?? '') ?>"
                   placeholder="np. wniosek_urlop" pattern="[a-z0-9_]+"
                   <?= $edit_type ? 'readonly' : '' ?> required>
            <div class="form-text">Tylko małe litery, cyfry i _. Nie można zmieniać po zapisaniu.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Etykieta (widoczna dla użytkownika) <span class="text-danger">*</span></label>
            <input type="text" name="label" class="form-control"
                   value="<?= h($edit_type['label'] ?? '') ?>"
                   placeholder="np. Wniosek o urlop" required>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Ikona Bootstrap Icons</label>
            <div class="input-group">
              <span class="input-group-text" id="icon-preview">
                <i class="bi <?= h($edit_type['icon'] ?? 'bi-file-text') ?>"></i>
              </span>
              <input type="text" name="icon" class="form-control font-monospace"
                     value="<?= h($edit_type['icon'] ?? 'bi-file-text') ?>"
                     placeholder="bi-file-text" id="icon-input">
            </div>
            <div class="form-text">
              Np.:
              <?php foreach (['bi-calendar-check','bi-award','bi-envelope-paper','bi-pencil-square','bi-file-earmark-x','bi-chat-left-text','bi-briefcase'] as $ic): ?>
              <a href="#" class="icon-pick text-decoration-none me-1" data-icon="<?= $ic ?>"><i class="bi <?= $ic ?>"></i></a>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Opis (widoczny w formularzu)</label>
            <input type="text" name="description" class="form-control"
                   value="<?= h($edit_type['description'] ?? '') ?>"
                   placeholder="Krótki opis czego dotyczy ten typ pisma">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Kolejność</label>
            <input type="number" name="sort_order" class="form-control" value="<?= $edit_type['sort_order'] ?? 0 ?>">
          </div>
          <div class="col-md-9 d-flex gap-4 align-items-center pt-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="requires_contract" id="req_contract"
                     <?= ($edit_type['requires_contract'] ?? 0) ? 'checked' : '' ?>>
              <label class="form-check-label" for="req_contract">Wymaga wskazania umowy</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="allow_attachment" id="allow_att"
                     <?= ($edit_type['allow_attachment'] ?? 1) ? 'checked' : '' ?>>
              <label class="form-check-label" for="allow_att">Zezwól na załącznik</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                     <?= ($edit_type['is_active'] ?? 1) ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_active">Aktywny (widoczny dla użytkowników)</label>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2 mt-4">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check2 me-1"></i> Zapisz typ
          </button>
          <a href="<?= APP_URL ?>/admin/application_types.php" class="btn btn-outline-secondary">Anuluj</a>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Lista typów z polami ───────────────────────────────────────────────────── -->
<?php if (!$types): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-ui-checks-grid fs-1 d-block mb-2 opacity-25"></i>
    <p class="mb-0">Brak zdefiniowanych typów. Dodaj pierwszy typ klikając „Nowy typ".</p>
  </div>
</div>
<?php else: ?>

<div class="accordion shadow-sm" id="types-acc">
<?php foreach ($types as $t): ?>
<div class="accordion-item" id="type-<?= $t['id'] ?>">
  <h2 class="accordion-header">
    <button class="accordion-button<?= count($types) > 1 ? ' collapsed' : '' ?>" type="button"
            data-bs-toggle="collapse" data-bs-target="#tc-<?= $t['id'] ?>">
      <i class="bi <?= h($t['icon']) ?> me-2 text-primary"></i>
      <span class="fw-semibold me-2"><?= h($t['label']) ?></span>
      <code class="text-muted fw-normal small me-3"><?= h($t['name']) ?></code>
      <?php if (!$t['is_active']): ?>
      <span class="badge bg-secondary me-2">Nieaktywny</span>
      <?php endif; ?>
      <span class="badge bg-light text-dark border ms-auto me-2">
        <?= count($t['fields']) ?> <?= count($t['fields']) === 1 ? 'pole' : 'pola/pól' ?>
      </span>
    </button>
  </h2>
  <div id="tc-<?= $t['id'] ?>" class="accordion-collapse collapse<?= count($types) === 1 ? ' show' : '' ?>">
    <div class="accordion-body pt-0">

      <!-- Pasek akcji dla typu -->
      <div class="d-flex gap-2 flex-wrap py-3 border-bottom mb-3">
        <?php if ($t['requires_contract']): ?>
        <span class="badge bg-info text-dark"><i class="bi bi-file-earmark-text me-1"></i>Wymaga umowy</span>
        <?php endif; ?>
        <?php if ($t['allow_attachment']): ?>
        <span class="badge bg-light text-dark border"><i class="bi bi-paperclip me-1"></i>Załącznik dozwolony</span>
        <?php endif; ?>
        <?php if ($t['description']): ?>
        <span class="text-muted small fst-italic"><?= h($t['description']) ?></span>
        <?php endif; ?>
        <div class="ms-auto d-flex gap-2">
          <a href="?edit=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil me-1"></i>Edytuj typ
          </a>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-<?= $t['is_active'] ? 'warning' : 'success' ?>">
              <i class="bi bi-<?= $t['is_active'] ? 'pause' : 'play' ?> me-1"></i>
              <?= $t['is_active'] ? 'Dezaktywuj' : 'Aktywuj' ?>
            </button>
          </form>
          <form method="post" class="d-inline"
                onsubmit="return confirm('Usunąć typ „<?= h(addslashes($t['label'])) ?>" wraz ze wszystkimi polami?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete_type">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">
              <i class="bi bi-trash"></i>
            </button>
          </form>
        </div>
      </div>

      <!-- Tabela pól -->
      <?php if ($t['fields']): ?>
      <div class="table-responsive mb-3">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:36px"></th>
              <th>Nazwa</th>
              <th>Etykieta</th>
              <th>Typ pola</th>
              <th>Opcje / placeholder</th>
              <th class="text-center">Wymagane</th>
              <th class="text-end">Akcje</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($t['fields'] as $fi => $f):
              $opts = app_field_options($f);
          ?>
            <tr>
              <td class="text-muted small text-center"><?= $f['sort_order'] ?></td>
              <td><code class="small"><?= h($f['name']) ?></code></td>
              <td class="fw-semibold"><?= h($f['label']) ?></td>
              <td>
                <span class="badge bg-light text-dark border">
                  <i class="bi <?= FIELD_TYPES[$f['field_type']]['icon'] ?? 'bi-file-text' ?> me-1"></i>
                  <?= FIELD_TYPES[$f['field_type']]['label'] ?? $f['field_type'] ?>
                </span>
              </td>
              <td class="text-muted small">
                <?php if ($f['field_type'] === 'select' && $opts): ?>
                  <?= implode(', ', array_map('htmlspecialchars', array_slice($opts, 0, 3))) ?>
                  <?php if (count($opts) > 3): ?><em>+<?= count($opts) - 3 ?> więcej</em><?php endif; ?>
                <?php elseif ($f['placeholder']): ?>
                  <em><?= h($f['placeholder']) ?></em>
                <?php else: ?>&mdash;<?php endif; ?>
              </td>
              <td class="text-center">
                <?php if ($f['required']): ?>
                <i class="bi bi-check-circle-fill text-success"></i>
                <?php else: ?>
                <i class="bi bi-dash text-muted"></i>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <div class="d-flex justify-content-end gap-1">
                  <!-- Przesuń -->
                  <?php if ($fi > 0): ?>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="move_field">
                    <input type="hidden" name="field_id" value="<?= $f['id'] ?>">
                    <input type="hidden" name="type_id" value="<?= $t['id'] ?>">
                    <input type="hidden" name="dir" value="up">
                    <button type="submit" class="btn btn-xs btn-outline-secondary" title="W górę">
                      <i class="bi bi-arrow-up"></i>
                    </button>
                  </form>
                  <?php endif; ?>
                  <?php if ($fi < count($t['fields']) - 1): ?>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="move_field">
                    <input type="hidden" name="field_id" value="<?= $f['id'] ?>">
                    <input type="hidden" name="type_id" value="<?= $t['id'] ?>">
                    <input type="hidden" name="dir" value="down">
                    <button type="submit" class="btn btn-xs btn-outline-secondary" title="W dół">
                      <i class="bi bi-arrow-down"></i>
                    </button>
                  </form>
                  <?php endif; ?>
                  <!-- Edytuj -->
                  <button class="btn btn-xs btn-outline-primary" title="Edytuj pole"
                          data-bs-toggle="collapse" data-bs-target="#fedit-<?= $f['id'] ?>">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <!-- Usuń -->
                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Usunąć pole „<?= h(addslashes($f['label'])) ?>"?')">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="delete_field">
                    <input type="hidden" name="field_id" value="<?= $f['id'] ?>">
                    <input type="hidden" name="type_id" value="<?= $t['id'] ?>">
                    <button type="submit" class="btn btn-xs btn-outline-danger" title="Usuń">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
                <!-- Inline edycja pola -->
                <div class="collapse mt-2 text-start" id="fedit-<?= $f['id'] ?>">
                  <?= _field_form($t['id'], $f) ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="text-muted small fst-italic mb-3">Brak pól — użytkownik zobaczy tylko pola Tytuł i ewentualny załącznik.</div>
      <?php endif; ?>

      <!-- Dodaj nowe pole -->
      <div>
        <button class="btn btn-sm btn-outline-success" data-bs-toggle="collapse"
                data-bs-target="#fadd-<?= $t['id'] ?>">
          <i class="bi bi-plus-lg me-1"></i>Dodaj pole
        </button>
        <div class="collapse mt-2" id="fadd-<?= $t['id'] ?>">
          <?= _field_form($t['id'], null) ?>
        </div>
      </div>

    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php
// ── Funkcja renderująca formularz pola ────────────────────────────────────────
function _field_form(int $type_id, ?array $f): string {
    $fid    = $f['id']         ?? 0;
    $fname  = $f['name']       ?? '';
    $flabel = $f['label']      ?? '';
    $ftype  = $f['field_type'] ?? 'text';
    $ph     = $f['placeholder'] ?? '';
    $req    = $f['required']   ?? 0;
    $sort   = $f['sort_order'] ?? 0;
    $opts   = $f ? app_field_options($f) : [];
    $opts_text = implode("\n", $opts);

    ob_start(); ?>
    <div class="card border-success-subtle bg-success bg-opacity-10">
      <div class="card-body py-2 px-3">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action"   value="save_field">
          <input type="hidden" name="type_id"  value="<?= $type_id ?>">
          <input type="hidden" name="field_id" value="<?= $fid ?>">
          <div class="row g-2 align-items-end">
            <div class="col-sm-3">
              <label class="form-label small fw-semibold mb-1">Nazwa <span class="text-danger">*</span></label>
              <input type="text" name="fname" class="form-control form-control-sm font-monospace"
                     value="<?= h($fname) ?>" placeholder="np. data_od" pattern="[a-z0-9_]+"
                     <?= $fid ? 'readonly' : '' ?> required>
            </div>
            <div class="col-sm-3">
              <label class="form-label small fw-semibold mb-1">Etykieta <span class="text-danger">*</span></label>
              <input type="text" name="flabel" class="form-control form-control-sm"
                     value="<?= h($flabel) ?>" placeholder="np. Data od" required>
            </div>
            <div class="col-sm-2">
              <label class="form-label small fw-semibold mb-1">Typ pola</label>
              <select name="ftype" class="form-select form-select-sm ftype-sel">
                <?php foreach (FIELD_TYPES as $k => $ft): ?>
                <option value="<?= $k ?>"<?= $ftype === $k ? ' selected' : '' ?>><?= $ft['label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-2">
              <label class="form-label small fw-semibold mb-1">Placeholder</label>
              <input type="text" name="placeholder" class="form-control form-control-sm"
                     value="<?= h($ph) ?>" placeholder="Podpowiedź...">
            </div>
            <div class="col-sm-1">
              <label class="form-label small fw-semibold mb-1">Kolejność</label>
              <input type="number" name="fsort" class="form-control form-control-sm" value="<?= $sort ?>">
            </div>
            <div class="col-sm-1 d-flex align-items-center gap-2 pt-3">
              <div class="form-check mb-0">
                <input type="checkbox" class="form-check-input" name="frequired" id="freq-<?= $fid ?: 'new-' . $type_id ?>"
                       <?= $req ? 'checked' : '' ?>>
                <label class="form-check-label small" for="freq-<?= $fid ?: 'new-' . $type_id ?>">Wymagane</label>
              </div>
            </div>
            <!-- Opcje dla select -->
            <div class="col-12 opts-row" style="<?= $ftype !== 'select' ? 'display:none' : '' ?>">
              <label class="form-label small fw-semibold mb-1">Opcje listy <span class="text-muted fw-normal">(każda opcja w nowej linii)</span></label>
              <textarea name="options_text" class="form-control form-control-sm font-monospace" rows="3"
                        placeholder="Opcja 1&#10;Opcja 2&#10;Opcja 3"><?= h($opts_text) ?></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-sm btn-success">
                <i class="bi bi-check2 me-1"></i><?= $fid ? 'Zapisz zmiany' : 'Dodaj pole' ?>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
    <?php return ob_get_clean();
}
?>

<style>
.btn-xs { padding: .1rem .35rem; font-size: .75rem; }
</style>

<script>
// Podgląd ikony
document.getElementById('icon-input')?.addEventListener('input', function () {
    const el = document.getElementById('icon-preview').querySelector('i');
    el.className = 'bi ' + this.value.trim();
});
document.querySelectorAll('.icon-pick').forEach(a => {
    a.addEventListener('click', e => {
        e.preventDefault();
        const ic = a.dataset.icon;
        document.getElementById('icon-input').value = ic;
        document.getElementById('icon-preview').querySelector('i').className = 'bi ' + ic;
    });
});

// Pokaż/ukryj sekcję opcji dla select
document.querySelectorAll('.ftype-sel').forEach(sel => {
    sel.addEventListener('change', function () {
        this.closest('form').querySelector('.opts-row').style.display =
            this.value === 'select' ? '' : 'none';
    });
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
