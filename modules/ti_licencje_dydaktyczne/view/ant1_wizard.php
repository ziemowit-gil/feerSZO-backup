<?php
/**
 * modules/ti_licencje_dydaktyczne/view/ant1_wizard.php — kreator ANT1 (wniosek o nadanie uprawnień w ramach dostępu do subskrypcji
 * Claude for Nonprofit): 1) wybór osoby (uczestnik albo dydaktyk / pracownik), 2) formularz (/operacje/nowa?form=ANT1).
 * Zmienne: $q, $found, $recent (ostatnie sprawy ANT1), $actor.
 */
require __DIR__ . '/_parts.php';
$title = 'ANT1 — dostęp do Claude';
include __DIR__ . '/_head.php';
$c = OperationForm::Ant1->color();
?>
<div class="pivot"><a href="<?= h(route('ld.index')) ?>">Sprawy AM5 / AM7</a><a href="<?= h(route('ld.accounts')) ?>">Operacje na licencjach</a><a href="<?= h(route('ld.ant1')) ?>" aria-current="page">ANT1</a></div>
<h1><span class="sym" style="background:<?= h($c) ?>">ANT1</span> Wniosek o nadanie uprawnień w ramach dostępu do subskrypcji Claude for Nonprofit</h1>
<p class="text-secondary">Formularz elektroniczny: 1) wybierz osobę (pełnoletni / niepełnoletni uczestnik szkoleń albo dydaktyk / trener), 2) wypełnij ANT1 — dane, e-mail w domenie <?= h(Ant1Form::EMAIL_DOMAIN) ?>, kurs i grupa.
  Osoba potwierdza przez link (e-mail + SMS) wniosek, <b>oświadczenie o zryczałtowanej opłacie <?= h(Ant1Form::FEE) ?> za dostęp do Claude</b> i klauzulę RODO; potem kierownik składa oświadczenie (decyzja cyfrowa),
  Dział IT dostaje zgłoszenie, a osoba — dokument ANT1 w PDF (układ jak AM20). Pusty wzór: <a href="<?= h(route('ld.op.blank', ['form' => 'ANT1'])) ?>" target="_blank" rel="noopener">ANT1 do druku</a>.</p>

<section class="panel accent" style="border-left-color:<?= h($c) ?>">
  <h2 class="mt-0">1. Osoba</h2>
  <form method="get" action="licencjeDydaktyczne.php" class="d-flex gap-2" role="search">
    <input type="hidden" name="_route" value="/ant1">
    <label class="visually-hidden" for="q">Imię, nazwisko lub e-mail</label>
    <input class="form-control" id="q" name="q" value="<?= h($q) ?>" placeholder="uczestnik albo dydaktyk / pracownik — imię, nazwisko, e-mail" autofocus>
    <button class="btn btn-metro dark">Szukaj</button>
  </form>
  <?php if ($q !== ''): ?>
    <?php if (!$found): ?><p class="text-secondary mt-2 mb-0">Nic nie znaleziono.</p><?php else: ?>
    <ul class="list-unstyled mt-2 mb-0"><?php foreach ($found as $f): ?>
      <li class="py-1"><a class="btn btn-sm btn-metro" style="background:<?= h($c) ?>;color:#fff" href="<?= h(route('ld.op.new', ['konto' => $f['kind'] . ':' . $f['id'], 'form' => 'ANT1'])) ?>">2. Wypełnij ANT1</a> <b><?= h($f['name']) ?></b> <span class="small text-secondary"><?= h($f['role']) ?><?= $f['email'] ? ' · ' . h($f['email']) : '' ?></span></li>
    <?php endforeach; ?></ul><?php endif; ?>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="mt-0">Sprawy ANT1</h2>
  <?php if (!$recent): ?><p class="text-secondary mb-0">Nie ma jeszcze żadnej sprawy ANT1.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Nr sprawy</th><th>Osoba</th><th>Konto (e-mail)</th><th>Stan</th><th>Termin</th><th>Helpdesk</th></tr></thead><tbody>
    <?php foreach (array_slice($recent, 0, 50) as $o): ?>
      <tr><td><a href="<?= h(route('ld.op', ['id' => $o['id']])) ?>"><?= h($o['number']) ?></a></td><td><?= h($o['account_name']) ?> <span class="small text-secondary"><?= h(Ant1Form::TYPES[$o['request']['rodzaj'] ?? ''] ?? '') ?></span></td>
        <td class="small"><?= h($o['request']['email_edu'] ?? '') ?></td><td><?= ldChip($o['statusEnum']->label(), $o['statusEnum']->color()) ?></td><td class="small"><?= h(ldDate($o['due_on'])) ?></td><td class="small"><?= h($o['helpdesk_number'] ?: '—') ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>
<?php include __DIR__ . '/_foot.php'; ?>
