<?php
/**
 * crm/index.php — CRM: Lista Kontaktów
 *
 * AJAX: filtry, paginacja i szybkie akcje (status / usuń) bez przeładowania strony.
 *       GET ?_ajax=1 → JSON { ok, total, list_html }
 *       POST z nagłówkiem X-Requested-With: XMLHttpRequest → JSON { ok }
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');

$crm_can_write  = can_write('crm') || is_admin();
$crm_can_delete = can_delete('crm') || is_admin();

crm_migrate();

$PAGE_TITLE = 'CRM — Kontakty';

// ── Filtry ────────────────────────────────────────────────────────────────────
$filters = [
    'q'           => trim($_GET['q']           ?? ''),
    'status'      => trim($_GET['status']      ?? ''),
    'type'        => trim($_GET['type']        ?? ''),
    'tag'         => trim($_GET['tag']         ?? ''),
    'group'       => (int)($_GET['group']      ?? 0) ?: '',
    'wojewodztwo' => trim($_GET['wojewodztwo'] ?? ''),
    'action_id'   => (int)($_GET['action_id']  ?? 0) ?: '',
];
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

// ── Dane ──────────────────────────────────────────────────────────────────────
$result  = CrmManager::getContacts($filters, $page, $per_page);
$rows    = $result['rows'];
$total   = $result['total'];
$paging  = paginate($total, $per_page, $page, APP_URL . '/crm/index.php?' . http_build_query(array_filter($filters)));
$stats   = CrmManager::getStats();

$all_tags   = db_all("SELECT tag, COUNT(*) AS cnt FROM crm_tags
                      JOIN crm_contacts ON crm_contacts.id = crm_tags.contact_id
                      WHERE crm_contacts.crm_active=1
                      GROUP BY tag ORDER BY cnt DESC LIMIT 30");
$all_groups = CrmManager::getGroups();

$crm_actions = [];
try {
    $crm_actions = db_all(
        "SELECT DISTINCT a.id, a.nazwa FROM actions a
         JOIN crm_action_links al ON al.action_id=a.id
         ORDER BY a.nazwa"
    );
} catch (\Throwable $e) {}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $crm_can_write) {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $xhr    = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
              && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($action === 'quick_status' && !empty($_POST['contact_id'])) {
        $cid    = (int)$_POST['contact_id'];
        $status = trim($_POST['new_status'] ?? '');
        if (array_key_exists($status, crm_statuses())) {
            CrmManager::updateContact($cid, ['status' => $status]);
            if ($xhr) { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'new_status' => $status]); exit; }
            flash_set('success', 'Status kontaktu zaktualizowany.');
        } elseif ($xhr) { header('Content-Type: application/json'); echo json_encode(['ok' => false]); exit; }
    }

    if ($action === 'delete' && !empty($_POST['contact_id']) && $crm_can_delete) {
        CrmManager::deleteContact((int)$_POST['contact_id']);
        if ($xhr) { header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit; }
        flash_set('success', 'Kontakt usunięty.');
    }

    header('Location: ' . APP_URL . '/crm/index.php?' . http_build_query(array_filter($filters)));
    exit;
}

// ── Renderer listy (używany przez pełną stronę I przez AJAX ?_ajax=1) ─────────
function _crm_table_html(
    array $rows, int $total, array $paging, int $per_page,
    array $filters, bool $can_w, bool $can_d
): string {
    $page = $paging['page'];
    $lu   = function(array $m = []) use ($filters, $page): string {
        return APP_URL . '/crm/index.php?' . http_build_query(array_filter(array_merge(['page' => $page], $filters, $m)));
    };
    ob_start();
    ?>
    <div class="crm-list-card">
      <?php if ($rows): ?>
      <div class="table-responsive">
        <table class="crm-table" id="crmContactTable"
               aria-label="Kontakty CRM — <?= $total ?> rekordów">
          <thead>
            <tr>
              <th scope="col" style="width:36px">
                <input type="checkbox" id="chk-all" class="form-check-input"
                       aria-label="Zaznacz wszystkie kontakty"
                       onchange="Bulk.toggleAll(this.checked)">
              </th>
              <th scope="col">Kontakt <i class="bi bi-arrow-down-up sort-icon ms-1" aria-hidden="true"></i></th>
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
              $sc       = crm_statuses()[$row['status']] ?? ['label' => $row['status'], 'color' => '#939393'];
              $is_org   = $row['type'] === 'organizacja';
            ?>
            <tr data-row-href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$row['id'] ?>">
              <td>
                <input type="checkbox" class="form-check-input crm-row-chk"
                       value="<?= (int)$row['id'] ?>"
                       aria-label="Zaznacz: <?= h($row['imie_nazwisko']) ?>"
                       onchange="Bulk.update()">
              </td>

              <!-- Kontakt: awatar + imię -->
              <td>
                <div class="crm-name-cell">
                  <div class="crm-avatar <?= $is_org ? 'org' : '' ?>" aria-hidden="true"
                       style="background:<?= $is_org ? 'var(--crm-navy)' : 'var(--crm-primary)' ?>">
                    <?= h($initials) ?>
                  </div>
                  <div>
                    <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$row['id'] ?>"
                       class="crm-name-link">
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
                <?php if ($can_w): ?>
                <div class="dropdown">
                  <button type="button"
                          class="crm-badge crm-badge-<?= h($row['status']) ?> border-0 bg-transparent dropdown-toggle"
                          data-bs-toggle="dropdown"
                          aria-haspopup="true"
                          aria-expanded="false"
                          aria-label="Status: <?= h($sc['label']) ?> — kliknij aby zmienić">
                    <?= h($sc['label']) ?>
                  </button>
                  <ul class="dropdown-menu shadow-sm" style="font-size:.83rem">
                    <?php foreach (crm_statuses() as $sk => $sv): ?>
                    <li>
                      <form method="post" class="d-inline" data-ajax-action="quick_status">
                        <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
                        <input type="hidden" name="_action"    value="quick_status">
                        <input type="hidden" name="contact_id" value="<?= (int)$row['id'] ?>">
                        <input type="hidden" name="new_status" value="<?= h($sk) ?>">
                        <button type="submit"
                                class="dropdown-item <?= $row['status'] === $sk ? 'fw-bold text-success' : '' ?>">
                          <?= h($sv['label']) ?>
                          <?= $row['status'] === $sk ? '<i class="bi bi-check2 ms-1" aria-hidden="true"></i>' : '' ?>
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
                  <a href="<?= h($lu(['tag' => $tag, 'page' => 1])) ?>"
                     class="crm-tag"
                     aria-label="Filtruj po tagu: <?= h($tag) ?>">
                    <?= h($tag) ?>
                  </a>
                  <?php endforeach; ?>
                  <?php if (count($tags) > 3): ?>
                  <span class="crm-tag" style="background:#f3f3f3;border-color:#ddd;color:var(--crm-text-light)"
                        aria-label="+<?= count($tags) - 3 ?> więcej tagów">
                    +<?= count($tags) - 3 ?>
                  </span>
                  <?php endif; ?>
                </div>
                <?php else: ?>
                <span class="text-muted" aria-label="Brak tagów">—</span>
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
                   aria-label="Podgląd: <?= h($row['imie_nazwisko']) ?>">
                  <i class="bi bi-eye" aria-hidden="true"></i>
                </a>
                <?php if ($can_w): ?>
                <a href="<?= APP_URL ?>/crm/contact/add.php?id=<?= (int)$row['id'] ?>"
                   class="btn btn-crm-ghost btn-sm"
                   aria-label="Edytuj: <?= h($row['imie_nazwisko']) ?>">
                  <i class="bi bi-pencil" aria-hidden="true"></i>
                </a>
                <button type="button"
                        class="btn btn-crm-ghost btn-sm"
                        aria-label="Wyślij wiadomość do <?= h($row['imie_nazwisko']) ?>"
                        onclick="openCommModal(<?= (int)$row['id'] ?>)">
                  <i class="bi bi-send" aria-hidden="true"></i>
                </button>
                <?php endif; ?>
                <?php if ($can_d): ?>
                <form method="post" class="d-inline"
                      data-ajax-action="delete"
                      data-contact-name="<?= h($row['imie_nazwisko']) ?>">
                  <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action"    value="delete">
                  <input type="hidden" name="contact_id" value="<?= (int)$row['id'] ?>">
                  <button type="submit"
                          class="btn btn-crm-ghost btn-sm text-danger"
                          aria-label="Usuń: <?= h($row['imie_nazwisko']) ?>">
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
        <nav aria-label="Strony wyników"><?= pagination_html($paging) ?></nav>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <!-- Empty state -->
      <div class="crm-empty" role="status" aria-live="polite">
        <span class="crm-empty-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
        <?php if (array_filter($filters)): ?>
        <h5>Brak wyników dla wybranych filtrów</h5>
        <p class="text-muted">Spróbuj zmienić kryteria lub <a href="<?= APP_URL ?>/crm/index.php">wyczyść filtry</a>.</p>
        <?php elseif (!crm_access_unrestricted() && empty(crm_accessible_group_ids())): ?>
        <h5>Brak dostępu do kontaktów</h5>
        <p class="text-muted">Twoje konto nie jest przypisane do żadnej grupy CRM. Skontaktuj się z administratorem.</p>
        <?php else: ?>
        <h5>Brak kontaktów w systemie CRM</h5>
        <p class="text-muted">Zacznij od dodania pierwszego kontaktu lub zaimportuj dane.</p>
        <?php if ($can_w): ?>
        <a href="<?= APP_URL ?>/crm/contact/add.php" class="btn btn-crm-primary mt-2">
          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj pierwszy kontakt
        </a>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ── AJAX fragment endpoint ────────────────────────────────────────────────────
if (isset($_GET['_ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok'        => true,
        'total'     => $total,
        'list_html' => _crm_table_html($rows, $total, $paging, $per_page, $filters, $crm_can_write, $crm_can_delete),
    ]);
    exit;
}

// ── Pełna strona ──────────────────────────────────────────────────────────────
include __DIR__ . '/includes/header_crm.php';
?>

<!-- ══ STATYSTYKI ═══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="crm-stat-card">
      <div class="crm-stat-value"><?= $stats['total'] ?></div>
      <div class="crm-stat-label">
        <i class="bi bi-people-fill me-1" style="color:var(--crm-primary)" aria-hidden="true"></i>Wszystkich kontaktów
      </div>
      <?php if ($stats['new_this_month'] > 0): ?>
      <div class="crm-stat-delta up">
        <i class="bi bi-arrow-up-short" aria-hidden="true"></i><?= $stats['new_this_month'] ?> nowych w tym miesiącu
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php foreach (array_slice($stats['by_status'], 0, 3) as $bs):
    $sc = crm_statuses()[$bs['status']] ?? ['label' => $bs['status'], 'color' => '#939393'];
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
  <div class="crm-object-icon" aria-hidden="true"><i class="bi bi-diagram-2-fill"></i></div>
  <div>
    <h1 class="crm-object-title">Kontakty CRM</h1>
    <div class="crm-object-count" id="crm-total-count" aria-live="polite" aria-atomic="true">
      <?= $total ?> <?= $total === 1 ? 'rekord' : ($total < 5 ? 'rekordy' : 'rekordów') ?>
    </div>
  </div>
  <div class="crm-object-actions">
    <?php if ($crm_can_write): ?>
    <a href="<?= APP_URL ?>/crm/contact/quick_add.php" class="btn btn-crm-primary btn-sm">
      <i class="bi bi-lightning-fill me-1" aria-hidden="true"></i>Szybkie +
    </a>
    <a href="<?= APP_URL ?>/crm/contact/add.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Pełny
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/crm/communicate.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij wiadomość
    </a>
    <?php $export_q = http_build_query(array_filter($filters)); ?>
    <div class="dropdown">
      <button class="btn btn-crm-outline btn-sm dropdown-toggle"
              data-bs-toggle="dropdown"
              aria-expanded="false"
              aria-haspopup="true">
        <i class="bi bi-download me-1" aria-hidden="true"></i>Eksport
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size:.83rem">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/export.php?<?= $export_q ?>">
          <i class="bi bi-filetype-csv me-2 text-muted" aria-hidden="true"></i>CSV (Excel-kompatybilny)
        </a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/export.php?format=xlsx&<?= $export_q ?>">
          <i class="bi bi-file-earmark-spreadsheet me-2 text-success" aria-hidden="true"></i>Excel (.xlsx)
        </a></li>
      </ul>
    </div>
    <div class="crm-sync-label ms-2" id="crmSyncStatus"
         title="Synchronizacja heartbeat co 50 s"
         aria-live="polite" aria-atomic="true">
      <span class="crm-sync-dot" id="crmSyncDot" aria-hidden="true"></span>
      <span id="crmSyncText">Synchronizacja</span>
    </div>
  </div>
</div>

<!-- ══ FILTER STRIP ═══════════════════════════════════════════════════════════ -->
<form id="crm-filter-form" method="get" action="<?= APP_URL ?>/crm/index.php"
      class="crm-filter-bar" role="search" aria-label="Filtry kontaktów">

  <div class="crm-search-wrap">
    <i class="bi bi-search" aria-hidden="true"></i>
    <input type="text"
           name="q"
           id="crm-search-input"
           value="<?= h($filters['q']) ?>"
           class="form-control"
           placeholder="Szukaj kontaktów…"
           aria-label="Szukaj kontaktów"
           autocomplete="off">
  </div>

  <select name="status" class="form-select" style="width:auto;min-width:130px"
          aria-label="Filtruj po statusie">
    <option value="">Wszystkie statusy</option>
    <?php foreach (crm_statuses() as $key => $s): ?>
    <option value="<?= h($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>>
      <?= h($s['label']) ?>
    </option>
    <?php endforeach; ?>
  </select>

  <select name="type" class="form-select" style="width:auto;min-width:120px"
          aria-label="Filtruj po typie">
    <option value="">Wszystkie typy</option>
    <option value="osoba"       <?= $filters['type'] === 'osoba'       ? 'selected' : '' ?>>Osoba</option>
    <option value="organizacja" <?= $filters['type'] === 'organizacja' ? 'selected' : '' ?>>Organizacja</option>
  </select>

  <?php
  $woj_in_crm = db_all("SELECT DISTINCT wojewodztwo FROM crm_contacts
                         WHERE crm_active=1 AND wojewodztwo IS NOT NULL AND wojewodztwo != ''
                         ORDER BY wojewodztwo");
  if ($woj_in_crm):
  ?>
  <select name="wojewodztwo" class="form-select" style="width:auto;min-width:150px"
          aria-label="Filtruj po województwie">
    <option value="">Wszystkie woj.</option>
    <?php foreach ($woj_in_crm as $w): ?>
    <option value="<?= h($w['wojewodztwo']) ?>"
            <?= $filters['wojewodztwo'] === $w['wojewodztwo'] ? 'selected' : '' ?>>
      <?= h(ucfirst($w['wojewodztwo'])) ?>
    </option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <?php if ($crm_actions): ?>
  <select name="action_id" class="form-select" style="width:auto;min-width:160px"
          aria-label="Filtruj po działaniu">
    <option value="">Wszystkie działania</option>
    <?php foreach ($crm_actions as $a): ?>
    <option value="<?= (int)$a['id'] ?>"
            <?= (int)$filters['action_id'] === (int)$a['id'] ? 'selected' : '' ?>>
      <?= h($a['nazwa']) ?>
    </option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <?php if ($all_groups): ?>
  <select name="group" class="form-select" style="width:auto;min-width:140px"
          aria-label="Filtruj po grupie">
    <option value="">Wszystkie grupy</option>
    <?php foreach ($all_groups as $g): ?>
    <option value="<?= (int)$g['id'] ?>"
            <?= (int)$filters['group'] === (int)$g['id'] ? 'selected' : '' ?>>
      <?= h($g['name']) ?> (<?= (int)$g['member_count'] ?>)
    </option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <button type="submit" class="btn btn-crm-primary btn-sm">
    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtruj
  </button>

  <?php if (array_filter($filters)): ?>
  <a href="<?= APP_URL ?>/crm/index.php"
     class="btn btn-outline-secondary btn-sm"
     aria-label="Wyczyść wszystkie filtry">
    <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Wyczyść
  </a>
  <?php endif; ?>

</form>

<!-- Chip tagi -->
<?php if ($all_tags): ?>
<div class="crm-filter-chips mb-1" role="group" aria-label="Filtruj po tagu">
  <span class="text-muted small me-1" aria-hidden="true">
    <i class="bi bi-tags me-1"></i>Tagi:
  </span>
  <?php foreach ($all_tags as $t):
    $active = $filters['tag'] === $t['tag'];
    $chip_url = APP_URL . '/crm/index.php?' . http_build_query(array_filter(array_merge($filters, ['tag' => $active ? '' : $t['tag'], 'page' => 1])));
  ?>
  <a href="<?= h($chip_url) ?>"
     class="crm-chip <?= $active ? 'active' : '' ?>"
     aria-pressed="<?= $active ? 'true' : 'false' ?>"
     aria-label="<?= $active ? 'Usuń filtr: ' : 'Filtruj po tagu: ' ?><?= h($t['tag']) ?>">
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

<!-- Chip grupy -->
<?php if ($all_groups): ?>
<div class="crm-filter-chips mb-2" role="group" aria-label="Filtruj po grupie">
  <span class="text-muted small me-1" aria-hidden="true">
    <i class="bi bi-collection me-1"></i>Grupy:
  </span>
  <?php foreach ($all_groups as $g):
    $gactive  = (int)$filters['group'] === (int)$g['id'];
    $gchip_url = APP_URL . '/crm/index.php?' . http_build_query(array_filter(array_merge($filters, ['group' => $gactive ? '' : $g['id'], 'page' => 1])));
  ?>
  <a href="<?= h($gchip_url) ?>"
     class="crm-chip <?= $gactive ? 'active' : '' ?>"
     aria-pressed="<?= $gactive ? 'true' : 'false' ?>"
     aria-label="<?= $gactive ? 'Usuń filtr grupy: ' : 'Filtruj po grupie: ' ?><?= h($g['name']) ?>"
     style="<?= $gactive ? '' : 'border-color:' . h($g['color']) . '55' ?>">
    <i class="bi <?= h($g['icon']) ?> me-1" aria-hidden="true" style="color:<?= h($g['color']) ?>"></i>
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

<!-- ══ LISTA — region AJAX ════════════════════════════════════════════════════ -->
<!-- Ukryty live region — ogłoszenia dla czytników ekranu -->
<div id="crm-live" role="status" aria-live="polite" aria-atomic="true"
     class="visually-hidden"></div>

<div id="crm-list-region" aria-label="Wyniki listy kontaktów">
  <?= _crm_table_html($rows, $total, $paging, $per_page, $filters, $crm_can_write, $crm_can_delete) ?>
</div>

<!-- ══ HEARTBEAT ══════════════════════════════════════════════════════════════ -->
<div id="crmHeartbeatData"
     data-token="<?= h(org_setting('crm_sync_token')) ?>"
     data-endpoint="<?= h(APP_URL) ?>/crm/api/heartbeat.php"
     aria-hidden="true" style="display:none"></div>

<script>
(function () {
  'use strict';
  var hb = document.getElementById('crmHeartbeatData');
  var dot = document.getElementById('crmSyncDot');
  var lbl = document.getElementById('crmSyncText');
  if (!hb) return;
  var TOKEN = hb.dataset.token, ENDPOINT = hb.dataset.endpoint, INTERVAL = 50000;
  var STORE_KEY = 'crm_last_sync_' + TOKEN.slice(0, 8);
  function getLastSync() { return parseInt(localStorage.getItem(STORE_KEY) || '0', 10); }
  function setLastSync(ts) { localStorage.setItem(STORE_KEY, String(ts)); }
  function fmt(d) {
    return d.getHours().toString().padStart(2, '0') + ':' +
           d.getMinutes().toString().padStart(2, '0') + ':' +
           d.getSeconds().toString().padStart(2, '0');
  }
  function setStatus(st, msg) {
    if (dot) dot.style.background = st === 'ok' ? 'var(--crm-primary)' : st === 'busy' ? '#FE9339' : '#EA001E';
    if (lbl) lbl.textContent = msg;
  }
  function doSync() {
    setStatus('busy', 'Synchronizacja…');
    var fd = new FormData();
    fd.append('token', TOKEN);
    fd.append('since', String(getLastSync()));
    fetch(ENDPOINT, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) {
          setLastSync(d.server_ts);
          var upd = d.count > 0 ? ' (+' + d.count + ')' : '';
          setStatus('ok', 'Zsync. ' + fmt(new Date()) + upd);
          if (d.count > 0 && window._CrmList) {
            window._CrmList.refresh(); // odśwież listę po wykryciu zmian
          }
        } else {
          setStatus('error', 'Błąd synchronizacji');
        }
      })
      .catch(function () { setStatus('error', 'Brak połączenia'); });
  }
  setTimeout(doSync, 2000);
  setInterval(doSync, INTERVAL);
})();
</script>

<!-- ══ BULK ACTIONS ══════════════════════════════════════════════════════════ -->
<div id="bulk-bar"
     style="display:none;position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);
            z-index:1060;background:#1e293b;color:#fff;border-radius:12px;
            padding:.65rem 1.25rem;box-shadow:0 8px 32px rgba(0,0,0,.28);
            align-items:center;gap:.75rem;flex-wrap:wrap;min-width:340px"
     role="toolbar" aria-label="Akcje dla zaznaczonych kontaktów">
  <span id="bulk-count" class="fw-semibold"
        style="font-size:.85rem;white-space:nowrap" aria-live="polite">0 zaznaczonych</span>

  <div class="dropdown">
    <button class="btn btn-sm btn-outline-light" data-bs-toggle="dropdown"
            aria-haspopup="true" aria-expanded="false">
      <i class="bi bi-bookmark me-1" aria-hidden="true"></i>Status
    </button>
    <ul class="dropdown-menu dropdown-menu-dark">
      <?php foreach (crm_statuses() as $sk => $sv): ?>
      <li>
        <a class="dropdown-item" href="#"
           onclick="Bulk.do('set_status','<?= h($sk) ?>');return false">
          <span aria-hidden="true"
                style="display:inline-block;width:10px;height:10px;border-radius:50%;
                       background:<?= h($sv['color']) ?>;margin-right:.4rem;vertical-align:middle"></span>
          <?= h($sv['label']) ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="d-flex gap-1">
    <input type="text" id="bulk-tag-inp" placeholder="Tag…"
           class="form-control form-control-sm"
           aria-label="Wpisz tag i naciśnij Enter"
           style="width:110px;background:#334155;border-color:#475569;color:#fff"
           onkeydown="if(event.key==='Enter'){Bulk.do('add_tag',this.value);this.value='';}">
    <button class="btn btn-sm btn-outline-light" aria-label="Dodaj tag do zaznaczonych"
            onclick="Bulk.do('add_tag',document.getElementById('bulk-tag-inp').value);document.getElementById('bulk-tag-inp').value=''">
      <i class="bi bi-tag" aria-hidden="true"></i>
    </button>
  </div>

  <?php if ($all_groups): ?>
  <div class="dropdown">
    <button class="btn btn-sm btn-outline-light" data-bs-toggle="dropdown"
            aria-haspopup="true" aria-expanded="false">
      <i class="bi bi-people me-1" aria-hidden="true"></i>Grupa
    </button>
    <ul class="dropdown-menu dropdown-menu-dark" style="max-height:220px;overflow-y:auto">
      <?php foreach ($all_groups as $g): ?>
      <li>
        <a class="dropdown-item" href="#"
           onclick="Bulk.do('add_to_group',<?= (int)$g['id'] ?>);return false">
          <i class="bi <?= h($g['icon']) ?>" aria-hidden="true"
             style="color:<?= h($g['color']) ?>"></i>
          <?= h($g['name']) ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($crm_can_delete): ?>
  <button class="btn btn-sm btn-outline-danger" aria-label="Usuń zaznaczone kontakty"
          onclick="Bulk.do('delete',null)">
    <i class="bi bi-trash3" aria-hidden="true"></i>
  </button>
  <?php endif; ?>

  <button class="btn btn-sm btn-link text-light p-0 ms-1"
          onclick="Bulk.clear()" aria-label="Odznacz wszystkie">
    <i class="bi bi-x-lg" aria-hidden="true"></i>
  </button>
</div>

<script>
/* ── Bulk actions ─────────────────────────────────────────────────────────── */
const Bulk = (function () {
  const bar   = document.getElementById('bulk-bar');
  const count = document.getElementById('bulk-count');
  const API   = '<?= APP_URL ?>/crm/api/bulk.php';
  const CSRF  = '<?= csrf_token() ?>';

  function getChecked() {
    return [...document.querySelectorAll('.crm-row-chk:checked')].map(c => parseInt(c.value));
  }

  function update() {
    const ids   = getChecked();
    const n     = ids.length;
    bar.style.display = n ? 'flex' : 'none';
    count.textContent = n + ' zaznaczon' + (n === 1 ? 'y' : 'ych');
    const all = document.getElementById('chk-all');
    if (all) {
      const total = document.querySelectorAll('.crm-row-chk').length;
      all.indeterminate = n > 0 && n < total;
      all.checked = n === total && n > 0;
    }
  }

  function toggleAll(checked) {
    document.querySelectorAll('.crm-row-chk').forEach(c => c.checked = checked);
    update();
  }

  function clear() {
    document.querySelectorAll('.crm-row-chk, #chk-all').forEach(c => c.checked = false);
    update();
  }

  async function doAction(action, value) {
    const ids = getChecked();
    if (!ids.length) return;
    if (action === 'delete' && !confirm('Usunąć ' + ids.length + ' kontaktów? Operacja jest odwracalna przez administratora.')) return;
    if (action === 'add_tag' && !value?.trim()) return;

    const res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, ids, value: value ?? '', _csrf: CSRF }),
    }).then(r => r.json());

    if (res.ok) {
      const msgs = {
        set_status:   `Zmieniono status dla ${res.affected} kontaktów.`,
        add_tag:      `Dodano tag do ${res.affected} kontaktów.`,
        remove_tag:   `Usunięto tag z ${res.affected} kontaktów.`,
        add_to_group: `Dodano ${res.affected} kontaktów do grupy.`,
        delete:       `Usunięto ${res.affected} kontaktów.`,
      };
      const url = new URL(location.href);
      url.searchParams.set('_bulk_ok', msgs[action] || 'Operacja wykonana.');
      location.replace(url.toString());
    } else {
      alert('Błąd: ' + (res.error || 'Nieznany błąd'));
    }
  }

  // Flash po bulk action
  const bp = new URLSearchParams(location.search).get('_bulk_ok');
  if (bp) {
    const div = document.createElement('div');
    div.className = 'alert alert-success alert-dismissible py-2 px-3 mb-2';
    div.setAttribute('role', 'status');
    div.innerHTML = `<i class="bi bi-check-circle me-1" aria-hidden="true"></i>${bp}`
      + `<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Zamknij"></button>`;
    document.querySelector('.crm-object-header')?.insertAdjacentElement('afterend', div);
    const clean = new URL(location.href);
    clean.searchParams.delete('_bulk_ok');
    history.replaceState({}, '', clean.toString());
  }

  return { update, toggleAll, clear, do: doAction };
})();
</script>

