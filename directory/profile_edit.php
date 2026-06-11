<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/directory.php';

require_login();
directory_migrate();

$cu = current_user();

// Admin może edytować każdy profil przez ?id=X
$target_id = (int)($cu['id'] ?? 0);
if (is_admin() && !empty($_GET['id'])) {
    $target_id = (int)$_GET['id'];
}
if ($target_id !== (int)$cu['id'] && !is_admin()) {
    header('Location: ' . APP_URL . '/directory/profile_edit.php');
    exit;
}

$person     = directory_get_profile($target_id);
if (!$person) { header('Location: ' . APP_URL . '/directory/'); exit; }

$field_defs = directory_get_field_defs();
$errors     = [];
$is_own     = $target_id === (int)$cu['id'];

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $bio       = trim($_POST['bio'] ?? '');
    $phone_pub = isset($_POST['phone_public']) ? 1 : 0;

    // Numer telefonu do SMS — zapis w users.phone_number
    $phone_new = preg_replace('/[^\d+]/', '', trim($_POST['phone_number'] ?? ''));
    if ($phone_new !== '' && strlen($phone_new) < 9) {
        $errors[] = ['field' => 'phone_number', 'msg' => 'Numer telefonu jest za krótki (min. 9 cyfr).'];
    }

    // Umiejętności — z ukrytego pola JSON (chip-input w JS)
    $skills_arr = [];
    $skills_json_post = trim($_POST['skills_json'] ?? '');
    if ($skills_json_post !== '') {
        $decoded = json_decode($skills_json_post, true);
        if (is_array($decoded)) {
            $skills_arr = array_values(array_filter(array_map('trim', $decoded)));
        }
    }

    if (mb_strlen($bio) > 1000) {
        $errors[] = ['field' => 'bio', 'msg' => 'Bio nie może przekraczać 1000 znaków.'];
    }

    if (empty($errors)) {
        $custom = [];
        foreach ($field_defs as $fd) {
            $key = 'field_' . (int)$fd['id'];
            $custom[(int)$fd['id']] = trim($_POST[$key] ?? '');
        }
        directory_save_profile($target_id, [
            'bio'           => $bio,
            'phone_public'  => $phone_pub,
            'skills'        => $skills_arr,
            'custom_fields' => $custom,
        ]);
        // Zapisz numer telefonu w tabeli users
        try {
            db()->prepare("UPDATE users SET phone_number=? WHERE id=?")
                ->execute([$phone_new, $target_id]);
        } catch (\Throwable $e) {}
        flash_set('success', 'Profil został zapisany.');
        header('Location: ' . APP_URL . '/directory/profile.php?id=' . $target_id);
        exit;
    }

    // Reload z danymi POST na potrzeby podglądu po błędzie
    $person['bio']          = $bio;
    $person['phone_public'] = $phone_pub;
    $person['skills_array'] = $skills_arr;
    $person['phone_number'] = $phone_new;
}

$display           = directory_display_name($person);
$skills_json_value = json_encode($person['skills_array'] ?? [], JSON_UNESCAPED_UNICODE);
$PAGE_TITLE        = $is_own ? 'Mój profil — edycja' : 'Edycja profilu: ' . $display;

// Build a map of field errors for quick lookup
$field_errors = [];
foreach ($errors as $e) {
    if (isset($e['field'])) {
        $field_errors[$e['field']] = $e['msg'];
    }
}

include __DIR__ . '/includes/header_dir.php';
?>

