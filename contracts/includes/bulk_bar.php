<?php
/**
 * contracts/includes/bulk_bar.php
 * Pasek akcji masowych + modale. Dołączany z każdego list.php.
 * Wymaga zmiennej $TYPE w kontekście wywołania.
 */
if (!isset($TYPE)) return;
?>
<!-- Pasek akcji masowych (pojawia się po zaznaczeniu wierszy) -->
<div id="bulk-bar" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:1050;
     background:#1e293b;color:#f8fafc;padding:.55rem 1.25rem;
     box-shadow:0 -3px 14px rgba(0,0,0,.3);border-top:2px solid #334155"
     role="toolbar" aria-label="Akcje masowe">
  <div class="d-flex align-items-center gap-2 flex-wrap" style="max-width:1200px;margin:0 auto">
    <span class="fw-semibold me-1" style="font-size:.85rem">
      <i class="bi bi-check2-square me-1 text-sky-300" style="color:#7DD3FC"></i>
      <span id="bulk-count">0</span> zaznaczonych
    </span>
    <?php if (can_edit()): ?>
    <button type="button" class="btn btn-sm btn-outline-light py-1 px-3" onclick="bulkOpenAmendment()">
      <i class="bi bi-file-earmark-plus me-1"></i>Aneks
    </button>
    <button type="button" class="btn btn-sm btn-outline-light py-1 px-3" onclick="bulkOpenAccess()">
      <i class="bi bi-shield-lock me-1"></i>Zmień dostęp
    </button>
    <?php endif; ?>
    <button type="button" class="btn btn-sm btn-link text-white-50 ms-auto p-0"
            onclick="bulkClear()" title="Anuluj zaznaczenie" aria-label="Anuluj zaznaczenie">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
</div>

