<?php
/**
 * modules/ti_licencje_dydaktyczne/view/op_show.php — karta operacji: dane wniosku, stan licencji, decyzja cyfrowa kierownika,
 * wykonanie (automatyczne / zadanie IT), historia płatników, dziennik, powiadomienia i rekord JSON (#detail-panel).
 * Zmienne: $op, $record, $events, $notifs, $history, $actor, $canApprove.
 */
require __DIR__ . '/_parts.php';
$f = $op['formEnum'];
$st = $op['statusEnum'];
$title = $op['number'];
include __DIR__ . '/_head.php';
$id = (int)$op['id'];
$opVal = function (array $def, string $v): string {
    [, $type, , $opts] = $def;
    if ($v === '') return '—';
    return match ($type) { 'select' => (string)($opts[$v] ?? $v), 'money' => LicenseOperationService::money((float)$v), 'date' => ldDate($v), 'account' => LicenseOperationService::nrb($v), 'check' => 'tak', default => $v };
};
?>
<div class="pivot"><a href="<?= h(route('ld.accounts')) ?>">Operacje na licencjach</a><a href="<?= h(route('ld.account', ['kind' => $op['account_kind'], 'id' => $op['account_id']])) ?>"><?= h($op['account_name']) ?></a><a href="#" aria-current="page"><?= h($f->value) ?></a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0"><span class="sym" style="background:<?= h($f->color()) ?>"><?= h($f->actionType()) ?></span> <?= h($f->title()) ?></h1>
  <a class="btn btn-metro dark" href="<?= h(route('ld.op.pdf', ['id' => $id])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Druk (PDF)</a>
  <a class="btn btn-outline-secondary" href="<?= h(route('ld.op.json', ['id' => $id])) ?>" target="_blank" rel="noopener">JSON</a>
</div>
<div class="tiles">
  <div class="tile wide" style="background:<?= h($f->color()) ?>"><span class="n" style="font-size:1.05rem;font-weight:600"><?= h($op['number']) ?></span><span class="l">Numer sprawy</span></div>
  <div class="tile" style="background:<?= h($st->color()) ?>"><span class="n" style="font-size:1.05rem;font-weight:600"><?= h($st->label()) ?></span><span class="l"><?= $f->needsApproval() ? 'Stan' : 'Bez akceptacji' ?></span></div>
  <div class="tile" style="background:#f0a30a"><span class="n" style="font-size:1.2rem"><?= h(ldDate($op['due_on'])) ?></span><span class="l">Termin <?= LD_REALIZATION_DAYS ?> dni</span></div>
  <div class="tile" style="background:#647687"><span class="n" style="font-size:.95rem;font-weight:600"><?= h($op['execution_mode'] ?: '—') ?></span><span class="l">Tryb wykonania<?= $op['helpdesk_number'] ? ' · ' . h($op['helpdesk_number']) : '' ?></span></div>
</div>

