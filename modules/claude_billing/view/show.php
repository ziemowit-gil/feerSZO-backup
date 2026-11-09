<?php
/**
 * modules/claude_billing/view/show.php — karta abonamentu Claude: stan, dane do faktury VAT, dzień graniczny, naliczenia, dziennik, zakończenie.
 * Zmienne: $sub, $charges, $events, $s, $gaps, $paidThrough, $actor.
 */
$title = $P['label'] . ' — ' . $sub['client_name'];
$kierCur = 'claudeAbonament.php';
include dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_head.php';
$st = ['active' => '#008a00', 'suspended' => '#a20025', 'ended' => '#647687'];
?>
<div class="pivot"><?php foreach (ClaudeBilling::PRODUCTS as $pk => $pp): ?><a href="<?= h(route('cb.index', ['product' => $pk])) ?>"<?= $pk === $product && empty($isTest) ? ' aria-current="page"' : '' ?>><?= h($pp['label']) ?></a><?php endforeach; ?><a href="<?= h(route('cb.test', ['product' => $product])) ?>"<?= !empty($isTest) ? ' aria-current="page"' : '' ?>>Test całego przebiegu (<?= h($P['label']) ?>)</a></div>
<h1><?= h($sub['client_name']) ?> <span class="chip align-middle" style="background:<?= $st[$sub['status']] ?? '#647687' ?>"><?= h(ClaudeBilling::STATUS[$sub['status']] ?? $sub['status']) ?></span></h1>

<section class="panel accent">
  <dl class="row mb-0">
    <dt class="col-sm-3">Aktywowany</dt><dd class="col-sm-9"><?= h(ClaudeBilling::date($sub['activated_on'])) ?> przez <?= h($sub['activated_by']) ?><?= $sub['cycle_start'] !== $sub['activated_on'] ? ' · bieżący cykl od ' . h(ClaudeBilling::date($sub['cycle_start'])) : '' ?></dd>
    <dt class="col-sm-3">Naliczenie</dt><dd class="col-sm-9">co miesiąc w dniu <?= (int)date('j', strtotime((string)$sub['cycle_start'])) ?>, nie później niż <?= ClaudeBilling::dueDay($sub, $s) ?>. dnia; termin <?= ClaudeBilling::TERM_DAYS ?> dni<?= (int)$sub['free'] ? ' — <b>bez opłat</b> (uczestnik „bez rozliczeń”)' : '' ?></dd>
    <dt class="col-sm-3">Opłacono do</dt><dd class="col-sm-9"><?= h(ClaudeBilling::date($paidThrough)) ?></dd>
    <dt class="col-sm-3">Zgłoszenie IT</dt><dd class="col-sm-9"><?= h($sub['helpdesk_number'] ?: '—') ?></dd>
    <dt class="col-sm-3">Warunki</dt><dd class="col-sm-9"><details><summary>zaakceptowane <?= h(ClaudeBilling::date($sub['terms_accepted_at'])) ?><?= $sub['terms_ip'] ? ', IP ' . h($sub['terms_ip']) : '' ?></summary><p class="small mb-0"><?= h($sub['terms_text']) ?></p></details></dd>
    <?php if ($sub['status'] === 'ended'): ?><dt class="col-sm-3">Zakończony</dt><dd class="col-sm-9"><?= h(ClaudeBilling::date($sub['ended_on'])) ?> — <?= h($sub['ended_reason']) ?></dd><?php endif; ?>
  </dl>
</section>

<section class="panel<?= $gaps ? ' warn' : '' ?>">
  <h2 class="mt-0">Dane do faktury VAT</h2>
  <?php if ($gaps): ?><p class="text-danger fw-semibold">Brakuje: <?= h(implode(', ', $gaps)) ?> — faktury czekają.</p><?php endif; ?>
  <form method="post" action="<?= h(route('cb.buyer', ['id' => (int)$sub['id']])) ?>">
    <?= Router::csrfField() ?>
    <div class="row g-2">
      <?php foreach (ClaudeBilling::BUYER_FIELDS as $k => $l): ?>
      <div class="col-md-4"><label class="form-label small" for="b-<?= h($k) ?>"><?= h(ucfirst($l)) ?></label><input class="form-control form-control-sm" id="b-<?= h($k) ?>" name="<?= h($k) ?>" value="<?= h((string)$sub[$k]) ?>"<?= isset($gaps[$k]) ? ' aria-invalid="true"' : '' ?>></div>
      <?php endforeach; ?>
    </div>
    <button class="btn-metro btn-sm mt-2" type="submit">Zapisz dane (i wystaw zaległe faktury)</button>
  </form>
</section>

<h2>Naliczenia</h2>
<?php if (!$charges): ?><p class="text-secondary">Brak naliczeń.</p><?php else: $test = false; include __DIR__ . '/_charges.php'; endif; ?>

<?php if ($sub['status'] !== 'ended'): ?>
<div class="row g-3">
  <div class="col-md-6"><section class="panel">
    <h2 class="mt-0">Dzień graniczny dla tej osoby</h2>
    <form method="post" action="<?= h(route('cb.dueday', ['id' => (int)$sub['id']])) ?>" class="d-flex gap-2 align-items-end"><?= Router::csrfField() ?>
      <div><label class="form-label small" for="dd">Dzień (1–28, puste = ogólne: <?= (int)$s['due_day'] ?>)</label><input class="form-control form-control-sm" id="dd" name="due_day" type="number" min="1" max="28" value="<?= h((string)($sub['due_day'] ?? '')) ?>"></div>
      <button class="btn-metro btn-sm" type="submit">Zapisz</button></form>
  </section></div>
  <div class="col-md-6"><section class="panel warn">
    <h2 class="mt-0">Zakończ abonament</h2>
    <form method="post" action="<?= h(route('cb.end', ['id' => (int)$sub['id']])) ?>" class="d-flex gap-2 align-items-end"><?= Router::csrfField() ?>
      <div class="flex-grow-1"><label class="form-label small" for="er">Powód</label><input class="form-control form-control-sm" id="er" name="reason" placeholder="np. koniec kursu"></div>
      <button class="btn-metro red btn-sm" type="submit">Zakończ (zgłoszenie do IT)</button></form>
  </section></div>
</div>
<?php endif; ?>

<h2>Dziennik</h2>
<ul class="list-unstyled small">
  <?php foreach ($events as $e): ?><li class="border-bottom py-1"><span class="text-secondary"><?= h(date('d.m.Y H:i', strtotime((string)$e['created_at']))) ?></span> — <?= h($e['text']) ?><?= $e['by_name'] ? ' <span class="text-secondary">(' . h($e['by_name']) . ')</span>' : '' ?></li><?php endforeach; ?>
</ul>
<?php include dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_foot.php'; ?>
