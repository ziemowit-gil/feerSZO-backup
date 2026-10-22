<?php
/**
 * modules/zlecenie_inline/ui/inlineForm.php
 * Formularz edycji inline w zakładce „Umowa” podglądu (Bootstrap + klasy cv-*, bez Tailwindu).
 * Każda grupa pól = własna sekcja cv-section z ikoną, jak sekcje podglądu.
 * Oczekuje: $row (umowa), $id, $_zl_inline_errors (array), $_zl_inline_open (bool), $_zl_inline_post (array).
 */
$_zi_fields = zlecenie_inline_fields();
$_zi_vals   = $row;
if (!empty($_zl_inline_post)) $_zi_vals = array_merge($row, $_zl_inline_post); // po błędzie walidacji: wartości z POST
$_zi_users  = [];
try { $_zi_users = db_all("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name"); } catch (\Throwable $e) {}
$_zi_zrodla = [];
foreach (ZL_EW_ZRODLA as $k => $v) $_zi_zrodla[$k] = $v['label'];
$_zi_units = ['' => '— wybierz —'];
try { foreach (db_all("SELECT id, name FROM org_units WHERE status='active' ORDER BY sort_order, name") as $_ou) $_zi_units[$_ou['id']] = $_ou['name']; } catch (\Throwable $e) {}
require_once dirname(__DIR__, 3) . '/includes/person_picker.php';
require_once dirname(__DIR__, 3) . '/includes/contract_access.php';

/** Ikona i kolor sekcji — te same co w podglądzie umowy. */
$_zi_icons = [
    'Dane podstawowe'                     => ['bi-file-text-fill', '#EEF4FF', '#2563EB'],
    'Zleceniobiorca'                      => ['bi-person-fill',    '#F0FDF4', '#16A34A'],
    'Wynagrodzenie i podatek'             => ['bi-cash-coin',      '#FFF7ED', '#EA580C'],
    'Ewidencja godzin'                    => ['bi-calendar-week',  '#F0F9FF', '#0284C7'],
    'ZUS — rejestracja i wyrejestrowanie' => ['bi-shield-check',   '#F5F3FF', '#7C3AED'],
    'Rachunek'                            => ['bi-receipt',        '#FFF7ED', '#C2410C'],
    'Podpisanie'                          => ['bi-pen',            '#FDF2F8', '#DB2777'],
    'Instytucja szkoleniowa (IS)'           => ['bi-building-check', '#EEF4FF', '#1D4ED8'],
    'Microsoft 365'                       => ['bi-microsoft',      '#F0F9FF', '#0369A1'],
    'Dostęp'                              => ['bi-people',         '#F1F5F9', '#334155'],
    'Numery referencyjne'                 => ['bi-hash',           '#F8FAFC', '#475569'],
    'Uwagi'                               => ['bi-chat-left-text', '#FEFCE8', '#CA8A04'],
];
/** Pola, które globalny app.js chowa razem z najbliższym .mb-3/.row — dostają własny wrapper .mb-3. */
$_zi_wrap = ['platforma_el', 'id_dokumentu_el'];

$_zi_col = function (array $d): string {
    if (!empty($d['wide']))  return 'col-12';
    if (!empty($d['short'])) return 'col-6 col-md-2';
    if (in_array($d['type'], ['date', 'number', 'checkbox'], true)) return 'col-6 col-md-3';
    return 'col-12 col-md-4';
};
?>
<style>
  #zl-inline-edit .zi-section + .zi-section { margin-top: .9rem; }
  #zl-inline-edit .form-label { font-size: .74rem; font-weight: 600; letter-spacing: .02em; color: #475569; margin-bottom: .2rem; }
  #zl-inline-edit .form-control, #zl-inline-edit .form-select { font-size: .86rem; }
  #zl-inline-edit .form-check-label { font-size: .86rem; color: #334155; }
  #zl-inline-edit .zi-actions { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #E2E8F0; padding: .6rem 0 .1rem; margin-top: .9rem; z-index: 2; }
  #zl-inline-edit .zi-req { color: #DC2626; }
</style>

<div class="d-flex justify-content-end mb-2 no-print" id="zl-inline-bar"<?= $_zl_inline_open ? ' style="display:none!important"' : '' ?>>
  <button type="button" class="btn btn-sm btn-outline-primary" id="zl-inline-open" aria-controls="zl-inline-edit" aria-expanded="<?= $_zl_inline_open ? 'true' : 'false' ?>">
    <i class="bi bi-pencil-square me-1"></i>Edytuj w tym oknie
  </button>
</div>

