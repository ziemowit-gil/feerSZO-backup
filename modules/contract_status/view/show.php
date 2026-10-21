<?php
/**
 * modules/contract_status/view/show.php — umowa: bieżący status, oś zmian statusu z dziennika,
 * „Cofnij ostatnią zmianę” i ustawienie dowolnego statusu (z powodem). Zmienne: $type, $row, $hist, $undo.
 */
$PAGE_TITLE = 'Status umowy ' . ($row['numer_umowy'] ?? '');
include dirname(__DIR__, 3) . '/includes/header.php';
$cur   = (string)($row['status'] ?? '');
$view  = APP_URL . '/contracts/' . rawurlencode($type) . '/view.php?id=' . (int)$row['id'];
$color = fn(string $s): string => contractStatusColor($s); // słownik umowy_statusy
?>
<style>
.cst-hero{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem}
.cst-tile{flex:1 1 220px;min-height:108px;padding:.8rem 1rem;color:#fff;display:flex;flex-direction:column;justify-content:space-between}
.cst-tile small{opacity:.85;text-transform:uppercase;letter-spacing:.06em;font-size:.72rem;font-weight:600}
.cst-tile b{font-size:1.6rem;font-weight:300;line-height:1.1}
.cst-flow{list-style:none;margin:0;padding:0;border-left:3px solid #cbd5e1}
.cst-flow li{position:relative;padding:.35rem 0 .7rem 1.1rem}
.cst-flow li::before{content:"";position:absolute;left:-8px;top:.6rem;width:13px;height:13px;border-radius:50%;background:var(--k,#94a3b8);border:2px solid #fff;box-shadow:0 0 0 1px #94a3b8}
.cst-chip{display:inline-block;padding:.05rem .45rem;color:#fff;font-size:.8rem;font-weight:600}
.cst-pick{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.35rem}
.cst-pick label{display:flex;align-items:center;gap:.45rem;padding:.5rem .6rem;border:2px solid transparent;background:#f1f5f9;cursor:pointer;font-weight:600;font-size:.9rem}
.cst-pick label .sw{width:14px;height:14px;flex:0 0 14px;background:var(--k)}
.cst-pick input{position:absolute;opacity:0}
.cst-pick input:checked + label{border-color:var(--k);background:#fff}
.cst-pick input:focus-visible + label{outline:3px solid #1d4ed8;outline-offset:1px}
.cst-pick input:disabled + label{opacity:.45;cursor:not-allowed}
</style>

<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">
  <li class="breadcrumb-item"><a href="<?= h(route('cst.index')) ?>">Status umowy — wycofanie</a></li>
  <li class="breadcrumb-item active" aria-current="page"><?= h((string)$row['numer_umowy']) ?></li>
</ol></nav>
<?= flash_html() ?>

<div class="cst-hero">
  <div class="cst-tile" style="background:#1f2937"><small>Umowa</small><b><?= h((string)$row['numer_umowy']) ?></b>
    <span class="small"><?= h(CONTRACT_TYPES[$type] ?? $type) ?> · <a class="link-light" href="<?= h($view) ?>">otwórz umowę</a></span></div>
  <div class="cst-tile" style="background:<?= $color($cur) ?>"><small>Status teraz</small><b><?= h(contractStatusLabel($cur)) ?></b>
    <span class="small"><?= $hist ? 'od ' . h(date('d.m.Y H:i', strtotime((string)$hist[0]['at']))) : '&nbsp;' ?></span></div>
  <?php if ($undo): ?>
  <div class="cst-tile" style="background:<?= $color($undo) ?>"><small>Poprzedni status</small><b><?= h(contractStatusLabel($undo)) ?></b>
    <span class="small">z dziennika — do cofnięcia</span></div>
  <?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card"><div class="card-body">
      <h5 class="card-title">Zmień status</h5>
      <form method="post" action="<?= h(route('cst.update', ['type' => $type, 'id' => $row['id']])) ?>" id="cst-form"><?= csrf_field() ?>
        <fieldset class="mb-3"><legend class="form-label small fw-semibold">Nowy status</legend>
          <div class="cst-pick">
            <?php foreach (contractStatuses() as $k => $s): if ($k === 'aneks') continue; $id = 'cst-' . md5($k); ?>
              <span style="--k:<?= $color($k) ?>">
                <input type="radio" name="to" id="<?= $id ?>" value="<?= h($k) ?>"<?= $k === $cur ? ' disabled' : '' ?><?= $k === $undo ? ' checked' : '' ?>>
                <label for="<?= $id ?>"><span class="sw" aria-hidden="true"></span><?= h($s['label']) ?><?= $k === $cur ? ' <span class="fw-normal small">(teraz)</span>' : '' ?></label>
              </span>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <div class="mb-3"><label class="form-label small fw-semibold" for="cst-reason">Powód <span class="text-danger">*</span></label>
          <input id="cst-reason" class="form-control" name="reason" required maxlength="300" placeholder="np. omyłkowo oznaczona jako zakończona"></div>
        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-danger" name="mode" value="set"><i class="bi bi-check2-circle" aria-hidden="true"></i> Ustaw wybrany status</button>
          <?php if ($undo): ?>
          <button class="btn btn-outline-danger" name="mode" value="undo"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Cofnij ostatnią zmianę (→ <?= h(contractStatusLabel($undo)) ?>)</button>
          <?php endif; ?>
        </div>
        <p class="small text-body-secondary mt-2 mb-0">Zmiana pomija reguły przejść i wymagane pliki. Zostanie zapisana w historii umowy jako „Panel administratora” z Twoim nazwiskiem i powodem.</p>
      </form>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <h5 class="card-title">Historia zmian statusu</h5>
      <?php if (!$hist): ?><p class="text-body-secondary small mb-0">Brak zmian statusu w dzienniku umowy.</p><?php else: ?>
      <ol class="cst-flow">
        <?php foreach (array_slice($hist, 0, 25) as $h): ?>
        <li style="--k:<?= $color($h['to']) ?>">
          <div class="small text-body-secondary"><?= h(date('d.m.Y H:i', strtotime((string)$h['at']))) ?> · <?= h((string)$h['who']) ?></div>
          <div><?php if ($h['from'] !== ''): ?><span class="cst-chip" style="background:<?= $color($h['from']) ?>"><?= h(contractStatusLabel($h['from'])) ?></span> → <?php endif; ?>
            <span class="cst-chip" style="background:<?= $color($h['to']) ?>"><?= h(contractStatusLabel($h['to'])) ?></span></div>
          <?php if (preg_match('/Powód: (.+)$/u', (string)$h['note'], $m)): ?><div class="small">Powód: <?= h($m[1]) ?></div><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<script>
// „Cofnij” nie potrzebuje wyboru statusu; „Ustaw” — tak
document.getElementById('cst-form').addEventListener('submit', function (e) {
  if (e.submitter && e.submitter.value === 'set' && !this.querySelector('input[name=to]:checked')) {
    e.preventDefault(); alert('Wybierz nowy status.');
  }
});
</script>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
