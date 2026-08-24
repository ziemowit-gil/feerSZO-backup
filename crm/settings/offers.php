<?php
/**
 * crm/settings/offers.php — Ustawienia modułu Oferty (działalność odpłatna).
 *
 * Sterowanie regułami biznesowymi: wymóg potwierdzenia dla osoby fizycznej,
 * limity rabatów per rola, kto zatwierdza rabaty, kto edytuje cennik,
 * domyślna ważność oferty i termin follow-upu.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_offers.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('settings', 'write');
if (!can_write('crm_ustawienia') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do ustawień CRM.');
    header('Location: ' . APP_URL . '/crm/dashboard.php'); exit;
}
crm_offers_migrate();

$PAGE_TITLE = 'CRM — Oferty (ustawienia)';
$all_roles  = [];
try { $all_roles = crm_all_roles(); } catch (\Throwable $e) {}
if (!$all_roles) $all_roles = ['admin' => 'Administrator', 'editor' => 'Redaktor', 'viewer' => 'Podgląd'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    crm_offer_setting_save('crm_offer_confirm_person', !empty($_POST['confirm_person']) ? '1' : '0');
    crm_offer_setting_save('crm_offer_number_prefix', strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($_POST['prefix'] ?? 'OF'))) ?: 'OF');
    crm_offer_setting_save('crm_offer_validity_days', (string)max(1, (int)($_POST['validity_days'] ?? 14)));
    crm_offer_setting_save('crm_offer_followup_days', (string)max(1, (int)($_POST['followup_days'] ?? 3)));
    crm_offer_setting_save('crm_offer_discount_default_limit', (string)max(0, min(100, (float)str_replace(',', '.', (string)($_POST['default_limit'] ?? 10)))));
    crm_offer_setting_save('crm_offer_discount_approvers', implode(',', array_map('trim', (array)($_POST['approvers'] ?? ['admin']))));
    crm_offer_setting_save('crm_offer_catalog_editors', implode(',', array_map('trim', (array)($_POST['catalog_editors'] ?? ['admin']))));
    crm_offer_setting_save('crm_offer_footer', trim((string)($_POST['footer'] ?? '')));
    $bc = trim((string)($_POST['brand_color'] ?? ''));
    crm_offer_setting_save('crm_offer_brand_color', preg_match('/^#[0-9a-fA-F]{6}$/', $bc) ? $bc : '');

    $limits = [];
    foreach ((array)($_POST['limit'] ?? []) as $role => $v) {
        $v = trim((string)$v);
        if ($v === '') continue;
        $limits[(string)$role] = max(0, min(100, (float)str_replace(',', '.', $v)));
    }
    crm_offer_setting_save('crm_offer_discount_limits', json_encode($limits, JSON_UNESCAPED_UNICODE));

    flash_set('success', 'Ustawienia modułu Oferty zapisane.');
    header('Location: ' . APP_URL . '/crm/settings/offers.php'); exit;
}

$limits    = json_decode(crm_offer_setting('crm_offer_discount_limits', '{}'), true) ?: [];
$approvers = array_filter(array_map('trim', explode(',', crm_offer_setting('crm_offer_discount_approvers', 'admin'))));
$cat_edit  = array_filter(array_map('trim', explode(',', crm_offer_setting('crm_offer_catalog_editors', 'admin'))));

include __DIR__ . '/../includes/header_crm.php';
require_once __DIR__ . '/_nav.php';
?>

<nav aria-label="Ścieżka nawigacji" class="mb-2">
  <ol class="breadcrumb mb-0" style="font-size:.82rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia</a></li>
    <li class="breadcrumb-item active">Oferty</li>
  </ol>
</nav>

<h1 class="fw-bold mb-3" style="font-size:1.25rem">
  <i class="bi bi-file-earmark-ruled-fill me-2" style="color:var(--crm-primary)"></i>Oferty — ustawienia
</h1>

<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="card shadow-sm mb-3">
  <div class="card-header py-2 fw-semibold" style="font-size:.9rem">
    <i class="bi bi-patch-check me-1"></i>Potwierdzanie ofert
  </div>
  <div class="card-body">
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" role="switch" name="confirm_person" value="1" id="cpSw"
             <?= crm_offer_confirm_person_required() ? 'checked' : '' ?>>
      <label class="form-check-label fw-semibold" for="cpSw">
        Oferta dla osoby fizycznej wymaga potwierdzenia klienta
      </label>
    </div>
    <div class="alert alert-warning py-2 mb-0" style="font-size:.83rem">
      <i class="bi bi-exclamation-triangle-fill me-1"></i>
      Gdy włączone: akceptacja i uruchomienie realizacji oferty dla osoby fizycznej są <strong>zablokowane</strong>
      do chwili zarejestrowania potwierdzenia (online przez link, e-mail, skan albo protokół).
      Oferty dla organizacji/kontrahentów nie są objęte tym wymogiem.
      Wyłączenie zaleca się wyłącznie wtedy, gdy organizacja dokumentuje zgody klientów innym sposobem.
    </div>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-header py-2 fw-semibold" style="font-size:.9rem"><i class="bi bi-percent me-1"></i>Rabaty</div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label small fw-semibold mb-1">Domyślny limit rabatu (%)</label>
        <input name="default_limit" class="form-control form-control-sm"
               value="<?= h(crm_offer_setting('crm_offer_discount_default_limit', '10')) ?>">
        <div class="form-text" style="font-size:.75rem">Dla roli bez własnego limitu. Admin ma zawsze 100%.</div>
      </div>
      <div class="col-md-8">
        <label class="form-label small fw-semibold mb-1">Limity per rola (%)</label>
        <div class="row g-2">
          <?php foreach ($all_roles as $role => $rlabel): if ($role === 'admin') continue; ?>
          <div class="col-6 col-lg-4">
            <div class="input-group input-group-sm">
              <span class="input-group-text" style="min-width:110px;font-size:.75rem" title="<?= h($role) ?>"><?= h($rlabel ?: $role) ?></span>
              <input name="limit[<?= h($role) ?>]" class="form-control"
                     value="<?= h((string)($limits[$role] ?? '')) ?>" placeholder="—">
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold mb-1">Kto zatwierdza rabaty ponad limit</label>
        <select name="approvers[]" class="form-select form-select-sm" multiple size="5">
          <?php foreach ($all_roles as $role => $rlabel): ?>
          <option value="<?= h($role) ?>" <?= in_array($role, $approvers, true) ? 'selected' : '' ?>><?= h($rlabel ?: $role) ?> (<?= h($role) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold mb-1">Kto edytuje cennik usług odpłatnych</label>
        <select name="catalog_editors[]" class="form-select form-select-sm" multiple size="5">
          <?php foreach ($all_roles as $role => $rlabel): ?>
          <option value="<?= h($role) ?>" <?= in_array($role, $cat_edit, true) ? 'selected' : '' ?>><?= h($rlabel ?: $role) ?> (<?= h($role) ?>)</option>
          <?php endforeach; ?>
        </select>
        <div class="form-text" style="font-size:.75rem">Pozostali mogą używać cennika w ofertach, ale nie zmieniać cen bazowych.</div>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-header py-2 fw-semibold" style="font-size:.9rem"><i class="bi bi-sliders me-1"></i>Dokument i cykl życia</div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Prefiks numeracji</label>
        <input name="prefix" class="form-control form-control-sm" value="<?= h(crm_offer_setting('crm_offer_number_prefix', 'OF')) ?>">
        <div class="form-text" style="font-size:.75rem">Format: <code><?= h(crm_offer_setting('crm_offer_number_prefix', 'OF')) ?>/0001/<?= date('Y') ?></code></div>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Domyślna ważność (dni)</label>
        <input type="number" min="1" name="validity_days" class="form-control form-control-sm"
               value="<?= h(crm_offer_setting('crm_offer_validity_days', '14')) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Follow-up po (dniach)</label>
        <input type="number" min="1" name="followup_days" class="form-control form-control-sm"
               value="<?= h(crm_offer_setting('crm_offer_followup_days', '3')) ?>">
        <div class="form-text" style="font-size:.75rem">Zadanie dla opiekuna po wysłaniu oferty.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Kolor dokumentu (marka)</label>
        <?php $_bc = crm_offer_setting('crm_offer_brand_color', '') ?: '#2E844A'; ?>
        <div class="input-group input-group-sm">
          <input type="color" name="brand_color" class="form-control form-control-color" value="<?= h($_bc) ?>"
                 title="Kolor nagłówka, belki i akcentów w ofercie">
          <span class="input-group-text" style="font-family:monospace"><?= h($_bc) ?></span>
        </div>
        <div class="form-text" style="font-size:.75rem">Logo pobierane z Administracja → Dane organizacji.</div>
      </div>
      <div class="col-12">
        <label class="form-label small fw-semibold mb-1">Stopka dokumentu oferty</label>
        <textarea name="footer" class="form-control form-control-sm" rows="3"
                  placeholder="np. Oferta nie stanowi oferty handlowej w rozumieniu art. 66 §1 KC…"><?= h(crm_offer_setting('crm_offer_footer', '')) ?></textarea>
      </div>
    </div>
  </div>
</div>

<div class="d-flex gap-2 mb-4">
  <button class="btn btn-crm-primary"><i class="bi bi-check-lg me-1"></i>Zapisz ustawienia</button>
  <a href="<?= APP_URL ?>/crm/offers/index.php" class="btn btn-outline-secondary">Przejdź do ofert</a>
  <a href="<?= APP_URL ?>/crm/offers/catalog.php" class="btn btn-outline-secondary">Katalog usług</a>
</div>
</form>

<?php require_once __DIR__ . '/_nav_end.php'; ?>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
