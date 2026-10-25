<?php
/** Raporty do wydruku. Zmienne: $reports (typ => tytuł). */
$PAGE_TITLE = 'Raporty RODO'; $active = 'rodo.reports';
include __DIR__ . '/_head.php';
?>
<p class="text-muted">Raporty otwierają się jako strona gotowa do wydruku (A4). Użyj przycisku <strong>Drukuj</strong> albo „Zapisz jako PDF” w oknie drukowania przeglądarki. Raport wniosków nie zawiera danych wnioskodawców — tylko numer, rodzaj, terminy i status.</p>
<div class="list-group" style="max-width:48rem">
<?php foreach ($reports as $k => $t): ?>
  <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="<?= h(route('rodo.report', ['typ' => $k])) ?>" target="_blank" rel="noopener">
    <span><i class="bi bi-printer me-2" aria-hidden="true"></i><?= h($t) ?></span><span class="badge text-bg-light">otwiera w nowej karcie</span></a>
<?php endforeach; ?>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
