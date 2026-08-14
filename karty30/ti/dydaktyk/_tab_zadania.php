<?php /* ═══════════════════════ TAB: ZADANIA ═══════════════════════ */ ?>
<?php
  $hw_view = (int)($_GET['hw'] ?? 0);
  $hw_obj  = $hw_view ? k30_ti_homework_get($hw_view) : null;
  if ($hw_obj && (int)$hw_obj['course_id'] !== $cur_course) $hw_obj = null;
?>
<?php if ($hw_obj):
  $hw_subs = k30_ti_homework_submissions($hw_view);
?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex align-items-center flex-wrap gap-2">
    <a href="index.php?course=<?= $cur_course ?>&tab=zadania" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-arrow-left me-1"></i>Wróć do zadań</a>
    <span class="fw-semibold"><i class="bi bi-check2-square me-2"></i>Oddania: <?= h($hw_obj['title']) ?></span>
    <span class="badge bg-secondary ms-auto"><?= count($hw_subs) ?> oddań</span>
  </div>
  <div class="list-group list-group-flush">
    <?php if (!$hw_subs): ?>
    <div class="list-group-item text-body-secondary py-3">Brak oddanych prac dla tego zadania.</div>
    <?php endif; ?>
    <?php foreach ($hw_subs as $s): $graded = ($s['status'] ?? '') === 'graded'; ?>
    <div class="list-group-item">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold"><?= h($s['client_name']) ?></span>
        <?php if ($graded): ?><span class="badge bg-success"><?= h($s['grade'] ?: 'ocenione') ?></span>
        <?php else: ?><span class="badge bg-warning text-dark">do oceny</span><?php endif; ?>
        <span class="text-body-secondary small ms-auto"><i class="bi bi-clock me-1"></i><?= $s['submitted_at'] ? h(date('d.m.Y H:i', strtotime($s['submitted_at']))) : '—' ?></span>
      </div>
      <?php if (trim((string)($s['body'] ?? '')) !== ''): ?>
      <div class="small mt-2 p-2 rounded bg-body-tertiary border" style="white-space:pre-wrap"><?= nl2br(h($s['body'])) ?></div>
      <?php endif; ?>
      <?php if (trim((string)($s['file_path'] ?? '')) !== ''): ?>
      <div class="mt-2"><a href="?dl=sub&id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-download me-1"></i><?= h(mb_substr((string)$s['file_name'], 0, 40)) ?: 'Pobierz plik' ?></a></div>
      <?php endif; ?>
      <form method="post" class="row g-2 mt-1 align-items-end">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="hw_grade">
        <input type="hidden" name="course_id" value="<?= $cur_course ?>">
        <input type="hidden" name="homework_id" value="<?= $hw_view ?>">
        <input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
        <div class="col-12 col-sm-3">
          <label class="form-label small mb-1" for="grd<?= (int)$s['id'] ?>">Ocena</label>
          <input type="text" class="form-control form-control-sm" id="grd<?= (int)$s['id'] ?>" name="grade" value="<?= h($s['grade'] ?? '') ?>" placeholder="np. 4 / 85%">
        </div>
        <div class="col-12 col-sm-7">
          <label class="form-label small mb-1" for="fb<?= (int)$s['id'] ?>">Informacja zwrotna</label>
          <input type="text" class="form-control form-control-sm" id="fb<?= (int)$s['id'] ?>" name="feedback" value="<?= h($s['feedback'] ?? '') ?>" placeholder="komentarz dla kursanta">
        </div>
        <div class="col-12 col-sm-2 d-grid">
          <button class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Zapisz</button>
        </div>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="card-footer small text-body-secondary"><i class="bi bi-info-circle me-1"></i>Ocena liczbowa trafia automatycznie do dziennika ocen (kategoria „Zadanie domowe").</div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex align-items-center flex-wrap gap-2">
    <span class="fw-semibold"><i class="bi bi-journal-check me-2"></i>Zadania domowe</span>
    <button type="button" class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#addH">
      <i class="bi bi-plus-lg me-1"></i>Dodaj zadanie
    </button>
    <?php if ($homeworks): ?>
    <div class="w-100">
      <input type="search" class="form-control form-control-sm" placeholder="Szukaj zadań (tytuł, opis, termin…)"
             aria-label="Filtruj zadania" data-dyd-filterbox="dyd-list-zadania">
    </div>
    <?php endif; ?>
  </div>
  <div class="list-group list-group-flush" id="dyd-list-zadania">
    <?php if (!$homeworks): ?><div class="list-group-item text-body-secondary py-3">Brak zadań. Kliknij „Dodaj zadanie", aby utworzyć pierwsze.</div><?php endif; ?>
    <div class="list-group-item dyd-filter-empty text-body-secondary py-3" style="display:none">Brak zadań pasujących do wyszukiwania.</div>
    <?php foreach ($homeworks as $hw): $av = k30_ti_avail_status($hw['open_at']??null, $hw['close_at']??null); ?>
    <div class="list-group-item <?= $hw['is_active']?'':'opacity-50' ?>" data-filter-item="1">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold"><?= h($hw['title']) ?></span>
        <?php if (!$hw['is_active']): ?><span class="badge bg-secondary">ukryte</span><?php endif; ?>
        <?php if ($av['state']==='upcoming'): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-clock me-1"></i><?= h($av['label']) ?></span>
        <?php elseif ($av['state']==='closed'): ?><span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"><i class="bi bi-lock me-1"></i><?= h($av['label']) ?></span><?php endif; ?>
        <span class="text-body-secondary small ms-auto"><i class="bi bi-inbox me-1"></i><?= (int)$hw['sub_count'] ?> oddań · <?= (int)$hw['graded_count'] ?> ocen.</span>
      </div>
      <?php if ($hw['due_at']): ?><div class="text-body-secondary small mt-1"><i class="bi bi-calendar-check me-1"></i>termin: <?= h(substr($hw['due_at'],0,16)) ?></div><?php endif; ?>
      <?php if ($hw['description']): ?><div class="small mt-1" style="white-space:pre-wrap"><?= nl2br(h($hw['description'])) ?></div><?php endif; ?>
      <?php if (trim((string)($hw['hint'] ?? '')) !== ''): ?><div class="small mt-1 text-info-emphasis" style="white-space:pre-wrap"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i><strong>Podpowiedź:</strong> <?= nl2br(h($hw['hint'])) ?></div><?php endif; ?>
      <div class="mt-2 d-flex gap-2 flex-wrap">
        <?php if ($hw['attach_path']): ?><a href="?dl=hw&id=<?= (int)$hw['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-paperclip me-1"></i>załącznik</a><?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="modal" data-bs-target="#edH<?= (int)$hw['id'] ?>"><i class="bi bi-pencil me-1"></i>Edytuj</button>
        <a href="index.php?course=<?= $cur_course ?>&tab=zadania&hw=<?= (int)$hw['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-check2-square me-1"></i>Oddania / oceny<?php if ((int)$hw['sub_count'] > (int)$hw['graded_count']): ?> <span class="badge text-bg-warning"><?= (int)$hw['sub_count'] - (int)$hw['graded_count'] ?></span><?php endif; ?></a>
        <form method="post" class="ms-auto" onsubmit="return confirm('Usunąć zadanie wraz z oddaniami?')">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="delete_homework">
          <input type="hidden" name="_tab" value="zadania">
          <input type="hidden" name="course_id" value="<?= $cur_course ?>">
          <input type="hidden" name="homework_id" value="<?= (int)$hw['id'] ?>">
          <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń zadanie"><i class="bi bi-trash"></i></button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<!-- Wyskakujące okienka: dodawanie + edycja zadań -->
<div class="modal fade" id="addH" tabindex="-1" aria-labelledby="addH_t" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $hwFormHtml(null, 'addH'); ?></div></div>
</div>
<?php foreach ($homeworks as $hw): ?>
<div class="modal fade" id="edH<?= (int)$hw['id'] ?>" tabindex="-1" aria-labelledby="edH<?= (int)$hw['id'] ?>_t" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered"><div class="modal-content"><?php $hwFormHtml($hw, 'edH'.(int)$hw['id']); ?></div></div>
</div>
<?php endforeach; ?>
<?php endif; /* $hw_obj */ ?>
