<?php
/**
 * modules/ti_dop/view/rates.php — raport kierownika „Stawki godzinowe”: godziny rozliczone × kwota na koncie → stawka (zł/h),
 * rozbicie na grupy, porównanie ze stawką zapisu, wpłaty na konto. Zmienne: $rep, $f.
 */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$title = 'Stawki godzinowe — raport kierownika';
include __DIR__ . '/_head.php';
$zl = fn($v) => $v === null ? '—' : number_format((float)$v, 2, ',', ' ') . ' zł';
$rt = fn($v) => $v === null ? '—' : number_format((float)$v, 2, ',', ' ') . ' zł/h';
$hh = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', ' '), '0'), ',') . ' h';
$months = [0 => 'cały rok', 1 => 'styczeń', 2 => 'luty', 3 => 'marzec', 4 => 'kwiecień', 5 => 'maj', 6 => 'czerwiec', 7 => 'lipiec', 8 => 'sierpień', 9 => 'wrzesień', 10 => 'październik', 11 => 'listopad', 12 => 'grudzień'];
$t = $rep['total'];
$qs = array_filter(['_route' => '/stawki', 'rok' => $f['year'], 'mies' => $f['month'] ?: null, 'q' => $f['q'] ?: null, 'roznice' => $f['diff'] ? 1 : null], fn($v) => $v !== null);
?>
<div class="pivot"><a href="<?= h(route('dop.index')) ?>">Dokumenty rozliczeniowe</a><a href="#" aria-current="page">Stawki godzinowe</a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0">Stawki godzinowe na kontach</h1>
  <a class="btn btn-metro dark" href="wnioskiDop.php?<?= h(http_build_query($qs + ['csv' => 1])) ?>"><i class="bi bi-filetype-csv" aria-hidden="true"></i> CSV</a>
</div>
<p class="text-secondary">Stawka = kwota należności na koncie ÷ liczba godzin rozliczonych (rozliczenia wystawione, bez grup „tylko godziny”). Obok stawka zapisu do grupy — różnica oznacza rabat, korektę albo kwotę ręczną. „Wpłaty / h” liczy faktyczne wpływy na konto w okresie.</p>
<form method="get" action="wnioskiDop.php" class="panel row g-2 align-items-end"><input type="hidden" name="_route" value="/stawki">
  <div class="col-md-2"><label class="form-label small" for="rok">Rok (0 = wszystkie)</label><input class="form-control" type="number" id="rok" name="rok" min="0" max="<?= (int)date('Y') + 1 ?>" value="<?= (int)$f['year'] ?>"></div>
  <div class="col-md-2"><label class="form-label small" for="mies">Miesiąc</label><select class="form-select" id="mies" name="mies"><?php foreach ($months as $k => $l): ?><option value="<?= $k ?>"<?= $f['month'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label small" for="q">Uczestnik</label><input class="form-control" id="q" name="q" value="<?= h($f['q']) ?>" placeholder="imię lub nazwisko"></div>
  <div class="col-md-2"><div class="form-check"><input class="form-check-input" type="checkbox" id="rz" name="roznice" value="1"<?= $f['diff'] ? ' checked' : '' ?>><label class="form-check-label small" for="rz">tylko różnice ze stawką zapisu</label></div></div>
  <div class="col-md-2"><button class="btn btn-metro w-100">Pokaż</button></div>
</form>
<div class="tiles">
  <div class="tile" style="background:#1ba1e2"><span class="n"><?= count($rep['rows']) ?></span><span class="l">Kont w raporcie</span></div>
  <div class="tile" style="background:#0050ef"><span class="n" style="font-size:1.5rem"><?= h($hh($t['h'])) ?></span><span class="l">Godziny rozliczone</span></div>
  <div class="tile" style="background:#647687"><span class="n" style="font-size:1.3rem"><?= h($zl($t['due'])) ?></span><span class="l">Należności</span></div>
  <div class="tile wide" style="background:#008a00"><span class="n" style="font-size:1.6rem"><?= h($rt($t['rate'])) ?></span><span class="l">Średnia stawka (należności ÷ godziny) · wpłaty/h <?= h($rt($t['pay_rate'])) ?></span></div>
</div>
<div class="table-responsive panel p-0"><table class="table table-sm table-hover mb-0 align-middle">
  <caption class="visually-hidden">Godziny, kwoty i stawka godzinowa per konto uczestnika</caption>
  <thead><tr><th scope="col">Uczestnik / grupa</th><th scope="col" class="text-end">Godziny</th><th scope="col" class="text-end">Należności</th><th scope="col" class="text-end">Stawka</th><th scope="col" class="text-end">Stawka zapisu</th><th scope="col" class="text-end">Wpłaty na konto</th><th scope="col" class="text-end">Wpłaty / h</th></tr></thead>
  <tbody>
  <?php if (!$rep['rows']): ?><tr><td colspan="7" class="text-secondary p-3">Brak rozliczeń w wybranym okresie.</td></tr><?php endif; ?>
  <?php foreach ($rep['rows'] as $r): ?>
    <tr class="table-light"><th scope="row"><a href="<?= h(route('settle.new', ['kind' => 'RM', 'klient' => $r['client_id']])) ?>"><?= h($r['name']) ?></a><?= $r['diff'] ? ' ' . ldChip('różnica', '#fa6800') : '' ?></th>
      <td class="text-end"><?= h($hh($r['h'])) ?></td><td class="text-end"><?= h($zl($r['due'])) ?></td><td class="text-end fw-bold"><?= h($rt($r['rate'])) ?></td><td></td>
      <td class="text-end"><?= h($zl($r['payments'])) ?></td><td class="text-end"><?= h($rt($r['pay_rate'])) ?></td></tr>
    <?php foreach ($r['groups'] as $g): ?>
    <tr><td class="ps-4 small"><?= h($g['name']) ?> <span class="text-secondary">· <?= (int)$g['months'] ?> mies.<?= $g['manual'] ? ', ' . (int)$g['manual'] . ' z kwotą ręczną' : '' ?></span></td>
      <td class="text-end small"><?= h($hh($g['h'])) ?></td><td class="text-end small"><?= h($zl($g['due'])) ?></td>
      <td class="text-end small<?= $g['diff'] ? ' text-danger fw-bold' : '' ?>"><?= h($rt($g['rate'])) ?></td><td class="text-end small"><?= h($rt($g['enr_rate'])) ?></td><td></td><td></td></tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php include __DIR__ . '/_foot.php'; ?>
