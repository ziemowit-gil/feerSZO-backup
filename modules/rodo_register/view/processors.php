<?php
/** Podmioty przetwarzające (art. 28). Zmienne: $rows. */
$PAGE_TITLE = 'Podmioty przetwarzające'; $active = 'rodo.processors';
include __DIR__ . '/_head.php';
$stat = ['brak' => 'brak umowy', 'w_toku' => 'w toku', 'podpisana' => 'podpisana', 'nie_dotyczy' => 'nie dotyczy (odrębny administrator)'];
$row = function (array $p) use ($stat) {
    $id = (int)($p['id'] ?? 0); ?>
  <tr><form method="post" action="<?= h(route('rodo.processor.save', ['id' => $id])) ?>"><?= csrf_field() ?>
    <td><input class="form-control form-control-sm" name="nazwa" value="<?= h($p['nazwa'] ?? '') ?>" aria-label="Nazwa podmiotu" required></td>
    <td><input class="form-control form-control-sm" name="zakres" value="<?= h($p['zakres'] ?? '') ?>" aria-label="Zakres powierzenia"></td>
    <td><input class="form-control form-control-sm" name="kraj" value="<?= h($p['kraj'] ?? 'PL/EOG') ?>" aria-label="Kraj przetwarzania" size="10"></td>
    <td><input class="form-control form-control-sm" name="transfer_podstawa" value="<?= h($p['transfer_podstawa'] ?? '') ?>" aria-label="Podstawa transferu"></td>
    <td><select class="form-select form-select-sm" name="umowa_status" aria-label="Status umowy powierzenia"><?php foreach ($stat as $k => $l): ?><option value="<?= $k ?>" <?= ($p['umowa_status'] ?? 'brak') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></td>
    <td><input class="form-control form-control-sm" type="date" name="umowa_data" value="<?= h($p['umowa_data'] ?? '') ?>" aria-label="Data umowy"></td>
    <td><input type="hidden" name="uwagi" value="<?= h($p['uwagi'] ?? '') ?>"><button class="btn btn-sm btn-outline-primary" type="submit"><?= $id ? 'Zapisz' : 'Dodaj' ?></button></td>
  </form></tr>
<?php };
?>
<p class="text-muted">Lista dostawców, którym powierzamy lub udostępniamy dane (wykryci w kodzie integracji). Dla każdego ustaw status umowy powierzenia (art. 28) i podstawę transferu poza EOG.</p>
<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Podmioty przetwarzające</caption>
<thead><tr><th scope="col">Podmiot</th><th scope="col">Zakres</th><th scope="col">Kraj</th><th scope="col">Podstawa transferu</th><th scope="col">Umowa</th><th scope="col">Data</th><th scope="col"><span class="visually-hidden">Akcja</span></th></tr></thead><tbody>
<?php foreach ($rows as $p) $row($p); $row([]); ?>
</tbody></table></div>
<?php include __DIR__ . '/_foot.php'; ?>
