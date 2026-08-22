<?php
/**
 * rozliczenia/uczestnicy.php — Uczestnicy i ich salda (wyszukiwarka + filtr zaległości).
 * GET: ?m=YYYY-MM&q=fraza&only=debt|credit
 */
require_once __DIR__ . '/_boot.php';
$rz_redirect = 'uczestnicy.php?m=' . $rz_ym;
require __DIR__ . '/_actions.php';

$q    = trim((string)($_GET['q'] ?? ''));
$only = in_array($_GET['only'] ?? '', ['debt','credit'], true) ? (string)$_GET['only'] : '';

// Kandydaci: aktywni kursanci TI + ci, którzy mają jakiekolwiek rozliczenia
$params = [];
$where  = "(cl.id IN (SELECT client_id FROM k30_ti_enrollments WHERE status='active')
            OR cl.id IN (SELECT client_id FROM k30_ti_billing WHERE status IN ('issued','paid')))";
if ($q !== '') { $where .= " AND cl.name LIKE ?"; $params[] = '%' . $q . '%'; }
$rows = db_all("SELECT cl.id, cl.name FROM k30_clients cl WHERE $where ORDER BY cl.name", $params);

$list = [];
foreach ($rows as $r) {
    $cid = (int)$r['id'];
    $a   = ti_client_allocation($cid);
    if ($only === 'debt'   && $a['debt']   <= 0.005) continue;
    if ($only === 'credit' && $a['credit'] <= 0.005) continue;
    $groups = array_filter(array_keys($a['groups']), fn($g) => $g > 0);
    $list[] = ['client_id'=>$cid, 'name'=>(string)$r['name'], 'charges'=>$a['charges'], 'paid'=>$a['paid'],
               'debt'=>$a['debt'], 'credit'=>$a['credit'], 'groups'=>count($groups)];
}

$PAGE_TITLE = 'Uczestnicy';
$RZ_ACTIVE  = 'uczestnicy';
include __DIR__ . '/_head.php';
?>
<div class="mb-3">
  <h1 class="h4 fw-bold mb-1">Uczestnicy</h1>
  <p class="text-body-secondary small mb-0">Salda liczone bieżąco ze wszystkich grup uczestnika.</p>
</div>

<?= rz_month_bar('uczestnicy.php', $rz_year, $rz_month, $rz_month_label, ($q !== '' ? 'q=' . rawurlencode($q) : '') . ($only ? ($q !== '' ? '&amp;' : '') . 'only=' . $only : '')) ?>
<?= flash_html() ?>

<div class="card mb-3">
  <div class="card-header fw-semibold d-flex align-items-center gap-2"><i class="bi bi-search" aria-hidden="true"></i>Szukaj i filtruj</div>
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="m" value="<?= h($rz_ym) ?>">
      <div class="col-sm-6">
        <label class="form-label small fw-semibold mb-1" for="q">Imię i nazwisko</label>
        <input type="search" name="q" id="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="np. Kowalski">
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1" for="only">Pokaż</label>
        <select name="only" id="only" class="form-select form-select-sm">
          <option value="">wszystkich</option>
          <option value="debt"   <?= $only === 'debt'   ? 'selected' : '' ?>>tylko z niedopłatą</option>
          <option value="credit" <?= $only === 'credit' ? 'selected' : '' ?>>tylko z nadpłatą</option>
        </select>
      </div>
      <div class="col-sm-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel" aria-hidden="true"></i>Filtruj</button></div>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header fw-semibold d-flex align-items-center gap-2"><i class="bi bi-people" aria-hidden="true"></i>Lista
    <span class="sp"></span><span class="badge bg-light text-secondary border"><?= count($list) ?></span></div>
  <?php if (!$list): ?>
  <div class="rz-empty">Brak uczestników spełniających kryteria.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Uczestnicy i ich salda rozliczeń</caption>
      <thead><tr>
        <th scope="col">Uczestnik</th>
        <th scope="col" class="rz-num">Grupy</th>
        <th scope="col" class="rz-num">Należności</th>
        <th scope="col" class="rz-num">Pokryte</th>
        <th scope="col" class="rz-num">Saldo</th>
        <th scope="col" class="rz-num">Akcje</th>
      </tr></thead>
      <tbody>
        <?php foreach ($list as $p): ?>
        <tr>
          <th scope="row" style="font-weight:600">
            <a href="uczestnik.php?client_id=<?= (int)$p['client_id'] ?>&amp;m=<?= h($rz_ym) ?>"><?= h($p['name']) ?></a>
          </th>
          <td class="rz-num"><?= (int)$p['groups'] ?></td>
          <td class="rz-num"><?= h(rz_zl($p['charges'])) ?></td>
          <td class="rz-num"><?= h(rz_zl($p['paid'])) ?></td>
          <td class="rz-num"><?= rz_saldo((float)$p['credit'], (float)$p['debt']) ?></td>
          <td class="rz-num">
            <?php if ($rz_can_write): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="issue">
              <input type="hidden" name="notify" value="1">
              <input type="hidden" name="client_id" value="<?= (int)$p['client_id'] ?>">
              <button class="btn btn-sm btn-outline-secondary" title="Wystaw rozliczenia za <?= h($rz_month_label) ?> i wyślij mail">
                <i class="bi bi-receipt" aria-hidden="true"></i>Wystaw + mail
              </button>
            </form>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
               href="<?= APP_URL ?>/karty30/ti/hours_pdf.php?client_id=<?= (int)$p['client_id'] ?>&amp;month=<?= $rz_month ?>&amp;year=<?= $rz_year ?>">
              <i class="bi bi-clock-history" aria-hidden="true"></i>Rozpiska
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
