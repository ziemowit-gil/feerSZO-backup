<?php
/** Karta pojedynczego materiału w panelu kursanta — oczekuje $m w zasięgu. */
$mav   = k30_ti_avail_status($m['open_at'] ?? null, $m['close_at'] ?? null);
$mopen = $mav['state'] === 'open';
?>
<div class="card <?= $mopen ? '' : 'opacity-75' ?>">
  <div class="card-body py-2">
    <div class="d-flex flex-wrap align-items-start gap-2">
      <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle text-nowrap">
        <i class="bi bi-<?= h(k30_ti_material_type_icon($m['type'])) ?> me-1" aria-hidden="true"></i><?= h(k30_ti_material_type_label($m['type'])) ?>
      </span>
      <div class="flex-grow-1 min-width-0">
        <div class="fw-semibold"><?= h($m['title']) ?>
          <?php if ($mav['state']==='upcoming'): ?><span class="badge text-bg-warning ms-1"><i class="bi bi-clock me-1" aria-hidden="true"></i><?= h($mav['label']) ?></span>
          <?php elseif ($mav['state']==='closed'): ?><span class="badge text-bg-secondary ms-1"><i class="bi bi-lock me-1" aria-hidden="true"></i><?= h($mav['label']) ?></span>
          <?php elseif (($m['close_at'] ?? '')!==''): ?><span class="badge text-bg-light text-dark border ms-1"><?= h($mav['label']) ?></span><?php endif; ?>
        </div>
        <?php if ($m['description']): ?><p class="small mb-1 mt-1" style="white-space:pre-wrap"><?= h($m['description']) ?></p><?php endif; ?>
        <?php if ($mopen): ?>
        <div class="d-flex flex-wrap gap-2 mt-1">
          <?php if ($m['url']): ?>
          <a href="<?= h($m['url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz link
          </a>
          <?php endif; ?>
          <?php if ($m['attach_path']): ?>
          <a href="material_file.php?id=<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-download me-1" aria-hidden="true"></i><?= h(mb_substr($m['attach_name'],0,40)) ?>
          </a>
          <?php endif; ?>
        </div>
        <?php elseif ($mav['state']==='upcoming'): ?>
        <div class="small text-body-secondary mt-1"><i class="bi bi-lock me-1" aria-hidden="true"></i>Materiał będzie dostępny od <?= h(substr($mav['open_at'],0,16)) ?>.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
