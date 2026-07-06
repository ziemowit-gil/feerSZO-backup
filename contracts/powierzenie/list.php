<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
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

$statuses = ['projekt' => 'Projekt', 'podpisana' => 'Podpisana', 'w realizacji' => 'W realizacji',
             'do rozliczenia' => 'Do rozliczenia', 'zakończona' => 'Zakończona', 'rozwiązana' => 'Rozwiązana', 'anulowana' => 'Anulowana'];
$formy_zlecenia = ['powierzenie' => 'Powierzenie', 'wsparcie' => 'Wsparcie'];
$tryby = ['konkurs' => 'Otwarty konkurs ofert', 'art19a' => 'Art. 19a — mały grant', 'inny' => 'Inny tryb'];

include dirname(dirname(__DIR__)) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/adv_filter.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-bank text-primary"></i> Umowy powierzenia zadania publicznego
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
          <td class="fw-semibold"><?= h($r['numer_umowy']) ?></td>
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
            <?php if ($r['data_zakonczenia']): ?><span class="text-muted">→</span> <?= date_pl($r['data_zakonczenia']) ?><?php endif; ?>
          </td>
          <td class="d-none d-xl-table-cell"><?= money($r['kwota_dotacji'], $r['waluta'] ?: 'PLN') ?></td>
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
        <tr><td colspan="9" class="text-center text-muted py-4">
          <i class="bi bi-bank display-6 d-block mb-2 opacity-25"></i>
          Brak umów<?= $search || $status || $forma || $adv_count ? ' spełniających kryteria' : '' ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php include dirname(__DIR__) . '/includes/bulk_bar.php'; ?>
  <div class="card-footer d-flex justify-content-between align-items-center py-2" style="background:#FAFAFA">
    <small class="text-muted">Znaleziono: <strong><?= $total ?></strong><?= $search || $status || $forma || $adv_count ? ' — filtrowanie aktywne' : '' ?></small>
    <?php if ($pag['pages'] > 1): ?>
    <?= pagination_html($pag) ?>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
