<?php
/**
 * Karta pojedynczego zadania domowego — oczekuje $h, $now, $vlab_token w zasięgu.
 * $dyd_hw_force_open = true wyłącza zwijanie oddanych zadań: w widoku
 * alternatywnym karta jest jedyną treścią kolumny szczegółów, więc nie ma czego
 * chować (na liście, gdzie kart jest kilkanaście, zwijanie zostaje).
 */
$done    = !empty($h['sub_id']);
$graded  = ($h['sub_status'] ?? '') === 'graded';
$overdue = $h['due_at'] && $h['due_at'] < $now && !$done;
$hav     = k30_ti_avail_status($h['open_at'] ?? null, $h['close_at'] ?? null, $now);
$hopen   = $hav['state'] === 'open';
// Oddane zadania zwijamy, by nie zaśmiecały listy — pełną treść można rozwinąć (a11y: <details>).
$collapsible = $done && empty($dyd_hw_force_open);
$cardCls = ($graded ? 'border-success' : ($overdue || $hav['state']==='closed' ? 'border-danger' : ''))
         . ($hav['state']==='upcoming' ? ' opacity-75' : '');

// ── Nagłówek (zawsze widoczny / treść <summary>) ─────────────────────────────
ob_start(); ?>
    <div class="d-flex flex-wrap align-items-start gap-2 <?= $collapsible ? '' : 'mb-2' ?>">
      <div class="flex-grow-1 min-width-0">
        <div class="fw-bold"><?= h($h['title']) ?></div>
        <div class="small text-body-secondary">
          <i class="bi bi-pc-display me-1" aria-hidden="true"></i><?= h($h['course_name']) ?>
          <?php if ($h['due_at']): ?>
          · <span class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>">termin: <?= h(substr($h['due_at'],0,16)) ?></span>
          <?php endif; ?>
          <?php if (($h['open_at'] ?? '')!=='' && $hav['state']==='upcoming'): ?>
          · <span class="text-warning-emphasis">otwarcie: <?= h(substr($h['open_at'],0,16)) ?></span>
          <?php endif; ?>
          <?php if (($h['close_at'] ?? '')!==''): ?>
          · <span class="<?= $hav['state']==='closed' ? 'text-danger fw-semibold' : '' ?>">zamknięcie: <?= h(substr($h['close_at'],0,16)) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($graded): ?>
      <span class="badge text-bg-success">Ocena: <?= h($h['sub_grade'] ?: 'zaliczone') ?></span>
      <?php elseif ($done): ?>
      <span class="badge text-bg-secondary">Oddane</span>
      <?php elseif ($hav['state']==='upcoming'): ?>
      <span class="badge text-bg-warning"><i class="bi bi-clock me-1" aria-hidden="true"></i>Wkrótce</span>
      <?php elseif ($hav['state']==='closed'): ?>
      <span class="badge text-bg-danger"><i class="bi bi-lock me-1" aria-hidden="true"></i>Zamknięte</span>
      <?php elseif ($overdue): ?>
      <span class="badge text-bg-danger">Po terminie</span>
      <?php else: ?>
      <span class="badge text-bg-warning">Do oddania</span>
      <?php endif; ?>
      <?php if ($collapsible): ?>
      <i class="bi bi-chevron-down dyd-hw-chevron flex-shrink-0 text-body-secondary" aria-hidden="true"></i>
      <?php endif; ?>
    </div>
    <?php if ($graded && trim((string)($h['sub_feedback'] ?? '')) !== ''): ?>
    <div class="small text-success mt-2" style="white-space:pre-wrap"><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i><strong>Komentarz prowadzącego:</strong> <?= h($h['sub_feedback']) ?></div>
    <?php endif; ?>
<?php $hdr = ob_get_clean();

