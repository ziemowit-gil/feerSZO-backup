<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/powierzenie_schema.php';
require_once dirname(dirname(__DIR__)) . '/includes/powierzenie_pliki.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_powierzenie', 'Ten typ umowy');
$PAGE_TITLE = 'Umowy powierzenia zadania publicznego';
$TYPE  = 'powierzenie';
$TABLE = 'umowy_powierzenie';

// ── Filtry podstawowe
$search = trim($_GET['q']      ?? '');
$status = $_GET['status']      ?? '';
$forma  = $_GET['forma_zl']    ?? '';

// ── Filtry zaawansowane
$opiekun    = trim($_GET['opiekun']   ?? '');
$projekt    = trim($_GET['projekt']   ?? '');
$tryb       = $_GET['tryb']           ?? '';
$data_od    = $_GET['data_od']        ?? '';
$data_do    = $_GET['data_do']        ?? '';
$koniec_od  = $_GET['koniec_od']      ?? '';
$koniec_do  = $_GET['koniec_do']      ?? '';
$kwota_od   = $_GET['kwota_od']       ?? '';
$kwota_do   = $_GET['kwota_do']       ?? '';

// ── Sortowanie
$_sorts   = ['created_at' => 'created_at', 'data_zawarcia' => 'data_zawarcia',
             'data_zakonczenia' => 'data_zakonczenia', 'organ_zlecajacy' => 'organ_zlecajacy',
             'kwota_dotacji' => 'kwota_dotacji'];
$sort_col = $_sorts[$_GET['sort'] ?? ''] ?? 'created_at';
$sort_dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$page = max(1, intval($_GET['page'] ?? 1));
$per  = 20;

// ── WHERE
$where  = '1=1';
$params = [];

if ($search) {
    $where .= " AND (numer_umowy LIKE ? OR nazwa_zadania LIKE ? OR organ_zlecajacy LIKE ?"
            . " OR nr_rejestru LIKE ? OR email LIKE ? OR nazwa_konkursu LIKE ?"
            . " OR numer_projektu LIKE ? OR opiekun LIKE ? OR uwagi LIKE ? OR sfera_zadania LIKE ?)";
    $params = array_merge($params, array_fill(0, 10, "%$search%"));
}
if ($status)     { $where .= " AND status = ?"; $params[] = $status; }
if ($forma)      { $where .= " AND forma_zlecenia = ?"; $params[] = $forma; }
if ($opiekun)    { $where .= " AND opiekun LIKE ?"; $params[] = "%$opiekun%"; }
if ($projekt)    { $where .= " AND numer_projektu LIKE ?"; $params[] = "%$projekt%"; }
if ($tryb)       { $where .= " AND tryb_zlecenia = ?"; $params[] = $tryb; }
if ($data_od)    { $where .= " AND data_zawarcia >= ?"; $params[] = $data_od; }
if ($data_do)    { $where .= " AND data_zawarcia <= ?"; $params[] = $data_do; }
if ($koniec_od)  { $where .= " AND data_zakonczenia >= ?"; $params[] = $koniec_od; }
if ($koniec_do)  { $where .= " AND data_zakonczenia <= ?"; $params[] = $koniec_do; }
if ($kwota_od !== '') { $where .= " AND CAST(kwota_dotacji AS REAL) >= ?"; $params[] = (float)$kwota_od; }
if ($kwota_do !== '') { $where .= " AND CAST(kwota_dotacji AS REAL) <= ?"; $params[] = (float)$kwota_do; }
$where .= ' AND ' . contract_access_where($TYPE);

$adv_count = (int)!!$opiekun + (int)!!$projekt + (int)!!$tryb
           + (int)!!$data_od + (int)!!$data_do + (int)!!$koniec_od + (int)!!$koniec_do
           + (int)($kwota_od !== '') + (int)($kwota_do !== '');
$adv_open  = $adv_count > 0 || !empty($_GET['adv']);

