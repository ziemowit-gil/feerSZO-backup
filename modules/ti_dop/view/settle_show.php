<?php
/**
 * modules/ti_dop/view/settle_show.php — karta PRZ-P / PRZ / RKO: dane, wynik wykonania, zatwierdzenie kierownika, wysyłki. Zmienne: $r, $canApprove.
 */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$k = $r['kindEnum']; $d = $r['d']; $s = $d['snapshot_after'] ?? $d['snapshot'] ?? [];
$title = $r['number'];
include __DIR__ . '/_head.php';
$zl = fn($v) => number_format((float)$v, 2, ',', ' ') . ' zł';
?>
<div class="pivot"><a href="<?= h(route('settle.index')) ?>">Rozliczenia</a><a href="#" aria-current="page"><?= h($r['number']) ?></a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0"><span class="sym" style="background:<?= h($k->color()) ?>"><?= h($k->value) ?></span> <?= h($k->title()) ?></h1>
  <a class="btn btn-metro dark" href="<?= h(route('settle.pdf', ['id' => $r['id']])) ?>" target="_blank" rel="noopener">PDF</a>
  <?php if ($r['status'] === 'applied'): ?><?= ldPost(route('settle.send', ['id' => $r['id']]), 'Wyślij kursantowi i rodzicowi', 'btn-metro') ?><?php endif; ?>
</div>
<div class="tiles">
  <div class="tile wide" style="background:#1d1d1d"><span class="n" style="font-size:1rem;font-weight:600"><?= h($r['number']) ?></span><span class="l"><?= h($r['client_name']) ?> · <?= h(SettlementDocs::courseName((int)$r['course_id'])) ?></span></div>
  <div class="tile" style="background:<?= $r['status'] === 'applied' ? '#008a00' : ($r['status'] === 'rejected' ? '#a20025' : '#f0a30a') ?>"><span class="n" style="font-size:1rem;font-weight:600"><?= h(['pending' => 'czeka na zatwierdzenie', 'approved' => 'zatwierdzony', 'applied' => 'wykonany', 'rejected' => 'odrzucony'][$r['status']] ?? $r['status']) ?></span><span class="l"><?= $r['mode'] === 'direct' ? 'bez akceptacji (akta)' : 'z akceptacją' ?></span></div>
  <div class="tile" style="background:<?= ($s['balance'] ?? 0) < 0 ? '#a20025' : '#0050ef' ?>"><span class="n" style="font-size:1.2rem"><?= h($zl($s['balance'] ?? 0)) ?></span><span class="l">Saldo (<?= isset($d['snapshot_after']) ? 'po wykonaniu' : 'w chwili wystawienia' ?>)</span></div>
