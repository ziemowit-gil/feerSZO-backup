<?php
/**
 * Person Picker — reużywalny widget z autouzupełnianiem, modalem przeglądania
 * i modalem dodawania nowej osoby.
 *
 * Użycie:
 *   require_once '.../includes/person_picker.php';
 *   echo person_picker($row, [
 *     'id'          => 'pp',           // prefix ID (unikalne na stronie)
 *     'fill'        => [               // person field → element ID do uzupełnienia
 *       'imie_nazwisko' => 'f_imie_nazwisko',
 *       'pesel'         => 'f_pesel',
 *       'email'         => 'f_email',
 *       'telefon'       => 'f_telefon',
 *       'data_urodzenia'=> 'f_data_urodzenia',
 *     ],
 *     'addr_widget' => 'mainAddrWidget',  // ID diva address_widget do uzupełnienia
 *     'on_select_js'=> 'updateSummary();',// JS wstrzyknięty po wyborze osoby
 *     'label'       => 'Wyszukaj osobę',
 *   ]);
 *
 * Modale (ppBrowseModal, ppAddModal) renderowane raz na stronę.
 */

function person_picker(array $row = [], array $opts = []): string
{
    static $modals_rendered   = false;
    static $js_rendered       = false;
    static $instance_count    = 0;
    $instance_count++;

    $pid         = $opts['id']          ?? ('pp' . $instance_count);
    $label       = $opts['label']       ?? 'Wyszukaj istniejącą osobę';
    $fill        = $opts['fill']        ?? [];
    $addr_widget = $opts['addr_widget'] ?? '';
    $on_select   = $opts['on_select_js'] ?? '';
    $app_url     = defined('APP_URL') ? APP_URL : '';

    $cur_pid     = h($row['person_id']    ?? '');
    $cur_name    = h($row['_person_name'] ?? '');
    $has_person  = !empty($row['person_id']);

    // JSON trafia do <script> — NIE używamy htmlspecialchars (to niszczy cudzysłowy).
    // JSON_HEX_TAG zapobiega injection przez </script> w wartościach.
    $fill_json   = json_encode($fill,        JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
    $on_sel_json = json_encode($on_select,   JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
    $addr_json   = json_encode($addr_widget, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
    $url_json    = json_encode($app_url,     JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);

    ob_start();
?>
<!-- ── Person Picker: <?= h($pid) ?> ─────────────────────────────────── -->
<div class="person-picker" id="<?= h($pid) ?>_wrap">

  <label class="form-label fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">
    <i class="bi bi-search me-1" aria-hidden="true"></i><?= h($label) ?>
  </label>

  <!-- ARIA combobox container -->
  <div id="<?= h($pid) ?>_combobox"
       role="combobox"
       aria-expanded="false"
       aria-owns="<?= h($pid) ?>_listbox"
       aria-haspopup="listbox"
       class="position-relative">

    <div class="input-group">
      <span class="input-group-text bg-white border-end-0" aria-hidden="true">
        <i class="bi bi-person-lines-fill text-primary"></i>
      </span>
      <input type="text"
             id="<?= h($pid) ?>_search"
             class="form-control border-start-0 ps-1"
             placeholder="Wpisz imię, nazwisko, PESEL lub e-mail…"
             value="<?= $cur_name ?>"
             autocomplete="off"
             spellcheck="false"
             role="searchbox"
             aria-autocomplete="list"
             aria-controls="<?= h($pid) ?>_listbox"
             aria-expanded="false"
             aria-label="<?= h($label) ?>">

      <!-- Wyczyść -->
      <button type="button"
              id="<?= h($pid) ?>_clear"
              class="btn btn-outline-secondary"
              title="Wyczyść wybór"
              aria-label="Wyczyść wybraną osobę"
              style="display:<?= $has_person ? 'flex' : 'none' ?>;align-items:center">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>

      <!-- Przeglądaj -->
      <button type="button"
              id="<?= h($pid) ?>_browse_btn"
              class="btn btn-outline-secondary"
              data-bs-toggle="modal"
              data-bs-target="#ppBrowseModal"
              data-pp-id="<?= h($pid) ?>"
              aria-label="Przeglądaj listę wszystkich osób">
        <i class="bi bi-people me-1" aria-hidden="true"></i>
        <span class="d-none d-sm-inline">Przeglądaj</span>
      </button>

      <!-- Nowa osoba -->
      <button type="button"
              id="<?= h($pid) ?>_add_btn"
              class="btn btn-outline-primary"
              data-bs-toggle="modal"
              data-bs-target="#ppAddModal"
              data-pp-id="<?= h($pid) ?>"
              aria-label="Dodaj nową osobę do rejestru">
        <i class="bi bi-person-plus me-1" aria-hidden="true"></i>
        <span class="d-none d-sm-inline">Nowa</span>
      </button>
    </div>

    <!-- Listbox wyników -->
    <ul id="<?= h($pid) ?>_listbox"
        role="listbox"
        aria-label="Wyniki wyszukiwania osób"
        class="list-group shadow-sm pp-listbox"
        style="display:none;position:absolute;top:100%;left:0;right:0;z-index:1052;max-height:320px;overflow-y:auto;border-radius:.375rem">
    </ul>
  </div>

  <input type="hidden" name="person_id" id="<?= h($pid) ?>_hidden" value="<?= $cur_pid ?>">

  <!-- Live region dla czytników ekranu -->
  <div id="<?= h($pid) ?>_status"
       role="status"
       aria-live="polite"
       aria-atomic="true"
       class="visually-hidden"></div>

  <!-- Alert potwierdzenia -->
  <div id="<?= h($pid) ?>_alert"
       role="alert"
       class="alert alert-success py-1 px-2 mt-2 small d-flex align-items-center gap-2 <?= $has_person ? '' : 'd-none' ?>"
       <?= $has_person ? '' : 'aria-hidden="true"' ?>>
    <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>
    <span>Dane uzupełnione z rejestru osób. Sprawdź i zatwierdź.</span>
    <button type="button"
            class="btn-close btn-sm ms-auto"
            id="<?= h($pid) ?>_alert_close"
            aria-label="Zamknij powiadomienie"></button>
  </div>

</div>
<!-- ── /Person Picker ─────────────────────────────────────────────────── -->

<script>
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    new PersonPicker({
      pid:        <?= json_encode($pid) ?>,
      appUrl:     <?= $url_json ?>,
      fillMap:    <?= $fill_json ?>,
      addrWidget: <?= $addr_json ?>,
      onSelectJs: <?= $on_sel_json ?>,
    });
  });
}());
</script>
<?php

    // ── Modale — renderowane raz na stronę ──────────────────────────────────
    if (!$modals_rendered):
        $modals_rendered = true;
