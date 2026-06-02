<?php
/**
 * contracts/wolontariat/list.php — Lista porozumień wolontariackich.
 * Przebudowana od podstaw: statystyki, filtry, nowoczesny układ.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_wolontariat', 'Ten typ umowy');

$PAGE_TITLE = 'Porozumienia wolontariackie';
$TYPE  = 'wolontariat';
$TABLE = 'umowy_wolontariat';

// Filtry
$search    = trim($_GET['q']        ?? '');
$status    = $_GET['status']        ?? '';
$opiekun   = trim($_GET['opiekun']  ?? '');
$projekt   = trim($_GET['projekt']  ?? '');
$page      = max(1, intval($_GET['page'] ?? 1));
$per       = 20;
$view_mode = in_array($_GET['view'] ?? 'table', ['table','cards']) ? ($_GET['view'] ?? 'table') : 'table';

$where  = '1=1';
$params = [];
if ($search) {
    $where .= " AND (numer_umowy LIKE ? OR imie_nazwisko LIKE ? OR nr_rejestru LIKE ? OR pesel LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($status) {
    $where .= " AND status = ?";
    $params[] = $status;
}
if ($opiekun) {
    $where .= " AND opiekun LIKE ?";
    $params[] = "%$opiekun%";
}
if ($projekt) {
    $where .= " AND projekt_program LIKE ?";
    $params[] = "%$projekt%";
}

$total  = (int)(db_one("SELECT COUNT(*) AS c FROM {$TABLE} WHERE {$where}", $params)['c'] ?? 0);
$pag    = paginate($total, $per, $page, APP_URL . "/contracts/{$TYPE}/list.php?" . http_build_query(array_filter(['q' => $search, 'status' => $status, 'opiekun' => $opiekun, 'projekt' => $projekt, 'view' => $view_mode !== 'table' ? $view_mode : null])));
$rows   = db_all("SELECT * FROM {$TABLE} WHERE {$where} ORDER BY created_at DESC LIMIT {$per} OFFSET {$pag['offset']}", $params);

// Statystyki globalne
$status_counts = [];
$all_statuses  = ['projekt', 'podpisana', 'w realizacji', 'zakończona', 'rozwiązana', 'anulowana'];
$all_total     = 0;
foreach ($all_statuses as $s) {
    $c = (int)(db_one("SELECT COUNT(*) AS c FROM {$TABLE} WHERE status=?", [$s])['c'] ?? 0);
    $status_counts[$s] = $c;
    $all_total += $c;
}
$active_total = ($status_counts['podpisana'] ?? 0) + ($status_counts['w realizacji'] ?? 0) + ($status_counts['projekt'] ?? 0);

// Etykiety i kolory statusów
$status_cfg = [
    'projekt'     => ['label' => 'Projekt',     'color' => '#6366F1', 'bg' => '#EEF2FF', 'icon' => 'bi-file-earmark-text'],
    'podpisana'   => ['label' => 'Podpisana',   'color' => '#2E844A', 'bg' => '#EFF7ED', 'icon' => 'bi-check-circle'],
    'w realizacji'=> ['label' => 'W realizacji','color' => '#0176D3', 'bg' => '#EEF4FF', 'icon' => 'bi-play-circle'],
    'zakończona'  => ['label' => 'Zakończona',  'color' => '#374151', 'bg' => '#F3F4F6', 'icon' => 'bi-flag'],
    'rozwiązana'  => ['label' => 'Rozwiązana',  'color' => '#D97706', 'bg' => '#FEF3E2', 'icon' => 'bi-x-circle'],
    'anulowana'   => ['label' => 'Anulowana',   'color' => '#DC2626', 'bg' => '#FEF2F2', 'icon' => 'bi-slash-circle'],
];

// Lista opiekunów do filtra
$opiekunowie = db_all("SELECT DISTINCT opiekun FROM {$TABLE} WHERE opiekun IS NOT NULL AND opiekun != '' ORDER BY opiekun");

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<style>
/* ── Wolontariat list styles ────────────────────────────── */
.wol-stat-pill {
  display: inline-flex; align-items: center; gap: .4rem;
  padding: .35rem .85rem;
  border-radius: 2rem;
  font-size: .78rem; font-weight: 600;
  text-decoration: none; cursor: pointer;
  border: 2px solid transparent;
  transition: all .12s;
}
.wol-stat-pill:hover { filter: brightness(.95); transform: translateY(-1px); }
.wol-stat-pill.selected { box-shadow: 0 0 0 3px rgba(0,0,0,.15); }

