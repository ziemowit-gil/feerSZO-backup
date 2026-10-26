<?php
/**
 * crm/includes/inbox_contact.php — pasek kontaktu pod nagłówkiem wiadomości (skrzynka „kontaktowa”): kto pisze, co o nim wiemy,
 * jak go dodać/przypiąć. Włączany w zakresie crm/inbox.php ($msg, $hidden, $can_write).
 */
?>
<?php /* ── Karta kontaktu: kto pisze i co o nim wiemy (zawsze na górze szpalty) ── */
$rc = $msg['ctx']['contact'] ?? null;
$rc_cand = $msg['ctx']['candidates'] ?? [];
$rc_peer = function_exists('crm_mailbox_peer_email') ? crm_mailbox_peer_email($msg) : '';
?>
<section class="ib-card ib-contact" aria-label="Kontakt">
<?php if ($rc): $ct = CRM_CONTACT_TYPES[$rc['type']] ?? CRM_CONTACT_TYPES['osoba']; ?>
  <div class="ib-ct-top">
    <span class="ib-ct-ico" style="color:<?= h($ct['color']) ?>" aria-hidden="true"><i class="bi <?= h($ct['icon']) ?>"></i></span>
    <div class="ib-ct-main">
      <a class="ib-ct-name" href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$rc['id'] ?>"><?= h($rc['imie_nazwisko']) ?></a>
      <div class="ib-ct-sub"><?= h($ct['label']) ?><?= $rc['organizacja'] ? ' · ' . h($rc['organizacja']) : '' ?><?= $rc['stanowisko'] ? ' · ' . h($rc['stanowisko']) : '' ?></div>
    </div>
    <?php if ($rc['status']): ?><span class="ib-ct-st"><?= h(crm_statuses()[$rc['status']]['label'] ?? $rc['status']) ?></span><?php endif; ?>
  </div>
  <div class="ib-ct-lines">
    <?php if ($rc['email']): ?><a href="mailto:<?= h($rc['email']) ?>"><i class="bi bi-envelope" aria-hidden="true"></i><?= h($rc['email']) ?></a><?php endif; ?>
    <?php if ($rc['telefon']): ?><a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $rc['telefon'])) ?>"><i class="bi bi-telephone" aria-hidden="true"></i><?= h($rc['telefon']) ?></a><?php endif; ?>
    <?php if ($rc['owner_name']): ?><span><i class="bi bi-person-check" aria-hidden="true"></i>Opiekun: <?= h($rc['owner_name']) ?></span><?php endif; ?>
  </div>
  <div class="ib-ct-stats" role="list">
    <span role="listitem"><b><?= (int)$rc['n_msgs'] ?></b> wiad.</span>
    <span role="listitem"><b><?= (int)$rc['n_cases'] ?></b> spraw</span>
    <span role="listitem"><b><?= (int)$rc['n_offers'] ?></b> ofert</span>
    <?php if ($rc['last_at']): ?><span role="listitem">ost. <?= h(date('d.m.Y', strtotime($rc['last_at']))) ?></span><?php endif; ?>
    <?php if (!empty($rc['email_opt_out'])): ?><span role="listitem" class="ib-ct-warn">wypisany z wysyłek</span><?php endif; ?>
  </div>
  <div class="ib-ct-act">
    <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$rc['id'] ?>"><i class="bi bi-person-lines-fill" aria-hidden="true"></i>Karta</a>
    <?php if ($can_write): ?>
    <a href="<?= APP_URL ?>/crm/cases/add.php?contact_id=<?= (int)$rc['id'] ?>"><i class="bi bi-briefcase" aria-hidden="true"></i>Sprawa</a>
    <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$rc['id'] ?>#notes"><i class="bi bi-journal-plus" aria-hidden="true"></i>Notatka</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="ib-ct-top">
    <span class="ib-ct-ico" aria-hidden="true"><i class="bi bi-person-question"></i></span>
    <div class="ib-ct-main">
      <span class="ib-ct-name">Nadawca spoza kontaktów</span>
      <div class="ib-ct-sub"><?= h($rc_peer ?: 'brak adresu') ?></div>
    </div>
  </div>
  <?php if ($can_write && $rc_peer !== ''): ?>
  <form method="post" class="ib-ct-act" style="gap:.35rem"><?= $hidden ?>
    <input type="hidden" name="_op" value="add_contact">
    <button type="submit" class="ib-act ib-act--primary"><i class="bi bi-person-plus" aria-hidden="true"></i>Dodaj do kontaktów</button>
  </form>
  <?php foreach ($rc_cand as $cd): ?>
  <form method="post" class="ib-ct-cand"><?= $hidden ?>
    <input type="hidden" name="_op" value="link_contact"><input type="hidden" name="contact_id" value="<?= (int)$cd['id'] ?>">
    <span class="ib-ct-cname"><?= h($cd['imie_nazwisko']) ?><small><?= h($cd['why']) ?></small></span>
    <button type="submit" class="ib-act" title="Przypnij wiadomości tego nadawcy do tego kontaktu"><i class="bi bi-link-45deg" aria-hidden="true"></i>Przypnij</button>
  </form>
  <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>
</section>

