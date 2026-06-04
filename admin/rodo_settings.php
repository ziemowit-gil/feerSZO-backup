<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rodo.php';
rodo_migrate();
require_login();
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $fields = ['org_name','org_adres','org_miejscowosc','org_nip','org_krs'];
    foreach ($fields as $key) {
        $val = trim($_POST[$key] ?? '');
        $exists = db_one("SELECT id FROM settings WHERE key_=?", [$key]);
        if ($exists) {
            db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$val, $key]);
        } else {
            db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute([$key, $val]);
        }
    }
    flash_set('success', 'Dane organizacji zaktualizowane.');
    header('Location: rodo_settings.php'); exit;
}

$org = rodo_org_data();
$PAGE_TITLE = 'Ustawienia RODO';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/rodo/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h4 class="mb-0 fw-bold"><i class="bi bi-shield-lock text-primary me-2"></i>Ustawienia RODO</h4>
</div>

<?= flash_html() ?>

<div class="row">
<div class="col-md-7">

<div class="card border-0 shadow-sm mb-3">
  <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
    <i class="bi bi-building me-1 text-primary"></i>Dane administratora danych (wypełniane automatycznie w dokumentach)
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold">Pełna nazwa organizacji <span class="text-danger">*</span></label>
        <?php
        $stored_name = org_setting('org_name');
        $const_name  = defined('ORG_NAME') ? ORG_NAME : '';
        $show_name   = (strlen($const_name) > strlen($stored_name)) ? $const_name : ($stored_name ?: $const_name);
        ?>
        <input name="org_name" class="form-control" required value="<?= h($show_name) ?>">
        <div class="form-text">Pojawia się w nagłówku upoważnienia jako „Administrator danych".</div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">Adres siedziby <span class="text-danger">*</span></label>
        <input name="org_adres" class="form-control" required
               value="<?= h(org_setting('org_adres')) ?>">
        <div class="form-text">ul. Przykładowa 1, 00-001 Warszawa</div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">Miejscowość</label>
        <input name="org_miejscowosc" class="form-control"
               value="<?= h(org_setting('org_miejscowosc')) ?>">
        <div class="form-text">Używana w wierszu „Miejscowość, data" na dokumentach.</div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <label class="form-label fw-semibold">NIP</label>
          <input name="org_nip" class="form-control font-monospace"
                 value="<?= h(org_setting('org_nip')) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">KRS</label>
          <input name="org_krs" class="form-control font-monospace"
                 value="<?= h(org_setting('org_krs')) ?>">
        </div>
      </div>

      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i>Zapisz dane organizacji
      </button>
    </form>
  </div>
</div>

</div><!-- /col-7 -->
<div class="col-md-5">

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-info-circle me-1"></i>Wskazówki RODO
    </div>
    <div class="card-body small" style="line-height:1.7">
      <p class="fw-semibold mb-1">📋 Rejestr osób upoważnionych</p>
      <p class="text-muted mb-2">
        Prowadzenie rejestru osób upoważnionych do przetwarzania danych wynika z
        <strong>zasady rozliczalności (art. 5 ust. 2 RODO)</strong>.
        System automatycznie nadaje numery RODO/RRRR/NNNN.
      </p>
      <p class="fw-semibold mb-1">🎓 Szkolenie</p>
      <p class="text-muted mb-2">
        Przed przekazaniem danych wolontariuszowi należy przeprowadzić szkolenie
        z zakresu ochrony danych osobowych i odnotować ten fakt w systemie.
      </p>
      <p class="fw-semibold mb-1">🔒 Zakres — zasada minimalizacji</p>
      <p class="text-muted mb-2">
        W § 2 należy precyzyjnie określić zakres upoważnienia.
        Nie należy dawać „nieograniczonego" dostępu do wszystkich baz danych.
      </p>
      <p class="fw-semibold mb-1">⏰ Wygaśnięcie</p>
      <p class="text-muted mb-0">
        System automatycznie oznacza upoważnienie jako wygasłe gdy umowa wolontariatu
        zostanie zakończona lub anulowana.
      </p>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-list-ul me-1"></i>Szybkie linki
    </div>
    <div class="list-group list-group-flush">
      <a href="<?= APP_URL ?>/rodo/index.php" class="list-group-item list-group-item-action py-2" style="font-size:.86rem">
        <i class="bi bi-shield-lock text-primary me-2"></i>Rejestr upoważnień
      </a>
      <a href="<?= APP_URL ?>/rodo/new.php" class="list-group-item list-group-item-action py-2" style="font-size:.86rem">
        <i class="bi bi-plus-circle text-success me-2"></i>Nowe upoważnienie
      </a>
      <a href="<?= APP_URL ?>/rodo/index.php?training=0&status=aktywne" class="list-group-item list-group-item-action py-2" style="font-size:.86rem">
        <i class="bi bi-exclamation-triangle text-warning me-2"></i>Aktywne bez szkolenia
      </a>
    </div>
  </div>

</div><!-- /col-5 -->
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
