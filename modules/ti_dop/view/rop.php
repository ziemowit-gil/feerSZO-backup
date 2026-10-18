<?php
/**
 * modules/ti_dop/view/rop.php — ROP: stan subkonta na dzień wpłaty — lista wystawionych, ręczne wystawienie (uczestnik, wpłaty
 * albo dzień, numer wniosku), włącznik automatu. Zmienne: $list, $c, $payments, $requests, $clients, $q, $auto.
 */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$title = 'ROP — stan subkonta na dzień wpłaty';
include __DIR__ . '/_head.php';
?>
<div class="pivot"><a href="<?= h(route('dop.index')) ?>">Dokumenty rozliczeniowe</a><a href="<?= h(route('rop.index')) ?>" aria-current="page">ROP</a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0"><span class="sym" style="background:#008a00">ROP</span> Stan subkonta na dzień wpłaty</h1>
  <?= ldPost(route('rop.auto'), $auto ? 'Automat po wpłacie: WŁĄCZONY (wyłącz)' : 'Automat po wpłacie: wyłączony (włącz)', $auto ? 'btn-metro green' : 'btn-metro dark') ?>
</div>
<p class="text-secondary">Dokument dla kursanta i rodzica z podpisem osoby wystawiającej. Numer <b>ROP/{ID uczestnika}{6 cyfr}-{nr wniosku}</b>, bez wniosku — <b>-SALDPocz</b>.
  Automat wystawia ROP po każdej wpłacie na subkonto (podpis: osoba księgująca; płatność online — system).</p>
<section class="panel accent">
  <h2 class="mt-0">Wystaw ROP ręcznie</h2>
  <?php if (!$c): ?>
    <form method="get" action="wnioskiDop.php" class="d-flex gap-2" role="search"><input type="hidden" name="_route" value="/rop">
      <label class="visually-hidden" for="q">Uczestnik</label><input class="form-control" id="q" name="q" value="<?= h($q) ?>" placeholder="imię, nazwisko, e-mail uczestnika"><button class="btn btn-metro dark">Szukaj</button></form>
    <ul class="list-unstyled mt-2 mb-0"><?php foreach ($clients as $cl): ?><li class="py-1"><a class="btn btn-sm btn-metro" href="<?= h(route('rop.index', ['klient' => $cl['id']])) ?>">Wybierz</a> <?= h($cl['name']) ?></li><?php endforeach; ?></ul>
  <?php else: ?>
    <p><b><?= h($c['name']) ?></b> · subkonto <?= h($c['nrb'] ?: '— (brak)') ?> · saldo teraz <?= h(number_format($c['balance'], 2, ',', ' ')) ?> zł — <a href="<?= h(route('rop.index')) ?>">inny uczestnik</a></p>
    <form method="post" action="<?= h(route('rop.create')) ?>" class="row g-2"><?= Router::csrfField() ?><input type="hidden" name="klient" value="<?= (int)$c['id'] ?>">
      <div class="col-md-7"><span class="form-label small d-block">Wpłaty (zaznacz; puste = wszystkie wpłaty wskazanego dnia)</span>
        <div style="max-height:12rem;overflow:auto" class="border p-2">
        <?php foreach ($payments as $p): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="payments[]" value="<?= (int)$p['id'] ?>" id="p<?= (int)$p['id'] ?>">
          <label class="form-check-label small" for="p<?= (int)$p['id'] ?>"><?= h(ldDate((string)$p['paid_at'])) ?> — <b><?= h(number_format((float)$p['amount'], 2, ',', ' ')) ?> zł</b> <?= h(mb_strimwidth((string)$p['note'], 0, 70, '…')) ?></label></div><?php endforeach; ?>
        <?php if (!$payments): ?><span class="small text-secondary">Brak wpłat.</span><?php endif; ?></div></div>
      <div class="col-md-5"><label class="form-label small" for="ao">Stan na dzień (gdy nie wybrano wpłat)</label><input class="form-control" type="date" id="ao" name="as_of">
        <label class="form-label small mt-2" for="rn">Numer wniosku (opcjonalnie)</label><input class="form-control" id="rn" name="request_number" list="rn-list" placeholder="pusto = SALDPocz">
        <datalist id="rn-list"><?php foreach ($requests as $rq): ?><option value="<?= h($rq) ?>"><?php endforeach; ?></datalist>
        <button class="btn btn-metro w-100 mt-3">Wystaw i wyślij kursantowi i rodzicowi</button></div>
    </form>
  <?php endif; ?>
</section>
<div class="table-responsive panel p-0"><table class="table table-hover mb-0">
  <thead><tr><th>Numer</th><th>Uczestnik</th><th>Stan na</th><th>Wystawił(a)</th><th>Wysyłka</th></tr></thead><tbody>
  <?php if (!$list): ?><tr><td colspan="5" class="text-secondary p-3">Brak dokumentów ROP.</td></tr><?php endif; ?>
  <?php foreach ($list as $r): $sl = json_decode((string)($r['sent'] ?? ''), true) ?: []; ?>
    <tr><td class="text-nowrap"><a href="<?= h(route('rop.show', ['id' => $r['id']])) ?>"><?= h($r['number']) ?></a><?= $r['auto'] ? ' <span class="badge text-bg-light border">auto</span>' : '' ?></td>
      <td><?= h($r['client_name']) ?></td><td><?= h(ldDate($r['as_of'])) ?></td><td class="small"><?= h($r['issued_name']) ?></td>
      <td class="small"><?= h(implode(', ', array_map(fn($x) => $x['rola'] . ': ' . LicenseNotificationService::statusLabel($x['status']), $sl)) ?: '—') ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php include __DIR__ . '/_foot.php'; ?>
