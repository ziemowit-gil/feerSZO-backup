<?php
/**
 * modules/ti_dop/view/index.php — „Wnioski DOP”: kafle stanów, lista wniosków DOP1 / DOP2, nowe wnioski, puste wzory.
 * Zmienne: $list, $f, $counts, $actor, $canApprove.
 */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$title = 'Wnioski DOP — zwrot nadpłaty i płatność z subkonta';
include __DIR__ . '/_head.php';
$u = fn(array $q) => h(route('dop.index', $q));
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0"><span class="sym" style="background:#00aba9">DOP1</span> <span class="sym" style="background:#6a00ff">DOP2</span> Wnioski rozliczeniowe uczestnika</h1>
  <a class="btn btn-metro" style="background:#00aba9" href="<?= h(route('dop.new', ['form' => 'DOP1'])) ?>">Nowy DOP1 — zwrot nadpłaty</a>
  <a class="btn btn-metro" style="background:#6a00ff" href="<?= h(route('dop.new', ['form' => 'DOP2'])) ?>">Nowy DOP2 — płatność z subkonta</a>
</div>
<p class="text-secondary">Wniosek podpisuje wnioskodawca przez link (e-mail + SMS, podpis elektroniczny) albo odręcznie — wtedy wgrywasz skan. Realizacja do <?= LD_REALIZATION_DAYS ?> dni;
  zwrot i płatność trafiają do księgi uczestnika (rejestr nadpłat, subkonto), a dokument końcowy — do teczki „Dokumenty kursanta”.</p>
<nav class="tiles" aria-label="Stany">
  <?php foreach (DopStatus::cases() as $s): if (in_array($s, [DopStatus::Cancelled], true)) continue; ?>
    <a class="tile" style="background:<?= h($s->color()) ?>" href="<?= $u(['status' => $s->value]) ?>"><span class="n"><?= (int)($counts[$s->value] ?? 0) ?></span><span class="l"><?= h($s->label()) ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="row g-2 align-items-end mb-2" method="get" action="wnioskiDop.php" role="search">
  <div class="col-md-5"><label class="form-label small" for="q">Szukaj</label><input class="form-control" id="q" name="q" value="<?= h($f['q']) ?>" placeholder="uczestnik albo numer wniosku"></div>
  <div class="col-md-2"><label class="form-label small" for="fm">Wzór</label><select class="form-select" id="fm" name="form"><option value="">wszystkie</option><?php foreach (DopForm::cases() as $x): ?><option<?= $f['form'] === $x->value ? ' selected' : '' ?>><?= $x->value ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label small" for="st">Stan</label><select class="form-select" id="st" name="status"><option value="">wszystkie</option><?php foreach (DopStatus::cases() as $x): ?><option value="<?= $x->value ?>"<?= $f['status'] === $x->value ? ' selected' : '' ?>><?= h($x->label()) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><button class="btn btn-metro dark w-100">Filtruj</button></div>
</form>
<div class="table-responsive panel p-0"><table class="table table-hover mb-0">
  <thead><tr><th>Numer</th><th>Uczestnik</th><th class="text-end">Kwota</th><th>Złożono</th><th>Termin</th><th>Stan</th></tr></thead><tbody>
  <?php if (!$list): ?><tr><td colspan="6" class="text-secondary p-3">Brak wniosków.</td></tr><?php endif; ?>
  <?php foreach ($list as $r): ?>
    <tr><td class="text-nowrap"><span class="sym" style="background:<?= h($r['formEnum']->color()) ?>"><?= h($r['form']) ?></span> <a href="<?= h(route('dop.show', ['id' => $r['id']])) ?>"><?= h($r['number']) ?></a></td>
      <td><?= h($r['client_name']) ?></td><td class="text-end text-nowrap"><?= h(number_format((float)($r['d']['kwota'] ?? 0), 2, ',', ' ')) ?> zł</td>
      <td class="small text-nowrap"><?= h(ldDt($r['created_at'])) ?></td><td class="small text-nowrap<?= in_array($r['status'], ['done', 'rejected', 'cancelled'], true) || $r['due_on'] >= ldToday() ? '' : ' text-danger fw-semibold' ?>"><?= h(ldDate($r['due_on'])) ?></td>
      <td><?= ldChip($r['statusEnum']->label(), $r['statusEnum']->color()) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<section class="panel"><h2 class="mt-0">Wzory do druku (wersja papierowa)</h2>
  <ul class="list-unstyled mb-0"><?php foreach (DopForm::cases() as $x): ?><li class="mb-1"><span class="sym" style="background:<?= h($x->color()) ?>"><?= $x->value ?></span> <a href="<?= h(route('dop.blank', ['form' => $x->value])) ?>" target="_blank" rel="noopener">Wniosek <?= $x->value ?> – <?= h($x->title()) ?></a></li><?php endforeach; ?></ul>
</section>
<?php include __DIR__ . '/_foot.php'; ?>