<script>
/* ── AJAX filter + pagination + szybkie akcje ─────────────────────────────── */
(function () {
  'use strict';

  var region  = document.getElementById('crm-list-region');
  var form    = document.getElementById('crm-filter-form');
  var countEl = document.getElementById('crm-total-count');
  var liveEl  = document.getElementById('crm-live');

  if (!region || !form) return;

  var abort    = null;
  var debounce = null;

  /* ── Ładuje fragment listy ─────────────────────── */
  function load(params, push) {
    if (abort) { try { abort.abort(); } catch (e) {} }
    abort = (typeof AbortController !== 'undefined') ? new AbortController() : null;

    region.setAttribute('aria-busy', 'true');
    region.style.opacity = '0.5';
    region.style.transition = 'opacity .15s';
    region.style.pointerEvents = 'none';

    var url = new URL(window.location.pathname, window.location.origin);
    params.forEach(function (v, k) { if (v) url.searchParams.set(k, v); });
    url.searchParams.set('_ajax', '1');

    var opts = abort ? { signal: abort.signal } : {};

    fetch(url.toString(), opts)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) return;

        region.innerHTML = data.list_html;

        if (countEl && data.total !== undefined) {
          var t = data.total;
          countEl.textContent = t + ' ' + (t === 1 ? 'rekord' : t < 5 ? 'rekordy' : 'rekordów');
        }

        if (push !== false) {
          var histUrl = new URL(window.location.href);
          histUrl.search = params.toString();
          history.pushState({ crm: params.toString() }, '', histUrl.toString());
        }

        region.removeAttribute('aria-busy');
        region.style.opacity = '1';
        region.style.pointerEvents = '';

        bindRegion();
        Bulk.clear();

        if (liveEl) {
          liveEl.textContent = '';
          setTimeout(function () {
            var t = data.total || 0;
            liveEl.textContent = 'Załadowano ' + t + ' ' + (t === 1 ? 'kontakt' : 'kontaktów') + '.';
          }, 50);
        }
      })
      .catch(function (e) {
        if (e && e.name === 'AbortError') return;
        region.removeAttribute('aria-busy');
        region.style.opacity = '1';
        region.style.pointerEvents = '';
      });
  }

  /* ── Parametry z formularza filtrów ─────────────── */
  function formParams() {
    var p = new URLSearchParams();
    new FormData(form).forEach(function (v, k) { if (v) p.set(k, v); });
    return p;
  }

  /* ── Synchronizuje pola formularza z URLSearchParams ── */
  function syncForm(params) {
    form.querySelectorAll('select, input[type="text"]').forEach(function (el) {
      if (el.name) el.value = params.get(el.name) || '';
    });
  }

  /* ── Binduje zdarzenia w dynamicznie ładowanym regionie ─ */
  function bindRegion() {
    // Paginacja
    region.querySelectorAll('a.page-link').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var href = a.getAttribute('href');
        if (!href) return;
        var p = new URLSearchParams(new URL(href, window.location.href).search);
        load(p, true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    // Zmiana statusu (AJAX)
    region.querySelectorAll('form[data-ajax-action="quick_status"]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(f);
        fetch(window.location.pathname, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: fd,
        })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (d.ok) load(formParams(), false); })
          .catch(function () {});
      });
    });

    // Usunięcie kontaktu (AJAX)
    region.querySelectorAll('form[data-ajax-action="delete"]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var name = f.dataset.contactName || 'kontakt';
        if (!confirm('Usunąć kontakt ' + name + '?')) return;
        var fd = new FormData(f);
        fetch(window.location.pathname, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: fd,
        })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (d.ok) load(formParams(), false); })
          .catch(function () {});
      });
    });

    // Kliknięcie na wierszu → otwórz kartę kontaktu
    region.querySelectorAll('tr[data-row-href]').forEach(function (tr) {
      tr.style.cursor = 'pointer';
      tr.addEventListener('click', function (e) {
        if (e.target.closest('a,button,input,label,[data-copy]')) return;
        window.location.href = tr.dataset.rowHref;
      });
    });

    // Checkboxy bulk
    region.querySelectorAll('.crm-row-chk').forEach(function (chk) {
      chk.addEventListener('change', function () { Bulk.update(); });
    });
  }

  /* ── Submit formularza ───────────────────────────── */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    load(formParams(), true);
  });

  /* ── Select → auto-załaduj ───────────────────────── */
  form.querySelectorAll('select').forEach(function (sel) {
    sel.addEventListener('change', function () { load(formParams(), true); });
  });

  /* ── Szukaj — debounce 380 ms ────────────────────── */
  var searchInp = form.querySelector('input[name="q"]');
  if (searchInp) {
    searchInp.addEventListener('input', function () {
      clearTimeout(debounce);
      debounce = setTimeout(function () { load(formParams(), true); }, 380);
    });
    searchInp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(debounce);
        load(formParams(), true);
      }
    });
  }

  /* ── Chipy tagów i grup ─────────────────────────── */
  document.querySelectorAll('.crm-filter-chips .crm-chip').forEach(function (chip) {
    chip.addEventListener('click', function (e) {
      e.preventDefault();
      var href = chip.getAttribute('href');
      if (!href) return;
      var p = new URLSearchParams(new URL(href, window.location.href).search);
      syncForm(p);
      // Odśwież stan wizualny chipów
      var parent = chip.closest('.crm-filter-chips');
      if (parent) {
        var wasActive = chip.classList.contains('active');
        parent.querySelectorAll('.crm-chip').forEach(function (c) {
          c.classList.remove('active');
          c.setAttribute('aria-pressed', 'false');
        });
        if (!wasActive) {
          chip.classList.add('active');
          chip.setAttribute('aria-pressed', 'true');
        }
      }
      load(p, true);
    });
  });

  /* ── Przeglądarka: wstecz / dalej ───────────────── */
  window.addEventListener('popstate', function (e) {
    var p = (e.state && e.state.crm !== undefined)
      ? new URLSearchParams(e.state.crm)
      : new URLSearchParams(window.location.search);
    syncForm(p);
    load(p, false);
  });

  /* ── Inicjalizacja + eksport dla heartbeatu ─────── */
  bindRegion();
  window._CrmList = {
    load: function (p) { load(p || formParams(), false); },
    refresh: function () { load(formParams(), false); },
  };
})();
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
