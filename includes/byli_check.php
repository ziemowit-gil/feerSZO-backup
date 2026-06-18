<?php
/**
 * Live-ostrzeżenie przy zawieraniu umowy: sprawdza, czy wpisana strona umowy
 * figuruje w rejestrze byłych współpracowników (moduł byli_enabled).
 *
 * Użycie — wstaw TUŻ POD polem z imieniem i nazwiskiem strony umowy:
 *   <?php require_once '.../includes/byli_check.php'; echo byli_check_field('imie_nazwisko'); ?>
 *
 * Pole jest identyfikowane po atrybucie name="". Sprawdzenie odbywa się:
 *   • przy załadowaniu strony (edycja z wpisaną wartością),
 *   • na bieżąco podczas wpisywania (debounce),
 *   • po wyborze osoby w person_picker (zdarzenie input/change).
 *
 * Zwraca '' gdy moduł byłych osób jest wyłączony — bezpieczne do bezwarunkowego wpięcia.
 */

if (!function_exists('byli_check_field')) {

function byli_check_field(string $fieldName, array $opts = []): string
{
    if (!function_exists('module_enabled') || !module_enabled('byli_enabled')) {
        return '';
    }

    static $assets_rendered = false;

    $app_url = defined('APP_URL') ? APP_URL : '';
    $safe    = preg_replace('/[^a-zA-Z0-9_]/', '_', $fieldName);
    $boxId   = 'byliWarn_' . $safe;

    ob_start();
?>
<div id="<?= htmlspecialchars($boxId, ENT_QUOTES) ?>" class="byli-warn-box" role="alert" aria-live="polite"></div>
<script>
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    if (window.ByliCheck) window.ByliCheck.bind(<?= json_encode($fieldName) ?>, <?= json_encode($boxId) ?>);
  });
}());
</script>
<?php

    if (!$assets_rendered):
        $assets_rendered = true;
?>
<style>
.byli-warn-box:empty { display: none; }
.byli-warn-box { margin-top: .5rem; }
.byli-warn-box .alert { margin-bottom: 0; }
.byli-warn-box .byli-rec { padding: .15rem 0; }
.byli-warn-box .byli-rec + .byli-rec { border-top: 1px dashed var(--bs-warning-border-subtle, #ffe69c); margin-top: .35rem; padding-top: .35rem; }
</style>
<script>
/* ── ByliCheck — live ostrzeżenie o byłym współpracowniku ──────────────────── */
(function () {
  if (window.ByliCheck) return;

  var APP_URL = <?= json_encode($app_url) ?>;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function fmtOkres(it) {
    var od = it.data_od || '', dod = it.data_do || '';
    if (!od && !dod) return '';
    return ' <span class="text-muted small">(współpraca: ' + esc(od || '—') + ' – ' + esc(dod || '—') + ')</span>';
  }

  function render(box, data) {
    if (!data || !data.count) { box.innerHTML = ''; return; }

    var rows = data.items.map(function (it) {
      var head = '<div class="fw-semibold">'
        + '<i class="bi bi-person-exclamation me-1" aria-hidden="true"></i>'
        + esc(it.imie_nazwisko)
        + (it.miasto ? ' <span class="text-muted small">— ' + esc(it.miasto) + '</span>' : '')
        + fmtOkres(it)
        + '</div>';
      var detail = '';
      if (it.powod) {
        detail += '<div class="small">Powód odejścia: <strong>' + esc(it.powod) + '</strong></div>';
      }
      if (it.uwagi) {
        detail += '<div class="small">Uwagi: ' + esc(it.uwagi) + '</div>';
      }
      if (it.sensitive_hidden) {
        detail += '<div class="small text-muted"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>'
          + 'Dane wrażliwe (powód / uwagi) widoczne tylko dla zarządu.</div>';
      }
      return '<div class="byli-rec">' + head + detail + '</div>';
    }).join('');

    box.innerHTML =
      '<div class="alert alert-warning d-flex gap-2 align-items-start py-2 px-3">'
      + '<i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>'
      + '<div class="flex-grow-1">'
      + '<div class="fw-semibold mb-1">Uwaga: osoba figuruje w rejestrze byłych współpracowników.</div>'
      + rows
      + '</div></div>';
  }

  function check(field, box) {
    var name = (field.value || '').trim();
    if (name.length < 3) { box.innerHTML = ''; return; }
    fetch(APP_URL + '/byli/check.php?name=' + encodeURIComponent(name))
      .then(function (r) { return r.json(); })
      .then(function (data) { render(box, data); })
      .catch(function () { /* cicho — to tylko ostrzeżenie pomocnicze */ });
  }

  window.ByliCheck = {
    bind: function (fieldName, boxId) {
      var field = document.querySelector('[name="' + fieldName + '"]');
      var box   = document.getElementById(boxId);
      if (!field || !box || field.dataset.byliBound) return;
      field.dataset.byliBound = '1';

      var timer;
      function schedule() {
        clearTimeout(timer);
        timer = setTimeout(function () { check(field, box); }, 350);
      }
      field.addEventListener('input', schedule);
      field.addEventListener('change', schedule);

      // Sprawdzenie początkowe (np. edycja z wpisaną wartością)
      if ((field.value || '').trim().length >= 3) check(field, box);
    }
  };
}());
</script>
<?php
    endif; // $assets_rendered

    return ob_get_clean();
}

} // function_exists
