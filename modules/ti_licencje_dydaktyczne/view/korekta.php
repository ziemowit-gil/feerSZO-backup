<?php
/**
 * modules/ti_licencje_dydaktyczne/view/korekta.php — AMSK: karta korekty (zakres, druki AMSK/1 i AMSK, potwierdzenie odręcznego podpisu, wysyłka).
 * Zmienne: $c, $t, $canApprove.
 */
require __DIR__ . '/_parts.php';
require_once dirname(__DIR__) . '/lib/LicenseCorrection.php';
$id = (int)$c['id'];
$code = LicenseCorrection::code($id);
$title = $code;
$kierCur = 'licencjeDydaktyczne.php?_route=%2Fkorekty';
include __DIR__ . '/_head.php';
$st = (string)$c['status'];
$isOp = $c['scope'] === 'operation';
$targetUrl = $isOp ? route('ld.op', ['id' => $c['target_id']]) : route('ld.show', ['id' => $c['target_id']]);
?>
<div class="pivot"><a href="<?= h(route('ld.index')) ?>">Licencje AM5 / AM7</a><a href="<?= h(route('ld.corr.index')) ?>">Korekty AMSK</a><a class="active" href="<?= h(route('ld.corr', ['id' => $id])) ?>" aria-current="page"><?= h($code) ?></a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0"><span class="sym" style="background:#7c2d12">AMSK</span> Korekta <?= h($code) ?> <?= ldChip(LicenseCorrection::statusLabel($st), LicenseCorrection::statusColor($st)) ?></h1>
  <a class="btn btn-metro" style="background:#7c2d12" href="<?= h(route('ld.corr.pdf', ['id' => $id])) ?>" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> Druk kontrolny AMSK/1 (do podpisu)</a>
  <?php if ($st === LicenseCorrection::APPLIED): ?><a class="btn btn-metro dark" href="<?= h(route('ld.corr.pdf', ['id' => $id, 'druk' => 'korekta'])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> AMSK (korekta)</a><?php endif; ?>
</div>
<p class="text-secondary">Dotyczy: <a href="<?= h($targetUrl) ?>"><?= h($c['target_number']) ?></a> <?= $isOp ? '(operacja)' : '(sprawa)' ?> · wystawił(a) <?= h($c['created_name']) ?>, <?= h(ldDt($c['created_at'])) ?></p>

<section class="panel"><h2 class="mt-0">Zakres korekty</h2>
  <div class="table-responsive"><table class="table table-sm mb-2"><thead><tr><th>Dokument</th><th>Pole</th><th>Było</th><th>Jest po korekcie</th></tr></thead><tbody>
  <?php foreach ($c['itemsList'] as $it): ?><tr><td class="small"><?= h($it['doc']) ?></td><td><?= h($it['label']) ?></td><td><?= nl2br(h($it['old_disp'])) ?></td><td><b><?= nl2br(h($it['new_disp'])) ?></b></td></tr><?php endforeach; ?></tbody></table></div>
  <p class="mb-0"><b>Uzasadnienie:</b> <?= nl2br(h($c['reason'])) ?></p>
</section>

<div class="row g-3">
<section class="col-lg-7"><div class="panel h-100"><h2 class="mt-0">Obieg</h2>
  <ol class="mb-0">
    <li><b>Wystawiono</b> — <?= h(ldDt($c['created_at'])) ?> (<?= h($c['created_name']) ?>).</li>
    <li>Wydrukuj <b>AMSK/1</b> (zawiera QR i Code128), kierownik <b>podpisuje odręcznie</b>, podpisany druk trafia do teczki sprawy.</li>
    <li><?= $st === LicenseCorrection::APPLIED ? '<b>Podpis potwierdzono</b> — ' . h(ldDt($c['applied_at'])) . ' (' . h($c['applied_name']) . '), zmiany wprowadzone.' : ($st === LicenseCorrection::CANCELLED ? '<b>Anulowano</b>' . ($c['cancel_reason'] ? ': ' . h($c['cancel_reason']) : '') . '.' : 'Potwierdź podpis w SZO (panel obok) — dopiero wtedy zmiany wejdą do danych sprawy.') ?></li>
    <li>Druk <b>AMSK</b> (korekta) idzie do osoby e-mailem<?= $c['sent_at'] ? ' — wysłano ' . h(ldDt($c['sent_at'])) : '' ?>.</li>
  </ol></div></section>
<section class="col-lg-5"><div class="panel h-100"><h2 class="mt-0">Akcje</h2>
  <?php if ($st === LicenseCorrection::PENDING): ?>
    <?php if ($canApprove): ?>
    <form method="post" action="<?= h(route('ld.corr.approve', ['id' => $id])) ?>" class="mb-3" onsubmit="return confirm('Wprowadzić korektę w danych sprawy? Zmiany zapiszą się od razu.')"><?= Router::csrfField() ?>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="signed" name="signed" value="1" required>
        <label class="form-check-label" for="signed">Druk kontrolny <?= h($code) ?> (AMSK/1) jest podpisany odręcznie przez kierownika</label></div>
      <button class="btn btn-sm btn-metro green">Potwierdź podpis i wprowadź korektę</button></form>
    <?php else: ?><p class="small text-secondary">Podpis potwierdza kierownik (rola „kierownik” w panelu TI) albo administrator.</p><?php endif; ?>
    <form method="post" action="<?= h(route('ld.corr.cancel', ['id' => $id])) ?>" class="d-flex gap-1" onsubmit="return confirm('Anulować korektę?')"><?= Router::csrfField() ?>
      <label class="visually-hidden" for="cr">Powód anulowania</label><input class="form-control form-control-sm" id="cr" name="reason" placeholder="powód anulowania">
      <button class="btn btn-sm btn-metro red">Anuluj</button></form>
  <?php elseif ($st === LicenseCorrection::APPLIED): ?>
    <?= ldPost(route('ld.corr.send', ['id' => $id]), $c['sent_at'] ? 'Wyślij AMSK ponownie' : 'Wyślij AMSK do osoby', 'btn-metro green', [], 'Wysłać AMSK e-mailem w PDF do osoby, której dotyczy sprawa?') ?>
  <?php else: ?><p class="text-secondary mb-0">Korekta anulowana — brak akcji.</p><?php endif; ?>
</div></section>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
