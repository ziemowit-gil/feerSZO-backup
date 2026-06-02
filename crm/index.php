<?php
/**
 * crm/index.php — CRM: Lista Kontaktów (widok główny)
 *
 * Salesforce Lightning-inspired: Object Header + Filter Strip + sortowalna tabela.
 * Heartbeat sync co 50 sekund (SyncService) — osadzony JS, nie wpływa na footer.php.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');

// Uprawnienia: admin/editor — zapis; viewer — tylko odczyt
$crm_can_write  = can_write('crm') || is_admin();
$crm_can_delete = can_delete('crm') || is_admin();

crm_migrate();

$PAGE_TITLE = 'CRM — Kontakty';

// ── Filtry ────────────────────────────────────────────────────────────────────
$filters = [
    'q'      => trim($_GET['q']      ?? ''),
    'status' => trim($_GET['status'] ?? ''),
    'type'   => trim($_GET['type']   ?? ''),
    'tag'    => trim($_GET['tag']    ?? ''),
    'group'  => (int)($_GET['group'] ?? 0) ?: '',
];
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

// ── Dane ──────────────────────────────────────────────────────────────────────
$result  = CrmManager::getContacts($filters, $page, $per_page);
$rows    = $result['rows'];
$total   = $result['total'];
$paging  = paginate($total, $per_page, $page, APP_URL . '/crm/index.php?' . http_build_query(array_filter($filters)));
$stats   = CrmManager::getStats();

// Tagi i grupy do filtrów
$all_tags   = db_all("SELECT tag, COUNT(*) AS cnt FROM crm_tags
                      JOIN crm_contacts ON crm_contacts.id = crm_tags.contact_id
                      WHERE crm_contacts.crm_active=1
                      GROUP BY tag ORDER BY cnt DESC LIMIT 30");
$all_groups = CrmManager::getGroups();

// ── POST: szybkie akcje (status, delete) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $crm_can_write) {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'quick_status' && !empty($_POST['contact_id'])) {
        $cid    = (int)$_POST['contact_id'];
        $status = trim($_POST['new_status'] ?? '');
        if (array_key_exists($status, CRM_STATUSES)) {
            CrmManager::updateContact($cid, ['status' => $status]);
            flash_set('success', 'Status kontaktu zaktualizowany.');
        }
    }

    if ($action === 'delete' && !empty($_POST['contact_id']) && $crm_can_delete) {
        CrmManager::deleteContact((int)$_POST['contact_id']);
        flash_set('success', 'Kontakt usunięty.');
    }

    header('Location: ' . APP_URL . '/crm/index.php?' . http_build_query(array_filter($filters)));
    exit;
}

// ── Helper: URL do listy z zachowaniem filtrów ────────────────────────────────
function crm_list_url(array $merge = []): string {
    global $filters, $page;
    $p = array_merge(['page' => $page], $filters, $merge);
    return APP_URL . '/crm/index.php?' . http_build_query(array_filter($p));
}

include __DIR__ . '/includes/header_crm.php';
?>

<!-- ══ STATYSTYKI ═══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="crm-stat-card">
      <div class="crm-stat-value"><?= $stats['total'] ?></div>
      <div class="crm-stat-label"><i class="bi bi-people-fill me-1" style="color:var(--crm-primary)"></i>Wszystkich kontaktów</div>
      <?php if ($stats['new_this_month'] > 0): ?>
      <div class="crm-stat-delta up"><i class="bi bi-arrow-up-short"></i><?= $stats['new_this_month'] ?> nowych w tym miesiącu</div>
      <?php endif; ?>
    </div>
  </div>
  <?php foreach (array_slice($stats['by_status'], 0, 3) as $bs):
    $sc = CRM_STATUSES[$bs['status']] ?? ['label' => $bs['status'], 'color' => '#939393'];
  ?>
  <div class="col-6 col-md-3">
    <div class="crm-stat-card">
      <div class="crm-stat-value" style="color:<?= h($sc['color']) ?>"><?= (int)$bs['cnt'] ?></div>
      <div class="crm-stat-label"><?= h($sc['label']) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══ OBJECT HEADER ══════════════════════════════════════════════════════════ -->
<div class="crm-object-header shadow-sm">
  <div class="crm-object-icon" aria-hidden="true">
    <i class="bi bi-diagram-2-fill"></i>
  </div>
  <div>
    <h1 class="crm-object-title">Kontakty CRM</h1>
    <div class="crm-object-count"><?= $total ?> <?= $total === 1 ? 'rekord' : ($total < 5 ? 'rekordy' : 'rekordów') ?></div>
  </div>
  <div class="crm-object-actions">
    <?php if ($crm_can_write): ?>
    <a href="<?= APP_URL ?>/crm/contact/add.php"
       class="btn btn-crm-primary btn-sm"
       aria-label="Dodaj nowy kontakt CRM">
      <i class="bi bi-plus-lg me-1"></i>Nowy kontakt
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/crm/communicate.php"
       class="btn btn-crm-outline btn-sm"
       aria-label="Wyślij wiadomość do kontaktów">
      <i class="bi bi-send me-1"></i>Wyślij wiadomość
    </a>
    <!-- Sync status -->
    <div class="crm-sync-label ms-2" id="crmSyncStatus" title="Synchronizacja heartbeat co 50 sekund" aria-live="polite">
      <span class="crm-sync-dot" id="crmSyncDot"></span>
      <span id="crmSyncText">Synchronizacja</span>
    </div>
  </div>
</div>

<!-- ══ FILTER STRIP ═══════════════════════════════════════════════════════════ -->
<form method="get" action="<?= APP_URL ?>/crm/index.php" class="crm-filter-bar" role="search" aria-label="Filtry kontaktów">

  <!-- Szukaj -->
  <div class="crm-search-wrap">
    <i class="bi bi-search" aria-hidden="true"></i>
    <input type="text"
           name="q"
           value="<?= h($filters['q']) ?>"
           class="form-control"
           placeholder="Szukaj kontaktów…"
           aria-label="Szukaj kontaktów"
           autocomplete="off">
  </div>

  <!-- Status -->
  <select name="status" class="form-select" style="width:auto;min-width:130px" aria-label="Filtruj po statusie">
    <option value="">Wszystkie statusy</option>
    <?php foreach (CRM_STATUSES as $key => $s): ?>
    <option value="<?= h($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>>
      <?= h($s['label']) ?>
    </option>
    <?php endforeach; ?>
  </select>

  <!-- Typ -->
  <select name="type" class="form-select" style="width:auto;min-width:120px" aria-label="Filtruj po typie">
    <option value="">Wszystkie typy</option>
    <option value="osoba"       <?= $filters['type'] === 'osoba'       ? 'selected' : '' ?>>Osoba</option>
    <option value="organizacja" <?= $filters['type'] === 'organizacja' ? 'selected' : '' ?>>Organizacja</option>
  </select>

  <!-- Grupa -->
  <?php if ($all_groups): ?>
  <select name="group" class="form-select" style="width:auto;min-width:140px" aria-label="Filtruj po grupie">
    <option value="">Wszystkie grupy</option>
    <?php foreach ($all_groups as $g): ?>
    <option value="<?= (int)$g['id'] ?>" <?= (int)$filters['group'] === (int)$g['id'] ? 'selected' : '' ?>>
      <?= h($g['name']) ?> (<?= (int)$g['member_count'] ?>)
    </option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <button type="submit" class="btn btn-crm-primary btn-sm" aria-label="Zastosuj filtry">
    <i class="bi bi-funnel me-1"></i>Filtruj
  </button>

  <?php if (array_filter($filters)): ?>
  <a href="<?= APP_URL ?>/crm/index.php" class="btn btn-outline-secondary btn-sm" aria-label="Wyczyść filtry">
    <i class="bi bi-x-lg me-1"></i>Wyczyść
  </a>
  <?php endif; ?>

</form>

<!-- Chip tagi (szybki filtr) -->
<?php if ($all_tags): ?>
<div class="crm-filter-chips mb-1" role="navigation" aria-label="Filtruj po tagu">
  <span class="text-muted small me-1">
    <i class="bi bi-tags me-1" aria-hidden="true"></i>Tagi:
  </span>
  <?php foreach ($all_tags as $t):
    $active = $filters['tag'] === $t['tag'];
  ?>
  <a href="<?= h(crm_list_url(['tag' => $active ? '' : $t['tag'], 'page' => 1])) ?>"
     class="crm-chip <?= $active ? 'active' : '' ?>"
     aria-pressed="<?= $active ? 'true' : 'false' ?>"
     aria-label="Filtruj po tagu: <?= h($t['tag']) ?>">
    <?= h($t['tag']) ?>
    <span class="text-muted ms-1" aria-hidden="true"><?= (int)$t['cnt'] ?></span>
  </a>
  <?php endforeach; ?>
  <a href="<?= APP_URL ?>/crm/tags.php" class="text-muted ms-1"
     style="font-size:.75rem;text-decoration:none" aria-label="Zarządzaj tagami">
    <i class="bi bi-gear" aria-hidden="true"></i>
  </a>
</div>
<?php endif; ?>

<!-- Chip grupy (szybki filtr) -->
<?php if ($all_groups): ?>
<div class="crm-filter-chips mb-2" role="navigation" aria-label="Filtruj po grupie">
  <span class="text-muted small me-1">
    <i class="bi bi-collection me-1" aria-hidden="true"></i>Grupy:
  </span>
  <?php foreach ($all_groups as $g):
    $gactive = (int)$filters['group'] === (int)$g['id'];
  ?>
  <a href="<?= h(crm_list_url(['group' => $gactive ? '' : $g['id'], 'page' => 1])) ?>"
     class="crm-chip <?= $gactive ? 'active' : '' ?>"
     aria-pressed="<?= $gactive ? 'true' : 'false' ?>"
     style="<?= $gactive ? '' : 'border-color:' . $g['color'] . '55' ?>"
     aria-label="Filtruj po grupie: <?= h($g['name']) ?>">
    <i class="bi <?= h($g['icon']) ?> me-1" aria-hidden="true"
       style="color:<?= h($g['color']) ?>"></i>
    <?= h($g['name']) ?>
    <span class="text-muted ms-1" aria-hidden="true"><?= (int)$g['member_count'] ?></span>
  </a>
  <?php endforeach; ?>
  <a href="<?= APP_URL ?>/crm/groups.php" class="text-muted ms-1"
     style="font-size:.75rem;text-decoration:none" aria-label="Zarządzaj grupami">
    <i class="bi bi-gear" aria-hidden="true"></i>
  </a>
</div>
<?php endif; ?>

<!-- ══ TABELA KONTAKTÓW ════════════════════════════════════════════════════════ -->
<div class="crm-list-card" aria-label="Lista kontaktów CRM">

  <?php if ($rows): ?>
  <div class="table-responsive">
    <table class="crm-table" aria-label="Kontakty CRM — <?= $total ?> rekordów">
      <thead>
        <tr>
          <th scope="col">
            <span>Kontakt</span>
            <i class="bi bi-arrow-down-up sort-icon ms-1" aria-hidden="true"></i>
          </th>
          <th scope="col">Status</th>
          <th scope="col" class="d-none d-md-table-cell">Firma / Stanowisko</th>
          <th scope="col" class="d-none d-lg-table-cell">Tagi</th>
          <th scope="col" class="d-none d-xl-table-cell">Ostatni kontakt</th>
          <th scope="col" class="d-none d-md-table-cell">Typ</th>
          <th scope="col"><span class="visually-hidden">Akcje</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row):
          $initials = $row['avatar_initials'] ?: CrmManager::makeInitials($row['imie_nazwisko']);
          $tags     = $row['tags_csv'] ? array_filter(explode(',', $row['tags_csv'])) : [];
          $sc       = CRM_STATUSES[$row['status']] ?? ['label' => $row['status'], 'color' => '#939393'];
          $is_org   = $row['type'] === 'organizacja';
        ?>
        <tr>
          <!-- Kontakt -->
          <td>
            <div class="crm-name-cell">
              <div class="crm-avatar <?= $is_org ? 'org' : '' ?>"
                   aria-hidden="true"
                   style="background:<?= $is_org ? 'var(--crm-navy)' : 'var(--crm-primary)' ?>">
                <?= h($initials) ?>
              </div>
              <div>
                <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$row['id'] ?>"
                   class="crm-name-link"
                   aria-label="Otwórz kartę kontaktu: <?= h($row['imie_nazwisko']) ?>">
                  <?= h($row['imie_nazwisko']) ?>
                </a>
                <?php if ($row['email']): ?>
                <div class="crm-name-sub">
                  <i class="bi bi-envelope" aria-hidden="true"></i>
                  <?= h($row['email']) ?>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </td>

          <!-- Status -->
          <td>
            <?php if ($crm_can_write): ?>
            <div class="dropdown">
              <button type="button"
                      class="crm-badge crm-badge-<?= h($row['status']) ?> border-0 bg-transparent dropdown-toggle"
                      data-bs-toggle="dropdown"
                      aria-haspopup="true"
                      aria-label="Zmień status: aktualnie <?= h($sc['label']) ?>">
                <?= h($sc['label']) ?>
              </button>
              <ul class="dropdown-menu shadow-sm" style="font-size:.83rem">
                <?php foreach (CRM_STATUSES as $sk => $sv): ?>
                <li>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
                    <input type="hidden" name="_action"     value="quick_status">
                    <input type="hidden" name="contact_id"  value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="new_status"  value="<?= h($sk) ?>">
                    <button class="dropdown-item <?= $row['status'] === $sk ? 'fw-bold text-success' : '' ?>">
                      <?= h($sv['label']) ?>
                      <?= $row['status'] === $sk ? '<i class="bi bi-check2 ms-1"></i>' : '' ?>
                    </button>
                  </form>
                </li>
                <?php endforeach; ?>
              </ul>
            </div>
            <?php else: ?>
            <span class="crm-badge crm-badge-<?= h($row['status']) ?>"><?= h($sc['label']) ?></span>
            <?php endif; ?>
          </td>

          <!-- Firma / Stanowisko -->
          <td class="d-none d-md-table-cell">
            <?php if ($row['organizacja']): ?>
            <div style="font-size:.84rem"><?= h($row['organizacja']) ?></div>
            <?php endif; ?>
            <?php if ($row['stanowisko']): ?>
            <div class="crm-name-sub"><?= h($row['stanowisko']) ?></div>
            <?php endif; ?>
            <?php if (!$row['organizacja'] && !$row['stanowisko']): ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>

          <!-- Tagi -->
          <td class="d-none d-lg-table-cell">
            <?php if ($tags): ?>
            <div class="crm-tags-list">
              <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
              <a href="<?= h(crm_list_url(['tag' => $tag, 'page' => 1])) ?>"
                 class="crm-tag"
                 aria-label="Filtruj po tagu: <?= h($tag) ?>">
                <?= h($tag) ?>
              </a>
              <?php endforeach; ?>
              <?php if (count($tags) > 3): ?>
              <span class="crm-tag" style="background:#f3f3f3;border-color:#ddd;color:var(--crm-text-light)">
                +<?= count($tags) - 3 ?>
              </span>
              <?php endif; ?>
            </div>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>

          <!-- Ostatni kontakt -->
          <td class="d-none d-xl-table-cell">
            <?php if ($row['last_comm_at']): ?>
            <span style="font-size:.82rem;color:var(--crm-text-muted)">
              <i class="bi bi-chat-dots me-1 text-muted" aria-hidden="true"></i>
              <?= date_pl($row['last_comm_at']) ?>
            </span>
            <?php else: ?>
            <span class="text-muted small">Brak</span>
            <?php endif; ?>
          </td>

          <!-- Typ -->
          <td class="d-none d-md-table-cell">
            <span class="badge bg-light text-dark border" style="font-size:.72rem">
              <i class="bi bi-<?= $is_org ? 'building' : 'person' ?> me-1" aria-hidden="true"></i>
              <?= $is_org ? 'Org.' : 'Osoba' ?>
            </span>
          </td>

          <!-- Akcje -->
          <td class="text-end" style="white-space:nowrap">
            <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$row['id'] ?>"
               class="btn btn-crm-ghost btn-sm"
               aria-label="Podgląd kontaktu <?= h($row['imie_nazwisko']) ?>">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </a>
            <?php if ($crm_can_write): ?>
            <a href="<?= APP_URL ?>/crm/contact/add.php?id=<?= (int)$row['id'] ?>"
               class="btn btn-crm-ghost btn-sm"
               aria-label="Edytuj kontakt <?= h($row['imie_nazwisko']) ?>">
              <i class="bi bi-pencil" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            <?php if ($crm_can_write): ?>
            <a href="<?= APP_URL ?>/crm/communicate.php?contact_id=<?= (int)$row['id'] ?>"
               class="btn btn-crm-ghost btn-sm"
               aria-label="Wyślij wiadomość do <?= h($row['imie_nazwisko']) ?>">
              <i class="bi bi-send" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            <?php if ($crm_can_delete): ?>
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Usunąć kontakt <?= h(addslashes($row['imie_nazwisko'])) ?>?')">
              <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
              <input type="hidden" name="_action"    value="delete">
              <input type="hidden" name="contact_id" value="<?= (int)$row['id'] ?>">
              <button type="submit"
                      class="btn btn-crm-ghost btn-sm text-danger"
                      aria-label="Usuń kontakt <?= h($row['imie_nazwisko']) ?>">
                <i class="bi bi-trash" aria-hidden="true"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginacja -->
  <?php if ($paging['pages'] > 1): ?>
  <div class="p-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
    <span class="text-muted small">
      Pokazuję <?= (($paging['page'] - 1) * $per_page) + 1 ?>–<?= min($paging['page'] * $per_page, $total) ?>
      z <?= $total ?> rekordów
    </span>
    <?= pagination_html($paging) ?>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <!-- Empty state -->
  <div class="crm-empty" role="status" aria-live="polite">
    <span class="crm-empty-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
    <?php if (array_filter($filters)): ?>
    <h5>Brak wyników dla wybranych filtrów</h5>
    <p class="text-muted">Spróbuj zmienić kryteria wyszukiwania lub <a href="<?= APP_URL ?>/crm/index.php">wyczyść filtry</a>.</p>
    <?php else: ?>
    <h5>Brak kontaktów w systemie CRM</h5>
    <p class="text-muted">Zacznij od dodania pierwszego kontaktu lub zaimportuj dane.</p>
    <?php if ($crm_can_write): ?>
    <a href="<?= APP_URL ?>/crm/contact/add.php" class="btn btn-crm-primary mt-2">
      <i class="bi bi-plus-lg me-1"></i>Dodaj pierwszy kontakt
    </a>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /crm-list-card -->

<!-- ══ HEARTBEAT SYNC (SyncService JS) ═══════════════════════════════════════
     Uruchamiany tylko na stronach CRM — nie w globalnym footer.php.
     Wysyła żądanie do crm/api/heartbeat.php co 50 sekund.
     Token pobierany z data-token (server-side, nie w JS).
═══════════════════════════════════════════════════════════════════════════════ -->
<div id="crmHeartbeatData"
     data-token="<?= h(org_setting('crm_sync_token')) ?>"
     data-endpoint="<?= h(APP_URL) ?>/crm/api/heartbeat.php"
     aria-hidden="true" style="display:none"></div>

<script>
(function () {
  'use strict';

  var hb      = document.getElementById('crmHeartbeatData');
  var dot     = document.getElementById('crmSyncDot');
  var label   = document.getElementById('crmSyncText');
  if (!hb) return;

  var TOKEN    = hb.dataset.token;
  var ENDPOINT = hb.dataset.endpoint;
  var INTERVAL = 50000; // 50 sekund

  // Klucz localStorage — izolowany per-tenant (APP_URL w tokenie)
  var STORE_KEY = 'crm_last_sync_' + TOKEN.slice(0, 8);

  function getLastSync() {
    return parseInt(localStorage.getItem(STORE_KEY) || '0', 10);
  }
  function setLastSync(ts) {
    localStorage.setItem(STORE_KEY, String(ts));
  }

  function formatTime(d) {
    return d.getHours().toString().padStart(2,'0') + ':' +
           d.getMinutes().toString().padStart(2,'0') + ':' +
           d.getSeconds().toString().padStart(2,'0');
  }

  function setStatus(state, msg) {
    if (dot) dot.style.background = state === 'ok'  ? 'var(--crm-primary)' :
                                    state === 'busy' ? '#FE9339' : '#EA001E';
    if (label) label.textContent = msg;
  }

  function doSync() {
    var since = getLastSync();
    setStatus('busy', 'Synchronizacja…');

    var fd = new FormData();
    fd.append('token', TOKEN);
    fd.append('since', String(since));

    fetch(ENDPOINT, { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.ok) {
          setLastSync(data.server_ts);
          var now = new Date();
          var upd = data.count > 0 ? ' (+' + data.count + ')' : '';
          setStatus('ok', 'Zsync. ' + formatTime(now) + upd);
          // Jeśli zmiany — subtelna aktualizacja UI (bez przeładowania strony)
          if (data.count > 0) {
            var countEl = document.querySelector('.crm-object-count');
            if (countEl && data.total !== undefined) {
              countEl.textContent = data.total + ' rekord' + (data.total === 1 ? '' : data.total < 5 ? 'y' : 'ów');
            }
          }
        } else {
          setStatus('error', 'Błąd synchronizacji');
        }
      })
      .catch(function() {
        setStatus('error', 'Brak połączenia');
      });
  }

  // Pierwsze uruchomienie po 2s od załadowania strony
  setTimeout(doSync, 2000);
  // Następne co 50s
  setInterval(doSync, INTERVAL);
})();
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