$total = (int)(db_one("SELECT COUNT(*) AS c FROM {$TABLE} WHERE {$where}", $params)['c'] ?? 0);

$_qs_base = array_filter([
    'q' => $search, 'status' => $status, 'forma_zl' => $forma,
    'opiekun' => $opiekun, 'projekt' => $projekt, 'tryb' => $tryb,
    'data_od' => $data_od, 'data_do' => $data_do,
    'koniec_od' => $koniec_od, 'koniec_do' => $koniec_do,
    'kwota_od' => $kwota_od, 'kwota_do' => $kwota_do,
    'sort' => $sort_col !== 'created_at' ? $sort_col : null,
    'dir'  => $sort_dir !== 'DESC' ? 'asc' : null,
]);
$pag  = paginate($total, $per, $page, APP_URL . "/contracts/{$TYPE}/list.php?" . http_build_query($_qs_base));
$rows = db_all("SELECT * FROM {$TABLE} WHERE {$where} ORDER BY {$sort_col} {$sort_dir} LIMIT {$per} OFFSET {$pag['offset']}", $params);

$statuses = contractStatusOptions($TYPE); // słownik umowy_statusy
$formy_zlecenia = ['powierzenie' => 'Powierzenie', 'wsparcie' => 'Wsparcie'];
$tryby = ['konkurs' => 'Otwarty konkurs ofert', 'art19a' => 'Art. 19a — mały grant', 'inny' => 'Inny tryb'];

