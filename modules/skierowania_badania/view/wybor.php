<?php
/**
 * modules/skierowania_badania/view/wybor.php — nowe skierowanie: wybór umowy (wolontariat / zlecenie) albo
 * skierowanie PRZED zawarciem umowy (dane ręcznie lub kandydat z Naboru).
 * Zmienne: $q, $contracts, $candidates. Kontroler: SkierowaniaController::create.
 */
$PAGE_TITLE = 'Nowe skierowanie — wybór osoby';
include dirname(__DIR__, 3) . '/includes/header.php';
?>
<nav aria-label="Ścieżka"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= h(route('skb.index')) ?>">Skierowania na badania</a></li><li class="breadcrumb-item active" aria-current="page">Nowe — wybór osoby</li></ol></nav>
<h4><i class="bi bi-person-check text-primary" aria-hidden="true"></i> Kogo kierujesz na badania?</h4>
<?= flash_html() ?>
<form method="get" action="<?= h(route('skb.create')) ?>" class="d-flex gap-2 mb-3" role="search">
  <label class="visually-hidden" for="w-q">Szukaj osoby</label>
  <input id="w-q" class="form-control" name="q" value="<?= h($q) ?>" placeholder="imię i nazwisko albo numer umowy" autofocus>
  <button class="btn btn-outline-primary">Szukaj</button>
</form>

<div class="row g-4">
<div class="col-lg-7">
  <h5>Osoba z umową</h5>
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
</div>

<div class="col-lg-5">
  <h5>Przed zawarciem umowy</h5>
  <p class="small text-body-secondary">Badanie wstępne przed podpisaniem porozumienia lub zlecenia. Dane wpiszesz ręcznie; po zawarciu umowy powiążesz z nią skierowanie (koszulka EZD przejdzie do umowy).</p>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach (SKB_TYPY as $k => $l): ?>
    <a class="btn btn-outline-warning btn-sm" href="<?= h(route('skb.create', ['pre' => 1, 'type' => $k])) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> <?= h($l) ?> — przed zawarciem</a>
    <?php endforeach; ?>
  </div>
  <?php if ($candidates): ?>
  <h6 class="small text-uppercase text-body-secondary">Kandydaci z Naboru</h6>
  <div class="list-group">
  <?php foreach ($candidates as $a): $t = $a['type'] === 'wolontariat' ? 'wolontariat' : 'zlecenie'; ?>
    <a class="list-group-item list-group-item-action" href="<?= h(route('skb.create', ['pre' => 1, 'type' => $t, 'rekr' => $a['id']])) ?>">
      <strong><?= h(trim($a['imie'] . ' ' . $a['nazwisko'])) ?></strong>
      <span class="text-body-secondary small"> — <?= h($a['position_name'] ?: $a['type']) ?>, <?= h(substr((string)$a['created_at'], 0, 10)) ?></span>
    </a>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</div>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
