<?php
if (!isset($charges)) { header('Location: /karty30/ti/dydaktyk/claudeAbonament.php', true, 302); exit; }   // widok tylko przez router modułu (bezpośredni adres pliku → panel)
/**
 * modules/claude_billing/view/_charges.php — tabela naliczeń (karta abonamentu i strona testu).
 * Zmienne: $charges, $test (bool), $p24 (opcjonalnie: ['enabled'=>bool,'env'=>string] — test).
 */
$test = $test ?? false;
$cst = ['pending' => '#f0a30a', 'paid' => '#008a00', 'cancelled' => '#647687'];
?>
<div class="table-responsive"><table class="table table-sm align-middle">
  <caption class="visually-hidden">Naliczenia</caption>
  <thead><tr><th scope="col">Okres</th><th scope="col">Naliczono</th><th scope="col" class="text-end">Kwota</th><th scope="col">Termin</th><th scope="col">Stan</th><th scope="col">Dokumenty (Fakturownia, zw)</th><th scope="col">Akcje</th></tr></thead>
  <tbody>
  <?php foreach ($charges as $c): $late = $c['status'] === 'pending' && $c['due_on'] < date('Y-m-d'); ?>
    <tr>
      <td><?= h(ClaudeBilling::periodLabel((string)$c['period'])) ?></td>
      <td><?= h(ClaudeBilling::date($c['issue_on'] ?: $c['created_at'])) ?></td>
      <td class="text-end text-nowrap"><b><?= h(ClaudeBilling::zl((float)$c['amount_pln'])) ?></b><br><small class="text-secondary"><?= h(ClaudeBilling::breakdown($c)) ?> (<?= h($c['rate_table']) ?>)<?= (float)$c['list_pln'] > (float)$c['amount_pln'] ? '<br>regularna ' . h(ClaudeBilling::zl((float)$c['list_pln'])) . ', rabat ' . h(ClaudeBilling::zl((float)$c['list_pln'] - (float)$c['amount_pln'])) : '' ?></small></td>
      <td class="<?= $late ? 'text-danger fw-bold' : '' ?>"><?= h(ClaudeBilling::date($c['due_on'])) ?></td>
      <td><span class="chip" style="background:<?= $cst[$c['status']] ?? '#647687' ?>"><?= h(ClaudeBilling::CHARGE_STATUS[$c['status']] ?? $c['status']) ?></span>
        <?php if ($c['status'] === 'paid'): ?><br><small><?= h(ClaudeBilling::date($c['paid_at'])) ?> · <?= h($c['paid_via']) ?></small><?php endif; ?>
        <?php if ($c['note']): ?><br><small class="text-secondary"><?= h($c['note']) ?></small><?php endif; ?></td>
      <td><?php foreach (['proforma' => 'Proforma', 'order' => 'Zamówienie', 'invoice' => 'Faktura'] as $dk => $dl):
              $no = (string)($c[$dk . '_number'] ?? ''); if ($no === '') continue; $u = (string)($c[$dk . '_view_url'] ?? ''); ?>
          <div class="small"><span class="text-secondary"><?= $dl ?>:</span> <?= $u !== '' ? '<a href="' . h($u) . '" target="_blank" rel="noopener">' . h($no) . '</a>' : h($no) ?></div>
        <?php endforeach; ?>
        <?php if ($c['invoice_error']): ?><div class="text-danger small"><?= h($c['invoice_error']) ?></div><?php endif; ?>
        <?php if ($c['invoice_payload']): ?><details><summary class="small">dane wysłane do Fakturowni</summary><pre class="small bg-light p-2" style="white-space:pre-wrap;max-width:520px"><?= h((string)$c['invoice_payload']) ?></pre></details><?php endif; ?></td>
      <td class="text-nowrap">
        <?php if ($test && $c['status'] === 'pending'): ?>
          <form method="post" action="<?= h(route('cb.test.pay', ['id' => (int)$c['id']])) ?>" class="d-inline"><?= Router::csrfField() ?><button class="btn-metro green btn-sm" type="submit">Symuluj wpłatę Przelewy24</button></form>
          <?php if (!empty($p24['enabled']) && ($p24['env'] ?? '') === 'sandbox'): ?><form method="post" action="<?= h(route('cb.test.p24', ['id' => (int)$c['id']])) ?>" class="d-inline"><?= Router::csrfField() ?><button class="btn-metro btn-sm" type="submit">Zapłać w sandboksie P24</button></form><?php endif; ?>
        <?php elseif (!$test && $c['status'] === 'pending'): ?>
          <form method="post" action="<?= h(route('cb.charge.paid', ['id' => (int)$c['id']])) ?>" class="d-flex gap-1 mb-1"><?= Router::csrfField() ?>
            <label class="visually-hidden" for="via<?= (int)$c['id'] ?>">Sposób</label><select class="form-select form-select-sm" id="via<?= (int)$c['id'] ?>" name="via" style="width:auto"><option>przelew</option><option>gotówka</option><option>Szybki przelew (Fakturownia)</option></select>
            <label class="visually-hidden" for="ref<?= (int)$c['id'] ?>">Odnośnik</label><input class="form-control form-control-sm" id="ref<?= (int)$c['id'] ?>" name="ref" placeholder="nr wyciągu / uwaga" style="width:140px">
            <button class="btn-metro green btn-sm" type="submit">Wpłynęło</button></form>
          <form method="post" action="<?= h(route('cb.charge.cancel', ['id' => (int)$c['id']])) ?>" class="d-flex gap-1"><?= Router::csrfField() ?>
            <label class="visually-hidden" for="rs<?= (int)$c['id'] ?>">Powód</label><input class="form-control form-control-sm" id="rs<?= (int)$c['id'] ?>" name="reason" placeholder="powód anulowania" style="width:200px">
            <button class="btn-metro red btn-sm" type="submit">Anuluj</button></form>
        <?php endif; ?>
        <?php if (!$test && $c['status'] !== 'cancelled' && (($c['status'] === 'pending' && !$c['proforma_number'] && !$c['invoice_number']) || ($c['status'] === 'paid' && !$c['invoice_number']))): ?>
          <form method="post" action="<?= h(route('cb.charge.invoice', ['id' => (int)$c['id']])) ?>" class="mt-1"><?= Router::csrfField() ?><button class="btn-metro btn-sm" type="submit"><?= $c['status'] === 'paid' ? 'Wystaw zamówienie i fakturę (zw)' : 'Wystaw proformę (zw)' ?></button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
