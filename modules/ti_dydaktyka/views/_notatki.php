<?php
/**
 * Sekcja „Notatki o postępach i trudnościach”. Zmienne: $clientId, $courseCtx, $actor, $courses, $lessonOptions, $notes,
 * $edit_note (edytowana notatka albo null), $nf (wartości formularza), $errors, $open.
 */
$_form_open = $edit_note || $open === 'notatki';
$_show = array_slice($notes, 0, 15); $_more = array_slice($notes, 15);
$_render = function (array $n) use ($clientId, $courseCtx, $actor): void {
    $can = TiDydaktykaManager::canEditNote($actor, $n); ?>
    <li class="border rounded p-2 mb-2">
      <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <?= dk_kind_badge($n['kind']) ?> <?= dk_vis_badge($n['visibility']) ?>
        <?php if ($n['topic'] !== ''): ?><strong><?= h($n['topic']) ?></strong><?php endif; ?>
        <span class="small text-body-secondary ms-auto"><?= h(dk_dt($n['created_at'])) ?><?= !empty($n['author']) ? ' · ' . h($n['author']) : '' ?></span>
      </div>
      <p class="mb-1" style="white-space:pre-wrap"><?= h($n['body']) ?></p>
      <?php if (!empty($n['course_name']) || !empty($n['lesson_date'])): ?>
      <div class="small text-body-secondary"><?= !empty($n['course_name']) ? 'Grupa: ' . h($n['course_name']) : '' ?><?= !empty($n['lesson_date']) ? ' · lekcja z ' . h(dk_date($n['lesson_date'])) : '' ?></div>
      <?php endif; ?>
      <?php if ($can): ?>
      <div class="mt-1 d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(dk_url($clientId, $courseCtx, ['note' => $n['id']], '#notatka-form')) ?>" aria-label="Edytuj notatkę: <?= h($n['topic'] !== '' ? $n['topic'] : TiDydaktykaManager::NOTE_KINDS[$n['kind']]) ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj</a>
        <form method="post" action="<?= h(dk_url($clientId, $courseCtx, [], '#notatki')) ?>" onsubmit="return confirm('Usunąć tę notatkę?')">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="delete_note">
          <input type="hidden" name="client" value="<?= (int)$clientId ?>"><input type="hidden" name="note_id" value="<?= (int)$n['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Usuń notatkę: <?= h($n['topic'] !== '' ? $n['topic'] : TiDydaktykaManager::NOTE_KINDS[$n['kind']]) ?>"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń</button>
        </form>
      </div>
      <?php endif; ?>
    </li>
<?php }; ?>
<section id="notatki" aria-labelledby="h-notatki" class="card border-0 shadow-sm mb-4">
  <div class="card-header"><h2 class="h5 mb-0" id="h-notatki"><i class="bi bi-sticky text-primary me-2" aria-hidden="true"></i>Notatki o postępach i trudnościach</h2></div>
  <div class="card-body">
    <p class="small text-body-secondary">Notatka <strong>wewnętrzna</strong> jest widoczna tylko dla prowadzących kursanta i kierownika. Notatka oznaczona <strong>dla kursanta</strong> pojawi się też w jego panelu i w panelu opiekuna (jeśli kursant jest małoletni).</p>

    <details id="notatka-form" class="mb-3"<?= $_form_open ? ' open' : '' ?>>
      <summary class="fw-semibold text-primary" style="cursor:pointer"><?= $edit_note ? 'Edytuj notatkę' : 'Dodaj notatkę' ?></summary>
      <form method="post" class="row g-2 mt-1" action="<?= h(dk_url($clientId, $courseCtx, [], '#notatki')) ?>">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="save_note">
        <input type="hidden" name="client" value="<?= (int)$clientId ?>">
        <?php if ($edit_note): ?><input type="hidden" name="note_id" value="<?= (int)$edit_note['id'] ?>"><?php endif; ?>
        <div class="col-md-3">
          <label class="form-label fw-semibold" for="nt-kind">Rodzaj <span class="text-danger" aria-hidden="true">*</span></label>
          <select class="form-select" id="nt-kind" name="kind" required><?php foreach (TiDydaktykaManager::NOTE_KINDS as $k => $l): ?><option value="<?= h($k) ?>"<?= $nf['kind'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-9"><label class="form-label" for="nt-topic">Temat (np. skróty klawiszowe NVDA, praca z syntezatorem mowy)</label><input type="text" class="form-control" id="nt-topic" name="topic" value="<?= h($nf['topic']) ?>" maxlength="190"></div>
        <div class="col-12"><label class="form-label fw-semibold" for="nt-body">Treść <span class="text-danger" aria-hidden="true">*</span></label><textarea class="form-control" id="nt-body" name="body" rows="4" maxlength="4000" required><?= h($nf['body']) ?></textarea></div>
        <div class="col-md-6">
          <fieldset>
            <legend class="form-label fw-semibold fs-6 mb-1">Widoczność</legend>
            <?php foreach (TiDydaktykaManager::VISIBILITY as $k => $l): ?>
            <div class="form-check"><input class="form-check-input" type="radio" name="visibility" id="nt-vis-<?= h($k) ?>" value="<?= h($k) ?>"<?= $nf['visibility'] === $k ? ' checked' : '' ?>><label class="form-check-label" for="nt-vis-<?= h($k) ?>"><?= h($l) ?></label></div>
            <?php endforeach; ?>
          </fieldset>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="nt-course">Grupa (opcjonalnie)</label>
          <select class="form-select" id="nt-course" name="course_id"><option value="0">— brak —</option>
            <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$nf['course_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="nt-session">Lekcja (opcjonalnie)</label>
          <select class="form-select" id="nt-session" name="session_id"><option value="0">— brak —</option>
            <?php foreach ($lessonOptions as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$nf['session_id'] === (int)$s['id'] ? ' selected' : '' ?>><?= h(dk_date($s['lesson_date'])) ?> — <?= h($s['course_name']) ?><?= $s['topic'] !== '' ? ': ' . h(mb_substr($s['topic'], 0, 40)) : '' ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-12 d-flex gap-2">
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i><?= $edit_note ? 'Zapisz zmiany' : 'Dodaj notatkę' ?></button>
          <?php if ($edit_note): ?><a class="btn btn-outline-secondary" href="<?= h(dk_url($clientId, $courseCtx, [], '#notatki')) ?>">Anuluj</a><?php endif; ?>
        </div>
      </form>
    </details>

    <?php if (!$notes): ?>
      <p class="text-body-secondary mb-0">Brak notatek o tym kursancie.</p>
    <?php else: ?>
      <ul class="list-unstyled mb-0" aria-label="Notatki kursanta, najnowsze pierwsze">
        <?php foreach ($_show as $n) $_render($n); ?>
      </ul>
      <?php if ($_more): ?>
      <details><summary class="fw-semibold text-primary" style="cursor:pointer">Starsze notatki (<?= count($_more) ?>)</summary>
        <ul class="list-unstyled mt-2 mb-0"><?php foreach ($_more as $n) $_render($n); ?></ul>
      </details>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
