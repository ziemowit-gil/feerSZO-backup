<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');
require_once __DIR__ . '/../includes/onboarding_schema.php';

$PAGE_TITLE = 'Ustawienia — Kreator onboardingowy';

$keys = [
    'onboarding_enabled',
    'onboarding_title',
    'onboarding_intro',
    'onboarding_klauzula',
    'onboarding_require_sms',
    'onboarding_require_email',
    'onboarding_notify_email',
    'onboarding_accent_color',
    'onboarding_bg_color',
    'onboarding_custom_css',
    // 'onboarding_logo' is handled separately (file upload)
];

$settings = [];
$all_keys = array_merge($keys, ['onboarding_logo']);
foreach ($all_keys as $k) {
    $r = db_one("SELECT value FROM settings WHERE key_=?", [$k]);
    $settings[$k] = $r['value'] ?? '';
}
// defaults for colour pickers
if ($settings['onboarding_accent_color'] === '') $settings['onboarding_accent_color'] = '#0d6efd';
if ($settings['onboarding_bg_color']     === '') $settings['onboarding_bg_color']     = '#f0f4f8';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // ── handle logo upload ───────────────────────────────────────────────────
    if (!empty($_FILES['onboarding_logo_file']['tmp_name'])) {
        $file    = $_FILES['onboarding_logo_file'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg','jpeg','png','gif','webp','svg'];
        if (in_array($ext, $allowed_ext) && $file['size'] <= 2 * 1024 * 1024) {
            $dest_dir = rtrim(UPLOAD_DIR, '/') . '/onboarding/';
            if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);
            // remove old logo file if it was stored locally
            $old_logo = $settings['onboarding_logo'];
            if ($old_logo && str_starts_with($old_logo, 'uploads/')) {
                @unlink(__DIR__ . '/../' . $old_logo);
            }
            $new_name = 'logo_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $dest_dir . $new_name)) {
                $logo_path = 'uploads/onboarding/' . $new_name;
                db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
                    ->execute(['onboarding_logo', $logo_path]);
                $settings['onboarding_logo'] = $logo_path;
            }
        }
    }

    // ── remove logo ──────────────────────────────────────────────────────────
    if (!empty($_POST['remove_logo'])) {
        $old_logo = $settings['onboarding_logo'];
        if ($old_logo && str_starts_with($old_logo, 'uploads/')) {
            @unlink(__DIR__ . '/../' . $old_logo);
        }
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
            ->execute(['onboarding_logo', '']);
        $settings['onboarding_logo'] = '';
    }

    // ── save scalar keys ─────────────────────────────────────────────────────
    foreach ($keys as $k) {
        $val = trim($_POST[$k] ?? '');
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
            ->execute([$k, $val]);
        $settings[$k] = $val;
    }

    flash_set('success', 'Ustawienia zapisane.');
    header('Location: ' . APP_URL . '/onboarding/settings.php');
    exit;
}

$public_url = APP_URL . '/onboarding/form.php';

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/onboarding/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Zgłoszenia
  </a>
  <h4 class="mb-0">
    <i class="bi bi-sliders text-primary"></i>
    Ustawienia — Kreator onboardingowy
  </h4>
</div>

<?= flash_html() ?>