<!-- Modal: Aneks masowy -->
<div class="modal fade" id="bulk-modal-amendment" tabindex="-1"
     aria-labelledby="bulk-amendment-title" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="bulk-amendment-title">
          <i class="bi bi-file-earmark-plus me-2 text-primary"></i>Aneks masowy
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-3">
          Aneks zostanie złożony dla <strong id="bulk-amend-count">0</strong> zaznaczonych umów.
          Status: <em>oczekuje na zatwierdzenie</em>.
        </p>
        <div class="mb-3">
          <label for="bulk-amend-numer" class="form-label fw-semibold">
            Oznaczenie aneksu <span class="text-danger">*</span>
          </label>
          <input type="text" id="bulk-amend-numer" class="form-control"
                 placeholder="np. Aneks nr 1/2026" maxlength="100">
        </div>
        <div class="mb-1">
          <label for="bulk-amend-opis" class="form-label fw-semibold">
            Opis zmian <span class="text-danger">*</span>
          </label>
          <textarea id="bulk-amend-opis" class="form-control" rows="4"
                    placeholder="Opisz zakres zmian wprowadzanych aneksem…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" id="bulk-amend-btn" class="btn btn-primary">
          <i class="bi bi-check-lg me-1"></i>Złóż aneksy
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Zmień dostęp -->
<div class="modal fade" id="bulk-modal-access" tabindex="-1"
     aria-labelledby="bulk-access-title" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="bulk-access-title">
          <i class="bi bi-shield-lock me-2 text-warning"></i>Zmień dostęp
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-3">
          Zmiana zostanie zastosowana dla <strong id="bulk-access-count">0</strong> zaznaczonych umów.
        </p>
        <div class="d-flex flex-column gap-2">
          <?php foreach ([
            'full'       => ['Pełny dostęp',    'Wszystkie moduły: zadania, CRM, korespondencja i inne.',    'bi-unlock-fill',     'text-success'],
            'tasks_only' => ['Tylko zadania',    'Dostęp wyłącznie do modułu Zadania.',                       'bi-list-check',      'text-primary'],
            'crm_only'   => ['Tylko CRM',        'Dostęp wyłącznie do modułu CRM.',                           'bi-person-lines-fill','text-info'],
          ] as $val => [$label, $desc, $icon, $col]): ?>
          <label class="card border p-3 d-flex flex-row align-items-center gap-3"
                 style="cursor:pointer;border-radius:8px;transition:border-color .15s"
                 onmouseenter="this.style.borderColor='#6366F1'"
                 onmouseleave="this.style.borderColor=''">
            <input type="radio" name="bulk_access_level" value="<?= $val ?>"
                   class="form-check-input flex-shrink-0 mt-0"
                   <?= $val === 'full' ? 'checked' : '' ?>>
            <div>
              <div class="fw-semibold">
                <i class="bi <?= $icon ?> me-1 <?= $col ?>"></i><?= $label ?>
              </div>
              <div class="text-muted small"><?= $desc ?></div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" id="bulk-access-btn" class="btn btn-warning">
          <i class="bi bi-check-lg me-1"></i>Zastosuj
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const TYPE   = <?= json_encode($TYPE) ?>;
  const CSRF   = <?= json_encode(csrf_token()) ?>;
  const ENDPOINT = <?= json_encode(APP_URL . '/contracts/bulk_action.php') ?>;

  // ── Selekcja ─────────────────────────────────────────────────────────────
  const bar   = document.getElementById('bulk-bar');
  const cbAll = document.getElementById('cb-all');

  function getChecked() {
    return [...document.querySelectorAll('.cb-row:checked')].map(c => c.value);
  }

  function updateBar() {
    const n    = getChecked().length;
    const all  = document.querySelectorAll('.cb-row').length;
    bar.style.display = n ? '' : 'none';
    document.getElementById('bulk-count').textContent = n;
    if (cbAll) {
      cbAll.checked     = n > 0 && n === all;
      cbAll.indeterminate = n > 0 && n < all;
    }
  }

  document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'cb-all') {
      document.querySelectorAll('.cb-row').forEach(c => { c.checked = e.target.checked; });
    }
    if (e.target && (e.target.id === 'cb-all' || e.target.classList.contains('cb-row'))) {
      updateBar();
    }
  });

  window.bulkClear = function () {
    document.querySelectorAll('.cb-row').forEach(c => { c.checked = false; });
    if (cbAll) { cbAll.checked = false; cbAll.indeterminate = false; }
    updateBar();
  };

  // ── Helpers ──────────────────────────────────────────────────────────────
  function getModal(id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); }
  function hideModal(id) { bootstrap.Modal.getInstance(document.getElementById(id))?.hide(); }

  async function post(action, extra) {
    const p = new URLSearchParams();
    p.append('_csrf', CSRF);
    p.append('type',  TYPE);
    p.append('action', action);
    for (const [k, v] of Object.entries(extra)) p.append(k, v);
    getChecked().forEach(id => p.append('ids[]', id));
    const r = await fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: p.toString(),
    });
    return r.json();
  }

  function showToast(msg, ok) {
    const t = document.createElement('div');
    t.className = 'position-fixed top-0 end-0 m-3 px-3 py-2 rounded shadow fw-semibold';
    t.style.cssText = 'z-index:9999;background:' + (ok ? '#16A34A' : '#DC2626') + ';color:#fff;font-size:.88rem';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.remove(); location.reload(); }, 2200);
  }

  function setBusy(btnId, busy, label) {
    const btn = document.getElementById(btnId);
    btn.disabled = busy;
    btn.innerHTML = busy
      ? '<span class="spinner-border spinner-border-sm me-1"></span>Proszę czekać…'
      : label;
  }

  // ── Aneks ─────────────────────────────────────────────────────────────────
  window.bulkOpenAmendment = function () {
    document.getElementById('bulk-amend-count').textContent = getChecked().length;
    document.getElementById('bulk-amend-numer').value = '';
    document.getElementById('bulk-amend-opis').value  = '';
    getModal('bulk-modal-amendment').show();
  };

  document.getElementById('bulk-amend-btn').addEventListener('click', async function () {
    const numer = document.getElementById('bulk-amend-numer').value.trim();
    const opis  = document.getElementById('bulk-amend-opis').value.trim();
    if (!numer || !opis) { alert('Wypełnij oznaczenie aneksu i opis zmian.'); return; }
    setBusy('bulk-amend-btn', true);
    const res = await post('amendment', { numer, opis });
    hideModal('bulk-modal-amendment');
    setBusy('bulk-amend-btn', false, '<i class="bi bi-check-lg me-1"></i>Złóż aneksy');
    showToast(res.ok ? 'Złożono ' + res.count + ' aneksów.' : ('Błąd: ' + (res.msg || '?')), res.ok);
  });

  // ── Dostęp ────────────────────────────────────────────────────────────────
  window.bulkOpenAccess = function () {
    document.getElementById('bulk-access-count').textContent = getChecked().length;
    const first = document.querySelector('input[name="bulk_access_level"][value="full"]');
    if (first) first.checked = true;
    getModal('bulk-modal-access').show();
  };

  document.getElementById('bulk-access-btn').addEventListener('click', async function () {
    const level = document.querySelector('input[name="bulk_access_level"]:checked')?.value;
    if (!level) return;
    setBusy('bulk-access-btn', true);
    const res = await post('set_access', { level });
    hideModal('bulk-modal-access');
    setBusy('bulk-access-btn', false, '<i class="bi bi-check-lg me-1"></i>Zastosuj');
    showToast(res.ok ? 'Zmieniono dostęp dla ' + res.count + ' umów.' : ('Błąd: ' + (res.msg || '?')), res.ok);
  });
})();
</script>
