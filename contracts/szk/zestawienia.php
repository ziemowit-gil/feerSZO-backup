<?php
/**
 * contracts/szk/zestawienia.php — podmenu Umowy SZK: Zmiany w umowach, Umowy z nadpłatami, Lista zapisów, Lista wypowiedzeń,
 * Notatki do umów, Płatnicy (?v=zmiany|nadplaty|zapisy|wypowiedzenia|notatki|platnicy). Logika: modules/ti_training_contract.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractReports.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_szk', 'Ten typ umowy');
$view = array_key_exists((string)($_GET['v'] ?? ''), TiSzkReports::VIEWS) ? (string)$_GET['v'] : 'zmiany';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cu = current_user();
    $by = (string)($cu['name'] ?? $cu['email'] ?? '');
    $cid = (int)($_POST['contract_id'] ?? 0);
    $back = ($_POST['back'] ?? '') === 'view' && $cid ? APP_URL . '/contracts/szk/view.php?id=' . $cid . '#szk-notes' : 'zestawienia.php?v=notatki';
    if (!can_edit()) { flash_set('error', 'Brak uprawnień do edycji umów.'); header('Location: ' . $back); exit; }
    $c = $cid ? TiTrainingContractStore::get($cid) : null;
    if ($c && !contract_can_access('szk', $c['_row'])) { flash_set('error', 'Brak dostępu do umowy.'); header('Location: ' . $back); exit; }
    if (($_POST['act'] ?? '') === 'note_add') {
        $err = TiSzkReports::addNote($cid, (string)($_POST['body'] ?? ''), $by, !empty($_POST['pinned']));
        $err !== '' ? flash_set('error', $err) : flash_set('success', 'Dodano notatkę.');
    } elseif (($_POST['act'] ?? '') === 'note_del') {
        $n = db_one("SELECT * FROM szk_notes WHERE id = ?", [(int)($_POST['note'] ?? 0)]);
        if ($n && ((string)$n['author'] === $by || is_admin())) { TiSzkReports::deleteNote((int)$n['id']); flash_set('success', 'Usunięto notatkę.'); }
        else flash_set('error', 'Notatkę może usunąć jej autor lub administrator.');
    }
    header('Location: ' . $back); exit;
}

[$title, $icon, $desc] = TiSzkReports::VIEWS[$view];
$data = TiSzkReports::build($view);
$contracts = $view === 'notatki' && can_edit() ? db_all("SELECT id, numer_umowy, imie_nazwisko FROM " . TiTrainingContractStore::TABLE . " WHERE is_test = 0 ORDER BY id DESC LIMIT 500") : [];
$PAGE_TITLE = $title . ' — umowy szkoleniowe';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<h4 class="mb-1"><i class="bi <?= h($icon) ?> text-primary"></i> <?= h($title) ?> <span class="badge bg-secondary ms-1"><?= count($data['rows']) ?></span></h4>
<p class="text-muted small mb-3"><?= h($desc) ?></p>
<ul class="nav nav-pills mb-3 flex-wrap" aria-label="Zestawienia umów szkoleniowych">
  <?php foreach (TiSzkReports::VIEWS as $k => [$l]): ?><li class="nav-item"><a class="nav-link py-1 <?= $view === $k ? 'active' : '' ?>" <?= $view === $k ? 'aria-current="page"' : '' ?> href="?v=<?= h($k) ?>"><?= h($l) ?></a></li><?php endforeach; ?>
  <li class="nav-item ms-md-auto"><a class="nav-link py-1" href="promocje.php"><i class="bi bi-percent" aria-hidden="true"></i> Promocje</a></li>
  <?php if (is_admin()): ?><li class="nav-item"><a class="nav-link py-1" href="ustawienia.php"><i class="bi bi-gear" aria-hidden="true"></i> Ustawienia</a></li><?php endif; ?>
</ul>
<?php if ($data['note'] !== ''): ?><div class="alert alert-warning"><?= $data['note'] ?></div><?php endif; ?>

<?php if ($view === 'notatki' && can_edit()): ?>
<form method="post" class="card shadow-sm mb-3"><div class="card-body row g-2 align-items-end">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="act" value="note_add">
  <div class="col-md-4"><label class="form-label small mb-1" for="n-c">Umowa</label>
    <select class="form-select form-select-sm" id="n-c" name="contract_id" required><option value="">— wybierz —</option>
      <?php foreach ($contracts as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h(($c['numer_umowy'] ?: 'projekt #' . $c['id']) . ' · ' . $c['imie_nazwisko']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md"><label class="form-label small mb-1" for="n-b">Notatka</label><textarea class="form-control form-control-sm" id="n-b" name="body" rows="2" maxlength="4000" required></textarea></div>
  <div class="col-auto"><div class="form-check"><input class="form-check-input" type="checkbox" id="n-p" name="pinned" value="1"><label class="form-check-label small" for="n-p">Przypnij</label></div></div>
  <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Dodaj</button></div>
</div></form>
<?php endif; ?>

<div class="card shadow-sm"><div class="card-body p-2 border-bottom">
  <label class="visually-hidden" for="z-filter">Filtruj wiersze</label>
  <input id="z-filter" class="form-control form-control-sm" placeholder="Filtruj wiersze…" autocomplete="off"></div>
<div class="table-responsive"><table class="table table-hover table-sm align-middle mb-0" id="z-table">
  <caption class="visually-hidden"><?= h($title) ?></caption>
  <thead class="table-light"><tr><?php foreach ($data['cols'] as $c): ?><th scope="col"><?= h($c) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php if (!$data['rows']): ?><tr><td colspan="<?= count($data['cols']) ?>" class="text-center text-muted py-4">Brak danych w tym zestawieniu.</td></tr><?php endif; ?>
  <?php foreach ($data['rows'] as $r): ?><tr><?php foreach ($r as $cell): ?><td class="small"><?= $cell ?></td><?php endforeach; ?></tr><?php endforeach; ?>
  </tbody></table></div></div>
<script>document.getElementById('z-filter').addEventListener('input', function () {
  var q = this.value.toLowerCase();
  document.querySelectorAll('#z-table tbody tr').forEach(function (tr) { tr.hidden = q !== '' && tr.textContent.toLowerCase().indexOf(q) < 0; });
});</script>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
