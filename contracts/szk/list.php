<?php
/**
 * contracts/szk/list.php — Umowy szkoleniowe (SZK) w Menu Umowy: lista z filtrami, kafelkami statusów i sortowaniem.
 * Dane i numeracja: modules/ti_training_contract (TiTrainingContractStore, tabela umowy_szk).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractStore.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_szk', 'Ten typ umowy');
TiTrainingContractStore::migrate();
$PAGE_TITLE = 'Umowy szkoleniowe (SZK)';
$TYPE  = 'szk';
$TABLE = TiTrainingContractStore::TABLE;

$search  = trim($_GET['q'] ?? '');
$status  = (string)($_GET['status'] ?? '');
$fin     = (string)($_GET['fin'] ?? '');
$opiekun = trim($_GET['opiekun'] ?? '');
$data_od = (string)($_GET['data_od'] ?? '');
$data_do = (string)($_GET['data_do'] ?? '');
$tests   = !empty($_GET['testowe']);

$_sorts   = ['created_at' => 'created_at', 'data_zawarcia' => 'data_zawarcia', 'imie_nazwisko' => 'imie_nazwisko',
             'numer_umowy' => 'numer_umowy', 'status' => 'status'];
$sort_col = $_sorts[$_GET['sort'] ?? ''] ?? 'created_at';
$sort_dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 20;

$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= " AND (numer_umowy LIKE ? OR nr_rejestru LIKE ? OR imie_nazwisko LIKE ? OR temat LIKE ? OR email LIKE ? OR pesel LIKE ? OR platnik_nazwa LIKE ? OR opiekun LIKE ?)";
    $params = array_merge($params, array_fill(0, 8, "%$search%"));
}
if ($status !== '')  { $where .= " AND status = ?"; $params[] = $status; }
if ($fin !== '')     { $where .= " AND dane LIKE ?"; $params[] = '%"finansowanie":"' . $fin . '"%'; }
if ($opiekun !== '') { $where .= " AND opiekun LIKE ?"; $params[] = "%$opiekun%"; }
if ($data_od !== '') { $where .= " AND data_zawarcia >= ?"; $params[] = $data_od; }
if ($data_do !== '') { $where .= " AND data_zawarcia <= ?"; $params[] = $data_do; }
if (!$tests)         { $where .= " AND is_test = 0"; }
$where .= ' AND ' . contract_access_where($TYPE);

$total = (int)(db_one("SELECT COUNT(*) AS c FROM {$TABLE} WHERE {$where}", $params)['c'] ?? 0);
$_qs = array_filter(['q' => $search, 'status' => $status, 'fin' => $fin, 'opiekun' => $opiekun, 'data_od' => $data_od, 'data_do' => $data_do,
    'testowe' => $tests ? 1 : null, 'sort' => $sort_col !== 'created_at' ? $sort_col : null, 'dir' => $sort_dir !== 'DESC' ? 'asc' : null]);
$pag  = paginate($total, $per, $page, APP_URL . '/contracts/szk/list.php?' . http_build_query($_qs));
$rows = db_all("SELECT * FROM {$TABLE} WHERE {$where} ORDER BY {$sort_col} {$sort_dir}, id DESC LIMIT {$per} OFFSET {$pag['offset']}", $params);
$nTests = TiTrainingContractStore::countTest();

$sortLink = function (string $col, string $label) use ($_qs, $sort_col, $sort_dir): string {
    $dir = ($sort_col === $col && $sort_dir === 'ASC') ? 'desc' : 'asc';
    $q = http_build_query(array_merge($_qs, ['sort' => $col, 'dir' => $dir]));
    $ic = $sort_col === $col ? ($sort_dir === 'ASC' ? ' <i class="bi bi-caret-up-fill small" aria-hidden="true"></i>' : ' <i class="bi bi-caret-down-fill small" aria-hidden="true"></i>') : '';
    return '<a class="text-decoration-none text-reset" href="?' . h($q) . '">' . h($label) . $ic . '</a>';
};

include dirname(dirname(__DIR__)) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/status_tiles.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-mortarboard text-primary"></i> Umowy szkoleniowe (SZK)
    <span class="badge bg-secondary ms-1"><?= (int)$total ?></span></h4>
  <?php if (can_edit()): ?>
  <a href="<?= APP_URL ?>/contracts/szk/edit.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nowa umowa</a>
  <?php endif; ?>
</div>

<?= contract_status_tiles($TYPE, $TABLE, $status) ?>
<div class="card shadow-sm mb-3"><div class="card-body p-2">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md">
      <label class="visually-hidden" for="szk-q">Szukaj</label>
      <div class="input-group input-group-sm"><span class="input-group-text bg-white"><i class="bi bi-search text-muted" aria-hidden="true"></i></span>
        <input id="szk-q" name="q" class="form-control" placeholder="Numer, uczestnik, szkolenie, płatnik, PESEL…" value="<?= h($search) ?>" autocomplete="off"></div>
    </div>
    <div class="col-auto"><label class="form-label small mb-0" for="szk-st">Status</label>
      <select id="szk-st" name="status" class="form-select form-select-sm"><option value="">— wszystkie —</option>
        <?php foreach (contractStatusOptions($TYPE, null, true) as $k => $v): ?><option value="<?= h($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-auto"><label class="form-label small mb-0" for="szk-fin">Finansowanie</label>
      <select id="szk-fin" name="fin" class="form-select form-select-sm"><option value="">— wszystkie —</option>
        <?php foreach (TiTrainingContract::FUNDING as $k => $v): ?><option value="<?= h($k) ?>" <?= $fin === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-auto"><label class="form-label small mb-0" for="szk-op">Opiekun</label>
      <input id="szk-op" name="opiekun" class="form-control form-control-sm" value="<?= h($opiekun) ?>" style="width:9rem"></div>
    <div class="col-auto"><label class="form-label small mb-0" for="szk-od">Zawarta od</label>
      <input id="szk-od" type="date" name="data_od" class="form-control form-control-sm" value="<?= h($data_od) ?>"></div>
    <div class="col-auto"><label class="form-label small mb-0" for="szk-do">do</label>
      <input id="szk-do" type="date" name="data_do" class="form-control form-control-sm" value="<?= h($data_do) ?>"></div>
    <?php if ($nTests): ?><div class="col-auto"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="szk-t" name="testowe" value="1" <?= $tests ? 'checked' : '' ?>><label class="form-check-label small" for="szk-t">z testowymi (<?= (int)$nTests ?>)</label></div></div><?php endif; ?>
    <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Szukaj</button>
      <?php if ($_qs): ?><a class="btn btn-outline-secondary btn-sm" href="<?= APP_URL ?>/contracts/szk/list.php">Wyczyść</a><?php endif; ?></div>
  </form>
</div></div>

<div class="card shadow-sm"><div class="table-responsive">
<table class="table table-hover contracts-table mb-0 align-middle">
  <caption class="visually-hidden">Umowy szkoleniowe</caption>
  <thead class="table-light"><tr>
    <th scope="col"><?= $sortLink('numer_umowy', 'Numer') ?></th><th scope="col">RU</th>
    <th scope="col"><?= $sortLink('imie_nazwisko', 'Uczestnik') ?></th><th scope="col">Szkolenie</th>
    <th scope="col">Płatnik</th><th scope="col"><?= $sortLink('data_zawarcia', 'Zawarta') ?></th>
    <th scope="col"><?= $sortLink('status', 'Status') ?></th><th scope="col"><span class="visually-hidden">Akcje</span></th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">Brak umów spełniających kryteria.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $_d = json_decode((string)$r['dane'], true) ?: []; $_pl = $r['platnik_nazwa'] !== '' ? $r['platnik_nazwa'] : (string)($_d['platnik'] ?? ''); ?>
    <tr>
      <td class="font-monospace small text-nowrap"><a href="view.php?id=<?= (int)$r['id'] ?>"><?= $r['numer_umowy'] !== '' ? h($r['numer_umowy']) : '<span class="text-muted">projekt</span>' ?></a>
        <?php if ((int)$r['is_test'] === 1): ?><span class="badge text-bg-warning ms-1">test</span><?php endif; ?></td>
      <td class="font-monospace small text-nowrap"><?= h($r['nr_rejestru']) ?></td>
      <td><a href="view.php?id=<?= (int)$r['id'] ?>"><?= h($r['imie_nazwisko'] !== '' ? $r['imie_nazwisko'] : '(bez nazwiska)') ?></a></td>
      <td class="small"><?= h($r['temat']) ?></td>
      <td class="small"><?= h($_pl) ?></td>
      <td class="small text-nowrap"><?= $r['data_zawarcia'] !== '' ? h(date('d.m.Y', strtotime($r['data_zawarcia']))) : '' ?></td>
      <td><?= contractStatusBadge($r['status']) ?></td>
      <td class="text-nowrap text-end">
        <a class="btn btn-outline-secondary btn-sm" href="edit.php?id=<?= (int)$r['id'] ?>&amp;docx=1" aria-label="Pobierz DOCX: <?= h($r['imie_nazwisko']) ?>"><i class="bi bi-file-earmark-word" aria-hidden="true"></i></a>
        <?php if (can_edit()): ?><a class="btn btn-outline-primary btn-sm" href="edit.php?id=<?= (int)$r['id'] ?>" aria-label="Edytuj: <?= h($r['imie_nazwisko']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php if ($pag['pages'] > 1): ?><div class="card-footer"><?= pagination_html($pag) ?></div><?php endif; ?>
</div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
