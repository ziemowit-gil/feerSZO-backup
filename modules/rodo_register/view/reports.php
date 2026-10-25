<?php
/** Raporty do wydruku. Zmienne: $reports (typ => tytuł). */
$PAGE_TITLE = 'Raporty RODO'; $active = 'rodo.reports';
include __DIR__ . '/_head.php';
?>
<p class="text-muted">Każdy raport można otworzyć jako stronę do wydruku (A4) albo pobrać jako <strong>plik PDF</strong>. Raport wniosków nie zawiera danych wnioskodawców — tylko numer, rodzaj, terminy i status.</p>
<div class="list-group" style="max-width:52rem">
<?php foreach ($reports as $k => $t): ?>
  <div class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span><i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i><?= h($t) ?></span>
    <span class="d-flex gap-2">
      <a class="btn btn-sm btn-primary" href="<?= h(route('rodo.report.pdf', ['typ' => $k])) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Pobierz PDF<span class="visually-hidden">: <?= h($t) ?></span></a>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h(route('rodo.report', ['typ' => $k])) ?>" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> Wydruk<span class="visually-hidden">: <?= h($t) ?> (nowa karta)</span></a>
    </span>
  </div>
<?php endforeach; ?>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
