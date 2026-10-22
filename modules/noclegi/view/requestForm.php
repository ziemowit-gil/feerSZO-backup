<?php
/** Formularz nowego zgłoszenia noclegu. Zmienne: $f, $errors, $budgets, $role. */
$PAGE_TITLE = 'Nowe zgłoszenie noclegu'; $active = 'nowe';
include __DIR__ . '/_head.php'; include __DIR__ . '/_nav.php';
?>
<h1>Nowe zgłoszenie noclegu</h1>
<p class="ncl-lead">Zgłoszenie trafia do Logistyka (status „Wniosek”). Koordynator Projektu akceptuje koszt, a Logistyk przydziela pokój i potwierdza rezerwację. Pola z gwiazdką są wymagane.</p>
<?php if ($errors): ?><div class="alert alert-danger" role="alert">Formularz zawiera błędy — popraw zaznaczone pola.</div><?php endif; ?>
<form method="post" action="<?= h(route('nocl.store')) ?>" novalidate>
  <?= Router::csrfField() ?>
  <?php $showOther = $role === 'logistyk'; include __DIR__ . '/_requestFields.php'; ?>
  <button class="btn btn-primary" type="submit">Wyślij zgłoszenie</button>
  <a class="btn btn-outline-secondary" href="<?= h(route('nocl.index')) ?>">Anuluj</a>
</form>
<?php include __DIR__ . '/_foot.php'; ?>
