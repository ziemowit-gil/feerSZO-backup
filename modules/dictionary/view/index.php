<?php
/**
 * modules/dictionary/view/index.php — drzewo słowników. Zmienne: $tree ([Dictionary, głębokość]), $counts, $q, $trash,
 * $canWrite, $canDelete, $isAdmin.
 */
$PAGE_TITLE = 'Słownik Centralny';
$crumbs = ['Administracja' => APP_URL . '/admin/index.php', 'Słownik Centralny' => null];
$actions = ($canWrite ? '<a class="btn btn-sm btn-primary" href="' . h(route('dict.create')) . '"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nowy słownik</a>'
                      . '<a class="btn btn-sm btn-outline-secondary" href="' . h(route('dict.import.form')) . '"><i class="bi bi-upload" aria-hidden="true"></i> Import</a>' : '')
         . '<a class="btn btn-sm btn-outline-secondary" href="' . h(route('dict.export.all')) . '"><i class="bi bi-download" aria-hidden="true"></i> Eksport wszystkich (JSON)</a>';
include __DIR__ . '/_head.php';

$byKind = [];
$entriesTotal = 0;
foreach ($tree as [$d]) { $byKind[$d->kind->value] = ($byKind[$d->kind->value] ?? 0) + 1; $entriesTotal += $counts[$d->id] ?? 0; }
?>
<p class="text-body-secondary small mb-3">Jedno miejsce na listy wyboru całego systemu: słowniki są drzewem kategorii, wpisy mają okres obowiązywania,
  tłumaczenia i atrybuty JSON, a każda zmiana trafia do historii. Moduły czytają je przez <code>dictOptions('kod')</code> (z cache).</p>

<?php if (!$trash && $q === ''): ?>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="dict-tile"><div class="n"><?= count($tree) ?></div><div class="small">słowników</div></div></div>
  <div class="col-6 col-md-3"><div class="dict-tile"><div class="n"><?= $entriesTotal ?></div><div class="small">wpisów</div></div></div>
  <?php foreach (['system', 'business'] as $k): $kind = DictionaryKind::from($k); ?>
  <div class="col-6 col-md-3"><div class="dict-tile"><div class="n"><?= (int)($byKind[$k] ?? 0) ?></div><div class="small"><i class="bi <?= $kind->icon() ?>" aria-hidden="true"></i> <?= h(mb_strtolower($kind->label())) ?>ch</div></div></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="get" action="<?= h(route('dict.index')) ?>" class="row g-2 align-items-end mb-3" role="search">
  <div class="col-sm-6"><label class="form-label small" for="dict-q">Szukaj słownika (nazwa, kod, opis)</label>
    <input id="dict-q" class="form-control" name="q" value="<?= h($q) ?>" placeholder="np. instytucje"></div>
  <div class="col-sm-3 form-check ms-2 mb-2">
    <input class="form-check-input" type="checkbox" id="dict-trash" name="trash" value="1" <?= $trash ? 'checked' : '' ?>>
    <label class="form-check-label small" for="dict-trash">pokaż także kosz</label></div>
  <div class="col-sm-2"><button class="btn btn-outline-primary w-100"><i class="bi bi-search" aria-hidden="true"></i> Szukaj</button></div>
</form>

<?php if (!$tree): ?>
  <div class="alert alert-light border">Brak słowników<?= $q !== '' ? ' pasujących do „' . h($q) . '”' : '' ?>.
    <?php if ($canWrite && $q === ''): ?><a href="<?= h(route('dict.create')) ?>">Załóż pierwszy</a> albo <a href="<?= h(route('dict.import.form')) ?>">zaimportuj z pliku</a>.<?php endif; ?></div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover align-middle">
  <caption class="visually-hidden">Słowniki w układzie drzewa kategorii</caption>
  <thead><tr><th scope="col">Słownik</th><th scope="col">Kod</th><th scope="col">Rodzaj</th><th scope="col" class="text-end">Wpisy</th><th scope="col">Zmieniono</th></tr></thead>
  <tbody>
  <?php foreach ($tree as [$d, $depth]): ?>
    <tr class="<?= $d->isDeleted() ? 'dict-muted' : '' ?>">
      <td>
        <span class="dict-tree-pad" style="width:<?= $depth * 1.4 ?>rem"></span>
        <?php if ($depth): ?><i class="bi bi-arrow-return-right text-body-secondary" aria-hidden="true"></i><?php endif; ?>
        <a class="fw-semibold" href="<?= h(route('dict.show', ['code' => $d->code])) ?>"><?= h($d->name) ?></a>
        <?php if ($d->isProtected): ?><i class="bi bi-lock-fill text-danger ms-1" title="Zabezpieczony przed edycją"></i><span class="visually-hidden">(zabezpieczony)</span><?php endif; ?>
        <?php if ($d->isDeleted()): ?><span class="dict-tag text-danger ms-1">w koszu</span><?php endif; ?>
        <?php if ($d->description): ?><div class="small text-body-secondary" style="margin-left:<?= $depth * 1.4 + ($depth ? 1.1 : 0) ?>rem"><?= h(mb_strimwidth($d->description, 0, 140, '…')) ?></div><?php endif; ?>
      </td>
      <td><span class="dict-code"><?= h($d->code) ?></span></td>
      <td><span class="dict-tag"><i class="bi <?= $d->kind->icon() ?>" aria-hidden="true"></i> <?= h($d->kind->label()) ?></span></td>
      <td class="text-end"><?= (int)($counts[$d->id] ?? 0) ?></td>
      <td class="small text-body-secondary"><?= h($d->updatedAt ? date('d.m.Y H:i', strtotime($d->updatedAt)) : '') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<details class="mt-4 small">
  <summary class="fw-semibold">Jak użyć słownika w module</summary>
  <pre class="mt-2 p-2 bg-light border"><code>require_once ROOT . '/modules/dictionary/lib/dictionaryApi.php';
$opcje  = dictOptions('kod_slownika', STARA_STALA);         // ['kod' =&gt; 'Etykieta'] — aktywne, obowiązujące dziś
$wpisy  = dictItems('kod_slownika');                        // + atrybuty JSON, rodzic, głębokość
$nazwa  = dictLabel('kod_slownika', $row['pole'], $row['pole']);
if (!dictAllowed('kod_slownika', $_POST['pole'], array_keys(STARA_STALA))) { /* błąd walidacji */ }
// JS: fetch('<?= h(route('dict.api.options', ['code' => 'kod_slownika'])) ?>')</code></pre>
  <p class="mb-0">Pełne wytyczne: <code>modules/dictionary/README.md</code>.</p>
</details>
<?php include __DIR__ . '/_foot.php'; ?>
