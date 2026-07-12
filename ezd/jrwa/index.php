<?php
/**
 * Wykaz akt (JRWA) — Jednolity Rzeczowy Wykaz Akt.
 * Przegląd dla wszystkich z dostępem; dodawanie/edycja/usuwanie — administrator.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$user_id = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!is_admin()) { http_response_code(403); exit; }
    $action = $_POST['_action'] ?? '';
    try {
        if ($action === 'save') {
            $eid = (int)($_POST['id'] ?? 0);
            $d = [
                'symbol'      => $_POST['symbol'] ?? '',
                'title'       => $_POST['title'] ?? '',
                'kat_arch'    => $_POST['kat_arch'] ?? 'B10',
                'description' => $_POST['description'] ?? '',
                'sort_order'  => $_POST['sort_order'] ?? 0,
            ];
            if (!trim($d['symbol']) || !trim($d['title'])) throw new \RuntimeException('Symbol i hasło klasyfikacyjne są wymagane.');
            if ($eid) { ezd_jrwa_update($eid, $d, $user_id); flash_set('success','Zaktualizowano hasło JRWA.'); }
            else      { ezd_jrwa_create($d, $user_id);       flash_set('success','Dodano hasło JRWA.'); }
        } elseif ($action === 'delete') {
            ezd_jrwa_delete((int)($_POST['id'] ?? 0), $user_id);
            flash_set('success','Usunięto hasło JRWA.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location:'.APP_URL.'/ezd/jrwa/index.php'); exit;
}

$jrwa = ezd_jrwa_all();
$edit = null;
if (!empty($_GET['edit'])) $edit = ezd_jrwa_get((int)$_GET['edit']);
$PAGE_TITLE = 'Wykaz akt (JRWA)';

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item active">Wykaz akt (JRWA)</li>
</ol></nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-tags text-primary me-2"></i>Wykaz akt (JRWA)</h4>
    <div class="text-muted" style="font-size:.8rem;margin-top:.15rem">Jednolity Rzeczowy Wykaz Akt — klasyfikacja i kwalifikacja archiwalna</div>
  </div>
  <?php if (is_admin()): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#jrwa-form">
    <i class="bi bi-plus-lg me-1"></i><?= $edit ? 'Edytuj hasło' : 'Dodaj hasło' ?>
  </button>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if (is_admin()): ?>
<div class="collapse <?= $edit ? 'show' : '' ?>" id="jrwa-form">
  <div class="card shadow-sm mb-3 border-primary">
    <div class="card-header bg-primary bg-opacity-10 py-2"><span class="fw-semibold text-primary" style="font-size:.84rem">
      <i class="bi bi-<?= $edit ? 'pencil' : 'plus-circle' ?> me-1"></i><?= $edit ? 'Edycja: '.h($edit['symbol']) : 'Nowe hasło klasyfikacyjne' ?>
    </span></div>
    <div class="card-body">
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
        <div class="col-6 col-md-2">
          <label class="form-label mb-1" style="font-size:.74rem">Symbol <span class="text-danger">*</span></label>
          <input type="text" name="symbol" class="form-control form-control-sm font-monospace text-uppercase" value="<?= h($edit['symbol'] ?? '') ?>" placeholder="np. KOR" required>
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label mb-1" style="font-size:.74rem">Hasło klasyfikacyjne <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control form-control-sm" value="<?= h($edit['title'] ?? '') ?>" placeholder="np. Korespondencja ogólna" required>
        </div>
        <div class="col-4 col-md-2">
          <label class="form-label mb-1" style="font-size:.74rem">Kat. arch.</label>
          <select name="kat_arch" class="form-select form-select-sm">
            <?php foreach(EZD_KAT_ARCH as $ka): ?>
            <option value="<?= $ka ?>" <?= ($edit['kat_arch'] ?? 'B10')===$ka?'selected':'' ?>><?= $ka ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-4 col-md-2">
          <label class="form-label mb-1" style="font-size:.74rem">Kolejność</label>
          <input type="number" name="sort_order" class="form-control form-control-sm" value="<?= (int)($edit['sort_order'] ?? 0) ?>">
        </div>
        <div class="col-4 col-md-2">
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
        </div>
        <div class="col-12">
          <label class="form-label mb-1" style="font-size:.74rem">Opis / zakres</label>
          <input type="text" name="description" class="form-control form-control-sm" value="<?= h($edit['description'] ?? '') ?>" placeholder="Czego dotyczy ta kategoria akt">
        </div>
        <?php if($edit): ?>
        <div class="col-12"><a href="<?= APP_URL ?>/ezd/jrwa/index.php" class="text-muted" style="font-size:.78rem">Anuluj edycję</a></div>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.83rem">
      <thead class="table-light">
        <tr>
          <th style="width:90px">Symbol</th>
          <th>Hasło klasyfikacyjne</th>
          <th style="width:90px" class="text-center">Kat. arch.</th>
          <th>Opis / zakres</th>
          <th style="width:80px" class="text-center">Segregatory</th>
          <?php if(is_admin()): ?><th style="width:90px"></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($jrwa as $j): ?>
        <tr>
          <td class="font-monospace fw-bold text-primary"><?= h($j['symbol']) ?></td>
          <td class="fw-semibold"><?= h($j['title']) ?></td>
          <td class="text-center"><span class="badge bg-light text-dark border"><?= h($j['kat_arch']) ?></span></td>
          <td class="text-muted" style="font-size:.78rem"><?= h($j['description'] ?: '—') ?></td>
          <td class="text-center">
            <?php if($j['teczki_count']): ?>
            <a href="<?= APP_URL ?>/ezd/teczki/index.php?status=all" class="badge bg-secondary bg-opacity-15 text-secondary border border-secondary text-decoration-none"><?= (int)$j['teczki_count'] ?></a>
            <?php else: ?><span class="text-muted">0</span><?php endif; ?>
          </td>
          <?php if(is_admin()): ?>
          <td class="text-end">
            <a href="<?= APP_URL ?>/admin/ezd_workflows.php?jrwa_id=<?= $j['id'] ?>" class="btn btn-xs btn-outline-info btn-sm" title="Proces / workflow"><i class="bi bi-diagram-2"></i></a>
            <a href="<?= APP_URL ?>/ezd/jrwa/index.php?edit=<?= $j['id'] ?>#jrwa-form" class="btn btn-xs btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i></a>
            <?php if(!$j['teczki_count']): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć hasło <?= h($j['symbol']) ?> z wykazu akt?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= $j['id'] ?>">
              <button class="btn btn-xs btn-outline-danger btn-sm"><i class="bi bi-trash3"></i></button>
            </form>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if(!$jrwa): ?>
        <tr><td colspan="<?= is_admin()?6:5 ?>" class="text-center text-muted py-5">
          <i class="bi bi-tags" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
          Wykaz akt jest pusty.
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="text-muted mt-2" style="font-size:.74rem">
  Kategorie archiwalne: <strong>A</strong> — materiały archiwalne (wieczyste); <strong>B</strong><em>n</em> — dokumentacja niearchiwalna przechowywana <em>n</em> lat; <strong>Bc</strong> — dokumentacja o krótkotrwałym znaczeniu; <strong>BE</strong><em>n</em> — podlegająca ekspertyzie archiwum państwowego.
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
