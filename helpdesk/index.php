<?php
/**
 * helpdesk/index.php — Lista zgłoszeń helpdesku.
 *
 * Wzorzec CRM: crm-object-header + crm-filter-bar + tabela AJAX (?_ajax=1).
 * Kliknięcie w wiersz → helpdesk/view.php?id=X (pełna strona).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();
require_login();

$u      = current_user();
$uid    = (int)$u['id'];
$is_op  = hd_is_operator();

// ── Filtry ────────────────────────────────────────────────────────────────────
$f_q        = trim($_GET['q'] ?? '');
$f_status   = $_GET['status'] ?? '';
$f_category = $_GET['category'] ?? '';
$f_priority = $_GET['priority'] ?? '';
$f_view     = ($is_op && in_array($_GET['view'] ?? '', ['all', 'unassigned', 'mine'], true))
            ? $_GET['view'] : ($is_op ? 'all' : 'mine');
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 25;

$where  = ['1=1']; $params = [];
if (!$is_op || $f_view === 'mine') {
    $where[] = 't.requester_id = ?'; $params[] = $uid;
} elseif ($f_view === 'unassigned') {
    $where[] = 't.assigned_to IS NULL';
    $where[] = "t.status NOT IN ('zamknięte')";
}
if ($f_status && isset(HD_STATUSES[$f_status]))       { $where[] = 't.status = ?';   $params[] = $f_status; }
if ($f_category && isset(HD_CATEGORIES[$f_category])) { $where[] = 't.category = ?'; $params[] = $f_category; }
if ($f_priority && isset(HD_PRIORITIES[$f_priority])) { $where[] = 't.priority = ?'; $params[] = $f_priority; }
if ($f_q !== '') {
    $where[] = '(t.number LIKE ? OR t.title LIKE ? OR t.requester_name LIKE ?)';
    $like = '%' . $f_q . '%'; $params[] = $like; $params[] = $like; $params[] = $like;
}
$where_sql = implode(' AND ', $where);

$total_all = (int)(db_one(
    "SELECT COUNT(*) AS c FROM helpdesk_tickets t WHERE {$where_sql}", $params
)['c'] ?? 0);

$offset = ($page - 1) * $per_page;
$tickets = db_all(
    "SELECT t.*, u.name AS assigned_name
     FROM helpdesk_tickets t LEFT JOIN users u ON u.id = t.assigned_to
     WHERE {$where_sql}
     ORDER BY CASE t.status WHEN 'nowe' THEN 0 WHEN 'otwarte' THEN 1 WHEN 'oczekuje' THEN 2 ELSE 3 END,
              t.updated_at DESC
     LIMIT {$per_page} OFFSET {$offset}", $params);

$paging = paginate($total_all, $per_page, $page,
    APP_URL . '/helpdesk/index.php?' . http_build_query(array_filter([
        'q' => $f_q, 'status' => $f_status, 'category' => $f_category,
        'priority' => $f_priority, 'view' => $f_view,
    ])));

$unread_ids  = $is_op ? hd_unread_ids($uid) : [];
$unread_set  = array_flip($unread_ids);
$cnt_unread  = count($unread_ids);

// ── Statystyki ────────────────────────────────────────────────────────────────
$stats = [];
try {
    $stat_rows = db_all(
        "SELECT status, COUNT(*) AS c FROM helpdesk_tickets
         " . ($is_op ? '' : "WHERE requester_id={$uid}") . "
         GROUP BY status", []);
    foreach ($stat_rows as $s) $stats[$s['status']] = (int)$s['c'];
} catch (\Throwable $e) {}
$cnt_total      = array_sum($stats);
$cnt_nowe       = ($stats['nowe'] ?? 0) + ($stats['otwarte'] ?? 0);
$cnt_oczekuje   = $stats['oczekuje'] ?? 0;
$cnt_unassigned = $is_op ? (int)(db_one(
    "SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE assigned_to IS NULL AND status NOT IN ('zamknięte')", []
)['c'] ?? 0) : 0;

// ── Renderer tabeli (strona pełna + AJAX) ────────────────────────────────────
function _hd_table_html(
    array $tickets, int $total, array $paging, int $per_page,
    bool $is_op, array $unread_set, string $f_view
): string {
    ob_start(); ?>
    <div class="crm-list-card">
    <?php if ($tickets): ?>
    <div class="table-responsive">
      <table class="crm-table" id="hdTicketTable" aria-label="Zgłoszenia helpdesk — <?= $total ?> rekordów">
        <thead>
          <tr>
            <?php if ($is_op): ?>
            <th scope="col" style="width:36px">
              <input type="checkbox" id="hdChkAll" class="form-check-input"
                     aria-label="Zaznacz wszystkie" onchange="HdBulk.toggleAll(this.checked)">
            </th>
            <?php endif; ?>
            <th scope="col">Numer</th>
            <th scope="col">Tytuł</th>
            <th scope="col">Status</th>
            <th scope="col" class="d-none d-md-table-cell">Priorytet</th>
            <th scope="col" class="d-none d-lg-table-cell">Kategoria</th>
            <th scope="col" class="d-none d-md-table-cell">Zgłaszający</th>
            <?php if ($is_op): ?>
            <th scope="col" class="d-none d-lg-table-cell">Operator</th>
            <?php endif; ?>
            <th scope="col" class="d-none d-xl-table-cell">Zaktualizowano</th>
            <th scope="col"><span class="visually-hidden">Akcje</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tickets as $t):
            $unread   = isset($unread_set[(int)$t['id']]);
            $st       = HD_STATUSES[$t['status']] ?? ['label' => $t['status'], 'class' => 'secondary', 'icon' => 'bi-circle'];
            $pr       = HD_PRIORITIES[$t['priority']] ?? ['label' => $t['priority'], 'class' => 'secondary'];
            $sla      = hd_sla($t);
            $age      = max(0, time() - strtotime($t['updated_at'] ?? 'now'));
            $ago      = $age < 3600 ? max(1,(int)($age/60)).'min'
                      : ($age < 86400 ? (int)($age/3600).'godz' : (int)($age/86400).'dni');
          ?>
          <tr data-row-href="<?= APP_URL ?>/helpdesk/view.php?id=<?= (int)$t['id'] ?>"
              class="<?= $unread ? 'hd-row-unread' : '' ?>">
            <?php if ($is_op): ?>
            <td>
              <input type="checkbox" class="form-check-input hd-row-chk"
                     value="<?= (int)$t['id'] ?>"
                     aria-label="Zaznacz zgłoszenie <?= h($t['number']) ?>"
                     onchange="HdBulk.update()">
            </td>
            <?php endif; ?>
            <td style="white-space:nowrap">
              <?php if ($unread): ?>
              <span class="hd-unread-dot me-1" title="Nieprzeczytane" aria-label="Nieprzeczytane"></span>
              <?php endif; ?>
              <span class="font-monospace fw-bold" style="font-size:.78rem;color:#64748b"><?= h($t['number']) ?></span>
            </td>
            <td>
              <a href="<?= APP_URL ?>/helpdesk/view.php?id=<?= (int)$t['id'] ?>"
                 class="crm-name-link<?= $unread ? ' fw-bold' : '' ?>">
                <?= h($t['title']) ?>
              </a>
              <?php if (!empty($t['requester_email'])): ?>
              <div class="crm-name-sub"><i class="bi bi-envelope me-1" aria-hidden="true"></i><?= h($t['requester_email']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= hd_status_badge($t['status']) ?></td>
            <td class="d-none d-md-table-cell"><?= hd_priority_badge($t['priority']) ?></td>
            <td class="d-none d-lg-table-cell">
              <span class="badge bg-light text-dark border" style="font-size:.72rem">
                <?= h(HD_CATEGORIES[$t['category']] ?? $t['category']) ?>
              </span>
            </td>
            <td class="d-none d-md-table-cell" style="font-size:.82rem"><?= h($t['requester_name']) ?></td>
            <?php if ($is_op): ?>
            <td class="d-none d-lg-table-cell" style="font-size:.82rem">
              <?php if ($t['assigned_name']): ?>
              <span class="text-success"><i class="bi bi-person-check me-1" aria-hidden="true"></i><?= h($t['assigned_name']) ?></span>
              <?php else: ?>
              <span class="text-danger small"><i class="bi bi-exclamation-circle me-1"></i>Brak</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="d-none d-xl-table-cell">
              <span style="font-size:.78rem;color:#94a3b8"><?= $ago ?> temu</span>
              <?php if ($is_op): ?>
              <div><?= hd_sla_indicator($t) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-end" style="white-space:nowrap">
              <a href="<?= APP_URL ?>/helpdesk/view.php?id=<?= (int)$t['id'] ?>"
                 class="btn btn-crm-ghost btn-sm"
                 aria-label="Otwórz zgłoszenie <?= h($t['number']) ?>">
                <i class="bi bi-eye" aria-hidden="true"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($paging['pages'] > 1): ?>
    <div class="p-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
      <span class="text-muted small">
        Pokazuję <?= (($paging['page']-1)*$per_page)+1 ?>–<?= min($paging['page']*$per_page, $total) ?>
        z <?= $total ?> zgłoszeń
      </span>
      <nav aria-label="Strony wyników"><?= pagination_html($paging) ?></nav>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="crm-empty" role="status" aria-live="polite">
      <span class="crm-empty-icon" aria-hidden="true"><i class="bi bi-headset"></i></span>
      <h5>Brak zgłoszeń spełniających kryteria</h5>
      <p class="text-muted">Spróbuj zmienić filtry lub <a href="<?= APP_URL ?>/helpdesk/index.php">wyczyść wszystkie</a>.</p>
    </div>
    <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ── AJAX endpoint ─────────────────────────────────────────────────────────────
if (isset($_GET['_ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok'           => true,
        'total'        => $total_all,
        'list_html'    => _hd_table_html($tickets, $total_all, $paging, $per_page, $is_op, $unread_set, $f_view),
        'unread_count' => $cnt_unread,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Pełna strona ──────────────────────────────────────────────────────────────
$PAGE_TITLE = 'Helpdesk IT';
include dirname(__DIR__) . '/includes/header.php';
echo hd_ui_css();
// Doładuj klasy CRM których helpdesk nie ma w hd_ui_css
?>
<style>
/* Klasy CRM użyte w helpdesku */
.crm-list-card{background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden}
.crm-table{width:100%;border-collapse:collapse;font-size:.86rem}
.crm-table thead tr{background:#f8fafc;border-bottom:1px solid #e5e7eb}
.crm-table th{padding:9px 12px;font-weight:600;color:#374151;white-space:nowrap;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em}
.crm-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.crm-table tbody tr:last-child td{border-bottom:none}
.crm-table tbody tr:hover td{background:#f8fafc}
.crm-table tbody tr[data-row-href]{cursor:pointer}
.crm-table tbody tr.hd-row-unread td{background:#eff6ff}
.crm-table tbody tr.hd-row-unread:hover td{background:#dbeafe}
.crm-name-link{color:#1e293b;text-decoration:none;font-weight:500}
.crm-name-link:hover{color:#2563eb;text-decoration:underline}
.crm-name-link.fw-bold{font-weight:700}
.crm-name-sub{font-size:.76rem;color:#94a3b8;margin-top:1px}
.crm-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#94a3b8;padding:4rem 1rem}
.crm-empty-icon{font-size:3rem;color:#cbd5e1;margin-bottom:.8rem;display:block}
.btn-crm-ghost{color:#6b7280;border:1px solid transparent;background:transparent;padding:2px 7px;border-radius:6px}
.btn-crm-ghost:hover{background:#f1f5f9;border-color:#e5e7eb;color:#374151}
/* Object header */
.crm-object-header{display:flex;align-items:center;gap:14px;padding:14px 18px;background:#fff;border-radius:12px;border:1px solid #e5e7eb;margin-bottom:1rem}
.crm-object-icon{width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#1e40af,#2563EB);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.25rem;flex-shrink:0}
.crm-object-title{font-size:1.1rem;font-weight:700;margin:0;color:#1e293b}
.crm-object-count{font-size:.8rem;color:#6b7280;margin-top:1px}
.crm-object-actions{margin-left:auto;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
/* Filter bar */
.crm-filter-bar{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:.6rem .75rem;margin-bottom:1rem}
.crm-filter-bar .form-control,.crm-filter-bar .form-select{font-size:.85rem;height:34px}
.crm-search-wrap{position:relative;flex:1;min-width:180px}
.crm-search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none}
.crm-search-wrap .form-control{padding-left:32px}
.btn-crm-primary{background:#2563EB;color:#fff;border:none;border-radius:6px}
.btn-crm-primary:hover{background:#1d4ed8;color:#fff}
.btn-crm-outline{background:#fff;color:#374151;border:1px solid #d1d5db;border-radius:6px}
.btn-crm-outline:hover{background:#f8fafc;border-color:#9ca3af}
/* Stat cards */
.crm-stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px}
.crm-stat-value{font-size:1.6rem;font-weight:700;color:#1e293b;line-height:1}
.crm-stat-label{font-size:.75rem;color:#6b7280;margin-top:3px}
.crm-stat-delta{font-size:.73rem;margin-top:4px}
.crm-stat-delta.warn{color:#dc2626}
.crm-stat-delta.ok{color:#16a34a}
</style>

<!-- ══ STATYSTYKI ═══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="crm-stat-card">
      <div class="crm-stat-value"><?= $cnt_total ?></div>
      <div class="crm-stat-label"><i class="bi bi-headset me-1" style="color:#2563eb"></i>Wszystkich zgłoszeń</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="crm-stat-card">
      <div class="crm-stat-value" style="color:#2563eb"><?= $cnt_nowe ?></div>
      <div class="crm-stat-label"><i class="bi bi-envelope-open me-1"></i>Aktywnych</div>
      <?php if ($cnt_unread && $is_op): ?>
      <div class="crm-stat-delta warn"><i class="bi bi-circle-fill me-1" style="font-size:.5rem"></i><?= $cnt_unread ?> nieprzeczytanych</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="crm-stat-card">
      <div class="crm-stat-value" style="color:#d97706"><?= $cnt_oczekuje ?></div>
      <div class="crm-stat-label"><i class="bi bi-hourglass-split me-1"></i>Oczekuje na odpowiedź</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="crm-stat-card">
      <div class="crm-stat-value" style="color:<?= $cnt_unassigned ? '#dc2626' : '#16a34a' ?>"><?= $cnt_unassigned ?></div>
      <div class="crm-stat-label"><i class="bi bi-person-x me-1"></i>Nieprzypisanych</div>
      <?php if ($cnt_unassigned): ?>
      <div class="crm-stat-delta warn">Wymagają przypisania</div>
      <?php else: ?>
      <div class="crm-stat-delta ok">Wszystkie obsłużone</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ══ OBJECT HEADER ══════════════════════════════════════════════════════════ -->
<div class="crm-object-header shadow-sm">
  <div class="crm-object-icon" aria-hidden="true"><i class="bi bi-headset"></i></div>
  <div>
    <h1 class="crm-object-title">
      Helpdesk IT
      <?php if ($cnt_unread && $is_op): ?>
      <span class="hd-unread-badge ms-1" id="hdUnreadBadge" title="<?= $cnt_unread ?> nieprzeczytanych"><?= $cnt_unread ?></span>
      <?php else: ?>
      <span class="hd-unread-badge ms-1 d-none" id="hdUnreadBadge"></span>
      <?php endif; ?>
    </h1>
    <div class="crm-object-count" id="hdTotalCount" aria-live="polite" aria-atomic="true">
      <?= $total_all ?> <?= $total_all === 1 ? 'zgłoszenie' : ($total_all < 5 ? 'zgłoszenia' : 'zgłoszeń') ?>
    </div>
  </div>
  <div class="crm-object-actions">
    <?php if ($is_op): ?>
    <div class="d-flex gap-1 border-end pe-2 me-1">
      <?php foreach (['all' => ['Wszystkie','bi-list-ul'], 'unassigned' => ['Nieprzypisane','bi-inbox'], 'mine' => ['Moje','bi-person']] as $v => [$lbl, $ico]): ?>
      <button type="button" class="btn btn-sm <?= $f_view === $v ? 'btn-crm-primary' : 'btn-crm-outline' ?> hd-view-btn"
              data-view="<?= $v ?>">
        <i class="bi <?= $ico ?> me-1" aria-hidden="true"></i><?= $lbl ?>
        <?php if ($v === 'unassigned' && $cnt_unassigned): ?>
        <span class="badge bg-danger ms-1"><?= $cnt_unassigned ?></span>
        <?php endif; ?>
      </button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/helpdesk/new.php" class="btn btn-crm-primary btn-sm">
      <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowe zgłoszenie
    </a>
    <?php if ($is_op || is_admin()): ?>
    <a href="<?= APP_URL ?>/helpdesk/admin_macros.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-card-text me-1" aria-hidden="true"></i>Gotowe odpowiedzi
    </a>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/helpdesk/admin.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-gear me-1" aria-hidden="true"></i>Ustawienia
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- ══ FILTER BAR ═════════════════════════════════════════════════════════════ -->
<form id="hdFilterForm" method="get" action="<?= APP_URL ?>/helpdesk/index.php"
      class="crm-filter-bar" role="search" aria-label="Filtry zgłoszeń">
  <input type="hidden" name="view" id="hdViewInput" value="<?= h($f_view) ?>">

  <div class="crm-search-wrap">
    <i class="bi bi-search" aria-hidden="true"></i>
    <input type="text" name="q" id="hdSearchInput" value="<?= h($f_q) ?>"
           class="form-control" placeholder="Szukaj numeru, tytułu, zgłaszającego…"
           autocomplete="off" aria-label="Szukaj zgłoszeń">
  </div>

  <select name="status" class="form-select" style="width:auto;min-width:140px" aria-label="Filtruj po statusie">
    <option value="">Wszystkie statusy</option>
    <?php foreach (HD_STATUSES as $k => $s): ?>
    <option value="<?= h($k) ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= h($s['label']) ?></option>
    <?php endforeach; ?>
  </select>

  <select name="category" class="form-select" style="width:auto;min-width:140px" aria-label="Filtruj po kategorii">
    <option value="">Wszystkie kategorie</option>
    <?php foreach (HD_CATEGORIES as $k => $v): ?>
    <option value="<?= h($k) ?>" <?= $f_category === $k ? 'selected' : '' ?>><?= h($v) ?></option>
    <?php endforeach; ?>
  </select>

  <select name="priority" class="form-select" style="width:auto;min-width:120px" aria-label="Filtruj po priorytecie">
    <option value="">Wszystkie priorytety</option>
    <?php foreach (HD_PRIORITIES as $k => $pr): ?>
    <option value="<?= h($k) ?>" <?= $f_priority === $k ? 'selected' : '' ?>><?= h($pr['label']) ?></option>
    <?php endforeach; ?>
  </select>

  <button type="submit" class="btn btn-crm-primary btn-sm">
    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtruj
  </button>

  <?php $active_filters = (int)($f_q !== '') + (int)($f_status !== '') + (int)($f_category !== '') + (int)($f_priority !== ''); ?>
  <?php if ($active_filters): ?>
  <a href="<?= APP_URL ?>/helpdesk/index.php?view=<?= h($f_view) ?>"
     class="btn btn-outline-danger btn-sm" aria-label="Wyczyść filtry">
    <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Wyczyść
    <span class="badge bg-danger ms-1"><?= $active_filters ?></span>
  </a>
  <?php endif; ?>
</form>

<!-- ══ LISTA AJAX ═════════════════════════════════════════════════════════════ -->
<div id="crm-live" role="status" aria-live="polite" aria-atomic="true" class="visually-hidden"></div>
<div id="hdListRegion" aria-label="Lista zgłoszeń">
  <?= _hd_table_html($tickets, $total_all, $paging, $per_page, $is_op, $unread_set, $f_view) ?>
</div>

<div class="hd-toast-wrap" id="hdToasts"></div>

<?php if ($is_op): ?>
<!-- ══ BULK BAR ════════════════════════════════════════════════════════════════ -->
<?php
$op_list = db_all("SELECT id, name FROM users WHERE (helpdesk_operator=1 OR role='admin') AND is_active=1 ORDER BY name", []);
?>
<div id="hdBulkBar" class="hd-bulk-bar d-none" role="region" aria-label="Masowe akcje">
  <div class="hd-bulk-inner">
    <span class="hd-bulk-count me-3">
      <span id="hdBulkCount">0</span> zaznaczonych
    </span>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <!-- Zmień status -->
      <div class="input-group input-group-sm" style="width:auto">
        <label class="input-group-text" for="hdBulkStatus" style="font-size:.78rem">Status</label>
        <select class="form-select form-select-sm" id="hdBulkStatus" style="min-width:120px">
          <option value="">— wybierz —</option>
          <?php foreach (HD_STATUSES as $k => $s): ?>
          <option value="<?= h($k) ?>"><?= h($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary btn-sm" onclick="HdBulk.doAction('set_status', document.getElementById('hdBulkStatus').value)">
          Zmień
        </button>
      </div>
      <!-- Przypisz -->
      <div class="input-group input-group-sm" style="width:auto">
        <label class="input-group-text" for="hdBulkAssign" style="font-size:.78rem">Przypisz</label>
        <select class="form-select form-select-sm" id="hdBulkAssign" style="min-width:130px">
          <option value="">— wybierz —</option>
          <option value="0">Usuń przypisanie</option>
          <?php foreach ($op_list as $op): ?>
          <option value="<?= (int)$op['id'] ?>"><?= h($op['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary btn-sm" onclick="HdBulk.doAction('assign', document.getElementById('hdBulkAssign').value)">
          OK
        </button>
      </div>
      <!-- Zamknij -->
      <button class="btn btn-sm btn-outline-danger" onclick="HdBulk.doAction('close')">
        <i class="bi bi-x-circle me-1"></i>Zamknij zaznaczone
      </button>
      <!-- Odznacz -->
      <button class="btn btn-sm btn-link text-secondary" onclick="HdBulk.clear()">
        <i class="bi bi-x me-1"></i>Odznacz
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  'use strict';
  var APP      = <?= json_encode(APP_URL) ?>;
  var CSRF     = <?= json_encode(csrf_token()) ?>;
  var region   = document.getElementById('hdListRegion');
  var form     = document.getElementById('hdFilterForm');
  var viewInp  = document.getElementById('hdViewInput');
  var countEl  = document.getElementById('hdTotalCount');
  var liveEl   = document.getElementById('crm-live');
  var toastWrap= document.getElementById('hdToasts');
  var abort    = null;
  var debounce = null;

  /* ── Ładuje fragment listy ─────────────────────── */
  function load(params, push) {
    if (abort) { try { abort.abort(); } catch(e){} }
    abort = (typeof AbortController !== 'undefined') ? new AbortController() : null;

    region.setAttribute('aria-busy','true');
    region.style.opacity = '0.5';
    region.style.pointerEvents = 'none';

    var url = new URL(window.location.pathname, window.location.origin);
    params.forEach(function(v,k){ if(v) url.searchParams.set(k,v); });
    url.searchParams.set('_ajax','1');
    var opts = abort ? { signal: abort.signal } : {};

    fetch(url.toString(), opts)
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) return;
        region.innerHTML = d.list_html;
        if (countEl && d.total !== undefined) {
          var t = d.total;
          countEl.textContent = t + ' ' + (t===1?'zgłoszenie':t<5?'zgłoszenia':'zgłoszeń');
        }
        // Badge unread
        var badge = document.getElementById('hdUnreadBadge');
        if (badge && d.unread_count !== undefined) {
          if (d.unread_count > 0) { badge.textContent = d.unread_count; badge.classList.remove('d-none'); }
          else badge.classList.add('d-none');
        }
        if (push !== false) {
          var hu = new URL(window.location.href);
          hu.search = params.toString();
          history.pushState({ hd: params.toString() }, '', hu.toString());
        }
        region.removeAttribute('aria-busy');
        region.style.opacity = '1';
        region.style.pointerEvents = '';
        bindRegion();
        if (liveEl) {
          liveEl.textContent = '';
          setTimeout(function(){ liveEl.textContent = 'Załadowano ' + (d.total||0) + ' zgłoszeń.'; }, 50);
        }
      })
      .catch(function(e){
        if (e && e.name === 'AbortError') return;
        region.removeAttribute('aria-busy');
        region.style.opacity = '1';
        region.style.pointerEvents = '';
      });
  }

  function formParams() {
    var p = new URLSearchParams();
    new FormData(form).forEach(function(v,k){ if(v) p.set(k,v); });
    return p;
  }

  function syncForm(params) {
    form.querySelectorAll('select,input[type="text"]').forEach(function(el){
      if (el.name) el.value = params.get(el.name) || '';
    });
  }

  /* ── Masowe akcje ─────────────────────────────── */
  window.HdBulk = {
    bar: document.getElementById('hdBulkBar'),
    countEl: document.getElementById('hdBulkCount'),
    getChecked: function() {
      return Array.from(region.querySelectorAll('.hd-row-chk:checked')).map(function(c){ return parseInt(c.value, 10); });
    },
    update: function() {
      var ids = this.getChecked();
      var n   = ids.length;
      if (this.countEl) this.countEl.textContent = n;
      if (this.bar)     this.bar.classList.toggle('d-none', n === 0);
      var all = region.querySelectorAll('.hd-row-chk');
      var chkAll = document.getElementById('hdChkAll');
      if (chkAll && all.length > 0) {
        chkAll.indeterminate = (n > 0 && n < all.length);
        chkAll.checked       = (n === all.length);
      }
    },
    toggleAll: function(checked) {
      region.querySelectorAll('.hd-row-chk').forEach(function(c){ c.checked = checked; });
      this.update();
    },
    clear: function() {
      region.querySelectorAll('.hd-row-chk').forEach(function(c){ c.checked = false; });
      var chkAll = document.getElementById('hdChkAll');
      if (chkAll) { chkAll.checked = false; chkAll.indeterminate = false; }
      this.update();
    },
    doAction: function(action, value) {
      var ids = this.getChecked();
      if (!ids.length) { toast('Zaznacz przynajmniej jedno zgłoszenie.', 'err'); return; }
      if (action === 'set_status' && !value) { toast('Wybierz status.', 'err'); return; }
      if (action === 'assign' && value === '') { toast('Wybierz operatora.', 'err'); return; }
      if (action === 'close' && !confirm('Zamknąć ' + ids.length + ' zaznaczonych zgłoszeń?')) return;
      var payload = { _csrf: CSRF, action: action, ids: ids };
      if (value !== undefined) payload.value = String(value);
      fetch(APP + '/helpdesk/api/bulk.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) { toast(d.error || 'Błąd akcji.', 'err'); return; }
        toast('Wykonano na ' + (d.affected || ids.length) + ' zgłoszeniach.', 'ok');
        HdBulk.clear();
        load(formParams(), false);
      })
      .catch(function(){ toast('Błąd sieci.', 'err'); });
    }
  };

  /* ── Wiązanie dynamicznego regionu ─────────────── */
  function bindRegion() {
    // Kliknięcie wiersza → otwórz zgłoszenie
    region.querySelectorAll('tr[data-row-href]').forEach(function(tr){
      tr.style.cursor = 'pointer';
      tr.addEventListener('click', function(e){
        if (e.target.closest('a,button,input')) return;
        window.location.href = tr.dataset.rowHref;
      });
    });
    // Paginacja AJAX
    region.querySelectorAll('a.page-link').forEach(function(a){
      a.addEventListener('click', function(e){
        e.preventDefault();
        var p = new URLSearchParams(new URL(a.getAttribute('href'), window.location.href).search);
        load(p, true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });
  }

  /* ── Submit formularza ─────────────────────────── */
  form.addEventListener('submit', function(e){ e.preventDefault(); load(formParams(), true); });

  /* ── Select → auto ────────────────────────────── */
  form.querySelectorAll('select').forEach(function(sel){
    sel.addEventListener('change', function(){ load(formParams(), true); });
  });

  /* ── Szukaj — debounce 380 ms ──────────────────── */
  var searchInp = document.getElementById('hdSearchInput');
  if (searchInp) {
    searchInp.addEventListener('input', function(){
      clearTimeout(debounce);
      debounce = setTimeout(function(){ load(formParams(), true); }, 380);
    });
    searchInp.addEventListener('keydown', function(e){
      if (e.key === 'Enter') { e.preventDefault(); clearTimeout(debounce); load(formParams(), true); }
    });
    // Skrót klawiszowy /
    document.addEventListener('keydown', function(e){
      if (e.ctrlKey||e.metaKey||e.altKey) return;
      var t = document.activeElement && document.activeElement.tagName;
      if (t==='INPUT'||t==='TEXTAREA'||t==='SELECT') return;
      if (e.key==='/') { e.preventDefault(); searchInp.focus(); searchInp.select(); }
    });
  }

  /* ── Przyciski widoku (all/unassigned/mine) ────── */
  document.querySelectorAll('.hd-view-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      viewInp.value = btn.dataset.view;
      document.querySelectorAll('.hd-view-btn').forEach(function(b){
        b.classList.toggle('btn-crm-primary', b===btn);
        b.classList.toggle('btn-crm-outline', b!==btn);
      });
      load(formParams(), true);
    });
  });

  /* ── Przeglądarka: wstecz/dalej ─────────────────── */
  window.addEventListener('popstate', function(e){
    var p = (e.state && e.state.hd !== undefined)
          ? new URLSearchParams(e.state.hd)
          : new URLSearchParams(window.location.search);
    syncForm(p);
    if (viewInp && p.get('view')) viewInp.value = p.get('view');
    load(p, false);
  });

  /* ── Toast ─────────────────────────────────────── */
  function toast(msg, type) {
    if (!msg) return;
    var t = document.createElement('div');
    t.className = 'hd-toast ' + (type==='ok'?'ok':type==='err'?'err':'');
    t.textContent = msg;
    toastWrap.appendChild(t);
    setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.remove(); },300); }, 4500);
  }

  bindRegion();
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
