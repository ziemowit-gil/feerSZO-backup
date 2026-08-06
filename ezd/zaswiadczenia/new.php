<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/zaswiadczenia_ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$user_id = (int)current_user()['id'];
$user    = current_user();

// Krok 1: wybór typu (jeśli brak ?typ_id)
$typ_id  = (int)($_GET['typ_id'] ?? $_POST['typ_id'] ?? 0);
$typy    = ezd_zas_typy_all(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Krok 1 POST: wybrano typ
    if (!$typ_id) {
        $typ_id = (int)($_POST['typ_id'] ?? 0);
        if (!$typ_id) { flash_set('error', 'Wybierz typ zaświadczenia.'); header('Location:' . APP_URL . '/ezd/zaswiadczenia/new.php'); exit; }
        header('Location:' . APP_URL . '/ezd/zaswiadczenia/new.php?typ_id=' . $typ_id); exit;
    }

    // Krok 2 POST: złóż wniosek
    $typ = ezd_zas_typ_get($typ_id);
    if (!$typ || !$typ['is_active']) { flash_set('error', 'Nieprawidłowy typ.'); header('Location:' . APP_URL . '/ezd/zaswiadczenia/new.php'); exit; }

    $name  = trim($_POST['wnioskodawca_name'] ?? '');
    $email = trim($_POST['wnioskodawca_email'] ?? '');
    if (!$name) { flash_set('error', 'Imię i nazwisko wnioskodawcy jest wymagane.'); header('Location:' . APP_URL . '/ezd/zaswiadczenia/new.php?typ_id=' . $typ_id); exit; }

    $dane = [];
    $errors = [];
    foreach ($typ['pola'] as $pole) {
        $val = trim($_POST['pole_' . ($pole['name'] ?? '')] ?? '');
        if (!empty($pole['required']) && $val === '') {
            $errors[] = 'Pole „' . h($pole['label']) . '" jest wymagane.';
        } else {
            $dane[$pole['name']] = $val;
        }
    }

    if ($errors) {
        flash_set('error', implode('<br>', $errors));
        header('Location:' . APP_URL . '/ezd/zaswiadczenia/new.php?typ_id=' . $typ_id); exit;
    }

    $id       = ezd_zas_create($typ_id, $name, $email, $dane, $user_id);
    $z_urzedu = isset($_POST['z_urzedu']) ? 1 : 0;
    if ($z_urzedu) {
        db()->prepare("UPDATE ezd_zaswiadczenia_wlasne SET z_urzedu=1 WHERE id=?")->execute([$id]);
    }

    // Jeśli nie wymaga akceptacji → od razu wydaj
    if (!$typ['wymaga_akceptacji']) {
        $res = ezd_zas_wydaj($id, $user_id);
        if ($res['ok']) {
            flash_set('success', 'Zaświadczenie ' . h($res['nr']) . ' wydane natychmiast (bez weryfikacji).');
        }
    } else {
        flash_set('success', 'Wniosek złożony. Czeka na weryfikację i wydanie.');
    }

    header('Location:' . APP_URL . '/ezd/zaswiadczenia/view.php?id=' . $id); exit;
}

$typ = $typ_id ? ezd_zas_typ_get($typ_id) : null;
$PAGE_TITLE = 'Nowy wniosek o zaświadczenie';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/zaswiadczenia/index.php">Zaświadczenia</a></li>
  <li class="breadcrumb-item active">Nowy wniosek</li>
</ol></nav>

<?= flash_html() ?>

<?php if (!$typ_id): ?>
<!-- Krok 1: wybór typu -->
<div class="card shadow-sm" style="max-width:640px">
  <div class="card-header fw-semibold" style="font-size:.88rem"><i class="bi bi-award me-1 text-primary"></i>Wybierz rodzaj zaświadczenia</div>
  <div class="card-body">
    <?php if (!$typy): ?>
    <div class="alert alert-warning py-2" style="font-size:.84rem">
      <i class="bi bi-exclamation-triangle me-1"></i>Brak aktywnych typów zaświadczeń.
      <?php if(ezd_is_manager()||can_edit()): ?><a href="<?= APP_URL ?>/ezd/zaswiadczenia/typy.php">Zdefiniuj typy.</a><?php endif; ?>
    </div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="list-group mb-3">
        <?php foreach($typy as $t): ?>
        <label class="list-group-item list-group-item-action d-flex gap-3 py-2" style="cursor:pointer">
          <input class="form-check-input flex-shrink-0 mt-1" type="radio" name="typ_id" value="<?= $t['id'] ?>" required>
          <div>
            <div class="fw-semibold" style="font-size:.88rem"><?= h($t['nazwa']) ?></div>
            <?php if($t['opis']): ?><div class="text-muted" style="font-size:.76rem"><?= h($t['opis']) ?></div><?php endif; ?>
            <?php if($t['wymaga_akceptacji']): ?><span class="badge bg-warning text-dark mt-1" style="font-size:.66rem">Wymaga akceptacji</span><?php else: ?><span class="badge bg-success mt-1" style="font-size:.66rem">Natychmiastowe wydanie</span><?php endif; ?>
          </div>
        </label>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-right me-1"></i>Dalej</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- Krok 2: formularz wniosku -->
