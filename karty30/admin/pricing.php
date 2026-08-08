<?php
/**
 * karty30/admin/pricing.php — Cennik i bezpłatny limit godzin D3.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/pfron.php';

k30_require_access();
karty30_migrate();
if (!is_admin()) { flash_set('danger','Tylko administrator.'); header('Location: ../index.php'); exit; }

$PAGE_TITLE = 'Cennik D3 — Dydaktyka 3';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    // Przełącznik PFRON
    if ($op === 'toggle_pfron') {
        $val = (isset($_POST['pfron_enabled']) && $_POST['pfron_enabled'] === '1') ? '1' : '0';
        org_setting_set('k30_pfron_enabled', $val);
        flash_set('success', $val === '1' ? 'Obsługa PFRON włączona.' : 'Obsługa PFRON wyłączona.');
        header('Location: pricing.php'); exit;
    }

    // Globalny limit bezpłatnych godzin
    if ($op === 'save_limit') {
        $limit = max(0, (float)str_replace(',', '.', $_POST['free_hours_limit'] ?? '0'));
        try {
            db()->prepare("INSERT INTO settings (key_,value) VALUES ('k30_free_hours_limit',?)
                           ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
               ->execute([(string)$limit]);
        } catch (\Throwable $e) {
            $ex = db_one("SELECT key_ FROM settings WHERE key_='k30_free_hours_limit'");
            if ($ex) db()->prepare("UPDATE settings SET value=? WHERE key_='k30_free_hours_limit'")->execute([(string)$limit]);
            else     db_insert('settings', ['key_'=>'k30_free_hours_limit','value'=>(string)$limit]);
        }
        flash_set('success', 'Limit bezpłatnych godzin zaktualizowany.');
        header('Location: pricing.php'); exit;
    }

    // Dodaj / edytuj próg
    if ($op === 'save_tier') {
        $tid        = (int)($_POST['tier_id'] ?? 0);
        $hours_from = max(0, (float)str_replace(',','.',trim($_POST['hours_from'] ?? '0')));
        $hours_to   = trim($_POST['hours_to'] ?? '');
        $hours_to   = ($hours_to !== '' && (float)str_replace(',','.',$hours_to) > $hours_from)
                      ? (float)str_replace(',','.',$hours_to) : null;
        $rate       = max(0, (float)str_replace(',','.',trim($_POST['rate'] ?? '0')));
        $label      = trim($_POST['label'] ?? '');
        $sort       = (int)($_POST['sort_order'] ?? 0);
        if ($tid) {
            db()->prepare("UPDATE k30_price_tiers SET hours_from=?,hours_to=?,rate=?,label=?,sort_order=? WHERE id=?")
               ->execute([$hours_from,$hours_to,$rate,$label,$sort,$tid]);
        } else {
            db_insert('k30_price_tiers',['hours_from'=>$hours_from,'hours_to'=>$hours_to,'rate'=>$rate,'label'=>$label,'sort_order'=>$sort]);
        }
        flash_set('success', 'Próg cennika zapisany.');
        header('Location: pricing.php'); exit;
    }

    if ($op === 'delete_tier') {
        $tid = (int)($_POST['tier_id'] ?? 0);
        if ($tid) db()->prepare("DELETE FROM k30_price_tiers WHERE id=?")->execute([$tid]);
        flash_set('success', 'Próg usunięty.');
        header('Location: pricing.php'); exit;
    }
}

$free_limit = k30_free_hours_limit();
$tiers      = k30_price_tiers();
$edit_id    = (int)($_GET['edit'] ?? 0);
$edit_tier  = $edit_id ? db_one("SELECT * FROM k30_price_tiers WHERE id=?", [$edit_id]) : null;

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>
<style>
.tier-badge { display:inline-block;padding:.2em .6em;border-radius:6px;font-size:.78rem;font-weight:600;background:#f0fdf4;color:#15803d;border:1px solid #86efac }
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item active">Cennik</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0"><i class="bi bi-currency-exchange text-primary me-1"></i>Cennik konsultacji K30</h4>
  <?php if (!$edit_tier && !isset($_GET['new'])): ?>
  <a href="?new=1" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-plus-lg me-1"></i>Dodaj próg</a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<!-- Przełącznik PFRON -->
<div class="card border-0 shadow-sm mb-4" style="max-width:500px">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-building-fill-check text-primary"></i>Obsługa PFRON
  </div>
  <div class="card-body">
    <p class="small text-muted mb-3">
      Wyłączenie ukrywa wszystkie elementy związane z PFRON: menu, stat na dashboardzie,
      tryb rozliczenia PFRON w harmonogramie, kartę beneficjenta, portal kursanta
      i kafelek logowania. Dane historyczne pozostają w bazie bez zmian.
    </p>
    <form method="post" action="pricing.php">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="toggle_pfron">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch"
               id="pfronEnabledSwitch" name="pfron_enabled" value="1"
               <?= k30_pfron_enabled() ? 'checked' : '' ?>
               onchange="this.form.submit()">
        <label class="form-check-label fw-semibold" for="pfronEnabledSwitch">
          <?= k30_pfron_enabled() ? '<span class="text-success">Włączona</span>' : '<span class="text-secondary">Wyłączona</span>' ?>
        </label>
      </div>
    </form>
  </div>
</div>

<!-- Limit bezpłatnych godzin -->
<div class="card border-0 shadow-sm mb-4" style="max-width:500px">
  <div class="card-header fw-semibold"><i class="bi bi-gift me-1 text-success"></i>Globalny limit bezpłatnych godzin</div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="save_limit">
      <div class="input-group" style="max-width:260px">
        <input type="number" class="form-control" name="free_hours_limit"
               value="<?= h(number_format($free_limit, 2, '.', '')) ?>"
               min="0" step="0.5" placeholder="0">
        <span class="input-group-text">godzin</span>
        <button type="submit" class="btn btn-primary">Zapisz</button>
      </div>
      <div class="form-text mt-1">
        Każdy nowy beneficjent otrzymuje tyle godzin bezpłatnie.
        Indywidualny limit na kliencie (<em>Dostępne godziny</em>) nadpisuje globalny jeśli jest wyższy.
      </div>
    </form>
  </div>
</div>

<!-- Formularz progu -->
<?php if (isset($_GET['new']) || $edit_tier):
  $f = $edit_tier ?? ['hours_from'=>'','hours_to'=>'','rate'=>'','label'=>'','sort_order'=>0];
?>
<div class="card border-0 shadow-sm mb-4" style="max-width:580px">
  <div class="card-header fw-semibold"><?= $edit_tier ? 'Edytuj próg' : 'Nowy próg cennika' ?></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf"    value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"      value="save_tier">
      <input type="hidden" name="tier_id"  value="<?= (int)($f['id']??0) ?>">
      <div class="row g-3 mb-3">
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Od (h) <span class="text-danger">*</span></label>
          <input type="number" class="form-control" name="hours_from" step="0.5" min="0"
                 value="<?= h($f['hours_from']) ?>" required placeholder="0">
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Do (h)</label>
          <input type="number" class="form-control" name="hours_to" step="0.5" min="0"
                 value="<?= h($f['hours_to'] ?? '') ?>" placeholder="bez limitu">
          <div class="form-text">Puste = bez górnej granicy.</div>
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Stawka (zł/h) <span class="text-danger">*</span></label>
          <input type="number" class="form-control" name="rate" step="0.01" min="0"
                 value="<?= h($f['rate']) ?>" required placeholder="0.00">
          <div class="form-text">0 = bezpłatny.</div>
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-8">
          <label class="form-label">Etykieta (opcjonalna)</label>
          <input type="text" class="form-control" name="label"
                 value="<?= h($f['label']) ?>" placeholder="np. Bezpłatny, Standardowy, Komercyjny">
        </div>
        <div class="col-sm-4">
          <label class="form-label">Kolejność</label>
          <input type="number" class="form-control" name="sort_order" min="0"
                 value="<?= (int)$f['sort_order'] ?>">
        </div>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Zapisz próg</button>
        <a href="pricing.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Lista progów -->
<div class="card border-0 shadow-sm" style="max-width:720px">
  <div class="card-header fw-semibold d-flex align-items-center">
    Progi cennika <span class="badge bg-secondary ms-2"><?= count($tiers) ?></span>
  </div>
  <?php if (!$tiers): ?>
  <div class="card-body text-muted">Brak zdefiniowanych progów. <a href="?new=1">Dodaj pierwszy</a>.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>Od (h)</th><th>Do (h)</th><th>Stawka</th><th>Etykieta</th><th class="text-end">Akcje</th></tr>
      </thead>
      <tbody>
        <?php foreach ($tiers as $t): ?>
        <tr>
          <td><?= number_format((float)$t['hours_from'],2,',','') ?> h</td>
          <td><?= $t['hours_to'] !== null ? number_format((float)$t['hours_to'],2,',','').' h' : '∞' ?></td>
          <td>
            <?php if ((float)$t['rate'] == 0): ?>
            <span class="tier-badge">0,00 zł/h — bezpłatny</span>
            <?php else: ?>
            <strong><?= number_format((float)$t['rate'],2,',','') ?> zł/h</strong>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= h($t['label']) ?></td>
          <td class="text-end">
            <a href="?edit=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 me-1">
              <i class="bi bi-pencil"></i>
            </a>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ten próg?')">
              <input type="hidden" name="_csrf"    value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"      value="delete_tier">
              <input type="hidden" name="tier_id"  value="<?= (int)$t['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer text-muted small">
    <i class="bi bi-info-circle me-1"></i>
    Progi stosowane sekwencyjnie od dołu limitu bezpłatnego.
    Godziny bezpłatne (globalny limit + indywidualny) są odliczane przed naliczaniem opłat.
  </div>
  <?php endif; ?>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
