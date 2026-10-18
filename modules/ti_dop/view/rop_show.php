<?php
/**
 * modules/ti_dop/view/rop_show.php — podgląd dokumentu ROP: stan subkonta na dzień wpłaty, wysyłki, ponowna wysyłka. Zmienne: $r.
 */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$s = $r['snap'];
$title = $r['number'];
include __DIR__ . '/_head.php';
$zl = fn($v) => number_format((float)$v, 2, ',', ' ') . ' zł';
?>
<div class="pivot"><a href="<?= h(route('rop.index')) ?>">ROP</a><a href="#" aria-current="page"><?= h($r['number']) ?></a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0"><span class="sym" style="background:#008a00">ROP</span> <?= h($r['number']) ?></h1>
  <a class="btn btn-metro dark" href="<?= h(route('rop.pdf', ['id' => $r['id']])) ?>" target="_blank" rel="noopener">PDF</a>
  <?= ldPost(route('rop.send', ['id' => $r['id']]), 'Wyślij ponownie kursantowi i rodzicowi', 'btn-metro') ?>
</div>
<div class="tiles">
  <div class="tile wide" style="background:#1d1d1d"><span class="n" style="font-size:1.1rem;font-weight:600"><?= h($s['client']['name']) ?></span><span class="l">stan na <?= h(ldDate($s['as_of'])) ?></span></div>
  <div class="tile" style="background:#647687"><span class="n" style="font-size:1.2rem"><?= h($zl($s['balance_before'])) ?></span><span class="l">Saldo przed wpłatą</span></div>
  <div class="tile" style="background:#008a00"><span class="n" style="font-size:1.2rem">+<?= h($zl($s['paid'])) ?></span><span class="l">Wpłata</span></div>
  <div class="tile" style="background:<?= $s['balance_after'] < 0 ? '#a20025' : '#0050ef' ?>"><span class="n" style="font-size:1.2rem"><?= h($zl($s['balance_after'])) ?></span><span class="l">Saldo po wpłacie</span></div>
</div>
<section class="panel"><dl class="row small mb-0">
  <dt class="col-sm-3">Subkonto</dt><dd class="col-sm-9"><?= h($s['nrb'] ?: '—') ?></dd>
  <dt class="col-sm-3">Wniosek</dt><dd class="col-sm-9"><?= h($r['request_number'] ?: 'SALDPocz (bez wniosku)') ?></dd>
  <dt class="col-sm-3">Wystawił(a)</dt><dd class="col-sm-9"><?= h($r['issued_name']) ?>, <?= h(ldDt($r['issued_at'])) ?><?= $r['auto'] ? ' (automatycznie po wpłacie)' : '' ?></dd>
  <dt class="col-sm-3">Wysyłki</dt><dd class="col-sm-9"><?php foreach ($r['sentList'] as $x): ?><div><?= h(ldDt($x['at'])) ?> — <?= h($x['rola']) ?> <?= h($x['email']) ?>: <?= h(LicenseNotificationService::statusLabel($x['status'])) ?></div><?php endforeach; ?></dd>
</dl></section>
<?php include __DIR__ . '/_foot.php'; ?>