</div>
<div class="row g-3">
<section class="col-lg-7"><div class="panel h-100"><h2 class="mt-0">Treść</h2><dl class="row small mb-0">
  <?php if ($k === SettleKind::Przp): ?>
    <dt class="col-sm-4">Na dzień</dt><dd class="col-sm-8"><?= h(ldDate($d['as_of'])) ?></dd>
    <dt class="col-sm-4">Godziny wykorzystane / opłacone</dt><dd class="col-sm-8"><?= h($d['godz_wykorzystane']) ?> h / <?= h($d['godz_oplacone']) ?> h</dd>
    <dt class="col-sm-4">Saldo początkowe</dt><dd class="col-sm-8"><?= h($zl($d['saldo'])) ?></dd><dt class="col-sm-4">Podstawa</dt><dd class="col-sm-8"><?= h($d['zrodlo']) ?></dd>
  <?php elseif ($k === SettleKind::Prz): ?>
    <dt class="col-sm-4">Stan początkowy</dt><dd class="col-sm-8"><?= h(($d['snapshot']['baseline']['number'] ?? '—')) ?></dd>
    <?php foreach ($d['months'] as $i => $m): $cur = $d['snapshot']['current'][$i] ?? []; ?><dt class="col-sm-4"><?= h(sprintf('%02d.%d', $m['month'], $m['year'])) ?></dt>
      <dd class="col-sm-8">było: <?= isset($cur['hours']) && $cur['hours'] !== null ? h($cur['hours']) . ' h, ' . h($zl($cur['amount'])) : 'brak rozliczenia' ?> → <b><?= h($m['h']) ?> h × <?= h($zl($m['rate'])) ?> = <?= h($zl($m['amount'])) ?></b></dd><?php endforeach; ?>
  <?php else: ?>
    <?php if (!empty($s['group'])): $g = $s['group']; ?><dt class="col-sm-4">Grupa</dt><dd class="col-sm-8"><?= h($g['name']) ?>: <?= h($g['lessons']) ?> zajęć, <?= h($g['hours_attended']) ?> h obecności, rozliczono <?= h($g['hours_billed']) ?> h; należności <?= h($zl($g['charges'])) ?>, wpłaty <?= h($zl($g['payments'])) ?></dd><?php endif; ?>
    <?php foreach (($s['groups'] ?? []) as $g): ?><dt class="col-sm-4"><?= h($g['name']) ?></dt><dd class="col-sm-8">należności <?= h($zl($g['charges'])) ?>, wpłaty <?= h($zl($g['payments'])) ?>, saldo <?= h($zl($g['credit'] - $g['debt'])) ?></dd><?php endforeach; ?>
    <?php if (!empty($s['resources'])): ?><dt class="col-sm-4">Do zamknięcia (AMZ)</dt><dd class="col-sm-8"><?= h(implode('; ', array_map(fn($x) => $x['name'] . ' (' . $x['status'] . ')', $s['resources']))) ?></dd><?php endif; ?>
    <dt class="col-sm-4">Wynik</dt><dd class="col-sm-8"><?php $b = (float)($s['balance'] ?? 0); ?><?= $b > 0.004 ? 'nadpłata ' . h($zl($b)) . ' — <a href="' . h(route('dop.new', ['form' => 'DOP1', 'klient' => $r['client_id']])) . '">wniosek DOP1 o zwrot</a>' : ($b < -0.004 ? 'do dopłaty ' . h($zl(-$b)) : 'bez salda') ?></dd>
  <?php endif; ?>
  <dt class="col-sm-4">Uzasadnienie</dt><dd class="col-sm-8"><?= h($d['uzasadnienie'] ?: '—') ?></dd>
  <dt class="col-sm-4">Wystawił(a)</dt><dd class="col-sm-8"><?= h($r['created_name']) ?>, <?= h(ldDt($r['created_at'])) ?></dd>
  <?php if ($r['decided_at']): ?><dt class="col-sm-4">Decyzja</dt><dd class="col-sm-8"><?= h($r['decided_name']) ?>, <?= h(ldDt($r['decided_at'])) ?><?= $r['decision_note'] ? ' — ' . h($r['decision_note']) : '' ?></dd><?php endif; ?>
  <?php if ($r['res']['log'] ?? []): ?><dt class="col-sm-4">Wykonanie</dt><dd class="col-sm-8"><?= h(implode('; ', $r['res']['log'])) ?></dd><?php endif; ?>
</dl></div></section>
<section class="col-lg-5"><div class="panel h-100"><h2 class="mt-0">Obieg</h2>
  <?php if ($r['status'] === 'pending'): ?>
    <?php if ($canApprove): ?><form method="post" action="<?= h(route('settle.decide', ['id' => $r['id']])) ?>" class="d-grid gap-2"><?= Router::csrfField() ?>
      <label class="form-label small mb-0" for="nt">Uwagi (przy odrzuceniu wymagane)</label><input class="form-control form-control-sm" id="nt" name="note">
      <div class="d-flex gap-2"><button class="btn btn-metro green flex-fill" name="decision" value="approve">Zatwierdź i wykonaj</button><button class="btn btn-metro red flex-fill" name="decision" value="reject">Odrzuć</button></div></form>
    <?php else: ?><p class="small text-secondary">Zatwierdza kierownik albo administrator.</p><?php endif; ?>
  <?php endif; ?>
  <ul class="small mt-2 mb-0"><?php foreach ($r['sentList'] as $x): ?><li><?= h(ldDt($x['at'])) ?> — <?= h($x['rola']) ?> <?= h($x['email']) ?>: <?= h(LicenseNotificationService::statusLabel($x['status'])) ?></li><?php endforeach; ?></ul>
</div></section>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
