<?php
/**
 * Rejestr byłych osób (byłych współpracowników).
 * Powód odejścia i uwagi mogą być wrażliwe — widoczne tylko dla zarządu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/byli.php';

require_login();
require_module_enabled('byli_enabled', 'Rejestr byłych osób');
if (!can_edit()) { flash_set('error', 'Brak uprawnień do rejestru byłych osób.'); header('Location:'.APP_URL.'/index.php'); exit; }

$user_id = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    try {
        if ($action === 'save') {
            $id  = (int)($_POST['id'] ?? 0);
            $existing = $id ? byli_get($id) : null;
            $can_sensitive = !$id ? true : (is_zarzad() || empty($existing['wrazliwe']));
            byli_save($id, $_POST, $user_id, $can_sensitive);
            flash_set('success', $id ? 'Zaktualizowano wpis.' : 'Dodano osobę do rejestru.');
            header('Location:'.APP_URL.'/byli/index.php'); exit;
        }
        if ($action === 'delete' && is_admin()) {
            byli_delete((int)($_POST['id'] ?? 0));
            flash_set('success', 'Wpis usunięty.');
            header('Location:'.APP_URL.'/byli/index.php'); exit;
        }
        if ($action === 'zarzad_save' && is_admin()) {
            zarzad_set_user_ids($_POST['zarzad'] ?? []);
            flash_set('success', 'Skład zarządu zaktualizowany.');
            header('Location:'.APP_URL.'/byli/index.php'); exit;
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location:'.APP_URL.'/byli/index.php'); exit;
}

$q    = trim($_GET['q'] ?? '');
$rows = byli_all($q);
$edit = !empty($_GET['edit']) ? byli_get((int)$_GET['edit']) : null;
$zarzad_ids = zarzad_user_ids();
// Czy w formularzu edycji pokazać pola wrażliwe
$edit_show_sensitive = !$edit ? true : (is_zarzad() || empty($edit['wrazliwe']));
$PAGE_TITLE = 'Rejestr byłych osób';

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-person-dash text-primary me-2"></i>Rejestr byłych osób</h4>
    <div class="text-muted" style="font-size:.8rem;margin-top:.15rem">Byli współpracownicy — dane podstawowe; powód odejścia i uwagi mogą być zastrzeżone dla zarządu</div>
  </div>
  <div class="d-flex gap-2">
    <?php if(is_zarzad()): ?>
    <span class="badge bg-success bg-opacity-15 text-success border border-success align-self-center"><i class="bi bi-shield-check me-1"></i>Dostęp zarządu</span>
    <?php endif; ?>
    <a href="#form-byly" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Dodaj osobę</a>
  </div>
</div>

<?= flash_html() ?>

<!-- Formularz dodawania / edycji -->
<div class="card shadow-sm mb-3 <?= $edit ? 'border-primary' : '' ?>" id="form-byly">
  <div class="card-header py-2 <?= $edit ? 'bg-primary bg-opacity-10' : '' ?>">
    <span class="fw-semibold <?= $edit ? 'text-primary' : '' ?>" style="font-size:.84rem">
      <i class="bi bi-<?= $edit ? 'pencil' : 'person-plus' ?> me-1"></i><?= $edit ? 'Edycja wpisu: '.h($edit['imie'].' '.$edit['nazwisko']) : 'Nowa osoba' ?>
    </span>
  </div>
  <div class="card-body">
    <form method="post" class="row g-2">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="save">
      <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Imię</label>
        <input type="text" name="imie" class="form-control form-control-sm" value="<?= h($edit['imie'] ?? '') ?>"></div>
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Nazwisko <span class="text-danger">*</span></label>
        <input type="text" name="nazwisko" class="form-control form-control-sm" value="<?= h($edit['nazwisko'] ?? '') ?>" required></div>
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Miasto</label>
        <input type="text" name="miasto" class="form-control form-control-sm" value="<?= h($edit['miasto'] ?? '') ?>"></div>
      <div class="col-md-3"></div>
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Współpraca od</label>
        <input type="date" name="data_od" class="form-control form-control-sm" value="<?= h($edit['data_od'] ?? '') ?>"></div>
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Współpraca do</label>
        <input type="date" name="data_do" class="form-control form-control-sm" value="<?= h($edit['data_do'] ?? '') ?>"></div>

      <?php if($edit_show_sensitive): ?>
      <div class="col-12 mt-3"><hr class="my-1"><div class="text-muted mb-1" style="font-size:.72rem"><i class="bi bi-shield-lock me-1"></i>Dane mogące być wrażliwe</div></div>
      <div class="col-md-6"><label class="form-label mb-1" style="font-size:.74rem">Powód odejścia</label>
        <input type="text" name="powod_odejscia" class="form-control form-control-sm" value="<?= h($edit['powod_odejscia'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label mb-1" style="font-size:.74rem">Uwagi</label>
        <input type="text" name="uwagi" class="form-control form-control-sm" value="<?= h($edit['uwagi'] ?? '') ?>"></div>
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" name="wrazliwe" id="wrazliwe" value="1" <?= !empty($edit['wrazliwe'])?'checked':'' ?>>
          <label class="form-check-label" for="wrazliwe" style="font-size:.82rem">Powód i uwagi wrażliwe — widoczne tylko dla zarządu</label>
        </div>
      </div>
      <?php else: ?>
      <div class="col-12 mt-2"><div class="alert alert-secondary py-2 mb-0" style="font-size:.8rem"><i class="bi bi-shield-lock me-1"></i>Powód odejścia i uwagi są oznaczone jako wrażliwe — edycja dostępna tylko dla zarządu.</div></div>
      <?php endif; ?>

      <div class="col-12 d-flex gap-2 mt-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i><?= $edit ? 'Zapisz zmiany' : 'Dodaj' ?></button>
        <?php if($edit): ?><a href="<?= APP_URL ?>/byli/index.php" class="btn btn-outline-secondary btn-sm">Anuluj</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Szukaj -->
<form method="get" class="mb-3" style="max-width:420px">
  <div class="input-group input-group-sm">
    <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Szukaj: imię, nazwisko, miasto…">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    <?php if($q): ?><a href="<?= APP_URL ?>/byli/index.php" class="btn btn-outline-secondary">×</a><?php endif; ?>
  </div>
</form>

<!-- Lista -->
<div class="card shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-people me-1 text-primary"></i>Byłe osoby (<?= count($rows) ?>)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.83rem">
      <thead class="table-light">
        <tr>
          <th>Imię i nazwisko</th>
          <th>Miasto</th>
          <th class="text-nowrap">Okres współpracy</th>
          <th>Powód odejścia</th>
          <th>Uwagi</th>
          <th style="width:80px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $see = byli_can_see_sensitive($r); ?>
        <tr>
          <td class="fw-semibold"><?= h(trim($r['imie'].' '.$r['nazwisko'])) ?>
            <?php if(!empty($r['wrazliwe'])): ?><i class="bi bi-shield-lock-fill text-warning ms-1" title="Dane wrażliwe — tylko zarząd" style="font-size:.72rem"></i><?php endif; ?>
          </td>
          <td><?= h($r['miasto'] ?: '—') ?></td>
          <td class="text-nowrap text-muted" style="font-size:.78rem">
            <?= $r['data_od'] ? date_pl($r['data_od']) : '—' ?> – <?= $r['data_do'] ? date_pl($r['data_do']) : '…' ?>
          </td>
          <td>
            <?php if($see): ?><?= h($r['powod_odejscia'] ?: '—') ?>
            <?php else: ?><span class="text-muted fst-italic"><i class="bi bi-lock me-1"></i>tylko zarząd</span><?php endif; ?>
          </td>
          <td>
            <?php if($see): ?><?= h(mb_substr($r['uwagi'] ?: '—', 0, 60)) ?>
            <?php else: ?><span class="text-muted fst-italic"><i class="bi bi-lock me-1"></i>tylko zarząd</span><?php endif; ?>
          </td>
          <td class="text-end">
            <a href="?edit=<?= $r['id'] ?>#form-byly" class="btn btn-xs btn-outline-secondary btn-sm" title="Edytuj"><i class="bi bi-pencil"></i></a>
            <?php if(is_admin()): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć wpis z rejestru?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn btn-xs btn-outline-danger btn-sm" title="Usuń"><i class="bi bi-trash3"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?>
        <tr><td colspan="6" class="text-center text-muted py-5">
          <i class="bi bi-person-dash" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
          Rejestr jest pusty<?= $q ? ' dla tego wyszukiwania' : '' ?>.
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if(is_admin()): ?>
<!-- Konfiguracja zarządu (dostęp do danych wrażliwych) -->
<div class="card shadow-sm mt-3">
  <div class="card-header py-2"><a class="text-decoration-none text-muted" data-bs-toggle="collapse" href="#zarzad-cfg" style="font-size:.82rem"><i class="bi bi-shield-lock me-1"></i>Skład zarządu — dostęp do danych wrażliwych</a></div>
  <div class="collapse" id="zarzad-cfg"><div class="card-body">
    <p class="text-muted" style="font-size:.8rem">Zaznacz osoby z dostępem do pól wrażliwych (powód odejścia, uwagi). Administratorzy mają dostęp zawsze.</p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="zarzad_save">
      <div class="row g-2">
        <?php foreach(db_all("SELECT id,name,role FROM users WHERE is_active=1 ORDER BY name") as $u): ?>
        <div class="col-md-4 col-lg-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="zarzad[]" id="z<?= $u['id'] ?>" value="<?= $u['id'] ?>" <?= in_array((int)$u['id'],$zarzad_ids,true)?'checked':'' ?> <?= $u['role']==='admin'?'disabled checked':'' ?>>
            <label class="form-check-label" for="z<?= $u['id'] ?>" style="font-size:.82rem"><?= h($u['name']) ?><?php if($u['role']==='admin'): ?> <span class="badge bg-secondary" style="font-size:.6rem">admin</span><?php endif; ?></label>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-sm btn-primary mt-3"><i class="bi bi-check-lg me-1"></i>Zapisz skład zarządu</button>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