// ── Kafle Metro: przegląd umów (bez filtrów listy, z ograniczeniem dostępu)
$_today = date('Y-m-d');
$_in30  = date('Y-m-d', strtotime('+30 days'));
$_st = db_one("SELECT COUNT(*) AS wszystkie,
        SUM(CASE WHEN " . contractStatusSqlIn(['active']) . " THEN 1 ELSE 0 END) AS realizacja,
        SUM(CASE WHEN status = 'do rozliczenia' THEN 1 ELSE 0 END) AS rozliczenie,
        SUM(CASE WHEN status = 'jednostronnie' THEN 1 ELSE 0 END) AS jednostronnie,
        SUM(CASE WHEN status = 'w podpisie w instytucji' THEN 1 ELSE 0 END) AS w_instytucji,
        SUM(CASE WHEN data_zakonczenia >= ? AND data_zakonczenia <= ?
                  AND COALESCE(status,'') NOT IN (" . contractStatusSqlList('closed', 'cancelled') . ") THEN 1 ELSE 0 END) AS konczace,
        SUM(CASE WHEN COALESCE(status,'') NOT IN ('rozwiązana','anulowana','projekt','jednostronnie','w podpisie w instytucji') THEN CAST(kwota_dotacji AS REAL) ELSE 0 END) AS dotacje
    FROM {$TABLE} WHERE " . contract_access_where($TYPE), [$_today, $_in30]) ?: [];
$_soon_on = $koniec_od === $_today && $koniec_do === $_in30;

include dirname(dirname(__DIR__)) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/adv_filter.php';
?>

<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/powierzenie_metro.css?v=<?= (int)@filemtime(dirname(dirname(__DIR__)) . '/assets/css/powierzenie_metro.css') ?>">
<div class="pw-metro">

<div class="pw-head">
  <div>
    <div class="pw-eyebrow"><i class="bi bi-bank me-1"></i>Umowy · zadania publiczne</div>
    <h1 class="pw-title">Umowy powierzenia<small><?= $total ?> <?= $total === 1 ? 'umowa' : (($total % 10 >= 2 && $total % 10 <= 4 && ($total % 100 < 12 || $total % 100 > 14)) ? 'umowy' : 'umów') ?></small></h1>
  </div>
  <?php if (can_edit()): ?>
  <div class="pw-cmds">
    <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/add.php" class="pw-cmd is-primary"><i class="bi bi-plus-lg"></i>Nowa umowa</a>
  </div>
  <?php endif; ?>
</div>

<nav class="pw-tiles" aria-label="Przegląd umów powierzenia">
  <a href="?" class="pw-tile is-dark<?= !$search && !$status && !$forma && !$adv_count ? ' is-on' : '' ?>">
    <span class="pw-tile-lbl"><i class="bi bi-collection"></i>Wszystkie</span>
    <span class="pw-tile-val"><?= (int)($_st['wszystkie'] ?? 0) ?></span>
  </a>
  <a href="?status=<?= urlencode('jednostronnie') ?>" class="pw-tile<?= (int)($_st['jednostronnie'] ?? 0) ? ' is-acc' : '' ?><?= $status === 'jednostronnie' ? ' is-on' : '' ?>">
    <span class="pw-tile-lbl"><i class="bi bi-pen"></i>Jednostronnie</span>
    <span class="pw-tile-val"><?= (int)($_st['jednostronnie'] ?? 0) ?></span>
    <span class="pw-tile-sub">podpisane przez Fundację</span>
  </a>
  <a href="?status=<?= urlencode('w podpisie w instytucji') ?>" class="pw-tile<?= (int)($_st['w_instytucji'] ?? 0) ? ' is-orange' : '' ?><?= $status === 'w podpisie w instytucji' ? ' is-on' : '' ?>">
    <span class="pw-tile-lbl"><i class="bi bi-building"></i>W podpisie w instytucji</span>
    <span class="pw-tile-val"><?= (int)($_st['w_instytucji'] ?? 0) ?></span>
    <span class="pw-tile-sub">czekają na podpis organu</span>
  </a>
  <a href="?status=<?= urlencode('w realizacji') ?>" class="pw-tile is-acc<?= $status === 'w realizacji' ? ' is-on' : '' ?>">
    <span class="pw-tile-lbl"><i class="bi bi-play-circle"></i>W realizacji</span>
    <span class="pw-tile-val"><?= (int)($_st['realizacja'] ?? 0) ?></span>
    <span class="pw-tile-sub">podpisane i realizowane</span>
  </a>
  <a href="?koniec_od=<?= $_today ?>&amp;koniec_do=<?= $_in30 ?>&amp;sort=data_zakonczenia&amp;dir=asc" class="pw-tile<?= (int)($_st['konczace'] ?? 0) ? ' is-orange' : '' ?><?= $_soon_on ? ' is-on' : '' ?>">
    <span class="pw-tile-lbl"><i class="bi bi-hourglass-split"></i>Kończą się</span>
    <span class="pw-tile-val"><?= (int)($_st['konczace'] ?? 0) ?></span>
    <span class="pw-tile-sub">w ciągu 30 dni</span>
  </a>
  <a href="?status=<?= urlencode('do rozliczenia') ?>" class="pw-tile<?= (int)($_st['rozliczenie'] ?? 0) ? ' is-green' : '' ?><?= $status === 'do rozliczenia' ? ' is-on' : '' ?>">
    <span class="pw-tile-lbl"><i class="bi bi-calculator"></i>Do rozliczenia</span>
    <span class="pw-tile-val"><?= (int)($_st['rozliczenie'] ?? 0) ?></span>
  </a>
  <div class="pw-tile">
    <span class="pw-tile-lbl"><i class="bi bi-cash-stack"></i>Dotacje</span>
    <span class="pw-tile-val is-money"><?= money((float)($_st['dotacje'] ?? 0), 'PLN') ?></span>
    <span class="pw-tile-sub">umowy podpisane i dalej</span>
  </div>
</nav>

<div class="card pw-filter">
  <div class="card-body p-2">
    <form method="get" id="filter-form">
      <div class="row g-2 align-items-center">
        <div class="col">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
            <input name="q" class="form-control" placeholder="Numer, zadanie, organ, konkurs, projekt…"
                   value="<?= h($search) ?>" autocomplete="off">
          </div>
        </div>
        <div class="col-auto">
          <select name="forma_zl" class="form-select form-select-sm">
            <option value="">— forma —</option>
            <?php foreach ($formy_zlecenia as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $forma === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <select name="status" class="form-select form-select-sm">
            <option value="">— status —</option>
            <?php foreach ($statuses as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search"></i> Szukaj</button>
        </div>
        <div class="col-auto">
          <a class="adv-toggle <?= $adv_open ? 'is-active' : '' ?>"
             data-bs-toggle="collapse" href="#advPanel" role="button"
             aria-expanded="<?= $adv_open ? 'true' : 'false' ?>">
            <i class="bi bi-sliders"></i> Zaawansowane
            <?php if ($adv_count): ?>
            <span class="badge rounded-pill bg-primary ms-1" style="font-size:.7rem"><?= $adv_count ?></span>
            <?php endif; ?>
          </a>
        </div>
        <?php if ($search || $status || $forma || $adv_count): ?>
        <div class="col-auto">
          <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i> Wyczyść</a>
        </div>
        <?php endif; ?>
      </div>

      <div class="collapse <?= $adv_open ? 'show' : '' ?>" id="advPanel">
        <div class="adv-panel mt-2">
          <div class="row g-2">
            <div class="col-md-3">
              <label class="adv-label">Opiekun</label>
              <input name="opiekun" class="form-control" placeholder="Nazwisko…" value="<?= h($opiekun) ?>">
            </div>
            <div class="col-md-3">
              <label class="adv-label">Nr projektu</label>
              <input name="projekt" class="form-control" placeholder="Projekt, program…" value="<?= h($projekt) ?>">
            </div>
            <div class="col-md-3">
              <label class="adv-label">Tryb zlecenia</label>
              <select name="tryb" class="form-select">
                <option value="">— wszystkie —</option>
                <?php foreach ($tryby as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $tryb === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="adv-label">Data zawarcia</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="date" name="data_od" class="form-control" value="<?= h($data_od) ?>">
                <span class="input-group-text">do</span>
                <input type="date" name="data_do" class="form-control" value="<?= h($data_do) ?>">
              </div>
            </div>
            <div class="col-md-4">
              <label class="adv-label">Realizacja do</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="date" name="koniec_od" class="form-control" value="<?= h($koniec_od) ?>">
                <span class="input-group-text">do</span>
                <input type="date" name="koniec_do" class="form-control" value="<?= h($koniec_do) ?>">
              </div>
            </div>
            <div class="col-md-4">
              <label class="adv-label">Kwota dotacji</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="number" name="kwota_od" class="form-control" placeholder="0" min="0" step="100" value="<?= h($kwota_od) ?>">
                <span class="input-group-text">do</span>
                <input type="number" name="kwota_do" class="form-control" placeholder="∞" min="0" step="100" value="<?= h($kwota_do) ?>">
              </div>
            </div>
            <div class="col-md-3">
              <label class="adv-label">Sortuj według</label>
              <div class="input-group input-group-sm">
                <select name="sort" class="form-select">
                  <option value="created_at"       <?= $sort_col==='created_at'?'selected':'' ?>>Data dodania</option>
                  <option value="data_zawarcia"     <?= $sort_col==='data_zawarcia'?'selected':'' ?>>Data zawarcia</option>
                  <option value="data_zakonczenia"  <?= $sort_col==='data_zakonczenia'?'selected':'' ?>>Realizacja do</option>
                  <option value="organ_zlecajacy"   <?= $sort_col==='organ_zlecajacy'?'selected':'' ?>>Organ</option>
                  <option value="kwota_dotacji"     <?= $sort_col==='kwota_dotacji'?'selected':'' ?>>Kwota dotacji</option>
                </select>
                <select name="dir" class="form-select" style="max-width:5rem">
                  <option value="desc" <?= $sort_dir==='DESC'?'selected':'' ?>>↓</option>
                  <option value="asc"  <?= $sort_dir==='ASC'?'selected':'' ?>>↑</option>
                </select>
              </div>
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Zastosuj filtry</button>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card pw-table-card">
  <div class="table-responsive">
    <table class="table table-hover contracts-table mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:2%" class="ps-3">
            <input type="checkbox" id="cb-all" class="form-check-input" title="Zaznacz wszystkie">
          </th>
          <th>Numer</th>
          <th class="d-none d-md-table-cell">Nr rejestru</th>
          <th>Zadanie publiczne</th>
          <th>Organ zlecający</th>
          <th class="d-none d-sm-table-cell">Realizacja</th>
          <th class="d-none d-xl-table-cell">Kwota dotacji</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="ps-3">
            <input type="checkbox" class="cb-row form-check-input" value="<?= (int)$r['id'] ?>" aria-label="Zaznacz">
          </td>
          <td><a class="pw-num" href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/view.php?id=<?= (int)$r['id'] ?>"><?= h($r['numer_umowy'] ?: '#' . $r['id']) ?></a></td>
          <td class="font-monospace small d-none d-md-table-cell"><?= h($r['nr_rejestru'] ?? '') ?: '—' ?></td>
          <td style="font-size:.83rem;max-width:240px">
            <?= h($r['nazwa_zadania'] ?: '—') ?>
            <?php if ($r['forma_zlecenia'] ?? ''): ?><div class="text-muted" style="font-size:.72rem"><?= h($formy_zlecenia[$r['forma_zlecenia']] ?? $r['forma_zlecenia']) ?></div><?php endif; ?>
          </td>
          <td>
            <?= h($r['organ_zlecajacy'] ?: '—') ?>
            <?php if ($r['email'] ?? ''): ?><div class="text-muted" style="font-size:.75rem"><?= h($r['email']) ?></div><?php endif; ?>
          </td>
          <td class="d-none d-sm-table-cell" style="white-space:nowrap;font-size:.82rem">
            <?= date_pl($r['data_rozpoczecia']) ?: '—' ?>
            <?php if ($r['data_zakonczenia']):
              $_soon = $r['data_zakonczenia'] >= $_today && $r['data_zakonczenia'] <= $_in30
                    && !in_array(contractStatusGroup($r['status'] ?? ''), ['closed', 'cancelled'], true); ?>
            <span class="text-muted">→</span> <span class="<?= $_soon ? 'pw-end-soon' : '' ?>"<?= $_soon ? ' title="Kończy się w ciągu 30 dni"' : '' ?>><?= date_pl($r['data_zakonczenia']) ?></span><?php endif; ?>
          </td>
          <td class="d-none d-xl-table-cell"><?= money($r['kwota_dotacji'], $r['waluta'] ?: 'PLN') ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-end" style="white-space:nowrap"><span class="pw-row-acts">
            <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-eye"></i></a>
            <?php if (can_edit()): ?>
            <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/edit.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
            <?php if (is_admin() || (can_edit() && (int)($r['created_by'] ?? 0) === (int)current_user()['id'])): ?>
            <form method="post" action="<?= APP_URL ?>/contracts/delete.php" class="d-inline"
                  onsubmit="return confirm('Usunąć umowę <?= h(addslashes($r['numer_umowy'] ?? '#'.$r['id'])) ?>?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="type"  value="<?= $TYPE ?>">
              <input type="hidden" name="id"    value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="9" class="text-center pw-empty">
          <b>Brak umów<?= $search || $status || $forma || $adv_count ? ' spełniających kryteria' : '' ?></b>
          <?php if ($search || $status || $forma || $adv_count): ?><a href="?">Pokaż wszystkie</a><?php endif; ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php include dirname(__DIR__) . '/includes/bulk_bar.php'; ?>
  <div class="card-footer d-flex justify-content-between align-items-center py-2">
    <small class="text-muted">Znaleziono: <strong><?= $total ?></strong><?= $search || $status || $forma || $adv_count ? ' — filtrowanie aktywne' : '' ?></small>
    <?php if ($pag['pages'] > 1): ?>
    <?= pagination_html($pag) ?>
    <?php endif; ?>
  </div>
</div>

</div><!-- /pw-metro -->
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
