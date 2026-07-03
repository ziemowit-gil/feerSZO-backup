<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';
require_once __DIR__ . '/../includes/webauthn.php';

kdok_require_access();
kdok_migrate();
webauthn_migrate();

$PAGE_TITLE = 'EOD Dokumentów Księgowych';

// Filtry
$filter_type    = $_GET['type']    ?? '';
$filter_status  = $_GET['status']  ?? '';
$filter_q       = trim($_GET['q']  ?? '');
$filter_miesiac = (int)($_GET['miesiac'] ?? 0);
$filter_rok     = (int)($_GET['rok']     ?? 0);
$page           = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 20;

$where = ['1=1'];
$params = [];

if ($filter_type) {
    $where[] = 'type = ?';
    $params[] = $filter_type;
}
if ($filter_status) {
    $where[] = 'status = ?';
    $params[] = $filter_status;
}
if ($filter_q) {
    $where[] = '(title LIKE ? OR number LIKE ? OR description LIKE ?)';
    $params[] = "%$filter_q%";
    $params[] = "%$filter_q%";
    $params[] = "%$filter_q%";
}
if ($filter_miesiac && $filter_rok) {
    $where[] = '((miesiac IS NOT NULL AND rok IS NOT NULL AND miesiac = ? AND rok = ?) OR (miesiac IS NULL AND CAST(SUBSTR(created_at,6,2) AS INTEGER) = ? AND CAST(SUBSTR(created_at,1,4) AS INTEGER) = ?))';
    $params[] = $filter_miesiac; $params[] = $filter_rok;
    $params[] = $filter_miesiac; $params[] = $filter_rok;
}

$where_sql = implode(' AND ', $where);

$total = (int)(kdok_one("SELECT COUNT(*) AS c FROM kdok_documents WHERE $where_sql", $params)['c'] ?? 0);
$pag   = paginate($total, $per_page, $page, '?');
$docs  = kdok_all(
    "SELECT * FROM kdok_documents WHERE $where_sql ORDER BY id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $pag['offset']])
);

// Powiąż kroki
foreach ($docs as &$doc) {
    $steps = kdok_all(
        "SELECT step_type, status FROM kdok_steps WHERE doc_id = ?",
        [$doc['id']]
    );
    $doc['steps'] = array_column($steps, 'status', 'step_type');

    // Czy zalogowany użytkownik ma choć jeden nierozstrzygnięty krok do zaakceptowania — pod masową akceptację
    $doc['can_bulk'] = !in_array($doc['status'], ['zaakceptowany', 'odrzucony'], true);
    if ($doc['can_bulk']) {
        $doc['can_bulk'] = false;
        foreach (array_keys(KDOK_STEPS) as $step) {
            if (!kdok_has_role($step)) continue;
            $s = $doc['steps'][$step] ?? null;
            if (!in_array($s, ['ok', 'uwagi', 'odrzucono'], true)) { $doc['can_bulk'] = true; break; }
        }
    }
}
unset($doc);

$user             = current_user();
$has_webauthn     = webauthn_user_has_keys((int)$user['id']);
$has_ikaks        = kdok_ikaks_has((int)$user['id']);
$my_cert          = kdok_cert_get((int)$user['id']);
$cert_ok          = $my_cert && kdok_cert_is_valid($my_cert);
$auth_ready       = $cert_ok && ($has_webauthn || $has_ikaks);
$ikaks_session_ok = !$has_webauthn && kdok_ikaks_session_ok((int)$user['id']);
$ikaks_expires_at = $ikaks_session_ok ? kdok_ikaks_session_expires_at((int)$user['id']) : null;

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-file-earmark-check"></i> EOD Dokumentów Księgowych</h4>
  <div class="d-flex gap-2">
    <?php if (is_admin() || kdok_has_role('zatwierdza')): ?>
    <a href="<?= APP_URL ?>/ksiegowosc/zip.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-file-zip"></i> Pobierz ZIP miesiąca
    </a>
    <?php endif; ?>
    <?php if (kdok_has_role('upload')): ?>
    <a href="<?= APP_URL ?>/ksiegowosc/add.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg"></i> Nowy dokument
    </a>
    <?php endif; ?>
  </div>
