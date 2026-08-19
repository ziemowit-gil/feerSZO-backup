<?php
/**
 * Panel "Sprawa EZD" w zakładce dokumentów umowy.
 *
 * Wymagane zmienne w scope callera:
 *   $TYPE  (string) — typ umowy np. 'zlecenie'
 *   $id    (int)    — ID umowy
 *   $row   (array)  — wiersz umowy (do tytułu koszulki)
 *   $USER  (array)  — current_user()
 *
 * Wymagane includy przed tym plikiem:
 *   require_once __DIR__ . '/../includes/contract_ezd.php';
 *   $_ezd_sprawa = contract_ezd_get_sprawa($TYPE, $id);
 */
if (!module_enabled('ezd_enabled')) return;

$_ezd_sprawa    = $ezd_sprawa ?? contract_ezd_get_sprawa($TYPE, $id);
$_ezd_sprawa_id = $_ezd_sprawa['id'] ?? null;
$_ezd_can_write = isset($USER) && in_array($USER['role'] ?? '', ['admin','editor','manager'], true);
$_ezd_link_url  = '/contracts/ezd_link.php?type=' . urlencode($TYPE) . '&id=' . $id;
?>
<div class="card mb-3" id="contract-ezd-panel">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-folder2-open text-primary"></i>
    <strong>Sprawa EZD</strong>
    <?php if ($_ezd_sprawa_id): ?>
      <a href="/ezd/sprawa.php?id=<?= $_ezd_sprawa_id ?>"
         class="badge bg-primary text-decoration-none ms-auto"
         target="_blank">
        <?= h($_ezd_sprawa['znak_sprawy'] ?? '#' . $_ezd_sprawa_id) ?>
        <i class="bi bi-box-arrow-up-right ms-1 small"></i>
      </a>
    <?php else: ?>
      <span class="badge bg-secondary ms-auto">Brak powiązania</span>
    <?php endif; ?>
  </div>
  <div class="card-body p-3" id="contract-ezd-body">

    <?php if ($_ezd_sprawa): ?>
      <?php $_s = $_ezd_sprawa; ?>
      <div class="row g-2 small">
        <div class="col-sm-6">
          <div class="text-muted">Tytuł</div>
          <div><?= h($_s['title']) ?></div>
        </div>
        <div class="col-sm-3">
          <div class="text-muted">Status</div>
          <div>
            <?php $cls = $_s['status'] === 'open' ? 'success' : 'secondary'; ?>
            <span class="badge bg-<?= $cls ?>">
              <?= $_s['status'] === 'open' ? 'Otwarta' : 'Zamknięta' ?>
            </span>
          </div>
        </div>
        <div class="col-sm-3">
          <div class="text-muted">Segregator</div>
          <div><?= h($_s['teczka_title'] ?? '—') ?></div>
        </div>
        <?php if (!empty($_s['deadline'])): ?>
        <div class="col-sm-6">
          <div class="text-muted">Termin</div>
          <div><?= h($_s['deadline']) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($_s['owner_name'])): ?>
        <div class="col-sm-6">
          <div class="text-muted">Prowadzący</div>
          <div><?= h($_s['owner_name']) ?></div>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($_ezd_can_write): ?>
      <div class="mt-3 d-flex gap-2 flex-wrap">
        <a href="/ezd/sprawa.php?id=<?= $_ezd_sprawa_id ?>" class="btn btn-sm btn-outline-primary" target="_blank">
          <i class="bi bi-folder2-open me-1"></i>Otwórz koszulkę EZD
        </a>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-ezd-action="unlink"
                data-url="<?= h($_ezd_link_url) ?>">
          <i class="bi bi-x-lg me-1"></i>Odwiąż
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-ezd-action="search"
                data-url="<?= h($_ezd_link_url) ?>">
          <i class="bi bi-link-45deg me-1"></i>Zmień powiązanie
        </button>
      </div>
      <?php endif; ?>

    <?php elseif ($_ezd_can_write): ?>
      <p class="text-muted small mb-3">
        Ta umowa nie jest powiązana z żadną koszulką EZD.
        Możesz automatycznie utworzyć koszulkę lub wyszukać istniejącą.
      </p>
      <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-primary" data-ezd-action="create"
                data-url="<?= h($_ezd_link_url) ?>">
          <i class="bi bi-folder-plus me-1"></i>Utwórz koszulkę EZD
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-ezd-action="search"
                data-url="<?= h($_ezd_link_url) ?>">
          <i class="bi bi-search me-1"></i>Wyszukaj istniejącą
        </button>
      </div>
    <?php else: ?>
      <p class="text-muted small mb-0">Brak powiązanej koszulki EZD.</p>
    <?php endif; ?>

  </div>
