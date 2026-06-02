<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');

$PAGE_TITLE = 'Typy wiadomości';

// ── Utwórz tabelę jeśli nie istnieje ─────────────────────────────────────────
db()->exec("CREATE TABLE IF NOT EXISTS message_types (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    name           TEXT NOT NULL,
    description    TEXT NOT NULL DEFAULT '',
    available_for  TEXT NOT NULL DEFAULT 'both',
    is_active      INTEGER NOT NULL DEFAULT 1,
    sort_order     INTEGER NOT NULL DEFAULT 0,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ── Załaduj domyślne typy (tylko gdy tabela pusta) ────────────────────────────
$count = db_one("SELECT COUNT(*) AS c FROM message_types");
if ((int)($count['c'] ?? 0) === 0) {
    $defaults = [
        ['Pytanie ogólne',                    '',  'both', 1],
        ['Zmiana danych osobowych',           '',  'user', 2],
        ['Pytanie o wynagrodzenie/rozliczenie','', 'user', 3],
        ['Pytanie o harmonogram/zadania',     '',  'user', 4],
        ['Problem z kontem Microsoft 365',    '',  'user', 5],
        ['Inne',                              '',  'both', 6],
    ];
    foreach ($defaults as [$name, $desc, $for, $ord]) {
        db_insert('message_types', [
            'name'          => $name,
            'description'   => $desc,
            'available_for' => $for,
            'is_active'     => 1,
            'sort_order'    => $ord,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // Dodaj nowy typ
    if ($action === 'add') {
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 255);
        $for  = in_array($_POST['available_for'] ?? '', ['admin', 'user', 'both'], true)
                ? $_POST['available_for'] : 'both';
        if ($name !== '') {
            $max = db_one("SELECT MAX(sort_order) AS m FROM message_types");
            db_insert('message_types', [
                'name'          => $name,
                'description'   => $desc,
                'available_for' => $for,
                'is_active'     => 1,
                'sort_order'    => ((int)($max['m'] ?? 0)) + 1,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
            flash_set('success', 'Typ wiadomości dodany.');
        }
    }

    // Edycja inline
    if ($action === 'edit') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 255);
        $for  = in_array($_POST['available_for'] ?? '', ['admin', 'user', 'both'], true)
                ? $_POST['available_for'] : 'both';
        if ($id && $name !== '') {
            db()->prepare("UPDATE message_types SET name=?, description=?, available_for=? WHERE id=?")
                 ->execute([$name, $desc, $for, $id]);
            flash_set('success', 'Zaktualizowano.');
        }
    }

    // Przełącz aktywność
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            db()->prepare("UPDATE message_types SET is_active = 1 - is_active WHERE id=?")
                 ->execute([$id]);
        }
    }

    // Usuń (tylko nieużywane)
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $used = db_one("SELECT COUNT(*) AS c FROM messages WHERE type_id=?", [$id]);
            if ((int)($used['c'] ?? 0) === 0) {
                db()->prepare("DELETE FROM message_types WHERE id=?")->execute([$id]);
                flash_set('success', 'Usunięto typ wiadomości.');
            } else {
                flash_set('error', 'Nie można usunąć — typ jest używany przez wiadomości. Ukryj go zamiast usuwać.');
            }
        }
    }

    // Przesunięcie kolejności ▲▼
    if ($action === 'move_up' || $action === 'move_down') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = $id ? db_one("SELECT id, sort_order FROM message_types WHERE id=?", [$id]) : null;
        if ($row) {
            if ($action === 'move_up') {
                $neighbor = db_one(
                    "SELECT id, sort_order FROM message_types WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1",
                    [$row['sort_order']]
                );
            } else {
                $neighbor = db_one(
                    "SELECT id, sort_order FROM message_types WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1",
                    [$row['sort_order']]
                );
            }
            if ($neighbor) {
                db()->prepare("UPDATE message_types SET sort_order=? WHERE id=?")->execute([$neighbor['sort_order'], $row['id']]);
                db()->prepare("UPDATE message_types SET sort_order=? WHERE id=?")->execute([$row['sort_order'], $neighbor['id']]);
            }
        }
    }

    header('Location: message_types.php');
    exit;
}

$types = db_all("SELECT * FROM message_types ORDER BY sort_order ASC, id ASC");

// Czy edytujemy konkretny wiersz?
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0"><i class="bi bi-tags text-primary"></i> Typy wiadomości</h4>
  <a href="msg_settings.php" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-gear"></i> Ustawienia powiadomień
  </a>
</div>

<?= flash_html() ?>

<!-- ── Formularz dodawania ────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold">
    <i class="bi bi-plus-circle text-success me-1"></i> Dodaj nowy typ wiadomości
  </div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="add">
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Nazwa <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control form-control-sm"
               placeholder="np. Pytanie ogólne" maxlength="120" required>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Opis <span class="text-muted fw-normal">(opcjonalnie)</span></label>
        <input type="text" name="description" class="form-control form-control-sm"
               placeholder="Krótki opis dla użytkownika" maxlength="255">
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold">Dostępny dla</label>
        <select name="available_for" class="form-select form-select-sm">
          <option value="both">Obu stron</option>
          <option value="user">Tylko użytkownik</option>
          <option value="admin">Tylko admin</option>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-success btn-sm w-100">
          <i class="bi bi-plus-lg"></i> Dodaj
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Lista typów ────────────────────────────────────────────────────────── -->
<?php if (!$types): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-tags" style="font-size:2.5rem;opacity:.25"></i>
    <div class="mt-3">Brak typów wiadomości.</div>
  </div>
