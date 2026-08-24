<?php
/**
 * crm/offers/catalog.php — Katalog usług odpłatnych (cennik ofertowy).
 *
 * Edycja cennika jest osobnym uprawnieniem (crm_offer_can_edit_catalog) —
 * handlowiec może wstawiać pozycje do oferty, ale nie zmieniać cen bazowych.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_offers.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('offers', 'read');
crm_offers_migrate();

$PAGE_TITLE = 'Katalog usług odpłatnych';
$can_edit   = crm_offer_can_edit_catalog();
$uid        = (int)(current_user()['id'] ?? 0);
$edit_id    = (int)($_GET['edit'] ?? 0);
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_edit) {
        flash_set('danger', 'Brak uprawnień do edycji cennika.');
        header('Location: catalog.php'); exit;
    }
    $a = (string)($_POST['a'] ?? 'save');

    if ($a === 'delete') {
        $del = (int)($_POST['id'] ?? 0);
        if ($del) {
            crm_update('crm_offer_catalog', ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')], $del);
            flash_set('success', 'Pozycja wyłączona z katalogu (istniejące oferty pozostają bez zmian).');
        }
        header('Location: catalog.php'); exit;
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $vat  = (string)($_POST['vat_rate'] ?? '23');
    if (!isset(CRM_OFFER_VAT_RATES[$vat])) $vat = '23';
    if ($name === '') $errors[] = 'Nazwa usługi jest wymagana.';
    if (in_array($vat, ['zw', 'np'], true) && trim((string)($_POST['vat_basis'] ?? '')) === '') {
        $errors[] = 'Dla stawki „zw." / „np." podaj podstawę prawną.';
    }

    if (!$errors) {
        $data = [
            'code'            => trim((string)($_POST['code'] ?? '')) ?: null,
            'name'            => $name,
            'description'     => trim((string)($_POST['description'] ?? '')) ?: null,
            'category'        => trim((string)($_POST['category'] ?? '')) ?: null,
            'unit'            => trim((string)($_POST['unit'] ?? 'szt.')) ?: 'szt.',
            'unit_net'        => max(0, (float)str_replace(',', '.', (string)($_POST['unit_net'] ?? 0))),
            'vat_rate'        => $vat,
            'vat_basis'       => trim((string)($_POST['vat_basis'] ?? '')) ?: null,
            'funding_source'  => isset(CRM_OFFER_FUNDING[$_POST['funding_source'] ?? '']) ? $_POST['funding_source'] : 'odplatna',
            'objective_id'    => (int)($_POST['objective_id'] ?? 0) ?: null,
            'accounting_note' => trim((string)($_POST['accounting_note'] ?? '')) ?: null,
            'min_unit_net'    => ($_POST['min_unit_net'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$_POST['min_unit_net']) : null,
            'is_active'       => !empty($_POST['is_active']) ? 1 : 0,
            'sort_order'      => (int)($_POST['sort_order'] ?? 0),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];
        $eid = (int)($_POST['id'] ?? 0);
        if ($eid) {
            crm_update('crm_offer_catalog', $data, $eid);
            flash_set('success', 'Pozycja katalogu zaktualizowana.');
        } else {
            $data['created_by'] = $uid;
            $data['created_at'] = date('Y-m-d H:i:s');
            crm_insert('crm_offer_catalog', $data);
            flash_set('success', 'Pozycja dodana do katalogu.');
        }
        header('Location: catalog.php'); exit;
    }
}

$rows = crm_offer_catalog_list(false);
$edit = $edit_id ? crm_one("SELECT * FROM crm_offer_catalog WHERE id=?", [$edit_id]) : null;
$objectives = crm_offer_objectives();
$f = static fn(string $k, $d = '') => $_POST[$k] ?? ($edit[$k] ?? $d);

include dirname(__DIR__) . '/includes/header_crm.php';
?>

<nav aria-label="breadcrumb" class="mb-2" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="index.php">Oferty</a></li>
    <li class="breadcrumb-item active">Katalog usług</li>
  </ol>
</nav>

<?php if (!crm_offers_available()): ?>
<div class="alert alert-danger" role="alert">
  <div class="fw-bold"><i class="bi bi-database-exclamation me-1"></i>Schemat modułu Oferty nie jest gotowy</div>
  <div style="font-size:.85rem">Nie udało się utworzyć / zaktualizować tabel modułu. Szczegóły:
    <code><?= h(crm_offers_last_error() ?: 'brak szczegółów — sprawdź log PHP') ?></code></div>
</div>
<?php endif; ?>

<div class="crm-page-header">
  <div>
    <div class="crm-page-title"><i class="bi bi-list-columns" style="color:#0176D3"></i> Katalog usług odpłatnych</div>
    <div class="crm-page-subtitle">Cennik bazowy wykorzystywany w kreatorze ofert</div>
  </div>
  <div class="crm-page-actions">
    <a href="index.php" class="btn btn-crm-ghost btn-sm"><i class="bi bi-arrow-left me-1"></i>Oferty</a>
  </div>
</div>

<?php if (!$can_edit): ?>
<div class="alert alert-secondary py-2" style="font-size:.85rem">
  <i class="bi bi-lock me-1"></i>Masz dostęp tylko do odczytu — edycja cennika wymaga uprawnienia
  (Ustawienia CRM → Oferty → „Kto edytuje cennik").
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<div class="row g-3">
<div class="col-lg-<?= $can_edit ? '7' : '12' ?>">
  <div class="card shadow-sm">
    <div class="table-responsive">
    <table class="table table-sm mb-0" style="font-size:.85rem">
      <thead style="background:#F9FAFB"><tr>
        <th>Usługa</th><th>Kategoria</th><th class="text-end">Cena netto</th><th>VAT</th><th>J.m.</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
      <tr <?= (int)$r['is_active'] === 0 ? 'class="text-muted" style="opacity:.6"' : '' ?>>
        <td>
          <strong><?= h($r['name']) ?></strong>
          <?= $r['code'] ? ' <span class="text-muted" style="font-family:monospace;font-size:.75rem">' . h($r['code']) . '</span>' : '' ?>
          <?php if ((int)$r['is_active'] === 0): ?><span class="badge bg-secondary ms-1">nieaktywna</span><?php endif; ?>
          <?php if ($r['description']): ?><div class="text-muted" style="font-size:.76rem"><?= h(mb_substr((string)$r['description'], 0, 120)) ?></div><?php endif; ?>
          <?php if ($r['objective_id']): ?>
          <div style="font-size:.72rem;color:#2E844A"><i class="bi bi-bullseye"></i> <?= h(crm_offer_objective_label((int)$r['objective_id'])) ?></div>
          <?php endif; ?>
        </td>
        <td><?= h($r['category'] ?: '—') ?></td>
        <td class="text-end"><?= h(number_format((float)$r['unit_net'], 2, ',', ' ')) ?></td>
        <td><?= h(CRM_OFFER_VAT_RATES[$r['vat_rate']]['label'] ?? $r['vat_rate']) ?></td>
        <td><?= h($r['unit']) ?></td>
        <td class="text-end" style="white-space:nowrap">
          <?php if ($can_edit): ?>
          <a href="catalog.php?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-link p-0 me-2" title="Edytuj"><i class="bi bi-pencil"></i></a>
          <?php if ((int)$r['is_active'] === 1): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Wyłączyć pozycję z katalogu?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-link text-danger p-0" title="Wyłącz"><i class="bi bi-slash-circle"></i></button>
          </form>
          <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
      <tr><td colspan="6" class="text-center text-muted py-4">Katalog jest pusty — dodaj pierwszą usługę.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<?php if ($can_edit): ?>
<div class="col-lg-5">
  <form method="post" class="card shadow-sm">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="a" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="card-header py-2 fw-semibold" style="font-size:.9rem">
      <i class="bi bi-<?= $edit ? 'pencil' : 'plus-lg' ?> me-1"></i><?= $edit ? 'Edycja pozycji' : 'Nowa pozycja katalogu' ?>
      <?php if ($edit): ?><a href="catalog.php" class="float-end small">anuluj</a><?php endif; ?>
    </div>
    <div class="card-body">
      <div class="row g-2">
        <div class="col-8">
          <label class="form-label small fw-semibold mb-1">Nazwa <span class="text-danger">*</span></label>
          <input name="name" class="form-control form-control-sm" required value="<?= h($f('name')) ?>">
        </div>
        <div class="col-4">
          <label class="form-label small fw-semibold mb-1">Kod</label>
          <input name="code" class="form-control form-control-sm" value="<?= h($f('code')) ?>" placeholder="SZK-01">
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold mb-1">Opis (trafia do oferty)</label>
          <textarea name="description" class="form-control form-control-sm" rows="2"><?= h($f('description')) ?></textarea>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold mb-1">Kategoria</label>
          <input name="category" class="form-control form-control-sm" value="<?= h($f('category')) ?>"
                 placeholder="Szkolenia / Doradztwo / Ekspertyzy" list="catList">
          <datalist id="catList">
            <?php foreach (array_unique(array_filter(array_column($rows, 'category'))) as $cc): ?>
            <option value="<?= h($cc) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="col-3">
          <label class="form-label small fw-semibold mb-1">J.m.</label>
          <input name="unit" class="form-control form-control-sm" value="<?= h($f('unit', 'szt.')) ?>">
        </div>
        <div class="col-3">
          <label class="form-label small fw-semibold mb-1">Kolejność</label>
          <input type="number" name="sort_order" class="form-control form-control-sm" value="<?= h($f('sort_order', '0')) ?>">
        </div>
        <div class="col-4">
          <label class="form-label small fw-semibold mb-1">Cena netto</label>
          <input name="unit_net" class="form-control form-control-sm" value="<?= h($f('unit_net', '0')) ?>">
        </div>
        <div class="col-4">
          <label class="form-label small fw-semibold mb-1">Cena minimalna</label>
          <input name="min_unit_net" class="form-control form-control-sm" value="<?= h($f('min_unit_net')) ?>"
                 placeholder="opcjonalnie">
        </div>
        <div class="col-4">
          <label class="form-label small fw-semibold mb-1">VAT</label>
          <select name="vat_rate" class="form-select form-select-sm">
            <?php foreach (CRM_OFFER_VAT_RATES as $vk => $vv): ?>
            <option value="<?= h($vk) ?>" <?= (string)$f('vat_rate', '23') === $vk ? 'selected' : '' ?>><?= h($vv['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold mb-1">Podstawa zwolnienia / niepodlegania VAT</label>
          <input name="vat_basis" class="form-control form-control-sm" value="<?= h($f('vat_basis')) ?>"
                 placeholder="np. art. 43 ust. 1 pkt 29 lit. c ustawy o VAT">
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold mb-1">Rodzaj działalności</label>
          <select name="funding_source" class="form-select form-select-sm">
            <?php foreach (CRM_OFFER_FUNDING as $fk => $fl): ?>
            <option value="<?= h($fk) ?>" <?= (string)$f('funding_source', 'odplatna') === $fk ? 'selected' : '' ?>><?= h($fl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($objectives): ?>
        <div class="col-12">
          <label class="form-label small fw-semibold mb-1">Domyślny cel statutowy</label>
          <select name="objective_id" class="form-select form-select-sm">
            <option value="">— brak —</option>
            <?php foreach ($objectives as $ob): ?>
            <option value="<?= (int)$ob['id'] ?>" <?= (int)$f('objective_id') === (int)$ob['id'] ? 'selected' : '' ?>><?= h($ob['nazwa']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-12">
          <label class="form-label small fw-semibold mb-1">Notatka dla księgowości</label>
          <input name="accounting_note" class="form-control form-control-sm" value="<?= h($f('accounting_note')) ?>">
        </div>
        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="catAct"
                   <?= (int)$f('is_active', 1) === 1 ? 'checked' : '' ?>>
            <label class="form-check-label small" for="catAct">Pozycja aktywna (widoczna w kreatorze)</label>
          </div>
        </div>
      </div>
    </div>
    <div class="card-footer py-2">
      <button class="btn btn-crm-primary btn-sm"><i class="bi bi-check-lg me-1"></i><?= $edit ? 'Zapisz' : 'Dodaj' ?></button>
    </div>
  </form>
</div>
<?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
