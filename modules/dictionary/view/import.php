<?php
/**
 * modules/dictionary/view/import.php — import CSV/JSON z próbą na sucho.
 * Zmienne: $dicts ([Dictionary, głębokość]), $target, $result (statystyki per słownik albo null), $error (opcjonalnie), $dryRun.
 */
$PAGE_TITLE = 'Import słowników';
$crumbs = ['Administracja' => APP_URL . '/admin/index.php', 'Słownik Centralny' => route('dict.index'), 'Import' => null];
include __DIR__ . '/_head.php';
?>
<?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>

<?php if ($result !== null): ?>
<section class="mb-4" aria-labelledby="imp-res">
  <h2 id="imp-res" class="h5 fw-light"><?= $dryRun ? 'Wynik próby (nic nie zapisano)' : 'Wynik importu' ?></h2>
  <?php if (!$result): ?><div class="alert alert-light border">Plik nie zawierał słowników.</div><?php endif; ?>
  <?php foreach ($result as $s): ?>
  <div class="border-start border-4 ps-3 py-2 mb-2 <?= $s['errors'] ? 'border-warning' : 'border-success' ?>">
    <strong><?= h($s['dictionary']) ?></strong> —
    nowe: <?= (int)$s['created'] ?>, zmienione: <?= (int)$s['updated'] ?>, bez zmian: <?= (int)$s['unchanged'] ?>, błędy: <?= count($s['errors']) ?>
    <?php if (!$dryRun && $s['dictionary'] !== '' && $s['dictionary'] !== '?'): ?> · <a href="<?= h(route('dict.show', ['code' => $s['dictionary']])) ?>">otwórz słownik</a><?php endif; ?>
    <?php if ($s['errors']): ?><ul class="small mb-0 mt-1"><?php foreach ($s['errors'] as $line => $msg): ?><li><?= $line ? 'wiersz ' . (int)$line . ': ' : '' ?><?= h($msg) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" action="<?= h(route('dict.import')) ?>" class="row g-3" style="max-width:48rem">
  <?= Router::csrfField() ?>
  <div class="col-12">
    <label class="form-label" for="imp-file">Plik (.json albo .csv, do 5 MB)</label>
    <input id="imp-file" type="file" name="file" class="form-control" accept=".json,.csv,.txt,application/json,text/csv" required>
  </div>
  <div class="col-12">
    <label class="form-label" for="imp-target">Słownik docelowy dla CSV</label>
    <select id="imp-target" name="target" class="form-select">
      <option value="">— dla JSON słowniki są w pliku —</option>
      <?php foreach ($dicts as [$d, $depth]): ?>
        <option value="<?= h($d->code) ?>" <?= $target === $d->code ? 'selected' : '' ?> <?= $d->isProtected ? 'disabled' : '' ?>><?= str_repeat("\u{00A0}\u{00A0}\u{00A0}", $depth) . h($d->name) ?><?= $d->isProtected ? ' (zabezpieczony)' : '' ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-12 form-check ms-2">
    <input class="form-check-input" type="checkbox" id="imp-dry" name="dry_run" value="1" <?= $dryRun ? 'checked' : '' ?>>
    <label class="form-check-label" for="imp-dry">Tylko próba — pokaż, co się zmieni, bez zapisu</label>
  </div>
  <div class="col-12"><button class="btn btn-primary"><i class="bi bi-upload" aria-hidden="true"></i> Importuj</button></div>
</form>

<details class="mt-4 small">
  <summary class="fw-semibold">Format plików</summary>
  <p class="mt-2 mb-1"><strong>CSV</strong> (jeden słownik; separator <code>;</code>, <code>,</code> albo tabulator; UTF-8). Pierwszy wiersz to nagłówki:</p>
  <pre class="p-2 bg-light border"><code>code;label;parent_code;sort_order;is_active;valid_from;valid_to;description;attributes;label_en
um_maz;Urząd Marszałkowski Województwa Mazowieckiego;samorzad_wojewodztwa;7;1;;;;"{""adres"": ""ul. Jagiellońska 26, 03-719 Warszawa""}";Marshal Office</code></pre>
  <p class="mb-1"><strong>JSON</strong> — taki jak z eksportu (<code>{"format":"szo-dictionary","dictionaries":[…]}</code>); zakłada brakujące słowniki.</p>
  <p class="mb-0">Import dopasowuje wpisy po kodzie: nowe dodaje, istniejące aktualizuje, wpisów spoza pliku nie rusza. Słowników zabezpieczonych nie zmienia.</p>
</details>
<?php include __DIR__ . '/_foot.php'; ?>