</div>

<?= flash_html() ?>

<!-- Filtry -->
<?php
$months_pl = ['','Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
$years_range = range((int)date('Y') - 3, (int)date('Y') + 1);
?>
<form method="get" class="row g-2 mb-3">
  <div class="col-sm-3">
    <select name="type" class="form-select form-select-sm">
      <option value="">Wszystkie typy</option>
      <?php foreach (KDOK_TYPES as $k => $t): ?>
      <option value="<?= h($k) ?>" <?= $filter_type === $k ? 'selected' : '' ?>><?= h($t['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-2">
    <select name="status" class="form-select form-select-sm">
      <option value="">Wszystkie statusy</option>
      <?php foreach (KDOK_STATUSES as $k => $s): ?>
      <option value="<?= h($k) ?>" <?= $filter_status === $k ? 'selected' : '' ?>><?= h($s['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-2">
    <select name="miesiac" class="form-select form-select-sm">
      <option value="">Miesiąc</option>
      <?php for ($m = 1; $m <= 12; $m++): ?>
      <option value="<?= $m ?>" <?= $filter_miesiac === $m ? 'selected' : '' ?>><?= $months_pl[$m] ?></option>
      <?php endfor; ?>
    </select>
  </div>
  <div class="col-sm-1">
    <select name="rok" class="form-select form-select-sm">
      <option value="">Rok</option>
      <?php foreach ($years_range as $yr): ?>
      <option value="<?= $yr ?>" <?= $filter_rok === $yr ? 'selected' : '' ?>><?= $yr ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-2">
    <input type="text" name="q" class="form-control form-control-sm" placeholder="Szukaj…" value="<?= h($filter_q) ?>">
  </div>
  <div class="col-auto d-flex gap-1 align-items-center flex-wrap">
    <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-search"></i></button>
    <a href="<?= APP_URL ?>/ksiegowosc/index.php" class="btn btn-outline-secondary btn-sm">Wyczyść</a>
    <?php if ($filter_miesiac && $filter_rok && (is_admin() || kdok_has_role('zatwierdza'))): ?>
    <a href="<?= APP_URL ?>/ksiegowosc/zip.php?miesiac=<?= $filter_miesiac ?>&rok=<?= $filter_rok ?>"
       class="btn btn-sm btn-outline-success" title="Pobierz ZIP PDF zaakceptowanych z <?= $months_pl[$filter_miesiac] ?> <?= $filter_rok ?>">
      <i class="bi bi-file-zip"></i> ZIP <?= $months_pl[$filter_miesiac] ?> <?= $filter_rok ?>
    </a>
    <?php endif; ?>
  </div>
</form>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle small">
      <thead class="table-light">
        <tr>
          <th style="width:2rem"><input type="checkbox" id="cb-all" class="form-check-input" aria-label="Zaznacz wszystkie"></th>
          <th>Numer</th>
          <th>Typ</th>
          <th>Tytuł</th>
          <th>Status</th>
          <th class="text-center">Fml.</th>
          <th class="text-center">Mer.</th>
          <th class="text-center">Wyp.</th>
          <th>Dodano</th>
          <th>Dodał</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$docs): ?>
        <tr><td colspan="11" class="text-center text-muted py-4">Brak dokumentów.</td></tr>
      <?php endif; ?>
      <?php foreach ($docs as $doc): ?>
        <tr>
          <td>
            <?php if ($doc['can_bulk']): ?>
            <input type="checkbox" class="form-check-input cb-row" value="<?= $doc['id'] ?>" aria-label="Zaznacz dokument <?= h($doc['number']) ?>">
            <?php endif; ?>
          </td>
          <td><code><?= h($doc['number']) ?></code></td>
          <td><i class="<?= h(KDOK_TYPES[$doc['type']]['icon'] ?? 'bi-file') ?>"></i> <?= h(KDOK_TYPES[$doc['type']]['label'] ?? $doc['type']) ?></td>
          <td><?= h($doc['title']) ?></td>
          <td><?= kdok_status_badge($doc['status']) ?></td>
          <?php foreach (['formal','meryt','zatwierdza'] as $step): ?>
          <td class="text-center">
            <?php $s = $doc['steps'][$step] ?? 'oczekuje'; ?>
            <?php if ($s === 'ok'): ?>
              <i class="bi bi-check-circle-fill text-success" title="Zatwierdzone"></i>
            <?php elseif ($s === 'uwagi'): ?>
              <i class="bi bi-exclamation-circle-fill text-warning" title="Z uwagami"></i>
            <?php else: ?>
              <i class="bi bi-circle text-muted" title="Oczekuje"></i>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
          <td><?= date_pl($doc['created_at']) ?></td>
          <td><?= h($doc['creator_name']) ?></td>
          <td>
            <a href="<?= APP_URL ?>/ksiegowosc/view.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-eye"></i>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?= pagination_html($pag, '?type=' . urlencode($filter_type) . '&status=' . urlencode($filter_status) . '&q=' . urlencode($filter_q) . '&miesiac=' . $filter_miesiac . '&rok=' . $filter_rok . '&') ?>

<!-- Pasek masowej akceptacji (pojawia się po zaznaczeniu wierszy) -->
<div id="bulk-bar" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:1050;
     background:#1e293b;color:#f8fafc;padding:.55rem 1.25rem;
     box-shadow:0 -3px 14px rgba(0,0,0,.3);border-top:2px solid #334155"
     role="toolbar" aria-label="Akcje masowe">
  <div class="d-flex align-items-center gap-2 flex-wrap" style="max-width:1200px;margin:0 auto">
    <span class="fw-semibold me-1" style="font-size:.85rem">
      <i class="bi bi-check2-square me-1"></i>
      <span id="bulk-count">0</span> zaznaczonych
    </span>
    <button type="button" class="btn btn-sm btn-success py-1 px-3" onclick="bulkOpenAccept()">
      <i class="bi bi-check-lg me-1"></i>Zaakceptuj zaznaczone
    </button>
    <button type="button" class="btn btn-sm btn-link text-white-50 ms-auto p-0"
            onclick="bulkClear()" title="Anuluj zaznaczenie" aria-label="Anuluj zaznaczenie">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
</div>

<!-- Modal: masowa akceptacja (autoryzacja kluczem WebAuthn) -->
<div class="modal fade" id="bulkAcceptModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="bi bi-shield-lock-fill"></i> Masowa akceptacja</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-3">
          Zostaną zaakceptowane wszystkie oczekujące kroki (merytoryczny/formalny/wypłata),
          do których masz uprawnienia, dla <strong id="bulk-accept-count">0</strong> zaznaczonych dokumentów.
        </p>

        <div class="mb-3 p-2 rounded border <?= $has_webauthn ? 'border-success bg-success bg-opacity-10' : 'border-warning bg-warning bg-opacity-10' ?>">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-usb-symbol fs-4 <?= $has_webauthn ? 'text-success' : 'text-warning' ?>"></i>
            <div>
              <?php if ($has_webauthn): ?>
              <div class="fw-semibold">Klucz WebAuthn zarejestrowany</div>
              <?php else: ?>
              <div class="fw-semibold text-warning-emphasis">Brak zarejestrowanego klucza WebAuthn</div>
              <div class="small text-muted">
                Możesz awaryjnie użyć kodu IKAKS poniżej. Docelowo zarejestruj klucz w
                <a href="<?= APP_URL ?>/panel/webauthn.php" target="_blank">Mój profil → Klucze bezpieczeństwa</a>.
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="mb-3 p-2 rounded border <?= $cert_ok ? 'border-success bg-success bg-opacity-10' : 'border-danger bg-danger bg-opacity-10' ?>">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-patch-<?= $cert_ok ? 'check-fill text-success' : 'x-fill text-danger' ?> fs-4"></i>
            <div>
              <?php if ($cert_ok): ?>
              <div class="fw-semibold"><?= h($my_cert['subject_cn']) ?></div>
              <div class="small text-muted">Certyfikat X.509 aktywny · ważny do <?= date('d.m.Y', strtotime($my_cert['valid_to'])) ?></div>
              <?php else: ?>
              <div class="fw-semibold text-danger">Brak ważnego certyfikatu X.509</div>
              <div class="small text-muted">Skontaktuj się z administratorem.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if (!$auth_ready): ?>
        <div class="alert alert-danger mb-0">
          Autoryzacja niemożliwa. Wymagany ważny certyfikat X.509 oraz zarejestrowany klucz WebAuthn
          albo (awaryjnie) ustawiony kod IKAKS.
        </div>
        <?php elseif ($has_webauthn): ?>
        <div class="form-text mt-0 mb-2">Po dotknięciu klucza dokumenty zostaną zaakceptowane automatycznie.</div>
        <div class="d-flex align-items-center gap-2">
          <button type="button" id="bulkWebauthnConfirm" class="btn btn-primary">
            <i class="bi bi-usb-plug"></i> Dotknij klucz WebAuthn i zatwierdź
          </button>
          <span id="bulkWebauthnSpinner" class="spinner-border spinner-border-sm text-primary" style="display:none"></span>
          <span id="bulkWebauthnOk" class="text-success fw-semibold" style="display:none">
            <i class="bi bi-check-circle-fill"></i> Zweryfikowano
          </span>
        </div>
        <div id="bulkWebauthnError" class="text-danger small mt-1" style="display:none"></div>
        <?php elseif ($ikaks_session_ok): ?>
        <div class="alert alert-success mb-0">
          <i class="bi bi-check-circle-fill"></i> Sesja awaryjna IKAKS jest aktywna do
          <strong><?= date('H:i', $ikaks_expires_at) ?></strong> — kliknij „Potwierdź autoryzację”, bez ponownego podawania kodu.
        </div>
        <?php else: ?>
        <div>
          <label class="form-label fw-semibold">
            <i class="bi bi-key-fill text-warning"></i>
            IKAKS — Indywidualny Kod Autoryzacyjny (awaryjnie, brak klucza WebAuthn)
          </label>
          <input type="password" id="bulkIkaksInput" class="form-control form-control-lg"
            placeholder="Wpisz swój kod IKAKS…" autocomplete="off">
          <div id="bulkIkaksError" class="text-danger small mt-1 mb-2" style="display:none"></div>
          <label class="form-label fw-semibold">Powód użycia kodu IKAKS zamiast klucza WebAuthn</label>
          <textarea id="bulkIkaksReasonInput" class="form-control" rows="2"
            placeholder="Np. klucz zgubiony/w naprawie, jeszcze nie zarejestrowany…"></textarea>
          <div class="form-text">
            Kod wystarczy podać raz na 6 godzin — kolejne decyzje w tym oknie czasowym nie wymagają ponownej autoryzacji.
          </div>
          <div id="bulkIkaksReasonError" class="text-danger small mt-1" style="display:none">
            Podaj powód użycia kodu IKAKS.
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <?php if ($auth_ready && !$has_webauthn): ?>
        <button type="button" id="bulkIkaksConfirm" class="btn btn-dark">
          <i class="bi bi-shield-check"></i> Potwierdź autoryzację
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<input type="hidden" id="kdokWebauthnCsrf" value="<?= csrf_token() ?>">
<input type="hidden" id="kdokWebauthnBeginUrl" value="<?= APP_URL ?>/ksiegowosc/webauthn_begin.php">
<input type="hidden" id="kdokWebauthnVerifyUrl" value="<?= APP_URL ?>/ksiegowosc/webauthn_verify.php">
<input type="hidden" id="kdokBulkAcceptUrl" value="<?= APP_URL ?>/ksiegowosc/bulk_accept.php">

<script>
window.addEventListener('load', function () {
  var bar   = document.getElementById('bulk-bar');
  var cbAll = document.getElementById('cb-all');
  var modalEl = document.getElementById('bulkAcceptModal');
  if (!bar || !modalEl) return;

  function getChecked() {
    return Array.from(document.querySelectorAll('.cb-row:checked')).map(function (c) { return c.value; });
  }

  function updateBar() {
    var n   = getChecked().length;
    var all = document.querySelectorAll('.cb-row').length;
    bar.style.display = n ? '' : 'none';
    document.getElementById('bulk-count').textContent = n;
    if (cbAll) {
      cbAll.checked       = n > 0 && n === all;
      cbAll.indeterminate = n > 0 && n < all;
    }
  }

  document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'cb-all') {
      document.querySelectorAll('.cb-row').forEach(function (c) { c.checked = e.target.checked; });
    }
    if (e.target && (e.target.id === 'cb-all' || e.target.classList.contains('cb-row'))) {
      updateBar();
    }
  });

  window.bulkClear = function () {
    document.querySelectorAll('.cb-row').forEach(function (c) { c.checked = false; });
    if (cbAll) { cbAll.checked = false; cbAll.indeterminate = false; }
    updateBar();
  };

  function bsModal() { return bootstrap.Modal.getOrCreateInstance(modalEl); }

  // ── Krok WebAuthn ────────────────────────────────────────────────────────
  var waBtn      = document.getElementById('bulkWebauthnConfirm');
  var waSpinner  = document.getElementById('bulkWebauthnSpinner');
  var waOk       = document.getElementById('bulkWebauthnOk');
  var waError    = document.getElementById('bulkWebauthnError');
  var waVerified = false;

  function b64u_to_ab(str) {
    var s = str.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s);
    var buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  }

  function ab_to_b64u(buf) {
    var bytes = new Uint8Array(buf);
    var bin   = '';
    for (var i = 0; i < bytes.byteLength; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function setWebauthnVerified(ok) {
    waVerified = ok;
    if (waOk) waOk.style.display = ok ? '' : 'none';
  }

  async function doAccept(ikaks, ikaksReason) {
    var ids = getChecked();
    if (!ids.length) return;

    var csrf = document.getElementById('kdokWebauthnCsrf').value;
    var url  = document.getElementById('kdokBulkAcceptUrl').value;

    if (waBtn) {
      waBtn.disabled = true;
      waBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Zatwierdzanie…';
    }
    if (ikaksBtn) {
      ikaksBtn.disabled = true;
      ikaksBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Zatwierdzanie…';
    }

    try {
      var body = { _csrf: csrf, ids: ids };
      if (ikaks)       body.ikaks = ikaks;
      if (ikaksReason) body.ikaks_reason = ikaksReason;
      var resp = await fetch(url, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      var data = await resp.json();
      if (!data.ok) throw new Error(data.message || 'Błąd akceptacji');
      bsModal().hide();
      location.reload();
    } catch (e) {
      setWebauthnVerified(false);
      if (waBtn) {
        waBtn.disabled = false;
        waBtn.innerHTML = '<i class="bi bi-usb-plug"></i> Dotknij klucz WebAuthn i zatwierdź';
      }
      if (ikaksBtn) {
        ikaksBtn.disabled = false;
        ikaksBtn.innerHTML = '<i class="bi bi-shield-check"></i> Potwierdź autoryzację';
      }
      if (waError)    { waError.style.display    = ''; waError.textContent    = e.message || 'Błąd akceptacji.'; }
      if (ikaksError) { ikaksError.style.display = ''; ikaksError.textContent = e.message || 'Błąd akceptacji.'; }
    }
  }

  // ── Krok IKAKS (awaryjnie, gdy brak klucza WebAuthn) ────────────────────────
  var ikaksInp         = document.getElementById('bulkIkaksInput');
  var ikaksError       = document.getElementById('bulkIkaksError');
  var ikaksReasonInp   = document.getElementById('bulkIkaksReasonInput');
  var ikaksReasonError = document.getElementById('bulkIkaksReasonError');
  var ikaksBtn         = document.getElementById('bulkIkaksConfirm');

  if (ikaksBtn) {
    ikaksBtn.addEventListener('click', function () {
      // Brak pola kodu w DOM = aktywna sesja awaryjna IKAKS — po prostu zatwierdź
      if (!ikaksInp) { doAccept(); return; }

      if (!ikaksInp.value.trim()) {
        if (ikaksError) { ikaksError.style.display = ''; ikaksError.textContent = 'Wpisz kod IKAKS przed zatwierdzeniem.'; }
        ikaksInp.focus();
        return;
      }
      if (ikaksError) ikaksError.style.display = 'none';
      if (ikaksReasonInp && !ikaksReasonInp.value.trim()) {
        if (ikaksReasonError) ikaksReasonError.style.display = '';
        ikaksReasonInp.focus();
        return;
      }
      if (ikaksReasonError) ikaksReasonError.style.display = 'none';
      doAccept(ikaksInp.value, ikaksReasonInp ? ikaksReasonInp.value : '');
    });
  }
  if (ikaksInp) {
    ikaksInp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); ikaksBtn && ikaksBtn.click(); }
    });
  }

  async function doWebauthn() {
    if (!waBtn) return;
    var csrf      = document.getElementById('kdokWebauthnCsrf').value;
    var beginUrl  = document.getElementById('kdokWebauthnBeginUrl').value;
    var verifyUrl = document.getElementById('kdokWebauthnVerifyUrl').value;

    waBtn.disabled = true;
    if (waSpinner) waSpinner.style.display = '';
    if (waError) { waError.style.display = 'none'; waError.textContent = ''; }

    try {
      var beginResp = await fetch(beginUrl, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ _csrf: csrf })
      });
      var beginData = await beginResp.json();
      if (!beginData.ok) throw new Error(beginData.message || 'Błąd inicjalizacji');

      var opts = beginData.options;
      opts.challenge = b64u_to_ab(opts.challenge);
      if (opts.allowCredentials) {
        opts.allowCredentials = opts.allowCredentials.map(function (c) {
          return Object.assign({}, c, { id: b64u_to_ab(c.id) });
        });
      }

      var credential = await navigator.credentials.get({ publicKey: opts });

      var credData = {
        id:                credential.id,
        rawId:             ab_to_b64u(credential.rawId),
        clientDataJSON:    ab_to_b64u(credential.response.clientDataJSON),
        authenticatorData: ab_to_b64u(credential.response.authenticatorData),
        signature:         ab_to_b64u(credential.response.signature),
        userHandle:        credential.response.userHandle ? ab_to_b64u(credential.response.userHandle) : null,
      };

      var verifyResp = await fetch(verifyUrl, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ _csrf: csrf, response: credData })
      });
      var verifyData = await verifyResp.json();
      if (!verifyData.ok) throw new Error(verifyData.message || 'Błąd weryfikacji klucza');

      setWebauthnVerified(true);
      await doAccept();
    } catch (e) {
      setWebauthnVerified(false);
      if (waError) {
        waError.style.display = '';
        waError.textContent = e.message || 'Nie udało się zweryfikować klucza. Spróbuj ponownie.';
      }
    } finally {
      waBtn.disabled = false;
      if (waSpinner) waSpinner.style.display = 'none';
    }
  }

  if (waBtn) waBtn.addEventListener('click', doWebauthn);

  window.bulkOpenAccept = function () {
    document.getElementById('bulk-accept-count').textContent = getChecked().length;
    setWebauthnVerified(false);
    if (waError)          waError.style.display = 'none';
    if (ikaksInp)         ikaksInp.value = '';
    if (ikaksError)       ikaksError.style.display = 'none';
    if (ikaksReasonInp)   ikaksReasonInp.value = '';
    if (ikaksReasonError) ikaksReasonError.style.display = 'none';
    bsModal().show();
  };

  modalEl.addEventListener('shown.bs.modal', function () {
    if (ikaksInp) ikaksInp.focus();
  });

  modalEl.addEventListener('hidden.bs.modal', function () {
    if (waError) waError.style.display = 'none';
    setWebauthnVerified(false);
    if (ikaksInp)         ikaksInp.value = '';
    if (ikaksError)       ikaksError.style.display = 'none';
    if (ikaksReasonInp)   ikaksReasonInp.value = '';
    if (ikaksReasonError) ikaksReasonError.style.display = 'none';
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
