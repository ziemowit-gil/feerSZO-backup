<?php
/**
 * modules/dictionary/view/show.php — słownik i jego wpisy (drzewo). Zmienne: $dict, $entries ([DictionaryEntry, głębokość]),
 * $byId, $trash, $trashCount, $today, $parentDict, $children, $locales, $canWrite, $canDelete, $isAdmin.
 */
$PAGE_TITLE = $dict->name;
$crumbs = ['Administracja' => APP_URL . '/admin/index.php', 'Słownik Centralny' => route('dict.index')];
if ($parentDict) $crumbs[$parentDict->name] = route('dict.show', ['code' => $parentDict->code]);
$crumbs[$dict->name] = null;
$editable = $canWrite && !$dict->isProtected && !$dict->isDeleted();
$frozen = $dict->kind->codesFrozen();
$csrf = Router::csrfField();
$actions = ($editable ? '<a class="btn btn-sm btn-primary" href="' . h(route('dict.entry.create', ['code' => $dict->code])) . '"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nowy wpis</a>'
                      . '<a class="btn btn-sm btn-outline-secondary" href="' . h(route('dict.edit', ['code' => $dict->code])) . '"><i class="bi bi-pencil" aria-hidden="true"></i> Ustawienia</a>' : '')
         . '<a class="btn btn-sm btn-outline-secondary" href="' . h(route('dict.history', ['code' => $dict->code])) . '"><i class="bi bi-clock-history" aria-hidden="true"></i> Historia</a>'
         . '<div class="btn-group btn-group-sm" role="group" aria-label="Eksport"><a class="btn btn-outline-secondary" href="' . h(route('dict.export', ['code' => $dict->code, 'format' => 'csv'])) . '"><i class="bi bi-filetype-csv" aria-hidden="true"></i> CSV</a>'
         . '<a class="btn btn-outline-secondary" href="' . h(route('dict.export', ['code' => $dict->code, 'format' => 'json'])) . '"><i class="bi bi-filetype-json" aria-hidden="true"></i> JSON</a></div>'
         . ($editable ? '<a class="btn btn-sm btn-outline-secondary" href="' . h(route('dict.import.form', ['to' => $dict->code])) . '"><i class="bi bi-upload" aria-hidden="true"></i> Import</a>' : '');
include __DIR__ . '/_head.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <span class="dict-code"><?= h($dict->code) ?></span>
  <span class="dict-tag"><i class="bi <?= $dict->kind->icon() ?>" aria-hidden="true"></i> <?= h($dict->kind->label()) ?></span>
  <?php if ($dict->isProtected): ?><span class="dict-tag text-danger"><i class="bi bi-lock-fill" aria-hidden="true"></i> Zabezpieczony</span><?php endif; ?>
  <?php if ($dict->isDeleted()): ?><span class="dict-tag text-danger">W koszu</span><?php endif; ?>
</div>
<?php if ($dict->description): ?><p class="mb-2"><?= nl2br(h($dict->description)) ?></p><?php endif; ?>
<?php if ($frozen): ?><p class="small text-body-secondary mb-2"><i class="bi bi-cpu" aria-hidden="true"></i> Słownik systemowy: kody wpisów są używane w kodzie aplikacji — nie zmienisz ich ani nie usuniesz wpisu; możesz zmieniać etykiety, tłumaczenia, atrybuty, kolejność i wyłączać wpisy.</p><?php endif; ?>

<?php if ($dict->isProtected || $isAdmin || $dict->isDeleted()): ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <?php if ($dict->isProtected): ?><span class="small">Słownik jest zabezpieczony przed jakąkolwiek edycją.</span><?php endif; ?>
  <?php if ($isAdmin && !$dict->isDeleted()): ?>
  <form method="post" action="<?= h(route('dict.protect', ['code' => $dict->code])) ?>" class="d-inline"><?= $csrf ?>
    <input type="hidden" name="on" value="<?= $dict->isProtected ? '0' : '1' ?>">
    <button class="btn btn-sm <?= $dict->isProtected ? 'btn-outline-danger' : 'btn-outline-secondary' ?>"><i class="bi <?= $dict->isProtected ? 'bi-unlock' : 'bi-lock' ?>" aria-hidden="true"></i>
      <?= $dict->isProtected ? 'Zdejmij zabezpieczenie' : 'Zabezpiecz przed edycją' ?></button></form>
  <?php endif; ?>
  <?php if ($dict->isDeleted() && $canDelete): ?>
  <form method="post" action="<?= h(route('dict.restore', ['code' => $dict->code])) ?>" class="d-inline"><?= $csrf ?>
    <button class="btn btn-sm btn-success"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Przywróć słownik z kosza</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($children): ?>
