<?php
/**
 * modules/ti_licencje_dydaktyczne/view/rotacja.php — karta ASK-UP1: uzasadnienie rotacji licencji, druk do podpisu, potwierdzenie podpisu odręcznego.
 * Zmienne: $r, $canApprove.
 */
require __DIR__ . '/_parts.php';
require_once dirname(__DIR__) . '/lib/LicenseRotation.php';
$id = (int)$r['id'];
$code = LicenseRotation::code($id);
$title = $code;
$kierCur = 'licencjeDydaktyczne.php?_route=%2Fkorekty';
include __DIR__ . '/_head.php';
$st = (string)$r['status'];
$isOp = $r['scope'] === 'operation';
$targetUrl = $isOp ? route('ld.op', ['id' => $r['target_id']]) : route('ld.show', ['id' => $r['target_id']]);
?>
<div class="pivot"><a href="<?= h(route('ld.index')) ?>">Licencje AM5 / AM7</a><a href="<?= h(route('ld.corr.index')) ?>">Korekty AMSK</a><a class="active" href="<?= h(route('ld.rot', ['id' => $id])) ?>" aria-current="page"><?= h($code) ?></a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0"><span class="sym" style="background:#14532d">ASK-UP1</span> Uzasadnienie rotacji licencji <?= ldChip(LicenseRotation::statusLabel($st), LicenseRotation::statusColor($st)) ?></h1>
  <a class="btn btn-metro" style="background:#14532d" href="<?= h(route('ld.rot.pdf', ['id' => $id])) ?>" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> Druk ASK-UP1 (do podpisu)</a>
</div>
<p class="text-secondary">Dotyczy: <a href="<?= h($targetUrl) ?>"><?= h($r['target_number']) ?></a> <?= $isOp ? '(operacja)' : '(sprawa)' ?> · wystawił(a) <?= h($r['created_name']) ?>, <?= h(ldDt($r['created_at'])) ?></p>
<section class="panel"><h2 class="mt-0">Rotacja</h2>
  <dl class="row mb-2">
    <dt class="col-sm-3">Licencja / usługa</dt><dd class="col-sm-9"><?= h($r['license_label'] ?: '—') ?></dd>
    <dt class="col-sm-3">Dotychczasowy użytkownik</dt><dd class="col-sm-9"><?= h($r['from_holder'] ?: '—') ?> <span class="text-secondary small"><?= h($r['from_upn']) ?></span></dd>
    <dt class="col-sm-3">Nowy użytkownik</dt><dd class="col-sm-9"><b><?= h($r['to_holder']) ?></b> <span class="text-secondary small"><?= h($r['to_upn']) ?></span></dd>
    <dt class="col-sm-3">Data rotacji</dt><dd class="col-sm-9"><?= h(ldDate((string)$r['rotation_date']) ?: '—') ?></dd>
  </dl>
  <p class="mb-0"><b>Uzasadnienie:</b> <?= nl2br(h($r['reason'])) ?></p>
</section>
<section class="panel"><h2 class="mt-0">Podpis kierownika</h2>
  <?php if ($st === LicenseRotation::PENDING): ?>
    <p>Wydrukuj ASK-UP1 (QR i Code128), kierownik <b>podpisuje odręcznie</b>, podpisany druk trafia do teczki sprawy. Następnie potwierdź podpis w SZO.</p>
    <?php if ($canApprove): ?>
    <form method="post" action="<?= h(route('ld.rot.confirm', ['id' => $id])) ?>" class="mb-3"><?= Router::csrfField() ?>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="signed" name="signed" value="1" required>
        <label class="form-check-label" for="signed">Druk <?= h($code) ?> (ASK-UP1) jest podpisany odręcznie przez kierownika</label></div>
      <button class="btn btn-sm btn-metro green">Potwierdź podpis</button></form>
    <?php else: ?><p class="small text-secondary">Podpis potwierdza kierownik (rola „kierownik” w panelu TI) albo administrator.</p><?php endif; ?>
    <form method="post" action="<?= h(route('ld.rot.cancel', ['id' => $id])) ?>" class="d-flex gap-1" onsubmit="return confirm('Anulować uzasadnienie?')"><?= Router::csrfField() ?>
      <label class="visually-hidden" for="cr">Powód anulowania</label><input class="form-control form-control-sm" id="cr" name="reason" placeholder="powód anulowania">
      <button class="btn btn-sm btn-metro red">Anuluj</button></form>
  <?php elseif ($st === LicenseRotation::SIGNED): ?>
    <p class="mb-0">Podpis odręczny kierownika potwierdzono <?= h(ldDt($r['confirmed_at'])) ?> (<?= h($r['confirmed_name']) ?>).</p>
  <?php else: ?><p class="mb-0 text-secondary">Anulowano<?= $r['cancel_reason'] ? ': ' . h($r['cancel_reason']) : '' ?>.</p><?php endif; ?>
</section>
<?php include __DIR__ . '/_foot.php'; ?>