.wol-filter-bar {
  background: #fff;
  border: 1px solid #E5E7EB;
  border-radius: 10px;
  padding: .75rem 1rem;
}

.wol-table th {
  font-size: .73rem; font-weight: 700; letter-spacing: .05em;
  text-transform: uppercase; color: #6B7280;
  padding: .6rem .9rem;
  background: #F9FAFB;
  border-bottom: 2px solid #E5E7EB;
  white-space: nowrap;
}
.wol-table td {
  padding: .72rem .9rem;
  vertical-align: middle;
  border-bottom: 1px solid #F3F4F6;
  font-size: .855rem;
}
.wol-table tr:last-child td { border-bottom: none; }
.wol-table tr:hover td { background: #FAFAFA; }

.wol-num  { font-family: monospace; font-weight: 700; font-size: .87rem; color: #111827; }
.wol-name { font-weight: 600; color: #111827; }
.wol-sub  { font-size: .75rem; color: #9CA3AF; margin-top: 1px; }
.wol-status-pill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .18rem .6rem; border-radius: 2rem;
  font-size: .72rem; font-weight: 600; white-space: nowrap;
}

/* Karta wolontariusza */
.wol-card {
  background: #fff; border: 1px solid #E5E7EB; border-radius: 12px;
  padding: 1rem 1.1rem; transition: box-shadow .15s, transform .12s;
}
.wol-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.1); transform: translateY(-2px); }
.wol-card-num { font-family: monospace; font-size: .75rem; color: #9CA3AF; margin-bottom: .2rem; }
.wol-card-name { font-weight: 700; font-size: .95rem; color: #111827; margin-bottom: .15rem; }
.wol-card-meta { font-size: .78rem; color: #6B7280; }
</style>

<!-- ── Nagłówek strony ──────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 d-flex align-items-center gap-2">
      <span class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width:38px;height:38px">
        <i class="bi bi-heart-fill text-primary" style="font-size:1rem"></i>
      </span>
      Porozumienia wolontariackie
      <span class="badge bg-secondary"><?= $all_total ?></span>
    </h4>
    <div class="text-muted small ms-1" style="margin-left:50px">
      Aktywnych: <?= $active_total ?> · zakończonych: <?= ($status_counts['zakończona'] ?? 0) + ($status_counts['rozwiązana'] ?? 0) ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <?php if (can_edit()): ?>
    <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/new.php" class="btn btn-primary">
      <i class="bi bi-plus-lg me-1"></i>Nowe porozumienie
    </a>
    <a href="<?= APP_URL ?>/reports/export.php?type=<?= $TYPE ?>" class="btn btn-outline-secondary btn-sm" title="Eksportuj do CSV">
      <i class="bi bi-download"></i>
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- ── Pasy statusów (klikalne) ───────────────────────────────────────────── -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="?<?= http_build_query(array_filter(['q'=>$search,'opiekun'=>$opiekun,'projekt'=>$projekt])) ?>"
     class="wol-stat-pill <?= !$status ? 'selected' : '' ?>"
     style="background:#F3F4F6;color:#374151;border-color:<?= !$status ? '#374151' : 'transparent' ?>">
    <i class="bi bi-list-ul"></i> Wszystkie <strong><?= $all_total ?></strong>
  </a>
  <?php foreach ($status_cfg as $sk => $sv): ?>
  <?php if (!$status_counts[$sk]) continue; ?>
  <a href="?<?= http_build_query(array_filter(['q'=>$search,'status'=>$sk,'opiekun'=>$opiekun,'projekt'=>$projekt])) ?>"
     class="wol-stat-pill <?= $status===$sk ? 'selected' : '' ?>"
     style="background:<?= $sv['bg'] ?>;color:<?= $sv['color'] ?>;border-color:<?= $status===$sk ? $sv['color'] : 'transparent' ?>">
    <i class="bi <?= $sv['icon'] ?>"></i> <?= $sv['label'] ?> <strong><?= $status_counts[$sk] ?></strong>
  </a>
  <?php endforeach; ?>
</div>

<!-- ── Pasek filtrów ──────────────────────────────────────────────────────── -->
<form method="get" class="wol-filter-bar mb-3">
  <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
  <?php if ($view_mode !== 'table'): ?><input type="hidden" name="view" value="<?= h($view_mode) ?>"><?php endif; ?>
  <div class="row g-2 align-items-center">
    <div class="col-md-4">
      <div class="input-group input-group-sm">
        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
        <input name="q" class="form-control" placeholder="Numer, nazwisko, PESEL, nr rej.…"
               value="<?= h($search) ?>" autocomplete="off">
      </div>
    </div>
    <div class="col-md-3">
      <select name="opiekun" class="form-select form-select-sm">
        <option value="">— wszyscy opiekunowie —</option>
        <?php foreach ($opiekunowie as $op): ?>
        <option value="<?= h($op['opiekun']) ?>" <?= $opiekun===$op['opiekun']?'selected':'' ?>>
          <?= h($op['opiekun']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <input name="projekt" class="form-control form-control-sm" placeholder="Projekt/program…"
             value="<?= h($projekt) ?>">
    </div>
    <div class="col-auto">
      <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel"></i> Filtruj</button>
      <?php if ($search || $status || $opiekun || $projekt): ?>
      <a href="?" class="btn btn-outline-secondary btn-sm ms-1"><i class="bi bi-x"></i> Wyczyść</a>
      <?php endif; ?>
    </div>
    <div class="col-auto ms-auto">
      <div class="btn-group btn-group-sm" role="group" aria-label="Widok">
        <a href="?<?= http_build_query(array_filter(['q'=>$search,'status'=>$status,'opiekun'=>$opiekun,'projekt'=>$projekt,'view'=>'table'])) ?>"
           class="btn btn-outline-secondary <?= $view_mode==='table'?'active':'' ?>" title="Widok tabeli">
          <i class="bi bi-table"></i>
        </a>
        <a href="?<?= http_build_query(array_filter(['q'=>$search,'status'=>$status,'opiekun'=>$opiekun,'projekt'=>$projekt,'view'=>'cards'])) ?>"
           class="btn btn-outline-secondary <?= $view_mode==='cards'?'active':'' ?>" title="Widok kart">
          <i class="bi bi-grid-3x2-gap"></i>
        </a>
      </div>
    </div>
  </div>
</form>

<!-- ── Wyniki ─────────────────────────────────────────────────────────────── -->
<?php if (!$rows): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5">
    <i class="bi bi-heart display-4 text-secondary opacity-25 d-block mb-3"></i>
    <h5 class="text-muted">Brak porozumień wolontariackich</h5>
    <p class="text-muted small mb-3">
      <?= $search || $status || $opiekun || $projekt ? 'Spróbuj zmienić kryteria filtrowania.' : 'Nie dodano jeszcze żadnych porozumień.' ?>
    </p>
    <?php if (!$search && !$status && !$opiekun && !$projekt && can_edit()): ?>
    <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/new.php" class="btn btn-primary">
      <i class="bi bi-plus-lg me-1"></i>Dodaj pierwsze porozumienie
    </a>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($view_mode === 'cards'): ?>
<!-- WIDOK KART -->
<div class="row g-3 mb-3">
  <?php foreach ($rows as $r):
    $sc = $status_cfg[$r['status']] ?? ['label'=>$r['status'],'color'=>'#6B7280','bg'=>'#F3F4F6','icon'=>'bi-circle'];
    $bezterm = !empty($r['bezterminowa']);
  ?>
  <div class="col-sm-6 col-xl-4">
    <div class="wol-card h-100">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div class="wol-card-num"><?= h($r['numer_umowy']) ?></div>
        <span class="wol-status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
          <i class="bi <?= $sc['icon'] ?>"></i><?= $sc['label'] ?>
        </span>
      </div>
      <div class="wol-card-name"><?= h($r['imie_nazwisko']) ?></div>
      <div class="wol-card-meta mb-2">
        <?php if ($r['miejsce_wolontariatu']): ?>
        <i class="bi bi-geo-alt me-1"></i><?= h($r['miejsce_wolontariatu']) ?>
        <?php endif; ?>
      </div>
      <div class="d-flex justify-content-between align-items-end">
        <div class="text-muted" style="font-size:.75rem">
          <?php if ($r['data_zawarcia']): ?>
          <i class="bi bi-calendar3 me-1"></i><?= date_pl($r['data_zawarcia']) ?>
          <?php endif; ?>
          <?php if ($r['opiekun']): ?>
          <br><i class="bi bi-person me-1"></i><?= h($r['opiekun']) ?>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-1">
          <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/view.php?id=<?= $r['id'] ?>"
             class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye"></i>
          </a>
          <?php if (can_edit()): ?>
          <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/edit.php?id=<?= $r['id'] ?>"
             class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-pencil"></i>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php else: ?>
<!-- WIDOK TABELI -->
<div class="card shadow-sm mb-3">
  <div class="table-responsive">
    <table class="table wol-table mb-0">
      <thead>
        <tr>
          <th>Numer umowy</th>
          <th class="d-none d-md-table-cell">Nr rejestru</th>
          <th>Wolontariusz</th>
          <th class="d-none d-lg-table-cell">Miejsce / projekt</th>
          <th class="d-none d-sm-table-cell">Data zawarcia</th>
          <th class="d-none d-xl-table-cell">Opiekun</th>
          <th>Status</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $sc = $status_cfg[$r['status']] ?? ['label'=>$r['status'],'color'=>'#6B7280','bg'=>'#F3F4F6','icon'=>'bi-circle'];
          $bezterm = !empty($r['bezterminowa']);
        ?>
        <tr>
          <td>
            <div class="wol-num"><?= h($r['numer_umowy']) ?></div>
            <?php if (!empty($r['ubezpieczenie_nnw']) || !empty($r['ubezpieczenie_oc'])): ?>
            <div class="wol-sub">
              <?= !empty($r['ubezpieczenie_nnw']) ? '<span class="badge bg-success-subtle text-success" style="font-size:.65rem">NNW</span> ' : '' ?>
              <?= !empty($r['ubezpieczenie_oc'])  ? '<span class="badge bg-info-subtle text-info" style="font-size:.65rem">OC</span>' : '' ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="font-monospace text-muted d-none d-md-table-cell" style="font-size:.78rem">
            <?= h($r['nr_rejestru'] ?? '') ?: '—' ?>
          </td>
          <td>
            <div class="wol-name"><?= h($r['imie_nazwisko']) ?></div>
            <?php if (!empty($r['niepelnoletni'])): ?>
            <div class="wol-sub"><span class="badge bg-warning-subtle text-warning" style="font-size:.65rem">niepełnoletni</span></div>
            <?php endif; ?>
          </td>
          <td class="d-none d-lg-table-cell">
            <?php if ($r['miejsce_wolontariatu']): ?>
            <div style="font-size:.83rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <i class="bi bi-geo-alt text-muted me-1"></i><?= h($r['miejsce_wolontariatu']) ?>
            </div>
            <?php endif; ?>
            <?php if ($r['projekt_program']): ?>
            <div class="wol-sub"><i class="bi bi-folder2 me-1"></i><?= h($r['projekt_program']) ?></div>
            <?php endif; ?>
          </td>
          <td class="d-none d-sm-table-cell" style="font-size:.82rem;white-space:nowrap">
            <?= date_pl($r['data_zawarcia']) ?>
            <?php if ($r['data_zakonczenia'] && !$bezterm): ?>
            <div class="wol-sub">→ <?= date_pl($r['data_zakonczenia']) ?></div>
            <?php elseif ($bezterm): ?>
            <div class="wol-sub"><i class="bi bi-infinity"></i> bezterminowa</div>
            <?php endif; ?>
          </td>
          <td class="d-none d-xl-table-cell" style="font-size:.8rem;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= h($r['opiekun'] ?? '—') ?>
          </td>
          <td>
            <span class="wol-status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
              <i class="bi <?= $sc['icon'] ?>"></i><?= $sc['label'] ?>
            </span>
          </td>
          <td class="text-end" style="white-space:nowrap">
            <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/view.php?id=<?= $r['id'] ?>"
               class="btn btn-sm btn-outline-primary py-0 px-2" title="Podgląd">
              <i class="bi bi-eye"></i>
            </a>
            <?php if (can_edit()): ?>
            <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/edit.php?id=<?= $r['id'] ?>"
               class="btn btn-sm btn-outline-secondary py-0 px-2" title="Edytuj">
              <i class="bi bi-pencil"></i>
            </a>
            <?php endif; ?>
            <?php if (is_admin() || (can_edit() && (int)($r['created_by'] ?? 0) === (int)current_user()['id'])): ?>
            <form method="post" action="<?= APP_URL ?>/contracts/delete.php" class="d-inline"
                  onsubmit="return confirm('Usunąć umowę <?= h(addslashes($r['numer_umowy'] ?? '#'.$r['id'])) ?>?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="type"  value="<?= $TYPE ?>">
              <input type="hidden" name="id"    value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń">
                <i class="bi bi-trash"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Stopka z paginacją -->
  <div class="card-footer d-flex justify-content-between align-items-center py-2" style="background:#FAFAFA">
    <small class="text-muted">
      Znaleziono: <strong><?= $total ?></strong>
      <?= $search || $status || $opiekun || $projekt ? '(filtrowanie aktywne)' : '' ?>
    </small>
    <?php if ($pag['pages'] > 1): ?>
    <?= pagination_html($pag) ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