<div class="row g-3">
<section class="col-lg-7"><div class="panel accent h-100">
  <h2 class="mt-0"><?= $f->lifecycle() ? 'Dyspozycja' : 'Wniosek' ?></h2>
  <dl class="row small mb-0">
    <dt class="col-sm-4">Osoba</dt><dd class="col-sm-8"><?= h($op['account_name']) ?> · <?= h($op['account_role']) ?> · <?= h($op['account_login']) ?></dd>
    <?php if ($op['state']): ?><dt class="col-sm-4">Licencja</dt><dd class="col-sm-8"><b><?= h($op['state']['sku'] ?? '') ?></b> · <?= h($op['state']['vendor'] ?? '') ?> · <?= h($op['state']['type'] ?? '') ?> · do <?= h(ldDate($op['state']['expires_at'] ?? '') ?: '—') ?>
      <div>płatnik przed operacją: <?= h(($op['state']['payer'] ?? '') . ' (' . ($op['state']['funding_source'] ?? '') . ')') ?></div></dd><?php endif; ?>
    <?php foreach ($f->requestFields() as $k => $def): ?><dt class="col-sm-4"><?= h($def[0]) ?></dt><dd class="col-sm-8" style="white-space:pre-line"><?= h($opVal($def, (string)($op['request'][$k] ?? ''))) ?></dd><?php endforeach; ?>
    <?php if ($op['proof_path'] !== ''): ?><dt class="col-sm-4">Dowód zakupu</dt><dd class="col-sm-8"><a href="<?= h(route('ld.op.proof', ['id' => $id])) ?>" target="_blank" rel="noopener"><?= h($op['proof_name']) ?></a></dd><?php endif; ?>
    <?php if ($f === OperationForm::Am20): $am = (array)($op['request']['am20_1'] ?? []); $sm = LicenseResources::accountingSummary($am); ?>
      <dt class="col-sm-4">AM20-1 — dokumenty księgowe</dt><dd class="col-sm-8"><?php if (!$am): ?><?= !empty($op['request']['am20_1_brak']) ? 'FEER nie wystawiała dokumentów' : '—' ?><?php else: ?>
        <ul class="mb-1 ps-3"><?php foreach ($am as $dd): ?><li><?= h($dd['typ']) ?> <b><?= h($dd['numer']) ?></b>, <?= h(ldDate($dd['data'])) ?><?= $dd['kwota'] !== '' ? ', ' . h(LicenseOperationService::money((float)$dd['kwota'])) : '' ?></li><?php endforeach; ?></ul>
        razem <?= h(LicenseOperationService::money($sm['sum'])) ?> · pierwsza faktura <?= h(ldDate($sm['first_invoice']) ?: '—') ?> · ostatnia <?= h(ldDate($sm['last_invoice']) ?: '—') ?><?php endif; ?></dd>
      <dt class="col-sm-4">Potwierdzenie osoby</dt><dd class="col-sm-8"><?= $op['signed_at'] ? ldChip('potwierdzono', '#008a00') . ' ' . h($op['signed_name']) . ', ' . h(ldDt($op['signed_at'])) . ', IP ' . h($op['signed_ip']) : ldChip('czeka na potwierdzenie', '#fa6800') ?></dd>
    <?php endif; ?>
    <dt class="col-sm-4">Złożył(a)</dt><dd class="col-sm-8"><?= h($op['created_name']) ?>, <?= h(ldDt($op['created_at'])) ?>, IP <?= h($op['created_ip']) ?></dd>
    <?php if ($op['decided_at']): ?><dt class="col-sm-4">Decyzja</dt><dd class="col-sm-8"><?= !empty($op['decision']['grant']) ? ldChip('zatwierdzono', '#008a00') : ldChip('odmowa', '#a20025') ?> <?= h($op['decided_name']) ?>, <?= h(ldDt($op['decided_at'])) ?>, IP <?= h($op['decided_ip']) ?>
      <?php foreach ($f->decisionFields() as $k => $def): if (($op['decision'][$k] ?? '') === '') continue; ?><div><span class="text-secondary"><?= h($def[0]) ?>:</span> <?= h($op['decision'][$k]) ?></div><?php endforeach; ?></dd><?php endif; ?>
    <?php if ($op['execution_notes']): ?><dt class="col-sm-4">Wykonanie</dt><dd class="col-sm-8"><?= h($op['execution_notes']) ?><?= $op['executed_at'] ? ' <b>(' . h(ldDt($op['executed_at'])) . ')</b>' : '' ?></dd><?php endif; ?>
    <?php if ($op['helpdesk_ticket_id']): ?><dt class="col-sm-4">Helpdesk IT</dt><dd class="col-sm-8"><a href="<?= h(rtrim(APP_URL, '/') . '/helpdesk/view.php?id=' . (int)$op['helpdesk_ticket_id']) ?>"><?= h($op['helpdesk_number']) ?></a></dd><?php endif; ?>
  </dl>
</div></section>

