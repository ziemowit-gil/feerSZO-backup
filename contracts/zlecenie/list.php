<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_zlecenie', 'Ten typ umowy');
$PAGE_TITLE = 'Umowy zlecenie';
$TYPE  = 'zlecenie';
$TABLE = 'umowy_zlecenie';

// ── Filtry podstawowe
$search = trim($_GET['q']      ?? '');
$status = $_GET['status']      ?? '';

// ── Filtry zaawansowane
$opiekun   = trim($_GET['opiekun']   ?? '');
$projekt   = trim($_GET['projekt']   ?? '');
$forma     = $_GET['forma']          ?? '';
$typ_st    = $_GET['typ_st']         ?? '';
$data_od   = $_GET['data_od']        ?? '';
$data_do   = $_GET['data_do']        ?? '';
$wyna_od   = $_GET['wyna_od']        ?? '';
$wyna_do   = $_GET['wyna_do']        ?? '';
$zus_only  = !empty($_GET['zus']);

// ── Sortowanie
$_sorts   = ['created_at' => 'created_at', 'data_zawarcia' => 'data_zawarcia',
             'data_zakonczenia' => 'data_zakonczenia', 'imie_nazwisko' => 'imie_nazwisko',
             'wynagrodzenie_brutto' => 'wynagrodzenie_brutto'];
$sort_col = $_sorts[$_GET['sort'] ?? ''] ?? 'created_at';
$sort_dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$page = max(1, intval($_GET['page'] ?? 1));
$per  = 20;

// ── WHERE
$where  = '1=1';
$params = [];

if ($search) {
    $where .= " AND (numer_umowy LIKE ? OR imie_nazwisko LIKE ? OR nr_rejestru LIKE ?"
            . " OR pesel LIKE ? OR email LIKE ? OR przedmiot_zlecenia LIKE ?"
            . " OR numer_projektu LIKE ? OR opiekun LIKE ? OR uwagi LIKE ?)";
    $params = array_merge($params, array_fill(0, 9, "%$search%"));
}
if ($status)   { $where .= " AND status = ?"; $params[] = $status; }
if ($opiekun)  { $where .= " AND opiekun LIKE ?"; $params[] = "%$opiekun%"; }
if ($projekt)  { $where .= " AND numer_projektu LIKE ?"; $params[] = "%$projekt%"; }
if ($forma)    { $where .= " AND forma_podpisania = ?"; $params[] = $forma; }
if ($typ_st)   { $where .= " AND typ_stawki = ?"; $params[] = $typ_st; }
if ($data_od)  { $where .= " AND data_zawarcia >= ?"; $params[] = $data_od; }
if ($data_do)  { $where .= " AND data_zawarcia <= ?"; $params[] = $data_do; }
if ($wyna_od !== '') { $where .= " AND CAST(wynagrodzenie_brutto AS REAL) >= ?"; $params[] = (float)$wyna_od; }
if ($wyna_do !== '') { $where .= " AND CAST(wynagrodzenie_brutto AS REAL) <= ?"; $params[] = (float)$wyna_do; }
if ($zus_only) { $where .= " AND zus_skladki = 1"; }

$adv_count = (int)!!$opiekun + (int)!!$projekt + (int)!!$forma + (int)!!$typ_st
           + (int)!!$data_od + (int)!!$data_do + (int)($wyna_od !== '') + (int)($wyna_do !== '')
           + (int)$zus_only;
$adv_open  = $adv_count > 0 || !empty($_GET['adv']);

$total = (int)(db_one("SELECT COUNT(*) AS c FROM {$TABLE} WHERE {$where}", $params)['c'] ?? 0);

$_qs_base = array_filter([
    'q' => $search, 'status' => $status, 'opiekun' => $opiekun, 'projekt' => $projekt,
    'forma' => $forma, 'typ_st' => $typ_st, 'data_od' => $data_od, 'data_do' => $data_do,
    'wyna_od' => $wyna_od, 'wyna_do' => $wyna_do, 'zus' => $zus_only ? '1' : null,
    'sort' => $sort_col !== 'created_at' ? $sort_col : null,
    'dir'  => $sort_dir !== 'DESC' ? 'asc' : null,
]);
$pag  = paginate($total, $per, $page, APP_URL . "/contracts/{$TYPE}/list.php?" . http_build_query($_qs_base));
$rows = db_all("SELECT * FROM {$TABLE} WHERE {$where} ORDER BY {$sort_col} {$sort_dir} LIMIT {$per} OFFSET {$pag['offset']}", $params);