// ── Treść zwijana (opis, materiał, oddanie, formularz) ───────────────────────
ob_start(); ?>
    <?php if ($h['description']): ?>
    <p class="small mb-2" style="white-space:pre-wrap"><?= h($h['description']) ?></p>
    <?php endif; ?>
    <?php if (trim((string)($h['hint'] ?? '')) !== ''): ?>
    <details class="mb-2">
      <summary class="small text-info-emphasis" style="cursor:pointer"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Podpowiedź</summary>
      <div class="small mt-1 ps-3" style="white-space:pre-wrap"><?= h($h['hint']) ?></div>
    </details>
    <?php endif; ?>
    <?php if ($h['attach_path'] && $hav['state']!=='upcoming'): ?>
    <p class="small mb-2"><i class="bi bi-paperclip me-1" aria-hidden="true"></i>
      <a href="homework_file.php?t=attach&hw=<?= (int)$h['id'] ?>"><?= h($h['attach_name']) ?></a> (materiał od prowadzącego)
    </p>
    <?php endif; ?>

    <?php if ($done): ?>
    <div class="border rounded p-2 mb-2 bg-body-tertiary small">
      <div class="text-body-secondary mb-1">Twoje oddanie (<?= h(substr($h['sub_at'],0,16)) ?>):</div>
      <?php if ($h['sub_body']): ?><div class="mb-1" style="white-space:pre-wrap"><?= h($h['sub_body']) ?></div><?php endif; ?>
      <?php if ($h['sub_file_path']): ?>
      <div><i class="bi bi-download me-1" aria-hidden="true"></i><a href="homework_file.php?t=sub&id=<?= (int)$h['sub_id'] ?>"><?= h($h['sub_file_name']) ?></a></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$graded && $hopen): ?>
    <form method="post" enctype="multipart/form-data" class="border-top pt-2">
      <input type="hidden" name="_token"      value="<?= h($vlab_token) ?>">
      <input type="hidden" name="_op"          value="submit_homework">
      <input type="hidden" name="homework_id"  value="<?= (int)$h['id'] ?>">
      <div class="mb-2">
        <label class="form-label small fw-semibold">Treść / komentarz</label>
        <textarea class="form-control form-control-sm" name="body" rows="2" placeholder="Możesz wkleić odpowiedź lub dodać komentarz…"><?= h($h['sub_body'] ?? '') ?></textarea>
      </div>
      <div class="d-flex flex-wrap align-items-end gap-2">
        <div class="flex-grow-1">
          <label class="form-label small fw-semibold">Plik <span class="text-body-secondary fw-normal">(opc., maks. 25 MB)</span></label>
          <input type="file" class="form-control form-control-sm" name="file">
        </div>
        <button type="submit" class="btn btn-sm btn-primary">
          <i class="bi bi-upload me-1" aria-hidden="true"></i><?= $done ? 'Popraw oddanie' : 'Oddaj zadanie' ?>
        </button>
      </div>
    </form>
    <?php elseif (!$graded && $hav['state']==='upcoming'): ?>
    <div class="border-top pt-2 small text-body-secondary"><i class="bi bi-clock me-1" aria-hidden="true"></i>Oddawanie będzie możliwe od <?= h(substr($hav['open_at'],0,16)) ?>.</div>
    <?php elseif (!$graded && $hav['state']==='closed' && !$done): ?>
    <div class="border-top pt-2 small text-danger"><i class="bi bi-lock me-1" aria-hidden="true"></i>Oddawanie tego zadania zostało zamknięte (<?= h(substr($hav['close_at'],0,16)) ?>).</div>
    <?php endif; ?>
<?php $bdy = ob_get_clean();
?>
<?php if ($collapsible): ?>
<details class="card dyd-hw <?= $cardCls ?>">
  <summary class="card-body py-2 dyd-hw-summary">
<?= $hdr ?>
  </summary>
  <div class="card-body pt-0">
<?= $bdy ?>
  </div>
</details>
<?php else: ?>
<div class="card <?= $cardCls ?>">
  <div class="card-body">
<?= $hdr ?>
<?= $bdy ?>
  </div>
</div>
<?php endif; ?>
