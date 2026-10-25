<?php
/** Wnioski osób (art. 15–21). Zmienne: $rows, $status. */
$PAGE_TITLE = 'Wnioski osób — RODO'; $active = 'rodo.requests';
include __DIR__ . '/_head.php';
?>
<div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
  <form method="get" action="<?= h(route('rodo.requests')) ?>" class="d-flex gap-2 align-items-center"><?= route_field('rodo.requests') ?>
    <label class="form-label mb-0" for="st">Status</label>
    <select id="st" name="status" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
      <option value="">otwarte</option><option value="wszystkie" <?= $status === 'wszystkie' ? 'selected' : '' ?>>wszystkie</option>
      <?php foreach (RODO_REQUEST_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
    </select><noscript><button class="btn btn-sm btn-outline-secondary">Pokaż</button></noscript></form>
  <a class="btn btn-primary btn-sm" href="<?= h(route('rodo.request.new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Zarejestruj wniosek</a>
</div>
<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Wnioski osób</caption>
<thead><tr><th scope="col">Nr</th><th scope="col">Wnioskodawca</th><th scope="col">Rodzaj</th><th scope="col">Wpłynął</th><th scope="col">Termin</th><th scope="col">Status</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td><a href="<?= h(route('rodo.request', ['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?></a></td><td><?= h($r['wnioskodawca']) ?> <span class="text-muted small"><?= h(fcMask($r['email'], 'email')) ?></span></td>
  <td><?= h(RODO_REQUEST_TYPES[$r['typ']] ?? $r['typ']) ?></td><td><?= h($r['data_wplywu']) ?></td>
  <td><?= h($r['termin']) ?> <?php if ($r['po_terminie']): ?><span class="badge text-bg-danger">po terminie</span><?php endif; ?></td>
  <td><span class="badge <?= h(RODO_REQUEST_STATUS[$r['status']][1]) ?>"><?= h(RODO_REQUEST_STATUS[$r['status']][0]) ?></span></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="text-muted">Brak wniosków.</td></tr><?php endif; ?>
</tbody></table></div>
<?php include __DIR__ . '/_foot.php'; ?>
