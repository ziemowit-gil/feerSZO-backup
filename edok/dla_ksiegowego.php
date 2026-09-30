<?php
/**
 * edok/dla_ksiegowego.php — zestawienie zaakceptowanych wydatków i przychodów do zaksięgowania
 * (PDF i Excel) za wybrany okres.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok_ksiegowy.php';

edok_require_access();
edok_migrate();

$od = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['od'] ?? '') ? $_GET['od'] : date('Y-m-01');
$do = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['do'] ?? '') ? $_GET['do'] : date('Y-m-t');
$zakres = in_array($_GET['zakres'] ?? '', ['wydatki', 'przychody'], true) ? $_GET['zakres'] : 'oba';
$format = $_GET['format'] ?? '';

if (in_array($format, ['pdf', 'xlsx'], true)) {
    $wyd = $zakres !== 'przychody' ? edok_ksiegowy_rows($od, $do, 'wydatek') : [];
    $prz = $zakres !== 'wydatki'   ? edok_ksiegowy_rows($od, $do, 'przychod') : [];
    if (!$wyd && !$prz) {
        flash_set('warning', 'Brak zaakceptowanych dokumentów w wybranym okresie.');
        header('Location: ' . APP_URL . '/edok/dla_ksiegowego.php?' . http_build_query(['od' => $od, 'do' => $do, 'zakres' => $zakres]));
        exit;
    }
    $base = 'EODoK_do_zaksiegowania_' . $od . '_' . $do;
    if ($format === 'pdf') {
        $bin = edok_ksiegowy_pdf($od, $do, $wyd, $prz);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $base . '.pdf"');
    } else {
        $sheets = [];
        if ($zakres !== 'przychody') $sheets['Wydatki']   = edok_ksiegowy_sheet($wyd, true);
        if ($zakres !== 'wydatki')   $sheets['Przychody'] = edok_ksiegowy_sheet($prz, false);
        $bin = edok_xlsx($sheets);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');
    }
    // Zapis eksportu w EODoK (historia poniżej) — z sumą SHA-256.
    edok_export_save('ksiegowy_' . $format, "Do zaksięgowania {$od} – {$do} (" . ($zakres === 'oba' ? 'wydatki i przychody' : $zakres) . ')', $bin, $format);
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

$cnt_w = count(edok_ksiegowy_rows($od, $do, 'wydatek'));
$cnt_p = count(edok_ksiegowy_rows($od, $do, 'przychod'));
$PAGE_TITLE = 'Zestawienie dla księgowego — EODoK';
require_once __DIR__ . '/../includes/header.php';
$q = fn($fmt) => '?' . http_build_query(['od' => $od, 'do' => $do, 'zakres' => $zakres, 'format' => $fmt]);
?>
<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-file-earmark-spreadsheet"></i> Zestawienie dla księgowego</h4>
</div>
<p class="text-muted small">Zaakceptowane (do zapłaty i księgowania) wydatki oraz przychody z wybranego okresu — wg daty wpływu dokumentu. Bez proform i dokumentów testowych.</p>
<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-auto"><label class="form-label small mb-1" for="od">Od</label><input type="date" id="od" name="od" value="<?= h($od) ?>" class="form-control form-control-sm"></div>
  <div class="col-auto"><label class="form-label small mb-1" for="do">Do</label><input type="date" id="do" name="do" value="<?= h($do) ?>" class="form-control form-control-sm"></div>
  <div class="col-auto"><label class="form-label small mb-1" for="zakres">Zakres</label>
    <select id="zakres" name="zakres" class="form-select form-select-sm">
      <option value="oba" <?= $zakres === 'oba' ? 'selected' : '' ?>>Wydatki i przychody</option>
      <option value="wydatki" <?= $zakres === 'wydatki' ? 'selected' : '' ?>>Tylko wydatki</option>
      <option value="przychody" <?= $zakres === 'przychody' ? 'selected' : '' ?>>Tylko przychody</option></select></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Pokaż</button></div>
</form>
<div class="card shadow-sm" style="max-width:560px"><div class="card-body">
  <p class="mb-2">W okresie: <strong><?= $cnt_w ?></strong> wydatków, <strong><?= $cnt_p ?></strong> przychodów.</p>
  <a href="<?= h($q('pdf')) ?>" class="btn btn-danger"><i class="bi bi-file-earmark-pdf"></i> Pobierz PDF</a>
  <a href="<?= h($q('xlsx')) ?>" class="btn btn-success"><i class="bi bi-file-earmark-excel"></i> Pobierz Excel</a>
</div></div>
<?php $hist = db_all("SELECT * FROM edok_exports ORDER BY id DESC LIMIT 30"); if ($hist): ?>
<h6 class="mt-4">Zapisane eksporty</h6>
<div class="table-responsive"><table class="table table-sm small">
  <thead><tr><th>Kiedy</th><th>Eksport</th><th>Kto</th><th class="text-end">Rozmiar</th><th>SHA-256</th></tr></thead><tbody>
  <?php foreach ($hist as $e): ?>
  <tr><td class="text-nowrap"><?= h(date_pl($e['created_at'])) ?> <?= h(date('H:i', strtotime($e['created_at']))) ?></td>
    <td><a href="<?= APP_URL ?>/edok/export_file.php?id=<?= (int)$e['id'] ?>"><?= h($e['label']) ?></a> <span class="badge text-bg-light"><?= h(strtoupper(pathinfo($e['file_path'], PATHINFO_EXTENSION))) ?></span></td>
    <td><?= h($e['creator_name']) ?></td><td class="text-end"><?= h(number_format($e['file_size'] / 1024, 0, ',', ' ')) ?> KB</td>
    <td class="font-monospace text-muted"><?= h(substr($e['file_sha256'], 0, 12)) ?>…</td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
