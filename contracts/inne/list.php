<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_inne', 'Ten typ umowy');
$PAGE_TITLE = 'Inne umowy';
$TYPE  = 'inne';
$TABLE = 'umowy_inne';

// ── Filtry podstawowe
$search = trim($_GET['q']      ?? '');
$status = $_GET['status']      ?? '';
$typ    = $_GET['typ']         ?? '';

// ── Filtry zaawansowane
$opiekun    = trim($_GET['opiekun']   ?? '');
$projekt    = trim($_GET['projekt']   ?? '');
$forma      = $_GET['forma']          ?? '';
$waluta     = $_GET['waluta']         ?? '';
$data_od    = $_GET['data_od']        ?? '';
$data_do    = $_GET['data_do']        ?? '';
$koniec_od  = $_GET['koniec_od']      ?? '';
$koniec_do  = $_GET['koniec_do']      ?? '';
$wartosc_od = $_GET['wartosc_od']     ?? '';
$wartosc_do = $_GET['wartosc_do']     ?? '';

// ── Sortowanie
$_sorts   = ['created_at' => 'created_at', 'data_zawarcia' => 'data_zawarcia',
             'data_zakonczenia' => 'data_zakonczenia', 'strona_umowy' => 'strona_umowy',
             'wartosc_umowy' => 'wartosc_umowy'];
$sort_col = $_sorts[$_GET['sort'] ?? ''] ?? 'created_at';
$sort_dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$page = max(1, intval($_GET['page'] ?? 1));
$per  = 20;

// ── WHERE
$where  = '1=1';
$params = [];

if ($search) {
    $where .= " AND (numer_umowy LIKE ? OR strona_umowy LIKE ? OR nr_rejestru LIKE ?"
            . " OR pesel_nip_krs LIKE ? OR email LIKE ? OR przedmiot_umowy LIKE ?"
            . " OR numer_projektu LIKE ? OR opiekun LIKE ? OR uwagi LIKE ? OR typ_umowy LIKE ?)";
    $params = array_merge($params, array_fill(0, 10, "%$search%"));
}
if ($status)     { $where .= " AND status = ?"; $params[] = $status; }
if ($typ)        { $where .= " AND typ_umowy = ?"; $params[] = $typ; }
if ($opiekun)    { $where .= " AND opiekun LIKE ?"; $params[] = "%$opiekun%"; }
if ($projekt)    { $where .= " AND numer_projektu LIKE ?"; $params[] = "%$projekt%"; }
if ($forma)      { $where .= " AND forma_podpisania = ?"; $params[] = $forma; }
if ($waluta)     { $where .= " AND waluta = ?"; $params[] = $waluta; }
if ($data_od)    { $where .= " AND data_zawarcia >= ?"; $params[] = $data_od; }
if ($data_do)    { $where .= " AND data_zawarcia <= ?"; $params[] = $data_do; }
if ($koniec_od)  { $where .= " AND data_zakonczenia >= ?"; $params[] = $koniec_od; }
if ($koniec_do)  { $where .= " AND data_zakonczenia <= ?"; $params[] = $koniec_do; }
if ($wartosc_od !== '') { $where .= " AND CAST(wartosc_umowy AS REAL) >= ?"; $params[] = (float)$wartosc_od; }
if ($wartosc_do !== '') { $where .= " AND CAST(wartosc_umowy AS REAL) <= ?"; $params[] = (float)$wartosc_do; }

$adv_count = (int)!!$opiekun + (int)!!$projekt + (int)!!$forma + (int)!!$waluta
           + (int)!!$data_od + (int)!!$data_do + (int)!!$koniec_od + (int)!!$koniec_do
           + (int)($wartosc_od !== '') + (int)($wartosc_do !== '');
$adv_open  = $adv_count > 0 || !empty($_GET['adv']);

$total = (int)(db_one("SELECT COUNT(*) AS c FROM {$TABLE} WHERE {$where}", $params)['c'] ?? 0);

