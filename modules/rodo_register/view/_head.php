<?php
/** Wspólny nagłówek widoków modułu Obsługa RODO. Zmienne: $PAGE_TITLE, $active. */
include dirname(__DIR__, 3) . '/includes/header.php';
$tabs = ['rodo.index' => ['Pulpit', 'bi-speedometer2'], 'rodo.requests' => ['Wnioski osób', 'bi-person-lines-fill'], 'rodo.breaches' => ['Naruszenia', 'bi-exclamation-triangle'],
    'rodo.activities' => ['Rejestr czynności', 'bi-journal-text'], 'rodo.processors' => ['Podmioty przetwarzające', 'bi-building'], 'rodo.retention' => ['Retencja', 'bi-hourglass-split'], 'rodo.reports' => ['Raporty do wydruku', 'bi-printer']];
?>
<div class="container-xl py-3">
  <h1 class="h3 mb-1"><i class="bi bi-shield-check text-primary" aria-hidden="true"></i> Obsługa RODO</h1>
  <nav aria-label="Sekcje obsługi RODO"><ul class="nav nav-tabs mb-3">
    <?php foreach ($tabs as $n => [$l, $i]): ?>
      <li class="nav-item"><a class="nav-link <?= $active === $n ? 'active' : '' ?>" <?= $active === $n ? 'aria-current="page"' : '' ?> href="<?= h(route($n)) ?>"><i class="bi <?= $i ?>" aria-hidden="true"></i> <?= h($l) ?></a></li>
    <?php endforeach; ?>
  </ul></nav>
  <?= flash_html() ?>
