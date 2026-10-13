<?php
/**
 * karty30/ti/dydaktyk/konsultacjePfronDokumenty.php — dokumenty i regulamin PFRON w trybie Konsultacje:
 * stan dokumentów każdej umowy (numer dokumentu, podpis, skany umowy i regulaminu) z wejściem do kreatora
 * dokumentów (karty30/pfron/docs.php — generowanie umowy, regulaminu, podpis, skany) i wydruków (doc_print.php).
 * Kreator działa w SZO — otwiera się w nowej karcie; sama lista i filtry są tu, w panelu.
 */
require __DIR__ . '/_rezerwacje_nav.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consult_pfron/logic/consultPfron.php';
if (!k30_pfron_enabled()) { flash_set('warning', 'Moduł PFRON jest wyłączony w ustawieniach.'); header('Location: rezerwacje.php'); exit; }

$f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => (string)($_GET['status'] ?? ''), 'days' => 0];
$dok = (string)($_GET['dok'] ?? '');   // '' | brak (bez dokumentu) | niepodpisane | podpisane
$rows = array_values(array_filter(TiConsultPfron::list($f), function (array $r) use ($dok): bool {
    $hasDoc = (string)($r['doc_number'] ?? '') !== '';
    $signed = !empty($r['signed_at']) || (string)($r['signed_doc_path'] ?? '') !== '';
    return $dok === '' || ($dok === 'brak' && !$hasDoc) || ($dok === 'niepodpisane' && $hasDoc && !$signed) || ($dok === 'podpisane' && $signed);
}));
$base = rtrim(APP_URL, '/') . '/karty30/pfron/';
$stat = ['brak' => 0, 'niepodpisane' => 0, 'podpisane' => 0];
foreach (TiConsultPfron::list(['q' => '', 'status' => '', 'days' => 0]) as $r) {
    $hasDoc = (string)($r['doc_number'] ?? '') !== ''; $signed = !empty($r['signed_at']) || (string)($r['signed_doc_path'] ?? '') !== '';
    $stat[$signed ? 'podpisane' : ($hasDoc ? 'niepodpisane' : 'brak')]++;
}

$RZ_TITLE = 'Dokumenty i regulamin PFRON'; $RZ_CUR = 'konsultacjePfronDokumenty.php';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1300px">
<div class="d-flex align-items-center gap-2 flex-wrap mb-2">
  <h1 class="h4 fw-bold mb-0"><i class="bi bi-file-earmark-text text-primary me-2" aria-hidden="true"></i>Dokumenty i regulamin PFRON</h1>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($base) ?>doc_print.php?type=regulamin" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Regulamin PFRON (wzór PDF)</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($base) ?>docs.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Kreator dokumentów</a>
  </div>
</div>
<?= flash_html() ?>
<p class="small text-body-secondary mb-3">Umowa i regulamin uczestnictwa (Aktywny Samorząd) powstają w kreatorze dokumentów: numer dokumentu, wydruk PDF, podpis i skany podpisanych egzemplarzy. Kreator otwiera się w nowej karcie (konto pracownika SZO). Tu widzisz stan dokumentów każdej umowy.</p>
<div class="d-flex flex-wrap gap-2 mb-3 small">
  <a class="badge text-bg-light border text-decoration-none" href="konsultacjePfronDokumenty.php?dok=brak">bez dokumentu: <?= (int)$stat['brak'] ?></a>
  <a class="badge text-bg-warning text-decoration-none" href="konsultacjePfronDokumenty.php?dok=niepodpisane">do podpisu: <?= (int)$stat['niepodpisane'] ?></a>
  <a class="badge text-bg-success text-decoration-none" href="konsultacjePfronDokumenty.php?dok=podpisane">podpisane: <?= (int)$stat['podpisane'] ?></a>
