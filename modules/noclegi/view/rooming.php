<?php
/** Rooming: zajętość pokoi obiektu w oknie dat, lista dla obiektu (PDF/Excel), niezakwaterowani. Zmienne: $facs, $fid, $from, $to, $groups, $unassigned, $role. */
$PAGE_TITLE = 'Rooming'; $active = 'rooming';
include __DIR__ . '/_head.php'; include __DIR__ . '/_nav.php';
$d = fn($x) => date('d.m.Y', strtotime($x));
$q = ['obiekt' => $fid, 'od' => $from, 'do' => $to];
?>
<h1>Rooming</h1>
<p class="ncl-lead">Kto z kim mieszka w danym obiekcie i terminie. Przydziału dokonujesz na karcie zgłoszenia. Lista dla obiektu (PDF / Excel) nie zawiera kodów dostępu ani opisów potrzeb zdrowotnych.</p>
<?php if (!$facs): ?><div class="alert alert-info">Najpierw <a href="<?= h(route('nocl.facility.new')) ?>">dodaj obiekt</a>.</div><?php else: ?>
<form method="get" action="<?= h(route('nocl.rooming')) ?>" class="row g-2 align-items-end mb-3" aria-label="Wybór obiektu i okresu">
  <?= route_field('nocl.rooming') ?>
  <div class="col-md-4"><label class="form-label" for="obiekt">Obiekt</label><select class="form-select" id="obiekt" name="obiekt"><?php foreach ($facs as $f): ?><option value="<?= (int)$f['id'] ?>" <?= (int)$f['id'] === $fid ? 'selected' : '' ?>><?= h($f['name'] . ($f['city'] ? ', ' . $f['city'] : '')) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-3 col-md-2"><label class="form-label" for="od">Od</label><input class="form-control" type="date" id="od" name="od" value="<?= h($from) ?>"></div>
  <div class="col-sm-3 col-md-2"><label class="form-label" for="do">Do</label><input class="form-control" type="date" id="do" name="do" value="<?= h($to) ?>"></div>
  <div class="col-auto"><button class="btn btn-primary">Pokaż</button></div>
</form>
<p class="d-flex flex-wrap gap-2">
  <a class="btn btn-outline-primary" href="<?= h(route('nocl.rooming.pdf') . (str_contains(route('nocl.rooming.pdf'), '?') ? '&' : '?') . http_build_query($q)) ?>" target="_blank" rel="noopener">Rooming List — PDF</a>
  <a class="btn btn-outline-primary" href="<?= h(route('nocl.rooming.xlsx') . (str_contains(route('nocl.rooming.xlsx'), '?') ? '&' : '?') . http_build_query($q)) ?>">Rooming List — Excel</a>
</p>
<?php if (!$groups): ?><p class="ncl-muted">Obiekt nie ma aktywnych pokoi.</p><?php endif; ?>
<?php foreach ($groups as $g): $rm = $g['room']; ?>
<section class="ncl-room" aria-label="Pokój <?= h($rm['name']) ?>">
  <header><span>Pokój <?= h($rm['name']) ?> · <?= h(noclTypyPokoi()[$rm['room_type']] ?? $rm['room_type']) ?> · <?= (int)$rm['beds'] ?> m.<?= $rm['accessible'] ? ' · dostępny' : '' ?></span>
    <span class="ncl-muted"><?= count($g['people']) ?> os. w okresie</span></header>
  <?php if ($g['people']): ?><ul><?php foreach ($g['people'] as $p): ?>
    <li><a href="<?= h(route('nocl.show', ['id' => $p['id']])) ?>"><?= h($p['person_name']) ?></a> — <?= h($d($p['date_from'])) ?> → <?= h($d($p['date_to'])) ?> <span class="badge <?= h(noclStatusBadge($p['status'])) ?>"><?= h(noclStatusLabel($p['status'])) ?></span><?= $p['roommate_pref'] !== '' ? ' <small class="ncl-muted">(chce z: ' . h($p['roommate_pref']) . ')</small>' : '' ?></li>
  <?php endforeach; ?></ul><?php else: ?><p class="ncl-muted m-2">Wolny w całym okresie.</p><?php endif; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>
<h2>Do zakwaterowania w tym okresie</h2>
<?php if (!$unassigned): ?><p class="ncl-muted">Wszystkie otwarte zgłoszenia w tym okresie mają pokój.</p><?php else: ?>
<div class="ncl-scroll"><table class="ncl-table"><caption>Zgłoszenia bez pokoju</caption>
  <thead><tr><th scope="col">Nr</th><th scope="col">Osoba</th><th scope="col">Termin</th><th scope="col">Wymagania</th><th scope="col">Status</th></tr></thead><tbody>
  <?php foreach ($unassigned as $u): ?><tr>
    <td><a href="<?= h(route('nocl.show', ['id' => $u['id']])) ?>">#<?= (int)$u['id'] ?></a></td><td><?= h($u['person_name']) ?></td><td><?= h($d($u['date_from'])) ?> – <?= h($d($u['date_to'])) ?></td>
    <td><?= $u['needs_accessible'] ? 'pokój dostępny' : '' ?><?= $u['room_type_pref'] ? ' ' . h(noclTypyPokoi()[$u['room_type_pref']] ?? '') : '' ?></td>
    <td><span class="badge <?= h(noclStatusBadge($u['status'])) ?>"><?= h(noclStatusLabel($u['status'])) ?></span></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
