<?php
/**
 * crm/settings/services.php — otwarty katalog rodzajów usług.
 *
 * Rodzaje usług świadczonych na rzecz organizacji (kategoria kontaktu
 * „świadczy usługi na rzecz FEER"). Katalog jest otwarty: pozycje można dodawać
 * tutaj, a także wprost z karty kontaktu (pole „…lub wpisz nowy rodzaj").
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!can_write('crm_ustawienia') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do ustawień CRM.');
    header('Location: ' . APP_URL . '/crm/dashboard.php'); exit;
}
crm_migrate();
crm_require('settings', 'write');

$PAGE_TITLE = 'CRM — Rodzaje usług';
$ORG_SHORT  = org_setting('org_short_name') ?: 'FEER';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = $_POST['_op'] ?? '';
    $uid = (int)(current_user()['id'] ?? 0);

    if ($op === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $nazwa = trim($_POST['nazwa'] ?? '');
        $opis  = trim($_POST['opis']  ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);

        if ($nazwa === '') { flash_set('danger', 'Nazwa rodzaju usługi jest wymagana.'); goto redirect; }

        if ($id) {
            try {
                crm_db()->prepare("UPDATE crm_service_types SET nazwa=?, opis=?, sort_order=?, is_active=? WHERE id=?")
                    ->execute([$nazwa, $opis ?: null, $sort, isset($_POST['is_active']) ? 1 : 0, $id]);
                flash_set('success', 'Rodzaj usługi zaktualizowany.');
            } catch (\Throwable $e) {
                flash_set('danger', 'Taka nazwa już istnieje w katalogu.');
            }
        } else {
            try {
                crm_insert('crm_service_types', [
                    'nazwa'      => $nazwa,
                    'opis'       => $opis ?: null,
                    'is_active'  => 1,
                    'sort_order' => $sort,
                    'created_by' => $uid,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                flash_set('success', "Dodano rodzaj usługi: „{$nazwa}\".");
            } catch (\Throwable $e) {
                flash_set('danger', 'Taka nazwa już istnieje w katalogu.');
            }
        }
    } elseif ($op === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $cur = crm_one("SELECT is_active FROM crm_service_types WHERE id=?", [$id]);
        if ($cur) {
            crm_db()->prepare("UPDATE crm_service_types SET is_active=? WHERE id=?")
                ->execute([$cur['is_active'] ? 0 : 1, $id]);
        }
    } elseif ($op === 'delete') {
        $id   = (int)($_POST['id'] ?? 0);
        $used = crm_service_type_usage($id);
        if ($used > 0) {
            flash_set('danger', "Nie można usunąć — {$used} kontakt(ów) ma przypisany ten rodzaj usługi. "
                . 'Zamiast usuwać, wyłącz pozycję — zniknie z list wyboru, a historia zostanie.');
            goto redirect;
        }
        crm_db()->prepare("DELETE FROM crm_service_types WHERE id=?")->execute([$id]);
        flash_set('success', 'Rodzaj usługi usunięty.');
    } elseif ($op === 'reorder') {
        foreach (array_map('intval', (array)($_POST['ids'] ?? [])) as $i => $sid) {
            crm_db()->prepare("UPDATE crm_service_types SET sort_order=? WHERE id=?")->execute([$i, $sid]);
        }
        header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
    }
    redirect:
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

$types    = crm_service_types(false);
$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_row = $edit_id ? crm_one("SELECT * FROM crm_service_types WHERE id=?", [$edit_id]) : null;

$total_contacts = (int)(crm_one(
    "SELECT COUNT(DISTINCT contact_id) AS c FROM crm_contact_services"
)['c'] ?? 0);

include __DIR__ . '/../includes/header_crm.php';
require_once __DIR__ . '/_nav.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb mb-0 small">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia CRM</a></li>
  <li class="breadcrumb-item active">Rodzaje usług</li>
</ol></nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-tools"></i></div>
  <div>
    <h1 class="crm-object-title">Rodzaje usług</h1>
    <div class="crm-object-count">
      Katalog dla kategorii „świadczy usługi na rzecz <?= h($ORG_SHORT) ?>" —
      <?= count($types) ?> pozycji, <?= $total_contacts ?> kontakt(ów) z przypisaniem
    </div>
  </div>
  <div class="crm-object-actions">
    <button class="btn btn-crm-primary btn-sm" onclick="document.getElementById('add-form').classList.toggle('d-none')">
      <i class="bi bi-plus-lg me-1"></i>Dodaj rodzaj
    </button>
  </div>
</div>

<?= flash_get() ?>

<div class="alert alert-light border small">
  <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
  Katalog jest <strong>otwarty</strong> — nowy rodzaj można dopisać także wprost z karty kontaktu,
  w panelu „Usługi na rzecz <?= h($ORG_SHORT) ?>". Pozycji użytej przy kontaktach nie da się usunąć;
  wyłącz ją, aby zniknęła z list wyboru, zachowując historię.
</div>

<div class="card mb-4">
  <div class="card-header bg-white py-2 small fw-semibold text-muted">
    Przeciągnij wiersze, aby zmienić kolejność na listach wyboru. Zmiany zapisują się automatycznie.
  </div>
  <div class="table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:30px"><span class="visually-hidden">Uchwyt przeciągania</span></th>
          <th>Rodzaj usługi</th>
          <th>Opis</th>
          <th>Stan</th>
          <th>Kontaktów</th>
          <th><span class="visually-hidden">Akcje</span></th>
        </tr>
      </thead>
      <tbody id="svc-sortable">
        <?php if (!$types): ?>
        <tr><td colspan="6" class="text-muted small py-3">Katalog jest pusty — dodaj pierwszy rodzaj usługi.</td></tr>
        <?php endif; ?>
        <?php foreach ($types as $t): $cnt = crm_service_type_usage((int)$t['id']); ?>
        <tr data-id="<?= (int)$t['id'] ?>">
          <td class="drag-handle text-muted" style="cursor:grab"><i class="bi bi-grip-vertical" aria-hidden="true"></i></td>
          <td class="fw-semibold"><?= h($t['nazwa']) ?></td>
          <td class="small text-muted"><?= h($t['opis'] ?? '') ?: '—' ?></td>
          <td>
            <?php if ($t['is_active']): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle">aktywny</span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary border">wyłączony</span>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-light text-dark border"><?= $cnt ?></span></td>
          <td class="d-flex gap-1">
            <a href="?edit=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"
               aria-label="Edytuj <?= h($t['nazwa']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="_op" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="btn btn-sm <?= $t['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> py-0 px-2"
                      aria-label="<?= $t['is_active'] ? 'Wyłącz' : 'Włącz' ?> <?= h($t['nazwa']) ?>">
                <i class="bi <?= $t['is_active'] ? 'bi-pause' : 'bi-play' ?>" aria-hidden="true"></i>
              </button>
            </form>
            <?php if ($cnt === 0): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć rodzaj usługi <?= h(addslashes($t['nazwa'])) ?>?')">
              <?= csrf_field() ?><input type="hidden" name="_op" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2" aria-label="Usuń <?= h($t['nazwa']) ?>">
                <i class="bi bi-trash3" aria-hidden="true"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="add-form" class="card mb-3 <?= $edit_row ? '' : 'd-none' ?>">
  <div class="card-header bg-white py-2 fw-semibold"><?= $edit_row ? 'Edytuj rodzaj usługi' : 'Nowy rodzaj usługi' ?></div>
  <div class="card-body">
    <form method="post" class="row g-3 align-items-end">
      <?= csrf_field() ?><input type="hidden" name="_op" value="save">
      <?php if ($edit_row): ?><input type="hidden" name="id" value="<?= (int)$edit_row['id'] ?>"><?php endif; ?>
      <div class="col-md-4">
        <label class="form-label small mb-1" for="svc_nazwa">Nazwa <span class="text-danger">*</span></label>
        <input type="text" name="nazwa" id="svc_nazwa" class="form-control form-control-sm" required maxlength="120"
               value="<?= h($edit_row['nazwa'] ?? '') ?>" placeholder="np. Usługi księgowe">
      </div>
      <div class="col-md-5">
        <label class="form-label small mb-1" for="svc_opis">Opis</label>
        <input type="text" name="opis" id="svc_opis" class="form-control form-control-sm" maxlength="255"
               value="<?= h($edit_row['opis'] ?? '') ?>" placeholder="Do czego odnosi się ten rodzaj usługi">
      </div>
      <div class="col-md-1">
        <label class="form-label small mb-1" for="svc_sort">Kolejność</label>
        <input type="number" name="sort_order" id="svc_sort" class="form-control form-control-sm"
               value="<?= (int)($edit_row['sort_order'] ?? count($types) + 1) ?>">
      </div>
      <?php if ($edit_row): ?>
      <div class="col-auto d-flex align-items-center">
        <div class="form-check mb-0">
          <input type="checkbox" name="is_active" class="form-check-input" id="svcActive"
                 value="1" <?= $edit_row['is_active'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="svcActive">Aktywny</label>
        </div>
      </div>
      <?php endif; ?>
      <div class="col-auto">
        <button type="submit" class="btn btn-crm-primary btn-sm">
          <?= $edit_row ? '<i class="bi bi-save me-1"></i>Zapisz' : '<i class="bi bi-plus-lg me-1"></i>Dodaj' ?>
        </button>
        <a href="<?= APP_URL ?>/crm/settings/services.php" class="btn btn-outline-secondary btn-sm ms-1">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  const tbody = document.getElementById('svc-sortable');
  if (!tbody) return;
  let drag = null;

  tbody.querySelectorAll('tr[data-id]').forEach(tr => {
    tr.draggable = true;
    tr.addEventListener('dragstart', () => { drag = tr; tr.style.opacity = '.4'; });
    tr.addEventListener('dragend',   () => { drag = null; tr.style.opacity = ''; });
    tr.addEventListener('dragover',  e => {
      e.preventDefault();
      const r = tr.getBoundingClientRect();
      tr.style.borderTop    = e.clientY <  r.top + r.height / 2 ? '2px solid var(--crm-primary)' : '';
      tr.style.borderBottom = e.clientY >= r.top + r.height / 2 ? '2px solid var(--crm-primary)' : '';
    });
    tr.addEventListener('dragleave', () => { tr.style.borderTop = tr.style.borderBottom = ''; });
    tr.addEventListener('drop', e => {
      e.preventDefault();
      tr.style.borderTop = tr.style.borderBottom = '';
      if (!drag || drag === tr) return;
      const r = tr.getBoundingClientRect();
      tbody.insertBefore(drag, e.clientY < r.top + r.height / 2 ? tr : tr.nextSibling);
      const fd = new FormData();
      fd.append('_op', 'reorder');
      fd.append('_csrf', '<?= csrf_token() ?>');
      [...tbody.querySelectorAll('tr[data-id]')].forEach(x => fd.append('ids[]', x.dataset.id));
      fetch('', { method: 'POST', body: fd });
    });
  });
})();
</script>
<?php require_once __DIR__ . '/_nav_end.php'; ?>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
