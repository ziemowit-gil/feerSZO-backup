<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_dzielo', 'Ten typ umowy');
$PAGE_TITLE = 'Umowy o dzieło';
$TYPE  = 'dzielo';
$TABLE = 'umowy_dzielo';

$can_edit = can_edit();

// ── Filtry ────────────────────────────────────────────────────────────────────
$f = [
    'q'         => trim($_GET['q']         ?? ''),
    'status'    => $_GET['status']         ?? '',
    'opiekun'   => trim($_GET['opiekun']   ?? ''),
    'projekt'   => trim($_GET['projekt']   ?? ''),
    'forma'     => $_GET['forma']          ?? '',
    'data_od'   => $_GET['data_od']        ?? '',
    'data_do'   => $_GET['data_do']        ?? '',
    'termin_od' => $_GET['termin_od']      ?? '',
    'termin_do' => $_GET['termin_do']      ?? '',
    'wyna_od'   => $_GET['wyna_od']        ?? '',
    'wyna_do'   => $_GET['wyna_do']        ?? '',
    'kup50'     => !empty($_GET['kup50'])  ? '1' : '',
    'prawa'     => !empty($_GET['prawa'])  ? '1' : '',
    'sort'      => $_GET['sort']           ?? '',
    'dir'       => ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc',
];

$_sorts   = ['created_at'=>'created_at','data_zawarcia'=>'data_zawarcia',
             'termin_oddania'=>'termin_oddania','imie_nazwisko'=>'imie_nazwisko',
             'wynagrodzenie_brutto'=>'wynagrodzenie_brutto'];
$sort_col = $_sorts[$f['sort']] ?? 'created_at';
$sort_dir = $f['dir'] === 'asc' ? 'ASC' : 'DESC';

$page = max(1, intval($_GET['page'] ?? 1));
$per  = 20;

$statuses = ['projekt'=>'Projekt','podpisana'=>'Podpisana','w realizacji'=>'W realizacji',
             'zakończona'=>'Zakończona','rozwiązana'=>'Rozwiązana','anulowana'=>'Anulowana'];
$formy    = ['papierowa'=>'Papierowa','elektroniczna'=>'Elektroniczna','kwalifikowany'=>'Kwalifikowany e-podpis'];

// ── POST — szybkie akcje (quick_status / delete) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $xhr    = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
              && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $action = $_POST['_action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    $reply = function (bool $ok, array $extra = []) use ($xhr, $f) {
        if ($xhr) { header('Content-Type: application/json'); echo json_encode(['ok'=>$ok]+$extra); exit; }
        header('Location: ' . APP_URL . '/contracts/dzielo/list.php?' . http_build_query(array_filter($f)));
        exit;
    };

    $row  = $id ? db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]) : null;
    if ($row && !contract_can_access($TYPE, $row)) { $row = null; }
    $owns = $row && (is_admin() || (int)($row['created_by'] ?? 0) === (int)current_user()['id']);

    if ($action === 'quick_status' && $row && $can_edit) {
        $new     = trim($_POST['new_status'] ?? '');
        $allowed = is_admin()
            ? array_keys($statuses)
            : array_values(array_unique(array_merge([$row['status']], status_allowed_next($row['status'], false))));
        if ($new !== '' && in_array($new, $allowed, true) && isset($statuses[$new])) {
            db_update($TABLE, ['status'=>$new, 'updated_at'=>date('Y-m-d H:i:s')], $id);
            require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
            log_contract_action($TYPE, $id, current_user()['id'], 'status', 'Status → ' . $new);
            $reply(true, ['new_status'=>$new]);
        }
        $reply(false);
    }

    if ($action === 'delete' && $row && $owns) {
        require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
        db()->prepare("DELETE FROM {$TABLE} WHERE id = ?")->execute([$id]);
        log_contract_action($TYPE, $id, current_user()['id'], 'delete', 'Usunięto umowę');
        $reply(true);
    }

    $reply(false);
}

