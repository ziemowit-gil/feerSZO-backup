<?php
/**
 * edok/wyplaty.php — zestawienie wypłat z list płac (pozycje umów zlecenie): jedna wypłata = jeden wiersz,
 * z okresem, tytułem przelewu i statusem ręcznej wypłaty. Filtry + eksport CSV (bez PDF listy płac).
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();
// Wynagrodzenia i numery kont zleceniobiorców — tylko role finansowe (nie każdy, kto może dodać dokument).
if (!is_admin() && !edok_has_role('zatwierdza') && !edok_has_role('rachunkowa') && !edok_has_role('ksiegowy') && !edok_has_role('dekretacja')) {
    flash_set('danger', 'Zestawienie wypłat jest dostępne dla osób rozliczających płatności (rachunkowa, dekretacja, zatwierdzanie, księgowy).');
    header('Location: ' . APP_URL . '/edok/index.php');
    exit;
}

$f_od     = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['od'] ?? '')) ? (string)$_GET['od'] : '';
$f_do     = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['do'] ?? '')) ? (string)$_GET['do'] : '';
$f_status = in_array($_GET['status'] ?? '', ['do_wyplaty', 'wyplacone'], true) ? (string)$_GET['status'] : '';
$f_q      = trim((string)($_GET['q'] ?? ''));

// Okres pozycji = jej własny okres, a gdy brak — okres listy płac.
$where  = ["1 = 1"];
$params = [];
if ($f_od !== '') { $where[] = "COALESCE(NULLIF(w.okres_od,''), d.okres) >= ?"; $params[] = $f_od; }
if ($f_do !== '') { $where[] = "COALESCE(NULLIF(w.okres_od,''), d.okres) <= ?"; $params[] = $f_do; }
if ($f_status === 'do_wyplaty') $where[] = "w.paid_at IS NULL";
if ($f_status === 'wyplacone')  $where[] = "w.paid_at IS NOT NULL";
if ($f_q !== '') {
    $where[] = "(w.osoba LIKE ? OR w.umowa_numer LIKE ? OR d.number LIKE ?)";
    $like = '%' . $f_q . '%';
    array_push($params, $like, $like, $like);
}

$rows = db_all(
    "SELECT w.*, d.number AS doc_number, d.okres AS doc_okres, d.status AS doc_status,
            COALESCE(NULLIF(w.okres_od,''), d.okres) AS eff_od
       FROM edok_wyplaty w JOIN edok_documents d ON d.id = w.doc_id
      WHERE " . implode(' AND ', $where) . "
      ORDER BY eff_od DESC, w.osoba, w.id",
    $params
);

$suma = 0.0; $suma_paid = 0.0; $n_paid = 0;
foreach ($rows as &$r) {
    $r['_kwota'] = _edok_kwota_float((string)$r['kwota']);
    $r['_tytul'] = edok_wyplata_tytul(['okres' => $r['doc_okres']], $r);
    $r['_okres'] = edok_okres_label((string)$r['eff_od']);
    if (($r['okres_do'] ?? '') !== '' && $r['okres_do'] !== $r['eff_od']) $r['_okres'] .= '–' . edok_okres_label($r['okres_do']);
    $suma += $r['_kwota'];
    if (!empty($r['paid_at'])) { $suma_paid += $r['_kwota']; $n_paid++; }
}
unset($r);
$suma_do = $suma - $suma_paid;

// ── Eksport CSV (całe zestawienie z filtrami) ─────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="EODoK_wyplaty_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Lista', 'Okres', 'Osoba', 'Umowa nr', 'Rachunek', 'Kwota', 'Tytuł przelewu', 'Status', 'Data wypłaty', 'Uwagi'], ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['doc_number'], $r['_okres'], $r['osoba'], $r['umowa_numer'], edok_nrb_format($r['rachunek']),
            $r['kwota'], $r['_tytul'], !empty($r['paid_at']) ? 'Wypłacono' : 'Do wypłaty',
            !empty($r['paid_at']) ? date('d.m.Y', strtotime($r['paid_at'])) : '', $r['paid_comment'],
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-cash-stack"></i> Zestawienie wypłat (umowy zlecenie)</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/edok/index.php"><i class="bi bi-arrow-left"></i> EODoK</a>
      <a class="btn btn-sm btn-outline-success" href="<?= APP_URL ?>/edok/wyplaty.php?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><i class="bi bi-filetype-csv"></i> Eksport CSV</a>
    </div>
  </div>

  <form method="get" class="card card-body mb-3">
    <div class="row g-2 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="f_od">Okres od</label>
        <input type="month" name="od" id="f_od" class="form-control form-control-sm" value="<?= h($f_od) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="f_do">Okres do</label>
        <input type="month" name="do" id="f_do" class="form-control form-control-sm" value="<?= h($f_do) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="f_status">Status</label>
        <select name="status" id="f_status" class="form-select form-select-sm">
          <option value="">Wszystkie</option>
          <option value="do_wyplaty" <?= $f_status === 'do_wyplaty' ? 'selected' : '' ?>>Do wypłaty</option>
          <option value="wyplacone" <?= $f_status === 'wyplacone' ? 'selected' : '' ?>>Wypłacone</option>
        </select>
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label small mb-1" for="f_q">Osoba, nr umowy lub lista</label>
        <input type="search" name="q" id="f_q" class="form-control form-control-sm" value="<?= h($f_q) ?>">
      </div>
      <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-sm btn-primary w-100">Pokaż</button>
      </div>
    </div>
  </form>

  <div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card card-body py-2"><div class="small text-muted">Pozycje</div><div class="fw-semibold"><?= count($rows) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card card-body py-2"><div class="small text-muted">Razem</div><div class="fw-semibold font-monospace"><?= number_format($suma, 2, ',', ' ') ?> PLN</div></div></div>
    <div class="col-6 col-md-3"><div class="card card-body py-2"><div class="small text-muted">Wypłacone (<?= $n_paid ?>)</div><div class="fw-semibold font-monospace text-success"><?= number_format($suma_paid, 2, ',', ' ') ?> PLN</div></div></div>
    <div class="col-6 col-md-3"><div class="card card-body py-2"><div class="small text-muted">Do wypłaty</div><div class="fw-semibold font-monospace"><?= number_format($suma_do, 2, ',', ' ') ?> PLN</div></div></div>
  </div>

  <div class="card">
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
      <thead class="table-light">
        <tr><th>Lista</th><th>Okres</th><th>Osoba</th><th>Umowa nr</th><th>Rachunek</th><th class="text-end">Kwota</th><th>Tytuł przelewu</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Brak wypłat dla wybranych filtrów.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): $paid = !empty($r['paid_at']); ?>
        <tr<?= $paid ? ' class="text-muted"' : '' ?>>
          <td><a href="<?= APP_URL ?>/edok/view.php?id=<?= (int)$r['doc_id'] ?>#wyplaty"><?= h($r['doc_number']) ?></a></td>
          <td class="text-nowrap"><?= h($r['_okres']) ?></td>
          <td><?= h($r['osoba']) ?></td>
          <td class="font-monospace"><?= h($r['umowa_numer'] ?: '—') ?></td>
          <td class="font-monospace text-nowrap"><?= h(edok_nrb_format($r['rachunek'])) ?></td>
          <td class="text-end font-monospace text-nowrap"><?= h($r['kwota']) ?></td>
          <td class="font-monospace"><span class="tw-break-all"><?= h($r['_tytul']) ?></span></td>
          <td class="text-nowrap">
            <?php if ($paid): ?>
              <span class="badge bg-success">Wypłacono</span> <?= h(date('d.m.Y', strtotime($r['paid_at']))) ?>
            <?php else: ?>
              <span class="badge bg-secondary">Do wypłaty</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
