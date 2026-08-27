<?php
/**
 * rekrutacja/index.php — Lista zgłoszeń rekrutacyjnych
 * Filtry: status, typ (wolontariat/etat), stanowisko, tag, zakres dat, fraza
 * (imię/nazwisko/e-mail/wiadomość + słowa kluczowe w treści CV).
 * GET ?_ajax=1 → JSON { ok, total, list_html } (LiveSearch bez przeładowania).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rekrutacja.php';

rekr_migrate();
require_module_enabled('rekrutacja_enabled', 'Moduł rekrutacji');
rekr_require_operator();

$statuses  = rekr_statuses();
$positions = rekr_positions(false);

// ── Filtry ────────────────────────────────────────────────────────────────────
$f_q      = trim($_GET['q'] ?? '');
$f_status = isset($statuses[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$f_type   = isset(REKR_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$f_pos    = (int)($_GET['position'] ?? 0);
$f_tag    = trim(mb_strtolower($_GET['tag'] ?? ''));
$f_od     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['od'] ?? '') ? $_GET['od'] : '';
$f_do     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['do'] ?? '') ? $_GET['do'] : '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

$where = ['1=1']; $params = [];
if ($f_status) { $where[] = 'a.status = ?';      $params[] = $f_status; }
if ($f_type)   { $where[] = 'a.type = ?';        $params[] = $f_type; }
if ($f_pos)    { $where[] = 'a.position_id = ?'; $params[] = $f_pos; }
if ($f_od)     { $where[] = "a.created_at >= ?"; $params[] = $f_od . ' 00:00:00'; }
if ($f_do)     { $where[] = "a.created_at <= ?"; $params[] = $f_do . ' 23:59:59'; }
if ($f_tag !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM rekr_tags t WHERE t.application_id=a.id AND t.tag=?)';
    $params[] = $f_tag;
}
if ($f_q !== '') {
    // Fraza obejmuje też słowa kluczowe w wyekstrahowanej treści CV/listu
    $where[] = '(a.imie LIKE ? OR a.nazwisko LIKE ? OR a.email LIKE ? OR a.message LIKE ?
                 OR EXISTS (SELECT 1 FROM rekr_files f WHERE f.application_id=a.id AND f.extracted_text LIKE ?))';
    $like = '%' . $f_q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$where_sql = implode(' AND ', $where);

$total = (int)(db_one("SELECT COUNT(*) AS c FROM rekr_applications a WHERE {$where_sql}", $params)['c'] ?? 0);
$paging = paginate($total, $per_page, $page, APP_URL . '/rekrutacja/index.php?' . http_build_query(array_filter([
    'q' => $f_q, 'status' => $f_status, 'type' => $f_type, 'position' => $f_pos ?: null,
    'tag' => $f_tag, 'od' => $f_od, 'do' => $f_do,
])));

$apps = db_all(
    "SELECT a.*, p.name AS position_name,
            (SELECT COUNT(*) FROM rekr_files f WHERE f.application_id=a.id) AS files_cnt,
            (SELECT GROUP_CONCAT(t.tag, ',') FROM rekr_tags t WHERE t.application_id=a.id) AS tags_csv
     FROM rekr_applications a
     LEFT JOIN rekr_positions p ON p.id = a.position_id
     WHERE {$where_sql}
     ORDER BY a.created_at DESC
     LIMIT {$per_page} OFFSET {$paging['offset']}", $params);

// ── Renderer listy (pełna strona i AJAX używają tego samego HTML) ─────────────
function _rekr_list_html(array $apps, int $total, array $paging): string {
    ob_start(); ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-2">
        <caption class="visually-hidden">Lista zgłoszeń rekrutacyjnych (<?= $total ?>)</caption>
        <thead>
          <tr>
            <th scope="col">Kandydat</th>
            <th scope="col">Typ</th>
            <th scope="col">Stanowisko</th>
            <th scope="col">Status</th>
            <th scope="col">Tagi</th>
            <th scope="col" class="text-center" title="Dokumenty"><i class="bi bi-paperclip" aria-hidden="true"></i><span class="visually-hidden">Dokumenty</span></th>
            <th scope="col">Złożono</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$apps): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">Brak zgłoszeń spełniających kryteria.</td></tr>
        <?php endif; ?>
        <?php foreach ($apps as $a):
            $t = REKR_TYPES[$a['type']] ?? REKR_TYPES['wolontariat'];
            $url = APP_URL . '/rekrutacja/view.php?id=' . (int)$a['id']; ?>
          <tr>
            <td>
              <a class="fw-semibold text-decoration-none" href="<?= h($url) ?>"><?= h(rekr_candidate_name($a)) ?></a>
              <div class="small text-muted"><?= h($a['email']) ?></div>
            </td>
            <td><span class="badge text-bg-<?= h($t['class']) ?>"><i class="bi <?= h($t['icon']) ?> me-1" aria-hidden="true"></i><?= h($t['label']) ?></span></td>
            <td class="small"><?= h($a['position_name'] ?? '—') ?></td>
            <td><?= rekr_status_badge($a['status']) ?></td>
            <td>
              <?php foreach (array_filter(explode(',', (string)$a['tags_csv'])) as $tag): ?>
                <span class="badge rounded-pill text-bg-light border"><?= h($tag) ?></span>
              <?php endforeach; ?>
            </td>
            <td class="text-center"><?= (int)$a['files_cnt'] ?: '—' ?></td>
            <td class="small text-nowrap"><?= h(date('d.m.Y H:i', strtotime($a['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="d-flex justify-content-between align-items-center">
      <div class="small text-muted">Łącznie: <?= $total ?></div>
      <?= pagination_html($paging) ?>
    </div>
    <?php return ob_get_clean();
}

// ── AJAX (LiveSearch) ─────────────────────────────────────────────────────────
if (isset($_GET['_ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true, 'total' => $total, 'list_html' => _rekr_list_html($apps, $total, $paging)],
        JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Pełna strona ──────────────────────────────────────────────────────────────
$PAGE_TITLE = 'Rekrutacja';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-person-plus-fill me-2" aria-hidden="true"></i>Rekrutacja — zgłoszenia</h1>
    <div class="btn-group">
      <a class="btn btn-sm btn-outline-secondary" href="<?= h(APP_URL) ?>/rekrutacja/kalendarz.php"><i class="bi bi-calendar-week me-1" aria-hidden="true"></i>Rozmowy</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h(APP_URL) ?>/rekrutacja/apply.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Formularz publiczny</a>
      <?php if (is_admin()): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h(APP_URL) ?>/admin/rekrutacja.php"><i class="bi bi-gear me-1" aria-hidden="true"></i>Ustawienia</a>
      <?php endif; ?>
    </div>
  </div>

  <form id="rekrFilters" class="card card-body mb-3" method="get" action="index.php">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label small mb-1" for="rf-q">Szukaj (dane, wiadomość, treść CV)</label>
        <input type="search" class="form-control form-control-sm" id="rf-q" name="q" value="<?= h($f_q) ?>" placeholder="np. „księgowość", nazwisko, e-mail…">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="rf-status">Status</label>
        <select class="form-select form-select-sm" id="rf-status" name="status">
          <option value="">— wszystkie —</option>
          <?php foreach ($statuses as $s): ?>
          <option value="<?= h($s['slug']) ?>" <?= $f_status === $s['slug'] ? 'selected' : '' ?>><?= h($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="rf-type">Typ</label>
        <select class="form-select form-select-sm" id="rf-type" name="type">
          <option value="">— wszystkie —</option>
          <?php foreach (REKR_TYPES as $k => $t): ?>
          <option value="<?= h($k) ?>" <?= $f_type === $k ? 'selected' : '' ?>><?= h($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="rf-pos">Stanowisko</label>
        <select class="form-select form-select-sm" id="rf-pos" name="position">
          <option value="">— wszystkie —</option>
          <?php foreach ($positions as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $f_pos === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-1">
        <label class="form-label small mb-1" for="rf-tag">Tag</label>
        <input type="text" class="form-control form-control-sm" id="rf-tag" name="tag" value="<?= h($f_tag) ?>">
      </div>
      <div class="col-6 col-md-1">
        <label class="form-label small mb-1" for="rf-od">Od</label>
        <input type="date" class="form-control form-control-sm" id="rf-od" name="od" value="<?= h($f_od) ?>">
      </div>
      <div class="col-6 col-md-1">
        <label class="form-label small mb-1" for="rf-do">Do</label>
        <input type="date" class="form-control form-control-sm" id="rf-do" name="do" value="<?= h($f_do) ?>">
      </div>
    </div>
    <noscript><button class="btn btn-sm btn-primary mt-2">Filtruj</button></noscript>
  </form>

  <div id="rekrList" aria-live="polite" aria-busy="false">
    <?= _rekr_list_html($apps, $total, $paging) ?>
  </div>
</div>

<script>
// LiveSearch: każda zmiana filtra → fetch ?_ajax=1 (debounce dla pola tekstowego)
(function () {
  const form = document.getElementById('rekrFilters');
  const list = document.getElementById('rekrList');
  let timer = null, ctrl = null;

  function refresh() {
    if (ctrl) ctrl.abort();
    ctrl = new AbortController();
    const params = new URLSearchParams(new FormData(form));
    params.set('_ajax', '1');
    list.setAttribute('aria-busy', 'true');
    fetch('index.php?' + params.toString(), { signal: ctrl.signal })
      .then(r => r.json())
      .then(d => {
        if (d.ok) list.innerHTML = d.list_html;
        // Zaktualizuj URL, żeby filtry przeżyły odświeżenie/udostępnienie linku
        params.delete('_ajax');
        history.replaceState(null, '', 'index.php?' + params.toString());
      })
      .catch(() => {})
      .finally(() => list.setAttribute('aria-busy', 'false'));
  }
  form.addEventListener('submit', e => { e.preventDefault(); refresh(); });
  form.querySelectorAll('select, input[type=date]').forEach(el => el.addEventListener('change', refresh));
  form.querySelectorAll('input[type=search], input[type=text]').forEach(el =>
    el.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(refresh, 350); }));
  // Paginacja wewnątrz listy — przechwyć kliknięcia i doładuj AJAX-em
  list.addEventListener('click', e => {
    const a = e.target.closest('.pagination a.page-link');
    if (!a) return;
    e.preventDefault();
    const url = new URL(a.href, location.href);
    url.searchParams.set('_ajax', '1');
    list.setAttribute('aria-busy', 'true');
    fetch(url).then(r => r.json()).then(d => { if (d.ok) list.innerHTML = d.list_html; })
      .finally(() => list.setAttribute('aria-busy', 'false'));
  });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