<nav aria-label="Nawigacja strony" class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/directory/profile.php?id=<?= $target_id ?>"
     class="btn btn-sm btn-outline-secondary"
     aria-label="Wróć do profilu użytkownika <?= h($display) ?>">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do profilu
  </a>
  <div class="d-flex align-items-center gap-2 ms-1">
    <?php
    $avatar_html = directory_avatar_html($person, 32);
    if (strpos($avatar_html, '<img') !== false) {
        $avatar_html = preg_replace(
            '/<img\b([^>]*?)(?:\s+alt="[^"]*")?([^>]*?)>/',
            '<img$1 alt="' . h($display) . '"$2>',
            $avatar_html
        );
    } elseif (strpos($avatar_html, '<span') !== false) {
        $avatar_html = preg_replace(
            '/<span\b/',
            '<span aria-label="' . h($display) . '"',
            $avatar_html,
            1
        );
    }
    echo $avatar_html;
    ?>
    <span class="fw-semibold" aria-hidden="true"><?= h($display) ?></span>
  </div>
</nav>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" role="alert" aria-live="assertive" id="formErrors">
  <h2 class="h6 mb-2">
    <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>Wystąpiły błędy w formularzu
  </h2>
  <ul class="mb-0 ps-3">
    <?php foreach ($errors as $e): ?>
    <li id="err-<?= h(is_array($e) ? $e['field'] : 'general') ?>">
      <?= h(is_array($e) ? $e['msg'] : $e) ?>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<!-- ── Zdjęcie profilowe ───────────────────────────────────────────────────── -->
