<?php
if (!isset($P, $actor)) { header('Location: /karty30/ti/dydaktyk/claudeAbonament.php', true, 302); exit; }   // widok tylko przez router modułu (bezpośredni adres pliku → panel)
/**
 * modules/claude_billing/view/show.php — karta abonamentu (panel dydaktyka › Płatne licencje): stan, wniosek (te same kroki co w Helpdesku),
 * dane do faktury, opłaty z dokumentami PDF, dzień dokumentu dla tej osoby, zakończenie, dziennik. Szata jak ekran zarządzania (_style.php).
 * Zmienne: $sub, $charges, $events, $s, $gaps, $paidThrough, $actor, $product, $P.
 */
$title = $P['label'] . ': ' . $sub['client_name'];
$kierCur = 'claudeAbonament.php';
include dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_head.php';
include __DIR__ . '/_style.php';
$stCls = ['requested' => 'due', 'active' => 'ok', 'suspended' => 'bad', 'ended' => 'off'];
$dd = ClaudeBilling::dueDay($sub, $s);
$firstM = array_values(array_filter($charges, fn($x) => ($x['kind'] ?? 'month') === 'month'));
$first = $firstM ? $firstM[count($firstM) - 1] : null;   // najstarsza opłata miesięczna (pierwsza po wniosku / włączeniu)
?>
<div class="cbx">
<p class="small mb-1"><a href="<?= h(route('cb.index', ['product' => $product, 'tab' => $sub['status'] === 'requested' ? 'wnioski' : 'abonamenty'])) ?>">← Płatne licencje: <?= h($P['label']) ?></a></p>
<h1 class="mb-3"><?= h($sub['client_name']) ?> <span class="cb-tag <?= h($stCls[$sub['status']] ?? 'off') ?> align-middle"><?= h(ClaudeBilling::STATUS[$sub['status']] ?? $sub['status']) ?></span></h1>

<section class="panel">
  <dl class="cb-info row mb-0">
    <div class="col-md-4"><dt><?= $sub['status'] === 'requested' ? 'Wniosek z' : 'Włączony od' ?></dt><dd><?= h(ClaudeBilling::date($sub['activated_on'])) ?><span class="d-block small text-secondary"><?= h($sub['activated_by']) ?></span></dd></div>
    <div class="col-md-4"><dt>Dokument co miesiąc</dt><dd><?= $dd === 1 ? 'proforma 1. dnia miesiąca' : 'proforma ok. ' . $dd . '. dnia miesiąca' ?>, termin <?= ClaudeBilling::TERM_DAYS ?> dni<?= (int)$sub['free'] ? '<span class="d-block small"><b>bez opłat</b> (uczestnik „bez rozliczeń”)</span>' : '' ?></dd></div>
    <div class="col-md-4"><dt>Opłacony do</dt><dd><?= h(ClaudeBilling::date($paidThrough)) ?></dd></div>
    <div class="col-md-4"><dt>Konto Office</dt><dd><?php require_once dirname(__DIR__) . '/logic/LicenceOffice.php'; $oa = LicenceOffice::summary((int)$sub['client_id']); ?>
      <?= $oa['upn'] !== '' ? '<span class="font-monospace">' . h($oa['upn']) . '</span> ' : '' ?><span class="cb-tag <?= $oa['ok'] ? 'ok' : ($oa['state'] === 'error' ? 'bad' : 'off') ?>"><?= h($oa['ok'] ? 'aktywne' : $oa['label']) ?></span>
      <?php if ($oa['note'] !== '' || $oa['checked'] !== ''): ?><span class="d-block small text-secondary"><?= h(trim($oa['note'] . ($oa['checked'] !== '' ? ', sprawdzono ' . $oa['checked'] : ''), ', ')) ?></span><?php endif; ?>
      <?php if (!$oa['ok']): ?><form method="post" action="<?= h(route('cb.office', ['cid' => (int)$sub['client_id']])) ?>" class="mt-1"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>"><input type="hidden" name="back" value="show:<?= (int)$sub['id'] ?>"><button class="cb-btn ghost" type="submit">Sprawdź w tenancie EDU / załóż</button></form><?php endif; ?></dd></div>
    <div class="col-md-4"><dt>Zgłoszenie w Helpdesku</dt><dd><?= (int)($sub['helpdesk_ticket_id'] ?? 0) ? '<a href="' . h(APP_URL) . '/helpdesk/view.php?id=' . (int)$sub['helpdesk_ticket_id'] . '" target="_blank" rel="noopener">' . h($sub['helpdesk_number'] ?: 'otwórz') . '<span class="visually-hidden"> (nowa karta)</span></a>' : h($sub['helpdesk_number'] ?: '—') ?></dd></div>
    <div class="col-md-8"><dt>Zasady opłat</dt><dd><details><summary class="small">zaakceptowane <?= h(ClaudeBilling::date($sub['terms_accepted_at'])) ?><?= $sub['terms_ip'] ? ', IP ' . h($sub['terms_ip']) : '' ?></summary><p class="small mb-0 mt-1"><?= h($sub['terms_text']) ?></p></details></dd></div>
    <?php if ($sub['status'] === 'ended'): ?><div class="col-12"><dt>Zakończony</dt><dd><?= h(ClaudeBilling::date($sub['ended_on'])) ?>: <?= h($sub['ended_reason']) ?></dd></div><?php endif; ?>
  </dl>