<section class="col-lg-5"><div class="panel h-100" id="decyzja">
  <h2 class="mt-0">Akcje</h2>
  <?php if ($st === OperationStatus::Pending && $f->needsApproval()): ?>
    <?php if ($canApprove): ?>
    <form method="post" action="<?= h(route('ld.op.decide', ['id' => $id])) ?>" class="d-grid gap-2"><?= Router::csrfField() ?>
      <div class="small text-secondary"><?= h(LicenseResources::vendorCheck((string)$op['resource_key'])) ?></div>
      <?php foreach ($f->decisionFields() as $k => [$label, $type]): ?>
        <div><label class="form-label small mb-0" for="d-<?= h($k) ?>"><?= h($label) ?></label>
          <?php if ($type === 'textarea'): ?><textarea class="form-control form-control-sm" id="d-<?= h($k) ?>" name="<?= h($k) ?>" rows="2"></textarea>
          <?php else: ?><input class="form-control form-control-sm" id="d-<?= h($k) ?>" name="<?= h($k) ?>"><?php endif; ?></div>
      <?php endforeach; ?>
      <div class="d-flex gap-2">
        <button class="btn btn-metro green flex-fill" name="decision" value="grant">Zatwierdź</button>
        <button class="btn btn-metro red flex-fill" name="decision" value="refuse">Odmów</button>
      </div>
      <div class="small text-secondary">Stempel decyzji: Twoje ID, czas i IP. Po zatwierdzeniu: druk PDF e-mailem do osoby i wykonanie (automatycznie albo zadanie dla IT).</div>
    </form>
    <?php else: ?><p class="small text-secondary">Decyzję podejmuje kierownik (rola „kierownik” panelu TI) albo administrator.</p><?php endif; ?>
    <form method="post" action="<?= h(route('ld.op.cancel', ['id' => $id])) ?>" class="d-flex gap-1 mt-3" onsubmit="return confirm('Wycofać operację?')"><?= Router::csrfField() ?>
      <label class="visually-hidden" for="cr">Powód</label><input class="form-control form-control-sm" id="cr" name="reason" placeholder="powód wycofania"><button class="btn btn-sm btn-outline-danger">Wycofaj</button></form>
  <?php elseif ($st === OperationStatus::Signing): ?>
    <p class="small">Czeka na potwierdzenie wniosku przez <b><?= h($op['account_name']) ?></b> (link e-mail + SMS, ważny <?= LD_TOKEN_DAYS ?> dni). Zmiana wejdzie w życie po potwierdzeniu.</p>
    <?= ldPost(route('ld.op.signlink', ['id' => $id]), 'Wyślij link do potwierdzenia ponownie', 'btn-metro') ?>
    <form method="post" action="<?= h(route('ld.op.cancel', ['id' => $id])) ?>" class="d-flex gap-1 mt-3" onsubmit="return confirm('Wycofać wniosek?')"><?= Router::csrfField() ?>
      <label class="visually-hidden" for="cr2">Powód</label><input class="form-control form-control-sm" id="cr2" name="reason" placeholder="powód wycofania"><button class="btn btn-sm btn-outline-danger">Wycofaj</button></form>
  <?php elseif ($st === OperationStatus::Approved): ?>
    <form method="post" action="<?= h(route('ld.op.done', ['id' => $id])) ?>" class="d-grid gap-2"><?= Router::csrfField() ?>
      <label class="form-label small mb-0" for="dn">Co wykonano (u dostawcy / w finansach)</label><textarea class="form-control form-control-sm" id="dn" name="notes" rows="2"></textarea>
      <button class="btn btn-metro green">Oznacz jako wykonane</button></form>
  <?php else: ?>
    <p class="small text-secondary mb-2"><?= h($st->label()) ?>.</p>
  <?php endif; ?>
  <?php if (!in_array($st, [OperationStatus::Pending, OperationStatus::Cancelled, OperationStatus::Signing], true)): ?><div class="mt-3"><?= ldPost(route('ld.op.resend', ['id' => $id]), 'Wyślij dokument ponownie (PDF e-mailem)', 'btn-metro', [], 'Wysłać dokument ponownie?') ?></div><?php endif; ?>
</div></section>
</div>