<div class="dir-info-card mb-3" style="max-width:720px">
  <div class="h6 mb-3">
    <i class="bi bi-person-circle me-2" aria-hidden="true" style="color:var(--dir-primary)"></i>Zdjęcie profilowe
  </div>

  <div class="d-flex align-items-center gap-4 flex-wrap">
    <!-- Podgląd aktualnego zdjęcia -->
    <div class="text-center">
      <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:#9CA3AF;margin-bottom:.4rem">Aktualne</div>
      <?= directory_avatar_html($person, 72) ?>
    </div>

    <?php
    $has_pending  = !empty($person['avatar_pending_file']);
    $has_approved = !empty($person['avatar_file']);
    $status       = $person['avatar_status'] ?? '';
    ?>

    <!-- Status oczekującego -->
    <?php if ($has_pending): ?>
    <div class="text-center">
      <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:#9CA3AF;margin-bottom:.4rem">Oczekuje na akceptację</div>
      <div class="position-relative d-inline-block">
        <img src="<?= APP_URL ?>/directory/avatar_thumb.php?uid=<?= $target_id ?>&t=pending"
             alt="Oczekujące zdjęcie"
             class="rounded-circle"
             style="width:72px;height:72px;object-fit:cover;border:2px solid #F59E0B">
        <span class="position-absolute top-0 end-0 badge rounded-pill bg-warning text-dark"
              style="font-size:.6rem;transform:translate(30%,-30%)"
              title="Oczekuje na akceptację administratora">
          <i class="bi bi-hourglass-split"></i>
        </span>
      </div>
    </div>
    <?php elseif ($status === 'rejected'): ?>
    <div class="alert alert-warning py-2 px-3 mb-0 small d-flex align-items-center gap-2" role="alert">
      <i class="bi bi-x-circle-fill text-danger"></i>
      <span>Ostatnie zdjęcie zostało <strong>odrzucone</strong> przez administratora. Możesz przesłać nowe.</span>
    </div>
    <?php endif; ?>

    <!-- Formularz uploadu -->
    <div class="flex-grow-1">
      <form method="post" enctype="multipart/form-data"
            action="<?= APP_URL ?>/directory/avatar_upload.php">
        <?= csrf_field() ?>
        <?php if ($target_id !== (int)$cu['id']): ?>
        <input type="hidden" name="user_id" value="<?= $target_id ?>">
        <?php endif; ?>
        <div class="mb-2">
          <label for="avatarFile" class="form-label fw-semibold" style="font-size:.85rem">
            <?= $has_pending ? 'Zastąp oczekujące zdjęcie' : ($has_approved ? 'Zmień zdjęcie' : 'Dodaj zdjęcie') ?>
          </label>
          <input type="file" class="form-control form-control-sm" id="avatarFile" name="avatar"
                 accept="image/jpeg,image/png,image/webp,image/gif"
                 aria-describedby="avatarHint">
          <div id="avatarHint" class="form-text">
            JPG, PNG, WebP lub GIF · max 5 MB · po przesłaniu czeka na akceptację administratora
          </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button type="submit" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-upload me-1"></i>Prześlij zdjęcie
          </button>
          <?php if ($has_approved || $has_pending): ?>
          <button type="submit" name="_remove_avatar" value="1"
                  class="btn btn-sm btn-outline-danger"
                  onclick="return confirm('Usunąć zdjęcie profilowe? Ta operacja jest nieodwracalna.')">
            <i class="bi bi-trash me-1"></i>Usuń zdjęcie
          </button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<form method="post" id="profileForm" style="max-width:720px"
      aria-describedby="formDesc">
  <p id="formDesc" class="visually-hidden">
    Formularz edycji profilu użytkownika <?= h($display) ?>. Pola oznaczone jako wymagane muszą być wypełnione przed zapisem.
  </p>
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <!-- ── O mnie ──────────────────────────────────────────────────────── -->
  <fieldset class="dir-info-card mb-3">
    <legend class="h6 mb-2">
      <i class="bi bi-person-vcard me-2" aria-hidden="true" style="color:var(--dir-primary)"></i>O mnie
    </legend>
    <label class="form-label text-muted" style="font-size:.82rem" for="bio">
      Krótki opis / bio
    </label>
    <textarea id="bio" name="bio" class="form-control" rows="5" maxlength="1000"
              placeholder="Kilka słów o sobie, zainteresowaniach, zadaniach w organizacji…"
              style="font-size:.9rem;resize:vertical"
              aria-describedby="bioHint<?= isset($field_errors['bio']) ? ' err-bio' : '' ?>"
              <?= isset($field_errors['bio']) ? 'aria-invalid="true"' : '' ?>><?= h($person['bio']) ?></textarea>
    <div id="bioHint" class="d-flex justify-content-between mt-1" style="font-size:.75rem;color:#9CA3AF">
      <span>Maksymalnie 1000 znaków.</span>
      <span>
        <output id="bioCount" for="bio" aria-live="polite"><?= mb_strlen($person['bio']) ?></output>/1000
      </span>
    </div>
  </fieldset>

  <!-- ── Umiejętności ────────────────────────────────────────────────── -->
  <fieldset class="dir-info-card mb-3">
    <legend class="h6 mb-2">
      <i class="bi bi-stars me-2" aria-hidden="true" style="color:#F59E0B"></i>Umiejętności
    </legend>
    <p id="skillsHint" class="form-label text-muted mb-2" style="font-size:.82rem">
      Wpisz umiejętność i naciśnij <kbd>Enter</kbd> lub przecinek. Kliknij × przy tagu, aby go usunąć.
    </p>
    <input type="hidden" name="skills_json" id="skillsJson" value="<?= h($skills_json_value) ?>">
    <div role="group"
         aria-labelledby="skillsGroupLabel"
         aria-describedby="skillsHint">
      <span id="skillsGroupLabel" class="visually-hidden">Lista umiejętności</span>
      <div id="skillsContainer"
           class="d-flex flex-wrap gap-2 align-items-center p-2 rounded"
           style="border:1px solid #D1D5DB;min-height:44px;cursor:text;background:#fff">
        <input type="text" id="skillsInput" class="flex-grow-1 border-0"
               style="outline:none;font-size:.88rem;min-width:180px;background:transparent"
               placeholder="np. Excel, zarządzanie projektami, grafika…"
               aria-label="Wpisz nową umiejętność"
               autocomplete="off">
      </div>
    </div>
  </fieldset>

  <!-- ── Numer telefonu ────────────────────────────────────────────────── -->
  <?php
  $_ph_contract = $person['phone_direct'] ?: $person['phone_mobile'] ?: '';
  $_ph_account  = $person['phone_number'] ?? '';
  $_ph_err      = $field_errors['phone_number'] ?? null;
  ?>
  <fieldset class="dir-info-card mb-3">
    <legend class="h6 mb-2">
      <i class="bi bi-phone me-2 text-success" aria-hidden="true"></i>Numer telefonu
    </legend>

    <!-- Pole do wpisania / zmiany numeru -->
    <div class="mb-2">
      <label class="form-label" for="phone_number" style="font-size:.85rem;font-weight:600">
        Numer do logowania kodem SMS
      </label>
      <input type="tel"
             id="phone_number"
             name="phone_number"
             class="form-control<?= $_ph_err ? ' is-invalid' : '' ?>"
             style="font-size:.92rem;max-width:260px"
             value="<?= h($_ph_account) ?>"
             placeholder="np. 48123456789 lub 123456789"
             autocomplete="tel"
             inputmode="tel"
             <?= $_ph_err ? 'aria-invalid="true" aria-describedby="phone-err"' : 'aria-describedby="phone-hint"' ?>>
      <?php if ($_ph_err): ?>
      <div id="phone-err" class="invalid-feedback"><?= h($_ph_err) ?></div>
      <?php else: ?>
      <div id="phone-hint" style="font-size:.78rem;color:#6B7280;margin-top:.3rem">
        <i class="bi bi-shield-check me-1 text-success" aria-hidden="true"></i>
        Ten numer pozwoli Ci <strong>logować się kodem SMS</strong> zamiast hasłem.
        Zostaw puste, żeby usunąć.
      </div>
      <?php endif; ?>
    </div>

    <?php if ($_ph_contract && $_ph_contract !== $_ph_account): ?>
    <!-- Numer z umowy (tylko informacja, nie nadpisujemy) -->
    <div style="font-size:.8rem;color:#6B7280;margin-bottom:.65rem;padding:.5rem .65rem;background:#F9FAFB;border-radius:6px;border:1px solid #E5E7EB">
      <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>
      Numer z umowy (niedostępny do edycji): <strong><?= h($_ph_contract) ?></strong>
    </div>
    <?php endif; ?>

    <!-- Widoczność w katalogu -->
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" id="phone_public" name="phone_public"
             role="switch"
             aria-checked="<?= $person['phone_public'] ? 'true' : 'false' ?>"
             <?= $person['phone_public'] ? 'checked' : '' ?>>
      <label class="form-check-label" style="font-size:.85rem" for="phone_public">
        Pokazuj mój numer telefonu innym użytkownikom katalogu
      </label>
    </div>
  </fieldset>

  <!-- ── Pola własne (definiowane przez admina) ──────────────────────── -->
  <?php if ($field_defs): ?>
  <fieldset class="dir-info-card mb-3">
    <legend class="h6 mb-2">
      <i class="bi bi-card-list me-2 text-secondary" aria-hidden="true"></i>Dodatkowe informacje
    </legend>
    <?php foreach ($field_defs as $fd):
        $key     = 'field_' . (int)$fd['id'];
        $cur_val = $person['field_values'][(int)$fd['id']] ?? '';
        $err_id  = 'err-' . h($key);
        $has_err = isset($field_errors[$key]);
    ?>
    <div class="mb-3">
      <label class="form-label" for="<?= h($key) ?>" style="font-size:.85rem;font-weight:600"><?= h($fd['label']) ?></label>
      <?php if ($fd['field_type'] === 'textarea'): ?>
        <textarea id="<?= h($key) ?>" name="<?= h($key) ?>" class="form-control" rows="3" style="font-size:.88rem"
                  <?= $has_err ? 'aria-invalid="true" aria-describedby="' . $err_id . '"' : '' ?>><?= h($cur_val) ?></textarea>
      <?php elseif ($fd['field_type'] === 'url'): ?>
        <input type="url" id="<?= h($key) ?>" name="<?= h($key) ?>" class="form-control" style="font-size:.88rem"
               value="<?= h($cur_val) ?>" placeholder="https://"
               <?= $has_err ? 'aria-invalid="true" aria-describedby="' . $err_id . '"' : '' ?>>
      <?php elseif ($fd['field_type'] === 'select'): ?>
        <?php $opts = json_decode($fd['options'] ?? '[]', true); if (!is_array($opts)) $opts = []; ?>
        <select id="<?= h($key) ?>" name="<?= h($key) ?>" class="form-select" style="font-size:.88rem"
                <?= $has_err ? 'aria-invalid="true" aria-describedby="' . $err_id . '"' : '' ?>>
          <option value="">— wybierz —</option>
          <?php foreach ($opts as $opt): ?>
          <option value="<?= h($opt) ?>" <?= $cur_val === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="text" id="<?= h($key) ?>" name="<?= h($key) ?>" class="form-control" style="font-size:.88rem"
               value="<?= h($cur_val) ?>"
               <?= $has_err ? 'aria-invalid="true" aria-describedby="' . $err_id . '"' : '' ?>>
      <?php endif; ?>
      <?php if ($has_err): ?>
      <div id="<?= $err_id ?>" class="invalid-feedback d-block" role="alert"><?= h($field_errors[$key]) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </fieldset>
  <?php endif; ?>

  <div class="d-flex gap-2">
    <button type="submit" class="btn px-4" style="background:var(--dir-primary);color:#fff;font-weight:600">
      <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz profil
    </button>
    <a href="<?= APP_URL ?>/directory/profile.php?id=<?= $target_id ?>"
       class="btn btn-outline-secondary">
      Anuluj
    </a>
  </div>