</section>

<?php require_once dirname(__DIR__) . '/logic/LicenceCard.php'; $pmCard = LicenceCard::active((int)$sub['client_id']); $pmMode = LicenceCard::mode($sub); $pmCons = LicenceCard::consents((int)$sub['client_id']); ?>
<section class="panel" aria-labelledby="pm-h">
  <h2 class="mt-0" id="pm-h">Płatność</h2>
  <div class="row g-3">
    <div class="col-md-5">
      <form method="post" action="<?= h(route('cb.paymode', ['id' => (int)$sub['id']])) ?>" class="d-flex gap-2 align-items-end"><?= Router::csrfField() ?>
        <div class="flex-grow-1"><label class="form-label small" for="pm-sel">Sposób płatności dla tej osoby</label>
          <select class="form-select form-select-sm" id="pm-sel" name="pay_mode">
            <option value=""<?= ($sub['pay_mode'] ?? '') === '' ? ' selected' : '' ?>>jak w ustawieniach (<?= h(LicenceCard::PAY_MODES[$s['pay_mode']] ?? '') ?>)</option>
            <?php foreach (LicenceCard::PAY_MODES as $pmk => $pml): ?><option value="<?= h($pmk) ?>"<?= ($sub['pay_mode'] ?? '') === $pmk ? ' selected' : '' ?>><?= h($pml) ?></option><?php endforeach; ?>
          </select></div>
        <button class="cb-btn ghost" type="submit">Zapisz</button></form>
      <p class="small text-secondary mb-0 mt-1">Teraz: <?= h(LicenceCard::PAY_MODES[$pmMode]) ?>.</p>
    </div>
    <div class="col-md-7">
      <div class="small"><b>Karta:</b> <?= $pmCard ? h(LicenceCard::label($pmCard)) . ', podpięta ' . h(ClaudeBilling::date($pmCard['created_at'])) : 'brak (podpina ją uczestnik w Panelu Licencji › Rozliczenia)' ?></div>
      <?php if ($pmCard): ?>
        <details class="mt-1"><summary class="small">Odepnij kartę (wycofanie zgody)</summary>
          <form method="post" action="<?= h(route('cb.card.remove', ['cid' => (int)$sub['client_id']])) ?>" class="mt-1"><?= Router::csrfField() ?><input type="hidden" name="sub_id" value="<?= (int)$sub['id'] ?>">
            <p class="small mb-1">Np. gdy uczestnik poprosił o to e-mailem. Kolejne opłaty: proforma i przelew. To nie jest zakończenie abonamentu.</p>
            <button class="cb-btn danger" type="submit">Odepnij kartę</button></form></details>
      <?php endif; ?>
      <?php if ($pmCons): ?><ul class="list-unstyled small mt-2 mb-0">
        <?php foreach ($pmCons as $z): ?><li>Zgoda #<?= (int)$z['id'] ?> · <?= h(date('d.m.Y H:i:s', strtotime((string)$z['accepted_at']))) ?> · IP <?= h((string)$z['ip']) ?>
          · <?= $z['withdrawn_at'] ? 'wycofana ' . h(ClaudeBilling::date($z['withdrawn_at'])) . ($z['withdrawn_by'] !== '' ? ' (' . h((string)$z['withdrawn_by']) . ')' : '') : ($z['card_id'] ? 'aktywna' : 'płatność niedokończona') ?>
          · <a href="<?= h(route('cb.consent', ['id' => (int)$z['id']])) ?>" download>PDF<span class="visually-hidden"> zgody #<?= (int)$z['id'] ?></span></a><?= $z['sfile_id'] ? ' · w teczce uczestnika' : '' ?></li><?php endforeach; ?>
      </ul><?php endif; ?>
    </div>
  </div>
</section>