<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

  <!-- Card: Aktywność -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">
      <i class="bi bi-toggle-on"></i> Formularz
    </div>
    <div class="card-body">
      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" role="switch"
               id="onboarding_enabled" name="onboarding_enabled"
               value="1" <?= $settings['onboarding_enabled'] ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="onboarding_enabled">
          Formularz aktywny
        </label>
      </div>
      <?php if ($settings['onboarding_enabled']): ?>
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
        <input type="text" class="form-control" id="public-url"
               value="<?= h($public_url) ?>" readonly>
        <button class="btn btn-outline-secondary" type="button"
                onclick="navigator.clipboard.writeText(document.getElementById('public-url').value)
                         .then(()=>{this.innerHTML='<i class=\'bi bi-check\'></i>';setTimeout(()=>{this.innerHTML='<i class=\'bi bi-clipboard\'></i>';},1500)})">
          <i class="bi bi-clipboard"></i>
        </button>
      </div>
      <small class="text-muted">Publiczny adres formularza zgłoszeniowego.</small>
      <?php else: ?>
      <div class="text-muted" style="font-size:.9em">
        Formularz jest wyłączony. Publiczny adres:
        <code><?= h($public_url) ?></code>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Card: Treść formularza -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">
      <i class="bi bi-file-text"></i> Treść formularza
    </div>
    <div class="card-body">
      <div class="mb-3">
        <label for="onboarding_title" class="form-label fw-semibold">Tytuł formularza</label>
        <input type="text" class="form-control" id="onboarding_title" name="onboarding_title"
               value="<?= h($settings['onboarding_title']) ?>"
               placeholder="np. Dołącz do wolontariatu">
      </div>
      <div class="mb-3">
        <label for="onboarding_intro" class="form-label fw-semibold">Tekst wprowadzający</label>
        <textarea class="form-control" id="onboarding_intro" name="onboarding_intro"
                  rows="4"
                  placeholder="Opcjonalnie — pojawi się przed formularzem"><?= h($settings['onboarding_intro']) ?></textarea>
        <div class="form-text">Opcjonalnie — pojawi się przed formularzem.</div>
      </div>
    </div>
  </div>

  <!-- Card: Weryfikacja -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">
      <i class="bi bi-shield-check"></i> Weryfikacja
    </div>
    <div class="card-body">
      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" role="switch"
               id="onboarding_require_sms" name="onboarding_require_sms"
               value="1" <?= $settings['onboarding_require_sms'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="onboarding_require_sms">
          Wymagaj weryfikacji SMS numeru telefonu
        </label>
      </div>
      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" role="switch"
               id="onboarding_require_email" name="onboarding_require_email"
               value="1" <?= $settings['onboarding_require_email'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="onboarding_require_email">
          Wymagaj weryfikacji adresu e-mail
        </label>
      </div>
      <div class="mb-3">
        <label for="onboarding_notify_email" class="form-label fw-semibold">
          E-mail do powiadomień
        </label>
        <input type="email" class="form-control" id="onboarding_notify_email"
               name="onboarding_notify_email"
               value="<?= h($settings['onboarding_notify_email']) ?>"
               placeholder="np. koordynator@organizacja.pl">
        <div class="form-text">Na ten adres będą wysyłane powiadomienia o nowych zgłoszeniach.</div>
      </div>
    </div>
  </div>

  <!-- Card: Klauzula RODO -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">
      <i class="bi bi-file-earmark-lock"></i> Klauzula informacyjna (RODO)
    </div>
    <div class="card-body">
      <div class="mb-3">
        <label for="onboarding_klauzula" class="form-label fw-semibold">Treść klauzuli</label>
        <textarea class="form-control font-monospace" id="onboarding_klauzula"
                  name="onboarding_klauzula" rows="12"><?= h($settings['onboarding_klauzula']) ?></textarea>
        <div class="form-text">
          Tekst zostanie wyświetlony kandydatowi do akceptacji.
          Zostaw puste, aby użyć tekstu domyślnego.
        </div>
      </div>
    </div>
  </div>

  <!-- Card: Wygląd formularza -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">
      <i class="bi bi-palette"></i> Wygląd formularza
    </div>
    <div class="card-body">

      <!-- Logo -->
      <div class="mb-4">
        <label class="form-label fw-semibold">Logo</label>
        <?php if ($settings['onboarding_logo']): ?>
        <div class="mb-2 d-flex align-items-center gap-3">
          <img src="<?= APP_URL . '/' . h($settings['onboarding_logo']) ?>"
               alt="logo" style="max-height:60px;max-width:200px;object-fit:contain;border:1px solid #dee2e6;border-radius:.375rem;padding:4px;background:#fff">
          <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" name="remove_logo" id="remove_logo" value="1">
            <label class="form-check-label text-danger" for="remove_logo">Usuń logo</label>
          </div>
        </div>
        <?php endif; ?>
        <input type="file" class="form-control" name="onboarding_logo_file"
               accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
        <div class="form-text">PNG / JPEG / SVG / WebP, maks. 2 MB. Wyświetlane nad tytułem formularza.</div>
      </div>

      <!-- Colours -->
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label for="onboarding_accent_color" class="form-label fw-semibold">Kolor akcentu (przyciski, postęp)</label>
          <div class="input-group">
            <input type="color" class="form-control form-control-color" id="onboarding_accent_color"
                   name="onboarding_accent_color"
                   value="<?= h($settings['onboarding_accent_color']) ?>"
                   title="Wybierz kolor">
            <input type="text" class="form-control form-control-sm font-monospace"
                   id="onboarding_accent_color_text"
                   value="<?= h($settings['onboarding_accent_color']) ?>"
                   maxlength="7" placeholder="#0d6efd"
                   oninput="document.getElementById('onboarding_accent_color').value=this.value">
          </div>
        </div>
        <div class="col-sm-6">
          <label for="onboarding_bg_color" class="form-label fw-semibold">Kolor tła strony</label>
          <div class="input-group">
            <input type="color" class="form-control form-control-color" id="onboarding_bg_color"
                   name="onboarding_bg_color"
                   value="<?= h($settings['onboarding_bg_color']) ?>"
                   title="Wybierz kolor">
            <input type="text" class="form-control form-control-sm font-monospace"
                   id="onboarding_bg_color_text"
                   value="<?= h($settings['onboarding_bg_color']) ?>"
                   maxlength="7" placeholder="#f0f4f8"
                   oninput="document.getElementById('onboarding_bg_color').value=this.value">
          </div>
        </div>
      </div>

      <!-- Custom CSS -->
      <div class="mb-1">
        <label for="onboarding_custom_css" class="form-label fw-semibold">Własny CSS</label>
        <textarea class="form-control font-monospace" id="onboarding_custom_css"
                  name="onboarding_custom_css" rows="8"
                  placeholder="/* Dodatkowe style CSS dla formularza */"><?= h($settings['onboarding_custom_css']) ?></textarea>
        <div class="form-text">Wstrzykiwane do sekcji <code>&lt;style&gt;</code> formularza publicznego.</div>
      </div>

    </div>
  </div>

  <div class="d-flex justify-content-end mb-5">
    <button type="submit" class="btn btn-primary px-4">
      <i class="bi bi-save"></i> Zapisz ustawienia
    </button>
  </div>

</form>

<script>
// sync colour picker → text input
document.getElementById('onboarding_accent_color').addEventListener('input', function() {
    document.getElementById('onboarding_accent_color_text').value = this.value;
});
document.getElementById('onboarding_bg_color').addEventListener('input', function() {
    document.getElementById('onboarding_bg_color_text').value = this.value;
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
