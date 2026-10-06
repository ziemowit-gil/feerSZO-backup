<?php
/**
 * modules/zlecenie_inline/ui/inlineForm.php
 * Formularz edycji inline w zakładce „Umowa” podglądu (Bootstrap + klasy cv-*, bez Tailwindu).
 * Oczekuje: $row (umowa), $id, $_zl_inline_errors (array), $_zl_inline_open (bool).
 */
$_zi_fields = zlecenie_inline_fields();
$_zi_vals   = $row;
if (!empty($_zl_inline_post)) $_zi_vals = array_merge($row, $_zl_inline_post); // po błędzie walidacji: wartości z POST
$_zi_users  = [];
try { $_zi_users = db_all("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name"); } catch (\Throwable $e) {}
$_zi_zrodla = [];
foreach (ZL_EW_ZRODLA as $k => $v) $_zi_zrodla[$k] = $v['label'];
$_zi_ctl = 'form-control form-control-sm';
?>
<div class="d-flex justify-content-end mb-2 no-print" id="zl-inline-bar"<?= $_zl_inline_open ? ' style="display:none!important"' : '' ?>>
  <button type="button" class="btn btn-sm btn-outline-primary" id="zl-inline-open" aria-controls="zl-inline-edit" aria-expanded="<?= $_zl_inline_open ? 'true' : 'false' ?>">
    <i class="bi bi-pencil-square me-1"></i>Edytuj w tym oknie
  </button>
</div>

<div id="zl-inline-edit" class="cv-section<?= $_zl_inline_open ? '' : ' d-none' ?>">
  <div class="cv-section-head">
    <div class="cv-section-icon" style="background:#EEF4FF;color:#2563EB"><i class="bi bi-pencil-square"></i></div>
    <span class="cv-section-title">Edycja umowy</span>
    <span class="small text-muted ms-2">status, pliki i dostęp — w <a href="edit.php?id=<?= $id ?>">pełnej edycji</a></span>
  </div>

  <?php if (!empty($_zl_inline_errors)): ?>
  <div class="alert alert-danger py-2 small" role="alert">
    <ul class="mb-0 ps-3"><?php foreach ($_zl_inline_errors as $_e) echo '<li>' . h($_e) . '</li>'; ?></ul>
  </div>
  <?php endif; ?>

  <form method="post" id="zl-inline-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_zl_inline_save" value="1">

    <?php foreach ($_zi_fields as $_sec => $_flds): ?>
    <fieldset class="mb-3">
      <legend class="cv-label" style="font-size:.72rem;letter-spacing:.04em;text-transform:uppercase;color:#64748b;margin-bottom:.4rem"><?= h($_sec) ?></legend>
      <div class="row g-2">
        <?php foreach ($_flds as $_name => $_d):
          $_val = $_zi_vals[$_name] ?? '';
          $_col = !empty($_d['wide']) ? 'col-12' : (!empty($_d['short']) ? 'col-6 col-md-2' : 'col-12 col-md-4');
          $_id  = 'zi-' . $_name;
        ?>
        <div class="<?= $_col ?>">
          <?php if ($_d['type'] === 'checkbox'): ?>
          <div class="form-check mt-md-4">
            <input class="form-check-input" type="checkbox" id="<?= $_id ?>" name="<?= h($_name) ?>" value="1" <?= !empty($_val) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="<?= $_id ?>"><?= h($_d['label']) ?></label>
          </div>
          <?php else: ?>
          <label class="form-label small fw-semibold mb-1" for="<?= $_id ?>"><?= h($_d['label']) ?><?= !empty($_d['required']) ? ' <span class="text-danger">*</span>' : '' ?></label>
            <?php if ($_d['type'] === 'select'):
              $_opts = $_d['options'] === '__zrodla__' ? $_zi_zrodla : ($_d['options'] === '__users__' ? ['' => '— wybierz konto —'] + array_column(array_map(fn($u) => [$u['id'], $u['name'] . ($u['email'] ? ' (' . $u['email'] . ')' : '')], $_zi_users), 1, 0) : $_d['options']); ?>
            <select class="form-select form-select-sm" id="<?= $_id ?>" name="<?= h($_name) ?>"<?= $_name === 'zrodlo_godzin' ? ' onchange="document.getElementById(\'zi-ti_instructor_user_id\').closest(\'div\').style.display = this.value===\'karty30\' ? \'\' : \'none\'"' : '' ?>>
              <?php foreach ($_opts as $_ov => $_ol): ?>
              <option value="<?= h((string)$_ov) ?>" <?= (string)$_val === (string)$_ov ? 'selected' : '' ?>><?= h($_ol) ?></option>
              <?php endforeach; ?>
            </select>
            <?php elseif ($_d['type'] === 'textarea'): ?>
            <textarea class="<?= $_zi_ctl ?>" id="<?= $_id ?>" name="<?= h($_name) ?>" rows="<?= (int)($_d['rows'] ?? 3) ?>"><?= h($_val) ?></textarea>
            <?php else: ?>
            <input class="<?= $_zi_ctl ?><?= !empty($_d['mono']) ? ' font-monospace' : '' ?>" id="<?= $_id ?>" name="<?= h($_name) ?>" type="<?= h($_d['type']) ?>" value="<?= h($_val) ?>"
                   <?= isset($_d['step']) ? 'step="' . h($_d['step']) . '"' : '' ?> <?= isset($_d['maxlength']) ? 'maxlength="' . (int)$_d['maxlength'] . '"' : '' ?> <?= isset($_d['inputmode']) ? 'inputmode="' . h($_d['inputmode']) . '"' : '' ?> <?= !empty($_d['required']) ? 'required' : '' ?>>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <?php endforeach; ?>

    <div class="d-flex flex-wrap gap-2 justify-content-end pt-2" style="border-top:1px solid #E2E8F0">
      <button type="button" class="btn btn-sm btn-outline-secondary" id="zl-inline-close"><i class="bi bi-x-lg me-1"></i>Anuluj</button>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz zmiany</button>
    </div>
  </form>
</div>

<script>
(function () {
  var open  = document.getElementById('zl-inline-open');
  var close = document.getElementById('zl-inline-close');
  var box   = document.getElementById('zl-inline-edit');
  var bar   = document.getElementById('zl-inline-bar');
  if (!open || !box) return;
  function show() { box.classList.remove('d-none'); bar.style.setProperty('display', 'none', 'important'); open.setAttribute('aria-expanded', 'true'); box.scrollIntoView({ behavior: 'smooth', block: 'start' }); var f = box.querySelector('input,select,textarea'); if (f) f.focus(); }
  function hide() { box.classList.add('d-none'); bar.style.removeProperty('display'); open.setAttribute('aria-expanded', 'false'); }
  open.addEventListener('click', show);
  if (close) close.addEventListener('click', hide);
  // „Edytuj” w nagłówku umowy otwiera edycję w tym oknie (Ctrl/⌘/środkowy klik → pełna edycja)
  document.querySelectorAll('.contract-hero-actions a[href^="edit.php"]').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) return;
      ev.preventDefault();
      var tabBtn = document.getElementById('tab-umowa-btn');
      if (tabBtn && window.bootstrap) bootstrap.Tab.getOrCreateInstance(tabBtn).show();
      show();
    });
  });
  var zr = document.getElementById('zi-zrodlo_godzin');
  if (zr) zr.dispatchEvent(new Event('change'));
})();
</script>
