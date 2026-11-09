<?php
/**
 * karty30/ti/kursant/_claude_abonament_view.php — karta „Abonament Claude” w zakładce Licencje panelu uczestnika (modules/claude_billing).
 * Uczestnik z licencją Claude aktywuje dostęp (dane do faktury + akceptacja warunków), widzi naliczenia, płaci (domyślnie portal płatności SZO,
 * informacja o Szybkim przelewie z faktury w Fakturowni i rachunku do wpłat), uzupełnia dane do FV, rezygnuje.
 * Wymaga: $student (konto uczestnika). Formularze → claudeAbonament.php (token panelu _token).
 */
require_once dirname(__DIR__, 3) . '/modules/claude_billing/logic/claudeBilling.php';
$cb_cid = (int)$student['client_id'];
try { $cb = ClaudeBilling::participantView($cb_cid); } catch (\Throwable $e) { $cb = null; error_log('claude view: ' . $e->getMessage()); }
if ($cb && ($cb['hasLicense'] || $cb['sub'])):
    $cb_s = $cb['settings']; $cb_sub = $cb['sub'];
    $cb_flash = $_SESSION['claude_flash'] ?? null; unset($_SESSION['claude_flash']);
    $cb_err = $_SESSION['claude_errors'] ?? []; $cb_old = $_SESSION['claude_old'] ?? []; unset($_SESSION['claude_errors'], $_SESSION['claude_old']);
    $cb_open = $cb_sub && in_array($cb_sub['status'], ['active', 'suspended'], true);
    $cb_buyer = $cb_open ? array_intersect_key($cb_sub, ClaudeBilling::BUYER_FIELDS) : ClaudeBilling::buyerDefaults($cb_cid);
    $cb_buyer = array_merge($cb_buyer, array_intersect_key($cb_old, ClaudeBilling::BUYER_FIELDS));
    $cb_rate = $cb['rate'];
    $cb_acc = $cb['account'];
    $cb_e = fn(string $k) => isset($cb_err[$k]) ? '<div id="cb-e-' . h($k) . '" class="invalid-feedback d-block">' . h($cb_err[$k]) . '</div>' : '';
    $cb_a = fn(string $k) => isset($cb_err[$k]) ? ' aria-invalid="true" aria-describedby="cb-e-' . h($k) . '"' : '';
    $cb_badge = ['active' => 'text-bg-success', 'suspended' => 'text-bg-danger', 'ended' => 'text-bg-secondary'];
    $cb_cbadge = ['pending' => 'text-bg-warning', 'paid' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'];
    $cb_pay = 'platnosci.php';   // domyślnie: portal płatności SZO (Przelewy24 / przelew)
    $cb_buyer_form = function () use ($cb_buyer, $cb_e, $cb_a) { ?>
      <div class="row g-2">
        <?php foreach (ClaudeBilling::BUYER_FIELDS as $k => $label): $req = $k !== 'buyer_tax_no'; ?>
        <div class="<?= $k === 'buyer_name' || $k === 'buyer_street' ? 'col-md-6' : 'col-md-4' ?>">
          <label class="form-label small mb-1" for="cb-<?= h($k) ?>"><?= h(ucfirst($label)) ?><?= $req ? ' *' : '' ?></label>
          <input class="form-control form-control-sm" id="cb-<?= h($k) ?>" name="<?= h($k) ?>" value="<?= h((string)($cb_buyer[$k] ?? '')) ?>"
                 <?= $k === 'buyer_email' ? 'type="email" autocomplete="email"' : ($k === 'buyer_post_code' ? 'inputmode="numeric" placeholder="00-000" autocomplete="postal-code"' : ($k === 'buyer_city' ? 'autocomplete="address-level2"' : ($k === 'buyer_street' ? 'autocomplete="street-address"' : ($k === 'buyer_name' ? 'autocomplete="name"' : 'inputmode="numeric"')))) ?><?= $cb_a($k) ?>>
          <?= $cb_e($k) ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php };
?>
<section id="claude-abonament" class="card mt-4 kp-claude" aria-labelledby="cb-h">
  <div class="card-body">
    <h2 id="cb-h" class="h5 fw-bold mb-1"><i class="bi bi-stars me-1" aria-hidden="true"></i>Abonament Claude <span class="fw-normal text-body-secondary small">(Claude for Nonprofit)</span>
      <?php if ($cb_sub): ?><span class="badge <?= $cb_badge[$cb_sub['status']] ?? 'text-bg-secondary' ?> ms-1"><?= h(ClaudeBilling::STATUS[$cb_sub['status']] ?? $cb_sub['status']) ?></span><?php endif; ?></h2>

    <?php if ($cb_flash): ?><div class="alert alert-<?= $cb_flash[0] === 'ok' ? 'success' : 'danger' ?> mt-2" role="<?= $cb_flash[0] === 'ok' ? 'status' : 'alert' ?>"><?= h($cb_flash[1]) ?></div><?php endif; ?>

    <p class="small text-body-secondary mb-2">
      Opłata miesięczna: <b><?= h(ClaudeBilling::usd($cb_s['usd'])) ?></b> — cena dla organizacji non-profit<?= $cb_s['list_usd'] > 0 ? ' (cena regularna ' . h(ClaudeBilling::usd($cb_s['list_usd'])) . ')' : '' ?>,
      przeliczana po ostatnim kursie <?= h(PkoRates::KINDS[$cb_s['rate_kind']]) ?> PKO BP z dnia naliczenia<?php if ($cb_rate): ?> — ostatnio <?= h(ClaudeBilling::rateFmt((float)$cb_rate['rate'])) ?>/USD, czyli ok. <b><?= h(ClaudeBilling::zl((float)$cb_rate['amount_pln'])) ?></b><?php endif; ?>.
      Naliczenie z fakturą VAT: w dniu aktywacji, potem co miesiąc w dniu aktywacji, nie później niż <?= (int)($cb_sub ? ClaudeBilling::dueDay($cb_sub, $cb_s) : $cb_s['due_day']) ?>. dnia miesiąca. Termin płatności: <?= ClaudeBilling::TERM_DAYS ?> dni.
    </p>

    <?php if (!$cb_open): ?>
      <?php if (!$cb['eligible']['ok']): ?>
        <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= h($cb['eligible']['reason']) ?></div>
      <?php else: ?>
        <form method="post" action="claudeAbonament.php" novalidate class="border-top pt-3">
          <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
          <input type="hidden" name="op" value="activate">
          <h3 class="h6 fw-bold">Aktywuj dostęp do Claude</h3>
          <p class="small mb-2">Dane do faktury VAT (faktura z Fakturowni przychodzi przy każdym naliczeniu). Pola z gwiazdką są wymagane.</p>
          <?php $cb_buyer_form(); ?>
          <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" name="accept" value="1" id="cb-accept"<?= !empty($cb_old['accept']) ? ' checked' : '' ?><?= $cb_a('accept') ?>>
            <label class="form-check-label small" for="cb-accept"><?= h(ClaudeBilling::termsText($cb_s)) ?></label>
            <?= $cb_e('accept') ?>
          </div>
          <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-play-circle me-1" aria-hidden="true"></i>Aktywuj dostęp i akceptuję warunki</button>
          <p class="small text-body-secondary mt-2 mb-0">Po aktywacji Dział IT dostaje zgłoszenie w Helpdesku i włącza Twoje miejsce w Claude; pierwsza opłata pojawi się od razu poniżej i w portalu płatności.</p>
        </form>
      <?php endif; ?>
      <?php if ($cb_sub && $cb_sub['status'] === 'ended'): ?><p class="small text-body-secondary mt-2 mb-0">Poprzedni abonament zakończony <?= h(ClaudeBilling::date($cb_sub['ended_on'])) ?>.</p><?php endif; ?>
    <?php else: ?>
      <dl class="row small mb-2">
        <dt class="col-sm-4">Aktywny od</dt><dd class="col-sm-8"><?= h(ClaudeBilling::date($cb_sub['activated_on'])) ?><?= $cb_sub['helpdesk_number'] ? ' · zgłoszenie IT ' . h($cb_sub['helpdesk_number']) : '' ?></dd>
        <dt class="col-sm-4">Opłacono do</dt><dd class="col-sm-8"><?= h(ClaudeBilling::date($cb['paidThrough'])) ?></dd>
        <?php if ($cb_acc): ?>
        <dt class="col-sm-4">Rachunek do wpłat</dt><dd class="col-sm-8"><span class="font-monospace"><?= h($cb_acc['nrb_fmt']) ?></span> (Fundacja FEER) — w tytule podaj numer faktury</dd>
        <?php endif; ?>
      </dl>
      <?php if ($cb_sub['status'] === 'suspended'): ?>
        <div class="alert alert-danger" role="alert"><i class="bi bi-pause-circle me-1" aria-hidden="true"></i>Dostęp jest wstrzymany z powodu zaległej opłaty. Po wpłacie przywrócimy go automatycznie.</div>
      <?php endif; ?>
      <?php if ($cb['gaps']): ?>
        <div class="alert alert-warning" role="alert"><b>Brakuje danych do faktury VAT:</b> <?= h(implode(', ', $cb['gaps'])) ?>. Uzupełnij je poniżej — bez nich faktura nie zostanie wystawiona.</div>
      <?php endif; ?>

      <div class="alert alert-light border small" role="note">
        <b>Jak zapłacić?</b> Przycisk „Zapłać” prowadzi do portalu płatności SZO (Przelewy24 albo przelew).
        Korzystamy też z <b>Szybkiego przelewu</b> w Fakturowni — na fakturze jest przycisk „Zapłać” (BLIK, szybki przelew; obsługa Autopay, mała opłata po stronie płacącego).
        <?php if ($cb_acc): ?>Możesz też zrobić zwykły przelew na rachunek <span class="font-monospace"><?= h($cb_acc['nrb_fmt']) ?></span>.<?php endif; ?>
      </div>

      <?php if ($cb['charges']): ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle small mb-2">
          <caption class="visually-hidden">Naliczenia za dostęp do Claude</caption>
          <thead><tr><th scope="col">Okres</th><th scope="col" class="text-end">Kwota</th><th scope="col">Termin</th><th scope="col">Status</th><th scope="col">Faktura</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
          <tbody>
          <?php foreach ($cb['charges'] as $ch): $late = $ch['status'] === 'pending' && $ch['due_on'] < date('Y-m-d'); ?>
            <tr>
              <td><?= h(ClaudeBilling::periodLabel((string)$ch['period'])) ?></td>
              <td class="text-end text-nowrap"><b><?= h(ClaudeBilling::zl((float)$ch['amount_pln'])) ?></b><br><span class="text-body-secondary"><?= h(ClaudeBilling::usd((float)$ch['usd'])) ?> × <?= h(ClaudeBilling::rateFmt((float)$ch['rate'])) ?></span></td>
              <td class="text-nowrap<?= $late ? ' text-danger fw-bold' : '' ?>"><?= h(ClaudeBilling::date($ch['due_on'])) ?><?= $late ? ' (po terminie)' : '' ?></td>
              <td><span class="badge <?= $cb_cbadge[$ch['status']] ?? 'text-bg-secondary' ?>"><?= h(ClaudeBilling::CHARGE_STATUS[$ch['status']] ?? $ch['status']) ?></span></td>
              <td><?php if ($ch['invoice_view_url']): ?><a href="<?= h($ch['invoice_view_url']) ?>" target="_blank" rel="noopener"><?= h($ch['invoice_number']) ?><span class="visually-hidden"> (otwiera się w nowej karcie)</span></a>
                  <?php elseif ($ch['invoice_number']): ?><?= h($ch['invoice_number']) ?><?php else: ?><span class="text-body-secondary">w przygotowaniu</span><?php endif; ?></td>
              <td class="text-nowrap">
                <?php if ($ch['status'] === 'pending'): ?>
                  <a class="btn btn-primary btn-sm" href="<?= h($cb_s['pay_redirect'] === 'fakturownia' && $ch['invoice_view_url'] ? $ch['invoice_view_url'] : $cb_pay) ?>"<?= $cb_s['pay_redirect'] === 'fakturownia' && $ch['invoice_view_url'] ? ' target="_blank" rel="noopener"' : '' ?>>Zapłać<span class="visually-hidden"> za <?= h(ClaudeBilling::periodLabel((string)$ch['period'])) ?></span></a>
                  <?php if ($ch['invoice_view_url'] && $cb_s['pay_redirect'] !== 'fakturownia'): ?><a class="btn btn-outline-secondary btn-sm" href="<?= h($ch['invoice_view_url']) ?>" target="_blank" rel="noopener">Szybki przelew<span class="visually-hidden"> (faktura w Fakturowni, nowa karta)</span></a><?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
            <?php if ($ch['status'] === 'pending' && $cb_acc): ?><tr class="table-light"><td colspan="6" class="small text-body-secondary">Przelew tradycyjny: <span class="font-monospace"><?= h($cb_acc['nrb_fmt']) ?></span>, tytuł: <b><?= h(ClaudeBilling::transferTitle($ch)) ?></b></td></tr><?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <details class="mt-2"<?= $cb['gaps'] || $cb_err ? ' open' : '' ?>>
        <summary class="small fw-bold">Dane do faktury VAT</summary>
        <form method="post" action="claudeAbonament.php" novalidate class="mt-2">
          <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
          <input type="hidden" name="op" value="buyer">
          <?php $cb_buyer_form(); ?>
          <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Zapisz dane do faktury</button>
        </form>
      </details>

      <details class="mt-2">
        <summary class="small fw-bold">Zrezygnuj z dostępu do Claude</summary>
        <form method="post" action="claudeAbonament.php" class="mt-2">
          <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
          <input type="hidden" name="op" value="cancel">
          <p class="small mb-2">Kolejne opłaty nie będą naliczane, a Dział IT wyłączy Twoje miejsce w Claude po opłaconym okresie<?= $cb['paidThrough'] ? ' (do ' . h(ClaudeBilling::date($cb['paidThrough'])) . ')' : '' ?>. Zaległe opłaty pozostają do uregulowania.</p>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="cb-confirm" required><label class="form-check-label small" for="cb-confirm">Potwierdzam rezygnację.</label></div>
          <button type="submit" class="btn btn-outline-danger btn-sm mt-2">Zrezygnuj</button>
        </form>
      </details>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