$_qs_base = array_filter([
    'q' => $search, 'status' => $status, 'typ' => $typ,
    'opiekun' => $opiekun, 'projekt' => $projekt, 'forma' => $forma, 'waluta' => $waluta,
    'data_od' => $data_od, 'data_do' => $data_do,
    'koniec_od' => $koniec_od, 'koniec_do' => $koniec_do,
    'wartosc_od' => $wartosc_od, 'wartosc_do' => $wartosc_do,
    'sort' => $sort_col !== 'created_at' ? $sort_col : null,
    'dir'  => $sort_dir !== 'DESC' ? 'asc' : null,
]);
$pag  = paginate($total, $per, $page, APP_URL . "/contracts/{$TYPE}/list.php?" . http_build_query($_qs_base));
$rows = db_all("SELECT * FROM {$TABLE} WHERE {$where} ORDER BY {$sort_col} {$sort_dir} LIMIT {$per} OFFSET {$pag['offset']}", $params);

$statuses  = ['projekt' => 'Projekt', 'podpisana' => 'Podpisana', 'w realizacji' => 'W realizacji',
              'zakończona' => 'Zakończona', 'rozwiązana' => 'Rozwiązana', 'anulowana' => 'Anulowana'];
$typy_umow = ['najem' => 'Najem', 'użyczenie' => 'Użyczenie', 'darowizna' => 'Darowizna',
              'partnerstwo' => 'Partnerstwo', 'NDA' => 'NDA', 'licencja' => 'Licencja', 'inne' => 'Inne'];
$formy    = ['papierowa' => 'Papierowa', 'elektroniczna' => 'Elektroniczna', 'kwalifikowany' => 'Kwalifikowany e-podpis'];
$waluty   = db_all("SELECT DISTINCT waluta FROM {$TABLE} WHERE waluta IS NOT NULL AND waluta != '' ORDER BY waluta");

