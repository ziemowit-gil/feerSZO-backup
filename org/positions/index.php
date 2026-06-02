<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/org.php';
require_role('admin'); require_module_enabled('org_enabled','Moduł struktury organizacyjnej');

$PAGE_TITLE = 'Szablony stanowisk';

// ── Obsługa POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        if (!$name) { flash_set('error', 'Nazwa stanowiska jest wymagana.'); }
        else {
            $sort = (int)($_POST['sort_order'] ?? 0);
            $head = isset($_POST['is_head_role']) ? 1 : 0;
            try {
                db()->prepare("INSERT INTO org_positions (name,code,is_head_role,sort_order) VALUES (?,?,?,?)")
                   ->execute([$name, $code, $head, $sort]);
                flash_set('success', 'Stanowisko dodane.');
            } catch (\Throwable $e) { flash_set('error', 'Błąd: ' . $e->getMessage()); }
        }
    }

    elseif ($action === 'edit') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        if (!$id || !$name) { flash_set('error', 'Nieprawidłowe dane.'); }
        else {
            $sort = (int)($_POST['sort_order'] ?? 0);
            $head = isset($_POST['is_head_role']) ? 1 : 0;
            try {
                db()->prepare("UPDATE org_positions SET name=?,code=?,is_head_role=?,sort_order=? WHERE id=?")
                   ->execute([$name, $code, $head, $sort, $id]);
                flash_set('success', 'Stanowisko zaktualizowane.');
            } catch (\Throwable $e) { flash_set('error', 'Błąd: ' . $e->getMessage()); }
        }
    }

    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Nie usuwaj jeśli są przypisania
        $used = db_one("SELECT COUNT(*) AS c FROM org_members WHERE position_id=?", [$id])['c'] ?? 0;
        if ($used > 0) {
            flash_set('error', "Stanowisko jest używane przez $used przypisań — usuń powiązania przed usunięciem.");
        } else {
            db()->prepare("DELETE FROM org_positions WHERE id=?")->execute([$id]);
            flash_set('success', 'Stanowisko usunięte.');
        }
    }

    header('Location: ' . APP_URL . '/org/positions/index.php'); exit;
}

// ── Dane ──────────────────────────────────────────────────────────────────────
$positions = db_all(
    "SELECT p.*, (SELECT COUNT(*) FROM org_members m WHERE m.position_id=p.id) AS usage_count
     FROM org_positions p ORDER BY p.sort_order, p.name"
);

// Dla modala edycji — pobierz bieżącą pozycję z GET
$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_pos = $edit_id ? db_one("SELECT * FROM org_positions WHERE id=?", [$edit_id]) : null;

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/org/index.php">Struktura</a></li>
  <li class="breadcrumb-item active">Szablony stanowisk</li>
</ol></nav>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-briefcase text-primary me-2"></i>Szablony stanowisk</h4>
  <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
    <i class="bi bi-plus-lg me-1"></i>Dodaj stanowisko
  </button>
</div>

<?= flash_html() ?>

