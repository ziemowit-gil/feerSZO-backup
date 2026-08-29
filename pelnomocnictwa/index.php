<?php
/**
 * Rejestr pełnomocnictw — samodzielny rejestr w SZO (inspirowany klasą JRWA 013 z EZD).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/pelnomocnictwa.php';

require_login();
require_module_enabled('pelnomocnictwa_enabled', 'Rejestr pełnomocnictw');
if (!can_edit()) { flash_set('error', 'Brak uprawnień do rejestru pełnomocnictw.'); header('Location:'.APP_URL.'/index.php'); exit; }

$user_id = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            pelnomocnictwo_save($id, $_POST, $user_id);
            flash_set('success', $id ? 'Zaktualizowano pełnomocnictwo.' : 'Dodano pełnomocnictwo do rejestru.');
            header('Location:'.APP_URL.'/pelnomocnictwa/index.php'); exit;
        }
        if ($action === 'delete' && is_admin()) {
            pelnomocnictwo_delete((int)($_POST['id'] ?? 0));
            flash_set('success', 'Wpis usunięty.');
            header('Location:'.APP_URL.'/pelnomocnictwa/index.php'); exit;
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location:'.APP_URL.'/pelnomocnictwa/index.php'); exit;
}

$q      = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$rok    = (int)($_GET['rok'] ?? 0);
$rows   = pelnomocnictwa_all(['q' => $q, 'status' => $status, 'rok' => $rok ?: null]);
$edit   = !empty($_GET['edit']) ? pelnomocnictwo_get((int)$_GET['edit']) : null;
$stats  = pelnomocnictwa_stats();
$PAGE_TITLE = 'Rejestr pełnomocnictw';

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-person-badge text-primary me-2"></i>Rejestr pełnomocnictw</h4>
    <div class="text-muted" style="font-size:.8rem;margin-top:.15rem">Mocodawca, pełnomocnik, zakres umocowania i okres ważności — inspirowany klasą JRWA 013</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <span class="badge bg-success bg-opacity-15 text-success border border-success" style="font-size:.72rem"><?= $stats['wazne'] ?> ważnych</span>
    <span class="badge bg-danger bg-opacity-15 text-danger border border-danger" style="font-size:.72rem"><?= $stats['wygasle'] ?> wygasłych</span>
    <span class="badge bg-secondary bg-opacity-15 text-secondary border border-secondary" style="font-size:.72rem"><?= $stats['odwolane'] ?> odwołanych</span>
    <a href="#form-peln" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowe pełnomocnictwo</a>
  </div>
</div>

<?= flash_html() ?>

<!-- Formularz dodawania / edycji -->
<div class="card shadow-sm mb-3 <?= $edit ? 'border-primary' : '' ?>" id="form-peln">
  <div class="card-header py-2 <?= $edit ? 'bg-primary bg-opacity-10' : '' ?>">
    <span class="fw-semibold <?= $edit ? 'text-primary' : '' ?>" style="font-size:.84rem">
      <i class="bi bi-<?= $edit ? 'pencil' : 'plus-lg' ?> me-1"></i><?= $edit ? 'Edycja: '.h($edit['numer']) : 'Nowe pełnomocnictwo' ?>
    </span>
  </div>
  <div class="card-body">
    <form method="post" class="row g-2">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="save">
      <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Numer</label>
        <input type="text" name="numer" class="form-control form-control-sm" value="<?= h($edit['numer'] ?? '') ?>" placeholder="<?= h(pelnomocnictwa_suggest_numer()) ?> (auto)"></div>
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Forma</label>
        <input type="text" name="forma" class="form-control form-control-sm" value="<?= h($edit['forma'] ?? '') ?>" placeholder="pisemne / notarialne / elektroniczne"></div>
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Data udzielenia</label>
        <input type="date" name="data_udzielenia" class="form-control form-control-sm" value="<?= h($edit['data_udzielenia'] ?? date('Y-m-d')) ?>"></div>
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Ważne do <span class="text-muted">(puste = bezterminowe)</span></label>
        <input type="date" name="data_waznosci" class="form-control form-control-sm" value="<?= h($edit['data_waznosci'] ?? '') ?>"></div>

      <div class="col-md-6"><label class="form-label mb-1" style="font-size:.74rem">Mocodawca <span class="text-danger">*</span></label>
        <input type="text" name="mocodawca" class="form-control form-control-sm" value="<?= h($edit['mocodawca'] ?? '') ?>" required placeholder="kto udziela pełnomocnictwa"></div>
      <div class="col-md-6"><label class="form-label mb-1" style="font-size:.74rem">Pełnomocnik <span class="text-danger">*</span></label>
        <input type="text" name="pelnomocnik" class="form-control form-control-sm" value="<?= h($edit['pelnomocnik'] ?? '') ?>" required placeholder="komu udzielono pełnomocnictwa"></div>

      <div class="col-12"><label class="form-label mb-1" style="font-size:.74rem">Zakres umocowania</label>
        <textarea name="zakres" class="form-control form-control-sm" rows="2"><?= h($edit['zakres'] ?? '') ?></textarea></div>

      <div class="col-md-4"><label class="form-label mb-1" style="font-size:.74rem">Data odwołania <span class="text-muted">(jeśli odwołane)</span></label>
        <input type="date" name="data_odwolania" class="form-control form-control-sm" value="<?= h($edit['data_odwolania'] ?? '') ?>"></div>
      <div class="col-md-8"><label class="form-label mb-1" style="font-size:.74rem">Uwagi</label>
        <input type="text" name="uwagi" class="form-control form-control-sm" value="<?= h($edit['uwagi'] ?? '') ?>"></div>

      <div class="col-12 d-flex gap-2 mt-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i><?= $edit ? 'Zapisz zmiany' : 'Dodaj' ?></button>
        <?php if($edit): ?><a href="<?= APP_URL ?>/pelnomocnictwa/index.php" class="btn btn-outline-secondary btn-sm">Anuluj</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Szukaj i filtruj -->
<form method="get" class="mb-3 d-flex flex-wrap gap-2 align-items-end">
  <div>
    <label class="form-label mb-1" style="font-size:.72rem">Szukaj</label>
    <input type="text" name="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="numer, mocodawca, pełnomocnik, zakres…" style="min-width:260px">
  </div>
  <div>
    <label class="form-label mb-1" style="font-size:.72rem">Status</label>
    <select name="status" class="form-select form-select-sm">
      <option value="">wszystkie</option>
      <?php foreach (pelnomocnictwa_statuses() as $k=>$lbl): ?>
      <option value="<?= $k ?>" <?= $status===$k?'selected':'' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label mb-1" style="font-size:.72rem">Rok</label>
    <input type="number" name="rok" class="form-control form-control-sm" value="<?= $rok ?: '' ?>" style="width:100px" placeholder="rok">
  </div>
  <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-search"></i> Filtruj</button>
  <?php if($q || $status || $rok): ?><a href="<?= APP_URL ?>/pelnomocnictwa/index.php" class="btn btn-outline-secondary btn-sm">Wyczyść</a><?php endif; ?>
</form>

<!-- Lista -->
<div class="card shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-person-badge me-1 text-primary"></i>Pełnomocnictwa (<?= count($rows) ?>)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.83rem">
      <thead class="table-light">
        <tr>
          <th>Numer</th>
          <th>Mocodawca</th>
          <th>Pełnomocnik</th>
          <th>Zakres</th>
          <th class="text-nowrap">Ważność</th>
          <th>Status</th>
          <th style="width:110px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): [$label,$color] = pelnomocnictwo_status_label(pelnomocnictwo_status($r)); ?>
        <tr>
          <td class="fw-semibold text-nowrap"><?= h($r['numer']) ?></td>
          <td><?= h($r['mocodawca']) ?></td>
          <td><?= h($r['pelnomocnik']) ?></td>
          <td><?= h(mb_substr($r['zakres'] ?: '—', 0, 70)) ?></td>
          <td class="text-nowrap text-muted" style="font-size:.78rem">
            <?= $r['data_udzielenia'] ? date_pl($r['data_udzielenia']) : '—' ?> – <?= $r['data_waznosci'] ? date_pl($r['data_waznosci']) : 'bezterminowo' ?>
          </td>
          <td><span class="badge bg-<?= $color ?> bg-opacity-15 text-<?= $color ?> border border-<?= $color ?>" style="font-size:.72rem"><?= h($label) ?></span></td>
          <td class="text-end text-nowrap">
            <a href="<?= APP_URL ?>/pelnomocnictwa/print.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm" title="Wydruk" target="_blank"><i class="bi bi-printer"></i></a>
            <a href="?edit=<?= $r['id'] ?>#form-peln" class="btn btn-xs btn-outline-secondary btn-sm" title="Edytuj"><i class="bi bi-pencil"></i></a>
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
        <tr><td colspan="7" class="text-center text-muted py-5">
          <i class="bi bi-person-badge" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
          Rejestr jest pusty<?= ($q||$status||$rok) ? ' dla tego filtra' : '' ?>.
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