</div>
<form method="get" class="row g-2 align-items-end mb-3" role="search" aria-label="Filtry dokumentów PFRON">
  <div class="col-sm-4 col-lg-3"><label class="form-label small mb-0" for="pd-q">Beneficjent lub numer umowy</label><input class="form-control form-control-sm" id="pd-q" name="q" value="<?= h($f['q']) ?>"></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="pd-st">Status umowy</label>
    <select class="form-select form-select-sm" id="pd-st" name="status"><option value="">wszystkie</option><?php foreach (K30_PFRON_CONTRACT_STATUSES as $k => $v): ?><option value="<?= h($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($v['label']) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="pd-d">Dokumenty</label>
    <select class="form-select form-select-sm" id="pd-d" name="dok"><option value="">wszystkie</option><option value="brak"<?= $dok === 'brak' ? ' selected' : '' ?>>bez dokumentu</option><option value="niepodpisane"<?= $dok === 'niepodpisane' ? ' selected' : '' ?>>do podpisu</option><option value="podpisane"<?= $dok === 'podpisane' ? ' selected' : '' ?>>podpisane</option></select></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Pokaż</button> <a class="btn btn-sm btn-link" href="konsultacjePfronDokumenty.php">Wyczyść</a></div>
</form>
<?php if (!$rows): ?><div class="alert alert-secondary">Brak umów PFRON dla wybranych filtrów. <a href="konsultacjePfron.php">Umowy PFRON</a></div><?php else: ?>
<div class="table-responsive"><table class="table table-sm table-hover align-middle">
  <caption class="visually-hidden">Dokumenty umów PFRON</caption>
  <thead class="table-light"><tr><th scope="col">Umowa</th><th scope="col">Beneficjent</th><th scope="col">Nr dokumentu</th><th scope="col">Podpis</th><th scope="col">Skany</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
  <tbody><?php foreach ($rows as $r): $st = K30_PFRON_CONTRACT_STATUSES[$r['status']] ?? ['label' => $r['status'], 'color' => '#555', 'bg' => '#eee']; ?>
    <tr>
      <th scope="row"><a href="konsultacjePfron.php?id=<?= (int)$r['id'] ?>"><?= h($r['contract_number']) ?></a><br><span class="badge" style="background:<?= h($st['bg']) ?>;color:#111827;border:1px solid <?= h($st['color']) ?>"><?= h($st['label']) ?></span></th>
      <td><a href="konsultacjeKlient.php?id=<?= (int)$r['client_id'] ?>"><?= h($r['client_name']) ?></a></td>
      <td class="font-monospace small"><?= h((string)($r['doc_number'] ?? '') ?: '—') ?></td>
      <td class="small"><?= !empty($r['signed_at']) ? '<span class="badge text-bg-success">podpisana ' . h(date('d.m.Y', strtotime((string)$r['signed_at']))) . '</span>' : ((string)($r['doc_number'] ?? '') !== '' ? '<span class="badge text-bg-warning">do podpisu</span>' : '<span class="text-body-secondary">brak dokumentu</span>') ?></td>
      <td class="small"><?= (string)($r['signed_doc_path'] ?? '') !== '' ? '<span class="badge text-bg-light border">umowa</span> ' : '' ?><?= (string)($r['signed_doc2_path'] ?? '') !== '' ? '<span class="badge text-bg-light border">regulamin</span>' : '' ?><?= (string)($r['signed_doc_path'] ?? '') === '' && (string)($r['signed_doc2_path'] ?? '') === '' ? '—' : '' ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-primary py-0" href="<?= h($base) ?>docs.php?pfron_id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener" title="Kreator dokumentów (nowa karta)"><i class="bi bi-pencil-square" aria-hidden="true"></i><span class="visually-hidden">Kreator dokumentów</span></a>
        <?php if ((string)($r['doc_number'] ?? '') !== ''): ?>
        <a class="btn btn-sm btn-outline-secondary py-0" href="<?= h($base) ?>doc_print.php?pfron_id=<?= (int)$r['id'] ?>&amp;type=umowa" target="_blank" rel="noopener" title="Umowa PDF"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i><span class="visually-hidden">Umowa PDF</span></a>
        <a class="btn btn-sm btn-outline-secondary py-0" href="<?= h($base) ?>doc_print.php?pfron_id=<?= (int)$r['id'] ?>&amp;type=regulamin" target="_blank" rel="noopener" title="Regulamin PDF"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><span class="visually-hidden">Regulamin PDF</span></a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
