<?php
/**
 * modules/ti_licencje_dydaktyczne/view/am20_wizard.php — formularz elektroniczny AM20 (+ AM20-1): wybór osoby i licencji,
 * potem formularz operacji (/operacje/nowa?form=AM20). Zmienne: $q, $found, $acc, $res, $actor.
 */
require __DIR__ . '/_parts.php';
$title = 'AM20 — formularz elektroniczny';
include __DIR__ . '/_head.php';
?>
<div class="pivot"><a href="<?= h(route('ld.index')) ?>">Sprawy AM5 / AM7</a><a href="<?= h(route('ld.accounts')) ?>">Operacje na licencjach</a><a href="<?= h(route('ld.am20')) ?>" aria-current="page">AM20</a></div>
<h1><span class="sym" style="background:#008a00">AM20</span> <span class="sym" style="background:#008a00">AM20-1</span> Zmiana sposobu płatności za posiadaną licencję</h1>
<p class="text-secondary">Formularz elektroniczny: 1) wybierz osobę, 2) wybierz licencję, 3) wypełnij AM20 i wykaz dokumentów księgowych AM20-1.
  Bez akceptacji kierownika — osoba potwierdza wniosek przez link (e-mail + SMS), potem doliczenie do zajęć albo dane do wpłaty.</p>

<section class="panel accent">
  <h2 class="mt-0">1. Osoba</h2>
  <?php if ($acc): ?>
    <p class="mb-0"><b><?= h($acc['name']) ?></b> · <?= h($acc['role']) ?> · <?= h($acc['login']) ?> · SubID <?= h($acc['sub_id']) ?>
      — <a href="<?= h(route('ld.am20')) ?>">zmień osobę</a></p>
  <?php else: ?>
  <form method="get" action="licencjeDydaktyczne.php" class="d-flex gap-2" role="search">
    <input type="hidden" name="_route" value="/am20">
    <label class="visually-hidden" for="q">Imię, nazwisko lub e-mail</label>
    <input class="form-control" id="q" name="q" value="<?= h($q) ?>" placeholder="uczestnik albo pracownik — imię, nazwisko, e-mail" autofocus>
    <button class="btn btn-metro dark">Szukaj</button>
  </form>
  <?php if ($q !== ''): ?>
    <?php if (!$found): ?><p class="text-secondary mt-2 mb-0">Nic nie znaleziono.</p><?php else: ?>
    <ul class="list-unstyled mt-2 mb-0"><?php foreach ($found as $f): ?>
      <li class="py-1"><a class="btn btn-sm btn-metro" href="<?= h(route('ld.am20', ['konto' => $f['kind'] . ':' . $f['id']])) ?>">Wybierz</a> <b><?= h($f['name']) ?></b> <span class="small text-secondary"><?= h($f['role']) ?><?= $f['email'] ? ' · ' . h($f['email']) : '' ?></span></li>
    <?php endforeach; ?></ul><?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>
</section>

<?php if ($acc): ?>
<section class="panel">
  <h2 class="mt-0">2. Licencja</h2>
  <?php if (!$res): ?>
    <p class="mb-1">Ta osoba nie ma przypisanej licencji. AM20 dotyczy już posiadanej licencji — najpierw przypisz ją
      <?php if ($acc['kind'] === 'client' && $acc['student_account_id']): ?>w <a href="konta.php?selected=<?= (int)$acc['student_account_id'] ?>&amp;ptab=licencje#detail-panel">kartotece uczestnika (zakładka Licencje)</a><?php else: ?>w katalogu licencji<?php endif; ?>.</p>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Licencja</th><th>Okres</th><th>Obecny płatnik</th><th></th></tr></thead><tbody>
    <?php foreach ($res as $r): ?>
      <tr><td><b><?= h($r['sku']) ?></b><div class="small text-secondary"><?= h($r['vendor']) ?> · <?= h($r['source']) ?></div></td>
        <td class="small"><?= h(trim(ldDate($r['assigned_at']) . ' — ' . ldDate($r['expires_at']), ' —')) ?></td>
        <td class="small"><?= h($r['funding']['payer']) ?> (<?= h($r['funding']['source']) ?>)</td>
        <td class="text-end"><a class="btn btn-sm btn-metro green" href="<?= h(route('ld.op.new', ['konto' => $acc['kind'] . ':' . $acc['id'], 'form' => 'AM20', 'zasob' => $r['key']])) ?>">3. Wypełnij AM20 + AM20-1</a></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
