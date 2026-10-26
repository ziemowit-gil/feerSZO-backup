<?php
/**
 * contracts/szk/promocje.php — Promocje do umów SZK: lista i dodawanie/edycja reguł rabatowych TI (discount_rules z modules/ti_pricing).
 * Te same reguły działają w cennikach panelu dydaktyka; tu wybiera się je w umowie („Promocje i opłaty”).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractFees.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_szk', 'Ten typ umowy');
ti_pricing_migrate();
$cu = current_user();
$uid = (int)($cu['id'] ?? 0);
$by = (string)($cu['name'] ?? $cu['email'] ?? '');
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_edit()) { flash_set('error', 'Brak uprawnień.'); header('Location: promocje.php'); exit; }
    if (($_POST['act'] ?? '') === 'toggle') {
        $r = db_one("SELECT is_active FROM discount_rules WHERE id = ?", [(int)($_POST['id'] ?? 0)]);
        if ($r) { db()->prepare("UPDATE discount_rules SET is_active = ?, updated_at = datetime('now') WHERE id = ?")->execute([(int)$r['is_active'] ? 0 : 1, (int)$_POST['id']]); flash_set('success', 'Zmieniono aktywność promocji.'); }
        header('Location: promocje.php'); exit;
    }
    $res = ti_pricing_save_rule($_POST + ['is_active' => !empty($_POST['is_active']) ? 1 : 0], $by, $uid);
    if (is_string($res)) $err = $res;
    else { flash_set('success', 'Zapisano promocję.'); header('Location: promocje.php'); exit; }
}
$editId = (int)($_GET['edytuj'] ?? $_POST['id'] ?? 0);
$form = $err !== '' ? $_POST : ($editId ? (db_one("SELECT * FROM discount_rules WHERE id = ?", [$editId]) ?: []) : []);
$showForm = $err !== '' || $editId > 0 || isset($_GET['nowa']);
$types = db_all("SELECT id, name FROM lesson_types WHERE is_active = 1 ORDER BY name");
$rules = db_all("SELECT r.*, t.name AS type_name FROM discount_rules r LEFT JOIN lesson_types t ON t.id = r.target_lesson_type_id ORDER BY r.is_active DESC, r.priority, r.id");
$used = [];
foreach (db_all("SELECT promocja_id, COUNT(*) c FROM " . TiTrainingContractStore::TABLE . " WHERE promocja_id IS NOT NULL GROUP BY promocja_id") as $u) $used[(int)$u['promocja_id']] = (int)$u['c'];
$f = fn(string $k, $d = '') => $form[$k] ?? $d;
$PAGE_TITLE = 'Promocje — umowy szkoleniowe';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-percent text-primary"></i> Promocje do umów szkoleniowych</h4>
  <?php if (can_edit()): ?><a class="btn btn-primary" href="?nowa=1"><i class="bi bi-plus-lg"></i> Dodaj promocję</a><?php endif; ?>
</div>
<?php if ($err !== ''): ?><div class="alert alert-danger" role="alert"><?= h($err) ?></div><?php endif; ?>

<?php if ($showForm && can_edit()): ?>
<form method="post" class="card shadow-sm mb-4"><div class="card-body">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$editId ?>">
  <h5 class="h6 fw-bold"><?= $editId ? 'Edycja promocji' : 'Nowa promocja' ?></h5>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="p-name">Nazwa</label><input class="form-control" id="p-name" name="name" required maxlength="160" value="<?= h($f('name')) ?>"></div>
    <div class="col-md-3"><label class="form-label" for="p-dt">Rodzaj rabatu</label>
      <select class="form-select" id="p-dt" name="discount_type"><option value="percentage" <?= $f('discount_type', 'percentage') === 'percentage' ? 'selected' : '' ?>>Procent (%)</option><option value="fixed" <?= $f('discount_type') === 'fixed' ? 'selected' : '' ?>>Kwota (zł)</option></select></div>
    <div class="col-md-3"><label class="form-label" for="p-dv">Wartość</label><input class="form-control" id="p-dv" name="discount_value" required inputmode="decimal" value="<?= h($f('discount_value')) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="p-lt">Dotyczy typu zajęć</label>
      <select class="form-select" id="p-lt" name="target_lesson_type_id"><option value="">— wszystkie —</option><?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$f('target_lesson_type_id', 0) === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label" for="p-ct">Warunek</label>
      <select class="form-select" id="p-ct" name="condition_type"><?php foreach (TI_PRICING_CONDITIONS as $k => $l): ?><option value="<?= h($k) ?>" <?= $f('condition_type', 'none') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label" for="p-cv">Wartość warunku</label><input class="form-control" id="p-cv" name="condition_value" value="<?= h($f('condition_value')) ?>" aria-describedby="p-cv-h"><div class="form-text" id="p-cv-h">Statusy po przecinku / liczba dni / min. liczba grup / min. nadpłata.</div></div>
    <div class="col-md-3"><label class="form-label" for="p-df">Obowiązuje od</label><input class="form-control" id="p-df" type="date" name="date_from" value="<?= h(substr((string)$f('date_from'), 0, 10)) ?>"></div>
    <div class="col-md-3"><label class="form-label" for="p-dtt">do</label><input class="form-control" id="p-dtt" type="date" name="date_to" value="<?= h(substr((string)$f('date_to'), 0, 10)) ?>"></div>
    <div class="col-md-2"><label class="form-label" for="p-pr">Priorytet</label><input class="form-control" id="p-pr" type="number" name="priority" min="0" max="9999" value="<?= (int)$f('priority', 100) ?>"></div>
    <div class="col-md-4 d-flex align-items-end gap-3">
      <div class="form-check"><input class="form-check-input" type="checkbox" id="p-st" name="stackable" value="1" <?= (int)$f('stackable', 1) ? 'checked' : '' ?>><label class="form-check-label" for="p-st">Łączy się z innymi</label></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="p-ac" name="is_active" value="1" <?= (int)$f('is_active', 1) ? 'checked' : '' ?>><label class="form-check-label" for="p-ac">Aktywna</label></div></div>
  </div>
  <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Zapisz promocję</button> <a class="btn btn-link" href="promocje.php">Anuluj</a></div>
</div></form>
<?php endif; ?>

<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <caption class="visually-hidden">Promocje</caption>
  <thead class="table-light"><tr><th scope="col">Promocja</th><th scope="col">Typ zajęć</th><th scope="col">Warunek</th><th scope="col">Okres</th><th scope="col">Umowy</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
  <tbody>
  <?php if (!$rules): ?><tr><td colspan="7" class="text-center text-muted py-4">Brak promocji — dodaj pierwszą.</td></tr><?php endif; ?>
  <?php foreach ($rules as $r): ?>
    <tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>">
      <td><strong><?= h(TiSzkFees::promotionLabel($r)) ?></strong><div class="small text-muted">priorytet <?= (int)$r['priority'] ?><?= (int)$r['stackable'] ? '' : ' · nie łączy się' ?></div></td>
      <td class="small"><?= h($r['type_name'] ?? 'wszystkie') ?></td>
      <td class="small"><?= h(TI_PRICING_CONDITIONS[$r['condition_type']] ?? $r['condition_type']) ?><?= $r['condition_value'] ? ': ' . h($r['condition_value']) : '' ?></td>
      <td class="small text-nowrap"><?= $r['date_from'] ? h(date('d.m.Y', strtotime($r['date_from']))) : '…' ?> – <?= $r['date_to'] ? h(date('d.m.Y', strtotime($r['date_to']))) : '…' ?></td>
      <td class="small"><?= (int)($used[(int)$r['id']] ?? 0) ?></td>
      <td><?= (int)$r['is_active'] ? '<span class="badge text-bg-success">aktywna</span>' : '<span class="badge text-bg-secondary">wyłączona</span>' ?></td>
      <td class="text-end text-nowrap"><?php if (can_edit()): ?>
        <a class="btn btn-outline-primary btn-sm" href="?edytuj=<?= (int)$r['id'] ?>" aria-label="Edytuj: <?= h($r['name']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
        <form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-outline-secondary btn-sm"><?= (int)$r['is_active'] ? 'Wyłącz' : 'Włącz' ?></button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<p class="small text-muted mt-2">Promocję wybiera się w karcie umowy (Promocje i opłaty). Te same reguły liczą ceny zapisów w cennikach panelu dydaktyka.</p>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