<div id="zl-inline-edit" class="<?= $_zl_inline_open ? '' : 'd-none' ?>">
  <form method="post" id="zl-inline-form" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_zl_inline_save" value="1">

    <!-- Pasek edycji -->
    <div class="cv-section zi-section">
      <div class="cv-section-head flex-wrap gap-2">
        <div class="cv-section-icon" style="background:#EEF4FF;color:#2563EB"><i class="bi bi-pencil-square"></i></div>
        <span class="cv-section-title">Edycja umowy <?= h($row['numer_umowy']) ?></span>
        <div class="ms-auto d-flex gap-2">
          <button type="button" class="btn btn-sm btn-outline-secondary zl-inline-close"><i class="bi bi-x-lg me-1"></i>Anuluj</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz zmiany</button>
        </div>
      </div>
      <?php if (!empty($_zl_inline_errors)): ?>
      <div class="alert alert-danger py-2 small mb-0" role="alert">
        <ul class="mb-0 ps-3"><?php foreach ($_zl_inline_errors as $_e) echo '<li>' . h($_e) . '</li>'; ?></ul>
      </div>
      <?php else: ?>
      <div class="small text-muted">Pola oznaczone <span class="zi-req">*</span> są wymagane. Zmiany trafiają do historii umowy.</div>
      <?php endif; ?>
    </div>

    <?php foreach ($_zi_fields as $_sec => $_flds): [$_ico, $_bg, $_fg] = $_zi_icons[$_sec] ?? ['bi-card-list', '#F8FAFC', '#475569']; ?>
    <div class="cv-section zi-section">
      <div class="cv-section-head">
        <div class="cv-section-icon" style="background:<?= $_bg ?>;color:<?= $_fg ?>"><i class="bi <?= $_ico ?>"></i></div>
        <span class="cv-section-title"><?= h($_sec) ?></span>
      </div>
      <div class="row g-2">
        <?php foreach ($_flds as $_name => $_d):
          $_val = $_zi_vals[$_name] ?? '';
          $_id  = 'zi-' . $_name;
          $_wrap = in_array($_name, $_zi_wrap, true);
        ?>
        <div class="<?= $_zi_col($_d) ?>" id="zi-col-<?= h($_name) ?>">
          <?php if ($_wrap): ?><div class="mb-3 mb-0"><?php endif; ?>
          <?php if ($_d['type'] === 'person'): ?>
            <?= person_picker($_zi_vals, ['id' => 'zipp', 'label' => $_d['label'], 'fill' => [
                  'imie_nazwisko' => 'zi-imie_nazwisko', 'pesel' => 'zi-pesel', 'email' => 'zi-email', 'seria_nr_dowodu' => 'zi-seria_nr_dowodu',
                  'urzad_skarbowy' => 'zi-urzad_skarbowy', 'rachunek_bankowy' => 'zi-rachunek_bankowy']]) ?>
            <div class="form-text">Wybierz osobę z rejestru — puste pola (imię, PESEL, e-mail, nr konta…) uzupełnią się automatycznie.</div>
          <?php elseif ($_d['type'] === 'access'): ?>
            <input type="hidden" name="_zi_access" value="1">
            <?= contract_access_field_html(contract_access_user_ids('zlecenie', $id)) ?>
          <?php elseif ($_d['type'] === 'file'): ?>
            <label class="form-label" for="<?= $_id ?>"><?= h($_d['label']) ?></label>
            <input class="form-control form-control-sm" type="file" id="<?= $_id ?>" name="<?= h($_name) ?>" accept="<?= h($_d['accept'] ?? '') ?>">
            <div class="form-text"><?= !empty($row[$_name]) ? 'Jest plik: ' . upload_link_signed($row[$_name]) . ' — wybierz nowy, żeby go zastąpić.' : 'Brak pliku.' ?></div>
          <?php elseif ($_d['type'] === 'checkbox'): ?>
          <div class="form-check mt-md-4 pt-md-1">
            <input class="form-check-input" type="checkbox" id="<?= $_id ?>" name="<?= h($_name) ?>" value="1" <?= !empty($_val) ? 'checked' : '' ?>>
            <label class="form-check-label" for="<?= $_id ?>"><?= h($_d['label']) ?></label>
          </div>
          <?php else: ?>
          <label class="form-label" for="<?= $_id ?>"><?= h($_d['label']) ?><?= !empty($_d['required']) ? ' <span class="zi-req">*</span>' : '' ?></label>
            <?php if ($_d['type'] === 'select'):
              $_opts = $_d['options'] === '__zrodla__' ? $_zi_zrodla
                     : ($_d['options'] === '__status__' ? zlecenie_inline_status_options((string)$row['status'])
                     : ($_d['options'] === '__units__' ? $_zi_units
                     : ($_d['options'] === '__users__' ? ['' => '— wybierz konto —'] + array_column(array_map(fn($u) => [$u['id'], $u['name'] . ($u['email'] ? ' (' . $u['email'] . ')' : '')], $_zi_users), 1, 0)
                     : $_d['options']))); ?>
            <select class="form-select form-select-sm" id="<?= $_id ?>" name="<?= h($_name) ?>">
              <?php foreach ($_opts as $_ov => $_ol): ?>
              <option value="<?= h((string)$_ov) ?>" <?= (string)$_val === (string)$_ov ? 'selected' : '' ?>><?= h($_ol) ?></option>
              <?php endforeach; ?>
            </select>
            <?php elseif ($_d['type'] === 'textarea'): ?>
            <textarea class="form-control form-control-sm" id="<?= $_id ?>" name="<?= h($_name) ?>" rows="<?= (int)($_d['rows'] ?? 3) ?>"><?= h($_val) ?></textarea>
            <?php else: ?>
            <input class="form-control form-control-sm<?= !empty($_d['mono']) ? ' font-monospace' : '' ?>" id="<?= $_id ?>" name="<?= h($_name) ?>" type="<?= h($_d['type']) ?>" value="<?= h($_val) ?>"
                   <?= isset($_d['step']) ? 'step="' . h($_d['step']) . '"' : '' ?> <?= isset($_d['maxlength']) ? 'maxlength="' . (int)$_d['maxlength'] . '"' : '' ?> <?= isset($_d['inputmode']) ? 'inputmode="' . h($_d['inputmode']) . '"' : '' ?> <?= !empty($_d['required']) ? 'required' : '' ?>>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($_wrap): ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="zi-actions d-flex flex-wrap gap-2 justify-content-end">
      <button type="button" class="btn btn-sm btn-outline-secondary zl-inline-close"><i class="bi bi-x-lg me-1"></i>Anuluj</button>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz zmiany</button>
    </div>
  </form>
