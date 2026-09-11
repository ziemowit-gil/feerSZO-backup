<?php
/**
 * edok/preliminarz.php — Preliminarz Płatności (przeniesiony z KDOK).
 * Ujednolicony: pokazuje zaakceptowane dokumenty z EODoK ORAZ archiwalnego
 * KDOK (edok_preliminarz_query() w includes/edok.php) — jedna lista „co trzeba
 * zapłacić" niezależnie od tego, w którym module dokument powstał.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

if (!is_admin() && !edok_has_role('zatwierdza') && !(function_exists('kdok_has_role') && kdok_has_role('zatwierdza'))) {
    flash_set('danger', 'Brak dostępu do Preliminarza Płatności.');
    header('Location: ' . APP_URL . '/edok/index.php');
    exit;
}

$f_termin_od = trim($_GET['termin_od'] ?? '');
$f_termin_do = trim($_GET['termin_do'] ?? date('Y-m-d', strtotime('+30 days')));
$f_status    = trim($_GET['status'] ?? '');
$f_waluta    = trim($_GET['waluta'] ?? '');
$f_mpp       = !empty($_GET['mpp']);
$f_q         = trim($_GET['q'] ?? '');

$filters = array_filter([
    'termin_od'        => $f_termin_od,
    'termin_do'        => $f_termin_do ?: null,
    'status_platnosci' => $f_status,
    'waluta'           => $f_waluta,
    'mpp'              => $f_mpp ?: null,
    'q'                => $f_q,
]);

$rows = edok_preliminarz_query($filters);

if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="EODoK_Preliminarz_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Źródło','Numer','Tytuł','Tytuł przelewu','Kontrahent','NIP','Nr rachunku','Netto','VAT','Brutto','Waluta','Termin płatności','Klasyfikacja','Status płatności','MPP'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            strtoupper($r['source']), $r['number'], $r['title'], $r['tytul_przelewu'] ?? '', $r['kontrahent'], $r['nip'], $r['rachunek_bankowy'],
            $r['kwota_netto'], $r['kwota_vat'], $r['kwota_brutto'], $r['waluta'],
            $r['termin_platnosci'] ? substr($r['termin_platnosci'], 0, 10) : '',
            $r['klasyfikacja'], $r['status_platnosci'], !empty($r['wymaga_mpp']) ? 'MPP' : '',
        ], ';');
    }
    fclose($out);
    exit;
}

$sumy = [];
foreach ($rows as $r) {
    $w = $r['waluta'] ?: 'PLN';
    $b = (float) str_replace([' ', ','], ['', '.'], $r['kwota_brutto'] ?: '0');
    $v = (float) str_replace([' ', ','], ['', '.'], $r['kwota_vat'] ?: '0');
    $sumy[$w]['brutto'] = ($sumy[$w]['brutto'] ?? 0) + $b;
    $sumy[$w]['vat']    = ($sumy[$w]['vat']    ?? 0) + $v;
}

$PAGE_TITLE = 'Preliminarz Płatności — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-calendar-check"></i> Preliminarz Płatności</h4>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/edok/preliminarz.php?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn btn-sm btn-outline-success">
      <i class="bi bi-filetype-csv"></i> CSV
    </a>
    <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> EODoK</a>
  </div>
</div>
<p class="text-muted small">Zaakceptowane dokumenty z EODoK oraz archiwalnego KDOK — jedna lista płatności niezależnie od modułu pochodzenia.</p>

<?= flash_html() ?>

<form method="get" class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-sm-auto">
        <label class="form-label mb-1 small fw-semibold">Termin płatności</label>
        <div class="d-flex gap-1 align-items-center">
          <input type="date" name="termin_od" class="form-control form-control-sm" value="<?= h($f_termin_od) ?>">
          <span class="text-muted">–</span>
          <input type="date" name="termin_do" class="form-control form-control-sm" value="<?= h($f_termin_do) ?>">
        </div>
      </div>
      <div class="col-sm-2">
        <label class="form-label mb-1 small fw-semibold">Status płatności</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">Wszystkie</option>
          <?php foreach (EDOK_STATUS_PLATNOSCI as $k => $s): ?>
          <option value="<?= h($k) ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= h($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-1">
        <label class="form-label mb-1 small fw-semibold">Waluta</label>
        <select name="waluta" class="form-select form-select-sm">
          <option value="">Wszystkie</option>
          <?php foreach (['PLN','EUR','USD','CHF','GBP'] as $w): ?>
          <option value="<?= $w ?>" <?= $f_waluta === $w ? 'selected' : '' ?>><?= $w ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label mb-1 small fw-semibold">Szukaj</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="NIP / nr faktury / tytuł" value="<?= h($f_q) ?>">
      </div>
      <div class="col-auto">
        <div class="form-check form-check-inline mt-3">
          <input class="form-check-input" type="checkbox" name="mpp" id="f-mpp" value="1" <?= $f_mpp ? 'checked' : '' ?>>
          <label class="form-check-label small" for="f-mpp">Tylko MPP</label>
        </div>
      </div>
      <div class="col-auto d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
        <a href="<?= APP_URL ?>/edok/preliminarz.php" class="btn btn-outline-secondary btn-sm">Wyczyść</a>
      </div>
    </div>
  </div>
</form>

<?php if ($sumy): ?>
<div class="d-flex flex-wrap gap-3 mb-3">
  <?php foreach ($sumy as $w => $s): ?>
  <div class="card border-0 shadow-sm px-3 py-2 text-center" style="min-width:160px">
    <div class="small text-muted">Łącznie brutto <strong><?= h($w) ?></strong></div>
    <div class="fw-bold fs-6 font-monospace"><?= number_format($s['brutto'], 2, ',', ' ') ?></div>
  </div>
  <?php endforeach; ?>
  <div class="card border-0 shadow-sm px-3 py-2 text-center" style="min-width:120px">
    <div class="small text-muted">Liczba pozycji</div>
    <div class="fw-bold fs-6"><?= count($rows) ?></div>
  </div>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
<div class="text-center text-muted py-5">
  <i class="bi bi-check-circle display-4 d-block mb-2"></i>
  Brak dokumentów spełniających kryteria.
</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle" style="font-size:.83rem">
      <thead class="table-dark">
        <tr>
          <th>Źródło</th>
          <th>Numer</th>
          <th>Kontrahent / NIP</th>
          <th>Tytuł przelewu</th>
          <th class="text-end">Brutto</th>
          <th>Termin</th>
          <th class="text-center">Dni</th>
          <th class="text-center">MPP</th>
          <th>Klasyfikacja</th>
          <th>Status płatności</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
        $p      = (int)($r['priorytet'] ?? 5);
        $termin = $r['termin_platnosci'] ?? '';
        $diff   = $termin ? (int)round((strtotime($termin) - strtotime(date('Y-m-d'))) / 86400) : null;
        $rowClass = match(true) { $p === 1 => 'table-danger', $p === 2 => 'table-warning', default => '' };
        ?>
        <tr class="<?= $rowClass ?>">
          <td><span class="badge bg-<?= $r['source'] === 'edok' ? 'primary' : 'secondary' ?>"><?= strtoupper($r['source']) ?></span></td>
          <td><a href="<?= h($r['view_url']) ?>"><code><?= h($r['number']) ?></code></a></td>
          <td>
            <div><?= h($r['kontrahent'] ?: '—') ?></div>
            <?php if ($r['nip']): ?><div class="text-muted small font-monospace"><?= h($r['nip']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if (!empty($r['tytul_przelewu'])): ?>
            <span class="font-monospace" style="font-size:.78rem"><?= h($r['tytul_przelewu']) ?></span>
            <button type="button" class="btn btn-sm btn-link p-0 ms-1" title="Kopiuj tytuł przelewu"
              onclick="navigator.clipboard.writeText(<?= json_encode($r['tytul_przelewu'], JSON_UNESCAPED_UNICODE) ?>)">
              <i class="bi bi-clipboard"></i>
            </button>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-end font-monospace fw-semibold"><?= $r['kwota_brutto'] ? h($r['kwota_brutto']) : '—' ?> <?= h($r['waluta']) ?></td>
          <td><?php if ($termin): ?><span class="<?= $p <= 2 ? 'fw-semibold' : '' ?>"><?= h(substr($termin, 0, 10)) ?></span><?php else: ?>—<?php endif; ?></td>
          <td class="text-center">
            <?php if ($diff !== null): ?>
            <span class="badge <?= $diff < 0 ? 'bg-danger' : ($diff <= 3 ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= $diff < 0 ? abs($diff) . ' po' : $diff ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-center"><?= !empty($r['wymaga_mpp']) ? '<i class="bi bi-exclamation-triangle-fill text-warning" title="MPP"></i>' : '—' ?></td>
          <td class="small"><?= h($r['klasyfikacja'] ?: '—') ?></td>
          <td>
            <form method="post" action="<?= APP_URL ?>/edok/preliminarz_action.php" class="d-flex gap-1 align-items-center">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="source" value="<?= h($r['source']) ?>">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <select name="status" class="form-select form-select-sm" style="width:auto" onchange="this.form.requestSubmit()">
                <?php foreach (EDOK_STATUS_PLATNOSCI as $k => $s): ?>
                <option value="<?= h($k) ?>" <?= $r['status_platnosci'] === $k ? 'selected' : '' ?>><?= h($s['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
