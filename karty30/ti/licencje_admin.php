<?php
/**
 * karty30/ti/licencje_admin.php — Licencje na oprogramowanie (inne niż MS365).
 *
 * Katalog oprogramowania + przypisywanie licencji kursantom (login/klucz/ważność).
 * Przypisane licencje są widoczne w panelu kursanta (zakładka „Licencje").
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$can_write  = can_write('karty30') || is_admin();
$can_delete = is_admin();
$PAGE_TITLE = 'Licencje — TI';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    // Zapis pozycji katalogu (dodanie / edycja)
    if ($op === 'save_license') {
        $data = [
            'name'        => trim($_POST['name'] ?? ''),
            'vendor'      => trim($_POST['vendor'] ?? ''),
            'category'    => trim($_POST['category'] ?? ''),
            'vendor_url'  => trim($_POST['vendor_url'] ?? ''),
            'seats_total' => max(0, (int)($_POST['seats_total'] ?? 0)),
            'notes'       => trim($_POST['notes'] ?? ''),
            'is_active'   => isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($data['name'] === '') { flash_set('danger','Podaj nazwę oprogramowania.'); header('Location: licencje_admin.php'); exit; }
        $lid = (int)($_POST['license_id'] ?? 0);
        if ($lid) {
            $set = []; $p = [];
            foreach ($data as $k => $v) { $set[] = "$k=?"; $p[] = $v; }
            $p[] = $lid;
            db()->prepare("UPDATE k30_ti_licenses SET ".implode(',', $set)." WHERE id=?")->execute($p);
            flash_set('success','Oprogramowanie zaktualizowane.');
        } else {
            $data['created_by'] = current_user()['id'] ?? null;
            db_insert('k30_ti_licenses', $data);
            flash_set('success','Oprogramowanie dodane do katalogu.');
        }
        header('Location: licencje_admin.php'); exit;
    }

    // Włącz / wyłącz pozycję katalogu
    if ($op === 'toggle_license') {
        $lid = (int)($_POST['license_id'] ?? 0);
        if ($lid) db()->prepare("UPDATE k30_ti_licenses SET is_active=1-is_active WHERE id=?")->execute([$lid]);
        header('Location: licencje_admin.php'); exit;
    }

    // Usuń pozycję katalogu (wraz z przypisaniami) — tylko admin
    if ($op === 'delete_license') {
        if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
        $lid = (int)($_POST['license_id'] ?? 0);
        if ($lid) db()->prepare("DELETE FROM k30_ti_licenses WHERE id=?")->execute([$lid]);
        flash_set('success','Oprogramowanie usunięte z katalogu.');
        header('Location: licencje_admin.php'); exit;
    }

    // Przypisz licencję kursantowi
    if ($op === 'assign') {
        $license_id = (int)($_POST['license_id'] ?? 0);
        $client_id  = (int)($_POST['client_id'] ?? 0);
        if (!$license_id || !$client_id) { flash_set('danger','Wybierz oprogramowanie i kursanta.'); header('Location: licencje_admin.php#assign'); exit; }
        $exp = trim($_POST['expires_at'] ?? '');
        if ($exp !== '') { $d = DateTime::createFromFormat('Y-m-d', $exp); if (!$d || $d->format('Y-m-d') !== $exp) $exp = ''; }
        db_insert('k30_ti_client_licenses', [
            'license_id'  => $license_id,
            'client_id'   => $client_id,
            'login'       => trim($_POST['login'] ?? ''),
            'access_key'  => trim($_POST['access_key'] ?? ''),
            'notes'       => trim($_POST['notes'] ?? ''),
            'expires_at'  => $exp ?: null,
            'status'      => 'active',
            'assigned_by' => current_user()['id'] ?? null,
        ]);
        flash_set('success','Licencja przypisana kursantowi.');
        header('Location: licencje_admin.php#assign'); exit;
    }

    // Cofnij przypisanie
    if ($op === 'revoke') {
        $aid = (int)($_POST['assignment_id'] ?? 0);
        if ($aid) db()->prepare("UPDATE k30_ti_client_licenses SET status='revoked' WHERE id=?")->execute([$aid]);
        flash_set('success','Przypisanie cofnięte.');
        header('Location: licencje_admin.php#assign'); exit;
    }
}

$licenses   = k30_ti_licenses_all(false);
$edit_id    = (int)($_GET['edit'] ?? 0);
$edit_row   = $edit_id ? db_one("SELECT * FROM k30_ti_licenses WHERE id=?", [$edit_id]) : null;
$f          = $edit_row ?: ['name'=>'','vendor'=>'','category'=>'','vendor_url'=>'','seats_total'=>0,'notes'=>'','is_active'=>1];

// Kursanci TI (zapisani na kurs lub z kontem panelu)
$ti_clients = db_all(
    "SELECT DISTINCT c.id, c.name FROM k30_clients c
     WHERE c.id IN (SELECT client_id FROM k30_ti_enrollments)
        OR c.id IN (SELECT client_id FROM k30_ti_student_accounts)
     ORDER BY c.name"
);

// Wszystkie aktywne przypisania (do tabeli)
$assignments = db_all(
    "SELECT cl.*, l.name AS license_name, c.name AS client_name
     FROM k30_ti_client_licenses cl
     JOIN k30_ti_licenses l ON l.id=cl.license_id
     JOIN k30_clients c ON c.id=cl.client_id
     WHERE cl.status='active'
     ORDER BY c.name, l.name"
);
$today = date('Y-m-d');

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Licencje</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-key text-primary me-2"></i>Licencje na oprogramowanie</h4>
  <span class="text-muted small">inne niż Microsoft 365 — np. Adobe, Canva, antywirus, IDE</span>
</div>

<?= flash_html() ?>

<div class="row g-4">

  <!-- Katalog oprogramowania -->
  <div class="col-lg-5">
    <?php if ($can_write): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-semibold">
        <i class="bi bi-<?= $edit_row ? 'pencil' : 'plus-lg' ?> me-2"></i><?= $edit_row ? 'Edytuj: '.h($edit_row['name']) : 'Dodaj oprogramowanie' ?>
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"         value="save_license">
          <input type="hidden" name="license_id"  value="<?= (int)($f['id'] ?? 0) ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold">Nazwa <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="name" value="<?= h($f['name']) ?>" required
                   placeholder="np. Adobe Creative Cloud, Canva Pro, ESET">
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Producent</label>
              <input type="text" class="form-control" name="vendor" value="<?= h($f['vendor']) ?>" placeholder="Adobe, Canva…">
            </div>
            <div class="col-6">
              <label class="form-label">Kategoria</label>
              <input type="text" class="form-control" name="category" value="<?= h($f['category']) ?>" placeholder="grafika, antywirus…">
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-8">
              <label class="form-label">Link (logowanie/pobranie)</label>
              <input type="url" class="form-control" name="vendor_url" value="<?= h($f['vendor_url']) ?>" placeholder="https://…">
            </div>
            <div class="col-4">
              <label class="form-label">Liczba miejsc</label>
              <input type="number" class="form-control" name="seats_total" min="0" value="<?= (int)($f['seats_total'] ?? 0) ?>">
              <div class="form-text">0 = bez limitu</div>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">Notatki</label>
            <textarea class="form-control" name="notes" rows="2"><?= h($f['notes']) ?></textarea>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" id="lic_act" <?= ($f['is_active'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="lic_act">Aktywne (dostępne do przypisania)</label>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $edit_row ? 'Zapisz zmiany' : 'Dodaj' ?></button>
            <?php if ($edit_row): ?><a href="licencje_admin.php" class="btn btn-outline-secondary">Anuluj</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-card-list me-2"></i>Katalog oprogramowania <span class="badge bg-secondary ms-1"><?= count($licenses) ?></span></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
          <thead class="table-light"><tr><th>Nazwa</th><th>Przypisań</th><th class="text-end">Akcje</th></tr></thead>
          <tbody>
            <?php if (!$licenses): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">Brak pozycji. Dodaj pierwsze oprogramowanie.</td></tr>
            <?php endif; ?>
            <?php foreach ($licenses as $l): ?>
            <tr class="<?= $l['is_active'] ? '' : 'opacity-50' ?>">
              <td>
                <div class="fw-semibold"><?= h($l['name']) ?> <?php if (!$l['is_active']): ?><span class="badge bg-secondary">nieaktywne</span><?php endif; ?></div>
                <div class="text-muted small">
                  <?= $l['vendor'] ? h($l['vendor']) : '' ?><?= $l['category'] ? ' · '.h($l['category']) : '' ?>
                  <?php if ($l['seats_total'] > 0): ?> · <?= (int)$l['assigned_count'] ?>/<?= (int)$l['seats_total'] ?> miejsc<?php endif; ?>
                </div>
              </td>
              <td><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><?= (int)$l['assigned_count'] ?></span></td>
              <td class="text-end text-nowrap">
                <?php if ($can_write): ?>
                <a href="?edit=<?= (int)$l['id'] ?>" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2" title="Edytuj"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op"         value="toggle_license">
                  <input type="hidden" name="license_id"  value="<?= (int)$l['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2" title="<?= $l['is_active'] ? 'Wyłącz' : 'Włącz' ?>">
                    <i class="bi bi-<?= $l['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                  </button>
                </form>
                <?php endif; ?>
                <?php if ($can_delete): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć „<?= h(addslashes($l['name'])) ?>” i wszystkie jego przypisania?')">
                  <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op"         value="delete_license">
                  <input type="hidden" name="license_id"  value="<?= (int)$l['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Przypisania -->
  <div class="col-lg-7" id="assign">
    <?php if ($can_write): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-semibold"><i class="bi bi-person-plus me-2 text-success"></i>Przypisz licencję kursantowi</div>
      <div class="card-body">
        <?php $active_licenses = array_filter($licenses, fn($l)=>$l['is_active']); ?>
        <?php if (!$active_licenses): ?>
        <div class="text-muted small">Najpierw dodaj aktywne oprogramowanie do katalogu.</div>
        <?php elseif (!$ti_clients): ?>
        <div class="text-muted small">Brak kursantów TI (zapisanych na kurs lub z kontem panelu).</div>
        <?php else: ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"   value="assign">
          <div class="row g-2 mb-2">
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Oprogramowanie <span class="text-danger">*</span></label>
              <select class="form-select" name="license_id" required>
                <option value="">— wybierz —</option>
                <?php foreach ($active_licenses as $l): ?>
                <option value="<?= (int)$l['id'] ?>"><?= h($l['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Kursant <span class="text-danger">*</span></label>
              <select class="form-select" name="client_id" required>
                <option value="">— wybierz —</option>
                <?php foreach ($ti_clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-sm-4">
              <label class="form-label">Login / konto</label>
              <input type="text" class="form-control font-monospace" name="login" placeholder="np. e-mail konta">
            </div>
            <div class="col-sm-4">
              <label class="form-label">Klucz / hasło</label>
              <input type="text" class="form-control font-monospace" name="access_key" placeholder="klucz licencyjny">
            </div>
            <div class="col-sm-4">
              <label class="form-label">Ważne do <span class="text-muted small">(opc.)</span></label>
              <input type="date" class="form-control" name="expires_at" min="<?= date('Y-m-d') ?>">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">Notatka <span class="text-muted small">(opc.)</span></label>
            <input type="text" class="form-control" name="notes" placeholder="np. zakres, ograniczenia">
          </div>
          <button type="submit" class="btn btn-success"><i class="bi bi-person-plus me-1"></i>Przypisz</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-people me-2 text-primary"></i>Przypisane licencje <span class="badge bg-secondary ms-1"><?= count($assignments) ?></span></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
          <thead class="table-light"><tr><th>Kursant</th><th>Oprogramowanie</th><th>Login</th><th>Ważne do</th><?php if ($can_write): ?><th class="text-end">Akcje</th><?php endif; ?></tr></thead>
          <tbody>
            <?php if (!$assignments): ?>
            <tr><td colspan="<?= $can_write ? 5 : 4 ?>" class="text-center text-muted py-3">Brak przypisanych licencji.</td></tr>
            <?php endif; ?>
            <?php foreach ($assignments as $a):
              $expired = !empty($a['expires_at']) && $a['expires_at'] < $today; ?>
            <tr>
              <td class="fw-semibold"><?= h($a['client_name']) ?></td>
              <td><?= h($a['license_name']) ?></td>
              <td class="font-monospace small"><?= $a['login'] ? h($a['login']) : '<span class="text-muted">—</span>' ?></td>
              <td class="small">
                <?php if (!empty($a['expires_at'])): ?>
                  <span class="<?= $expired ? 'text-danger fw-semibold' : '' ?>"><?= h($a['expires_at']) ?><?= $expired ? ' (wygasło)' : '' ?></span>
                <?php else: ?><span class="text-muted">bezterminowo</span><?php endif; ?>
              </td>
              <?php if ($can_write): ?>
              <td class="text-end">
                <form method="post" class="d-inline" onsubmit="return confirm('Cofnąć przypisanie?')">
                  <input type="hidden" name="_csrf"          value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op"             value="revoke">
                  <input type="hidden" name="assignment_id"   value="<?= (int)$a['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2" title="Cofnij"><i class="bi bi-x-lg"></i></button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
