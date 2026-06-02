<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
require_login();
notif_migrate();

if (!is_admin()) {
    flash_set('danger', 'Brak uprawnień.');
    header('Location: ' . APP_URL . '/komunikaty/index.php');
    exit;
}

$_cu = current_user();

// Pobierz org_units
$org_units = [];
try { $org_units = db_all("SELECT id, name FROM org_units ORDER BY name", []); } catch (\Throwable $e) {}

$errors = [];
$values = [
    'title'      => '',
    'body'       => '',
    'audience'   => 'all',
    'is_pinned'  => 0,
    'expires_at' => '',
    'send_email' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $values['title']      = trim($_POST['title'] ?? '');
    $values['body']       = trim($_POST['body'] ?? '');
    $values['audience']   = trim($_POST['audience'] ?? 'all');
    $values['is_pinned']  = isset($_POST['is_pinned']) ? 1 : 0;
    $values['expires_at'] = trim($_POST['expires_at'] ?? '');
    $values['send_email'] = isset($_POST['send_email']) ? 1 : 0;

    if ($values['title'] === '') $errors[] = 'Tytuł jest wymagany.';
    if (mb_strlen($values['title']) > 200) $errors[] = 'Tytuł może mieć maksymalnie 200 znaków.';
    if (mb_strlen($values['body']) > 5000) $errors[] = 'Treść może mieć maksymalnie 5000 znaków.';
    if (mb_strlen($values['body']) < 1) $errors[] = 'Treść jest wymagana.';

    // Walidacja audience
    $valid_audiences = ['all', 'role:admin', 'role:editor', 'role:viewer'];
    foreach ($org_units as $ou) {
        $valid_audiences[] = 'unit:' . $ou['id'];
    }
    if (!in_array($values['audience'], $valid_audiences, true)) {
        $errors[] = 'Nieprawidłowy odbiorca.';
    }

    if (empty($errors)) {
        $author_name = trim(($values['name'] ?? '') ?: (($_cu['first_name'] ?? '') . ' ' . ($_cu['last_name'] ?? '')));
        if ($author_name === ' ' || $author_name === '') {
            $author_name = $_cu['name'] ?? '';
        }
        $ann_id = ann_create($values, (int)$_cu['id'], $author_name);
        flash_set('success', 'Ogłoszenie zostało opublikowane.');
        header('Location: ' . APP_URL . '/komunikaty/index.php');
        exit;
    }
}