<div class="mb-3 small"><strong>Słowniki podrzędne:</strong>
  <?php foreach ($children as $c): ?><a class="dict-tag text-decoration-none me-1" href="<?= h(route('dict.show', ['code' => $c->code])) ?>"><?= h($c->name) ?></a><?php endforeach; ?>
  <?php if ($canWrite): ?><a class="small ms-1" href="<?= h(route('dict.create', ['parent' => $dict->code])) ?>">+ dodaj</a><?php endif; ?>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-2" role="tablist">
  <li class="nav-item"><a class="nav-link <?= $trash ? '' : 'active' ?>" <?= $trash ? '' : 'aria-current="page"' ?> href="<?= h(route('dict.show', ['code' => $dict->code])) ?>">Wpisy (<?= count($byId) - $trashCount ?>)</a></li>
  <li class="nav-item"><a class="nav-link <?= $trash ? 'active' : '' ?>" <?= $trash ? 'aria-current="page"' : '' ?> href="<?= h(route('dict.show', ['code' => $dict->code, 'trash' => 1])) ?>">Kosz (<?= $trashCount ?>)</a></li>
</ul>

<?php if (!empty($treeView['big'])): $tv = $treeView; ?>
<form method="get" action="<?= h(route('dict.show', ['code' => $dict->code])) ?>" class="d-flex flex-wrap align-items-center gap-2 mb-2" role="search"><?= route_field('dict.show', ['code' => $dict->code]) ?>
  <label class="visually-hidden" for="dictTreeQ">Szukaj w słowniku</label>
  <input type="search" name="q" id="dictTreeQ" value="<?= h($tv['q']) ?>" class="form-control form-control-sm" style="max-width:22rem" placeholder="Szukaj po nazwie lub kodzie…">
  <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search" aria-hidden="true"></i> Szukaj</button>
  <span class="small text-body-secondary">
    Duży słownik (<?= (int)$tv['total'] ?> wpisów) — pokazano <?= (int)$tv['shown'] ?>:
    <?= $tv['q'] !== '' ? 'wyniki dla „' . h($tv['q']) . '”' : ($tv['pod'] !== '' ? 'gałąź „' . h($tv['podLabel'] ?: $tv['pod']) . '”' : 'najwyższy poziom') ?>.
    <?php if ($tv['q'] !== '' || $tv['pod'] !== ''): ?><a href="<?= h(route('dict.show', ['code' => $dict->code])) ?>">Najwyższy poziom</a><?php endif; ?>
  </span>
</form>
<?php endif; ?>