// ── WHERE ─────────────────────────────────────────────────────────────────────
$where  = '1=1';
$params = [];
if ($f['q']) {
    $where .= " AND (numer_umowy LIKE ? OR imie_nazwisko LIKE ? OR nr_rejestru LIKE ?"
            . " OR pesel LIKE ? OR email LIKE ? OR opis_dziela LIKE ?"
            . " OR numer_projektu LIKE ? OR opiekun LIKE ? OR uwagi LIKE ?)";
    $params = array_merge($params, array_fill(0, 9, "%{$f['q']}%"));
}
if ($f['status'])    { $where .= " AND status = ?";            $params[] = $f['status']; }
if ($f['opiekun'])   { $where .= " AND opiekun LIKE ?";        $params[] = "%{$f['opiekun']}%"; }
if ($f['projekt'])   { $where .= " AND numer_projektu LIKE ?"; $params[] = "%{$f['projekt']}%"; }
if ($f['forma'])     { $where .= " AND forma_podpisania = ?";  $params[] = $f['forma']; }
if ($f['data_od'])   { $where .= " AND data_zawarcia >= ?";    $params[] = $f['data_od']; }
if ($f['data_do'])   { $where .= " AND data_zawarcia <= ?";    $params[] = $f['data_do']; }
if ($f['termin_od']) { $where .= " AND termin_oddania >= ?";   $params[] = $f['termin_od']; }
if ($f['termin_do']) { $where .= " AND termin_oddania <= ?";   $params[] = $f['termin_do']; }
if ($f['wyna_od'] !== '') { $where .= " AND CAST(wynagrodzenie_brutto AS REAL) >= ?"; $params[] = (float)$f['wyna_od']; }
if ($f['wyna_do'] !== '') { $where .= " AND CAST(wynagrodzenie_brutto AS REAL) <= ?"; $params[] = (float)$f['wyna_do']; }
if ($f['kup50']) { $where .= " AND kup50 = 1"; }
if ($f['prawa']) { $where .= " AND prawa_autorskie = 1"; }
$where .= ' AND ' . contract_access_where($TYPE);

$adv_count = (int)!!$f['opiekun'] + (int)!!$f['projekt'] + (int)!!$f['forma']
           + (int)!!$f['data_od'] + (int)!!$f['data_do'] + (int)!!$f['termin_od'] + (int)!!$f['termin_do']
           + (int)($f['wyna_od'] !== '') + (int)($f['wyna_do'] !== '')
           + (int)!!$f['kup50'] + (int)!!$f['prawa'];
$adv_open  = $adv_count > 0 || !empty($_GET['adv']);

$total  = (int)(db_one("SELECT COUNT(*) AS c FROM {$TABLE} WHERE {$where}", $params)['c'] ?? 0);

$qs_base = array_filter([
    'q'=>$f['q'], 'status'=>$f['status'], 'opiekun'=>$f['opiekun'], 'projekt'=>$f['projekt'],
    'forma'=>$f['forma'], 'data_od'=>$f['data_od'], 'data_do'=>$f['data_do'],
    'termin_od'=>$f['termin_od'], 'termin_do'=>$f['termin_do'],
    'wyna_od'=>$f['wyna_od'], 'wyna_do'=>$f['wyna_do'],
    'kup50'=>$f['kup50'] ?: null, 'prawa'=>$f['prawa'] ?: null,
    'sort'=>$sort_col !== 'created_at' ? $sort_col : null,
    'dir' =>$sort_dir !== 'DESC' ? 'asc' : null,
], fn($v) => $v !== '' && $v !== null);

$pag  = paginate($total, $per, $page, APP_URL . "/contracts/{$TYPE}/list.php?" . http_build_query($qs_base));
$rows = db_all("SELECT * FROM {$TABLE} WHERE {$where} ORDER BY {$sort_col} {$sort_dir} LIMIT {$per} OFFSET {$pag['offset']}", $params);

// ── Statystyki ────────────────────────────────────────────────────────────────
$stats = [
    'total' => (int)(db_one("SELECT COUNT(*) c FROM {$TABLE}")['c'] ?? 0),
    'real'  => (int)(db_one("SELECT COUNT(*) c FROM {$TABLE} WHERE status = 'w realizacji'")['c'] ?? 0),
    'done'  => (int)(db_one("SELECT COUNT(*) c FROM {$TABLE} WHERE status = 'zakończona'")['c'] ?? 0),
    'suma'  => (float)(db_one("SELECT COALESCE(SUM(CAST(wynagrodzenie_brutto AS REAL)),0) s FROM {$TABLE} WHERE status NOT IN ('anulowana','rozwiązana')")['s'] ?? 0),
];