<?php if ($f === OperationForm::Am20): ?>
<section class="panel" id="am20-1">
  <h2 class="mt-0"><span class="sym" style="background:#008a00">AM20-1</span> Wykaz dokumentów księgowych <?= $st === OperationStatus::Signing ? '— formularz elektroniczny (do potwierdzenia przez osobę)' : '' ?></h2>
  <?php if ($st === OperationStatus::Signing): ?>
    <form method="post" action="<?= h(route('ld.op.am20annex', ['id' => $id])) ?>"><?= Router::csrfField() ?>
      <?php $docs = (array)($op['request']['am20_1'] ?? []); $brak = !empty($op['request']['am20_1_brak']); $errAm = ''; include __DIR__ . '/_am20_1_table.php'; ?>
      <button class="btn btn-metro green mt-2">Zapisz AM20-1</button>
    </form>
  <?php else: $am = (array)($op['request']['am20_1'] ?? []); $sm = LicenseResources::accountingSummary($am); ?>
    <?php if (!$am): ?><p class="mb-0"><?= !empty($op['request']['am20_1_brak']) ? 'FEER nie wystawiała dokumentów za tę licencję.' : '—' ?></p><?php else: ?>
    <table class="table table-sm small mb-1"><thead><tr><th>Rodzaj</th><th>Numer</th><th>Data</th><th class="text-end">Kwota</th></tr></thead><tbody>
      <?php foreach ($am as $dd): ?><tr><td><?= h($dd['typ']) ?></td><td><b><?= h($dd['numer']) ?></b></td><td><?= h(ldDate($dd['data'])) ?></td><td class="text-end"><?= $dd['kwota'] !== '' ? h(LicenseOperationService::money((float)$dd['kwota'])) : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <p class="small mb-0">Razem <b><?= h(LicenseOperationService::money($sm['sum'])) ?></b> · pierwsza faktura <b><?= h(ldDate($sm['first_invoice']) ?: '—') ?></b> · ostatnia <b><?= h(ldDate($sm['last_invoice']) ?: '—') ?></b></p><?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($history): ?>
<section class="panel">
  <h2 class="mt-0">Historia płatników licencji</h2>
  <table class="table table-sm small mb-0"><thead><tr><th>Od</th><th>Płatnik</th><th>Źródło</th><th>Pozycja</th><th>Sposób</th><th>Kwota</th></tr></thead><tbody>
  <?php foreach ($history as $hrow): ?><tr><td><?= h(ldDate($hrow['effective_from'])) ?></td><td><?= h($hrow['payer']) ?></td><td><?= h($hrow['funding_source']) ?></td><td><?= h($hrow['budget_position']) ?></td>
    <td><?= $hrow['payment_mode'] === 'zajecia' ? 'doliczenie do zajęć' : ($hrow['payment_mode'] === 'rachunek' ? 'rachunek ' . h(LicenseOperationService::nrb($hrow['bank_account'])) : '—') ?></td><td><?= (float)$hrow['amount'] > 0 ? h(LicenseOperationService::money((float)$hrow['amount'])) : '—' ?></td></tr><?php endforeach; ?>
  </tbody></table>
</section>
<?php endif; ?>

<?php $corrScope = 'operation'; $corrId = $id; $corrTarget = $op; include __DIR__ . '/_korektaForm.php'; ?>
<section class="panel" id="detail-panel">
  <h2 class="mt-0">Rekord operacji (JSON)</h2>
  <pre class="small mb-0" style="max-height:28rem;overflow:auto;background:#f4f4f4;padding:.75rem"><?= h(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</section>

<div class="row g-3">
<section class="col-lg-6"><div class="panel h-100"><h2 class="mt-0">Dziennik</h2>
  <ol class="list-unstyled small mb-0"><?php foreach ($events as $ev): ?><li class="mb-1"><span class="text-secondary"><?= h(ldDt($ev['created_at'])) ?></span> <b><?= h($ev['actor_name'] ?: 'system') ?></b> — <?= h($ev['note']) ?></li><?php endforeach; ?></ol>
</div></section>
<section class="col-lg-6"><div class="panel h-100"><h2 class="mt-0">Powiadomienia</h2>
  <?php if (!$notifs): ?><p class="small text-secondary mb-0">Brak.</p><?php else: ?>
  <table class="table table-sm small mb-0"><tbody><?php foreach ($notifs as $n): $nt = LicenseNotificationType::tryFrom((string)$n['type']); ?>
    <tr><td class="text-nowrap"><?= h(ldDt($n['created_at'])) ?></td><td><?= h($nt?->label() ?? $n['type']) ?></td><td><?= $n['channel'] === 'sms' ? 'SMS' : 'e-mail' ?><?= $n['is_fallback'] ? ' (fallback)' : '' ?></td><td><?= h($n['recipient']) ?></td><td><?= h(LicenseNotificationService::statusLabel((string)$n['status'])) ?></td></tr>
  <?php endforeach; ?></tbody></table><?php endif; ?>
</div></section>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