<div class="row g-4" style="max-width:860px">
  <div class="col-lg-7">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="typ_id" value="<?= $typ_id ?>">
      <div class="card shadow-sm">
        <div class="card-header">
          <div class="fw-semibold" style="font-size:.88rem"><i class="bi bi-award me-1 text-primary"></i><?= h($typ['nazwa']) ?></div>
          <?php if($typ['opis']): ?><div class="text-muted mt-1" style="font-size:.76rem"><?= h($typ['opis']) ?></div><?php endif; ?>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label fw-semibold mb-1" style="font-size:.78rem">Imię i nazwisko wnioskodawcy <span class="text-danger">*</span></label>
            <input type="text" name="wnioskodawca_name" class="form-control form-control-sm"
                   value="<?= h($user['name'] ?? '') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold mb-1" style="font-size:.78rem">Adres e-mail kontaktowy</label>
            <input type="email" name="wnioskodawca_email" class="form-control form-control-sm"
                   value="<?= h($user['email'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="z_urzedu" value="1" id="chk-zurzedu-new">
              <label class="form-check-label" for="chk-zurzedu-new" style="font-size:.85rem">
                <i class="bi bi-building me-1"></i>Wystawione z inicjatywy organizacji (z urzędu)
              </label>
            </div>
          </div>
          <?php if($typ['pola']): ?>
          <hr class="my-3">
          <div class="fw-semibold mb-2" style="font-size:.82rem">Dane do zaświadczenia</div>
          <?php foreach($typ['pola'] as $pole): ?>
          <div class="mb-3">
            <label class="form-label fw-semibold mb-1" style="font-size:.78rem">
              <?= h($pole['label'] ?? $pole['name']) ?>
              <?php if(!empty($pole['required'])): ?><span class="text-danger">*</span><?php endif; ?>
            </label>
            <?php
              $fn   = 'pole_' . ($pole['name'] ?? '');
              $type = $pole['type'] ?? 'text';
              $req  = !empty($pole['required']) ? 'required' : '';
              if ($type === 'textarea'): ?>
            <textarea name="<?= h($fn) ?>" class="form-control form-control-sm" rows="3" <?= $req ?>></textarea>
            <?php elseif ($type === 'select'): ?>
            <select name="<?= h($fn) ?>" class="form-select form-select-sm" <?= $req ?>>
              <option value="">— wybierz —</option>
              <?php foreach($pole['options'] ?? [] as $opt): ?><option><?= h($opt) ?></option><?php endforeach; ?>
            </select>
            <?php elseif ($type === 'date'): ?>
            <input type="date" name="<?= h($fn) ?>" class="form-control form-control-sm" <?= $req ?>>
            <?php else: ?>
            <input type="text" name="<?= h($fn) ?>" class="form-control form-control-sm" <?= $req ?>>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="card-footer d-flex gap-2 justify-content-between">
          <a href="<?= APP_URL ?>/ezd/zaswiadczenia/new.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Zmień typ</a>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Złóż wniosek</button>
        </div>
      </div>
    </form>
  </div>

  <?php if($typ['szablon_tresc']): ?>
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-eye me-1 text-muted"></i>Podgląd szablonu treści</div>
      <div class="card-body p-3" style="font-size:.78rem;white-space:pre-wrap;font-family:inherit;line-height:1.7;max-height:400px;overflow-y:auto"><?= h($typ['szablon_tresc']) ?></div>
      <div class="card-footer text-muted" style="font-size:.72rem"><i class="bi bi-info-circle me-1"></i>Tokeny <span class="font-monospace">{{...}}</span> zostaną zastąpione wprowadzonymi danymi.</div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