<div class="card shadow-sm">
  <div class="card-header fw-semibold" style="font-size:.88rem">
    <i class="bi bi-list-ul me-1 text-primary"></i>Stanowiska (<?= count($positions) ?>)
    <span class="text-muted fw-normal ms-2" style="font-size:.75rem">
      Szablony używane w formularzach przypisań — pozycja z gwiazdką ★ oznacza rolę kierowniczą.
    </span>
  </div>
  <?php if ($positions): ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
      <thead class="table-light">
        <tr>
          <th style="width:50px">Poz.</th>
          <th>Nazwa stanowiska</th>
          <th style="width:90px">Kod</th>
          <th style="width:110px">Rola</th>
          <th style="width:80px" class="text-center">Użycia</th>
          <th style="width:100px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($positions as $p): ?>
      <tr>
        <td class="text-muted text-center"><?= $p['sort_order'] ?></td>
        <td class="fw-semibold">
          <?= h($p['name']) ?>
          <?php if ($p['is_head_role']): ?><span class="text-warning ms-1" title="Rola kierownicza">★</span><?php endif; ?>
        </td>
        <td><code class="bg-light px-1 rounded" style="font-size:.75rem"><?= h($p['code']) ?></code></td>
        <td>
          <?php if ($p['is_head_role']): ?>
          <span class="badge bg-warning text-dark" style="font-size:.68rem"><i class="bi bi-star-fill me-1"></i>Kierownicza</span>
          <?php else: ?>
          <span class="badge bg-light text-secondary border" style="font-size:.68rem">Standardowa</span>
          <?php endif; ?>
        </td>
        <td class="text-center">
          <?php if ($p['usage_count'] > 0): ?>
          <span class="badge bg-primary bg-opacity-15 text-primary border border-primary" style="font-size:.7rem"><?= $p['usage_count'] ?></span>
          <?php else: ?>
          <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="d-flex gap-1 justify-content-end">
            <a href="?edit=<?= $p['id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm"
               data-bs-toggle="modal" data-bs-target="#editModal<?= $p['id'] ?>"
               style="font-size:.7rem;padding:.2rem .4rem" title="Edytuj">
              <i class="bi bi-pencil"></i>
            </a>
            <?php if ($p['usage_count'] == 0): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć stanowisko «<?= addslashes(h($p['name'])) ?>»?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button class="btn btn-xs btn-outline-danger btn-sm" style="font-size:.7rem;padding:.2rem .4rem" title="Usuń">
                <i class="bi bi-trash3"></i>
              </button>
            </form>
            <?php else: ?>
            <button class="btn btn-xs btn-outline-secondary btn-sm disabled" style="font-size:.7rem;padding:.2rem .4rem"
                    title="Nie można usunąć — stanowisko ma <?= $p['usage_count'] ?> przypisań">
              <i class="bi bi-lock text-muted"></i>
            </button>
            <?php endif; ?>
          </div>

          <!-- Modal edycji -->
          <div class="modal fade" id="editModal<?= $p['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-sm">
              <div class="modal-content">
                <form method="post">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action" value="edit">
                  <input type="hidden" name="id" value="<?= $p['id'] ?>">
                  <div class="modal-header py-2">
                    <h6 class="modal-title">Edytuj stanowisko</h6>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <div class="mb-2">
                      <label class="form-label fw-semibold" style="font-size:.82rem">Nazwa</label>
                      <input type="text" name="name" class="form-control form-control-sm" value="<?= h($p['name']) ?>" required>
                    </div>
                    <div class="mb-2">
                      <label class="form-label fw-semibold" style="font-size:.82rem">Kod (skrót)</label>
                      <input type="text" name="code" class="form-control form-control-sm" value="<?= h($p['code']) ?>"
                             placeholder="np. KIER, SPEC" maxlength="20" style="text-transform:uppercase">
                    </div>
                    <div class="mb-2">
                      <label class="form-label fw-semibold" style="font-size:.82rem">Kolejność</label>
                      <input type="number" name="sort_order" class="form-control form-control-sm" value="<?= $p['sort_order'] ?>" min="0" max="999">
                    </div>
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" name="is_head_role" id="hr_<?= $p['id'] ?>" <?= $p['is_head_role'] ? 'checked' : '' ?>>
                      <label class="form-check-label" for="hr_<?= $p['id'] ?>" style="font-size:.82rem">
                        <i class="bi bi-star-fill text-warning me-1"></i>Rola kierownicza
                      </label>
                    </div>
                  </div>
                  <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-briefcase" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.25"></i>
    Brak zdefiniowanych stanowisk.
  </div>
  <?php endif; ?>
</div>

<div class="mt-3 text-muted" style="font-size:.76rem">
  <i class="bi bi-info-circle me-1"></i>
  Stanowiska to szablony używane w formularzach dodawania przypisań — pomagają zachować spójność nazewnictwa.
  Nazwa własna stanowiska nadal może być wpisana ręcznie w formularzu przypisania.
</div>

<!-- Modal: Dodaj stanowisko -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add">
        <div class="modal-header py-2">
          <h6 class="modal-title"><i class="bi bi-plus-lg me-1 text-primary"></i>Nowe stanowisko</h6>
          <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label fw-semibold" style="font-size:.82rem">Nazwa <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control form-control-sm" placeholder="np. Specjalista ds. IT" required>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold" style="font-size:.82rem">Kod (skrót)</label>
            <input type="text" name="code" class="form-control form-control-sm"
                   placeholder="np. SPEC" maxlength="20" style="text-transform:uppercase">
            <div class="form-text" style="font-size:.72rem">Opcjonalny krótki identyfikator.</div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold" style="font-size:.82rem">Kolejność wyświetlania</label>
            <input type="number" name="sort_order" class="form-control form-control-sm" value="0" min="0" max="999">
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_head_role" id="hr_new">
            <label class="form-check-label" for="hr_new" style="font-size:.82rem">
              <i class="bi bi-star-fill text-warning me-1"></i>Rola kierownicza
              <span class="text-muted">(★ oznaczenie w listach)</span>
            </label>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Dodaj</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