include dirname(dirname(__DIR__)) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/adv_filter.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-file-earmark-text text-primary"></i> Inne umowy
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
            <input name="q" class="form-control" placeholder="Numer, strona umowy, przedmiot, projekt, uwagi…"
                   value="<?= h($search) ?>" autocomplete="off">
          </div>
        </div>
        <div class="col-auto">
          <select name="typ" class="form-select form-select-sm">
            <option value="">— typ —</option>
            <?php foreach ($typy_umow as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $typ === $k ? 'selected' : '' ?>><?= h($v) ?></option>
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
        <?php if ($search || $status || $typ || $adv_count): ?>
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
            <div class="col-md-2">
              <label class="adv-label">Waluta</label>
              <select name="waluta" class="form-select">
                <option value="">— wszystkie —</option>
                <?php foreach ($waluty as $w): ?>
                <option value="<?= h($w['waluta']) ?>" <?= $waluta === $w['waluta'] ? 'selected' : '' ?>><?= h($w['waluta']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
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
              <label class="adv-label">Data zakończenia</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="date" name="koniec_od" class="form-control" value="<?= h($koniec_od) ?>">
                <span class="input-group-text">do</span>
                <input type="date" name="koniec_do" class="form-control" value="<?= h($koniec_do) ?>">
              </div>
            </div>
            <div class="col-md-4">
              <label class="adv-label">Wartość umowy</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="number" name="wartosc_od" class="form-control" placeholder="0" min="0" step="100" value="<?= h($wartosc_od) ?>">
                <span class="input-group-text">do</span>
                <input type="number" name="wartosc_do" class="form-control" placeholder="∞" min="0" step="100" value="<?= h($wartosc_do) ?>">
              </div>
            </div>
            <div class="col-md-3">
              <label class="adv-label">Sortuj według</label>
              <div class="input-group input-group-sm">
                <select name="sort" class="form-select">
                  <option value="created_at"      <?= $sort_col==='created_at'?'selected':'' ?>>Data dodania</option>
                  <option value="data_zawarcia"    <?= $sort_col==='data_zawarcia'?'selected':'' ?>>Data zawarcia</option>
                  <option value="data_zakonczenia" <?= $sort_col==='data_zakonczenia'?'selected':'' ?>>Data zakończenia</option>
                  <option value="strona_umowy"     <?= $sort_col==='strona_umowy'?'selected':'' ?>>Strona umowy</option>
                  <option value="wartosc_umowy"    <?= $sort_col==='wartosc_umowy'?'selected':'' ?>>Wartość</option>
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
  <?php if ($waluta): ?><a href="<?= $_chip_qs('waluta') ?>" class="active-chip">Waluta: <?= h($waluta) ?> <span class="chip-x">×</span></a><?php endif; ?>
  <?php if ($forma): ?><a href="<?= $_chip_qs('forma') ?>" class="active-chip">Forma: <?= h($formy[$forma] ?? $forma) ?> <span class="chip-x">×</span></a><?php endif; ?>
  <?php if ($data_od || $data_do): ?>
  <a href="<?= $_base_url . '?' . http_build_query(array_filter(array_merge($_qs_base, ['data_od'=>null,'data_do'=>null,'page'=>null]))) ?>" class="active-chip">
    Zawarcie: <?= $data_od ?: '…' ?> – <?= $data_do ?: '…' ?> <span class="chip-x">×</span>
  </a>
  <?php endif; ?>
  <?php if ($koniec_od || $koniec_do): ?>
  <a href="<?= $_base_url . '?' . http_build_query(array_filter(array_merge($_qs_base, ['koniec_od'=>null,'koniec_do'=>null,'page'=>null]))) ?>" class="active-chip">
    Zakończenie: <?= $koniec_od ?: '…' ?> – <?= $koniec_do ?: '…' ?> <span class="chip-x">×</span>
  </a>
  <?php endif; ?>
  <?php if ($wartosc_od !== '' || $wartosc_do !== ''): ?>
  <a href="<?= $_base_url . '?' . http_build_query(array_filter(array_merge($_qs_base, ['wartosc_od'=>null,'wartosc_do'=>null,'page'=>null]))) ?>" class="active-chip">
    Wartość: <?= $wartosc_od !== '' ? number_format((float)$wartosc_od,0,',',' ') : '0' ?> – <?= $wartosc_do !== '' ? number_format((float)$wartosc_do,0,',',' ') : '∞' ?> <span class="chip-x">×</span>
  </a>
  <?php endif; ?>
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
          <th>Typ umowy</th>
          <th>Strona umowy</th>
          <th class="d-none d-sm-table-cell">Data zawarcia</th>
          <th class="d-none d-sm-table-cell">Data zakończenia</th>
          <th class="d-none d-xl-table-cell">Wartość</th>
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
          <td style="font-size:.83rem"><?= h($typy_umow[$r['typ_umowy']] ?? $r['typ_umowy']) ?></td>
          <td>
            <?= h($r['strona_umowy'] ?: ($r['imie_nazwisko'] ?? '—')) ?>
            <?php if ($r['email'] ?? ''): ?><div class="text-muted" style="font-size:.75rem"><?= h($r['email']) ?></div><?php endif; ?>
          </td>
          <td class="d-none d-sm-table-cell" style="white-space:nowrap;font-size:.82rem"><?= date_pl($r['data_zawarcia']) ?></td>
          <td class="d-none d-sm-table-cell" style="white-space:nowrap;font-size:.82rem">
            <?php if ($r['czas_nieokreslony']): ?>
              <span class="text-muted small">∞</span>
            <?php else: ?>
              <?= date_pl($r['data_zakonczenia']) ?>
            <?php endif; ?>
          </td>
          <td class="d-none d-xl-table-cell"><?= money($r['wartosc_umowy'], $r['waluta'] ?: 'PLN') ?></td>
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
          <i class="bi bi-file-earmark-text display-6 d-block mb-2 opacity-25"></i>
          Brak umów<?= $search || $status || $typ || $adv_count ? ' spełniających kryteria' : '' ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php include dirname(__DIR__) . '/includes/bulk_bar.php'; ?>
  <div class="card-footer d-flex justify-content-between align-items-center py-2" style="background:#FAFAFA">
    <small class="text-muted">Znaleziono: <strong><?= $total ?></strong><?= $search || $status || $typ || $adv_count ? ' — filtrowanie aktywne' : '' ?></small>
    <?php if ($pag['pages'] > 1): ?>
    <?= pagination_html($pag) ?>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
