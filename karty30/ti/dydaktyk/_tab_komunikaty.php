<?php /* ═══════════════════ TAB: KOMUNIKATY ═══════════════════ */ ?>
<div class="mt-3">
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h2 class="h5 fw-bold mb-0"><i class="bi bi-megaphone text-warning me-2" aria-hidden="true"></i>Komunikaty placówki</h2>
    <?php if ($dyd_notices_unread > 0): ?>
    <form method="post" class="d-inline">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="mark_all_notices">
      <button type="submit" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-check2-all me-1" aria-hidden="true"></i>Oznacz wszystkie jako przeczytane
      </button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!$dyd_notices): ?>
  <div class="card border-0 shadow-sm"><div class="card-body text-body-secondary py-4 text-center">
    <i class="bi bi-megaphone fs-2 d-block mb-2" aria-hidden="true"></i>
    Brak aktywnych komunikatów.
  </div></div>
  <?php else: ?>
  <div class="d-flex flex-column gap-2">
  <?php foreach ($dyd_notices as $dn):
    $is_pinned  = (int)$dn['is_pinned'];
    $is_read    = (int)($dn['is_read'] ?? 0);
    $accent     = $is_pinned ? '#f59e0b' : '#c2410c80';
    $card_class = $is_read ? '' : 'border-primary-subtle bg-primary-subtle';
  ?>
  <div class="card border-0 shadow-sm <?= $card_class ?>" style="border-left:4px solid <?= $accent ?>!important">
    <div class="card-body py-2 px-3">
      <div class="d-flex align-items-start gap-2 flex-wrap">
        <?php if ($is_pinned): ?><i class="bi bi-pin-angle-fill text-warning mt-1" title="Przypięty" aria-hidden="true"></i><?php endif; ?>
        <?php if (!$is_read): ?><i class="bi bi-circle-fill text-primary mt-1" style="font-size:.55rem" title="Nieprzeczytane" aria-hidden="true"></i><?php endif; ?>
        <div class="flex-grow-1">
          <div class="fw-semibold<?= $is_read ? '' : ' fw-bold' ?>"><?= h($dn['title']) ?></div>
          <?php if ($dn['body']): ?>
          <div class="text-body-secondary mt-1" style="white-space:pre-wrap;font-size:.9rem"><?= h($dn['body']) ?></div>
          <?php endif; ?>
          <div class="mt-1 text-body-secondary" style="font-size:.78rem">
            <i class="bi bi-person me-1" aria-hidden="true"></i><?= h($dn['author_name'] ?? '—') ?>
            <span class="ms-2"><i class="bi bi-clock me-1" aria-hidden="true"></i><?= substr($dn['created_at'],0,16) ?></span>
            <?php if ($dn['expires_at']): ?><span class="ms-2"><i class="bi bi-calendar-x me-1" aria-hidden="true"></i>do <?= h($dn['expires_at']) ?></span><?php endif; ?>
          </div>
        </div>
        <?php if (!$is_read): ?>
        <form method="post" class="flex-shrink-0 align-self-start">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="mark_notice">
          <input type="hidden" name="notice_id" value="<?= (int)$dn['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-check2 me-1" aria-hidden="true"></i>Przeczytane
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