// ── Renderer fragmentu listy ──────────────────────────────────────────────────
function _dzielo_table_html(array $rows, int $total, array $pag, int $per, array $f,
                            array $statuses, array $formy,
                            bool $can_edit, string $sort_col, string $sort_dir): string {
    $TYPE = 'dzielo';
    $base = APP_URL . "/contracts/{$TYPE}/list.php";
    $qs   = array_filter([
        'q'=>$f['q'], 'status'=>$f['status'], 'opiekun'=>$f['opiekun'], 'projekt'=>$f['projekt'],
        'forma'=>$f['forma'], 'data_od'=>$f['data_od'], 'data_do'=>$f['data_do'],
        'termin_od'=>$f['termin_od'], 'termin_do'=>$f['termin_do'],
        'wyna_od'=>$f['wyna_od'], 'wyna_do'=>$f['wyna_do'],
        'kup50'=>$f['kup50'] ?: null, 'prawa'=>$f['prawa'] ?: null,
        'sort'=>$sort_col !== 'created_at' ? $sort_col : null, 'dir'=>$sort_dir !== 'DESC' ? 'asc' : null,
    ], fn($v) => $v !== '' && $v !== null);
    $chip = fn($drop) => $base . '?' . http_build_query(array_diff_key($qs, array_flip(array_merge((array)$drop, ['page']))));

    $adv_count = (int)!!$f['opiekun'] + (int)!!$f['projekt'] + (int)!!$f['forma']
               + (int)!!$f['data_od'] + (int)!!$f['data_do'] + (int)!!$f['termin_od'] + (int)!!$f['termin_do']
               + (int)($f['wyna_od'] !== '') + (int)($f['wyna_do'] !== '')
               + (int)!!$f['kup50'] + (int)!!$f['prawa'];
    $filtering = $f['q'] !== '' || $f['status'] !== '' || $adv_count > 0;

    ob_start();
    ?>
    <?php if ($adv_count): ?>
    <div class="active-chips mb-2">
      <?php if ($f['opiekun']): ?><a href="<?= h($chip('opiekun')) ?>" class="active-chip">Opiekun: <?= h($f['opiekun']) ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['projekt']): ?><a href="<?= h($chip('projekt')) ?>" class="active-chip">Projekt: <?= h($f['projekt']) ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['forma']): ?><a href="<?= h($chip('forma')) ?>" class="active-chip">Forma: <?= h($formy[$f['forma']] ?? $f['forma']) ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['kup50']): ?><a href="<?= h($chip('kup50')) ?>" class="active-chip">50% KUP <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['prawa']): ?><a href="<?= h($chip('prawa')) ?>" class="active-chip">Prawa autorskie <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['data_od'] || $f['data_do']): ?><a href="<?= h($chip(['data_od','data_do'])) ?>" class="active-chip">Zawarcie: <?= h($f['data_od'] ?: '…') ?> – <?= h($f['data_do'] ?: '…') ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['termin_od'] || $f['termin_do']): ?><a href="<?= h($chip(['termin_od','termin_do'])) ?>" class="active-chip">Termin: <?= h($f['termin_od'] ?: '…') ?> – <?= h($f['termin_do'] ?: '…') ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['wyna_od'] !== '' || $f['wyna_do'] !== ''): ?><a href="<?= h($chip(['wyna_od','wyna_do'])) ?>" class="active-chip">Brutto: <?= $f['wyna_od'] !== '' ? number_format((float)$f['wyna_od'],0,',',' ') : '0' ?> – <?= $f['wyna_do'] !== '' ? number_format((float)$f['wyna_do'],0,',',' ') : '∞' ?> PLN <span class="chip-x">×</span></a><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table table-hover contracts-table mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:2%" class="ps-3"><input type="checkbox" id="cb-all" class="form-check-input" title="Zaznacz wszystkie"></th>
              <th>Numer</th>
              <th class="d-none d-md-table-cell">Nr rejestru</th>
              <th>Wykonawca</th>
              <th class="d-none d-lg-table-cell">Opis dzieła</th>
              <th class="d-none d-sm-table-cell">Data zawarcia</th>
              <th class="d-none d-sm-table-cell">Termin oddania</th>
              <th class="d-none d-xl-table-cell text-end">Brutto</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $vurl = APP_URL . "/contracts/{$TYPE}/view.php?id=" . (int)$r['id'];
            ?>
            <tr data-row-href="<?= h($vurl) ?>" style="cursor:pointer">
              <td class="ps-3"><input type="checkbox" class="cb-row form-check-input" value="<?= (int)$r['id'] ?>" aria-label="Zaznacz"></td>
              <td>
                <div class="fw-semibold"><?= h($r['numer_umowy']) ?></div>
                <?php if (!empty($r['kup50']) || !empty($r['prawa_autorskie'])): ?>
                <div style="font-size:.7rem">
                  <?= !empty($r['kup50'])           ? '<span class="badge bg-secondary-subtle text-secondary">50% KUP</span> ' : '' ?>
                  <?= !empty($r['prawa_autorskie'])  ? '<span class="badge bg-info-subtle text-info">Prawa aut.</span>' : '' ?>
                </div>
                <?php endif; ?>
              </td>
              <td class="font-monospace small d-none d-md-table-cell"><?= h($r['nr_rejestru'] ?? '') ?: '—' ?></td>
              <td>
                <?= h($r['imie_nazwisko']) ?>
                <?php if ($r['email'] ?? ''): ?><div class="text-muted" style="font-size:.75rem"><?= h($r['email']) ?></div><?php endif; ?>
              </td>
              <td class="d-none d-lg-table-cell" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.83rem"><?= h($r['opis_dziela']) ?></td>
              <td class="d-none d-sm-table-cell" style="white-space:nowrap;font-size:.82rem"><?= date_pl($r['data_zawarcia']) ?></td>
              <td class="d-none d-sm-table-cell" style="white-space:nowrap;font-size:.82rem"><?= date_pl($r['termin_oddania']) ?></td>
              <td class="d-none d-xl-table-cell text-end" style="white-space:nowrap"><?= money($r['wynagrodzenie_brutto']) ?></td>
              <td>
                <?php if ($can_edit): ?>
                <div class="dropdown d-inline-block">
                  <a href="#" class="text-decoration-none" data-bs-toggle="dropdown" aria-expanded="false"
                     onclick="event.stopPropagation()" title="Zmień status">
                    <?= status_badge($r['status']) ?>
                    <i class="bi bi-caret-down-fill" style="font-size:.6rem;opacity:.5"></i>
                  </a>
                  <ul class="dropdown-menu shadow-sm" style="font-size:.85rem">
                    <?php
                      $cur  = $r['status'] ?? '';
                      $next = is_admin()
                          ? array_keys($statuses)
                          : array_values(array_unique(array_merge([$cur], status_allowed_next($cur, false))));
                      foreach ($next as $st):
                        if (!isset($statuses[$st])) continue;
                    ?>
                    <li><a class="dropdown-item <?= $st===$cur?'active':'' ?>" href="#"
                           data-quick-status="<?= h($st) ?>" data-id="<?= (int)$r['id'] ?>"
                           onclick="event.stopPropagation()"><?= h($statuses[$st]) ?></a></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
                <?php else: ?>
                <?= status_badge($r['status']) ?>
                <?php endif; ?>
              </td>
              <td class="text-end" style="white-space:nowrap">
                <a href="<?= h($vurl) ?>" class="btn btn-sm btn-outline-primary py-0 px-2" onclick="event.stopPropagation()"><i class="bi bi-eye"></i></a>
                <?php if ($can_edit): ?>
                <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="event.stopPropagation()"><i class="bi bi-pencil"></i></a>
                <?php endif; ?>
                <?php if (is_admin() || ($can_edit && (int)($r['created_by'] ?? 0) === (int)current_user()['id'])): ?>
                <form method="post" class="d-inline" data-ajax-action="delete"
                      data-contract-name="<?= h($r['numer_umowy'] ?? ('#'.(int)$r['id'])) ?>"
                      onclick="event.stopPropagation()">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">
              <i class="bi bi-brush display-6 d-block mb-2 opacity-25"></i>
              Brak umów o dzieło<?= $filtering ? ' spełniających kryteria' : '' ?>
            </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex justify-content-between align-items-center py-2" style="background:#FAFAFA">
        <small class="text-muted">Znaleziono: <strong><?= $total ?></strong><?= $filtering ? ' — filtrowanie aktywne' : '' ?></small>
        <?php if ($pag['pages'] > 1): ?><?= pagination_html($pag) ?><?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

