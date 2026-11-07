<?php /* ═══════════════════════ TAB: ZADANIA ═══════════════════════ */ ?>
<?php
  $hw_view = (int)($_GET['hw'] ?? 0);
  $hw_table = (($_GET['widok'] ?? '') === 'tabela');
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
    <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Widok zadań">
      <a href="index.php?course=<?= $cur_course ?>&tab=zadania" class="btn btn-outline-secondary<?= $hw_table ? '' : ' active' ?>"<?= $hw_table ? '' : ' aria-current="true"' ?>><i class="bi bi-list-ul me-1"></i>Lista</a>
      <a href="index.php?course=<?= $cur_course ?>&tab=zadania&widok=tabela" class="btn btn-outline-secondary<?= $hw_table ? ' active' : '' ?>"<?= $hw_table ? ' aria-current="true"' : '' ?>><i class="bi bi-table me-1"></i>Tabela</a>
    </div>
    <?php if (count($homeworks) > 1): ?>
    <form method="post" onsubmit="return confirm('Ukryć wszystkie zadania poza najnowszym? Kursanci przestaną je widzieć.')">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="archive_previous_homework">
      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
      <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-archive me-1"></i>Archiwizuj poprzednie</button>
    </form>
    <?php endif; ?>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addH">
      <i class="bi bi-plus-lg me-1"></i>Dodaj zadanie
    </button>
    <?php if ($homeworks): ?>
    <div class="w-100">
      <input type="search" class="form-control form-control-sm" placeholder="Szukaj zadań (tytuł, opis, termin…)"
             aria-label="Filtruj zadania" data-dyd-filterbox="dyd-list-zadania">
    </div>
    <?php endif; ?>
  </div>
  <?php if ($hw_table): ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <caption class="visually-hidden">Zadania domowe kursu, od najnowszej daty lekcji</caption>
      <thead class="table-light">
        <tr>
          <th scope="col">Data lekcji</th>
          <th scope="col">Tytuł</th>
          <th scope="col">Termin</th>
          <th scope="col">Status</th>
          <th scope="col" class="text-end">Oddania</th>
          <th scope="col" class="text-end">Ocenione</th>
          <th scope="col"><span class="visually-hidden">Akcje</span></th>
        </tr>
      </thead>
      <tbody id="dyd-list-zadania">
        <?php if (!$homeworks): ?><tr><td colspan="7" class="text-body-secondary py-3">Brak zadań. Kliknij „Dodaj zadanie", aby utworzyć pierwsze.</td></tr><?php endif; ?>
        <tr class="dyd-filter-empty" style="display:none"><td colspan="7" class="text-body-secondary py-3">Brak zadań pasujących do wyszukiwania.</td></tr>
        <?php foreach ($homeworks as $hw): $av = k30_ti_avail_status($hw['open_at']??null, $hw['close_at']??null);
              $pending = (int)$hw['sub_count'] - (int)$hw['graded_count']; ?>
        <tr data-filter-item="1" class="<?= $hw['is_active']?'':'text-body-secondary' ?>">
          <td class="text-nowrap"><?= !empty($hw['lesson_date']) ? h(date('d.m.Y', strtotime($hw['lesson_date']))) : '—' ?></td>
          <td class="fw-semibold"><?= h($hw['title']) ?><?php if ($hw['attach_path']): ?> <i class="bi bi-paperclip" title="Ma załącznik"></i><span class="visually-hidden">(załącznik)</span><?php endif; ?></td>
          <td class="text-nowrap"><?= $hw['due_at'] ? h(substr($hw['due_at'],0,16)) : '—' ?></td>
          <td>
            <?php if (!$hw['is_active']): ?><span class="badge bg-secondary">ukryte</span><?php endif; ?>
            <?php if ($av['state']==='upcoming'): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><?= h($av['label']) ?></span><?php endif; ?>
            <?php if ($av['state']==='closed'): ?><span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"><?= h($av['label']) ?></span><?php endif; ?>
            <?php if ($hw['is_active'] && $av['state']!=='upcoming' && $av['state']!=='closed'): ?><span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">aktywne</span><?php endif; ?>
          </td>
          <td class="text-end"><?= (int)$hw['sub_count'] ?></td>
          <td class="text-end"><?= (int)$hw['graded_count'] ?><?php if ($pending > 0): ?> <span class="badge text-bg-warning" title="Do oceny"><?= $pending ?><span class="visually-hidden"> do oceny</span></span><?php endif; ?></td>
          <td class="text-end text-nowrap">
            <a href="index.php?course=<?= $cur_course ?>&tab=zadania&hw=<?= (int)$hw['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="Oddania / oceny"><i class="bi bi-check2-square"></i><span class="visually-hidden">Oddania / oceny: <?= h($hw['title']) ?></span></a>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="modal" data-bs-target="#edH<?= (int)$hw['id'] ?>" title="Edytuj"><i class="bi bi-pencil"></i><span class="visually-hidden">Edytuj: <?= h($hw['title']) ?></span></button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
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
      <?php if ($hw['description']): ?><div class="small mt-1 hw-desc"><?= k30_hw_desc_render($hw['description']) ?></div><?php endif; ?>
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
  <?php endif; /* $hw_table */ ?>
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
<style>.hw-desc p{margin:0 0 .4rem}.hw-desc pre{background:var(--bs-tertiary-bg);padding:.4rem .6rem;border-radius:.25rem;white-space:pre-wrap}.hw-desc h3{font-size:1.05rem}.hw-desc h4{font-size:.95rem}</style>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function(){
  // Edytor WYSIWYG opisu zadania — inicjalizowany dopiero po pokazaniu modala (textarea w display:none łamie TinyMCE)
  document.addEventListener('shown.bs.modal', function(e){
    var ta = e.target.querySelector && e.target.querySelector('textarea.hw-desc-editor');
    if (!ta || !window.tinymce || tinymce.get(ta.id)) return;
    tinymce.init({
      target: ta, license_key: 'gpl', promotion: false, branding: false, menubar: false,
      plugins: 'lists link code', height: 260, entity_encoding: 'raw',
      toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | blockquote | link | code | removeformat',
      block_formats: 'Akapit=p; Nagłówek=h3; Podnagłówek=h4; Kod=pre',
      setup: function(ed){ ed.on('change', function(){ ed.save(); }); }
    });
  });
  // okna TinyMCE (link, kod) w modalu Bootstrapa — bez tego pola nie dostają fokusu
  document.addEventListener('focusin', function(e){
    if (e.target.closest && e.target.closest('.tox-tinymce-aux, .tox-dialog, .tox-menu')) e.stopImmediatePropagation();
  }, true);
  document.addEventListener('submit', function(){ if (window.tinymce) tinymce.triggerSave(); }, true);

  // Szybkie ustawienie okna dostępności zadania
  function fmt(d){ function z(n){return (n<10?'0':'')+n;} return d.getFullYear()+'-'+z(d.getMonth()+1)+'-'+z(d.getDate())+'T'+z(d.getHours())+':'+z(d.getMinutes()); }
  document.addEventListener('click', function(e){
    var b = e.target.closest && e.target.closest('[data-hwq-act]'); if (!b) return;
    var box = b.closest('[data-hwq]'), pfx = box.getAttribute('data-hwq');
    var open = document.getElementById(pfx + '_open'), close = document.getElementById(pfx + '_close');
    var act = b.getAttribute('data-hwq-act');
    if (act === 'open-now') { open.value = fmt(new Date()); }
    else if (act === 'close-days') {
      var base = open.value ? new Date(open.value) : new Date();
      if (isNaN(base)) base = new Date();
      base.setDate(base.getDate() + parseInt(b.getAttribute('data-days'), 10));
      close.value = fmt(base);
    }
    else if (act === 'close-next') { var n = box.getAttribute('data-next'); if (n) close.value = n; }
    else if (act === 'clear') { open.value = ''; close.value = ''; }
  });
})();
</script>
<?php endif; /* $hw_obj */ ?>
