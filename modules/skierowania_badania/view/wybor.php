<?php
/**
 * modules/skierowania_badania/view/wybor.php — wybór umowy (wolontariat / zlecenie) dla nowego skierowania.
 * Zmienne: $q, $contracts. Kontroler: SkierowaniaController::create.
 */
$PAGE_TITLE = 'Nowe skierowanie — wybór umowy';
include dirname(__DIR__, 3) . '/includes/header.php';
?>
<nav aria-label="Ścieżka"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= h(route('skb.index')) ?>">Skierowania na badania</a></li><li class="breadcrumb-item active" aria-current="page">Nowe — wybór umowy</li></ol></nav>
<h4><i class="bi bi-person-check text-primary" aria-hidden="true"></i> Kogo kierujesz na badania?</h4>
<?= flash_html() ?>
<form method="get" action="<?= h(route('skb.create')) ?>" class="d-flex gap-2 mb-3" role="search">
  <label class="visually-hidden" for="w-q">Szukaj umowy</label>
  <input id="w-q" class="form-control" name="q" value="<?= h($q) ?>" placeholder="imię i nazwisko albo numer umowy" autofocus>
  <button class="btn btn-outline-primary">Szukaj</button>
</form>
<div class="list-group">
<?php foreach ($contracts as $c): $nr = skbContractNumber($c); ?>
  <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
     href="<?= h(route('skb.create', ['type' => $c['type'], 'id' => $c['id']])) ?>">
    <span><strong><?= h($c['imie_nazwisko'] ?: '(bez nazwiska)') ?></strong>
      <span class="text-body-secondary small"> — <?= h(SKB_TYPY[$c['type']]) ?><?= $nr !== '' ? ' nr ' . h($nr) : ' #' . (int)$c['id'] ?></span></span>
    <span class="badge text-bg-light"><?= h($c['status'] ?: '—') ?></span>
  </a>
<?php endforeach; if (!$contracts): ?>
  <div class="list-group-item text-body-secondary">Brak aktywnych umów wolontariackich ani zleceń<?= $q !== '' ? ' dla „' . h($q) . '”' : '' ?>.</div>
<?php endif; ?>
</div>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