?>

<!-- ═══════════════════════════════════════════════════════════════════
     Modal: Przeglądaj osoby
     ═══════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="ppBrowseModal" tabindex="-1"
     role="dialog" aria-modal="true" aria-labelledby="ppBrowseTitle">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header border-0 pb-1">
        <h5 class="modal-title d-flex align-items-center gap-2" id="ppBrowseTitle">
          <i class="bi bi-people-fill text-primary" aria-hidden="true"></i>
          Wybierz osobę z rejestru
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>

      <div class="modal-body pt-1">

        <!-- Wyszukiwarka w modalu -->
        <div class="input-group mb-3">
          <span class="input-group-text" aria-hidden="true"><i class="bi bi-search"></i></span>
          <input type="search"
                 id="ppBrowseSearch"
                 class="form-control"
                 placeholder="Filtruj po imieniu, nazwisku, PESEL, e-mail…"
                 autocomplete="off"
                 aria-label="Filtruj osoby"
                 aria-controls="ppBrowseTbody">
          <span class="input-group-text text-muted small" aria-live="polite" aria-atomic="true">
            <span id="ppBrowseCount">—</span> os.
          </span>
        </div>

        <!-- Tabela wyników -->
        <div class="table-responsive" style="max-height:420px;overflow-y:auto">
          <table class="table table-sm table-hover align-middle mb-0" role="grid"
                 aria-label="Lista osób" aria-describedby="ppBrowseTitle">
            <thead class="table-light sticky-top">
              <tr>
                <th scope="col">Imię i nazwisko</th>
                <th scope="col">PESEL</th>
                <th scope="col">E-mail</th>
                <th scope="col">Telefon</th>
              </tr>
            </thead>
            <tbody id="ppBrowseTbody" aria-live="polite" aria-relevant="additions removals">
              <tr><td colspan="4" class="text-center text-muted py-4">
                <span class="spinner-border spinner-border-sm me-2" role="status"></span>Ładowanie…
              </td></tr>
            </tbody>
          </table>
        </div>

      </div>
      <div class="modal-footer border-0 pt-1">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
          Anuluj
        </button>
        <a href="<?= h($app_url) ?>/persons/index.php" target="_blank"
           class="btn btn-sm btn-link text-muted ms-auto">
          <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz pełny rejestr
        </a>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     Modal: Dodaj nową osobę
     ═══════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="ppAddModal" tabindex="-1"
     role="dialog" aria-modal="true" aria-labelledby="ppAddTitle">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title d-flex align-items-center gap-2" id="ppAddTitle">
          <i class="bi bi-person-plus-fill text-primary" aria-hidden="true"></i>
          Nowa osoba w rejestrze
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>

      <div class="modal-body pt-2">
        <p class="text-muted small mb-3">
          Uzupełnij podstawowe dane. Pozostałe informacje możesz dodać później w rejestrze osób.
        </p>

        <!-- Alerty walidacji -->
        <div id="ppAddErrors" class="alert alert-danger py-2 small d-none" role="alert" aria-live="assertive"></div>

        <div id="ppAddForm" autocomplete="off">
          <input type="hidden" id="ppAddCsrf" value="">

          <div class="mb-3">
            <label for="ppAdd_name" class="form-label fw-semibold">
              Imię i nazwisko <span class="text-danger" aria-label="wymagane">*</span>
            </label>
            <input type="text" id="ppAdd_name" name="imie_nazwisko"
                   class="form-control" required
                   placeholder="Jan Kowalski"
                   aria-describedby="ppAdd_name_help"
                   aria-required="true">
            <div id="ppAdd_name_help" class="form-text">Pełne imię i nazwisko wolontariusza / strony umowy.</div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label for="ppAdd_pesel" class="form-label fw-semibold">PESEL</label>
              <input type="text" id="ppAdd_pesel" name="pesel"
                     class="form-control font-monospace" maxlength="11"
                     pattern="\d{11}" inputmode="numeric"
                     placeholder="00000000000"
                     aria-describedby="ppAdd_pesel_help">
              <div id="ppAdd_pesel_help" class="form-text">11 cyfr. Opcjonalnie.</div>
            </div>
            <div class="col-sm-6">
              <label for="ppAdd_dob" class="form-label fw-semibold">Data urodzenia</label>
              <input type="date" id="ppAdd_dob" name="data_urodzenia" class="form-control">
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label for="ppAdd_email" class="form-label fw-semibold">E-mail</label>
              <input type="email" id="ppAdd_email" name="email"
                     class="form-control" placeholder="jan@example.com">
            </div>
            <div class="col-sm-6">
              <label for="ppAdd_phone" class="form-label fw-semibold">Telefon</label>
              <input type="tel" id="ppAdd_phone" name="telefon"
                     class="form-control" placeholder="+48 000 000 000">
            </div>
          </div>
        </div>

        <p class="text-muted small mb-0">
          <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
          Osoba zostanie dodana do rejestru i automatycznie przypisana do formularza.
        </p>
      </div>

      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
          Anuluj
        </button>
        <button type="button" id="ppAddSubmit" class="btn btn-primary btn-sm px-4">
          <span id="ppAddBtnText"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Dodaj osobę</span>
          <span id="ppAddBtnSpinner" class="d-none" aria-hidden="true">
            <span class="spinner-border spinner-border-sm me-1" role="status"></span>Zapisuję…
          </span>
        </button>
      </div>

    </div>
  </div>
