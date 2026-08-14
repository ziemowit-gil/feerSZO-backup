<?php /* ═══════════════════════ TAB: MATERIAŁY ═══════════════════════ */ ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex align-items-center flex-wrap gap-2">
    <span class="fw-semibold"><i class="bi bi-collection-play me-2"></i>Materiały / eLearning</span>
    <button type="button" class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#addM">
      <i class="bi bi-plus-lg me-1"></i>Dodaj materiał
    </button>
    <?php if ($materials): ?>
    <div class="w-100">
      <input type="search" class="form-control form-control-sm" placeholder="Szukaj materiałów (tytuł, typ, opis…)"
             aria-label="Filtruj materiały" data-dyd-filterbox="dyd-list-materialy">
    </div>
    <?php endif; ?>
  </div>
  <div class="list-group list-group-flush" id="dyd-list-materialy">
    <?php if (!$materials): ?><div class="list-group-item text-body-secondary py-3">Brak materiałów. Kliknij „Dodaj materiał", aby utworzyć pierwszy.</div><?php endif; ?>
    <div class="list-group-item dyd-filter-empty text-body-secondary py-3" style="display:none">Brak materiałów pasujących do wyszukiwania.</div>
    <?php foreach ($materials as $m): $av = k30_ti_avail_status($m['open_at']??null, $m['close_at']??null); ?>
    <div class="list-group-item <?= $m['is_active']?'':'opacity-50' ?>" data-filter-item="1">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="badge badge-soft"><i class="bi bi-<?= h(k30_ti_material_type_icon($m['type'])) ?> me-1"></i><?= h(k30_ti_material_type_label($m['type'])) ?></span>
        <span class="fw-semibold"><?= h($m['title']) ?></span>
        <?php if (!$m['is_active']): ?><span class="badge bg-secondary">ukryte</span><?php endif; ?>
        <?php if ($av['state']==='upcoming'): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-clock me-1"></i><?= h($av['label']) ?></span>
        <?php elseif ($av['state']==='closed'): ?><span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"><i class="bi bi-lock me-1"></i><?= h($av['label']) ?></span><?php endif; ?>
      </div>
      <?php if ($m['session_date']): ?><div class="text-body-secondary small mt-1"><i class="bi bi-calendar-event me-1"></i>lekcja <?= h(date('d.m.Y', strtotime($m['session_date']))) ?></div><?php endif; ?>
      <?php if ($m['description']): ?><div class="small mt-1"><?= nl2br(h(mb_substr($m['description'],0,160))) ?></div><?php endif; ?>
      <div class="mt-2 d-flex gap-2 flex-wrap">
        <?php if ($m['attach_path']): ?><a href="?dl=mat&id=<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-download me-1"></i><?= h(mb_substr($m['attach_name'],0,20)) ?></a><?php endif; ?>
        <?php if ($m['url']): ?><a href="<?= h($m['url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-box-arrow-up-right me-1"></i>link</a><?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="modal" data-bs-target="#edM<?= (int)$m['id'] ?>"><i class="bi bi-pencil me-1"></i>Edytuj</button>
        <form method="post" class="ms-auto" onsubmit="return confirm('Usunąć materiał?')">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="delete_material">
          <input type="hidden" name="_tab" value="materialy">
          <input type="hidden" name="course_id" value="<?= $cur_course ?>">
          <input type="hidden" name="material_id" value="<?= (int)$m['id'] ?>">
          <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń materiał"><i class="bi bi-trash"></i></button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<!-- Wyskakujące okienka: dodawanie + edycja materiałów -->
<div class="modal fade" id="addM" tabindex="-1" aria-labelledby="addM_t" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $matFormHtml(null, 'addM'); ?></div></div>
</div>
<?php foreach ($materials as $m): ?>
<div class="modal fade" id="edM<?= (int)$m['id'] ?>" tabindex="-1" aria-labelledby="edM<?= (int)$m['id'] ?>_t" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $matFormHtml($m, 'edM'.(int)$m['id']); ?></div></div>
</div>
<?php endforeach; ?>