</form>

<script>
(function () {
  // Bio counter
  var bio = document.getElementById('bio');
  var cnt = document.getElementById('bioCount');
  if (bio && cnt) {
    bio.addEventListener('input', function () {
      cnt.textContent = bio.value.length;
    });
  }

  // Sync aria-checked on phone toggle
  var phoneToggle = document.getElementById('phone_public');
  if (phoneToggle) {
    phoneToggle.addEventListener('change', function () {
      this.setAttribute('aria-checked', this.checked ? 'true' : 'false');
    });
  }

  // Skills chip-input
  var skills = <?= $skills_json_value ?: '[]' ?>;
  var container = document.getElementById('skillsContainer');
  var input     = document.getElementById('skillsInput');
  var hidden    = document.getElementById('skillsJson');

  function esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }

  function render() {
    container.querySelectorAll('.sk-chip').forEach(function (c) { c.remove(); });
    skills.forEach(function (s, i) {
      var chip = document.createElement('span');
      chip.className = 'sk-chip dir-skill-tag';
      chip.style.cursor = 'default';
      // role="button" + aria-label for screen readers
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.setAttribute('aria-label', 'Usuń: ' + s);
      btn.style.cssText = 'border:none;background:none;padding:0 0 0 .2rem;font-size:.75rem;line-height:1;color:inherit;opacity:.7';
      btn.textContent = '×';
      btn.addEventListener('click', (function(idx) {
        return function () { skills.splice(idx, 1); render(); };
      })(i));

      chip.appendChild(document.createTextNode(s + ' '));
      chip.appendChild(btn);
      container.insertBefore(chip, input);
    });
    hidden.value = JSON.stringify(skills);
  }

  function add(s) { s = s.trim(); if (s && !skills.includes(s)) { skills.push(s); render(); } }

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      add(input.value.replace(/,/g, ''));
      input.value = '';
    } else if (e.key === 'Backspace' && !input.value && skills.length) {
      skills.pop(); render();
    }
  });
  input.addEventListener('blur', function () { if (input.value.trim()) { add(input.value); input.value = ''; } });
  container.addEventListener('click', function () { input.focus(); });

  render();
})();
</script>

<?php include __DIR__ . '/includes/footer_dir.php'; ?>
