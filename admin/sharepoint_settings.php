<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';

require_role('admin');
ika_require(APP_URL . '/admin/sharepoint_settings.php', 3600);
$PAGE_TITLE = 'SharePoint — synchronizacja plików';

$keys = ['sp_enabled','sp_site_url','sp_library','sp_base_folder'];
$cfg  = [];
foreach ($keys as $k) $cfg[$k] = m365_setting($k);

$m365_ok = (new M365Graph())->is_configured();

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
/* ── Wizard layout ─────────────────────────────────────────────────── */
.sp-wizard { max-width: 780px; margin: 0 auto; }

/* Stepper nav */
.sp-stepper { display:flex; gap:0; margin-bottom:2rem; counter-reset:step; }
.sp-step {
    flex:1; text-align:center; position:relative; cursor:default;
    font-size:.78rem; font-weight:600; color:#94a3b8;
}
.sp-step::before {
    counter-increment: step;
    content: counter(step);
    display: flex; align-items:center; justify-content:center;
    width: 32px; height: 32px; border-radius: 50%;
    background: #e2e8f0; color: #64748b;
    margin: 0 auto .4rem; font-size:.85rem; font-weight:700;
    position:relative; z-index:1; transition: background .2s, color .2s;
}
.sp-step::after {
    content:''; position:absolute; top:16px; left:calc(50% + 16px);
    right:calc(-50% + 16px); height:2px; background:#e2e8f0; z-index:0;
}
.sp-step:last-child::after { display:none; }
.sp-step.done::before   { background:#d1fae5; color:#065f46; content:'✓'; }
.sp-step.done::after    { background:#86efac; }
.sp-step.active::before { background:#3b82f6; color:#fff; box-shadow:0 0 0 4px #bfdbfe; }
.sp-step.active         { color:#1d4ed8; }

/* Step panels */
.sp-panel { display:none; animation: fadeIn .2s ease; }
.sp-panel.active { display:block; }
@keyframes fadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }

/* Library cards */
.drive-card {
    border: 2px solid #e2e8f0; border-radius:.5rem;
    padding:.65rem .85rem; cursor:pointer; transition: border-color .15s, background .15s;
    display:flex; align-items:center; gap:.75rem;
}
.drive-card:hover { border-color:#93c5fd; background:#f0f9ff; }
.drive-card.selected { border-color:#3b82f6; background:#eff6ff; }
.drive-card input[type=radio] { flex-shrink:0; }

/* Summary table */
.summary-row { display:flex; align-items:baseline; gap:.5rem; padding:.45rem 0; border-bottom:1px solid #f1f5f9; font-size:.875rem; }
.summary-row:last-child { border-bottom:0; }
.summary-label { color:#64748b; width:160px; flex-shrink:0; }
.summary-value { font-weight:600; font-family:monospace; word-break:break-all; }

/* Status badges */
.status-dot { width:9px;height:9px;border-radius:50%;display:inline-block;margin-right:5px; }
.dot-ok   { background:#22c55e; }
.dot-warn { background:#f59e0b; }
.dot-err  { background:#ef4444; }
.dot-idle { background:#94a3b8; }

/* Prereq checklist */
.prereq-item { display:flex; align-items:center; gap:.6rem; padding:.4rem 0; font-size:.875rem; }
.prereq-icon { font-size:1rem; flex-shrink:0; }
</style>

<div class="sp-wizard">

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-cloud-upload text-primary"></i> SharePoint — kreator konfiguracji</h4>
  <a href="m365_settings.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Wróć do M365
  </a>
</div>

<?= flash_html() ?>

<!-- Stepper -->
<div class="sp-stepper" id="stepper">
  <div class="sp-step active" id="nav-0">Warunki</div>
  <div class="sp-step"        id="nav-1">Witryna</div>
  <div class="sp-step"        id="nav-2">Biblioteka</div>
  <div class="sp-step"        id="nav-3">Folder</div>
  <div class="sp-step"        id="nav-4">Aktywacja</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     KROK 0 — Warunki wstępne
     ═══════════════════════════════════════════════════════════════════ -->
<div class="sp-panel active" id="panel-0">
  <div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-list-check"></i> Sprawdzenie warunków wstępnych</div>
    <div class="card-body">
      <p class="text-muted small mb-3">Zanim skonfigurujesz SharePoint, upewnij się że poniższe warunki są spełnione:</p>

      <div class="prereq-item">
        <?php if ($m365_ok): ?>
          <span class="prereq-icon text-success">✅</span>
          <div><strong>Microsoft 365</strong> — integracja skonfigurowana</div>
        <?php else: ?>
          <span class="prereq-icon text-danger">❌</span>
          <div>
            <strong>Microsoft 365</strong> — brak konfiguracji
            <div class="small text-muted mt-1">
              <a href="m365_settings.php" class="fw-semibold">Skonfiguruj M365</a>
              z Client ID i Client Secret, a następnie wróć tutaj.
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="prereq-item">
        <span class="prereq-icon text-info">ℹ️</span>
        <div>
          <strong>Uprawnienie Azure AD:</strong> <code>Sites.ReadWrite.All</code>
          <div class="small text-muted mt-1">
            Dodaj w Azure Portal → App registrations → API permissions → Microsoft Graph →
            Application permissions → <code>Sites.ReadWrite.All</code> → Grant admin consent.
          </div>
        </div>
      </div>

      <div class="prereq-item">
        <span class="prereq-icon text-info">ℹ️</span>
        <div>
          <strong>Witryna SharePoint</strong> musi istnieć i być dostępna przez Graph API.
          <div class="small text-muted mt-1">
            Np. <code>https://twojorg.sharepoint.com/sites/NazwaWitryny</code>
          </div>
        </div>
      </div>

      <?php if ($cfg['sp_site_url']): ?>
      <div class="alert alert-success py-2 small mt-3 mb-0">
        <i class="bi bi-check-circle-fill"></i>
        Masz już zapisaną konfigurację dla <strong><?= h($cfg['sp_site_url']) ?></strong>.
        Możesz ją edytować przechodząc przez kreator lub zmienić ustawienia poniżej.
      </div>
      <?php endif; ?>

      <div class="d-flex justify-content-end mt-4">
        <button class="btn btn-primary" onclick="goTo(1)" <?= $m365_ok ? '' : 'disabled' ?>>
          Dalej — Podaj witrynę <i class="bi bi-arrow-right"></i>
        </button>
      </div>
      <?php if (!$m365_ok): ?>
      <div class="text-center mt-2">
        <small class="text-danger">Skonfiguruj M365, aby przejść dalej.</small>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     KROK 1 — URL witryny
     ═══════════════════════════════════════════════════════════════════ -->
<div class="sp-panel" id="panel-1">
  <div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-globe2"></i> Adres URL witryny SharePoint</div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        Podaj pełny URL witryny SharePoint, do której będą synchronizowane pliki z systemu.
      </p>

      <div class="mb-3">
        <label class="form-label fw-semibold small">URL witryny <span class="text-danger">*</span></label>
        <div class="input-group">
          <input type="url" id="sp_site_url" class="form-control font-monospace"
            value="<?= h($cfg['sp_site_url']) ?>"
            placeholder="https://twojorg.sharepoint.com/sites/NazwaWitryny">
          <button class="btn btn-outline-primary" type="button" id="btn_test" onclick="testConnection()">
            <i class="bi bi-plug" id="test_icon"></i>
            <span id="test_label">Testuj połączenie</span>
          </button>
        </div>
        <div class="form-text">Przykład: <code>https://feer.sharepoint.com/sites/Dokumenty</code></div>
      </div>

      <!-- Wynik testu -->
      <div id="test_result" class="d-none">
        <div id="test_ok" class="d-none alert alert-success py-2 small">
          <i class="bi bi-check-circle-fill"></i>
          <strong>Połączono z SharePoint!</strong>
          <span id="test_site_name"></span>
          — znaleziono <strong id="test_drives_count"></strong> bibliotek(i).
        </div>
        <div id="test_err" class="d-none">
          <div class="alert alert-danger py-2 small mb-2">
            <i class="bi bi-x-circle-fill"></i> <strong>Błąd połączenia:</strong>
            <span id="test_err_msg"></span>
          </div>
          <div id="test_hint" class="alert alert-warning py-2 small d-none">
            <i class="bi bi-lightbulb"></i> <span id="test_hint_msg"></span>
          </div>
        </div>
      </div>

      <div class="d-flex justify-content-between mt-4">
        <button class="btn btn-outline-secondary" onclick="goTo(0)">
          <i class="bi bi-arrow-left"></i> Wstecz
        </button>
        <button class="btn btn-primary" id="btn_next_1" disabled onclick="goTo(2)">
          Dalej — Wybierz bibliotekę <i class="bi bi-arrow-right"></i>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     KROK 2 — Biblioteka dokumentów
     ═══════════════════════════════════════════════════════════════════ -->
<div class="sp-panel" id="panel-2">
  <div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-folder2-open"></i> Biblioteka dokumentów</div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        Wybierz bibliotekę, do której będą trafiać pliki z systemu.
        Zostaw puste, aby używać domyślnej biblioteki witryny.
      </p>

      <div id="drives_list" class="mb-3">
        <div class="text-muted small"><i class="bi bi-hourglass-split"></i> Ładowanie bibliotek...</div>
      </div>

      <div>
        <label class="form-label fw-semibold small">
          Nazwa biblioteki
          <span class="text-muted fw-normal">(uzupełniana automatycznie po wyborze powyżej)</span>
        </label>
        <input type="text" id="sp_library" class="form-control form-control-sm"
          value="<?= h($cfg['sp_library']) ?>"
          placeholder="Dokumenty (zostaw puste = domyślna)">
      </div>

      <div class="d-flex justify-content-between mt-4">
        <button class="btn btn-outline-secondary" onclick="goTo(1)">
          <i class="bi bi-arrow-left"></i> Wstecz
        </button>
        <button class="btn btn-primary" onclick="goTo(3)">
          Dalej — Folder bazowy <i class="bi bi-arrow-right"></i>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     KROK 3 — Folder bazowy
     ═══════════════════════════════════════════════════════════════════ -->
<div class="sp-panel" id="panel-3">
  <div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-folder-plus"></i> Folder bazowy (opcjonalnie)</div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        Możesz wskazać opcjonalny podfolder wewnątrz biblioteki.
        Podfoldery typów umów (np. <code>dzielo/</code>, <code>zlecenie/</code>) będą tworzone automatycznie.
      </p>

      <div class="mb-3">
        <label class="form-label fw-semibold small">Ścieżka folderu bazowego</label>
        <input type="text" id="sp_base_folder" class="form-control font-monospace"
          value="<?= h($cfg['sp_base_folder']) ?>"
          placeholder="np. Rejestr Umów">
        <div class="form-text">Zostaw puste, żeby pliki trafiały do korzenia biblioteki.</div>
      </div>

      <!-- Podgląd ścieżki -->
      <div class="card bg-light border-0 p-3 small">
        <strong class="d-block mb-1 text-muted"><i class="bi bi-tree"></i> Przykładowa ścieżka pliku:</strong>
        <code id="path_preview" class="text-break"></code>
      </div>

      <div class="d-flex justify-content-between mt-4">
        <button class="btn btn-outline-secondary" onclick="goTo(2)">
          <i class="bi bi-arrow-left"></i> Wstecz
        </button>
        <button class="btn btn-primary" onclick="goTo(4)">
          Dalej — Aktywacja <i class="bi bi-arrow-right"></i>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     KROK 4 — Podsumowanie i aktywacja
     ═══════════════════════════════════════════════════════════════════ -->
<div class="sp-panel" id="panel-4">
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-check2-all"></i> Podsumowanie konfiguracji</div>
    <div class="card-body">
      <div id="summary"></div>

      <hr class="my-3">

      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" role="switch" id="sp_enabled"
          <?= $cfg['sp_enabled']==='1' ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="sp_enabled">
          Synchronizacja z SharePoint aktywna
        </label>
        <div class="form-text">
          Gdy włączona, każdy plik wgrany do systemu jest automatycznie kopiowany na SharePoint.
        </div>
      </div>

      <div id="save_result" class="d-none mb-3"></div>

      <div class="d-flex justify-content-between align-items-center">
        <button class="btn btn-outline-secondary" onclick="goTo(3)">
          <i class="bi bi-arrow-left"></i> Wstecz
        </button>
        <div class="d-flex gap-2">
          <button class="btn btn-outline-secondary" onclick="retestConnection()">
            <i class="bi bi-arrow-repeat"></i> Testuj ponownie
          </button>
          <button class="btn btn-success" id="btn_save" onclick="saveSettings()">
            <i class="bi bi-check-lg"></i> Zapisz konfigurację
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> Jak to działa</div>
    <div class="card-body small text-muted">
      <ul class="ps-3 mb-0">
        <li class="mb-1">Każdy plik wgrany przez użytkownika (umowy, potwierdzenia, załączniki) jest automatycznie kopiowany do wybranej biblioteki SharePoint.</li>
        <li class="mb-1">Struktura folderów odzwierciedla typy umów: <code>dzielo/</code>, <code>zlecenie/</code>, <code>wolontariat/</code> itd.</li>
        <li class="mb-1">Pliki do 4 MB — jednym żądaniem; większe — przez sesję upload (chunked).</li>
        <li class="mb-1">Błąd uploadu do SharePoint <strong>nie blokuje</strong> zapisu pliku w systemie — jest logowany.</li>
        <li>Wymagane uprawnienie Azure AD: <strong>Sites.ReadWrite.All</strong> (Application).</li>
      </ul>
    </div>
  </div>
</div>

</div><!-- /.sp-wizard -->

<script>
const AJAX_URL = '<?= APP_URL ?>/admin/api/sp_wizard.php';
let wizardDrives = [];   // drives pobrane z kroku 1
let currentStep = 0;

// ── Nawigacja między krokami ─────────────────────────────────────────────────
function goTo(step) {
    document.querySelectorAll('.sp-panel').forEach((p, i) => {
        p.classList.toggle('active', i === step);
    });
    document.querySelectorAll('.sp-step').forEach((s, i) => {
        s.classList.remove('active', 'done');
        if (i < step)  s.classList.add('done');
        if (i === step) s.classList.add('active');
    });
    currentStep = step;
    if (step === 2) renderDrives();
    if (step === 3) updatePathPreview();
    if (step === 4) renderSummary();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Krok 1: test połączenia ──────────────────────────────────────────────────
async function testConnection() {
    const url = document.getElementById('sp_site_url').value.trim();
    if (!url) { alert('Podaj URL witryny SharePoint.'); return; }

    setTestState('loading');
    try {
        const res = await fetch(AJAX_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'test', site_url: url }),
        });
        const data = await res.json();
        if (data.ok) {
            wizardDrives = data.drives || [];
            setTestState('ok', data);
        } else {
            setTestState('error', data);
        }
    } catch (e) {
        setTestState('error', { error: 'Błąd sieci: ' + e.message });
    }
}

function setTestState(state, data) {
    const icon  = document.getElementById('test_icon');
    const label = document.getElementById('test_label');
    const box   = document.getElementById('test_result');
    const okDiv = document.getElementById('test_ok');
    const errDiv= document.getElementById('test_err');
    const btn   = document.getElementById('btn_next_1');

    icon.className  = 'bi bi-plug';
    label.textContent = 'Testuj połączenie';
    box.classList.remove('d-none');

    if (state === 'loading') {
        icon.className = 'bi bi-hourglass-split';
        label.textContent = 'Testowanie...';
        okDiv.classList.add('d-none');
        errDiv.classList.add('d-none');
        btn.disabled = true;
        return;
    }
    if (state === 'ok') {
        okDiv.classList.remove('d-none');
        errDiv.classList.add('d-none');
        document.getElementById('test_site_name').textContent =
            data.site_name ? ' (' + data.site_name + ')' : '';
        document.getElementById('test_drives_count').textContent = (data.drives || []).length;
        btn.disabled = false;
        icon.className = 'bi bi-check-circle-fill text-success';
    } else {
        okDiv.classList.add('d-none');
        errDiv.classList.remove('d-none');
        document.getElementById('test_err_msg').textContent = data.error || 'Nieznany błąd';
        const hintDiv = document.getElementById('test_hint');
        const hintMsg = document.getElementById('test_hint_msg');
        if (data.hint) {
            hintDiv.classList.remove('d-none');
            hintMsg.textContent = data.hint;
        } else {
            hintDiv.classList.add('d-none');
        }
        btn.disabled = true;
        if (data.goto_m365) {
            const a = document.createElement('a');
            a.href = 'm365_settings.php';
            a.className = 'btn btn-sm btn-warning mt-2';
            a.innerHTML = '<i class="bi bi-arrow-right"></i> Przejdź do ustawień M365';
            errDiv.appendChild(a);
        }
    }
}

// ── Krok 2: lista bibliotek ──────────────────────────────────────────────────
function renderDrives() {
    const container = document.getElementById('drives_list');
    const saved = document.getElementById('sp_library').value;

    if (!wizardDrives.length) {
        container.innerHTML = '<div class="text-muted small"><i class="bi bi-info-circle"></i> Brak pobranych bibliotek — wróć do kroku "Witryna" i przetestuj połączenie.</div>';
        return;
    }

    let html = '<div class="d-flex flex-column gap-2">';
    wizardDrives.forEach(d => {
        const name    = d.name    || '';
        const webUrl  = d.webUrl  || '';
        const dtype   = d.driveType || '';
        const sel     = (saved && saved === name) ? 'selected' : ((!saved && dtype === 'documentLibrary') ? '' : '');
        html += `
        <label class="drive-card ${sel}" onclick="selectDrive(this, ${JSON.stringify(name)})">
          <input type="radio" name="_drive" value="${escHtml(name)}" ${sel ? 'checked' : ''}
            style="pointer-events:none">
          <div class="flex-grow-1">
            <span class="fw-semibold">${escHtml(name)}</span>
            ${dtype ? `<span class="badge bg-secondary ms-1 small">${escHtml(dtype)}</span>` : ''}
            ${webUrl ? `<br><small class="text-muted font-monospace">${escHtml(webUrl)}</small>` : ''}
          </div>
        </label>`;
    });
    html += '</div>';
    container.innerHTML = html;

    // Pre-select saved library
    if (saved) {
        container.querySelectorAll('.drive-card').forEach(el => {
            const r = el.querySelector('input[type=radio]');
            if (r && r.value === saved) el.classList.add('selected');
        });
    }
}

function selectDrive(el, name) {
    document.querySelectorAll('.drive-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    const r = el.querySelector('input[type=radio]');
    if (r) r.checked = true;
    document.getElementById('sp_library').value = name;
}

// ── Krok 3: podgląd ścieżki ─────────────────────────────────────────────────
function updatePathPreview() {
    const folder  = document.getElementById('sp_base_folder').value.trim().replace(/^\/|\/$/g, '');
    const lib     = document.getElementById('sp_library').value.trim() || 'Dokumenty';
    const preview = folder
        ? lib + '/' + folder + '/dzielo/20260608_abc123.pdf'
        : lib + '/dzielo/20260608_abc123.pdf';
    document.getElementById('path_preview').textContent = preview;
}

document.addEventListener('DOMContentLoaded', () => {
    const folderInput = document.getElementById('sp_base_folder');
    if (folderInput) folderInput.addEventListener('input', updatePathPreview);
});

// ── Krok 4: podsumowanie ─────────────────────────────────────────────────────
function renderSummary() {
    const url    = document.getElementById('sp_site_url').value.trim();
    const lib    = document.getElementById('sp_library').value.trim();
    const folder = document.getElementById('sp_base_folder').value.trim();

    const rows = [
        ['URL witryny',    url    || '<span class="text-danger">—</span>'],
        ['Biblioteka',     lib    || '<span class="text-muted">domyślna</span>'],
        ['Folder bazowy',  folder || '<span class="text-muted">brak (root)</span>'],
        ['Liczba bibliotek', wizardDrives.length ? wizardDrives.length + ' znaleziono' : '<span class="text-warning">— nie testowano</span>'],
    ];

    document.getElementById('summary').innerHTML = rows.map(([l, v]) =>
        `<div class="summary-row"><span class="summary-label">${l}</span><span class="summary-value">${v}</span></div>`
    ).join('');
}

async function retestConnection() {
    goTo(1);
    await testConnection();
}

// ── Zapis ────────────────────────────────────────────────────────────────────
async function saveSettings() {
    const btn = document.getElementById('btn_save');
    const res_div = document.getElementById('save_result');

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Zapisywanie...';

    try {
        const res = await fetch(AJAX_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:      'save',
                sp_enabled:  document.getElementById('sp_enabled').checked,
                site_url:    document.getElementById('sp_site_url').value.trim(),
                library:     document.getElementById('sp_library').value.trim(),
                base_folder: document.getElementById('sp_base_folder').value.trim(),
            }),
        });
        const data = await res.json();
        if (data.ok) {
            res_div.classList.remove('d-none');
            res_div.innerHTML = '<div class="alert alert-success py-2"><i class="bi bi-check-circle-fill"></i> <strong>Konfiguracja zapisana!</strong></div>';
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Zapisano';
            btn.className = 'btn btn-outline-success';
            // Odśwież stronę po chwili, żeby stepper pokazał aktualny stan
            setTimeout(() => location.reload(), 1400);
        } else {
            res_div.classList.remove('d-none');
            res_div.innerHTML = `<div class="alert alert-danger py-2"><i class="bi bi-x-circle-fill"></i> ${escHtml(data.error || 'Błąd zapisu.')}</div>`;
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Zapisz konfigurację';
        }
    } catch (e) {
        res_div.classList.remove('d-none');
        res_div.innerHTML = `<div class="alert alert-danger py-2">Błąd sieci: ${escHtml(e.message)}</div>`;
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Zapisz konfigurację';
    }
}

// ── Utils ────────────────────────────────────────────────────────────────────
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Auto-start: jeśli M365 jest skonfigurowane i mamy URL → przejdź od razu do witryny ──
document.addEventListener('DOMContentLoaded', () => {
    const hasUrl = <?= json_encode((bool)$cfg['sp_site_url']) ?>;
    const m365ok = <?= json_encode($m365_ok) ?>;

    if (m365ok && hasUrl) {
        // Idź do kroku "Witryna" i od razu testuj
        goTo(1);
        testConnection();
    }
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