</div>
<?php else: ?>

<div class="card shadow-sm">
  <div class="table-responsive">
  <table class="table table-hover table-sm mb-0 align-middle">
    <thead class="table-light">
      <tr>
        <th style="width:2.5rem">#</th>
        <th>Nazwa</th>
        <th>Opis</th>
        <th>Dostępny dla</th>
        <th class="text-center">Aktywny</th>
        <th style="width:1rem">Kol.</th>
        <th class="text-end">Akcje</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($types as $t):
        $is_editing = ($edit_id === (int)$t['id']);
        $used = (int)(db_one("SELECT COUNT(*) AS c FROM messages WHERE type_id=?", [(int)$t['id']])['c'] ?? 0);
        $for_labels = ['both' => 'Obu stron', 'user' => 'Użytkownik', 'admin' => 'Admin'];
        $for_badge  = ['both' => 'bg-primary', 'user' => 'bg-info text-dark', 'admin' => 'bg-secondary'];
    ?>
    <tr class="<?= !$t['is_active'] ? 'table-secondary text-muted' : '' ?>">

      <?php if ($is_editing): ?>
      <!-- ── Tryb edycji ── -->
      <td colspan="5">
        <form method="post" class="row g-2 align-items-end py-1">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="edit">
          <input type="hidden" name="id"      value="<?= (int)$t['id'] ?>">
          <div class="col-md-4">
            <input type="text" name="name" class="form-control form-control-sm"
                   value="<?= h($t['name']) ?>" maxlength="120" required autofocus>
          </div>
          <div class="col-md-4">
            <input type="text" name="description" class="form-control form-control-sm"
                   value="<?= h($t['description']) ?>" maxlength="255"
                   placeholder="Opis (opcjonalnie)">
          </div>
          <div class="col-md-2">
            <select name="available_for" class="form-select form-select-sm">
              <option value="both"  <?= $t['available_for']==='both'  ? 'selected':'' ?>>Obu stron</option>
              <option value="user"  <?= $t['available_for']==='user'  ? 'selected':'' ?>>Tylko użytkownik</option>
              <option value="admin" <?= $t['available_for']==='admin' ? 'selected':'' ?>>Tylko admin</option>
            </select>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Zapisz</button>
            <a href="message_types.php" class="btn btn-secondary btn-sm ms-1">Anuluj</a>
          </div>
        </form>
      </td>
      <td></td>
      <td></td>
      <?php else: ?>
      <!-- ── Tryb widoku ── -->
      <td class="text-muted small"><?= (int)$t['sort_order'] ?></td>
      <td>
        <span class="fw-semibold <?= !$t['is_active'] ? 'text-muted' : '' ?>"><?= h($t['name']) ?></span>
        <?php if ($used): ?>
        <span class="badge bg-light text-dark border ms-1" title="Liczba wiadomości z tym typem" style="font-size:.65rem"><?= $used ?> wiad.</span>
        <?php endif; ?>
      </td>
      <td class="small text-muted"><?= h($t['description']) ?: '—' ?></td>
      <td>
        <span class="badge <?= $for_badge[$t['available_for']] ?? 'bg-secondary' ?>" style="font-size:.7rem">
          <?= $for_labels[$t['available_for']] ?? $t['available_for'] ?>
        </span>
      </td>
      <td class="text-center">
        <?php if ($t['is_active']): ?>
        <i class="bi bi-check-circle-fill text-success"></i>
        <?php else: ?>
        <i class="bi bi-dash-circle text-muted"></i>
        <?php endif; ?>
      </td>
      <?php endif; ?>

      <!-- Kolejność -->
      <td class="text-nowrap">
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="move_up">
          <input type="hidden" name="id"      value="<?= (int)$t['id'] ?>">
          <button type="submit" class="btn btn-sm btn-link p-0 me-1" title="Przesuń wyżej">
            <i class="bi bi-chevron-up"></i>
          </button>
        </form>
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="move_down">
          <input type="hidden" name="id"      value="<?= (int)$t['id'] ?>">
          <button type="submit" class="btn btn-sm btn-link p-0" title="Przesuń niżej">
            <i class="bi bi-chevron-down"></i>
          </button>
        </form>
      </td>

      <!-- Akcje -->
      <td class="text-end text-nowrap">
        <?php if (!$is_editing): ?>
        <a href="?edit=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary"
           title="Edytuj"><i class="bi bi-pencil"></i></a>

        <form method="post" class="d-inline ms-1">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="toggle">
          <input type="hidden" name="id"      value="<?= (int)$t['id'] ?>">
          <button type="submit" class="btn btn-sm <?= $t['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                  title="<?= $t['is_active'] ? 'Ukryj' : 'Aktywuj' ?>">
            <i class="bi <?= $t['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
          </button>
        </form>

        <?php if (!$used): ?>
        <form method="post" class="d-inline ms-1"
              onsubmit="return confirm('Usunąć typ &quot;<?= addslashes(h($t['name'])) ?>&quot;?')">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="delete">
          <input type="hidden" name="id"      value="<?= (int)$t['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger" title="Usuń">
            <i class="bi bi-trash3"></i>
          </button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="text-muted small mt-2">
  <i class="bi bi-info-circle"></i>
  Typy oznaczone jako „Tylko użytkownik" są widoczne w formularzu wiadomości w panelu użytkownika.
  Typy „Obu stron" — zarówno w panelu jak i w systemie administratora.
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
