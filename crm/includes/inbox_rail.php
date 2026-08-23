<?php
/**
 * crm/includes/inbox_rail.php — prawa szpalta Skrzynki CRM: sprawy, oferty, wątek.
 *
 * Ten sam kod obsługuje dwa miejsca: szpaltę obok wiadomości (od 1400 px)
 * i sekcję pod wiadomością na węższych ekranach — stąd partial, nie kopia.
 *
 * Wymaga zmiennych: $rail_cases, $rail_offers, $rail_thread, $qs, $sel_id.
 * Sekcje to <details> — rozwijanie działa z klawiatury i bez JS-a.
 */
$rail_cases  = $rail_cases  ?? [];
$rail_offers = $rail_offers ?? [];
$rail_thread = $rail_thread ?? [];
?>
<?php if ($rail_cases): ?>
<details class="ib-card" open>
  <summary>
    <i class="bi bi-briefcase-fill" style="color:#1D4ED8" aria-hidden="true"></i>
    Sprawy kontaktu
    <span class="ib-cnt2"><?= count($rail_cases) ?></span>
    <i class="bi bi-chevron-right ib-caret" aria-hidden="true"></i>
  </summary>
  <div class="ib-card-body">
    <?php foreach ($rail_cases as $c): ?>
    <a class="ib-rail-item" href="<?= APP_URL ?>/crm/cases/view.php?id=<?= (int)$c['id'] ?>">
      <?= h($c['title']) ?>
      <?php if (!empty($c['status'])): ?>
      <span class="text-muted" style="font-size:.72rem"> · <?= h($c['status']) ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
</details>
<?php endif; ?>

<?php if ($rail_offers): ?>
<details class="ib-card" open>
  <summary>
    <i class="bi bi-file-earmark-text-fill" style="color:#B45309" aria-hidden="true"></i>
    Oferty
    <span class="ib-cnt2"><?= count($rail_offers) ?></span>
    <i class="bi bi-chevron-right ib-caret" aria-hidden="true"></i>
  </summary>
  <div class="ib-card-body">
    <?php foreach ($rail_offers as $o): ?>
    <a class="ib-rail-item" href="<?= APP_URL ?>/crm/offers/view.php?id=<?= (int)$o['id'] ?>">
      <?= h($o['offer_number']) ?>
      <span class="text-muted" style="font-size:.72rem">
        · <?= h(number_format((float)$o['total_gross'], 0, ',', ' ')) ?> <?= h($o['currency']) ?>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
</details>
<?php endif; ?>

<?php if ($rail_thread): ?>
<details class="ib-card" open>
  <summary>
    <i class="bi bi-chat-left-text-fill" style="color:#0F766E" aria-hidden="true"></i>
    Wątek
    <span class="ib-cnt2"><?= count($rail_thread) ?></span>
    <i class="bi bi-chevron-right ib-caret" aria-hidden="true"></i>
  </summary>
  <div class="ib-card-body">
    <?php foreach ($rail_thread as $t): $cur = (int)$t['id'] === (int)$sel_id; ?>
    <a class="ib-rail-item<?= $cur ? ' fw-bold' : '' ?>" href="?<?= $qs(['msg' => (int)$t['id']]) ?>"
       <?= $cur ? 'aria-current="true"' : '' ?>>
      <i class="bi bi-<?= $t['direction'] === 'in' ? 'arrow-down-left text-primary' : 'arrow-up-right text-success' ?>"
         aria-hidden="true"></i>
      <?= h(date('d.m.Y H:i', strtotime((string)$t['sent_at']))) ?>
      <?php $tno = crm_msg_no((int)$t['id'], $t['msg_no'] ?? null); ?>
      <?php if ($tno !== ''): ?><span class="ib-no">#<?= h($tno) ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
</details>
<?php endif; ?>