</div>

<!-- Modal: wyszukiwanie koszulki EZD -->
<?php if ($_ezd_can_write): ?>
<div class="modal fade" id="ezdSearchModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-search me-2"></i>Wyszukaj koszulkę EZD</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="input-group mb-3">
          <input type="text" id="ezd-search-q" class="form-control" placeholder="Znak sprawy lub tytuł…">
          <button class="btn btn-outline-secondary" id="ezd-search-btn" type="button">Szukaj</button>
        </div>
        <div id="ezd-search-results"></div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  'use strict';

  function reloadPanel() {
    location.reload();
  }

  function post(url, data, cb) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    fetch(url, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => cb(d))
      .catch(() => alert('Błąd sieci. Spróbuj ponownie.'));
  }

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-ezd-action]');
    if (!btn) return;
    const action = btn.dataset.ezdAction;
    const url    = btn.dataset.url;

    if (action === 'create') {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Tworzę…';
      post(url, { action: 'create' }, function (d) {
        if (d.ok) {
          reloadPanel();
        } else {
          btn.disabled = false;
          btn.innerHTML = '<i class="bi bi-folder-plus me-1"></i>Utwórz koszulkę EZD';
          alert(d.error || 'Błąd tworzenia koszulki.');
        }
      });
    }

    if (action === 'unlink') {
      if (!confirm('Odwiązać koszulkę EZD od tej umowy? Koszulka nie zostanie usunięta.')) return;
      post(url, { action: 'unlink' }, function (d) {
        if (d.ok) reloadPanel(); else alert(d.error || 'Błąd.');
      });
    }

    if (action === 'search') {
      const modal = new bootstrap.Modal(document.getElementById('ezdSearchModal'));
      modal.show();
    }

    if (action === 'link') {
      const sid = btn.dataset.sid;
      post(url, { action: 'link', sprawa_id: sid }, function (d) {
        if (d.ok) reloadPanel(); else alert(d.error || 'Błąd powiązania.');
      });
    }
  });

  // Wyszukiwarka
  const searchBtn = document.getElementById('ezd-search-btn');
  const searchQ   = document.getElementById('ezd-search-q');
  const searchRes = document.getElementById('ezd-search-results');
  const ezd_url   = <?= json_encode($_ezd_link_url) ?>;

  if (searchBtn) {
    searchQ.addEventListener('keydown', e => { if (e.key === 'Enter') searchBtn.click(); });
    searchBtn.addEventListener('click', function () {
      const q = searchQ.value.trim();
      if (!q) return;
      searchRes.innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></div>';
      fetch(ezd_url + '&action=search&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(d => {
          if (!d.results || !d.results.length) {
            searchRes.innerHTML = '<div class="text-muted small">Brak wyników.</div>';
            return;
          }
          searchRes.innerHTML = d.results.map(s => `
            <div class="d-flex align-items-center justify-content-between border rounded px-3 py-2 mb-1">
              <div>
                <div class="fw-semibold small">${escHtml(s.znak_sprawy)}</div>
                <div class="text-muted small">${escHtml(s.title)}</div>
              </div>
              <button class="btn btn-sm btn-primary" data-ezd-action="link"
                      data-url="${ezd_url}" data-sid="${s.id}">Powiąż</button>
            </div>
          `).join('');
        })
        .catch(() => { searchRes.innerHTML = '<div class="text-danger small">Błąd wyszukiwania.</div>'; });
    });
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
})();
</script>
