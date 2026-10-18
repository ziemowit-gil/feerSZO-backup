<?php
/** @var array $types @var array<int,int> $own liczba własnych etapów per typ @var array<int,int> $open otwarte wnioski per typ */
?>
<nav aria-label="Ścieżka" class="small mb-2"><a href="<?= APP_URL ?>/admin/applications.php" class="text-decoration-none"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Pisma i wnioski użytkowników</a></nav>
<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0"><i class="bi bi-diagram-3 text-primary fs-4" aria-hidden="true"></i></div>
  <div>
    <h1 class="h4 mb-0">Workflow wniosków</h1>
    <div class="text-muted small">Etapy obsługi każdego typu pisma: kto, w jakim terminie i co dzieje się automatycznie (UPZ, e-mail do składającego).</div>
  </div>
</div>
<?= flash_html() ?>
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light"><tr><th scope="col">Typ wniosku</th><th scope="col">Etapy</th><th scope="col">Otwarte wnioski</th><th scope="col"><span class="visually-hidden">Edytuj</span></th></tr></thead>
      <tbody>
      <?php foreach ($types as $t): $n = $own[(int)$t['id']] ?? 0; ?>
        <tr>
          <td><i class="bi <?= h($t['icon'] ?: 'bi-file-text') ?> text-primary me-1" aria-hidden="true"></i><?= h($t['label']) ?><?= empty($t['is_active']) ? ' <span class="badge bg-secondary">nieaktywny</span>' : '' ?></td>
          <td><?= $n ? $n . ' własnych' : '<span class="text-muted">domyślne (' . count(appWfDefaultStages()) . ')</span>' ?></td>
          <td><?= (int)($open[(int)$t['id']] ?? 0) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h(route('appwf.edit', ['id' => (int)$t['id']])) ?>">Etapy <i class="bi bi-chevron-right" aria-hidden="true"></i></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
