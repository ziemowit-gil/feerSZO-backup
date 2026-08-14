<?php /* ═══════════════════ TAB: KOMUNIKATY ═══════════════════ */ ?>
<div class="mt-3">
  <h2 class="h5 fw-bold mb-3"><i class="bi bi-megaphone text-warning me-2" aria-hidden="true"></i>Komunikaty placówki</h2>
  <?php if (!$dyd_notices): ?>
  <div class="card border-0 shadow-sm"><div class="card-body text-body-secondary py-4 text-center">
    <i class="bi bi-megaphone fs-2 d-block mb-2" aria-hidden="true"></i>
    Brak aktywnych komunikatów.
  </div></div>
  <?php else: ?>
  <div class="d-flex flex-column gap-2">
  <?php foreach ($dyd_notices as $dn):
    $is_pinned = (int)$dn['is_pinned'];
  ?>
  <div class="card border-0 shadow-sm" style="border-left:4px solid <?= $is_pinned ? '#f59e0b' : '#c2410c80' ?>!important">
    <div class="card-body py-2 px-3">
      <div class="d-flex align-items-start gap-2 flex-wrap">
        <?php if ($is_pinned): ?><i class="bi bi-pin-angle-fill text-warning mt-1" title="Przypięty" aria-hidden="true"></i><?php endif; ?>
        <div class="flex-grow-1">
          <div class="fw-semibold"><?= h($dn['title']) ?></div>
          <?php if ($dn['body']): ?>
          <div class="text-body-secondary mt-1" style="white-space:pre-wrap;font-size:.9rem"><?= h($dn['body']) ?></div>
          <?php endif; ?>
          <div class="mt-1 text-body-secondary" style="font-size:.78rem">
            <i class="bi bi-person me-1" aria-hidden="true"></i><?= h($dn['author_name'] ?? '—') ?>
            <span class="ms-2"><i class="bi bi-clock me-1" aria-hidden="true"></i><?= substr($dn['created_at'],0,16) ?></span>
            <?php if ($dn['expires_at']): ?><span class="ms-2"><i class="bi bi-calendar-x me-1" aria-hidden="true"></i>do <?= h($dn['expires_at']) ?></span><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