<?php if ($sub['status'] === 'requested'): $sid = (int)$sub['id']; $paid = $first && $first['status'] === 'paid'; $inv = $first ? (string)$first['invoice_number'] : ''; ?>
<section class="panel" aria-labelledby="wn-h">
  <h2 class="mt-0" id="wn-h">Wniosek: następny krok</h2>
  <p class="cb-explain mb-2">Proforma <?= $first && $first['proforma_number'] !== '' ? h($first['proforma_number']) : '(w przygotowaniu)' ?> → wpłata<?= $paid ? ' (jest)' : '' ?> → faktura VAT<?= $inv !== '' ? ' (' . h($inv) . ')' : '' ?> → dostęp. Te same przyciski są w karcie zgłoszenia w Helpdesku.</p>
  <div class="d-flex flex-wrap gap-2 align-items-start">
    <?php if ($first && !$paid): ?>
      <form method="post" action="<?= h(route('cb.req', ['id' => $sid, 'act' => 'paid'])) ?>" class="d-flex gap-1"><?= Router::csrfField() ?><label class="visually-hidden" for="rq-ref">Tytuł przelewu</label><input class="form-control form-control-sm" id="rq-ref" name="ref" placeholder="tytuł / nr (opcja)" style="width:11rem"><button class="cb-btn ghost" type="submit">Wpłata dotarła</button></form>
    <?php elseif ($paid && $inv === ''): ?>
      <form method="post" action="<?= h(route('cb.req', ['id' => $sid, 'act' => 'invoice'])) ?>"><?= Router::csrfField() ?><button class="cb-btn" type="submit">Wystaw fakturę VAT</button></form>
    <?php else: ?>
      <form method="post" action="<?= h(route('cb.req', ['id' => $sid, 'act' => 'activate'])) ?>"><?= Router::csrfField() ?><button class="cb-btn ok" type="submit">Aktywuj dostęp</button></form>
    <?php endif; ?>
    <details><summary class="small">Odrzuć wniosek</summary>
      <form method="post" action="<?= h(route('cb.req', ['id' => $sid, 'act' => 'reject'])) ?>" class="mt-1" style="max-width:420px"><?= Router::csrfField() ?><label class="form-label small mb-0" for="rq-why">Powód (uczestnik dostanie e-mail)</label><textarea class="form-control form-control-sm mb-1" id="rq-why" name="reason" rows="2" required minlength="5"></textarea><button class="cb-btn danger" type="submit">Odrzuć wniosek</button></form>
    </details>
  </div>
</section>
<?php endif; ?>

<section class="panel" aria-labelledby="fv-h">
  <h2 class="mt-0" id="fv-h">Dane do faktury VAT</h2>
  <?php if ($gaps): ?><p class="text-danger fw-semibold">Brakuje: <?= h(implode(', ', $gaps)) ?>. Dokumenty czekają na te dane.</p><?php endif; ?>
  <form method="post" action="<?= h(route('cb.buyer', ['id' => (int)$sub['id']])) ?>">
    <?= Router::csrfField() ?>
    <div class="row g-2">
      <?php foreach (ClaudeBilling::BUYER_FIELDS as $k => $l): ?>
      <div class="col-md-4"><label class="form-label small" for="b-<?= h($k) ?>"><?= h(ucfirst($l)) ?></label><input class="form-control form-control-sm" id="b-<?= h($k) ?>" name="<?= h($k) ?>" value="<?= h((string)$sub[$k]) ?>"<?= isset($gaps[$k]) ? ' aria-invalid="true"' : '' ?>></div>
      <?php endforeach; ?>
    </div>
    <button class="cb-btn mt-2" type="submit">Zapisz dane (i wystaw zaległe dokumenty)</button>
  </form>
</section>

<h2>Opłaty i dokumenty</h2>
<?php if (!$charges): ?><p class="text-secondary">Brak opłat.</p><?php else: $test = false; include __DIR__ . '/_charges.php'; endif; ?>

<?php if ($sub['status'] !== 'ended'): ?>
<div class="row g-3">
  <div class="col-md-6"><section class="panel">
    <h2 class="mt-0">Dzień dokumentu dla tej osoby</h2>
    <form method="post" action="<?= h(route('cb.dueday', ['id' => (int)$sub['id']])) ?>" class="d-flex gap-2 align-items-end"><?= Router::csrfField() ?>
      <div><label class="form-label small" for="dd">Dzień 1–28 (puste = jak w ustawieniach: <?= (int)$s['due_day'] ?>)</label><input class="form-control form-control-sm" id="dd" name="due_day" type="number" min="1" max="28" value="<?= h((string)($sub['due_day'] ?? '')) ?>"></div>
      <button class="cb-btn ghost" type="submit">Zapisz</button></form>
  </section></div>
  <div class="col-md-6"><section class="panel">
    <h2 class="mt-0"><?= $sub['status'] === 'requested' ? 'Zamknij bez aktywacji' : 'Zakończ abonament' ?></h2>
    <form method="post" action="<?= h(route('cb.end', ['id' => (int)$sub['id']])) ?>" class="d-flex gap-2 align-items-end"><?= Router::csrfField() ?>
      <div class="flex-grow-1"><label class="form-label small" for="er">Powód</label><input class="form-control form-control-sm" id="er" name="reason" placeholder="np. koniec kursu"></div>
      <button class="cb-btn danger" type="submit">Zakończ (zgłoszenie do IT)</button></form>
  </section></div>
</div>
<?php endif; ?>

<h2>Dziennik</h2>
<ul class="list-unstyled small">
  <?php foreach ($events as $e): ?><li class="border-bottom py-1"><span class="text-secondary"><?= h(date('d.m.Y H:i', strtotime((string)$e['created_at']))) ?></span> · <?= h($e['text']) ?><?= $e['by_name'] ? ' <span class="text-secondary">(' . h($e['by_name']) . ')</span>' : '' ?></li><?php endforeach; ?>
</ul>
</div>
<?php include dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_foot.php'; ?>