$PAGE_TITLE = 'Nowe ogłoszenie';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-3">
  <ul class="mb-0 ps-3">
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/komunikaty/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h5 class="mb-0 fw-semibold"><i class="bi bi-megaphone me-2 text-warning"></i>Nowe ogłoszenie</h5>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm rounded-3">
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

          <div class="mb-3">
            <label for="ann-title" class="form-label fw-semibold">Tytuł <span class="text-danger">*</span></label>
            <input type="text" id="ann-title" name="title" class="form-control"
                   maxlength="200" required
                   value="<?= h($values['title']) ?>"
                   placeholder="Krótki, opisowy tytuł ogłoszenia">
            <div class="form-text text-end"><span id="title-cnt">0</span>/200</div>
          </div>

          <div class="mb-3">
            <label for="ann-body" class="form-label fw-semibold">Treść <span class="text-danger">*</span></label>
            <textarea id="ann-body" name="body" class="form-control font-monospace"
                      rows="8" maxlength="5000" required
                      placeholder="Pełna treść ogłoszenia…"><?= h($values['body']) ?></textarea>
            <div class="form-text d-flex justify-content-between">
              <span>Formatowanie zwykłym tekstem (nowe linie są zachowane)</span>
              <span><span id="body-cnt">0</span>/5000</span>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Odbiorca</label>
            <select name="audience" class="form-select" id="ann-audience">
              <option value="all" <?= $values['audience'] === 'all' ? 'selected' : '' ?>>Wszyscy aktywni użytkownicy</option>
              <optgroup label="Rola">
                <option value="role:admin"  <?= $values['audience'] === 'role:admin'  ? 'selected' : '' ?>>Administratorzy</option>
                <option value="role:editor" <?= $values['audience'] === 'role:editor' ? 'selected' : '' ?>>Edytorzy</option>
                <option value="role:viewer" <?= $values['audience'] === 'role:viewer' ? 'selected' : '' ?>>Przeglądający</option>
              </optgroup>
              <?php if (!empty($org_units)): ?>
              <optgroup label="Jednostka organizacyjna">
                <?php foreach ($org_units as $ou): ?>
                <option value="unit:<?= (int)$ou['id'] ?>"
                  <?= $values['audience'] === 'unit:' . $ou['id'] ? 'selected' : '' ?>>
                  <?= h($ou['name']) ?>
                </option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
            </select>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label for="ann-expires" class="form-label fw-semibold">Data wygaśnięcia <span class="text-muted fw-normal">(opcjonalna)</span></label>
              <input type="date" id="ann-expires" name="expires_at" class="form-control"
                     value="<?= h($values['expires_at']) ?>"
                     min="<?= date('Y-m-d') ?>">
              <div class="form-text">Po tej dacie ogłoszenie nie będzie widoczne</div>
            </div>
          </div>

          <div class="mb-3 d-flex flex-column gap-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="ann-pinned" name="is_pinned" value="1"
                     <?= $values['is_pinned'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="ann-pinned">
                <i class="bi bi-pin-angle-fill text-warning me-1"></i>Przypnij ogłoszenie na górze
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="ann-email" name="send_email" value="1"
                     <?= $values['send_email'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="ann-email">
                <i class="bi bi-envelope me-1"></i>Wyślij też e-mailem do odbiorców
              </label>
              <div class="alert alert-warning py-1 px-2 mt-1" id="email-warn" style="font-size:.79rem;<?= $values['send_email'] ? '' : 'display:none' ?>">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>Uwaga:</strong> Może to spowodować wysłanie wielu wiadomości e-mail (do wszystkich odbiorców). Użyj ostrożnie.
              </div>
            </div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-send me-1"></i>Opublikuj ogłoszenie
            </button>
            <a href="<?= APP_URL ?>/komunikaty/index.php" class="btn btn-outline-secondary">Anuluj</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Podgląd -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm rounded-3" style="position:sticky;top:1rem">
      <div class="card-header bg-white py-2">
        <span class="fw-semibold" style="font-size:.85rem"><i class="bi bi-eye me-1"></i>Podgląd treści</span>
      </div>
      <div class="card-body p-3">
        <div id="preview-title" class="fw-bold mb-2 text-dark" style="font-size:.92rem"></div>
        <div id="preview-body" class="text-muted" style="font-size:.83rem;white-space:pre-wrap;word-break:break-word;min-height:80px"></div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var titleEl   = document.getElementById('ann-title');
  var bodyEl    = document.getElementById('ann-body');
  var titleCnt  = document.getElementById('title-cnt');
  var bodyCnt   = document.getElementById('body-cnt');
  var prevTitle = document.getElementById('preview-title');
  var prevBody  = document.getElementById('preview-body');
  var emailCb   = document.getElementById('ann-email');
  var emailWarn = document.getElementById('email-warn');

  function update() {
    titleCnt.textContent = titleEl.value.length;
    bodyCnt.textContent  = bodyEl.value.length;
    prevTitle.textContent = titleEl.value || '(brak tytułu)';
    prevBody.textContent  = bodyEl.value  || '(brak treści)';
  }
  titleEl.addEventListener('input', update);
  bodyEl.addEventListener('input', update);
  update();

  emailCb.addEventListener('change', function() {
    emailWarn.style.display = emailCb.checked ? '' : 'none';
  });
})();
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