</div>

<script>
(function () {
  var open  = document.getElementById('zl-inline-open');
  var box   = document.getElementById('zl-inline-edit');
  var bar   = document.getElementById('zl-inline-bar');
  if (!open || !box) return;
  // Podczas edycji chowamy sekcje podglądu tej zakładki (nie dublujemy danych pod formularzem)
  function siblings() { return Array.prototype.filter.call(box.parentElement.children, function (el) { return el !== box && el !== bar && el.tagName !== 'SCRIPT' && el.tagName !== 'STYLE'; }); }
  function show() { box.classList.remove('d-none'); bar.style.setProperty('display', 'none', 'important'); open.setAttribute('aria-expanded', 'true'); siblings().forEach(function (el) { el.classList.add('zi-hidden'); el.style.display = 'none'; }); box.scrollIntoView({ behavior: 'smooth', block: 'start' }); var f = box.querySelector('input:not([type=hidden]),select,textarea'); if (f) f.focus({ preventScroll: true }); }
  function hide() { box.classList.add('d-none'); bar.style.removeProperty('display'); open.setAttribute('aria-expanded', 'false'); siblings().forEach(function (el) { el.classList.remove('zi-hidden'); el.style.display = ''; }); window.scrollTo({ top: 0, behavior: 'smooth' }); }
  open.addEventListener('click', show);
  if (!box.classList.contains('d-none')) siblings().forEach(function (el) { el.style.display = 'none'; }); // otwarty po błędzie walidacji
  box.querySelectorAll('.zl-inline-close').forEach(function (b) { b.addEventListener('click', hide); });
  // „Edytuj” w nagłówku umowy otwiera edycję w tym oknie (to jedyny edytor — osobny edit.php przekierowuje tu)
  document.querySelectorAll('.contract-hero-actions a[href*="edit=1"], .contract-hero-actions a[href^="edit.php"]').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) return;
      ev.preventDefault();
      var tabBtn = document.getElementById('tab-umowa-btn');
      if (tabBtn && window.bootstrap) bootstrap.Tab.getOrCreateInstance(tabBtn).show();
      show();
    });
  });
  // Prowadzący przy źródle „Karty30” albo przy zaznaczonym „Dydaktyka — udostępnij dane do modułu”
  var zr = document.getElementById('zi-zrodlo_godzin'), ti = document.getElementById('zi-col-ti_instructor_user_id'),
      dy = document.getElementById('zi-dydaktyka_udostepnij');
  if (zr && ti) {
    var upd = function () { ti.style.display = (zr.value === 'karty30' || (dy && dy.checked)) ? '' : 'none'; };
    zr.addEventListener('change', upd); if (dy) dy.addEventListener('change', upd); upd();
  }
})();
</script>
