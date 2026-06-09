<?php
/**
 * admin/sp_onboarding.php — OneClick: konfiguracja M365 + SharePoint + backup bazy.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';

require_role('admin');
ika_require(APP_URL . '/admin/sp_onboarding.php', 3600);
$PAGE_TITLE = 'SharePoint — konfiguracja i backup';

// ── Bieżące ustawienia ────────────────────────────────────────────────────────
$sp_cfg = [
    'sp_enabled'       => m365_setting('sp_enabled'),
    'sp_site_url'      => m365_setting('sp_site_url'),
    'sp_library'       => m365_setting('sp_library'),
    'sp_base_folder'   => m365_setting('sp_base_folder'),
    'sp_backup_folder' => m365_setting('sp_backup_folder') ?: 'Backup',
];

$graph      = new M365Graph();
$m365_ok    = $graph->is_configured();
$m365_test  = null;
if ($m365_ok) {
    try {
        $m365_test = $graph->test_connection();
    } catch (\Exception $e) {
        $m365_test = ['ok' => false, 'error' => $e->getMessage()];
    }
}

$sp_ok = $m365_ok && $sp_cfg['sp_enabled'] === '1' && $sp_cfg['sp_site_url'];

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.onb-step        { border-left: 3px solid #e2e8f0; padding-left: 1.25rem; margin-left: .75rem; position: relative; }
.onb-step + .onb-step { margin-top: 1.5rem; }
.onb-step::before {
    content: '';
    width: 14px; height: 14px; border-radius: 50%;
    background: #e2e8f0; border: 2px solid #cbd5e1;
    position: absolute; left: -8px; top: .2rem;
}
.onb-step.done::before  { background: #22c55e; border-color: #16a34a; }
.onb-step.active::before { background: #3b82f6; border-color: #2563eb; }
.onb-step.done          { border-left-color: #bbf7d0; }
.onb-step.active         { border-left-color: #93c5fd; }

.onb-label    { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; margin-bottom: .25rem; }
.onb-label.done   { color: #15803d; }
.onb-label.active { color: #1d4ed8; }

.sp-progress-bar { height: 4px; border-radius: 2px; transition: width .4s ease; }

#sp-log { font-family: monospace; font-size: .8rem; background: #0f172a; color: #e2e8f0;
          border-radius: 8px; padding: 1rem; max-height: 200px; overflow-y: auto;
          display: none; }
#sp-log .ok   { color: #86efac; }
#sp-log .err  { color: #fca5a5; }
#sp-log .info { color: #93c5fd; }
</style>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item"><a href="sharepoint_settings.php">SharePoint</a></li>
    <li class="breadcrumb-item active">Konfiguracja OneClick</li>
  </ol>
</nav>

<?= flash_html() ?>

<div class="row g-4">

<!-- ══ Lewa kolumna: kreator ══════════════════════════════════════════════════ -->
<div class="col-xl-7">

<div class="card shadow-sm">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <i class="bi bi-cloud-arrow-up text-primary"></i>
  SharePoint — konfiguracja i backup bazy danych
</div>
<div class="card-body">

  <!-- Postęp konfiguracji -->
  <div class="d-flex justify-content-between align-items-center mb-1">
    <small class="text-muted fw-semibold">Postęp</small>
    <small class="text-muted" id="progress-label">
      <?= ($m365_ok ? 1 : 0) + ($m365_test['ok'] ?? false ? 1 : 0) + ($sp_cfg['sp_site_url'] ? 1 : 0) + ($sp_ok ? 1 : 0) ?>/4
    </small>
  </div>
  <div class="progress mb-4" style="height:4px">
    <div class="progress-bar bg-primary sp-progress-bar" id="progress-bar"
         style="width:<?= (($m365_ok ? 25 : 0) + (($m365_test['ok'] ?? false) ? 25 : 0) + ($sp_cfg['sp_site_url'] ? 25 : 0) + ($sp_ok ? 25 : 0)) ?>%">
    </div>
  </div>

  <!-- Krok 1: M365 -->
  <div class="onb-step <?= $m365_ok && ($m365_test['ok'] ?? false) ? 'done' : ($m365_ok ? 'active' : '') ?>">
    <div class="onb-label <?= $m365_ok && ($m365_test['ok'] ?? false) ? 'done' : ($m365_ok ? 'active' : '') ?>">
      Krok 1 — Integracja Microsoft 365
    </div>
    <?php if (!$m365_ok): ?>
      <p class="small text-danger mb-1">
        <i class="bi bi-x-circle-fill me-1"></i>M365 nie jest skonfigurowany.
      </p>
      <a href="m365_settings.php" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-microsoft me-1"></i>Skonfiguruj M365
      </a>
    <?php elseif (!($m365_test['ok'] ?? false)): ?>
      <p class="small text-warning mb-1">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>Dane są, ale połączenie nie działa.
        <a href="m365_settings.php" class="ms-1">Sprawdź ustawienia</a>
      </p>
    <?php else: ?>
      <p class="small text-success mb-0">
        <i class="bi bi-check-circle-fill me-1"></i>
        Połączono z <strong><?= h($m365_test['org_name'] ?? '?') ?></strong>
        <a href="m365_settings.php" class="ms-2 small text-muted">zmień</a>
      </p>
    <?php endif; ?>
  </div>

  <!-- Krok 2: URL witryny SharePoint -->
  <div class="onb-step <?= $sp_cfg['sp_site_url'] ? 'done' : ($m365_ok ? 'active' : '') ?>" id="step2">
    <div class="onb-label <?= $sp_cfg['sp_site_url'] ? 'done' : ($m365_ok ? 'active' : '') ?>">
      Krok 2 — Witryna SharePoint
    </div>
    <!-- URL row: widoczny tylko gdy witryna jest już wybrana lub tryb ręczny -->
    <div id="sp-url-row" class="mb-2"
         style="<?= $sp_cfg['sp_site_url'] ? '' : 'display:none' ?>">
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
        <input type="url" id="sp_site_url" class="form-control font-monospace"
          value="<?= h($sp_cfg['sp_site_url']) ?>"
          placeholder="https://twojorg.sharepoint.com/sites/Dokumenty"
          <?= !$m365_ok ? 'disabled' : '' ?>>
        <button class="btn btn-outline-secondary" type="button" id="btn-list-sites"
                title="Wybierz inną witrynę z listy"
                <?= !$m365_ok ? 'disabled' : '' ?>>
          <i class="bi bi-arrow-repeat me-1"></i>Zmień
        </button>
        <button class="btn btn-outline-secondary" type="button" id="btn-test-sp"
                <?= !$m365_ok ? 'disabled' : '' ?>>
          <i class="bi bi-plug me-1"></i>Testuj
        </button>
      </div>
      <div class="form-text">
        <code id="sp-url-display-text"><?= h($sp_cfg['sp_site_url']) ?></code>
      </div>
    </div>

    <!-- Panel wyboru witryny ------------------------------------------------- -->
    <div id="sp-sites-panel" class="border rounded p-2 mb-2 bg-light"
         style="<?= (!$sp_cfg['sp_site_url'] && $m365_ok) ? '' : 'display:none' ?>">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="small fw-semibold text-secondary">
          <i class="bi bi-grid-1x2 me-1"></i>Witryny SharePoint w Twoim tenancie
        </span>
        <?php if ($sp_cfg['sp_site_url']): ?>
        <button type="button" class="btn-close" id="btn-close-sites" style="font-size:.7rem" aria-label="Zamknij"></button>
        <?php else: ?>
        <span id="btn-close-sites" style="display:none"></span><!-- placeholder dla JS -->
        <?php endif; ?>
      </div>
      <div class="input-group input-group-sm mb-2">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" id="sp-sites-filter" class="form-control"
               placeholder="Filtruj po nazwie lub URL…" autocomplete="off">
      </div>
      <div id="sp-sites-list"
           style="max-height:220px;overflow-y:auto;border:1px solid #dee2e6;border-radius:4px;background:#fff">
        <!-- wypełniane przez JS -->
      </div>
      <div id="sp-sites-footer" class="text-muted mt-1" style="font-size:.78rem;display:none"></div>
      <!-- Tryb ręczny — fallback gdy chcesz wpisać URL bezpośrednio -->
      <div class="text-end mt-2" style="font-size:.8rem">
        <button type="button" class="btn btn-link btn-sm p-0 text-muted" id="btn-manual-url"
                <?= !$m365_ok ? 'disabled' : '' ?>>
          <i class="bi bi-keyboard me-1"></i>Wpisz URL ręcznie…
        </button>
      </div>
    </div>

    <!-- Propozycja SZOSite (gdy brak URL i M365 skonfigurowane) -------------- -->
    <?php if (!$sp_cfg['sp_site_url'] && $m365_ok): ?>
    <div id="sp-szosite-proposal" class="alert alert-warning py-2 px-3 small d-flex align-items-start gap-2 mb-2">
      <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#d97706"></i>
      <div>
        <strong>Nie masz jeszcze witryny SharePoint?</strong><br>
        Kliknij <em>Wybierz…</em> aby wybrać istniejącą, lub utwórz nową witrynę SZOSite
        w centrum administracyjnym Microsoft 365:
        <br>
        <a href="https://admin.microsoft.com/_layouts/15/online/AdminHome.aspx#/sharepoint/activeSites"
           target="_blank" class="btn btn-sm btn-outline-warning mt-1" rel="noopener">
          <i class="bi bi-plus-circle me-1"></i>Utwórz witrynę w M365 Admin
        </a>
        <button type="button" class="btn btn-sm btn-outline-secondary mt-1 ms-1" id="btn-szosite-dismiss">
          <i class="bi bi-x me-1"></i>Ukryj
        </button>
      </div>
    </div>
    <?php endif; ?>

    <div id="sp-test-result" style="display:none"></div>
  </div>

  <!-- Krok 3: Biblioteka + foldery -->
  <div class="onb-step <?= $sp_ok ? 'done' : '' ?>" id="step3">
    <div class="onb-label <?= $sp_ok ? 'done' : '' ?>">Krok 3 — Biblioteka i foldery</div>
    <div class="row g-2 mb-2" id="library-section">
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Biblioteka dokumentów</label>
        <select id="sp_library" class="form-select form-select-sm" disabled>
          <option value="">— domyślna biblioteka —</option>
          <?php foreach (\array_values((function() use ($graph, $sp_cfg) {
              if (!$sp_cfg['sp_site_url'] || !$graph->is_configured()) return [];
              try {
                  $sid = $graph->sp_site_id($sp_cfg['sp_site_url']);
                  return $graph->sp_list_drives($sid);
              } catch (\Exception $e) { return []; }
          })()) as $d): ?>
          <option value="<?= h($d['name'] ?? '') ?>"
            <?= ($sp_cfg['sp_library'] === ($d['name'] ?? '')) ? 'selected' : '' ?>>
            <?= h($d['name'] ?? '?') ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Zostaw puste = domyślna.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Folder plików systemowych</label>
        <input type="text" id="sp_base_folder" class="form-control form-control-sm font-monospace"
          value="<?= h($sp_cfg['sp_base_folder']) ?>"
          placeholder="Rejestr Umów"
          <?= !$sp_cfg['sp_site_url'] ? 'disabled' : '' ?>>
        <div class="form-text">Podfolder dla plików umów.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Folder backupów bazy</label>
        <input type="text" id="sp_backup_folder" class="form-control form-control-sm font-monospace"
          value="<?= h($sp_cfg['sp_backup_folder']) ?>"
          placeholder="Backup"
          <?= !$sp_cfg['sp_site_url'] ? 'disabled' : '' ?>>
        <div class="form-text">
          Backupy trafią do: <code id="backup-path-preview"><?= h($sp_cfg['sp_backup_folder']) ?>/YYYY-MM/umowy_*.db.gz</code>
        </div>
      </div>
    </div>
  </div>

  <!-- Krok 4: Włącz -->
  <div class="onb-step <?= $sp_ok ? 'done' : '' ?>" id="step4">
    <div class="onb-label <?= $sp_ok ? 'done' : '' ?>">Krok 4 — Aktywacja</div>
    <div class="form-check form-switch mb-3">
      <input class="form-check-input" type="checkbox" role="switch" id="sp_enabled"
        <?= $sp_cfg['sp_enabled'] === '1' ? 'checked' : '' ?>>
      <label class="form-check-label fw-semibold small" for="sp_enabled">
        Synchronizacja plików aktywna
      </label>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-primary" id="btn-save" <?= !$m365_ok ? 'disabled' : '' ?>>
        <i class="bi bi-check-lg me-1"></i>Zapisz konfigurację
      </button>
      <button class="btn btn-success" id="btn-backup-now"
              <?= !$sp_ok ? 'disabled' : '' ?>
              title="<?= !$sp_ok ? 'Najpierw skonfiguruj i włącz SharePoint' : 'Utwórz backup bazy danych na SharePoint teraz' ?>">
        <i class="bi bi-cloud-arrow-up me-1"></i>Backup bazy teraz
      </button>
    </div>
    <div id="save-result" class="mt-2" style="display:none"></div>
    <div id="backup-result" class="mt-2" style="display:none"></div>
  </div>

  <!-- Log operacji -->
  <div id="sp-log" class="mt-3"></div>

</div>
</div>

</div><!-- /col-xl-7 -->

<!-- ══ Prawa kolumna: status + info ══════════════════════════════════════════ -->
<div class="col-xl-5">

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-activity"></i> Status</div>
<div class="card-body p-0">
  <?php
  $rows = [
      ['label' => 'Microsoft 365',       'ok' => $m365_ok && ($m365_test['ok'] ?? false),
       'val'   => $m365_ok ? ($m365_test['ok'] ?? false ? h($m365_test['org_name'] ?? '?') : '<span class="text-warning">błąd połączenia</span>') : '<span class="text-danger">nie skonfigurowano</span>'],
      ['label' => 'Witryna SharePoint',  'ok' => (bool)$sp_cfg['sp_site_url'],
       'val'   => $sp_cfg['sp_site_url'] ? '<span class="font-monospace small">'.h(parse_url($sp_cfg['sp_site_url'], PHP_URL_PATH)).'</span>' : '<span class="text-muted">—</span>'],
      ['label' => 'Biblioteka',          'ok' => true,
       'val'   => $sp_cfg['sp_library'] ? h($sp_cfg['sp_library']) : '<span class="text-muted">domyślna</span>'],
      ['label' => 'Folder backupów',     'ok' => true,
       'val'   => '<span class="font-monospace small">'.h($sp_cfg['sp_backup_folder']).'</span>'],
      ['label' => 'Sync plików',         'ok' => $sp_cfg['sp_enabled'] === '1',
       'val'   => $sp_cfg['sp_enabled'] === '1' ? '<span class="badge bg-success">Aktywny</span>' : '<span class="badge bg-secondary">Wyłączony</span>'],
  ];
  ?>
  <div class="px-3 py-2">
  <?php foreach ($rows as $r): ?>
  <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
    <span class="small text-muted"><?= $r['label'] ?></span>
    <span class="small fw-semibold d-flex align-items-center gap-1">
      <?php if ($r['ok']): ?><i class="bi bi-check-circle-fill text-success" style="font-size:.75rem"></i><?php endif; ?>
      <?= $r['val'] ?>
    </span>
  </div>
  <?php endforeach; ?>
  </div>
</div>
</div>

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-link-45deg"></i> Szybkie linki</div>
<div class="card-body p-2">
  <div class="d-grid gap-2">
    <a href="m365_settings.php" class="btn btn-sm btn-outline-secondary text-start">
      <i class="bi bi-microsoft me-2"></i>Ustawienia M365
    </a>
    <a href="sharepoint_settings.php" class="btn btn-sm btn-outline-secondary text-start">
      <i class="bi bi-cloud-upload me-2"></i>SharePoint — pliki (zaawansowane)
    </a>
    <a href="backups.php" class="btn btn-sm btn-outline-secondary text-start">
      <i class="bi bi-archive me-2"></i>Kopie zapasowe (lokalne)
    </a>
  </div>
</div>
</div>

<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> Jak to działa</div>
<div class="card-body small text-muted">
  <ul class="ps-3 mb-0">
    <li class="mb-1"><strong>Sync plików:</strong> każdy plik wgrany do systemu jest automatycznie kopiowany na SharePoint.</li>
    <li class="mb-1"><strong>Backup bazy:</strong> tworzy atomową kopię SQLite (VACUUM INTO), pakuje gzipem i wysyła na SharePoint do folderu <code>Backup/YYYY-MM/</code>.</li>
    <li class="mb-1">Backup pliku do 4 MB = jedno żądanie; większe = sesja chunked.</li>
    <li>Wymagane uprawnienia Azure AD: <code>Sites.ReadWrite.All</code> (Application).</li>
  </ul>
</div>
</div>

</div><!-- /col-xl-5 -->
</div><!-- /row -->

<script>
(function () {

const API_WIZARD = '<?= APP_URL ?>/admin/api/sp_wizard.php';
const API_BACKUP = '<?= APP_URL ?>/admin/api/sp_backup.php';

function log(msg, cls = 'info') {
    const el = document.getElementById('sp-log');
    el.style.display = 'block';
    const line = document.createElement('div');
    line.className = cls;
    line.textContent = '[' + new Date().toLocaleTimeString('pl-PL') + '] ' + msg;
    el.appendChild(line);
    el.scrollTop = el.scrollHeight;
}

function showAlert(containerId, ok, html) {
    const el = document.getElementById(containerId);
    el.style.display = 'block';
    el.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' py-2 small mb-0">'
        + (ok ? '<i class="bi bi-check-circle-fill me-1"></i>' : '<i class="bi bi-x-circle-fill me-1"></i>')
        + html + '</div>';
}

function setBtn(id, spin, label) {
    const b = document.getElementById(id);
    if (!b) return;
    b.disabled = spin;
    b.innerHTML = spin
        ? '<span class="spinner-border spinner-border-sm me-1"></span>' + label
        : label;
}

// ── Picker witryn SharePoint ─────────────────────────────────────────────────
let _spSitesCache = null;

async function openSitesPicker() {
    const panel = document.getElementById('sp-sites-panel');
    panel.style.display = 'block';

    // Przy pierwszym otwarciu — pobierz listę
    if (_spSitesCache === null) {
        const list = document.getElementById('sp-sites-list');
        list.innerHTML = '<div class="p-3 text-center text-muted small">'
            + '<span class="spinner-border spinner-border-sm me-1"></span>Pobieranie listy witryn…</div>';

        const res = await fetch(API_WIZARD, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'list_sites' }),
        }).then(r => r.json()).catch(e => ({ ok: false, error: e.message }));

        if (!res.ok) {
            list.innerHTML = '<div class="p-3 text-danger small">'
                + '<i class="bi bi-x-circle me-1"></i><strong>Błąd:</strong> ' + (res.error || '?')
                + (res.hint ? '<br><span class="text-muted">' + res.hint + '</span>' : '') + '</div>';
            _spSitesCache = [];
            return;
        }
        _spSitesCache = res.sites || [];

        const footer = document.getElementById('sp-sites-footer');
        footer.style.display = 'block';
        footer.textContent = 'Znaleziono ' + _spSitesCache.length + ' witryn w tenancie.';

        if (_spSitesCache.length === 0) {
            list.innerHTML = '<div class="p-3 text-center text-muted small">'
                + '<i class="bi bi-cloud-slash me-1"></i>Brak witryn SharePoint w tenancie.<br>'
                + '<a href="https://admin.microsoft.com/_layouts/15/online/AdminHome.aspx#/sharepoint/activeSites" '
                + 'target="_blank" class="btn btn-sm btn-outline-primary mt-2" rel="noopener">'
                + '<i class="bi bi-plus me-1"></i>Utwórz witrynę w M365 Admin</a></div>';
            return;
        }
    }
    renderSitesList(_spSitesCache, document.getElementById('sp-sites-filter').value);
}

function renderSitesList(sites, filterVal) {
    const q    = (filterVal || '').toLowerCase().trim();
    const list = document.getElementById('sp-sites-list');
    const filtered = q
        ? sites.filter(s =>
              (s.displayName || '').toLowerCase().includes(q) ||
              (s.webUrl      || '').toLowerCase().includes(q) ||
              (s.name        || '').toLowerCase().includes(q))
        : sites;

    if (!filtered.length) {
        list.innerHTML = '<div class="p-2 text-muted small text-center">Brak wyników dla „' + filterVal + '".</div>';
        return;
    }

    list.innerHTML = filtered.map(s => {
        const name = s.displayName || s.name || '(bez nazwy)';
        const url  = s.webUrl || '';
        const path = url ? (() => { try { return new URL(url).pathname; } catch(e) { return url; } })() : '';
        const desc = s.description
            ? '<br><span class="text-muted" style="font-size:.77rem">'
              + s.description.substring(0, 90) + (s.description.length > 90 ? '…' : '') + '</span>'
            : '';
        return '<button type="button" class="sp-site-item d-block w-100 text-start border-0 border-bottom px-3 py-2 bg-transparent" '
             + 'style="cursor:pointer;transition:background .12s" '
             + 'onmouseover="this.style.background=\'#eef2ff\'" onmouseout="this.style.background=\'\'" '
             + 'data-url="' + url.replace(/"/g, '&quot;') + '">'
             + '<div class="fw-semibold small">' + name + '</div>'
             + '<div class="text-muted font-monospace" style="font-size:.74rem">' + path + '</div>'
             + desc
             + '</button>';
    }).join('');

    list.querySelectorAll('.sp-site-item').forEach(btn => {
        btn.addEventListener('click', function () {
            const picked = this.dataset.url;
            // Wstaw URL i pokaż URL row
            document.getElementById('sp_site_url').value = picked;
            const urlRow = document.getElementById('sp-url-row');
            urlRow.style.display = '';
            // Zamknij panel (tylko jeśli był otwarty jako "zmień")
            const closeBtn = document.getElementById('btn-close-sites');
            if (closeBtn && closeBtn.style.display !== 'none') {
                document.getElementById('sp-sites-panel').style.display = 'none';
            } else {
                // Tryb pierwszego wyboru — schowaj picker po wyborze
                document.getElementById('sp-sites-panel').style.display = 'none';
            }
            // Ukryj propozycję SZOSite
            const proposal = document.getElementById('sp-szosite-proposal');
            if (proposal) proposal.style.display = 'none';
            log('Wybrano witrynę: ' + picked);
            // Auto-testuj
            document.getElementById('btn-test-sp').click();
        });
    });
}

// "Zmień" — otwórz picker gdy URL już ustawiony
const btnListSites = document.getElementById('btn-list-sites');
if (btnListSites) btnListSites.addEventListener('click', openSitesPicker);

// Zamknięcie panelu (tylko gdy jest widoczny przycisk close)
document.getElementById('btn-close-sites').addEventListener('click', function () {
    document.getElementById('sp-sites-panel').style.display = 'none';
});

// Filtrowanie listy on-the-fly
document.getElementById('sp-sites-filter').addEventListener('input', function () {
    if (_spSitesCache && _spSitesCache.length) renderSitesList(_spSitesCache, this.value);
});

// "Wpisz URL ręcznie…" — pokaż input i schowaj picker
const btnManual = document.getElementById('btn-manual-url');
if (btnManual) {
    btnManual.addEventListener('click', function () {
        document.getElementById('sp-sites-panel').style.display = 'none';
        const urlRow = document.getElementById('sp-url-row');
        urlRow.style.display = '';
        document.getElementById('sp_site_url').focus();
    });
}

// Ukryj propozycję SZOSite
const _dismissProposal = document.getElementById('btn-szosite-dismiss');
if (_dismissProposal) {
    _dismissProposal.addEventListener('click', function () {
        const p = document.getElementById('sp-szosite-proposal');
        if (p) p.style.display = 'none';
    });
}

// Auto-otwórz picker na starcie gdy M365 OK i brak URL
if (<?= $m365_ok ? 'true' : 'false' ?> && !document.getElementById('sp_site_url').value.trim()) {
    openSitesPicker();
}

// ── Test SharePoint ──────────────────────────────────────────────────────────
document.getElementById('btn-test-sp').addEventListener('click', async function () {
    const url = document.getElementById('sp_site_url').value.trim();
    if (!url) { alert('Podaj URL witryny SharePoint.'); return; }

    setBtn('btn-test-sp', true, 'Testuję…');
    log('Testowanie połączenia z SharePoint: ' + url);

    const res  = await fetch(API_WIZARD, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'test', site_url: url }),
    }).then(r => r.json()).catch(e => ({ ok: false, error: e.message }));

    setBtn('btn-test-sp', false, '<i class="bi bi-plug me-1"></i>Testuj');

    const box = document.getElementById('sp-test-result');
    box.style.display = 'block';

    if (!res.ok) {
        box.innerHTML = '<div class="alert alert-danger py-2 small mb-0">'
            + '<i class="bi bi-x-circle-fill me-1"></i><strong>Błąd:</strong> ' + (res.error || '?')
            + (res.hint ? '<br><span class="text-muted">' + res.hint + '</span>' : '') + '</div>';
        log('Błąd: ' + (res.error || '?'), 'err');
        return;
    }

    box.innerHTML = '<div class="alert alert-success py-2 small mb-0">'
        + '<i class="bi bi-check-circle-fill me-1"></i>Połączono. Znaleziono '
        + (res.drives?.length || 0) + ' bibliotek.</div>';
    log('Połączono z SharePoint. Biblioteki: ' + (res.drives?.length || 0), 'ok');

    // Uzupełnij select bibliotek
    const sel = document.getElementById('sp_library');
    const cur = sel.value;
    while (sel.options.length > 1) sel.remove(1);
    (res.drives || []).forEach(d => {
        const o = new Option(d.name, d.name, false, d.name === cur);
        sel.add(o);
    });
    sel.disabled = false;
    document.getElementById('sp_base_folder').disabled = false;
    document.getElementById('sp_backup_folder').disabled = false;
});

// ── Podgląd ścieżki backupu ──────────────────────────────────────────────────
document.getElementById('sp_backup_folder').addEventListener('input', function () {
    const f = this.value.trim() || 'Backup';
    document.getElementById('backup-path-preview').textContent = f + '/YYYY-MM/umowy_*.db.gz';
});

// ── Zapisz konfigurację ──────────────────────────────────────────────────────
document.getElementById('btn-save').addEventListener('click', async function () {
    const url      = document.getElementById('sp_site_url').value.trim();
    const library  = document.getElementById('sp_library').value;
    const base_f   = document.getElementById('sp_base_folder').value.trim();
    const backup_f = document.getElementById('sp_backup_folder').value.trim();
    const enabled  = document.getElementById('sp_enabled').checked;

    if (!url) { alert('Podaj URL witryny SharePoint.'); return; }

    setBtn('btn-save', true, 'Zapisuję…');
    log('Zapisuję konfigurację SharePoint…');

    // Zapisz SP settings przez wizard API
    const res = await fetch(API_WIZARD, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'save',
            sp_enabled: enabled,
            site_url: url,
            library: library,
            base_folder: base_f,
        }),
    }).then(r => r.json()).catch(e => ({ ok: false, error: e.message }));

    if (res.ok && backup_f) {
        // Zapisz folder backupów osobnym żądaniem do ustawień
        await fetch('<?= APP_URL ?>/admin/api/sp_save_setting.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: 'sp_backup_folder', value: backup_f }),
        }).catch(() => {});
    }

    setBtn('btn-save', false, '<i class="bi bi-check-lg me-1"></i>Zapisz konfigurację');

    if (res.ok) {
        showAlert('save-result', true, 'Konfiguracja zapisana pomyślnie.');
        log('Konfiguracja zapisana.', 'ok');
        if (enabled && url) {
            document.getElementById('btn-backup-now').disabled = false;
            document.getElementById('btn-backup-now').title = '';
        }
    } else {
        showAlert('save-result', false, res.error || 'Nieznany błąd zapisu.');
        log('Błąd zapisu: ' + (res.error || '?'), 'err');
    }
});

// ── Backup teraz ─────────────────────────────────────────────────────────────
document.getElementById('btn-backup-now').addEventListener('click', async function () {
    if (!confirm('Utworzyć backup bazy danych i wysłać na SharePoint?')) return;

    setBtn('btn-backup-now', true, 'Tworzę backup…');
    log('Tworzenie backupu bazy danych…');

    const res = await fetch(API_BACKUP, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'backup' }),
    }).then(r => r.json()).catch(e => ({ ok: false, error: e.message }));

    setBtn('btn-backup-now', false, '<i class="bi bi-cloud-arrow-up me-1"></i>Backup bazy teraz');

    if (res.ok) {
        const link = res.web_url ? ' <a href="' + res.web_url + '" target="_blank" class="ms-1">Otwórz w SharePoint</a>' : '';
        showAlert('backup-result', true,
            'Backup wysłany: <code>' + (res.sp_path || '?') + '</code> (' + (res.size_h || '?') + ')' + link);
        log('Backup OK: ' + (res.sp_path || '?') + ' (' + (res.size_h || '?') + ')', 'ok');
    } else {
        showAlert('backup-result', false, res.error || 'Nieznany błąd backupu.');
        log('Błąd backupu: ' + (res.error || '?'), 'err');
    }
});

})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
