<?php
if (!isset($hd_lr, $ticket)) return;   // tylko z helpdesk/_detail.php
/**
 * modules/claude_billing/view/_hd_card.php — karta „Wniosek o płatną licencję” w zgłoszeniu Helpdesk (wniosek z kreatora w panelu licencji).
 * Kroki: proforma (Fakturownia) → wpłata → faktura VAT → dostęp. Przyciski idą do helpdesk/licencjeWniosek.php.
 * Zmienne: $hd_lr (LicenceRequest::forTicket), $ticket.
 */
$lrSub = $hd_lr['sub']; $lrCh = $hd_lr['charge']; $lrP = $hd_lr['P'];
$lrReq = $lrSub['status'] === 'requested';
$lrPaid = $lrCh && $lrCh['status'] === 'paid';
$lrInv = $lrCh ? (string)$lrCh['invoice_number'] : '';
$lrSym = fn(string $n) => str_starts_with($n, 'SYM-');
$lrForm = function (string $act, string $label, string $cls, string $extra = '') use ($ticket, $lrSub) {
    return '<form method="post" action="licencjeWniosek.php" class="mt-2">' . '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">'
        . '<input type="hidden" name="ticket_id" value="' . (int)$ticket['id'] . '"><input type="hidden" name="sub_id" value="' . (int)$lrSub['id'] . '">'
        . '<input type="hidden" name="act" value="' . h($act) . '">' . $extra . '<button type="submit" class="btn btn-sm ' . h($cls) . ' w-100">' . h($label) . '</button></form>';
};
$lrStep = fn(bool $done, string $txt) => '<li class="' . ($done ? 'text-success' : 'text-muted') . '"><i class="bi ' . ($done ? 'bi-check-circle-fill' : 'bi-circle') . ' me-1" aria-hidden="true"></i>' . $txt . '<span class="visually-hidden">' . ($done ? ' (zrobione)' : ' (do zrobienia)') . '</span></li>';
?>
<div class="hd-side-card" id="hd-licencja">
  <div class="hd-side-card-head"><i class="bi bi-key"></i>Wniosek o płatną licencję</div>
  <div class="hd-side-card-body" style="font-size:.8rem">
    <div class="fw-semibold"><?= h($lrP['label']) ?>: <?= h((string)$lrSub['client_name']) ?></div>
    <div class="text-muted mb-2">Stan: <?= h(ClaudeBilling::STATUS[$lrSub['status']] ?? $lrSub['status']) ?><?= $hd_lr['virtual'] ? ' · konto testowe (dokumenty w symulacji)' : '' ?></div>
    <ol class="list-unstyled mb-2">
      <?php if ($lrCh && ($lrCh['pay_mode'] ?? '') === 'card'): require_once dirname(__DIR__) . '/logic/LicenceCard.php'; $lrCard = LicenceCard::active((int)$lrSub['client_id']); ?>
      <?= $lrStep((bool)$lrCard, $lrCard ? 'Płatność kartą: ' . h(LicenceCard::label($lrCard)) . ' (zgoda w dokumentach uczestnika)' : 'Płatność kartą: uczestnik jeszcze nie podpiął karty (bez proformy, FVAT po wpłacie)') ?>
      <?php else: ?>
      <?= $lrStep($lrCh && $lrCh['proforma_number'] !== '', 'Proforma ' . h($lrCh['proforma_number'] ?? '') . ($lrCh ? ', ' . h(ClaudeBilling::zl((float)$lrCh['amount_pln'])) . ', termin ' . h(ClaudeBilling::date($lrCh['due_on'])) : '')
          . ($lrCh && $lrCh['proforma_view_url'] && !$lrSym((string)$lrCh['proforma_number']) ? ' · <a href="' . h($lrCh['proforma_view_url']) . '" target="_blank" rel="noopener">podgląd<span class="visually-hidden"> proformy (nowa karta)</span></a>' : '')) ?>
      <?php endif; ?>
      <?= $lrStep($lrPaid, $lrPaid ? 'Wpłata dotarła ' . h(ClaudeBilling::date((string)$lrCh['paid_at'])) . ' (' . h((string)$lrCh['paid_via']) . ')' : 'Wpłata: jeszcze nie ma') ?>
      <?= $lrStep($lrInv !== '', $lrInv !== '' ? 'Faktura VAT ' . h($lrInv) . ($lrSym($lrInv) ? ' (symulacja)' : '') : 'Faktura VAT: do wystawienia po wpłacie') ?>
      <?= $lrStep(!$lrReq && $lrSub['status'] !== 'ended', !$lrReq && $lrSub['status'] !== 'ended' ? 'Dostęp aktywny od ' . h(ClaudeBilling::date($lrSub['activated_on'])) : 'Dostęp: do aktywacji') ?>
    </ol>
    <?php if ($lrCh): $lrDl = fn(string $d) => 'licencjeWniosek.php?ticket_id=' . (int)$ticket['id'] . '&amp;pdf=' . $d; ?>
    <div class="d-flex flex-wrap gap-1 mb-2">
      <?php foreach (ClaudeInvoice::DOC_LABELS as $d => $dl): if ((string)$lrCh[$d . '_number'] === '') continue; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= $lrDl($d) ?>" download><i class="bi bi-download me-1" aria-hidden="true"></i><?= h($dl) ?> PDF<span class="visually-hidden"> <?= h((string)$lrCh[$d . '_number']) ?></span></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php $lrO = $hd_lr['office'] ?? ['upn' => '', 'ok' => false, 'label' => '', 'state' => '', 'checked' => '']; ?>
    <div class="mb-1">Konto Office: <?= $lrO['upn'] !== '' ? '<span class="font-monospace">' . h($lrO['upn']) . '</span>' : '<span class="text-danger">brak imienia lub nazwiska w kartotece</span>' ?>
      (<?= h($lrO['ok'] ? 'aktywne' : $lrO['label']) ?><?= $lrO['checked'] !== '' ? ', sprawdzono ' . h($lrO['checked']) : '' ?>)</div>
    <?php if (!$lrO['ok']): ?>
      <?php if (!empty($lrP['requiresEdu'])): ?><div class="text-muted mb-1"><?= h($lrP['label']) ?> działa z kontem Office. Przy aktywacji SZO sprawdzi tenant EDU i założy konto, jeśli go nie ma (login e-mailem, hasło SMS-em).</div><?php endif; ?>
      <?= $lrForm('office', 'Sprawdź w tenancie EDU / załóż konto Office', 'btn-outline-secondary') ?>
    <?php endif; ?>
    <?php if ($hd_lr['pool'] !== null && !$hd_lr['assignment']): ?><div class="mb-1">Licencja z puli Fundacji przy aktywacji: <?= $hd_lr['pool'] ? 'jest wolne miejsce' : '<span class="text-danger">brak wolnych licencji</span>' ?>.</div><?php endif; ?>
    <div class="text-muted mb-1">Fakturownia: <?= $hd_lr['live'] ? 'podłączona' : 'niepodłączona, dokumenty w symulacji' ?>. Przez Fakturownię idą tylko faktury z licencji. Konfiguracja: Administracja › <a href="<?= h(APP_URL) ?>/admin/invoices_settings.php">Faktury</a>, pola „Konto Fakturowni” i „Token API” (administrator).</div>
    <?php if ($lrReq): ?>
      <?php if ($lrCh && !$lrPaid): ?>
        <?= $lrForm('paid', 'Wpłata dotarła (przelew)', 'btn-outline-primary', '<label class="form-label mb-0 small" for="lr-ref">Tytuł / nr przelewu (opcjonalnie)</label><input class="form-control form-control-sm mb-1" id="lr-ref" name="ref" maxlength="120">') ?>
      <?php elseif ($lrPaid && $lrInv === ''): ?>
        <?= $lrForm('invoice', 'Wystaw fakturę VAT', 'btn-primary') ?>
      <?php elseif ($lrInv !== '' || !$lrCh): ?>
        <?php require_once dirname(__DIR__, 2) . '/ti_licencje_kursant/logic/LicencjeDostep.php'; ?>
        <?= $lrForm('activate', 'Aktywuj dostęp', 'btn-success', '<fieldset class="border rounded p-2 mb-2"><legend class="small fw-semibold float-none w-auto px-1 mb-0">Dane dostępu dla uczestnika</legend>'
            . '<div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="acc_send" value="1" id="lr-acc-send" checked><label class="form-check-label small" for="lr-acc-send">Wyślij e-mail z tymi danymi (hasło SMS-em)</label></div>'
            . LicencjeDostep::fieldsHtml('lr-acc-', (string)$lrSub['product'], (int)$lrSub['client_id'], true) . '</fieldset>') ?>
      <?php endif; ?>
      <details class="mt-2"><summary class="small">Odrzuć wniosek</summary>
        <?= $lrForm('reject', 'Odrzuć wniosek', 'btn-outline-danger', '<label class="form-label mb-0 small" for="lr-why">Powód (uczestnik dostanie go e-mailem)</label><textarea class="form-control form-control-sm mb-1" id="lr-why" name="reason" rows="2" required minlength="5"></textarea>') ?>
      </details>
    <?php endif; ?>
    <a href="<?= h(APP_URL) ?>/karty30/ti/dydaktyk/claudeAbonament.php?_route=<?= h(rawurlencode('/abonament/' . (int)$lrSub['id'])) ?>&amp;product=<?= h(ClaudeBilling::productOf($lrSub)) ?>" class="btn btn-sm btn-outline-secondary mt-2 w-100" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Karta w panelu Płatne licencje</a>
  </div>
</div>