</div>

<?php
    endif; // $modals_rendered

    // ── JS klasy PersonPicker — renderowane raz na stronę ───────────────────
    if (!$js_rendered):
        $js_rendered = true;
?>
<style>
.pp-listbox .list-group-item { border-left: none; border-right: none; }
.pp-listbox .list-group-item:first-child { border-top: none; }
.pp-listbox .list-group-item[aria-selected="true"] {
  background: var(--bs-primary-bg-subtle);
  color: var(--bs-primary-text-emphasis);
}
.ppBrowseTr { cursor: pointer; }
.ppBrowseTr:focus { outline: 2px solid var(--bs-primary); outline-offset: -2px; }
@keyframes pp-autofill { from { background: #fef9c3; } to { background: transparent; } }
.pp-autofilled { animation: pp-autofill .8s ease-out; }
</style>

<script>
/* global PersonPicker registry */
window._ppRegistry = window._ppRegistry || {};
/* APP_URL stored once — used by shared browse/add modals */
window._ppAppUrl = window._ppAppUrl || <?= json_encode($app_url) ?>;

if (typeof PersonPicker === 'undefined') {

/* ══════════════════════════════════════════════════════════════════════════
   PersonPicker — ARIA combobox + browse modal + add-new modal
   ══════════════════════════════════════════════════════════════════════════ */
class PersonPicker {
  constructor({ pid, appUrl, fillMap, addrWidget, onSelectJs }) {
    this.pid        = pid;
    this.appUrl     = appUrl;
    this.fillMap    = fillMap    || {};
    this.addrWidget = addrWidget || '';
    this.onSelectJs = onSelectJs || '';

    // DOM refs
    this.wrap    = document.getElementById(pid + '_wrap');
    this.combo   = document.getElementById(pid + '_combobox');
    this.input   = document.getElementById(pid + '_search');
    this.listbox = document.getElementById(pid + '_listbox');
    this.hidden  = document.getElementById(pid + '_hidden');
    this.clearBtn= document.getElementById(pid + '_clear');
    this.alert   = document.getElementById(pid + '_alert');
    this.status  = document.getElementById(pid + '_status');

    this._results   = [];
    this._activeIdx = -1;
    this._timer     = null;

    // Register in global registry
    window._ppRegistry[pid] = this;

    this._initInput();
    this._initClear();
    this._initAlertClose();
    this._initBrowseModal();
    this._initAddModal();
  }

  /* ── Autocomplete input ────────────────────────────────────────────── */
  _initInput() {
    if (!this.input) return;

    this.input.addEventListener('input', () => {
      this.hidden.value = '';
      this._showClear(false);
      clearTimeout(this._timer);
      const q = this.input.value.trim();
      if (q.length < 2) { this._closeList(); return; }
      this._timer = setTimeout(() => this._fetch(q), 280);
    });

    this.input.addEventListener('keydown', (e) => this._keydown(e));

    this.input.addEventListener('focus', () => {
      const q = this.input.value.trim();
      if (q.length >= 2 && this._results.length) this._openList();
    });

    // Close on outside click / focus-out
    document.addEventListener('click', (e) => {
      if (this.wrap && !this.wrap.contains(e.target)) this._closeList();
    });
    document.addEventListener('focusin', (e) => {
      if (this.wrap && !this.wrap.contains(e.target)) this._closeList();
    });
  }

  /* ── Wyczyść przycisk ─────────────────────────────────────────────── */
  _initClear() {
    if (!this.clearBtn) return;
    this.clearBtn.addEventListener('click', () => this.clearSelection());
  }

  /* ── Zamknij alert ─────────────────────────────────────────────────── */
  _initAlertClose() {
    const btn = document.getElementById(this.pid + '_alert_close');
    if (!btn) return;
    btn.addEventListener('click', () => {
      if (this.alert) {
        this.alert.classList.add('d-none');
        this.alert.setAttribute('aria-hidden', 'true');
      }
    });
  }

  /* ── Keyboard navigation (↑↓ Enter Esc Tab) ───────────────────────── */
  _keydown(e) {
    const isOpen = this.listbox && this.listbox.style.display !== 'none';

    if (!isOpen) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        const q = this.input.value.trim();
        if (q.length >= 2) this._fetch(q);
      }
      return;
    }

    const items = this.listbox.querySelectorAll('[role="option"]');

    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        this._highlight(Math.min(this._activeIdx + 1, items.length - 1));
        break;
      case 'ArrowUp':
        e.preventDefault();
        if (this._activeIdx <= 0) { this._closeList(); this.input.focus(); break; }
        this._highlight(this._activeIdx - 1);
        break;
      case 'Enter':
        e.preventDefault();
        if (this._activeIdx >= 0 && this._results[this._activeIdx]) {
          this.selectPerson(this._results[this._activeIdx]);
        }
        break;
      case 'Escape':
        this._closeList();
        this.input.focus();
        break;
      case 'Tab':
        this._closeList();
        break;
    }
  }

  /* ── Fetch & render ────────────────────────────────────────────────── */
  _fetch(q) {
    fetch(this.appUrl + '/persons/search.php?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(data => {
        this._results   = data;
        this._activeIdx = -1;
        this._renderList(data, q);
        const n = data.length;
        this._announce(n === 0 ? 'Brak wyników.'
          : n + ' wynik' + (n === 1 ? '' : n < 5 ? 'i' : 'ów') + '. Użyj strzałek, aby wybrać.');
      })
      .catch(() => this._announce('Błąd wyszukiwania.'));
  }

  _renderList(persons, q) {
    this.listbox.innerHTML = '';

    if (!persons.length) {
      const li = this._makeLi('text-muted small py-2 px-3');
      li.setAttribute('aria-selected', 'false');
      li.innerHTML = 'Nie znaleziono wyników dla <strong>' + this._esc(q) + '</strong>.'
        + ' <button type="button" class="btn btn-link btn-sm p-0 align-baseline"'
        + ' id="' + this.pid + '_no_result_add">Dodaj nową osobę</button>';
      this.listbox.appendChild(li);
      const addBtn = document.getElementById(this.pid + '_no_result_add');
      if (addBtn) addBtn.addEventListener('click', () => {
        this._closeList();
        document.getElementById(this.pid + '_add_btn')?.click();
      });
    } else {
      persons.forEach((p, idx) => {
        const li = this._makeLi('list-group-item list-group-item-action py-2 px-3');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.id = this.pid + '_opt_' + idx;
        li.setAttribute('tabindex', '-1');
        li.innerHTML =
          '<div class="d-flex align-items-baseline gap-2">'
          + '<span class="fw-semibold">' + this._esc(p.imie_nazwisko || '') + '</span>'
          + (p.pesel_display
              ? '<span class="font-monospace text-muted small">' + this._esc(p.pesel_display) + '</span>'
              : '')
          + '</div>'
          + '<div class="small text-muted d-flex gap-3 mt-1">'
          + (p.email   ? '<span><i class="bi bi-envelope me-1" aria-hidden="true"></i>' + this._esc(p.email) + '</span>'   : '')
          + (p.telefon ? '<span><i class="bi bi-phone me-1" aria-hidden="true"></i>'    + this._esc(p.telefon) + '</span>' : '')
          + '</div>';
        li.addEventListener('click',     () => this.selectPerson(p));
        li.addEventListener('mousemove', () => this._highlight(idx));
        this.listbox.appendChild(li);
      });
    }
    this._openList();
  }

  /* ── ARIA list state ───────────────────────────────────────────────── */
  _openList() {
    if (!this.listbox) return;
    this.listbox.style.display = '';
    this.combo?.setAttribute('aria-expanded', 'true');
    this.input?.setAttribute('aria-expanded', 'true');
  }

  _closeList() {
    if (!this.listbox) return;
    this.listbox.style.display = 'none';
    this.combo?.setAttribute('aria-expanded', 'false');
    this.input?.setAttribute('aria-expanded', 'false');
    this.input?.removeAttribute('aria-activedescendant');
    this._activeIdx = -1;
  }

  _highlight(idx) {
    const items = this.listbox.querySelectorAll('[role="option"]');
    items.forEach((el, i) => {
      const active = i === idx;
      el.classList.toggle('active', active);
      el.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    this._activeIdx = idx;
    if (idx >= 0 && items[idx]) {
      this.input.setAttribute('aria-activedescendant', items[idx].id);
      items[idx].scrollIntoView({ block: 'nearest' });
    } else {
      this.input.removeAttribute('aria-activedescendant');
    }
  }

  /* ── Wybór osoby ───────────────────────────────────────────────────── */
  selectPerson(p) {
    if (!p) return;
    this.hidden.value = p.id;
    this.input.value  = p.imie_nazwisko || '';
    this._closeList();
    this._showClear(true);

    // Uzupełnij pola przez fillMap
    // Nadpisuje tylko pola puste lub oznaczone data-pp-filled (poprzedni wybór z pickera)
    for (const [field, elId] of Object.entries(this.fillMap)) {
      if (!elId) continue;
      const val = p[field];
      if (val === null || val === undefined || val === '') continue;
      const el = document.getElementById(elId);
      if (!el) continue;
      // Nadpisz jeśli: pole puste ALBO było już uzupełnione przez picker (nie ręcznie)
      if (el.value && !el.dataset.ppFilled) continue;
      el.value = String(val);
      el.dataset.ppFilled = '1';
      el.classList.add('pp-autofilled');
      setTimeout(() => el.classList.remove('pp-autofilled'), 900);
      el.dispatchEvent(new Event('input',  { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Uzupełnij address widget
    if (this.addrWidget) {
      const aw = document.getElementById(this.addrWidget);
      if (aw) {
        ['addr_street','addr_house','addr_flat','addr_postal','addr_city','addr_country'].forEach(f => {
          const val = p[f];
          if (val === null || val === undefined || val === '') return;
          const el = aw.querySelector('[name="' + f + '"]');
          if (!el) return;
          if (el.value && !el.dataset.ppFilled) return;
          el.value = String(val);
          el.dataset.ppFilled = '1';
          el.classList.add('pp-autofilled');
          setTimeout(() => el.classList.remove('pp-autofilled'), 900);
        });
      }
    }

    // Pokaż alert
    if (this.alert) {
      this.alert.classList.remove('d-none');
      this.alert.removeAttribute('aria-hidden');
    }

    // Announce do czytnika
    this._announce('Wybrano: ' + (p.imie_nazwisko || '') + '. Dane uzupełnione.');

    // Callback JS
    if (this.onSelectJs) {
      try { /* eslint-disable-next-line no-new-func */
        (new Function(this.onSelectJs)).call(null);
      } catch (_) {}
    }
  }

  clearSelection() {
    this.hidden.value = '';
    this.input.value  = '';
    this._results     = [];
    this._showClear(false);
    if (this.alert) { this.alert.classList.add('d-none'); this.alert.setAttribute('aria-hidden','true'); }
    this._closeList();
    // Usuń znaczniki pp-filled żeby pola znów można było nadpisać przy nowym wyborze
    for (const elId of Object.values(this.fillMap)) {
      const el = elId ? document.getElementById(elId) : null;
      if (el) delete el.dataset.ppFilled;
    }
    if (this.addrWidget) {
      const aw = document.getElementById(this.addrWidget);
      if (aw) aw.querySelectorAll('[data-pp-filled]').forEach(el => delete el.dataset.ppFilled);
    }
    this._announce('Wybór osoby wyczyszczony.');
  }

  /* ── Browse modal ──────────────────────────────────────────────────── */
  _initBrowseModal() {
    const btn = document.getElementById(this.pid + '_browse_btn');
    if (!btn) return;
    btn.addEventListener('click', () => {
      // Ustaw aktywny picker dla modalu
      const modal = document.getElementById('ppBrowseModal');
      if (modal) modal.dataset.ppId = this.pid;
    });
  }

  /* ── Add modal ─────────────────────────────────────────────────────── */
  _initAddModal() {
    const btn = document.getElementById(this.pid + '_add_btn');
    if (!btn) return;
    btn.addEventListener('click', () => {
      const modal = document.getElementById('ppAddModal');
      if (modal) modal.dataset.ppId = this.pid;
      // Wstaw CSRF
      const csrfSrc = document.querySelector('[name="_csrf"]');
      const csrfFld = document.getElementById('ppAddCsrf');
      if (csrfSrc && csrfFld) csrfFld.value = csrfSrc.value;
      // Reset pól
      if (typeof window._ppAddReset === 'function') window._ppAddReset();
      else {
        ['ppAdd_name','ppAdd_pesel','ppAdd_dob','ppAdd_email','ppAdd_phone'].forEach(function(id) {
          var el = document.getElementById(id); if (el) el.value = '';
        });
        document.getElementById('ppAddErrors')?.classList.add('d-none');
      }
    });
  }

  /* ── Helpers ───────────────────────────────────────────────────────── */
  _showClear(show) {
    if (this.clearBtn) this.clearBtn.style.display = show ? 'flex' : 'none';
  }

  _announce(msg) {
    if (!this.status) return;
    this.status.textContent = '';
    requestAnimationFrame(() => { this.status.textContent = msg; });
  }

  _makeLi(cls) {
    const li = document.createElement('li');
    li.className = cls;
    li.setAttribute('role', 'option');
    return li;
  }

  _esc(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
}

/* ══════════════════════════════════════════════════════════════════════════
   Browse modal — shared, single instance
   ══════════════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
  const browseModal  = document.getElementById('ppBrowseModal');
  if (!browseModal) return;

  const browseSearch = document.getElementById('ppBrowseSearch');
  const browseTbody  = document.getElementById('ppBrowseTbody');
  const browseCount  = document.getElementById('ppBrowseCount');
  let   browseTimer;

  function escHtml(s) {
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function loadPersons(q) {
    const appUrl = window._ppAppUrl || '';
    fetch(appUrl + '/persons/search.php?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(persons => {
        if (browseCount) browseCount.textContent = persons.length;
        browseTbody.innerHTML = '';

        if (!persons.length) {
          browseTbody.innerHTML =
            '<tr><td colspan="4" class="text-center text-muted py-4">Brak wyników.</td></tr>';
          return;
        }

        persons.forEach(p => {
          const tr = document.createElement('tr');
          tr.className = 'ppBrowseTr';
          tr.setAttribute('tabindex', '0');
          tr.setAttribute('role', 'row');
          tr.setAttribute('aria-label', 'Wybierz ' + escHtml(p.imie_nazwisko));
          tr.innerHTML =
            '<td class="fw-semibold">' + escHtml(p.imie_nazwisko) + '</td>'
            + '<td class="font-monospace small text-muted">' + escHtml(p.pesel_display || '—') + '</td>'
            + '<td class="small">' + escHtml(p.email || '—') + '</td>'
            + '<td class="small text-muted">' + escHtml(p.telefon || '—') + '</td>';

          function pick() {
            const pid    = browseModal.dataset.ppId;
            const picker = window._ppRegistry[pid];
            if (picker) picker.selectPerson(p);
            bootstrap.Modal.getInstance(browseModal)?.hide();
          }

          tr.addEventListener('click', pick);
          tr.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); }
            if (e.key === 'ArrowDown' && tr.nextElementSibling) tr.nextElementSibling.focus();
            if (e.key === 'ArrowUp'   && tr.previousElementSibling) tr.previousElementSibling.focus();
          });
          browseTbody.appendChild(tr);
        });
      })
      .catch(() => {
        browseTbody.innerHTML =
          '<tr><td colspan="4" class="text-center text-danger py-3">Błąd pobierania danych.</td></tr>';
      });
  }

  browseModal.addEventListener('shown.bs.modal', () => {
    browseSearch.value = '';
    browseSearch.focus();
    loadPersons('');
  });

  browseSearch?.addEventListener('input', function () {
    clearTimeout(browseTimer);
    browseTimer = setTimeout(() => loadPersons(this.value.trim()), 280);
  });

  // Klawiatura: Esc w wyszukiwarce zamyka listę i wraca do inputa
  browseSearch?.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      browseTbody.querySelector('.ppBrowseTr')?.focus();
    }
  });
});

/* ══════════════════════════════════════════════════════════════════════════
   Add modal — shared, single instance
   ══════════════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
  const addModal  = document.getElementById('ppAddModal');
  const addSubmit = document.getElementById('ppAddSubmit');
  if (!addModal || !addSubmit) return;

  function _ppAddSetLoading(on) {
    document.getElementById('ppAddBtnText')?.classList.toggle('d-none', on);
    document.getElementById('ppAddBtnSpinner')?.classList.toggle('d-none', !on);
    if (addSubmit) addSubmit.disabled = on;
  }
  window._ppAddSetLoading = _ppAddSetLoading;

  function _ppAddReset() {
    ['ppAdd_name','ppAdd_pesel','ppAdd_dob','ppAdd_email','ppAdd_phone'].forEach(function(id) {
      var el = document.getElementById(id);
      if (el) { el.value = ''; el.classList.remove('is-invalid'); }
    });
    document.getElementById('ppAddErrors')?.classList.add('d-none');
    _ppAddSetLoading(false);
  }
  window._ppAddReset = _ppAddReset;

  addSubmit.addEventListener('click', function () {
    const errBox = document.getElementById('ppAddErrors');
    errBox?.classList.add('d-none');

    // Podstawowa walidacja — imię wymagane
    const nameEl = document.getElementById('ppAdd_name');
    if (!nameEl || !nameEl.value.trim()) {
      if (nameEl) nameEl.classList.add('is-invalid');
      if (errBox) {
        errBox.innerHTML = '<div><i class="bi bi-exclamation-circle me-1"></i>Imię i nazwisko jest wymagane.</div>';
        errBox.classList.remove('d-none');
      }
      nameEl?.focus();
      return;
    }
    if (nameEl) nameEl.classList.remove('is-invalid');

    _ppAddSetLoading(true);

    const appUrl = window._ppAppUrl || '';
    const body   = {
      _csrf:          document.getElementById('ppAddCsrf')?.value || '',
      imie_nazwisko:  nameEl.value.trim(),
      pesel:          document.getElementById('ppAdd_pesel')?.value.trim() || '',
      email:          document.getElementById('ppAdd_email')?.value.trim() || '',
      telefon:        document.getElementById('ppAdd_phone')?.value.trim() || '',
      data_urodzenia: document.getElementById('ppAdd_dob')?.value          || '',
    };

    fetch(appUrl + '/persons/api_create.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(body),
    })
      .then(r => r.json())
      .then(data => {
        _ppAddSetLoading(false);
        if (data.ok) {
          const pid    = addModal.dataset.ppId;
          const picker = window._ppRegistry[pid];
          if (picker) picker.selectPerson(data.person);
          bootstrap.Modal.getInstance(addModal)?.hide();
          _ppAddReset();
        } else {
          if (errBox) {
            errBox.innerHTML = (data.errors || ['Nieznany błąd.']).map(s =>
              '<div><i class="bi bi-exclamation-circle me-1"></i>' + String(s).replace(/</g,'&lt;') + '</div>'
            ).join('');
            errBox.classList.remove('d-none');
            errBox.focus();
          }
        }
      })
      .catch(() => {
        _ppAddSetLoading(false);
        if (errBox) {
          errBox.textContent = 'Błąd połączenia z serwerem.';
          errBox.classList.remove('d-none');
        }
      });
  });
});

window.PersonPicker = PersonPicker;
} // end if (typeof PersonPicker === 'undefined')
</script>
<?php
    endif; // $js_rendered

    return ob_get_clean();
}
