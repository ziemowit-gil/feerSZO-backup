<?php
/**
 * edok/zaplacone_przed.php — raport odstępstw: dokumenty zapłacone PRZED akceptacją.
 * Każda taka zapłata omija kontrolę wstępną (obieg akceptacji jest zatwierdzany post
 * factum), więc zarząd powinien widzieć, jak często to się dzieje, kto płaci, jaką
 * formą, ile dni mija do akceptacji i czy są dowody zapłaty. Faktury rozliczające
 * proformę NIE są odstępstwem (proforma przeszła obieg przed zapłatą) — wykazywane
 * osobno, informacyjnie. Okres wg daty zapłaty (albo daty dodania, gdy jej brak).
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

if (!is_admin() && !edok_has_role('zatwierdza') && !edok_has_role('ksiegowy')) {
    flash_set('danger', 'Brak dostępu do raportu zapłat przed akceptacją.');
    header('Location: ' . APP_URL . '/edok/index.php');
    exit;
}

$data_od = trim($_GET['data_od'] ?? date('Y-01-01'));
$data_do = trim($_GET['data_do'] ?? date('Y-m-d'));

$rows = db_all(
    "SELECT d.*,
            (SELECT MAX(s.decided_at) FROM edok_steps s WHERE s.doc_id = d.id AND s.step_key = 'zatwierdza' AND s.status = 'ok') AS zatwierdzono_at
       FROM edok_documents d
      WHERE COALESCE(d.zaplacono_przed,0) = 1 AND COALESCE(d.kierunek,'wydatek') = 'wydatek'
        AND d.status NOT IN ('wycofany')
        AND COALESCE(d.data_zaplaty, substr(d.created_at,1,10)) BETWEEN ? AND ?
      ORDER BY COALESCE(d.data_zaplaty, d.created_at) DESC",
    [$data_od, $data_do]
);

$odstepstwa = array_values(array_filter($rows, fn($r) => $r['forma_zaplaty'] !== EDOK_FORMA_PROFORMA));
$z_proform  = count($rows) - count($odstepstwa);

// Mianownik: wszystkie wydatki dodane w okresie — do udziału procentowego.
$wszystkie = (int)(db_one(
    "SELECT COUNT(*) AS n FROM edok_documents WHERE COALESCE(kierunek,'wydatek')='wydatek' AND status NOT IN ('wycofany') AND substr(created_at,1,10) BETWEEN ? AND ?",
    [$data_od, $data_do]
)['n'] ?? 0);

$kw = fn(array $r): float => _edok_kwota_float((string)$r['kwota_brutto']);
$dni = function (array $r): ?int {
    if (!$r['zatwierdzono_at'] || !$r['data_zaplaty']) return null;
    return (int) round((strtotime(substr($r['zatwierdzono_at'], 0, 10)) - strtotime($r['data_zaplaty'])) / 86400);
};

$suma = 0.0; $bez_dowodu = 0; $do_zwrotu = 0; $zwrot_oczek = 0.0; $odrzucone = 0; $lagi = [];
$wg_formy = []; $wg_osoby = []; $wg_miesiaca = [];
foreach ($odstepstwa as $r) {
    $k = $kw($r);
    $suma += $k;
    if (empty($r['dowod_zaplaty_path'])) $bez_dowodu++;
    if ($r['status'] === 'odrzucony') $odrzucone++;
    if (edok_zaplata_do_zwrotu($r)) {
        $do_zwrotu++;
        if (($r['status_platnosci'] ?: 'nowy') !== 'oplacony' && $r['status'] !== 'odrzucony') $zwrot_oczek += $k;
    }
    if (($l = $dni($r)) !== null) $lagi[] = $l;
    $f = EDOK_FORMY_ZAPLATY[$r['forma_zaplaty']] ?? ($r['forma_zaplaty'] ?: '—');
    $wg_formy[$f]['n']   = ($wg_formy[$f]['n'] ?? 0) + 1;
    $wg_formy[$f]['sum'] = ($wg_formy[$f]['sum'] ?? 0) + $k;
    $o = edok_zaplata_do_zwrotu($r) ? ($r['zwrot_osoba'] ?: 'osoba prywatna') . ' (prywatnie)' : 'Organizacja';
    $wg_osoby[$o]['n']   = ($wg_osoby[$o]['n'] ?? 0) + 1;
    $wg_osoby[$o]['sum'] = ($wg_osoby[$o]['sum'] ?? 0) + $k;
    $m = substr((string)($r['data_zaplaty'] ?: $r['created_at']), 0, 7);
    $wg_miesiaca[$m] = ($wg_miesiaca[$m] ?? 0) + 1;
}
arsort($wg_formy); uasort($wg_osoby, fn($a, $b) => $b['n'] <=> $a['n']); ksort($wg_miesiaca);
$sr_lag = $lagi ? array_sum($lagi) / count($lagi) : null;
$udzial = $wszystkie ? count($odstepstwa) / $wszystkie * 100 : 0;
$fmt = fn(float $v) => number_format($v, 2, ',', ' ');

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="EODoK_zaplacone_przed_akceptacja_' . $data_od . '_' . $data_do . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Numer', 'Kontrahent', 'Nr dokumentu', 'Brutto', 'Waluta', 'Data zapłaty', 'Forma', 'Kto zapłacił', 'Dowód zapłaty', 'Status obiegu', 'Zatwierdzono', 'Dni od zapłaty do akceptacji', 'Status płatności'], ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['number'], $r['kontrahent_nazwa'], $r['nr_faktury'], $r['kwota_brutto'], $r['waluta'],
            $r['data_zaplaty'] ?? '', $r['forma_zaplaty'] === EDOK_FORMA_PROFORMA ? 'na podstawie proformy' : (EDOK_FORMY_ZAPLATY[$r['forma_zaplaty']] ?? $r['forma_zaplaty']),
            edok_zaplata_do_zwrotu($r) ? $r['zwrot_osoba'] . ' (do zwrotu)' : 'organizacja',
            $r['forma_zaplaty'] === EDOK_FORMA_PROFORMA ? 'n/d' : (!empty($r['dowod_zaplaty_path']) ? 'tak' : 'brak'),
            edok_status_label($r), $r['zatwierdzono_at'] ?? '', $dni($r) ?? '',
            EDOK_STATUS_PLATNOSCI[$r['status_platnosci'] ?: 'nowy']['label'] ?? $r['status_platnosci'],
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

$PAGE_TITLE = 'Zapłaty przed akceptacją — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-2">
    <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary" aria-label="Wróć"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-cash-coin"></i> Zapłaty przed akceptacją</h4>
  </div>
  <a href="<?= APP_URL ?>/edok/zaplacone_przed.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-success">
    <i class="bi bi-filetype-csv"></i> CSV
  </a>
</div>
<p class="text-muted small">Wydatki zapłacone, zanim przeszły obieg akceptacji — odstępstwo od kontroli wstępnej. Okres wg daty zapłaty. Faktury rozliczające proformę nie są liczone jako odstępstwo (proforma przeszła obieg przed zapłatą).</p>

<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-auto">
    <label class="form-label small mb-1" for="data_od">Od</label>
    <input type="date" name="data_od" id="data_od" class="form-control form-control-sm" value="<?= h($data_od) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1" for="data_do">Do</label>
    <input type="date" name="data_do" id="data_do" class="form-control form-control-sm" value="<?= h($data_do) ?>">
  </div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Pokaż</button></div>
</form>

<div class="d-flex flex-wrap gap-3 mb-3">
  <?php foreach ([
      ['Odstępstwa', count($odstepstwa), sprintf('%s%% wydatków w okresie', number_format($udzial, 1, ',', ''))],
      ['Kwota brutto', $fmt($suma), 'łącznie'],
      ['Bez dowodu zapłaty', $bez_dowodu, $bez_dowodu ? 'do uzupełnienia' : 'komplet'],
      ['Zwroty kosztów', $do_zwrotu, $zwrot_oczek > 0 ? $fmt($zwrot_oczek) . ' czeka na zwrot' : 'rozliczone'],
      ['Średnio dni do akceptacji', $sr_lag !== null ? number_format($sr_lag, 1, ',', '') : '—', 'od zapłaty do zatwierdzenia'],
      ['Odrzucone po zapłacie', $odrzucone, $odrzucone ? 'wymagają wyjaśnienia' : '—'],
  ] as [$label, $val, $sub]): ?>
  <div class="card border-0 shadow-sm px-3 py-2" style="min-width:170px">
    <div class="small text-muted"><?= h($label) ?></div>
    <div class="fs-5 fw-bold font-monospace"><?= h((string)$val) ?></div>
    <div class="small text-muted"><?= h($sub) ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php if ($z_proform): ?>
<p class="small text-muted">Dodatkowo <?= $z_proform ?> faktur rozliczających proformy (informacyjnie, nie są odstępstwem).</p>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card shadow-sm h-100"><div class="card-body">
      <h6 class="card-title">Wg formy zapłaty</h6>
      <table class="table table-sm mb-0"><tbody>
        <?php foreach ($wg_formy as $f => $v): ?>
        <tr><td><?= h($f) ?></td><td class="text-end"><?= $v['n'] ?></td><td class="text-end font-monospace"><?= $fmt($v['sum']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$wg_formy): ?><tr><td class="text-muted">—</td></tr><?php endif; ?>
      </tbody></table>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card shadow-sm h-100"><div class="card-body">
      <h6 class="card-title">Kto zapłacił</h6>
      <table class="table table-sm mb-0"><tbody>
        <?php foreach ($wg_osoby as $o => $v): ?>
        <tr><td><?= h($o) ?></td><td class="text-end"><?= $v['n'] ?></td><td class="text-end font-monospace"><?= $fmt($v['sum']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$wg_osoby): ?><tr><td class="text-muted">—</td></tr><?php endif; ?>
      </tbody></table>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card shadow-sm h-100"><div class="card-body">
      <h6 class="card-title">Wg miesięcy</h6>
      <table class="table table-sm mb-0"><tbody>
        <?php foreach ($wg_miesiaca as $m => $n): ?>
        <tr><td><?= h($m) ?></td><td class="text-end"><?= $n ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$wg_miesiaca): ?><tr><td class="text-muted">—</td></tr><?php endif; ?>
      </tbody></table>
    </div></div>
  </div>
</div>

<div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light">
      <tr><th>Numer</th><th>Kontrahent</th><th class="text-end">Brutto</th><th>Zapłata</th><th>Dowód</th><th>Status</th><th class="text-end">Dni do akceptacji</th></tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Brak zapłat przed akceptacją w tym okresie.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $pf = $r['forma_zaplaty'] === EDOK_FORMA_PROFORMA; $l = $dni($r); ?>
      <tr class="<?= $pf ? 'text-muted' : '' ?>">
        <td><a href="<?= APP_URL ?>/edok/view.php?id=<?= (int)$r['id'] ?>"><code><?= h($r['number']) ?></code></a></td>
        <td><?= h($r['kontrahent_nazwa']) ?></td>
        <td class="text-end font-monospace"><?= h($r['kwota_brutto']) ?> <?= h($r['waluta']) ?></td>
        <td class="small"><?= h(edok_zaplata_opis($r)) ?></td>
        <td class="small">
          <?php if ($pf): ?>—
          <?php elseif (!empty($r['dowod_zaplaty_path'])): ?><a href="<?= APP_URL ?>/edok/file.php?id=<?= (int)$r['id'] ?>&type=dowod" target="_blank"><i class="bi bi-paperclip"></i> jest</a>
          <?php else: ?><span class="badge bg-warning text-dark">brak</span><?php endif; ?>
        </td>
        <td><?= edok_status_badge($r['status'], $r) ?></td>
        <td class="text-end"><?= $l !== null ? $l : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