$statuses = ['projekt' => 'Projekt', 'podpisana' => 'Podpisana', 'w realizacji' => 'W realizacji',
             'zakończona' => 'Zakończona', 'rozwiązana' => 'Rozwiązana', 'anulowana' => 'Anulowana'];
$typy_st  = ['miesięczna' => 'Miesięczna', 'godzinowa' => 'Godzinowa', 'akordowa' => 'Akordowa', 'ryczałtowa' => 'Ryczałtowa'];
$formy    = ['papierowa' => 'Papierowa', 'elektroniczna' => 'Elektroniczna', 'kwalifikowany' => 'Kwalifikowany e-podpis'];

include dirname(dirname(__DIR__)) . '/includes/header.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_preview_notice.php';
if (contract_is_preview('zlecenie')) echo contract_preview_notice('zlecenie');
require_once dirname(__DIR__) . '/includes/adv_filter.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-person-lines-fill text-primary"></i> Umowy zlecenie
    <span class="badge bg-secondary ms-1"><?= $total ?></span>
  </h4>
  <?php if (can_edit()): ?>
  <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/add.php" class="btn btn-primary">
    <i class="bi bi-plus-lg"></i> Nowa umowa
  </a>
  <?php endif; ?>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body p-2">
    <form method="get" id="filter-form">
      <div class="row g-2 align-items-center">
        <div class="col">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
            <input name="q" class="form-control" placeholder="Numer, nazwisko, PESEL, przedmiot, projekt, uwagi…"
                   value="<?= h($search) ?>" autocomplete="off">
          </div>
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
        <?php if ($search || $status || $adv_count): ?>
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
              <label class="adv-label">Typ stawki</label>
              <select name="typ_st" class="form-select">
                <option value="">— wszystkie —</option>
                <?php foreach ($typy_st as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $typ_st === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="adv-label">Forma podpisania</label>
              <select name="forma" class="form-select">
                <option value="">— wszystkie —</option>
                <?php foreach ($formy as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $forma === $k ? 'selected' : '' ?>><?= h($v) ?></option>
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
              <label class="adv-label">Wynagrodzenie brutto (PLN)</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="number" name="wyna_od" class="form-control" placeholder="0" min="0" step="100" value="<?= h($wyna_od) ?>">
                <span class="input-group-text">do</span>
                <input type="number" name="wyna_do" class="form-control" placeholder="∞" min="0" step="100" value="<?= h($wyna_do) ?>">
              </div>
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <div class="form-check mb-0">
                <input type="checkbox" name="zus" value="1" id="f_zus" class="form-check-input" <?= $zus_only ? 'checked' : '' ?>>
                <label for="f_zus" class="form-check-label" style="font-size:.82rem;cursor:pointer">
                  <i class="bi bi-shield-check text-info"></i> ZUS składki
                </label>
              </div>
            </div>
            <div class="col-md-3">
              <label class="adv-label">Sortuj według</label>
              <div class="input-group input-group-sm">
                <select name="sort" class="form-select">
                  <option value="created_at"          <?= $sort_col==='created_at'?'selected':'' ?>>Data dodania</option>
                  <option value="data_zawarcia"        <?= $sort_col==='data_zawarcia'?'selected':'' ?>>Data zawarcia</option>
                  <option value="data_zakonczenia"     <?= $sort_col==='data_zakonczenia'?'selected':'' ?>>Data zakończenia</option>
                  <option value="imie_nazwisko"        <?= $sort_col==='imie_nazwisko'?'selected':'' ?>>Nazwisko</option>
                  <option value="wynagrodzenie_brutto" <?= $sort_col==='wynagrodzenie_brutto'?'selected':'' ?>>Wynagrodzenie</option>
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

<?php
$_base_url = APP_URL . "/contracts/{$TYPE}/list.php";
$_chip_qs  = fn($without) => $_base_url . '?' . http_build_query(array_filter(array_merge($_qs_base, ['page'=>null]), fn($v,$k) => $k !== $without, ARRAY_FILTER_USE_BOTH));
if ($adv_count):
?>
<div class="active-chips mb-2">
  <?php if ($opiekun): ?><a href="<?= $_chip_qs('opiekun') ?>" class="active-chip">Opiekun: <?= h($opiekun) ?> <span class="chip-x">×</span></a><?php endif; ?>
  <?php if ($projekt): ?><a href="<?= $_chip_qs('projekt') ?>" class="active-chip">Projekt: <?= h($projekt) ?> <span class="chip-x">×</span></a><?php endif; ?>
  <?php if ($typ_st): ?><a href="<?= $_chip_qs('typ_st') ?>" class="active-chip">Stawka: <?= h($typy_st[$typ_st] ?? $typ_st) ?> <span class="chip-x">×</span></a><?php endif; ?>
  <?php if ($forma): ?><a href="<?= $_chip_qs('forma') ?>" class="active-chip">Forma: <?= h($formy[$forma] ?? $forma) ?> <span class="chip-x">×</span></a><?php endif; ?>
  <?php if ($data_od || $data_do): ?>
  <a href="<?= $_base_url . '?' . http_build_query(array_filter(array_merge($_qs_base, ['data_od'=>null,'data_do'=>null,'page'=>null]))) ?>" class="active-chip">
    Zawarcie: <?= $data_od ?: '…' ?> – <?= $data_do ?: '…' ?> <span class="chip-x">×</span>
  </a>
  <?php endif; ?>
  <?php if ($wyna_od !== '' || $wyna_do !== ''): ?>
  <a href="<?= $_base_url . '?' . http_build_query(array_filter(array_merge($_qs_base, ['wyna_od'=>null,'wyna_do'=>null,'page'=>null]))) ?>" class="active-chip">
    Brutto: <?= $wyna_od !== '' ? number_format((float)$wyna_od, 0, ',', ' ') : '0' ?> – <?= $wyna_do !== '' ? number_format((float)$wyna_do, 0, ',', ' ') : '∞' ?> PLN <span class="chip-x">×</span>
  </a>
  <?php endif; ?>
  <?php if ($zus_only): ?><a href="<?= $_chip_qs('zus') ?>" class="active-chip"><i class="bi bi-shield-check"></i> ZUS <span class="chip-x">×</span></a><?php endif; ?>
</div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover contracts-table mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:2%" class="ps-3">
            <input type="checkbox" id="cb-all" class="form-check-input" title="Zaznacz wszystkie">
          </th>
          <th>Numer</th>
          <th class="d-none d-md-table-cell">Nr rejestru</th>
          <th>Zleceniobiorca</th>
          <th class="d-none d-lg-table-cell">Przedmiot</th>
          <th class="d-none d-sm-table-cell">Data zawarcia</th>
          <th class="d-none d-sm-table-cell">Do</th>
          <th class="d-none d-xl-table-cell">Brutto</th>
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
          <td class="fw-semibold"><?= h($r['numer_umowy']) ?></td>
          <td class="font-monospace small d-none d-md-table-cell"><?= h($r['nr_rejestru'] ?? '') ?: '—' ?></td>
          <td>
            <?= h($r['imie_nazwisko']) ?>
            <?php if ($r['email'] ?? ''): ?><div class="text-muted" style="font-size:.75rem"><?= h($r['email']) ?></div><?php endif; ?>
          </td>
          <td class="d-none d-lg-table-cell" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.83rem">
            <?= h($r['przedmiot_zlecenia']) ?>
          </td>
          <td class="d-none d-sm-table-cell" style="white-space:nowrap;font-size:.82rem"><?= date_pl($r['data_zawarcia']) ?></td>
          <td class="d-none d-sm-table-cell" style="white-space:nowrap;font-size:.82rem"><?= date_pl($r['data_zakonczenia']) ?></td>
          <td class="d-none d-xl-table-cell"><?= money($r['wynagrodzenie_brutto']) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-end" style="white-space:nowrap">
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
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">
          <i class="bi bi-person-lines-fill display-6 d-block mb-2 opacity-25"></i>
          Brak umów zlecenie<?= $search || $status || $adv_count ? ' spełniających kryteria' : '' ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php include dirname(__DIR__) . '/includes/bulk_bar.php'; ?>
  <div class="card-footer d-flex justify-content-between align-items-center py-2" style="background:#FAFAFA">
    <small class="text-muted">Znaleziono: <strong><?= $total ?></strong><?= $search || $status || $adv_count ? ' — filtrowanie aktywne' : '' ?></small>
    <?php if ($pag['pages'] > 1): ?>
    <?= pagination_html($pag) ?>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