// ── AJAX fragment ─────────────────────────────────────────────────────────────
if (isset($_GET['_ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok'        => true,
        'total'     => $total,
        'list_html' => _dzielo_table_html($rows, $total, $pag, $per, $f, $statuses, $formy, $can_edit, $sort_col, $sort_dir),
    ]);
    exit;
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_preview_notice.php';
echo contract_preview_notice('dzielo');
require_once dirname(__DIR__) . '/includes/adv_filter.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-brush text-primary"></i> Umowy o dzieło
    <span class="badge bg-secondary ms-1" id="dz-total-badge"><?= $total ?></span>
  </h4>
  <?php if ($can_edit): ?>
  <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/add.php" class="btn btn-primary">
    <i class="bi bi-plus-lg"></i> Nowa umowa
  </a>
  <?php endif; ?>
</div>

<!-- ══ STATYSTYKI ═══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card shadow-sm h-100"><div class="card-body py-2">
      <div class="h4 mb-0"><?= number_format($stats['total'],0,',',' ') ?></div>
      <div class="text-muted small"><i class="bi bi-brush me-1 text-primary"></i>Wszystkich</div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm h-100"><div class="card-body py-2">
      <div class="h4 mb-0"><?= number_format($stats['real'],0,',',' ') ?></div>
      <div class="text-muted small"><i class="bi bi-gear me-1" style="color:#0dcaf0"></i>W realizacji</div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm h-100"><div class="card-body py-2">
      <div class="h4 mb-0"><?= number_format($stats['done'],0,',',' ') ?></div>
      <div class="text-muted small"><i class="bi bi-flag me-1" style="color:#6366f1"></i>Zakończonych</div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm h-100"><div class="card-body py-2">
      <div class="h4 mb-0" style="font-size:1.25rem"><?= number_format($stats['suma'],0,',',' ') ?> <span class="small text-muted">PLN</span></div>
      <div class="text-muted small"><i class="bi bi-coin me-1 text-success"></i>Suma brutto (aktywne)</div>
    </div></div>
  </div>
</div>

<!-- ══ FILTRY ═══════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3">
  <div class="card-body p-2">
    <form method="get" id="dz-filter-form">
      <div class="row g-2 align-items-center">
        <div class="col">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
            <input name="q" class="form-control" placeholder="Numer, nazwisko, PESEL, opis dzieła, projekt, uwagi…"
                   value="<?= h($f['q']) ?>" autocomplete="off">
          </div>
        </div>
        <div class="col-auto">
          <select name="status" class="form-select form-select-sm">
            <option value="">— status —</option>
            <?php foreach ($statuses as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $f['status']===$k?'selected':'' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search"></i> Szukaj</button>
        </div>
        <div class="col-auto">
          <a class="adv-toggle <?= $adv_open?'is-active':'' ?>" id="dz-adv-toggle"
             data-bs-toggle="collapse" href="#advPanel" role="button"
             aria-expanded="<?= $adv_open?'true':'false' ?>" aria-controls="advPanel">
            <i class="bi bi-sliders"></i> Zaawansowane
            <?php if ($adv_count): ?><span class="badge rounded-pill bg-primary ms-1" style="font-size:.7rem"><?= $adv_count ?></span><?php endif; ?>
          </a>
        </div>
        <?php if ($f['q'] || $f['status'] || $adv_count): ?>
        <div class="col-auto">
          <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i> Wyczyść</a>
        </div>
        <?php endif; ?>
      </div>

      <div class="collapse <?= $adv_open?'show':'' ?>" id="advPanel">
        <div class="adv-panel mt-2">
          <div class="row g-2">
            <div class="col-md-3">
              <label class="adv-label">Opiekun</label>
              <input name="opiekun" class="form-control" placeholder="Nazwisko…" value="<?= h($f['opiekun']) ?>">
            </div>
            <div class="col-md-3">
              <label class="adv-label">Nr projektu</label>
              <input name="projekt" class="form-control" placeholder="Projekt, program…" value="<?= h($f['projekt']) ?>">
            </div>
            <div class="col-md-3">
              <label class="adv-label">Forma podpisania</label>
              <select name="forma" class="form-select">
                <option value="">— wszystkie —</option>
                <?php foreach ($formy as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $f['forma']===$k?'selected':'' ?>><?= h($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-3 pb-1">
              <div class="form-check mb-0">
                <input type="checkbox" name="kup50" value="1" id="f_kup50" class="form-check-input" <?= $f['kup50']?'checked':'' ?>>
                <label for="f_kup50" class="form-check-label" style="font-size:.82rem;cursor:pointer">50% KUP</label>
              </div>
              <div class="form-check mb-0">
                <input type="checkbox" name="prawa" value="1" id="f_prawa" class="form-check-input" <?= $f['prawa']?'checked':'' ?>>
                <label for="f_prawa" class="form-check-label" style="font-size:.82rem;cursor:pointer">Prawa autorskie</label>
              </div>
            </div>
            <div class="col-md-4">
              <label class="adv-label">Data zawarcia</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="date" name="data_od" class="form-control" value="<?= h($f['data_od']) ?>">
                <span class="input-group-text">do</span>
                <input type="date" name="data_do" class="form-control" value="<?= h($f['data_do']) ?>">
              </div>
            </div>
            <div class="col-md-4">
              <label class="adv-label">Termin oddania dzieła</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="date" name="termin_od" class="form-control" value="<?= h($f['termin_od']) ?>">
                <span class="input-group-text">do</span>
                <input type="date" name="termin_do" class="form-control" value="<?= h($f['termin_do']) ?>">
              </div>
            </div>
            <div class="col-md-4">
              <label class="adv-label">Wynagrodzenie brutto (PLN)</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="number" name="wyna_od" class="form-control" placeholder="0" min="0" step="100" value="<?= h($f['wyna_od']) ?>">
                <span class="input-group-text">do</span>
                <input type="number" name="wyna_do" class="form-control" placeholder="∞" min="0" step="100" value="<?= h($f['wyna_do']) ?>">
              </div>
            </div>
            <div class="col-md-3">
              <label class="adv-label">Sortuj według</label>
              <div class="input-group input-group-sm">
                <select name="sort" class="form-select">
                  <option value="created_at"          <?= $sort_col==='created_at'?'selected':'' ?>>Data dodania</option>
                  <option value="data_zawarcia"        <?= $sort_col==='data_zawarcia'?'selected':'' ?>>Data zawarcia</option>
                  <option value="termin_oddania"       <?= $sort_col==='termin_oddania'?'selected':'' ?>>Termin oddania</option>
                  <option value="imie_nazwisko"        <?= $sort_col==='imie_nazwisko'?'selected':'' ?>>Nazwisko</option>
                  <option value="wynagrodzenie_brutto" <?= $sort_col==='wynagrodzenie_brutto'?'selected':'' ?>>Wynagrodzenie</option>
                </select>
                <select name="dir" class="form-select" style="max-width:5rem">
                  <option value="desc" <?= $sort_dir==='DESC'?'selected':'' ?>>↓</option>
                  <option value="asc"  <?= $sort_dir==='ASC'?'selected':'' ?>>↑</option>
                </select>
              </div>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="dz-adv-clear">
                <i class="bi bi-eraser"></i> Wyczyść zaawansowane
              </button>
              <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Zastosuj filtry</button>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<div id="dz-list-region" aria-busy="false">
  <?= _dzielo_table_html($rows, $total, $pag, $per, $f, $statuses, $formy, $can_edit, $sort_col, $sort_dir) ?>
</div>

<div id="dz-live" class="visually-hidden" aria-live="polite"></div>

<?php include dirname(__DIR__) . '/includes/bulk_bar.php'; ?>

<script>
/* ── AJAX: filtry, paginacja, szybkie akcje ───────────────────────────────── */
(function () {
  'use strict';
  var region  = document.getElementById('dz-list-region');
  var form    = document.getElementById('dz-filter-form');
  var badgeEl = document.getElementById('dz-total-badge');
  var liveEl  = document.getElementById('dz-live');
  if (!region || !form) return;

  var abort = null, debounce = null;

  function formParams() {
    var p = new URLSearchParams();
    new FormData(form).forEach(function (v, k) { if (v) p.set(k, v); });
    return p;
  }

  function syncForm(params) {
    form.querySelectorAll('select, input[type="text"], input[type="date"], input[type="number"]').forEach(function (el) {
      if (el.name) el.value = params.get(el.name) || '';
    });
    form.querySelectorAll('input[type="checkbox"]').forEach(function (el) {
      if (el.name) el.checked = params.get(el.name) === el.value;
    });
  }

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

    fetch(url.toString(), abort ? { signal: abort.signal } : {})
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) return;
        region.innerHTML = data.list_html;
        if (badgeEl && data.total !== undefined) badgeEl.textContent = data.total;
        if (push !== false) {
          var histUrl = new URL(window.location.href);
          histUrl.search = params.toString();
          history.pushState({ dz: params.toString() }, '', histUrl.toString());
        }
        region.removeAttribute('aria-busy');
        region.style.opacity = '1';
        region.style.pointerEvents = '';
        bindRegion();
        if (window.bulkClear) window.bulkClear();
        if (liveEl) {
          liveEl.textContent = '';
          setTimeout(function () { liveEl.textContent = 'Załadowano ' + (data.total || 0) + ' umów.'; }, 50);
        }
      })
      .catch(function (e) {
        if (e && e.name === 'AbortError') return;
        region.removeAttribute('aria-busy');
        region.style.opacity = '1';
        region.style.pointerEvents = '';
      });
  }

  function postAction(fd) {
    return fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
    }).then(function (r) { return r.json(); });
  }

  function bindRegion() {
    region.querySelectorAll('a.page-link').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var href = a.getAttribute('href');
        if (!href) return;
        load(new URLSearchParams(new URL(href, window.location.href).search), true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });
    region.querySelectorAll('.active-chip').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var p = new URLSearchParams(new URL(a.getAttribute('href'), window.location.href).search);
        syncForm(p);
        load(p, true);
      });
    });
    region.querySelectorAll('a[data-quick-status]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('_action', 'quick_status');
        fd.append('id', a.dataset.id);
        fd.append('new_status', a.dataset.quickStatus);
        postAction(fd).then(function (d) { if (d.ok) load(formParams(), false); }).catch(function () {});
      });
    });
    region.querySelectorAll('form[data-ajax-action="delete"]').forEach(function (frm) {
      frm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!confirm('Usunąć umowę ' + (frm.dataset.contractName || '') + '?')) return;
        postAction(new FormData(frm)).then(function (d) { if (d.ok) load(formParams(), false); }).catch(function () {});
      });
    });
    region.querySelectorAll('tr[data-row-href]').forEach(function (tr) {
      tr.addEventListener('click', function (e) {
        if (e.target.closest('a,button,input,label,.dropdown')) return;
        window.location.href = tr.dataset.rowHref;
      });
    });
  }

  var CSRF = <?= json_encode(csrf_token()) ?>;

  form.addEventListener('submit', function (e) { e.preventDefault(); load(formParams(), true); });
  form.querySelectorAll('select').forEach(function (sel) {
    sel.addEventListener('change', function () { load(formParams(), true); });
  });
  var searchInp = form.querySelector('input[name="q"]');
  if (searchInp) {
    searchInp.addEventListener('input', function () {
      clearTimeout(debounce);
      debounce = setTimeout(function () { load(formParams(), true); }, 380);
    });
    searchInp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); clearTimeout(debounce); load(formParams(), true); }
    });
  }

  var advPanel = document.getElementById('advPanel');
  if (advPanel) {
    advPanel.querySelectorAll('input[type="date"], input[type="number"], input[type="checkbox"]').forEach(function (el) {
      el.addEventListener('change', function () { load(formParams(), true); });
    });
    advPanel.querySelectorAll('input[type="text"]').forEach(function (el) {
      el.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(function () { load(formParams(), true); }, 380);
      });
    });
    var advClear = document.getElementById('dz-adv-clear');
    if (advClear) advClear.addEventListener('click', function () {
      advPanel.querySelectorAll('input, select').forEach(function (el) {
        if (el.type === 'checkbox') el.checked = false;
        else if (el.name === 'sort') el.value = 'created_at';
        else if (el.name === 'dir')  el.value = 'desc';
        else el.value = '';
      });
      load(formParams(), true);
    });
  }

  window.addEventListener('popstate', function (e) {
    var p = (e.state && e.state.dz !== undefined)
      ? new URLSearchParams(e.state.dz)
      : new URLSearchParams(window.location.search);
    syncForm(p);
    load(p, false);
  });

  bindRegion();
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