<?php if (!$entries): ?>
  <div class="alert alert-light border"><?= $trash ? 'Kosz jest pusty.' : (!empty($treeView['big']) ? 'Brak wpisów pasujących do wyszukiwania.' : 'Słownik nie ma jeszcze wpisów.') ?>
    <?php if (!$trash && $editable): ?><a href="<?= h(route('dict.entry.create', ['code' => $dict->code])) ?>">Dodaj pierwszy wpis</a>.<?php endif; ?></div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
  <caption class="visually-hidden">Wpisy słownika <?= h($dict->name) ?></caption>
  <thead><tr>
    <th scope="col">Etykieta</th><th scope="col">Kod</th><th scope="col">Obowiązuje</th><th scope="col">Atrybuty</th>
    <?php if ($locales): ?><th scope="col">Tłumaczenia</th><?php endif; ?><th scope="col" class="text-end">Akcje</th>
  </tr></thead>
  <tbody>
  <?php foreach ($entries as $_row): [$e, $depth] = $_row; $_hidden = (int)($_row[2] ?? 0);
      $valid = $e->isValidOn($today);
      $muted = !$e->isActive || !$valid || $e->isDeleted(); ?>
    <tr id="e<?= $e->id ?>" class="<?= $muted ? 'dict-muted' : '' ?>">
      <td>
        <span class="dict-tree-pad" style="width:<?= $depth * 1.4 ?>rem"></span>
        <?php if ($depth): ?><i class="bi bi-arrow-return-right text-body-secondary" aria-hidden="true"></i><?php endif; ?>
        <span class="<?= $depth ? '' : 'fw-semibold' ?>"><?= h($e->label) ?></span>
        <?php if ($_hidden && ($treeView['pod'] ?? '') !== $e->code): ?><a class="dict-tag ms-1 text-decoration-none" href="<?= h(route('dict.show', ['code' => $dict->code, 'pod' => $e->code])) ?>"><?= $_hidden ?> podrzędnych →</a><?php endif; ?>
        <?php if (!$e->isActive): ?><span class="dict-tag ms-1">nieaktywny</span><?php endif; ?>
        <?php if ($e->isActive && !$valid): ?><span class="dict-tag ms-1"><?= $e->validFrom !== null && $e->validFrom > $today ? 'jeszcze nie obowiązuje' : 'wygasł' ?></span><?php endif; ?>
        <?php if ($e->description): ?><div class="small text-body-secondary" style="margin-left:<?= $depth * 1.4 ?>rem"><?= h(mb_strimwidth($e->description, 0, 160, '…')) ?></div><?php endif; ?>
      </td>
      <td><span class="dict-code"><?= h($e->code) ?></span></td>
      <td class="small text-nowrap"><?= $e->validFrom || $e->validTo ? h(($e->validFrom ? date('d.m.Y', strtotime($e->validFrom)) : '…') . ' – ' . ($e->validTo ? date('d.m.Y', strtotime($e->validTo)) : '…')) : '<span class="text-body-secondary">bezterminowo</span>' ?></td>
      <td class="small"><?php foreach (array_slice($e->attributes, 0, 3, true) as $ak => $av): ?>
        <div><span class="text-body-secondary"><?= h((string)$ak) ?>:</span> <?= h(mb_strimwidth(is_bool($av) ? ($av ? 'tak' : 'nie') : (is_scalar($av) ? (string)$av : json_encode($av, JSON_UNESCAPED_UNICODE)), 0, 60, '…')) ?></div>
      <?php endforeach; ?><?= count($e->attributes) > 3 ? '<span class="text-body-secondary">+' . (count($e->attributes) - 3) . ' więcej</span>' : '' ?></td>
      <?php if ($locales): ?><td class="small"><?php foreach ($e->labels as $l => $v): ?><div><span class="dict-code"><?= h($l) ?></span> <?= h($v) ?></div><?php endforeach; ?></td><?php endif; ?>
      <td class="text-end text-nowrap">
        <?php if ($e->isDeleted()): ?>
          <?php if ($canDelete && !$dict->isProtected): ?>
          <form method="post" action="<?= h(route('dict.entry.restore', ['code' => $dict->code, 'id' => $e->id])) ?>" class="d-inline"><?= $csrf ?>
            <button class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Przywróć</button></form>
          <?php endif; ?>
        <?php else: ?>
          <?php if ($editable): ?>
          <a class="btn btn-sm btn-outline-primary" href="<?= h(route('dict.entry.edit', ['code' => $dict->code, 'id' => $e->id])) ?>" aria-label="Edytuj wpis <?= h($e->label) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
          <a class="btn btn-sm btn-outline-secondary" href="<?= h(route('dict.entry.create', ['code' => $dict->code, 'parent' => $e->code])) ?>" title="Dodaj wpis podrzędny" aria-label="Dodaj wpis podrzędny do <?= h($e->label) ?>"><i class="bi bi-diagram-2" aria-hidden="true"></i></a>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= h(route('dict.history', ['code' => $dict->code, 'entry' => $e->id])) ?>" aria-label="Historia wpisu <?= h($e->label) ?>"><i class="bi bi-clock-history" aria-hidden="true"></i></a>
          <?php if ($canDelete && !$dict->isProtected && !$frozen): ?>
          <form method="post" action="<?= h(route('dict.entry.destroy', ['code' => $dict->code, 'id' => $e->id])) ?>" class="d-inline"
                onsubmit="return confirm('Przenieść wpis „<?= h(addslashes($e->label)) ?>” do kosza?')"><?= $csrf ?>
            <button class="btn btn-sm btn-outline-danger" aria-label="Usuń wpis <?= h($e->label) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($canDelete && !$dict->isDeleted() && !$dict->isProtected && !$frozen): ?>
<form method="post" action="<?= h(route('dict.destroy', ['code' => $dict->code])) ?>" class="mt-4" onsubmit="return confirm('Przenieść cały słownik do kosza? Moduły, które go używają, wrócą do wartości domyślnych.')"><?= $csrf ?>
  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash" aria-hidden="true"></i> Przenieś słownik do kosza</button>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
