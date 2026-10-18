<?php
/**
 * modules/contract_status/view/index.php — wyszukanie umowy do zmiany statusu. Zmienne: $q, $type, $found.
 */
$PAGE_TITLE = 'Status umowy — wycofanie';
include dirname(__DIR__, 3) . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <h4 class="mb-0"><i class="bi bi-arrow-counterclockwise text-danger" aria-hidden="true"></i> Status umowy — wycofanie</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Administracja</a>
</div>
<p class="text-body-secondary small mb-3">Narzędzie awaryjne administratora: ustawia <strong>dowolny</strong> status umowy albo cofa ostatnią zmianę — z pominięciem reguł przejść
  (także z „Zakończona”, „Anulowana”, „Rozwiązana”). Każda zmiana z powodem trafia do historii umowy. To samo z konsoli: <code>php cli/contractStatus.php</code>.</p>
<?= flash_html() ?>

<form method="get" action="<?= h(route('cst.index')) ?>" class="row g-2 align-items-end mb-3" role="search">
  <div class="col-sm-5"><label class="form-label small" for="cst-q">Numer umowy, nr w rejestrze albo id</label>
    <input id="cst-q" class="form-control" name="q" value="<?= h($q) ?>" placeholder="np. POW/0002/2026" autofocus></div>
  <div class="col-sm-4"><label class="form-label small" for="cst-t">Rodzaj umowy</label>
    <select id="cst-t" class="form-select" name="type"><option value="">wszystkie</option>
      <?php foreach (CONTRACT_TYPES as $k => $l): ?><option value="<?= h($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-sm-3"><button class="btn btn-primary w-100"><i class="bi bi-search" aria-hidden="true"></i> Szukaj</button></div>
</form>

<?php if ($q !== ''): ?>
  <?php if (!$found): ?>
  <div class="alert alert-light border">Nie znaleziono umowy „<?= h($q) ?>”.<?= ctype_digit($q) && $type === '' ? ' Szukasz po id? Wybierz rodzaj umowy.' : '' ?></div>
  <?php else: ?>
  <div class="list-group">
    <?php foreach ($found as [$t, $r]): ?>
    <a class="list-group-item list-group-item-action d-flex flex-wrap justify-content-between align-items-center gap-2" href="<?= h(route('cst.show', ['type' => $t, 'id' => $r['id']])) ?>">
      <span><strong><?= h((string)$r['numer_umowy']) ?></strong>
        <span class="text-body-secondary small ms-2"><?= h(CONTRACT_TYPES[$t]) ?> · #<?= (int)$r['id'] ?><?= !empty($r['nr_rejestru']) ? ' · ' . h($r['nr_rejestru']) : '' ?></span></span>
      <?= status_badge((string)$r['status']) ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
